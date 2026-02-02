<?php
/**
 * Page: Guest Delete
 * Handles permanent deletion of guest records, associated photos, and stay history.
 */

// --- 1. Load Configurations & Core Modules ---
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/auth.php'; // ใช้ checkPermission() จากระบบหลัก
require_once __DIR__ . '/../core/log.php';

// --- 2. Security & Permission Check ---
// อนุญาตเฉพาะ Role 1 (Developer) และ 2 (Admin) เท่านั้น
checkPermission(2);

// ปิดการแสดงผลข้อความ Error สดๆ เพื่อความปลอดภัย (Security by Obscurity)
error_reporting(0);
ini_set('display_errors', 0);

$citizen_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?? 0;

if ($citizen_id > 0) {
    try {
        $pdo->beginTransaction();

        // --- 3. Physical File Clean-up ---
        // ค้นหา Path รูปภาพก่อนลบข้อมูลใน DB
        $stmt_img = $pdo->prepare("SELECT photo_path FROM citizens WHERE id = ?");
        $stmt_img->execute([$citizen_id]);
        $guest = $stmt_img->fetch();
        
        if ($guest && !empty($guest['photo_path'])) {
            // ใช้ __DIR__ เพื่ออ้างอิงตำแหน่งไฟล์ที่แน่นอนจาก Root
            $file_path = __DIR__ . "/../" . $guest['photo_path'];
            
            if (file_exists($file_path) && is_file($file_path)) {
                unlink($file_path); // ลบไฟล์รูปจริงออกจาก Storage
            }
        }

        // --- 4. Database Cleanup (Cascading) ---
        // ลบข้อมูลในตารางลูกก่อน (Child Tables)
        $pdo->prepare("DELETE FROM stay_history WHERE citizen_id = ?")->execute([$citizen_id]);
        $pdo->prepare("DELETE FROM citizen_vulnerable_map WHERE citizen_id = ?")->execute([$citizen_id]);
        $pdo->prepare("DELETE FROM citizen_custom_values WHERE citizen_id = ?")->execute([$citizen_id]);

        // ลบข้อมูลหลักในตาราง citizens
        $stmt_del = $pdo->prepare("DELETE FROM citizens WHERE id = ?");
        $stmt_del->execute([$citizen_id]);

        if ($stmt_del->rowCount() > 0) {
            // --- 5. Activity Logging ---
            writeLog($pdo, 'DELETE_GUEST', "Deleted guest record and photo (ID: $citizen_id) by " . $_SESSION['username']);
            $pdo->commit();
            
            header("Location: ../index.php?page=guest_list&msg=delete_success");
        } else {
            // กรณีไม่มีข้อมูลให้ลบ (เช่น กดลบซ้ำ)
            $pdo->rollBack();
            header("Location: ../index.php?page=guest_list&warn=not_found");
        }
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        
        // บันทึกลง Error Log ของระบบ (ไม่แสดงให้ User เห็น)
        error_log("Critical Delete Failure: " . $e->getMessage());
        header("Location: ../index.php?page=guest_list&error=db_fail");
        exit();
    }
} else {
    header("Location: ../index.php?page=guest_list&error=invalid_id");
    exit();
}