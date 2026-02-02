<?php
/**
 * Citizen Registration System - Application Front Controller
 * * @author EngiNear
 * @version 0.0.1 (Env-Integrated)
 * @license MIT
 */

declare(strict_types=1);

// --- 1. Load Composer Autoloader & Environment Variables ---
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    
    // เริ่มต้นโหลดไฟล์ .env เข้าสู่ระบบ
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// เริ่มระบบ Session (แนะนำให้รันหลังโหลด Env เผื่อมีการเก็บ Session ใน DB/Redis)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- 2. Installation Guard ---
if (file_exists('install/') && !file_exists('install/install.lock')) {
    header("Location: install/index.php");
    exit();
}

// --- 3. Load Core Components ---
// ระบบจะทำงานต่อเมื่อมีไฟล์ config แล้วเท่านั้น
if (file_exists('config/db.php')) {
    require_once 'config/db.php';
    require_once 'core/auth.php';
    require_once 'core/security.php';
    require_once 'core/log.php';
    require_once 'core/functions.php';
}

// --- 4. Error Reporting Configuration ---
// ใน Production แนะนำให้เปลี่ยน display_errors เป็น 0
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 5. Routing Logic ---
// Clean and sanitize the 'page' parameter to prevent directory traversal
$page = isset($_GET['page']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['page']) : 'login';

// --- 6. Access Control (Auth Guard) ---
$is_logged_in = isset($_SESSION['user_id']);

if ($is_logged_in && $page == 'login') {
    header("Location: index.php?page=dashboard");
    exit();
}

if (!$is_logged_in && $page != 'login') {
    header("Location: index.php?page=login");
    exit();
}

// --- 7. Page Rendering ---
$pageFile = "pages/{$page}.php";
$useLayout = ($page !== 'login');

/* Render header */
if ($page !== 'login') {
    include 'includes/header.php';
}

/* Render content */
if (is_file($pageFile)) {
    require_once $pageFile;
} else {
    include 'pages/404.php'; 
}

/* Render footer */
if ($page !== 'login') {
    include 'includes/footer.php';
}

//Meow Meow

