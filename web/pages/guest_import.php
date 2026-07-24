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
 *   กลุ่ม "ที่อยู่ตามทะเบียนบ้าน" และ "ที่อยู่ตามภูมิลำเนา": ที่อยู่, ตำบล, อำเภอ, จังหวัด, รหัสไปรษณีย์
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
    // กลุ่ม "ที่อยู่ตามทะเบียนบ้าน" · รหัสไปรษณีย์ใช้ช่วยเลือกแถวใน address_lookup (ตำบลชื่อซ้ำแยกตามเขตไปรษณีย์)
    10 => 'addr_number',
    11 => 'addr_tambon',
    12 => 'addr_amphoe',
    13 => 'addr_province',
    14 => 'addr_zipcode',
    // กลุ่ม "ภูมิลำเนา" — เว้นว่างได้ทั้งชุด = ไม่มีข้อมูลภูมิลำเนา (ระบบจะ fallback ไปที่อยู่ตามทะเบียนบ้านตอนแสดงผล)
    15 => 'home_addr_number',
    16 => 'home_addr_tambon',
    17 => 'home_addr_amphoe',
    18 => 'home_addr_province',
    19 => 'home_addr_zipcode',
];
$FIRST_SPECIAL_IDX = 20;   // ตำแหน่งเริ่มของกลุ่ม "สถานะกลุ่มพิเศษ" (vulnerable + custom)

// master ของกลุ่มพิเศษ — ลำดับตรงกับ export_excel (ORDER BY id ASC) เพื่อจับคู่ตามตำแหน่งได้
$V_MASTER = $pdo->query("SELECT id, v_name FROM vulnerable_master ORDER BY id ASC")->fetchAll();
// ดึง field_type มาด้วย — ชีต "คำแนะนำ" ในเทมเพลตใช้แยกว่าช่องไหนเป็นเช็คบ็อก ช่องไหนเป็นข้อความ
$C_MASTER = $pdo->query("SELECT id, field_name, field_type FROM custom_field_master WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

/** หลักตรวจ (หลักที่ 13) ที่ถูกต้องของเลขบัตร — ใช้บอกผู้ใช้ว่าควรเป็นเลขอะไรเวลาแจ้งว่ากรอกผิด */
function imp_thaiIdCheckDigit(string $id): int {
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int)($id[$i] ?? 0) * (13 - $i);
    return (11 - ($sum % 11)) % 10;
}

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

/** ค้นหา address_id จากชื่อ ตำบล/อำเภอ/จังหวัด — ตรรกะจริงอยู่ที่ core/functions.php (ชุดเดียวกับฟอร์ม/API) */
function imp_lookupAddressId(PDO $pdo, string $tambon, string $amphoe, string $province, ?string $zipcode = null): ?int {
    return lookupAddressIdByName($pdo, $tambon, $amphoe, $province, $zipcode);
}

/**
 * อ่านกลุ่มคอลัมน์ "ภูมิลำเนา" จากแถวไฟล์ → ค่าที่จะเขียนลง citizens
 * ว่างทั้งชุด = ไม่มีข้อมูลภูมิลำเนา (same=0 + NULL) — หน้าแสดงผลจะ fallback ไปที่อยู่ตามทะเบียนบ้านเอง
 * เจตนา: ไฟล์นำเข้าไม่ควร "เดา" ว่าที่อยู่ที่ให้มาเป็นประเภทไหน จึงแยกคอลัมน์ให้ชัด
 */
function imp_readHome(PDO $pdo, array $d): array {
    $num = trim((string)($d['home_addr_number']   ?? ''));
    $t   = trim((string)($d['home_addr_tambon']   ?? ''));
    $a   = trim((string)($d['home_addr_amphoe']   ?? ''));
    $p   = trim((string)($d['home_addr_province'] ?? ''));
    $z   = trim((string)($d['home_addr_zipcode']  ?? ''));

    if ($num === '' && $t === '' && $a === '' && $p === '' && $z === '') {
        return ['same' => 0, 'address_id' => null, 'number' => null, 'has' => false];
    }
    return [
        'same'       => 0,
        'address_id' => imp_lookupAddressId($pdo, $t, $a, $p, $z !== '' ? $z : null),
        'number'     => $num !== '' ? $num : null,
        'has'        => true,
    ];
}

