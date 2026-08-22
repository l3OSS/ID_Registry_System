<?php
/**
 * Helper Functions for Data Formatting
 */

/**
 * Redirect ที่ปลอดภัยแม้ header.php พ่น HTML ไปแล้ว
 * — ล้าง output buffer ที่ index.php เปิดไว้ (ob_start) ก่อนส่ง Location
 *   ไม่งั้นจะได้ "headers already sent" + HTML ครึ่งหน้าหลุดไปกับ 302
 * ห้ามเรียก header('Location:') ตรง ๆ ในไฟล์ pages/ หรือ guard ใน core/ — ใช้ตัวนี้แทน
 */
function redirect(string $url): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . $url);
    exit;
}

/**
 * โหลดค่าตั้งค่าเว็บ (ชื่อระบบ/สร้อย/โลโก้/สวิตช์ PDPA) จากตาราง settings แถวแรก
 * คืน default ถ้าตาราง/แถวยังไม่มี — cache ต่อ request
 *
 * `pdpa_enabled` คืนเป็น int (1 = เปิด, 0 = ปิด) — DB เก่าที่ยังไม่ได้ migrate ไม่มีคอลัมน์นี้
 * จึง SELECT แยกและ default = 1 (เปิด) เพื่อไม่ให้พฤติกรรมเดิมเปลี่ยนก่อนอัปเดต
 */
function appSettings(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $defaults = ['app_name' => 'ระบบเก็บข้อมูลการเข้าพัก (Reg System)', 'site_subtitle' => '', 'logo_path' => ''];
    try {
        $row = $pdo->query("SELECT app_name, site_subtitle, logo_path FROM settings ORDER BY id ASC LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            foreach ($row as $k => $v) if ($v !== null && $v !== '') $defaults[$k] = $v;
        }
    } catch (Throwable $e) { /* ตารางยังไม่ครบ — ใช้ default */ }

    $defaults['pdpa_enabled'] = 1;
    $defaults['site_url']     = '';
    $defaults['qr_ip']        = '192.168.1.50';
    try {
        $row = $pdo->query("SELECT pdpa_enabled, site_url, qr_ip FROM settings ORDER BY id ASC LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $defaults['pdpa_enabled'] = (int)$row['pdpa_enabled'];
            if ($row['site_url'] !== null && $row['site_url'] !== '') $defaults['site_url'] = $row['site_url'];
            if ($row['qr_ip'] !== null && $row['qr_ip'] !== '')       $defaults['qr_ip']    = $row['qr_ip'];
        }
    } catch (Throwable $e) { /* ยังไม่ได้ migrate — ใช้ default (PDPA เปิด, ไม่มี site_url) */ }

    // คำเรียกหน่วยข้อมูลหลัก (configurable terminology) — SELECT แยก tolerant เผื่อ DB เก่ายังไม่มีคอลัมน์
    // ว่าง = ให้ t() ใช้ fallback 'entity.default' จากไฟล์ภาษาแทน
    $defaults['entity_term'] = '';
    try {
        $v = $pdo->query("SELECT entity_term FROM settings ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($v !== null && $v !== false && $v !== '') $defaults['entity_term'] = (string)$v;
    } catch (Throwable $e) { /* ยังไม่ได้ migrate — ใช้ fallback จากไฟล์ภาษา */ }

    return $cache = $defaults;
}

/**
 * ระบบยินยอมให้บันทึกข้อมูล (PDPA) เปิดอยู่หรือไม่ — ใช้เป็นประตูเดียวทั้งระบบ
 * (guest_form / dashboard QR / api/sync_send)
 */
function pdpaEnabled(PDO $pdo): bool {
    return appSettings($pdo)['pdpa_enabled'] === 1;
}

/**
 * ตรวจจับ URL รากของระบบจาก $_SERVER (ใช้เป็นค่าเริ่มต้น/สำรองของ site_url)
 * เช่น เปิด http://localhost/Reg/web/index.php?page=... → คืน http://localhost/Reg/web
 */
function detectSiteUrl(): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir   = str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/'));
    return $proto . '://' . $host . rtrim($dir, '/');
}

