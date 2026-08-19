<?php
// install/process_install.php

require_once __DIR__ . '/../core/lang.php'; // ข้อความทั้งหมดอยู่ที่ lang/th.php (ไม่พึ่ง DB)

// S1: กันติดตั้งซ้ำ (re-install attack) — ถ้ามี install.lock แล้ว ห้ามรันซ้ำเด็ดขาด
if (file_exists(__DIR__ . '/install.lock')) {
    http_response_code(403);
    die(e('inst.err_relocked'));
}

require_once __DIR__ . '/../core/session.php';
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

// ตรวจความพร้อมเซิร์ฟเวอร์ซ้ำฝั่ง server — index.php ซ่อนฟอร์มให้ก็จริง แต่ POST ตรงเข้ามาข้ามได้
// ต้องตรวจ "ก่อน" แตะ DB เสมอ ไม่งั้นถ้าเขียน .env ไม่ได้จะได้สถานะค้างครึ่งทาง (DB ถูกสร้างแล้ว แต่เข้าระบบไม่ได้)
require_once __DIR__ . '/requirements.php';
if ($failed = installRequirementsFailed()) {
    http_response_code(400);
    die(e('inst.err_not_ready')
        . htmlspecialchars(implode(', ', $failed))
        . " <button onclick='history.back()'>" . e('inst.back_fix_btn') . "</button>");
}

// 1. รับค่าและเตรียมข้อมูล
$db_host = $_POST['db_host'] ?? 'localhost';
$db_port = $_POST['db_port'] ?? '3306';
$db_name = $_POST['db_name'] ?? '';
$db_user = $_POST['db_user'] ?? '';
$db_pass = $_POST['db_pass'] ?? '';

$admin_user         = $_POST['admin_user'] ?? '';
$admin_pass_raw     = $_POST['admin_pass'] ?? '';
$admin_pass_confirm = $_POST['admin_pass_confirm'] ?? '';
$admin_nickname     = $_POST['admin_nickname'] ?? '';

// S9: กัน SQL injection ใน installer — ชื่อ DB ถูกต่อสตริงตรงใน CREATE DATABASE/USE/DSN
// จึงต้อง whitelist ให้เหลือเฉพาะอักขระที่ปลอดภัย (identifier ปกติ) ก่อนใช้งาน
if (!preg_match('/^[a-zA-Z0-9_]+$/', $db_name)) {
    die(e('inst.err_prefix') . e('inst.err_bad_dbname') . " <button onclick='history.back()'>" . e('inst.back_fix_btn') . "</button>");
}
if (!preg_match('/^[0-9]+$/', (string)$db_port)) {
    die(e('inst.err_prefix') . e('inst.err_bad_port') . " <button onclick='history.back()'>" . e('inst.back_fix_btn') . "</button>");
}

// 🟢 ตรวจสอบความถูกต้องของรหัสผ่าน (ใช้ policy กลางจาก core/functions.php)
require_once __DIR__ . '/../core/functions.php';
if (($pwErr = passwordPolicyError($admin_pass_raw)) !== null) {
    die(e('inst.err_prefix') . htmlspecialchars($pwErr) . " <button onclick='history.back()'>" . e('inst.back_fix_btn') . "</button>");
}
if ($admin_pass_raw !== $admin_pass_confirm) {
    die(e('inst.err_prefix') . e('inst.err_pw_mismatch') . " <button onclick='history.back()'>" . e('inst.back_fix_btn') . "</button>");
}

$admin_pass_hash = password_hash($admin_pass_raw, PASSWORD_DEFAULT);

try {
    // 2. เชื่อมต่อและสร้าง Database
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name` ");

    // 3. รันไฟล์ SQL Master Data
    $sql_file = 'sql/master_data.sql';
    if (!file_exists($sql_file)) throw new Exception(t('inst.err_no_sql'));
    $sql_content = file_get_contents($sql_file);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);
    $pdo->exec($sql_content);

    // 4. สร้างบัญชี Admin
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, nickname, role_level) VALUES (?, ?, ?, 1)");
    $stmt->execute([$admin_user, $admin_pass_hash, $admin_nickname]);

    // 5. 🛡️ สร้างไฟล์ .env (เก็บค่า Config ที่ได้จากการกรอกฟอร์ม)
    // สุ่มกุญแจสำหรับ Encryption ระบบ (ถ้ามี)
    $secure_key = base64_encode(openssl_random_pseudo_bytes(32)); 
    
    $env_content = "# Database Settings\n"
                 . "DB_HOST=\"$db_host\"\n"
                 . "DB_PORT=\"$db_port\"\n"
                 . "DB_NAME=\"$db_name\"\n"
                 . "DB_USER=\"$db_user\"\n"
                 . "DB_PASS=\"$db_pass\"\n\n"
                 . "# Security Keys - Generated at: " . date('Y-m-d H:i:s') . "\n"
                 . "ENCRYPTION_KEY=\"$secure_key\"\n"
                 // encrypt ใหม่ใช้ AES-256-GCM (prefix g1:) เสมอ — ค่านี้เป็นข้อมูลอ้างอิงให้ตรงของจริง (P6)
                 . "ENCRYPTION_METHOD=\"AES-256-GCM\"\n\n"
                 // P3/S7: production ต้องปิด display_errors — ไม่เขียนบรรทัดนี้ index.php จะตก default 'dev' แล้วโชว์ error เต็ม ๆ
                 . "# App Environment (prod = ปิด display_errors, log อย่างเดียว)\n"
                 . "APP_ENV=\"prod\"\n";
    
    if (file_put_contents(__DIR__ . '/../.env', $env_content) === false) {
        throw new Exception(t('inst.err_env_write'));
    }

    // 6. บันทึกประวัติการติดตั้งลงตาราง settings
    $install_log = json_encode([
        'installed_at' => date('Y-m-d H:i:s'),
        'terms_accepted' => true,
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'db_name' => $db_name
    ], JSON_UNESCAPED_UNICODE);

    $sql_settings = "INSERT INTO settings (id, install_log) VALUES (1, ?) 
                     ON DUPLICATE KEY UPDATE install_log = ?";
    $pdo->prepare($sql_settings)->execute([$install_log, $install_log]);

    // 7. ล็อคการติดตั้ง
    file_put_contents(__DIR__ . '/install.lock', date('Y-m-d H:i:s'));

    // 🟢 แสดงผลสำเร็จ
    echo "<div style='font-family:sans-serif; text-align:center; padding:50px;'>";
    echo "<h1 style='color:green;'>" . e('inst.done_title') . "</h1>";
    echo "<p>" . t('inst.done_env') . "</p>";
    echo "<div style='background:#fff3cd; padding:20px; border-radius:10px; display:inline-block; margin-top:20px;'>";
    echo "<p style='color:#856404;'><b>" . e('inst.done_sec_title') . "</b></p>";
    echo "<p>" . t('inst.done_sec1') . "</p>";
    echo "<p>" . t('inst.done_sec2') . "</p>";
    echo "</div>";
    echo "<br><br><a href='../index.php' style='padding:15px 30px; background:blue; color:white; text-decoration:none; border-radius:30px; font-weight:bold;'>" . e('inst.done_login_btn') . "</a>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div style='font-family:sans-serif; color:red; padding:30px; border:1px solid red;'>";
    echo "<h3>" . e('inst.fail_title') . "</h3>";
    echo "<p>" . e('inst.fail_cause') . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<button onclick='history.back()'>" . e('inst.back_fix_btn') . "</button>";
    echo "</div>";
}