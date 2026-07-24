<?php
/**
 * Export System - ปรับปรุงโครงสร้างผสานเซลล์และเพิ่มวันเข้าพักล่าสุด
 */

require_once __DIR__ . '/../core/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/auth.php';
// ไฟล์นี้ถูกเปิดตรงจากลิงก์ (ไม่ผ่าน index.php) จึงต้อง require rbac เอง
// — ใช้ userCan()/denyAccess() ด้านล่าง ไม่งั้น Fatal error: undefined function userCan()
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/lang.php';   // ข้อความทั้งระบบ — เปิดตรงจากลิงก์ ไม่ผ่าน index.php

$autoload_path = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload_path)) {
    die("Error: Please run 'composer install'");
}
require_once $autoload_path;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// --- 1. Access & Security Check ---
$can_access = userCan('export.excel');

if (!$can_access) denyAccess(t('export.err_no_access'));

// --- 2. Filtering Logic ---
$search = $_GET['search'] ?? '';
$gender = $_GET['gender'] ?? '';
$status = $_GET['status'] ?? '';
$params = [];
$conditions = [];

if (!empty($search)) {
    if (ctype_digit($search) && strlen($search) === 13) {
        $conditions[] = "c.id_card_hash = :hash";
        $params[':hash'] = hashID($search);
    } else {
        // ห้ามใช้ชื่อ placeholder ซ้ำ — PDO ตั้ง EMULATE_PREPARES=false ไว้ ใช้ :q ซ้ำจะได้ HY093 (ของเดิมพังตรงนี้)
        // ค้นจังหวัดจากตาราง lookup ทั้งภูมิลำเนา (hl) และทะเบียนบ้าน (al) ให้ตรงกับคอลัมน์ที่ export ออกไป
        $conditions[] = "(c.firstname LIKE :q1 OR c.lastname LIKE :q2
                          OR al.province LIKE :q3 OR hl.province LIKE :q4
                          OR c.addr_province LIKE :q5)";
        $params[':q1'] = "%$search%"; $params[':q2'] = "%$search%"; $params[':q3'] = "%$search%";
        $params[':q4'] = "%$search%"; $params[':q5'] = "%$search%";
    }
}

if (!empty($gender)) { $conditions[] = "c.gender = :gender"; $params[':gender'] = $gender; }
if ($status === 'active') {
    $conditions[] = "c.id IN (SELECT citizen_id FROM stay_history WHERE status = 'Active')";
}

$where_sql = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