/**
 * URL รากของระบบ — คืน site_url ที่ตั้งไว้ (ตัด / ท้ายออก) ถ้าว่างให้ตรวจจับจาก $_SERVER
 */
function siteUrl(PDO $pdo): string {
    $u = trim(appSettings($pdo)['site_url']);
    return $u !== '' ? rtrim($u, '/') : detectSiteUrl();
}

/**
 * ประกอบ URL หน้ายินยอม (guest_display) สำหรับ QR ให้แท็บเล็ตในวง LAN สแกน
 *
 * สูตร (ตามที่กำหนด): ตัด scheme://host/ ออกจาก $siteUrl ให้เหลือเฉพาะโฟลเดอร์ (เช่น reg/web)
 * แล้วประกอบใหม่เป็น  http://<qr_ip>/<โฟลเดอร์>/pages/guest_display.php?d=<display_key>
 * ระวังเครื่องหมาย "/": ตัด/เติมให้เหลือขีดเดียวทุกรอยต่อ
 */
function buildDisplayQrUrl(string $siteUrl, string $qrIp, string $displayKey): string {
    // 1) ตัด scheme://host/ ต้นทางออก เหลือเฉพาะ path โฟลเดอร์ เช่น "reg/web"
    $path = preg_replace('#^[a-z][a-z0-9+.\-]*://[^/]+/?#i', '', trim($siteUrl));
    $path = trim((string)$path, '/');

    // 2) ทำความสะอาด qr_ip (เผลอใส่ scheme หรือ / มาก็ตัดออก)
    $ip = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', trim($qrIp));
    $ip = trim((string)$ip, '/');
    if ($ip === '') $ip = '192.168.1.50';

    // 3) ประกอบใหม่ — เติม / คั่นเฉพาะเมื่อมีส่วนโฟลเดอร์
    $base = 'http://' . $ip . '/' . ($path !== '' ? $path . '/' : '');
    return $base . 'pages/guest_display.php?d=' . rawurlencode($displayKey);
}

/**
 * แปลงวันที่ ค.ศ. เป็น พ.ศ. พร้อมรูปแบบภาษาไทย
 */
function dateThai($strDate) {
    if (empty($strDate) || in_array($strDate, ['0000-00-00', '0000-00-00 00:00:00', 'null'])) {
        return '<span class="text-muted">-</span>';
    }

    $timestamp = strtotime($strDate);

    // แก้ไขกรณีวันที่ถูกบันทึกเป็นปี พ.ศ. (25xx) ลงในฐานข้อมูลโดยตรง
    if (!$timestamp || $timestamp < 0) {
        $parts = explode('-', explode(' ', $strDate)[0]);
        if (count($parts) == 3 && $parts[0] > 2400) {
            $parts[0] -= 543;
            $timestamp = strtotime(implode('-', $parts));
        }
    }

    if (!$timestamp) return '<span class="text-muted">-</span>';

    $thaiMonths = ["","ม.ค.","ก.พ.","มี.ค.","เม.ย.","พ.ค.","มิ.ย.","ก.ค.","ส.ค.","ก.ย.","ต.ค.","พ.ย.","ธ.ค."];
    $y = date("Y", $timestamp) + 543;
    $m = $thaiMonths[date("n", $timestamp)];
    $d = date("j", $timestamp);
    $t = date("H:i", $timestamp);

    return "<strong>$d $m $y</strong><br><small class='text-muted'>$t น.</small>";
}

