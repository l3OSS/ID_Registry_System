<?php
/**
 * Page: guest_import — นำเข้ารายชื่อผู้เข้าพักจากไฟล์ Excel/CSV
 * เปิดผ่าน index.php?page=guest_import (core + header/footer + csrf_verify โหลด/ทำงานให้แล้ว)
 *
 * ยกระบบมาจาก Sec (pages/member_import.php) แล้วปรับให้ตรง schema ของ Reg
 * (citizens + stay_history + vulnerable/custom · เพศ Male/Female · เก็บวันเกิด ไม่ใช่ อายุ)
 *
 * เกณฑ์:
 *  1) ใช้เลขบัตร ปช./พาสปอร์ต เป็นตัวเทียบซ้ำ (hash เดียวกับที่ระบบเก็บ)
 *  2) ซ้ำกับข้อมูลเดิม → ตรวจสถานะเข้าพัก: กำลังพัก = ข้าม · แจ้งออกแล้ว = เปิดเข้าพักใหม่ + รอเลือกข้อมูล
 *  3) อย่างน้อยต้องมี เลขบัตร/พาสปอร์ต + ชื่อ ไม่ครบ → ข้าม + รายงานเหตุผล
 *  4) ต้องแยกช่อง คำนำหน้า/ชื่อ/นามสกุล เอง (มีคำเตือน + เทมเพลตเปล่าให้โหลด)
 *  + เลข ปช. 13 หลักตรวจ checksum เข้ม · นำเข้าสำเร็จ = เช็คอิน Active อัตโนมัติ
 *
 * เทมเพลตหัวตาราง 2 แถว (main/sub) ให้เข้าคู่กับไฟล์ส่งออก:
 *   ช่องเดี่ยว (ผสานแนวตั้ง): ลำดับ, เลขบัตร, คำนำหน้า, ชื่อ, นามสกุล, เพศ, วันเกิด, เบอร์โทร, ประเภทที่พัก, วันเข้าพัก
 *   กลุ่ม "ที่อยู่": ที่อยู่, ตำบล, อำเภอ, จังหวัด
 *   กลุ่ม "สถานะกลุ่มพิเศษ": เช็คบ็อกกลุ่มเปราะบาง (vulnerable_master) + แท็กข้อความฟิลด์พิเศษ (custom_field_master)
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};

requirePermission('guests.register');

// ลำดับช่องคงที่ (0-based ตามตำแหน่งในไฟล์) → ฟิลด์ภายใน · ตำแหน่ง 0 = "ลำดับ" (ข้อมูลอ้างอิง ไม่ใช้)
$FIXED_FIELDS = [
    1  => 'id_card',        // * จำเป็น
    2  => 'prefix',
    3  => 'firstname',      // * จำเป็น
    4  => 'lastname',
    5  => 'gender',
    6  => 'birthdate',
    7  => 'phone',
    8  => 'location_type',
    9  => 'check_in',
    10 => 'addr_number',
    11 => 'addr_tambon',
    12 => 'addr_amphoe',
    13 => 'addr_province',
];
$FIRST_SPECIAL_IDX = 14;   // ตำแหน่งเริ่มของกลุ่ม "สถานะกลุ่มพิเศษ" (vulnerable + custom)

// master ของกลุ่มพิเศษ — ลำดับตรงกับ export_excel (ORDER BY id ASC) เพื่อจับคู่ตามตำแหน่งได้
$V_MASTER = $pdo->query("SELECT id, v_name FROM vulnerable_master ORDER BY id ASC")->fetchAll();
$C_MASTER = $pdo->query("SELECT id, field_name FROM custom_field_master WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

/** ตรวจ checksum เลขบัตรประชาชนไทย 13 หลัก */
function imp_validThaiId(string $id): bool {
    if (!ctype_digit($id) || strlen($id) !== 13) return false;
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int)$id[$i] * (13 - $i);
    $check = (11 - ($sum % 11)) % 10;
    return $check === (int)$id[12];
}

