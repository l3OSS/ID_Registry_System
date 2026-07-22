<?php
/**
 * Citizen Registration System - Application Front Controller
 * * @author EngiNear
 * @license MIT
 */

declare(strict_types=1);

// --- 1. Load Composer Autoloader & Environment Variables ---
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    
    // 🔥 เพิ่มการตรวจสอบไฟล์ .env ตรงนี้
    if (file_exists(__DIR__ . '/.env')) {
        try {
            $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
            $dotenv->load();
        } catch (Exception $e) {
            // กรณีไฟล์พังหรืออ่านไม่ได้ ให้ปล่อยผ่านไปก่อนเพื่อให้ไปติดที่หน้า Install Guard ด้านล่าง
        }
    }
}

// เริ่มระบบ Session (แนะนำให้รันหลังโหลด Env เผื่อมีการเก็บ Session ใน DB/Redis)
// Tier1: cookie flags httponly/samesite/secure ผ่าน start_secure_session()
require_once __DIR__ . '/core/session.php';
start_secure_session();

// --- 2. Installation Guard ---
if (file_exists('install/') && !file_exists('install/install.lock')) {
    header("Location: install/index.php");
    exit();
}

// --- 3. Load Core Components ---
// ระบบจะทำงานต่อเมื่อมีไฟล์ config แล้วเท่านั้น
if (file_exists('config/db.php')) {
    require_once 'config/db.php';
    require_once 'core/lang.php';      // ข้อความทั้งระบบ — ต้องมาก่อนไฟล์ที่เรียก t()/e()
    require_once 'core/auth.php';
    require_once 'core/security.php';
    require_once 'core/log.php';
    require_once 'core/functions.php';
    require_once 'core/csrf.php';
    require_once 'core/tx.php';
    require_once 'core/rbac.php';

    // P2/S2: ตรวจ CSRF ทุก POST ที่ผ่าน router (login, user_*, setting, profile, log_viewer ฯลฯ)
    // หมายเหตุ: guest_check.php และ api/sync_* ถูก POST ตรง ไม่ผ่านจุดนี้ — ตรวจในไฟล์เอง
    csrf_verify();
}

// --- 4. Error Reporting Configuration ---
// P3/S7: display_errors ขับด้วย env — ตั้ง APP_ENV=prod ใน .env เพื่อปิดใน production; dev เปิด; log เสมอ
$isProd = (($_ENV['APP_ENV'] ?? 'dev') === 'prod');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', $isProd ? '0' : '1');

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

/**
 * เก็บ output ไว้ใน buffer ก่อน — header.php ถูกพ่นออกไปก่อนหน้าเพจเสมอ
 * ถ้าไม่ buffer ไว้ หน้าไหน (หรือ guard requirePermission/checkLogin) ที่ redirect
 * หลังบันทึกข้อมูล/เจอสิทธิ์ไม่พอ จะโดน "headers already sent" · redirect() ล้าง buffer ให้ก่อนส่ง Location
 */
ob_start();

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

ob_end_flush();
