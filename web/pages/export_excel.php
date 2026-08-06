<?php
/**
 * Export System — รองรับข้อมูลจำนวนมาก
 *  - เล็ก (≤ MAX_XLSX แถว): .xlsx สวยงาม (หัวตาราง 2 แถว merge + styling) เหมือนเดิม
 *  - ใหญ่/ขอเอง (?format=csv): stream CSV ทีละก้อน (keyset by id) — RAM คงที่ ล้านแถวได้
 *
 * แก้คอขวดเดิม 3 จุด: (1) fetchAll() ทั้งชุด → OOM  (2) PhpSpreadsheet ถือทุกเซลล์ใน RAM
 * (3) N+1 query กลุ่มเปราะบาง/custom ต่อแถว + subquery MAX(id) ทั้ง stay_history
 */

require_once __DIR__ . '/../core/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/lang.php';

// --- 1. Access & Security ---
if (!userCan('export.excel')) denyAccess(t('export.err_no_access'));

$format   = (($_GET['format'] ?? '') === 'csv') ? 'csv' : 'xlsx';
const MAX_XLSX = 20000;   // เกินนี้ .xlsx เสี่ยง OOM (PhpSpreadsheet ~1KB/เซลล์) → บังคับ CSV
const CHUNK    = 2000;    // ขนาดก้อนต่อรอบ (keyset) — คุม RAM + จำนวน query

// --- 2. Filters (เหมือนหน้ารายชื่อ: ใช้ prefix + is_active) ---
$search = $_GET['search'] ?? '';
$gender = $_GET['gender'] ?? '';
$status = $_GET['status'] ?? '';
$params = [];
$conditions = [];

// ค้นหา = ตรรกะเดียวกับหน้ารายชื่อ (ตัดคำนำหน้า + arm ชื่อ/สกุล/ที่อยู่) — placeholder positional '?'
// export ใช้ WHERE เดียว (keyset streaming) จึง OR รวม arm (ไม่ UNION เหมือน guest_list)
if (!empty($search)) {
    if (ctype_digit($search) && strlen($search) === 13) {
        $conditions[] = "c.id_card_hash = ?";
        $params[] = hashID($search);
    } else {
        $arms = searchTextArms($pdo, $search);
        if ($arms) {
            $orParts = [];
            foreach ($arms as [$asql, $avals]) {
                $orParts[] = "($asql)";
                foreach ($avals as $v) $params[] = $v;
            }
            $conditions[] = "(" . implode(' OR ', $orParts) . ")";
        } else {
            $conditions[] = "1=0"; // คำนำหน้าล้วน / ไม่มี arm → ไม่มีผล
        }
    }
}
if (!empty($gender)) { $conditions[] = "c.gender = ?"; $params[] = $gender; }
if ($status === 'active')   { $conditions[] = "c.is_active = 1"; }
if ($status === 'inactive') { $conditions[] = "c.is_active = 0"; }

$where_sql = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";