/** แปลงคำเพศ → enum Male/Female หรือ null · รับ ชาย/หญิง/Male/Female/1/2 */
function imp_mapGender(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (in_array($s, ['Male', 'ชาย', '1'], true) || strtolower($s) === 'male') return 'Male';
    if (in_array($s, ['Female', 'หญิง', '2'], true) || strtolower($s) === 'female') return 'Female';
    return null;
}

/** แปลงประเภทที่พัก → enum Inside/Outside · ค่าเริ่มต้น Inside */
function imp_mapLocation(string $s): string {
    $s = trim($s);
    $low = strtolower($s);
    if (mb_strpos($s, 'นอก') !== false || strpos($low, 'out') !== false) return 'Outside';
    return 'Inside';
}

/** เช็คบ็อก "ติ๊กแล้ว" ไหม (✔/✓/yes/1/x/true/มี) */
function imp_isChecked(string $s): bool {
    $s = trim($s);
    if ($s === '') return false;
    if (in_array($s, ['✔', '✓', '☑', 'x', 'X', '/', '1'], true)) return true;
    return in_array(strtolower($s), ['yes', 'y', 'true', 'มี'], true);
}

/** ค้นหา address_id จากชื่อ ตำบล/อำเภอ/จังหวัด (สำเนาตรรกะจาก guest_check.lookupAddressId ให้หน้านี้พึ่งตัวเอง) */
function imp_lookupAddressId(PDO $pdo, string $tambon, string $amphoe, string $province): ?int {
    $t = str_replace(['ตำบล', 'ต.'], '', trim($tambon));
    $a = str_replace(['อำเภอ', 'อ.'], '', trim($amphoe));
    $p = str_replace(['จังหวัด', 'จ.'], '', trim($province));
    if ($t === '' && $a === '' && $p === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM address_lookup WHERE subdistrict LIKE ? AND district LIKE ? AND province LIKE ? LIMIT 1");
    $stmt->execute(["%$t%", "%$a%", "%$p%"]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/** normalize วันที่ → 'Y-m-d' (ค.ศ.) หรือ null · รับ ปปปป-ดด-วว, วว/ดด/ปปปป, และปี พ.ศ. (>2400) */
function imp_normalizeDate(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m))      { $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3]; }
    elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $m))  { $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3]; }
    else return null;
    if ($y > 2400) $y -= 543;                 // เผื่อกรอกเป็น พ.ศ.
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** normalize วันเข้าพัก → 'Y-m-d H:i:s' · ว่าง = ตอนนี้ */
function imp_normalizeCheckIn(string $s): string {
    $d = imp_normalizeDate($s);
    return $d !== null ? ($d . ' 00:00:00') : date('Y-m-d H:i:s');
}

/** ป้ายเพศจาก enum */
function imp_genderLabel(?string $g): string {
    if ($g === 'Male')   return t('common.male');
    if ($g === 'Female') return t('common.female');
    return '-';
}

/** จัดรูปที่อยู่ย่อ (ต./อ./จ.) สำหรับตารางเทียบ */
function imp_fmtAddr($t, $a, $p): string {
    $parts = array_filter([$t ? "ต.$t" : '', $a ? "อ.$a" : '', $p ? "จ.$p" : '']);
    return $parts ? implode('  ', $parts) : '-';
}

/** อ่านค่ากลุ่มพิเศษจากแถว → [vulnerable=>[v_id,...], custom=>[field_id=>value,...]] */
function imp_readSpecial(array $row, int $startIdx, array $vMaster, array $cMaster): array {
    $vulnerable = [];
    $custom = [];
    $idx = $startIdx;
    foreach ($vMaster as $v) {
        if (imp_isChecked((string)($row[$idx] ?? ''))) $vulnerable[] = (int)$v['id'];
        $idx++;
    }
    foreach ($cMaster as $c) {
        $val = trim((string)($row[$idx] ?? ''));
        if ($val !== '') {
            // ตรงกับ export: ✔ = ค่า 'Yes' · อื่น ๆ = ข้อความตามจริง
            $custom[(int)$c['id']] = imp_isChecked($val) ? 'Yes' : $val;
        }
        $idx++;
    }
    return ['vulnerable' => $vulnerable, 'custom' => $custom];
}

