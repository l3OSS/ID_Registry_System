<?php
/**
 * Secure Logout Process
 */

if (!class_exists('Dotenv\Dotenv')) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
}

require_once __DIR__ . '/session.php';
start_secure_session();

require_once __DIR__ . '/../config/db.php'; 
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/log.php';

// บันทึก Log ก่อนล้าง Session
if (isset($_SESSION['user_id'])) {
    // $pdo ถูกสร้างมาจาก db.php เรียบร้อยแล้ว
    writeLog($pdo, 'LOGOUT', 'User: ' . ($_SESSION['username'] ?? 'Unknown') . ' logged out.');
}

// ล้างข้อมูล Session
session_unset();

// ลบ cookie ของ session ทิ้งด้วย — สำคัญเมื่อผู้ใช้เคยติ๊ก "จำการเข้าสู่ระบบ"
// (cookie 30 วันจะไม่หายเองตอน logout ถ้าไม่สั่งให้หมดอายุ)
if (ini_get('session.use_cookies')) {
    $cp = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $cp['path'],
        'domain'   => $cp['domain'],
        'httponly' => $cp['httponly'],
        'samesite' => $cp['samesite'],
        'secure'   => $cp['secure'],
    ]);
}

session_destroy();

// หากไฟล์นี้อยู่ใน core/ การถอยออกไป index.php ควรใช้ ../index.php ถูกต้องแล้ว
header("Location: ../index.php?page=login");
exit();