$v_master = $pdo->query("SELECT id, v_name FROM vulnerable_master ORDER BY id ASC")->fetchAll();
$c_master = $pdo->query("SELECT id, field_name FROM custom_field_master WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// --- 3. ตัวช่วย: เติมข้อมูลเสริมให้ก้อนแถว (last stay / กลุ่มเปราะบาง / custom) แบบ batch (ไม่ N+1) ---
/**
 * @param array $rows แถว citizens (ต้องมี key 'id')
 * @return array คืน $rows เดิม + [_last_in,_last_out,_vids(array),_cvals(map fid=>val)]
 */
function enrichBatch(PDO $pdo, array $rows): array
{
    if (!$rows) return $rows;
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in  = implode(',', array_fill(0, count($ids), '?'));

    // latest stay ต่อคน (แทน subquery MAX(id) ทั้งตาราง — จำกัดเฉพาะ id ในก้อนนี้)
    $stay = [];
    $sh = $pdo->prepare(
        "SELECT sh.citizen_id, sh.check_in, sh.check_out, sh.status
         FROM stay_history sh
         JOIN (SELECT citizen_id, MAX(id) mid FROM stay_history WHERE citizen_id IN ($in) GROUP BY citizen_id) t
           ON t.mid = sh.id"
    );
    $sh->execute($ids);
    foreach ($sh->fetchAll() as $s) { $stay[(int)$s['citizen_id']] = $s; }

    // กลุ่มเปราะบาง
    $vmap = [];
    $vs = $pdo->prepare("SELECT citizen_id, v_id FROM citizen_vulnerable_map WHERE citizen_id IN ($in)");
    $vs->execute($ids);
    foreach ($vs->fetchAll() as $v) { $vmap[(int)$v['citizen_id']][] = (int)$v['v_id']; }

    // custom values
    $cmap = [];
    $cs = $pdo->prepare("SELECT citizen_id, field_id, field_value FROM citizen_custom_values WHERE citizen_id IN ($in)");
    $cs->execute($ids);
    foreach ($cs->fetchAll() as $c) { $cmap[(int)$c['citizen_id']][(int)$c['field_id']] = $c['field_value']; }

    foreach ($rows as &$r) {
        $id = (int)$r['id'];
        $st = $stay[$id] ?? null;
        $r['_last_in']  = $st['check_in'] ?? null;
        $r['_last_out'] = ($st && $st['status'] !== 'Active') ? $st['check_out'] : null;
        $r['_vids']     = $vmap[$id] ?? [];
        $r['_cvals']    = $cmap[$id] ?? [];
    }
    unset($r);
    return $rows;
}

// SELECT หลัก (ไม่มี subquery MAX(id) แล้ว — last stay เติมทีหลังแบบ batch)
$SELECT_COLS = "c.id, c.id_card_enc, c.id_card_last4, c.prefix, c.firstname, c.lastname, c.gender,
    TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) AS age, c.phone_enc,
    c.addr_number, c.addr_tambon, c.addr_amphoe, c.addr_province,
    c.home_addr_number,
    al.subdistrict AS lookup_tambon, al.district AS lookup_amphoe, al.province AS lookup_province, al.zipcode AS lookup_zipcode,
    hl.subdistrict AS home_tambon, hl.district AS home_amphoe, hl.province AS home_province, hl.zipcode AS home_zipcode";
$FROM_JOIN = "FROM citizens c
    LEFT JOIN address_lookup al ON c.address_id = al.id
    LEFT JOIN address_lookup hl ON c.home_address_id = hl.id";

// ค่าที่อยู่ 2 ชุด (ทะเบียนบ้าน/ภูมิลำเนา) เป็น '-' เมื่อว่าง
$cell = fn($v) => ($v !== null && trim((string)$v) !== '') ? $v : '-';
$rowAddr = function (array $r) use ($cell): array {
    $reg = [$r['addr_number'] ?? '', $r['lookup_tambon'] ?? $r['addr_tambon'] ?? '', $r['lookup_amphoe'] ?? $r['addr_amphoe'] ?? '',
            $r['lookup_province'] ?? $r['addr_province'] ?? '', $r['lookup_zipcode'] ?? ''];
    $home = [$r['home_addr_number'] ?? '', $r['home_tambon'] ?? '', $r['home_amphoe'] ?? '', $r['home_province'] ?? '', $r['home_zipcode'] ?? ''];
    return array_map(fn($v) => (string)$cell($v), array_merge($reg, $home));
};