/** เขียน map กลุ่มเปราะบาง + ฟิลด์พิเศษ (DELETE-then-INSERT เหมือน guest_check) */
function imp_writeSpecial(PDO $pdo, int $cid, array $special): void {
    $pdo->prepare("DELETE FROM citizen_vulnerable_map WHERE citizen_id = ?")->execute([$cid]);
    foreach ($special['vulnerable'] as $vid) {
        $pdo->prepare("INSERT INTO citizen_vulnerable_map (citizen_id, v_id) VALUES (?, ?)")->execute([$cid, $vid]);
    }
    $pdo->prepare("DELETE FROM citizen_custom_values WHERE citizen_id = ?")->execute([$cid]);
    foreach ($special['custom'] as $fid => $val) {
        $pdo->prepare("INSERT INTO citizen_custom_values (citizen_id, field_id, field_value) VALUES (?, ?, ?)")->execute([$cid, $fid, $val]);
    }
}

/** สร้างรายการ "เปิดเข้าพักใหม่ + รอเทียบ" — เก็บค่าเดิม(แสดง) + ค่าใหม่(แสดง/ใช้จริง) */
function imp_buildReopenEntry(array $old, array $d, array $special, int $rowNum): array {
    $new_apply = [
        'prefix' => $d['prefix'], 'firstname' => $d['firstname'], 'lastname' => $d['lastname'],
        'gender' => imp_mapGender($d['gender']), 'birthdate' => imp_normalizeDate($d['birthdate']),
        'addr_number' => $d['addr_number'], 'addr_tambon' => $d['addr_tambon'],
        'addr_amphoe' => $d['addr_amphoe'], 'addr_province' => $d['addr_province'],
        'phone' => $d['phone'],
        'special' => $special,
    ];
    return [
        'citizen_id' => (int)$old['id'],
        'row' => $rowNum,
        'old_display' => [
            'name'   => trim(($old['prefix'] ?? '') . ($old['firstname'] ?? '') . ' ' . ($old['lastname'] ?? '')),
            'last4'  => $old['id_card_last4'] ?? '',
            'gender' => imp_genderLabel($old['gender'] ?? null),
            'birth'  => $old['birthdate'] ?? '',
            'addr'   => imp_fmtAddr($old['a_tambon'] ?? '', $old['a_amphoe'] ?? '', $old['a_province'] ?? ''),
        ],
        'new_display' => [
            'name'   => trim($d['prefix'] . $d['firstname'] . ' ' . $d['lastname']),
            'last4'  => substr(str_replace(['-', ' '], '', $d['id_card']), -4),
            'gender' => imp_genderLabel($new_apply['gender']),
            'birth'  => $new_apply['birthdate'] ?? '',
            'addr'   => imp_fmtAddr($d['addr_tambon'], $d['addr_amphoe'], $d['addr_province']),
        ],
        'new_apply' => $new_apply,
    ];
}

