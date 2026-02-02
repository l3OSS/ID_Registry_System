<?php
/**
 * Authentication & Authorization System
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

/**
 * ตรวจสอบว่าเข้าสู่ระบบหรือยัง
 */
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php?page=login");
        exit();
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
        header("Location: index.php?page=403"); 
        exit();
    }
}

/**
 * เช็คว่าผู้ใช้ปัจจุบันมีสิทธิ์จัดการเป้าหมายหรือไม่
 */
function canManage($target_user_level, $target_user_id = null) {
    $my_level = $_SESSION['role_level'] ?? 99;
    $my_id = $_SESSION['user_id'] ?? 0;

    if ($my_level == 1) return true; // สิทธิ์สูงสุดจัดการได้หมด
    if ($my_level == 2 && $target_user_level >= 2) return true; // Admin จัดการ Admin/User ได้
    if ($my_level == 3 && $target_user_id == $my_id) return true; // User ทั่วไปแก้ได้แค่ตัวเอง

    return false;
}