// ================================================================
//  CSV STREAM (ใหญ่ / ขอเอง) — keyset by id, RAM คงที่
// ================================================================
if ($format === 'csv') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    @set_time_limit(0);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Resident_Report_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM ให้ Excel เปิดไทยถูก

    // หัวตารางแถวเดียว (CSV merge ไม่ได้ — ใช้ชื่อกลุ่มนำหน้า)
    $header = [
        t('export.col_no'), t('export.col_id_card'), t('export.col_id_last4'), t('export.col_fullname'),
        t('export.col_gender'), t('export.col_age'), t('export.col_phone'), t('export.col_checkin'), t('export.col_checkout'),
    ];
    foreach ([t('export.grp_address'), t('export.grp_home')] as $g) {
        foreach ([t('export.col_address'), t('export.col_tambon'), t('export.col_amphoe'), t('export.col_province'), t('export.col_zipcode')] as $s) {
            $header[] = "$g: $s";
        }
    }
    foreach ($v_master as $v) $header[] = $v['v_name'];
    foreach ($c_master as $c) $header[] = $c['field_name'];
    fputcsv($out, $header);

    $lastId = 0;
    $no = 0;
    while (true) {
        $sql = "SELECT $SELECT_COLS $FROM_JOIN
                " . ($where_sql ? $where_sql . " AND c.id > ?" : "WHERE c.id > ?") . "
                ORDER BY c.id ASC LIMIT " . CHUNK;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$lastId]));
        $rows = $stmt->fetchAll();
        if (!$rows) break;

        $rows = enrichBatch($pdo, $rows);
        foreach ($rows as $r) {
            $no++;
            $line = [
                $no,
                decryptData($r['id_card_enc']),
                $r['id_card_last4'],
                $r['prefix'] . $r['firstname'] . ' ' . $r['lastname'],
                ($r['gender'] === 'Male' ? t('common.male') : ($r['gender'] === 'Female' ? t('common.female') : '-')),
                $r['age'],
                !empty($r['phone_enc']) ? decryptData($r['phone_enc']) : '-',
                $r['_last_in']  ? date('d/m/Y', strtotime($r['_last_in']))  : '-',
                $r['_last_out'] ? date('d/m/Y', strtotime($r['_last_out'])) : '-',
            ];
            foreach ($rowAddr($r) as $a) $line[] = $a;
            foreach ($v_master as $v) $line[] = in_array((int)$v['id'], $r['_vids'], true) ? '✔' : '';
            foreach ($c_master as $c) { $val = $r['_cvals'][(int)$c['id']] ?? ''; $line[] = ($val === 'Yes' ? '✔' : $val); }
            fputcsv($out, $line);
        }
        $lastId = (int)end($rows)['id'];
        flush();
        if (count($rows) < CHUNK) break;
    }
    fclose($out);
    exit();
}

// ================================================================
//  XLSX (เล็ก ≤ MAX_XLSX) — probe ก่อน ถ้าเกินให้ไปโหลด CSV
// ================================================================
$autoload_path = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload_path)) { die("Error: Please run 'composer install'"); }
require_once $autoload_path;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

try {
    // ดึง ≤ MAX_XLSX+1 แถว (buffered) — ถ้าเกิน = ใหญ่เกินสำหรับ xlsx
    $sql = "SELECT $SELECT_COLS $FROM_JOIN $where_sql ORDER BY c.id ASC LIMIT " . (MAX_XLSX + 1);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Export query error: " . $e->getMessage());
    die(t('common.err_technical'));
}

// เกินขนาด → ไม่สร้าง xlsx (กัน OOM) เสนอ CSV แทน
if (count($data) > MAX_XLSX) {
    $csv_url = 'export_excel.php?' . http_build_query(array_merge($_GET, ['format' => 'csv']));
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>Export</title>';
    echo '<div style="font-family:Sarabun,sans-serif;max-width:560px;margin:60px auto;padding:24px;border:1px solid #ddd;border-radius:12px;text-align:center">';
    echo '<h3>' . htmlspecialchars(t('export.too_big_title')) . '</h3>';
    echo '<p style="color:#555">' . htmlspecialchars(t('export.too_big_body')) . '</p>';
    echo '<p><a href="' . htmlspecialchars($csv_url) . '" style="display:inline-block;background:#198754;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold">⬇ ' . htmlspecialchars(t('export.download_csv')) . '</a></p>';
    echo '<p><a href="../index.php?page=guest_list" style="color:#666">' . htmlspecialchars(t('btn.back')) . '</a></p>';
    echo '</div>';
    exit();
}

$data = enrichBatch($pdo, $data);

// --- XLSX generation (หัวตาราง 2 แถว merge + styling เหมือนเดิม) ---
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('ResidentReport');
$spreadsheet->getDefaultStyle()->getFont()->setName('Sarabun')->setSize(14);

$single_headers = [
    t('export.col_no'), t('export.col_id_card'), t('export.col_id_last4'), t('export.col_fullname'),
    t('export.col_gender'), t('export.col_age'), t('export.col_phone'), t('export.col_checkin'), t('export.col_checkout'),
];
$addr_subs   = [t('export.col_address'), t('export.col_tambon'), t('export.col_amphoe'), t('export.col_province'), t('export.col_zipcode')];
$addr_groups = [t('export.grp_address'), t('export.grp_home')];