/** ใช้ "ข้อมูลใหม่จากไฟล์" อัปเดตผู้เข้าพักที่กลับเข้าพัก — เขียนเฉพาะช่องที่ไฟล์มีค่า */
function imp_applyNewData(PDO $pdo, int $cid, array $n): bool {
    try {
        $pdo->beginTransaction();
        $sets = []; $vals = [];
        $put = function (string $col, $val) use (&$sets, &$vals) {
            if ($val !== null && $val !== '') { $sets[] = "$col = ?"; $vals[] = $val; }
        };
        $put('prefix', $n['prefix']); $put('firstname', $n['firstname']); $put('lastname', $n['lastname']);
        if ($n['gender'] !== null) { $sets[] = "gender = ?"; $vals[] = $n['gender']; }
        $put('birthdate', $n['birthdate']);
        $put('addr_number', $n['addr_number']); $put('addr_tambon', $n['addr_tambon']);
        $put('addr_amphoe', $n['addr_amphoe']); $put('addr_province', $n['addr_province']);
        $address_id = imp_lookupAddressId($pdo, $n['addr_tambon'], $n['addr_amphoe'], $n['addr_province']);
        if ($address_id !== null) { $sets[] = "address_id = ?"; $vals[] = $address_id; }
        if ($n['phone'] !== null && $n['phone'] !== '') { $sets[] = "phone_enc = ?"; $vals[] = encryptData($n['phone']); }
        if ($sets) {
            $sets[] = "updated_at = NOW()"; $vals[] = $cid;
            $pdo->prepare("UPDATE citizens SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        }
        // กลุ่มพิเศษจากไฟล์ (เขียนทับของเดิม)
        imp_writeSpecial($pdo, $cid, $n['special']);
        $pdo->commit();
        writeLog($pdo, 'IMPORT_RESOLVE', "อัปเดตผู้เข้าพัก ID $cid ด้วยข้อมูลใหม่จากไฟล์นำเข้า (กลับเข้าพัก)");
        return true;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Import resolve error (id ' . $cid . '): ' . $e->getMessage());
        return false;
    }
}

/** ประมวลผลไฟล์ที่อัปโหลด → รายงาน (total/ok/skipped[]/reopened[]/error) */
function imp_process(PDO $pdo, array $file, array $fixed, int $firstSpecialIdx, array $vMaster, array $cMaster): array {
    $res = ['total' => 0, 'ok' => 0, 'skipped' => [], 'reopened' => [], 'error' => null];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $res['error'] = t('imp.err_upload'); return $res; }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) { $res['error'] = t('imp.err_type'); return $res; }

    try {
        $reader = ($ext === 'csv') ? IOFactory::createReader('Csv') : IOFactory::createReader('Xlsx');
        if ($ext === 'csv') { $reader->setInputEncoding('UTF-8'); $reader->setDelimiter(','); }
        $spreadsheet = $reader->load($file['tmp_name']);
        // formatData=true → วันที่/ตัวเลขอ่านเป็นข้อความตามที่แสดง (กัน serial number ของ Excel)
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    } catch (\Throwable $e) {
        error_log('Import parse error: ' . $e->getMessage());
        $res['error'] = t('imp.err_parse');
        return $res;
    }

    // หัวตาราง 2 แถว → เริ่มอ่านข้อมูลแถวที่ 3 (index 2)
    if (count($rows) < 3) { $res['error'] = t('imp.err_empty'); return $res; }

    $seen = [];   // hash ที่เจอแล้วในไฟล์ (dedup ภายในไฟล์)

    for ($i = 2; $i < count($rows); $i++) {
        $row = $rows[$i];
        // ข้ามแถวว่างล้วน (ไม่นับใน total)
        $nonEmpty = array_filter(array_map(fn($v) => trim((string)$v), $row), 'strlen');
        if (!$nonEmpty) continue;

        $res['total']++;
        $rowNum = $i + 1;                            // เลขแถวจริงในไฟล์ (1-based รวมหัว 2 แถว)

        $d = [];
        foreach ($fixed as $idx => $k) { $d[$k] = trim((string)($row[$idx] ?? '')); }
        $special = imp_readSpecial($row, $firstSpecialIdx, $vMaster, $cMaster);

        $idRaw = str_replace(['-', ' '], '', $d['id_card']);
        $name  = trim($d['prefix'] . ' ' . $d['firstname'] . ' ' . $d['lastname']);
        $label = $name !== '' ? $name : ('— (' . t('imp.tbl_row') . ' ' . $rowNum . ')');

        $skip = function (string $reasonKey) use (&$res, $rowNum, $label) {
            $res['skipped'][] = ['row' => $rowNum, 'label' => $label, 'reason' => t($reasonKey)];
        };

        // req3: ต้องมีเลขบัตร/พาสปอร์ต + ชื่อ
        if ($idRaw === '')          { $skip('imp.skip_no_id');   continue; }
        if ($d['firstname'] === '') { $skip('imp.skip_no_name'); continue; }

        // ตรวจชนิด: 13 หลัก = บัตร ปช. (checksum เข้ม) · อื่น ๆ = พาสปอร์ต (ตัวอักษร/ตัวเลข 5-20)
        if (ctype_digit($idRaw) && strlen($idRaw) === 13) {
            if (!imp_validThaiId($idRaw)) { $skip('imp.skip_bad_id'); continue; }
        } else {
            if (!preg_match('/^[A-Za-z0-9]{5,20}$/', $idRaw)) { $skip('imp.skip_bad_passport'); continue; }
        }

        // req1/2: เทียบซ้ำด้วย hash — ในไฟล์ก่อน แล้วค่อยกับ DB
        $hash = hashID($idRaw);
        if (isset($seen[$hash])) { $skip('imp.skip_dup_file'); continue; }

        // ซ้ำกับข้อมูลเดิมในระบบ → ตรวจสถานะปัจจุบัน
        $mstmt = $pdo->prepare(
            "SELECT c.id, c.prefix, c.firstname, c.lastname, c.gender, c.birthdate, c.id_card_last4,
                    al.subdistrict AS a_tambon, al.district AS a_amphoe, al.province AS a_province
             FROM citizens c
             LEFT JOIN address_lookup al ON c.address_id = al.id
             WHERE c.id_card_hash = ? LIMIT 1"
        );
        $mstmt->execute([$hash]);
        $old = $mstmt->fetch();
        if ($old) {
            $seen[$hash] = true;
            $cid = (int)$old['id'];
            $act = $pdo->prepare("SELECT 1 FROM stay_history WHERE citizen_id = ? AND status = 'Active' LIMIT 1");
            $act->execute([$cid]);
            if ($act->fetchColumn()) {
                // กำลังพักอยู่ → ข้าม (รายงานหลังนำเข้าเสร็จ)
                $skip('imp.skip_dup_active');
                continue;
            }
            // แจ้งออกแล้ว → เปิดการเข้าพักอีกครั้ง (Active stay) + คิวไว้ให้เทียบข้อมูล
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    "INSERT INTO stay_history (citizen_id, check_in, location_type, status, admin_id)
                     VALUES (?, ?, ?, 'Active', ?)"
                )->execute([$cid, imp_normalizeCheckIn($d['check_in']), imp_mapLocation($d['location_type']), $_SESSION['user_id'] ?? 0]);
                $pdo->commit();
                writeLog($pdo, 'CHECK_IN', "เปิดการเข้าพักอีกครั้ง (นำเข้าไฟล์) ID: $cid");
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Import reopen error (row ' . $rowNum . '): ' . $e->getMessage());
                $skip('imp.skip_db_error');
                continue;
            }
            $res['reopened'][] = imp_buildReopenEntry($old, $d, $special, $rowNum);
            continue;
        }

        // ---- คนใหม่ → INSERT ----
        try {
            $pdo->beginTransaction();
            $public_id  = generatePublicId($pdo);
            $address_id = imp_lookupAddressId($pdo, $d['addr_tambon'], $d['addr_amphoe'], $d['addr_province']);

            $ins = $pdo->prepare(
                "INSERT INTO citizens
                 (public_id, id_card_hash, id_card_enc, id_card_last4, prefix, firstname, lastname,
                  gender, birthdate, addr_number, addr_tambon, addr_amphoe, addr_province, address_id, phone_enc)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $ins->execute([
                $public_id, $hash, encryptData($idRaw), substr($idRaw, -4),
                $d['prefix'] !== '' ? $d['prefix'] : null, $d['firstname'], $d['lastname'],
                imp_mapGender($d['gender']), imp_normalizeDate($d['birthdate']),
                $d['addr_number'] !== '' ? $d['addr_number'] : null,
                $d['addr_tambon'] !== '' ? $d['addr_tambon'] : null,
                $d['addr_amphoe'] !== '' ? $d['addr_amphoe'] : null,
                $d['addr_province'] !== '' ? $d['addr_province'] : null,
                $address_id,
                $d['phone'] !== '' ? encryptData($d['phone']) : null,
            ]);
            $cid = (int)$pdo->lastInsertId();

            // กลุ่มพิเศษจากไฟล์ (ไม่ auto-assign ตามอายุ — ไฟล์เป็นแหล่งความจริง เข้าคู่กับ export)
            imp_writeSpecial($pdo, $cid, $special);

            // เช็คอินอัตโนมัติ (Active stay)
            $pdo->prepare(
                "INSERT INTO stay_history (citizen_id, check_in, location_type, status, admin_id)
                 VALUES (?, ?, ?, 'Active', ?)"
            )->execute([$cid, imp_normalizeCheckIn($d['check_in']), imp_mapLocation($d['location_type']), $_SESSION['user_id'] ?? 0]);

            $pdo->commit();
            $seen[$hash] = true;
            $res['ok']++;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Import row error (row ' . $rowNum . '): ' . $e->getMessage());
            $skip('imp.skip_db_error');
        }
    }

    if ($res['ok'] > 0) {
        writeLog($pdo, 'IMPORT_GUESTS', "นำเข้าผู้เข้าพัก {$res['ok']} รายการ · ข้าม " . count($res['skipped']) . " · รวมอ่าน {$res['total']}");
    }
    return $res;
}