/** normalize วันที่ → 'Y-m-d' (ค.ศ.) หรือ null · รับ ปปปป-ดด-วว, วว/ดด/ปปปป, และปี พ.ศ. (>2400) */
function imp_normalizeDate(string $s): ?string {
    // ตรรกะย้ายไปอยู่ที่ core/functions.php::normalizeDateInput() แล้ว (ใช้ร่วมกับ guest_check.php)
    // คงชื่อเดิมไว้เป็น wrapper เพื่อไม่ต้องไล่แก้ call site ในไฟล์นี้
    return normalizeDateInput($s);
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

/**
 * ติดกลุ่มเปราะบางตามอายุอัตโนมัติ — ให้ผลตรงกับหน้าลงทะเบียน (guest_check.php)
 *   v_id 1 = "0-5 ขวบ" (อายุ <= 5) · v_id 2 = "ผู้สูงอายุ" (อายุ >= 60)
 *
 * เดิมการนำเข้าไฟล์ไม่ติดให้เลย ต่างจากการบันทึกผ่านฟอร์ม → นำเข้าทีละหลายร้อยคน
 * เด็กเล็ก/ผู้สูงอายุจะไม่ถูกแท็กสักคน เว้นแต่คนทำไฟล์จะติ๊กเองครบทุกแถว
 *
 * กติกา:
 *  - **union** กับที่ติ๊กมาในไฟล์ ไม่ลบของเดิม (เจตนาผู้กรอกมาก่อนเสมอ)
 *  - ติดเฉพาะ v_id ที่ยังมีอยู่จริงใน vulnerable_master — ผู้ดูแลอาจลบ/แก้รายการนี้ทีหลัง
 *    ถ้าไม่เช็คก่อนจะ INSERT แล้ว FK พังทั้งแถว
 *  - **ไม่มีวันเกิด = ไม่ติดอะไรเลย** (ต่างจาก guest_check ที่ปล่อยให้ age = 0 แล้วติด "0-5 ขวบ" ให้
 *    ซึ่งเป็นผลข้างเคียงที่ไม่ถูกต้อง — คนไม่มีวันเกิดไม่ใช่เด็ก)
 *  - วันเกิดที่ส่งเข้ามาต้องผ่าน imp_normalizeDate() แล้ว (แปลง พ.ศ. -> ค.ศ. ให้เรียบร้อย)
 */
function imp_autoTagByAge(array $vulnerable, ?string $birthdate, array $vMaster): array {
    if ($birthdate === null || $birthdate === '' || $birthdate === '0000-00-00') return $vulnerable;
    try {
        $age = (new DateTime())->diff(new DateTime($birthdate))->y;
    } catch (Throwable $e) {
        return $vulnerable;
    }
    $existing = array_map('intval', array_column($vMaster, 'id'));
    $current  = array_map('intval', $vulnerable);
    foreach ([[1, $age <= 5], [2, $age >= 60]] as [$vid, $matched]) {
        if ($matched && in_array($vid, $existing, true) && !in_array($vid, $current, true)) {
            $vulnerable[] = $vid;
            $current[]    = $vid;
        }
    }
    return $vulnerable;
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
        'addr_amphoe' => $d['addr_amphoe'], 'addr_province' => $d['addr_province'], 'addr_zipcode' => $d['addr_zipcode'],
        'home_addr_number' => $d['home_addr_number'], 'home_addr_tambon' => $d['home_addr_tambon'],
        'home_addr_amphoe' => $d['home_addr_amphoe'], 'home_addr_province' => $d['home_addr_province'],
        'home_addr_zipcode' => $d['home_addr_zipcode'],
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
            'home'   => imp_fmtAddr($old['h_tambon'] ?? '', $old['h_amphoe'] ?? '', $old['h_province'] ?? ''),
        ],
        'new_display' => [
            'name'   => trim($d['prefix'] . $d['firstname'] . ' ' . $d['lastname']),
            'last4'  => substr(str_replace(['-', ' '], '', $d['id_card']), -4),
            'gender' => imp_genderLabel($new_apply['gender']),
            'birth'  => $new_apply['birthdate'] ?? '',
            'addr'   => imp_fmtAddr($d['addr_tambon'], $d['addr_amphoe'], $d['addr_province']),
            'home'   => imp_fmtAddr($d['home_addr_tambon'], $d['home_addr_amphoe'], $d['home_addr_province']),
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
        $address_id = imp_lookupAddressId($pdo, $n['addr_tambon'], $n['addr_amphoe'], $n['addr_province'], $n['addr_zipcode'] ?? null);
        if ($address_id !== null) { $sets[] = "address_id = ?"; $vals[] = $address_id; }
        // ภูมิลำเนา: เขียนทับทั้งชุดเมื่อไฟล์มีข้อมูลมา (ไม่งั้นปล่อยของเดิมไว้)
        $home = imp_readHome($pdo, $n);
        if ($home['has']) {
            $sets[] = "home_same_as_reg = ?"; $vals[] = $home['same'];
            $sets[] = "home_address_id = ?";  $vals[] = $home['address_id'];
            $sets[] = "home_addr_number = ?"; $vals[] = $home['number'];
        }
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
        // ติด "0-5 ขวบ" / "ผู้สูงอายุ" ให้อัตโนมัติจากวันเกิด แบบเดียวกับหน้าลงทะเบียน
        // ทำที่นี่จุดเดียวเพื่อให้ครอบทั้งเส้นทาง "คนใหม่" และ "กลับเข้าพัก" (ทั้งคู่ใช้ $special ตัวนี้ต่อ)
        $special['vulnerable'] = imp_autoTagByAge($special['vulnerable'], imp_normalizeDate($d['birthdate']), $vMaster);

        $idRaw = str_replace(['-', ' '], '', $d['id_card']);
        $name  = trim($d['prefix'] . ' ' . $d['firstname'] . ' ' . $d['lastname']);
        $label = $name !== '' ? $name : ('— (' . t('imp.tbl_row') . ' ' . $rowNum . ')');

        // $extra = รายละเอียดเพิ่มต่อท้ายเหตุผล — เดิมรายงานบอกแค่ "checksum ไม่ผ่าน" ลอย ๆ
        // ผู้ใช้ไม่รู้ว่าแถวไหนเลขอะไรผิด ต้องไล่เดาเอง จึงแนบเลขที่กรอกมาให้เห็นตรง ๆ
        $skip = function (string $reasonKey, string $extra = '') use (&$res, $rowNum, $label) {
            $res['skipped'][] = ['row' => $rowNum, 'label' => $label,
                                 'reason' => t($reasonKey) . ($extra !== '' ? ' ' . $extra : '')];
        };

        // req3: ต้องมีเลขบัตร/พาสปอร์ต + ชื่อ
        if ($idRaw === '')          { $skip('imp.skip_no_id');   continue; }
        if ($d['firstname'] === '') { $skip('imp.skip_no_name'); continue; }

        // ตรวจชนิด: 13 หลัก = บัตร ปช. (checksum เข้ม) · อื่น ๆ = พาสปอร์ต (ตัวอักษร/ตัวเลข 5-20)
        if (ctype_digit($idRaw) && strlen($idRaw) === 13) {
            if (!imp_validThaiId($idRaw)) {
                // บอกให้ครบว่าเลขอะไร และหลักสุดท้ายที่ถูกต้องคืออะไร — แก้ในไฟล์ได้ทันทีโดยไม่ต้องเดา
                $skip('imp.skip_bad_id', t('imp.skip_bad_id_detail', [
                    'id'    => $idRaw,
                    'digit' => (string)imp_thaiIdCheckDigit($idRaw),
                ]));
                continue;
            }
        } else {
            if (!preg_match('/^[A-Za-z0-9]{5,20}$/', $idRaw)) {
                // ค่านี้มาจากไฟล์ผู้ใช้ตรง ๆ — ตัดความยาวกันเซลล์ในรายงานยืดผิดรูป
                // (ฝั่งแสดงผลผ่าน htmlspecialchars อยู่แล้ว จึงไม่มีปัญหาเรื่อง XSS)
                $skip('imp.skip_bad_passport', t('imp.skip_bad_val_detail', ['id' => mb_substr($idRaw, 0, 40)]));
                continue;
            }
        }

        // req1/2: เทียบซ้ำด้วย hash — ในไฟล์ก่อน แล้วค่อยกับ DB
        $hash = hashID($idRaw);
        if (isset($seen[$hash])) { $skip('imp.skip_dup_file'); continue; }

        // ซ้ำกับข้อมูลเดิมในระบบ → ตรวจสถานะปัจจุบัน
        $mstmt = $pdo->prepare(
            "SELECT c.id, c.prefix, c.firstname, c.lastname, c.gender, c.birthdate, c.id_card_last4,
                    al.subdistrict AS a_tambon, al.district AS a_amphoe, al.province AS a_province,
                    hl.subdistrict AS h_tambon, hl.district AS h_amphoe, hl.province AS h_province
             FROM citizens c
             LEFT JOIN address_lookup al ON c.address_id = al.id
             LEFT JOIN address_lookup hl ON c.home_address_id = hl.id
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
            $address_id = imp_lookupAddressId($pdo, $d['addr_tambon'], $d['addr_amphoe'], $d['addr_province'], $d['addr_zipcode'] ?? null);
            $home       = imp_readHome($pdo, $d);

            $ins = $pdo->prepare(
                "INSERT INTO citizens
                 (public_id, id_card_hash, id_card_enc, id_card_last4, prefix, firstname, lastname,
                  gender, birthdate, addr_number, addr_tambon, addr_amphoe, addr_province, address_id,
                  home_same_as_reg, home_address_id, home_addr_number, phone_enc)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
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
                $home['same'], $home['address_id'], $home['number'],
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
    // กลุ่มที่อยู่ 2 ชุด (ทะเบียนบ้าน + ภูมิลำเนา) — หัวย่อยเหมือนกัน ต่างกันที่ชื่อกลุ่มแถวบน
    $addrSubs   = [t('imp.col_addr_number'), t('imp.col_tambon'), t('imp.col_amphoe'), t('imp.col_province'), t('imp.col_zipcode')];
    $addrGroups = [t('imp.grp_address'), t('imp.grp_home')];

    // 1) ช่องเดี่ยว — ผสานแนวตั้ง (แถว 1-2)
    $col = 1;
    foreach ($singleHeaders as $h) {
        $L = Coordinate::stringFromColumnIndex($col);
        $sh->mergeCells("{$L}1:{$L}2");
        $sh->setCellValue("{$L}1", $h);
        $sh->getColumnDimension($L)->setAutoSize(true);
        $col++;
    }
    // 2) กลุ่มที่อยู่ 2 ชุด — ผสานแนวนอน (แถว 1) + หัวย่อย (แถว 2)
    foreach ($addrGroups as $grpName) {
        $addrStart = $col;                   // ชุดแรกเริ่มที่ 11, ชุดภูมิลำเนาเริ่มที่ 15
        foreach ($addrSubs as $sub) {
            $L = Coordinate::stringFromColumnIndex($col);
            $sh->setCellValue("{$L}2", $sub);
            $sh->getColumnDimension($L)->setAutoSize(true);
            $col++;
        }
        $sh->mergeCells(Coordinate::stringFromColumnIndex($addrStart) . '1:' . Coordinate::stringFromColumnIndex($col - 1) . '1');
        $sh->setCellValue(Coordinate::stringFromColumnIndex($addrStart) . '1', $grpName);
    }

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
    // 🐞 เลขเดิม 1101700230705 **ตก checksum** (หลักตรวจที่ถูกคือ 8 ไม่ใช่ 5) → ใครลอกแถวตัวอย่าง
    // ไปใช้จะถูก imp_validThaiId() ข้ามทิ้งพร้อมข้อความ "เลขบัตรประชาชนไม่ถูกต้อง" ทันที
    $sample = ['1', '1101700230708', 'นาย', 'สมชาย', 'ใจดี', 'ชาย', '1990-05-01', '0812345678', 'ในศูนย์', date('Y-m-d'),
               '99/1', 'ในเมือง', 'เมืองขอนแก่น', 'ขอนแก่น', '40000'];
    $sh->fromArray($sample, null, 'A3');

    $sh->getStyle("A1:{$lastCol}2")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D6EFD']],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);

    /**
     * ชีตที่ 2 "คำแนะนำ" — อธิบายกลุ่ม "สถานะกลุ่มพิเศษ" ซึ่งเป็นคอลัมน์ที่ไม่ตายตัว
     * (สร้างจาก vulnerable_master + custom_field_master ณ เวลาที่กดดาวน์โหลด)
     *
     * ทำไมต้องเป็น "ชีตแยก" ไม่ใช่แถวหมายเหตุในชีตข้อมูล:
     * imp_process() อ่านด้วย getActiveSheet() แล้วไล่ทุกแถวตั้งแต่แถว 3 เป็นต้นไป —
     * ถ้าเขียนคำแนะนำเป็นแถวในชีตเดียวกัน ผู้ใช้ที่ลืมลบจะถูกอ่านเป็นข้อมูลคนหนึ่ง
     * ชีตแยกจึงปลอดภัยกว่า (ต้อง setActiveSheetIndex(0) ปิดท้ายให้ชีตข้อมูลเป็นชีตที่ active)
     */
    $gs = $ss->createSheet();
    $gs->setTitle(t('imp.guide_sheet_title'));
    $gs->getColumnDimension('A')->setWidth(10);
    $gs->getColumnDimension('B')->setWidth(34);
    $gs->getColumnDimension('C')->setWidth(24);
    $gs->getColumnDimension('D')->setWidth(60);

    $r = 1;
    $gs->setCellValue("A$r", t('imp.guide_heading'));
    $gs->mergeCells("A$r:D$r");
    $gs->getStyle("A$r")->getFont()->setBold(true)->setSize(16);
    $r += 2;

    foreach ([t('imp.guide_dynamic'), t('imp.guide_redownload'), t('imp.guide_order'),
              t('imp.guide_checkbox'), t('imp.guide_text'), t('imp.guide_autotag')] as $line) {
        $gs->setCellValue("A$r", $line);
        $gs->mergeCells("A$r:D$r");
        $gs->getStyle("A$r")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $gs->getRowDimension($r)->setRowHeight(-1);
        $r++;
    }
    $r++;

    // ตารางสรุปคอลัมน์กลุ่มพิเศษที่ "มีอยู่จริงในไฟล์ฉบับนี้" — ผู้ใช้เทียบกับหน้าตั้งค่าได้ทันที
    $gs->setCellValue("A$r", t('imp.guide_current'));
    $gs->getStyle("A$r")->getFont()->setBold(true)->setSize(14);
    $r++;
    $headRow = $r;
    foreach ([['A', t('imp.guide_col')], ['B', t('imp.guide_name')], ['C', t('imp.guide_type')], ['D', t('imp.guide_fill')]] as [$L, $txt]) {
        $gs->setCellValue("{$L}{$r}", $txt);
    }
    $gs->getStyle("A{$headRow}:D{$headRow}")->applyFromArray([
        'font'    => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D6EFD']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ]);
    $r++;

    // เดินคอลัมน์ชุดเดียวกับที่เพิ่งสร้างไว้ในชีตข้อมูล (เริ่มที่ $spStart) ให้ตัวอักษรคอลัมน์ตรงกันเป๊ะ
    $spCol = $spStart;
    foreach ($GLOBALS['V_MASTER'] as $v) {
        $gs->setCellValue("A$r", Coordinate::stringFromColumnIndex($spCol));
        $gs->setCellValue("B$r", $v['v_name']);
        $gs->setCellValue("C$r", t('imp.guide_type_vuln'));
        $gs->setCellValue("D$r", t('imp.guide_vuln_fill'));
        $gs->getStyle("D$r")->getAlignment()->setWrapText(true);
        $spCol++; $r++;
    }
    foreach ($GLOBALS['C_MASTER'] as $c) {
        $isCheck = ($c['field_type'] ?? 'text') === 'checkbox';
        $gs->setCellValue("A$r", Coordinate::stringFromColumnIndex($spCol));
        $gs->setCellValue("B$r", $c['field_name']);
        $gs->setCellValue("C$r", $isCheck ? t('imp.guide_type_check') : t('imp.guide_type_text'));
        // ฟิลด์ข้อความมีกับดัก: imp_readSpecial แปลงค่าที่ "ดูเหมือนติ๊ก" เป็น 'Yes' ไม่ว่าฟิลด์ชนิดไหน
        $gs->setCellValue("D$r", $isCheck ? t('imp.guide_check_custom') : t('imp.guide_text_fill'));
        $gs->getStyle("D$r")->getAlignment()->setWrapText(true);
        $spCol++; $r++;
    }
    if ($spCol === $spStart) {   // ไม่มีกลุ่มเปราะบาง/ฟิลด์พิเศษเลย
        $gs->setCellValue("A$r", t('imp.guide_none'));
        $gs->mergeCells("A$r:D$r");
    } else {
        $gs->getStyle("A{$headRow}:D" . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    $ss->setActiveSheetIndex(0);   // ชีตข้อมูลต้องเป็นชีตที่ active — imp_process อ่าน getActiveSheet()

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
            <br><span class="small"><i class="bi bi-sliders"></i> <?= e('imp.warn_special') ?></span>
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
                                        $rowfn(t('imp.cmp_home'),      $o['home'] ?? '-', $n['home'] ?? '-');
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