/**
 * แปลงค่าวันที่ที่ผู้ใช้กรอก → 'Y-m-d' · แปลงไม่ได้คืน null
 *
 * ทำไมต้องมี: ช่องวันเกิดในฟอร์มเป็น <input type="text"> (flatpickr + ปฏิทิน พ.ศ.)
 * ไม่ใช่ type="date" → เบราว์เซอร์ไม่กันค่าประหลาด ถ้า JS ไม่ทำงานหรือผู้ใช้วางข้อความทับ
 * ค่าดิบจะไหลเข้า SQL แล้วชน `SQLSTATE[22007] Incorrect date value` = **INSERT ล้มทั้งแถว
 * ผู้ใช้เสียข้อมูลที่กรอกมาทั้งหมด** (เจอตอนทดสอบ 2026-07-24)
 *
 * รับ 'Y-m-d' (ที่ flatpickr ส่งมาปกติ) และ 'd/m/Y' · ปี > 2400 ถือเป็น พ.ศ. แล้วลบ 543
 * ตรรกะชุดเดียวกับที่ guest_import.php ใช้มาก่อน — ย้ายขึ้นมาไว้ที่เดียวให้ใช้ร่วมกัน
 */
function normalizeDateInput(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '' || $s === '0000-00-00') return null;

    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m))     { $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3]; }
    elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $m)) { $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3]; }
    else return null;

    if ($y > 2400) $y -= 543;                 // เผื่อกรอกเป็น พ.ศ.
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/**
 * แปลงค่าวันที่+เวลาที่ผู้ใช้กรอก → 'Y-m-d H:i:s' · แปลงไม่ได้คืนค่า $fallback
 * ใช้กับคอลัมน์ที่เป็น NOT NULL (เช่น stay_history.check_in) ซึ่งคืน null ไม่ได้
 */
function normalizeDateTimeInput(?string $s, ?string $fallback = null): string {
    $fallback = $fallback ?? date('Y-m-d H:i:s');
    $s = trim((string)$s);
    if ($s === '') return $fallback;

    $date = normalizeDateInput($s);
    if ($date === null) return $fallback;

    // ดึงเวลาต่อท้ายถ้ามี (flatpickr ส่ง 'Y-m-d H:i' สำหรับช่องวันเข้าพัก)
    $time = '00:00:00';
    if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?\s*$/', $s, $t)) {
        $h = (int)$t[1]; $i = (int)$t[2]; $sec = (int)($t[3] ?? 0);
        if ($h < 24 && $i < 60 && $sec < 60) $time = sprintf('%02d:%02d:%02d', $h, $i, $sec);
    }
    return $date . ' ' . $time;
}

/**
 * คำนวณอายุจากวันเกิด (รองรับทั้ง ค.ศ. และ พ.ศ.)
 */