// ---------- ดาวน์โหลดเทมเพลตเปล่า (?download=template) ----------
if (($_GET['download'] ?? '') === 'template') {
    while (ob_get_level() > 0) { ob_end_clean(); }   // ทิ้ง HTML ที่ index.php buffer ไว้ (header.php)

    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Template');
    $ss->getDefaultStyle()->getFont()->setName('Sarabun')->setSize(14);

    // หัวช่องเดี่ยว (คอลัมน์ 1-10) · เรียงตามตำแหน่ง
    $singleHeaders = [
        t('imp.col_no'), t('imp.col_idcard') . ' *', t('imp.col_prefix'), t('imp.col_firstname') . ' *',
        t('imp.col_lastname'), t('imp.col_gender'), t('imp.col_birthdate'), t('imp.col_phone'),
        t('imp.col_location'), t('imp.col_checkin'),
    ];
    $addrSubs = [t('imp.col_addr_number'), t('imp.col_tambon'), t('imp.col_amphoe'), t('imp.col_province')];

    // 1) ช่องเดี่ยว — ผสานแนวตั้ง (แถว 1-2)
    $col = 1;
    foreach ($singleHeaders as $h) {
        $L = Coordinate::stringFromColumnIndex($col);
        $sh->mergeCells("{$L}1:{$L}2");
        $sh->setCellValue("{$L}1", $h);
        $sh->getColumnDimension($L)->setAutoSize(true);
        $col++;
    }
    // 2) กลุ่ม "ที่อยู่" — ผสานแนวนอน (แถว 1) + หัวย่อย (แถว 2)
    $addrStart = $col;                       // = 11
    foreach ($addrSubs as $sub) {
        $L = Coordinate::stringFromColumnIndex($col);
        $sh->setCellValue("{$L}2", $sub);
        $sh->getColumnDimension($L)->setAutoSize(true);
        $col++;
    }
    $sh->mergeCells(Coordinate::stringFromColumnIndex($addrStart) . '1:' . Coordinate::stringFromColumnIndex($col - 1) . '1');
    $sh->setCellValue(Coordinate::stringFromColumnIndex($addrStart) . '1', t('imp.grp_address'));

    // 3) กลุ่ม "สถานะกลุ่มพิเศษ" — ผสานแนวนอน (แถว 1) + หัวย่อย vulnerable + custom (แถว 2)
    $spStart = $col;
    foreach ($GLOBALS['V_MASTER'] as $v) {
        $L = Coordinate::stringFromColumnIndex($col);
        $sh->setCellValue("{$L}2", $v['v_name']);
        $sh->getColumnDimension($L)->setAutoSize(true);
        $col++;
    }
    foreach ($GLOBALS['C_MASTER'] as $c) {
        $L = Coordinate::stringFromColumnIndex($col);
        $sh->setCellValue("{$L}2", $c['field_name']);
        $sh->getColumnDimension($L)->setAutoSize(true);
        $col++;
    }
    if ($col > $spStart) {
        $sh->mergeCells(Coordinate::stringFromColumnIndex($spStart) . '1:' . Coordinate::stringFromColumnIndex($col - 1) . '1');
        $sh->setCellValue(Coordinate::stringFromColumnIndex($spStart) . '1', t('imp.grp_special'));
    }

    $lastColIdx = $col - 1;
    $lastCol = Coordinate::stringFromColumnIndex($lastColIdx);

    // แถวตัวอย่าง (แถว 3 — ให้ผู้ใช้ลบก่อนใช้จริง)
    $sample = ['1', '1101700230705', 'นาย', 'สมชาย', 'ใจดี', 'ชาย', '1990-05-01', '0812345678', 'ในศูนย์', date('Y-m-d'),
               '99/1', 'ในเมือง', 'เมืองขอนแก่น', 'ขอนแก่น'];
    $sh->fromArray($sample, null, 'A3');

    $sh->getStyle("A1:{$lastCol}2")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D6EFD']],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="guest_import_template.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}

// ---------- ตัดสินใจข้อมูลซ้ำที่ "แจ้งออกแล้ว" (POST action=resolve) — csrf ผ่าน index.php ----------
$resolve_msg = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'resolve') {
    $cid = (int)($_POST['citizen_id'] ?? 0);
    $decision = ($_POST['decision'] ?? '') === 'new' ? 'new' : 'old';
    $pending = $_SESSION['import_pending'][$cid] ?? null;
    if ($pending) {
        if ($decision === 'new') {
            $resolve_msg = imp_applyNewData($pdo, $cid, $pending['new_apply']) ? t('imp.resolved_new') : t('imp.err_parse');
        } else {
            $resolve_msg = t('imp.resolved_old');
        }
        unset($_SESSION['import_pending'][$cid]);
    }
}