try {
    // แก้ SQL เพื่อหาวันที่เข้าพักล่าสุด และวันที่ออกล่าสุด (ถ้า Active ให้ว่างไว้)
$sql = "SELECT c.*, 
            TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) AS age,
            al.subdistrict AS lookup_tambon, 
            al.district AS lookup_amphoe, 
            al.province AS lookup_province,
            al.zipcode AS lookup_zipcode,
            hl.subdistrict AS home_tambon,
            hl.district AS home_amphoe,
            hl.province AS home_province,
            hl.zipcode AS home_zipcode,
            sh.check_in as last_in,
            CASE WHEN sh.status = 'Active' THEN NULL ELSE sh.check_out END as last_out
            FROM citizens c
            LEFT JOIN address_lookup al ON c.address_id = al.id
            LEFT JOIN address_lookup hl ON c.home_address_id = hl.id
            LEFT JOIN (
                SELECT citizen_id, check_in, check_out, status
                FROM stay_history
                WHERE id IN (SELECT MAX(id) FROM stay_history GROUP BY citizen_id)
            ) sh ON c.id = sh.citizen_id
            $where_sql 
            ORDER BY c.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    $v_master = $pdo->query("SELECT id, v_name FROM vulnerable_master ORDER BY id ASC")->fetchAll();
    $c_master = $pdo->query("SELECT id, field_name FROM custom_field_master WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// --- 3. Excel Generation ---
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('ResidentReport');

$spreadsheet->getDefaultStyle()->getFont()->setName('Sarabun')->setSize(14);

// หัวข้อพื้นฐาน (19 คอลัมน์แรก) — ที่อยู่ 2 ชุดแยกกัน: ทะเบียนบ้าน (J-N) + ภูมิลำเนา (O-S) ชุดละ 5 ช่อง
// หมายเหตุ: ลำดับคอลัมน์ของไฟล์ส่งออก ≠ เทมเพลตนำเข้า (ส่งออกรวมชื่อ-สกุล/มี last4+อายุ, นำเข้าต้องแยกช่อง)
// ที่ต้องตรงกันคือ "ความหมาย" ของกลุ่มที่อยู่ — ห้ามให้คอลัมน์ที่อยู่ชุดเดียวปนทั้งทะเบียนบ้านและภูมิลำเนา
// ช่องเดี่ยว (คอลัมน์ 1-9) — ผสานแนวตั้งครอบแถว 1-2
$single_headers = [
    t('export.col_no'), t('export.col_id_card'), t('export.col_id_last4'), t('export.col_fullname'),
    t('export.col_gender'), t('export.col_age'), t('export.col_phone'), t('export.col_checkin'), t('export.col_checkout'),
];
// กลุ่มที่อยู่ 2 ชุด — คอลัมน์หลักแถว 1 (ผสานแนวนอน) + หัวย่อยแถว 2 · หัวย่อยชุดเดียวกันทั้งสองกลุ่ม
$addr_subs   = [t('export.col_address'), t('export.col_tambon'), t('export.col_amphoe'), t('export.col_province'), t('export.col_zipcode')];
$addr_groups = [t('export.grp_address'), t('export.grp_home')];

$ADDR_START     = count($single_headers) + 1;                                    // = 10 (J)
$BASE_COL_COUNT = count($single_headers) + count($addr_groups) * count($addr_subs); // = 19 (S)
$base_headers   = array_merge($single_headers, $addr_subs, $addr_subs);
$v_names = array_column($v_master, 'v_name');
$c_names = array_column($c_master, 'field_name');
$all_headers = array_merge($base_headers, $v_names, $c_names);

// ปรับความกว้างคอลัมน์ลำดับ
$sheet->getColumnDimension('A')->setWidth(20, 'px');

// --- 🎯 การจัดการ Merge Cells (แถวที่ 1 และ 2) ---

// 1. ช่องเดี่ยว — ผสานแนวตั้ง
for ($i = 1; $i < $ADDR_START; $i++) {
    $colLetter = Coordinate::stringFromColumnIndex($i);
    $sheet->mergeCells("{$colLetter}1:{$colLetter}2");
    $sheet->setCellValue("{$colLetter}1", $all_headers[$i-1]);
    $sheet->getColumnDimension($colLetter)->setAutoSize(true);
}

// 1b. กลุ่มที่อยู่ — คอลัมน์หลักแถว 1 ผสานคลุมชุดละ 5 ช่อง + หัวย่อยแถว 2
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

// 2. ผสานคอลัมน์กลุ่มพิเศษ (ถัดจากข้อมูลพื้นฐาน) แนวนอนในแถวที่ 1
$startVCol = Coordinate::stringFromColumnIndex($BASE_COL_COUNT + 1);
$lastColIdx = count($all_headers);
$lastColStr = Coordinate::stringFromColumnIndex($lastColIdx);
$sheet->mergeCells("{$startVCol}1:{$lastColStr}1");
$sheet->setCellValue("{$startVCol}1", t('export.pdpa_section'));

// 3. ใส่หัวข้อคอลัมน์กลุ่มพิเศษ ในแถวที่ 2
for ($i = $BASE_COL_COUNT + 1; $i <= $lastColIdx; $i++) {
    $colLetter = Coordinate::stringFromColumnIndex($i);
    $sheet->setCellValue($colLetter . '2', $all_headers[$i-1]);
    $sheet->getColumnDimension($colLetter)->setAutoSize(true);
}

// Styling
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D6EFD']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle("A1:{$lastColStr}2")->applyFromArray($headerStyle);

// --- 4. Data Injection ---
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
    
    // วันเข้าพัก และวันออกล่าสุด
    $sheet->setCellValue('H' . $currentRow, $r['last_in'] ? date('d/m/Y', strtotime($r['last_in'])) : '-');
    $sheet->setCellValue('I' . $currentRow, $r['last_out'] ? date('d/m/Y', strtotime($r['last_out'])) : '-');

    // ที่อยู่ 2 ชุดแยกกัน — ไฟล์นี้เป็นทั้งรายงานและ "ไฟล์นำเข้ากลับ" จึงห้ามผสม/เดาประเภทที่อยู่
    // J-M = ที่อยู่ตามทะเบียนบ้าน · N-Q = ภูมิลำเนา (เว้น '-' ถ้าไม่มีข้อมูล)
    $reg = [
        'number'   => $r['addr_number']     ?? '',
        'tambon'   => $r['lookup_tambon']   ?? $r['addr_tambon']   ?? '',
        'amphoe'   => $r['lookup_amphoe']   ?? $r['addr_amphoe']   ?? '',
        'province' => $r['lookup_province'] ?? $r['addr_province'] ?? '',
        'zipcode'  => $r['lookup_zipcode']  ?? $r['addr_zipcode']  ?? '',
    ];
    $home = [
        'number'   => $r['home_addr_number'] ?? '',
        'tambon'   => $r['home_tambon']      ?? '',
        'amphoe'   => $r['home_amphoe']      ?? '',
        'province' => $r['home_province']    ?? '',
        'zipcode'  => $r['home_zipcode']     ?? '',
    ];
    $cell = fn($v) => ($v !== null && trim((string)$v) !== '') ? $v : '-';

    // เขียนทีละกลุ่มตามลำดับหัวตาราง (ที่อยู่/ตำบล/อำเภอ/จังหวัด/รหัสไปรษณีย์ × 2 ชุด)
    $colIdx = $ADDR_START;
    foreach ([$reg, $home] as $set) {
        foreach (['number', 'tambon', 'amphoe', 'province', 'zipcode'] as $part) {
            $sheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($colIdx) . $currentRow,
                (string)$cell($set[$part]),
                DataType::TYPE_STRING     // รหัสไปรษณีย์ต้องคงเลข 0 นำหน้า (เช่น 10110) ไม่ให้ Excel ตัดทิ้ง
            );
            $colIdx++;
        }
    }

    // ส่วนของ Vulnerable และ Custom Field (เริ่มถัดจากคอลัมน์ข้อมูลพื้นฐาน)
    $stmt_v = $pdo->prepare("SELECT v_id FROM citizen_vulnerable_map WHERE citizen_id = ?");
    $stmt_v->execute([$r['id']]);
    $active_v = $stmt_v->fetchAll(PDO::FETCH_COLUMN);

    $stmt_c = $pdo->prepare("SELECT field_id, field_value FROM citizen_custom_values WHERE citizen_id = ?");
    $stmt_c->execute([$r['id']]);
    $active_c = $stmt_c->fetchAll(PDO::FETCH_KEY_PAIR);

    $colIdx = $BASE_COL_COUNT + 1;
    foreach ($v_master as $v) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $currentRow, in_array($v['id'], $active_v) ? '✔' : '');
        $colIdx++;
    }
    foreach ($c_master as $c) {
        $val = $active_c[$c['id']] ?? '';
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $currentRow, ($val === 'Yes' ? '✔' : $val));
        $colIdx++;
    }
    $currentRow++;
}

// Borders
$sheet->getStyle("A3:{$lastColStr}" . ($currentRow - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A3:{$lastColStr}" . ($currentRow - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// --- 5. Output ---
// ปกติหน้านี้ถูกเปิดตรง (guest_list ลิงก์เป็น pages/export_excel.php) จึงไม่มี buffer ค้าง
// แต่ถ้าเปิดผ่าน router (index.php?page=export_excel) index.php จะ ob_start() + include
// header.php ไว้ก่อนแล้ว → HTML จะไปปนหัวไฟล์ .xlsx ทำให้ Excel เปิดไม่ขึ้น
// ทิ้ง buffer ทั้งหมดก่อนพ่นไบนารี — guard ตัวเดียวกับที่ guest_import.php:416 มีอยู่แล้ว
while (ob_get_level() > 0) { ob_end_clean(); }

$filename = "Resident_Report_" . date('Ymd_His') . ".xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();