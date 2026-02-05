<?php
// config/db.php
date_default_timezone_set('Asia/Bangkok');

// 1. ตรวจสอบการติดตั้ง Vendor (Composer)
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die("Error: 'vendor/autoload.php' not found. Please run terminal command 'composer install' on your current working directory.");
}
require_once $autoload;

// 2. โหลดไฟล์ .env
try {
    if (file_exists(dirname(__DIR__) . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->load();
    } else {
        die("Error: '.env' file missing. Please reinstall one");
    }
} catch (Exception $e) {
    // กรณีไฟล์ .env มีรูปแบบผิดพลาด
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