function calculateAge($birthdate) {
    if (empty($birthdate) || $birthdate == '0000-00-00') return 0;
    
    try {
        $date = new DateTime($birthdate);
        // ดักจับถ้าปีเป็น พ.ศ. ให้ถอยกลับมาเป็น ค.ศ. ก่อนคำนวณ
        if ($date->format('Y') > 2400) {
            $date->modify('-543 years');
        }
        $today = new DateTime();
        return $today->diff($date)->y;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * ดึงกลุ่มเป้าหมายพิเศษ (Vulnerable groups)
 */
function getVulnerableText($pdo, $citizen_id, $age = null) {
    $stmt = $pdo->prepare("
        SELECT m.v_name FROM citizen_vulnerable_map map 
        JOIN vulnerable_master m ON map.v_id = m.id WHERE map.citizen_id = ?
    ");
    $stmt->execute([$citizen_id]);
    $items = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // ตัวเรียกบางที่ส่ง 0 มาเมื่อ "ไม่มีวันเกิด" (ไม่ใช่ "อายุ 0 ขวบ") — ถ้ารับตรง ๆ จะขึ้น
    // "เด็ก (0-5 ปี)" ให้คนที่ไม่รู้อายุ เหมือนบั๊กที่แก้ไปแล้วใน guest_check.php
    // จึงถือว่า 0 = ไม่ทราบ แล้วไม่เดาให้ (เด็กแรกเกิดจริง ๆ ให้ติ๊กกลุ่มเปราะบางเอง ซึ่งดึงมาจาก map ด้านบนอยู่แล้ว)
    if ($age !== null && $age > 0) {
        if ($age <= 5) $items[] = "เด็ก (0-5 ปี)";
        if ($age >= 60) $items[] = "ผู้สูงอายุ";
    }

    $items = array_unique($items);
    return !empty($items) ? implode(", ", $items) : "-";
}

/**
 * ล้างคำนำหน้าที่อยู่ (ต./ตำบล/แขวง, อ./อำเภอ/เขต, จ./จังหวัด) + ช่องว่างออก
 * รวมไว้ที่เดียว (เดิมกระจายทั้ง api/address_id.php และ JS ใน guest_form.php)
 * ใช้ก่อนค้น address_lookup เพื่อให้ match ง่ายขึ้น
 */
function stripAddrPrefix($s): string {
    return trim(str_replace(
        ['ตำบล', 'แขวง', 'อำเภอ', 'เขต', 'จังหวัด', 'ต.', 'อ.', 'จ.', ' '],
        '',
        (string)$s
    ));
}

/**
 * หา address_id จาก "ชื่อ" ตำบล/อำเภอ/จังหวัด — ตรรกะกลางของทั้งระบบ
 * (เดิมมี 3 ชุดไม่เท่ากันที่ guest_check / guest_import / api/address_id.php —
 *  ชุดของ import ไม่ตัด "แขวง/เขต" ทำให้ที่อยู่ กทม. จากไฟล์หา id ไม่เจอ)
 * ใช้ stripAddrPrefix เป็นตัวล้างคำนำหน้าตัวเดียวกันหมด แล้วค้นแบบ LIKE (หลวม ๆ กันพิมพ์ไม่ตรงเป๊ะ)
 *
 * @return int|null null = หาไม่เจอ (ผู้เรียกตัดสินใจเองว่าจะข้ามหรือเก็บเป็นข้อความ)
 */
function lookupAddressIdByName(PDO $pdo, ?string $tambon, ?string $amphoe, ?string $province, ?string $zipcode = null): ?int
{
    $t = stripAddrPrefix($tambon);
    $a = stripAddrPrefix($amphoe);
    $p = stripAddrPrefix($province);
    if ($t === '' && $a === '' && $p === '') return null;

    // รหัสไปรษณีย์ (ถ้าส่งมา) ใช้เลือกแถวให้ตรง — บางตำบลชื่อซ้ำกันเป๊ะแต่แยกแถวตามเขตไปรษณีย์
    // เช่น วังใหม่/ปทุมวัน มี 5 แถว (10330/10110/10120/10400/10500) ถ้าไม่ดูรหัสจะได้แถวแรกเสมอ
    $z = preg_replace('/\D/', '', (string)$zipcode);

    if ($t !== '' && $a !== '' && $p !== '') {
        if ($z !== '') {
            $stmt = $pdo->prepare("SELECT id FROM address_lookup WHERE subdistrict = ? AND district = ? AND province = ? AND zipcode = ? LIMIT 1");
            $stmt->execute([$t, $a, $p, $z]);
            if ($id = $stmt->fetchColumn()) return (int)$id;
        }
        // ค้นแบบตรงตัวก่อน — LIKE '%เวียง%' ไปโดน "รอบเวียง" ก่อนแล้ว LIMIT 1 คว้าแถวผิด (วัดจริง 9 ตำบลทั้งประเทศ)
        $stmt = $pdo->prepare("SELECT id FROM address_lookup WHERE subdistrict = ? AND district = ? AND province = ? LIMIT 1");
        $stmt->execute([$t, $a, $p]);
        if ($id = $stmt->fetchColumn()) return (int)$id;
    }

    // ไม่เป๊ะ (พิมพ์ไม่ครบ/สะกดต่าง) → ค่อยผ่อนเป็น LIKE แบบเดิม
    $stmt = $pdo->prepare(
        "SELECT id FROM address_lookup
         WHERE subdistrict LIKE ? AND district LIKE ? AND province LIKE ? LIMIT 1"
    );
    $stmt->execute(["%$t%", "%$a%", "%$p%"]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/**
 * เลือกชุดที่อยู่ที่จะ "แสดงผล": ภูมิลำเนา (กล่อง 3) ก่อน — ถ้าไม่มีข้อมูลเลยค่อยใช้ที่อยู่ตามทะเบียนบ้าน
 * ใช้ร่วมกันที่ guest_list / guest_history / export_excel เพื่อให้ทุกหน้าตอบเหมือนกัน
 *
 * @param array $home ชุดภูมิลำเนา เช่น ['number'=>..,'tambon'=>..,'amphoe'=>..,'province'=>..,'zipcode'=>..]
 * @param array $reg  ชุดที่อยู่ตามทะเบียนบ้าน (คีย์เดียวกัน)
 * @return array ชุดที่เลือก + 'is_home' บอกว่าใช้ภูมิลำเนาหรือไม่
 */
function pickDisplayAddress(array $home, array $reg): array
{
    $hasHome = false;
    foreach ($home as $v) {
        if (trim((string)$v) !== '') { $hasHome = true; break; }
    }
    $picked = $hasHome ? $home : $reg;
    $picked['is_home'] = $hasHome;
    return $picked;
}

/**
 * กุญแจประจำจอยินยอม (display key) ของเจ้าหน้าที่ 1 คน — ฝังใน QR (`guest_display.php?d=...`)
 * ทำไมต้องมี: หลัง S5 ปิดโหมด broadcast แล้ว อุปกรณ์ผู้พัก (ไม่ได้ล็อกอิน) ไม่มีทางได้ sync_token
 * มาก่อน จึง poll ไม่ได้เลย — display key คือ capability ที่ "ต้องรู้ค่าถึงเรียกได้" ใช้บูตสแตรป
 * สุ่ม 128-bit, ผูกกับ user, สร้างครั้งแรกเมื่อเปิด QR แล้วใช้ซ้ำได้ (QR ที่พิมพ์ไว้ยังใช้ได้ตลอด)
 */
function getOrCreateDisplayKey(PDO $pdo, int $user_id): string {
    $key = $pdo->prepare("SELECT display_key FROM users WHERE id = ?");
    $key->execute([$user_id]);
    $existing = (string)($key->fetchColumn() ?: '');
    if ($existing !== '') {
        return $existing;
    }

    $new = bin2hex(random_bytes(16)); // 32 hex chars
    $pdo->prepare("UPDATE users SET display_key = ? WHERE id = ?")->execute([$new, $user_id]);
    return $new;
}

/**
 * นโยบายรหัสผ่าน (Tier3) — รวมกฎที่เดียวเพื่อใช้ทุกจุดที่ตั้ง/เปลี่ยนรหัสผ่าน
 * กฎ: ยาว >= 6 ตัวอักษร (ตัวเลขล้วนได้ — เจ้าหน้าที่ภาคสนามพิมพ์บนแท็บเล็ต/หน้างานเร็ว)
 * @return string|null ข้อความ error ถ้าไม่ผ่าน, null ถ้าผ่าน
 */
const PASSWORD_MIN_LENGTH = 6;

function passwordPolicyError(string $password): ?string {
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return "รหัสผ่านต้องมีความยาวอย่างน้อย " . PASSWORD_MIN_LENGTH . " ตัวอักษร";
    }
    return null;
}

/**
 * P7 — สร้าง public_id 13 หลัก (ไม่ขึ้นต้น 0) ที่ไม่ซ้ำในตาราง citizens
 * ใช้แทนการโชว์ PK ที่เดาลำดับได้ใน URL
 */
function generatePublicId(PDO $pdo): string {
    $stmt = $pdo->prepare("SELECT 1 FROM citizens WHERE public_id = ? LIMIT 1");
    do {
        $pid = (string)random_int(1000000000000, 9999999999999);
        $stmt->execute([$pid]);
    } while ($stmt->fetchColumn());
    return $pid;
}

/**
 * P7 — แปลง public_id (จาก URL) → internal id ของ citizens
 * @return int internal id หรือ 0 ถ้าไม่พบ/รูปแบบไม่ถูกต้อง
 */
function resolveCitizenId(PDO $pdo, string $publicId): int {
    if ($publicId === '' || !ctype_digit($publicId)) {
        return 0;
    }
    $stmt = $pdo->prepare("SELECT id FROM citizens WHERE public_id = ? LIMIT 1");
    $stmt->execute([$publicId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/* ================================================================
 *  ค้นหาผู้พัก (UNION-per-arm) — พอร์ตสถาปัตยกรรมจาก wp-bhikkhu-scholar
 *  หลักการ: เลี่ยง OR ข้ามคอลัมน์ (full-scan) → แตกเป็น arm ต่อคอลัมน์
 *  แต่ละ arm ใช้ index ของตัวเอง + LIMIT cap กันคำกว้าง materialize ล้านแถว
 *  ที่อยู่ resolve บนตารางเล็ก (address_lookup ~7,500 แถว) ก่อน แล้ว IN(ids)
 * ================================================================ */

/**
 * แปลงเลขไทย ๐-๙ → อารบิก 0-9 (อักขระอื่นคงเดิม)
 * จำเป็นก่อน preg_match('/\d/') แบบ non-/u ซึ่งไม่ match เลขไทย
 */
function thaiDigitsToArabic(string $s): string {
    return str_replace(
        ['๐','๑','๒','๓','๔','๕','๖','๗','๘','๙'],
        ['0','1','2','3','4','5','6','7','8','9'],
        $s
    );
}

/** เพดานนับผลค้นข้อความ — เกินนี้แสดง "มากกว่า N" (คุมต้นทุน DISTINCT/materialize) */
if (!defined('SEARCH_COUNT_CAP')) define('SEARCH_COUNT_CAP', 5000);

/**
 * โหลด dictionary คำนำหน้าชื่อ (ตาราง name_prefix) — ตารางเล็ก, cache ในคำขอเดียว
 * เรียงจาก "ยาว → สั้น" เพื่อ match longest-first (นางสาว ต้องมาก่อน นาง)
 * ตารางยังไม่ migrate → คืน [] (ระบบค้นยังทำงาน แค่ไม่ตัดคำนำหน้า)
 * @return string[]
 */
function namePrefixList(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $rows = $pdo->query("SELECT name FROM name_prefix")->fetchAll(PDO::FETCH_COLUMN);
        $seen = [];
        foreach ($rows as $n) {
            $n = trim((string)$n);
            if ($n !== '') $seen[$n] = true;
        }
        $cache = array_keys($seen);
        usort($cache, fn($a, $b) => mb_strlen($b) - mb_strlen($a)); // ยาวก่อน
    } catch (\Throwable $e) {
        $cache = []; // ตารางยังไม่มี = ไม่ตัดคำนำหน้า
    }
    return $cache;
}

/**
 * find-or-create คำนำหน้าใน dictionary (เรียกที่ write path — คำนำหน้าใหม่เพิ่มเองอัตโนมัติ)
 * best-effort: ตารางยังไม่ migrate / เขียนพลาด ต้องไม่ทำการบันทึกผู้พักล้ม
 */
function namePrefixRemember(PDO $pdo, ?string $prefix): void {
    $prefix = trim((string)$prefix);
    if ($prefix === '') return;
    try {
        $pdo->prepare("INSERT IGNORE INTO name_prefix (name) VALUES (?)")->execute([$prefix]);
    } catch (\Throwable $e) {
        error_log("namePrefixRemember: " . $e->getMessage());
    }
}

/**
 * แตกคำค้นเป็น "ลำดับคำ" ที่จะนำไปสร้าง arm — จัดการคำนำหน้าทุกทรง (ขับด้วย dictionary)
 *   - "นางสาว สมหญิง [ใจดี]" (เว้นวรรค + คำแรกเป็นคำนำหน้าเป๊ะ) → ตัดคำแรกทิ้ง (ปลอดภัย)
 *   - "นางสาวสมหญิง" (ติดกัน) → เพิ่ม sequence ที่ตัดคำนำหน้าเป็น "arm เสริม" (ไม่ทิ้งของเดิม
 *     กัน false-strip ชื่อจริงที่บังเอิญขึ้นต้นเหมือนคำนำหน้า — arm ที่ไม่ match ก็แค่คืนว่าง)
 *   - พิมพ์คำนำหน้าอย่างเดียว ("นาย") → คืน [] (ไม่มีชื่อให้ค้น)
 * @return array<int,string[]>  ลำดับคำ(แต่ละอันคือ 1 การตีความ)
 */
function searchNameSequences(PDO $pdo, string $term): array {
    $term = trim((string)preg_replace('/\s+/u', ' ', $term));
    if ($term === '') return [];
    $words    = explode(' ', $term);
    $prefixes = namePrefixList($pdo); // longest-first

    // คำแรกเป็นคำนำหน้าเป๊ะ ๆ หรือไม่
    $firstIsPrefix = in_array($words[0], $prefixes, true);

    if ($firstIsPrefix) {
        if (count($words) === 1) return []; // คำนำหน้าล้วน = ไม่มีชื่อ
        return [array_slice($words, 1)];    // ตัดคำแรก → ชื่อ [+สกุล]
    }

    $seqs = [$words]; // ใช้ตามที่พิมพ์เสมอ

    // เคสติดกัน: คำแรกขึ้นต้นด้วยคำนำหน้า (longest-first) → เพิ่ม sequence ที่ตัดคำนำหน้า
    foreach ($prefixes as $p) {
        if (mb_strpos($words[0], $p) === 0 && mb_strlen($words[0]) > mb_strlen($p)) {
            $rest       = mb_substr($words[0], mb_strlen($p));
            $newSeq     = $words;
            $newSeq[0]  = $rest;
            $seqs[]     = $newSeq;
            break; // เอาคำนำหน้าที่ยาวสุดพอ
        }
    }
    return $seqs;
}

/**
 * resolve id ของ address_lookup ที่ตำบล/อำเภอ/จังหวัด "ขึ้นต้นด้วย" คำค้น
 * (ตารางเล็ก → prefix search เร็ว · คืน int[] ไปทำ c.address_id IN(...))
 */
function resolveAddressIdsByPrefix(PDO $pdo, string $word): array {
    $word = trim($word);
    if ($word === '') return [];
    $like = $word . '%';
    $st = $pdo->prepare(
        "SELECT id FROM address_lookup
         WHERE subdistrict LIKE ? OR district LIKE ? OR province LIKE ?
         LIMIT 3000"
    );
    $st->execute([$like, $like, $like]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * สร้าง "arm" ของคำค้นข้อความ — คืน [[sql, [values]], ...] (placeholder positional '?')
 * ผู้เรียกเอาไป UNION (guest_list) หรือ OR (export) เอง · [] = ไม่มี arm (คำนำหน้าล้วน/ว่าง)
 *   1 คำ  → firstname / lastname / ที่อยู่ (IN ids)
 *   ≥2 คำ → firstname(คำแรก) AND lastname(คำสุดท้าย)
 */
function searchTextArms(PDO $pdo, string $term): array {
    $arms = [];
    foreach (searchNameSequences($pdo, $term) as $seq) {
        $n = count($seq);
        if ($n === 0) continue;
        $w0 = $seq[0];
        $wl = $seq[$n - 1];
        if ($n === 1) {
            $arms[] = ["c.firstname LIKE ?", ["$w0%"]];
            $arms[] = ["c.lastname LIKE ?",  ["$w0%"]];
            $ids = resolveAddressIdsByPrefix($pdo, $w0);
            if ($ids) {
                $inList = implode(',', $ids); // int ล้วน (จาก DB) — inline ปลอดภัย
                // NOTE (ค้าง): เวอร์ชันที่ผ่านเทสต์ 23/23 — arm เดียว OR 2 คอลัมน์ (province "เชียงใหม่" ~5.4s
                // บน worst-case 15M ทุกคน active). ลองแยกเป็น 2 arm แล้วช้าลง (เทสต์ timeout >120s) ยังไม่หาสาเหตุ
                $arms[] = ["(c.address_id IN ($inList) OR c.home_address_id IN ($inList))", []];
            }
        } else {
            $arms[] = ["(c.firstname LIKE ? AND c.lastname LIKE ?)", ["$w0%", "$wl%"]];
        }
    }
    return $arms;
}