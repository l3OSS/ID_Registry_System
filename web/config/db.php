<?php
// config/db.php
date_default_timezone_set('Asia/Bangkok');

// 1. ตรวจสอบ Autoload (Composer)
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    // ถ้ายังไม่มีโฟลเดอร์ vendor ให้แจ้งเตือน หรือถ้ามีหน้า install ก็เด้งไปเลย
    die("<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>
            <h2>❌ ไม่พบโฟลเดอร์ Vendor</h2>
            <p>กรุณารันคำสั่ง <code>composer install</code> ก่อนใช้งานระบบ</p>
         </div>");
}
require_once $autoload;

// 2. โหลดไฟล์ .env
$envFile = dirname(__DIR__) . '/.env';

if (!file_exists($envFile)) {
    // ถ้าไม่มีไฟล์ .env และเราอยู่ในหน้าหลัก ให้เด้งไปหน้าติดตั้ง
    if (file_exists(dirname(__DIR__) . '/install/index.php')) {
        header("Location: install/index.php");
        exit;
    } else {
        die("<h2>❌ ไม่พบไฟล์ตั้งค่า (.env)</h2><p>กรุณาทำการติดตั้งระบบใหม่</p>");
    }
}

// ถ้ามีไฟล์ .env ค่อยเริ่มโหลด Dotenv
try {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
} catch (Exception $e) {
    die("Error loading .env file: " . $e->getMessage());
}
// 3. เตรียมตัวแปร (ใช้การดึงจาก $_ENV)
$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';
$charset = 'utf8mb4';

// ตรวจสอบว่ามีข้อมูลสำคัญครบไหม
if (empty($db) || empty($user)) {
    die("Error: Database configuration is incomplete in .env file.");
}

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // แสดงข้อความที่เข้าใจง่ายขึ้น
    die('Database connection failed. Please check your .env settings.');
}