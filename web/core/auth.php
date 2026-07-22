<?php
/**
 * Authentication & Authorization System
 */

require_once __DIR__ . '/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';   // redirect() — guard เหล่านี้ถูกเรียกหลัง header.php พ่น HTML แล้ว

/**
 * ตรวจสอบว่าเข้าสู่ระบบหรือยัง
 */
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        redirect('index.php?page=login');
    }
}

/**
 * ตรวจสอบระดับสิทธิ์ขั้นต่ำ
 * Role: 1=Engineer, 2=Admin, 3=Mod
 */
function checkPermission($min_level = 3) {
    checkLogin();
    $user_level = $_SESSION['role_level'] ?? 99;
    
    // สิทธิ์ยิ่งน้อย เลขยิ่งสูง (1 < 2 < 3)
    if ($user_level > $min_level) {
        redirect('index.php?page=403');
    }
}

/**
 * เช็คว่าผู้ใช้ปัจจุบัน "จัดการบัญชีเป้าหมาย" ได้ไหม — ลำดับชั้น (เลขน้อย = สิทธิ์สูง)
 *   - EngiNear (1) จัดการได้ทุกคน
 *   - ตัวเอง แก้ของตัวเองได้เสมอ
 *   - คนอื่น ต้องมี users.edit และเป้าหมายต้อง "ระดับต่ำกว่า" ตัวเองเท่านั้น
 *     (ระดับเท่ากัน/สูงกว่า = ห้าม → Admin แก้ Admin คนอื่นไม่ได้ · ปิดช่องโหว่ยิง URL user_edit)
 */
function canManage($target_user_level, $target_user_id = null) {
    $my_level = (int)($_SESSION['role_level'] ?? 99);
    $my_id    = (int)($_SESSION['user_id'] ?? 0);

    if ($my_level === 1) return true;                        // EngiNear
    if ($target_user_id !== null && (int)$target_user_id === $my_id) return true; // ตัวเอง
    return userCan('users.edit') && (int)$target_user_level > $my_level;          // ระดับต่ำกว่าเท่านั้น
}