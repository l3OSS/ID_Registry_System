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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
session_destroy();

// หากไฟล์นี้อยู่ใน core/ การถอยออกไป index.php ควรใช้ ../index.php ถูกต้องแล้ว
header("Location: ../index.php?page=login");
exit();