// ---------- รับไฟล์นำเข้า (POST) — csrf_verify() ทำที่ index.php แล้ว ----------
$report = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_FILES['import_file'])) {
    $report = imp_process($pdo, $_FILES['import_file'], $FIXED_FIELDS, $FIRST_SPECIAL_IDX, $V_MASTER, $C_MASTER);
    if (!empty($report['reopened'])) {
        if (!isset($_SESSION['import_pending'])) $_SESSION['import_pending'] = [];
        foreach ($report['reopened'] as $rp) {
            $_SESSION['import_pending'][$rp['citizen_id']] = $rp;
        }
    }
}
?>

<div class="container-fluid py-3" style="max-width: 1100px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-arrow-up"></i> <?= e('imp.title') ?></h4>
        <a href="index.php?page=guest_list" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> <?= e('imp.back_to_list') ?>
        </a>
    </div>
    <p class="text-body-secondary"><?= e('imp.subtitle') ?></p>

    <!-- เตือน: การนำเข้าข้ามขั้นตอนยินยอม PDPA -->
    <div class="alert alert-danger border-2 d-flex align-items-start gap-2">
        <i class="bi bi-shield-exclamation fs-4"></i>
        <div>
            <strong><?= e('imp.warn_consent_title') ?></strong><br>
            <span class="small"><?= e('imp.warn_consent') ?></span>
        </div>
    </div>

    <?php if ($resolve_msg !== null): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($resolve_msg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- คำเตือนเรื่องแยกช่อง + เทมเพลต -->
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
        <div class="flex-grow-1">
            <i class="bi bi-exclamation-triangle-fill"></i> <strong><?= e('imp.warn_title') ?></strong><br>
            <span class="small"><?= e('imp.warn_split') ?></span>
        </div>
        <a href="index.php?page=guest_import&download=template" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-download"></i> <?= e('imp.download_template') ?>
        </a>
    </div>

    <!-- ฟอร์มอัปโหลด -->
    <form method="post" action="index.php?page=guest_import" enctype="multipart/form-data" class="card card-body shadow-sm mb-4">
        <?= csrf_field() ?>
        <label class="form-label fw-bold"><?= e('imp.upload_label') ?></label>
        <div class="input-group">
            <input type="file" name="import_file" class="form-control" accept=".xlsx,.csv" required>
            <button type="submit" class="btn btn-warning fw-bold">
                <i class="bi bi-upload"></i> <?= e('imp.btn_import') ?>
            </button>
        </div>
    </form>

    <?php if ($report !== null): ?>
        <?php if ($report['error']): ?>
            <div class="alert alert-danger"><i class="bi bi-x-octagon-fill"></i> <?= htmlspecialchars($report['error'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php else: ?>
            <div class="card shadow-sm">
                <div class="card-header fw-bold bg-body-tertiary"><i class="bi bi-clipboard-check"></i> <?= e('imp.report_title') ?></div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-3 mb-3">
                        <span class="badge text-bg-secondary fs-6"><?= e('imp.report_total', ['n' => $report['total']]) ?></span>
                        <span class="badge text-bg-success fs-6"><?= e('imp.report_ok', ['n' => $report['ok']]) ?></span>
                        <span class="badge text-bg-warning fs-6"><?= e('imp.report_skipped', ['n' => count($report['skipped'])]) ?></span>
                        <?php if (!empty($report['reopened'])): ?>
                            <span class="badge text-bg-info fs-6"><?= e('imp.report_reopened', ['n' => count($report['reopened'])]) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($report['skipped'])): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:90px;"><?= e('imp.tbl_row') ?></th>
                                        <th><?= e('imp.tbl_name') ?></th>
                                        <th><?= e('imp.tbl_reason') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report['skipped'] as $s): ?>
                                        <tr>
                                            <td class="text-center"><?= (int)$s['row'] ?></td>
                                            <td><?= htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-danger"><?= htmlspecialchars($s['reason'], ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-body-secondary"><i class="bi bi-check2-all"></i> <?= e('imp.no_skips') ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['import_pending'])): ?>
        <div class="card shadow-sm mt-4 border-info">
            <div class="card-header fw-bold text-bg-info">
                <i class="bi bi-arrow-left-right"></i> <?= e('imp.reopen_title') ?>
                <span class="badge text-bg-light ms-2"><?= count($_SESSION['import_pending']) ?></span>
            </div>
            <div class="card-body">
                <p class="text-body-secondary small mb-3"><i class="bi bi-info-circle"></i> <?= e('imp.reopen_note') ?></p>

                <?php foreach ($_SESSION['import_pending'] as $cid => $p): ?>
                    <?php $o = $p['old_display']; $n = $p['new_display'];
                          $rowfn = function ($label, $ov, $nv) {
                              $diff = trim((string)$ov) !== trim((string)$nv);
                              echo '<tr>';
                              echo '<td class="fw-bold bg-body-tertiary" style="width:22%">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>';
                              echo '<td style="background:#fff4f4">' . htmlspecialchars(($ov === '' ? '-' : $ov), ENT_QUOTES, 'UTF-8') . '</td>';
                              echo '<td style="background:#f4fff4' . ($diff ? '"><span class="text-danger fw-bold">' : '">') . htmlspecialchars(($nv === '' ? '-' : $nv), ENT_QUOTES, 'UTF-8') . ($diff ? ' <i class="bi bi-exclamation-circle"></i></span>' : '') . '</td>';
                              echo '</tr>';
                          };
                    ?>
                    <div class="border rounded p-3 mb-3">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-2">
                                <thead class="text-center">
                                    <tr>
                                        <th><?= e('imp.cmp_topic') ?></th>
                                        <th style="background:#fff4f4">💾 <?= e('imp.cmp_old') ?></th>
                                        <th style="background:#f4fff4">📝 <?= e('imp.cmp_new') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $rowfn(t('imp.cmp_name'),      $o['name'],   $n['name']);
                                        $rowfn(t('imp.cmp_last4'),     $o['last4'],  $n['last4']);
                                        $rowfn(t('imp.cmp_gender'),    $o['gender'], $n['gender']);
                                        $rowfn(t('imp.cmp_birthdate'), $o['birth'],  $n['birth']);
                                        $rowfn(t('imp.cmp_address'),   $o['addr'],   $n['addr']);
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        <form method="post" action="index.php?page=guest_import" class="d-flex flex-wrap gap-2 justify-content-end">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="resolve">
                            <input type="hidden" name="citizen_id" value="<?= (int)$cid ?>">
                            <button type="submit" name="decision" value="old" class="btn btn-primary">
                                <i class="bi bi-shield-check"></i> <?= e('imp.cmp_use_old') ?>
                            </button>
                            <button type="submit" name="decision" value="new" class="btn btn-success">
                                <i class="bi bi-pencil-square"></i> <?= e('imp.cmp_use_new') ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