$ADDR_START     = count($single_headers) + 1;
$BASE_COL_COUNT = count($single_headers) + count($addr_groups) * count($addr_subs);
$base_headers   = array_merge($single_headers, $addr_subs, $addr_subs);
$v_names = array_column($v_master, 'v_name');
$c_names = array_column($c_master, 'field_name');
$all_headers = array_merge($base_headers, $v_names, $c_names);

$sheet->getColumnDimension('A')->setWidth(20, 'px');

for ($i = 1; $i < $ADDR_START; $i++) {
    $colLetter = Coordinate::stringFromColumnIndex($i);
    $sheet->mergeCells("{$colLetter}1:{$colLetter}2");
    $sheet->setCellValue("{$colLetter}1", $all_headers[$i-1]);
    $sheet->getColumnDimension($colLetter)->setAutoSize(true);
}

$col = $ADDR_START;
foreach ($addr_groups as $grpName) {
    $grpStart = $col;
    foreach ($addr_subs as $sub) {
        $L = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue("{$L}2", $sub);
        $sheet->getColumnDimension($L)->setAutoSize(true);
        $col++;
    }
    $sheet->mergeCells(Coordinate::stringFromColumnIndex($grpStart) . '1:' . Coordinate::stringFromColumnIndex($col - 1) . '1');
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($grpStart) . '1', $grpName);
}

$startVCol = Coordinate::stringFromColumnIndex($BASE_COL_COUNT + 1);
$lastColIdx = count($all_headers);
$lastColStr = Coordinate::stringFromColumnIndex($lastColIdx);
$sheet->mergeCells("{$startVCol}1:{$lastColStr}1");
$sheet->setCellValue("{$startVCol}1", t('export.pdpa_section'));

for ($i = $BASE_COL_COUNT + 1; $i <= $lastColIdx; $i++) {
    $colLetter = Coordinate::stringFromColumnIndex($i);
    $sheet->setCellValue($colLetter . '2', $all_headers[$i-1]);
    $sheet->getColumnDimension($colLetter)->setAutoSize(true);
}

$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D6EFD']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle("A1:{$lastColStr}2")->applyFromArray($headerStyle);

$currentRow = 3;
foreach ($data as $i => $r) {
    $id_card = decryptData($r['id_card_enc']);
    $phone   = !empty($r['phone_enc']) ? decryptData($r['phone_enc']) : '-';

    $sheet->setCellValue('A' . $currentRow, $i + 1);
    $sheet->setCellValueExplicit('B' . $currentRow, $id_card, DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('C' . $currentRow, $r['id_card_last4'], DataType::TYPE_STRING);
    $sheet->setCellValue('D' . $currentRow, $r['prefix'] . $r['firstname'] . ' ' . $r['lastname']);
    $sheet->setCellValue('E' . $currentRow, ($r['gender'] == 'Male' ? t('common.male') : t('common.female')));
    $sheet->setCellValue('F' . $currentRow, $r['age']);
    $sheet->setCellValueExplicit('G' . $currentRow, $phone, DataType::TYPE_STRING);
    $sheet->setCellValue('H' . $currentRow, $r['_last_in']  ? date('d/m/Y', strtotime($r['_last_in']))  : '-');
    $sheet->setCellValue('I' . $currentRow, $r['_last_out'] ? date('d/m/Y', strtotime($r['_last_out'])) : '-');

    $colIdx = $ADDR_START;
    foreach ($rowAddr($r) as $a) {
        $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($colIdx) . $currentRow, $a, DataType::TYPE_STRING);
        $colIdx++;
    }

    $colIdx = $BASE_COL_COUNT + 1;
    foreach ($v_master as $v) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $currentRow, in_array((int)$v['id'], $r['_vids'], true) ? '✔' : '');
        $colIdx++;
    }
    foreach ($c_master as $c) {
        $val = $r['_cvals'][(int)$c['id']] ?? '';
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $currentRow, ($val === 'Yes' ? '✔' : $val));
        $colIdx++;
    }
    $currentRow++;
}

$sheet->getStyle("A3:{$lastColStr}" . ($currentRow - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A3:{$lastColStr}" . ($currentRow - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

while (ob_get_level() > 0) { ob_end_clean(); }
$filename = "Resident_Report_" . date('Ymd_His') . ".xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
