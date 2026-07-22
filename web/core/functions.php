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

    if ($age !== null) {
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