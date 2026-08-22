<?php
/**
 * Page: Guest Delete
 * Handles permanent deletion of guest records, associated photos, and stay history.
 */

// --- 1. Load Configurations & Core Modules ---
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php'; // ระบบสิทธิ์แบบ permission
require_once __DIR__ . '/../core/log.php';
require_once __DIR__ . '/../core/functions.php'; // resolveCitizenId (P7)
require_once __DIR__ . '/../core/csrf.php';       // ไฟล์นี้ถูก POST ตรง ไม่ผ่าน router จึงต้องตรวจเอง
require_once __DIR__ . '/../core/stats.php';       // ตัวนับแดชบอร์ด (active/กลุ่มเปราะบาง)

// --- 2. Security & Permission Check ---
requirePermission('guests.delete'); // EngiNear + Admin

/**
 * 🛡️ เดิมหน้านี้รับ id ทาง **GET** และมีแค่ requirePermission() — เป็นการลบถาวรที่เรียกได้ด้วย URL เปล่า ๆ
 * แค่ฝัง <img src=".../guest_delete.php?id=123"> ไว้หน้าไหนก็ได้ แล้ว Admin/EngiNear ที่ล็อกอินค้าง
 * เปิดเจอ = ลบผู้พัก + รูป + ประวัติเข้าพักถาวรโดยไม่มีใครกดยืนยัน (CSRF · ร้ายกว่า checkout เพราะกู้คืนไม่ได้)
 *
 * แก้เป็น: รับเฉพาะ POST + ตรวจ csrf token — แนวเดียวกับ checkout_save.php/guest_check.php ที่ถูก POST ตรงเหมือนกัน
 * (GET ไม่ควรเปลี่ยนสถานะข้อมูลตามหลัก HTTP · prefetch/crawler ของเบราว์เซอร์ก็ยิงโดนได้)
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ../index.php?page=guest_list');
    exit();
}
csrf_verify();

// ปิดการแสดงผลข้อความ Error สดๆ เพื่อความปลอดภัย (Security by Obscurity)
error_reporting(0);
ini_set('display_errors', 0);

// P7: URL ใช้ public_id → แปลงเป็น internal id
$citizen_id = resolveCitizenId($pdo, preg_replace('/\D/', '', (string)($_POST['id'] ?? '')));

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

        // ตัวนับแดชบอร์ด: ลบส่วนร่วมของคนนี้ก่อนลบข้อมูล (ต้องอ่าน is_active + แท็กตอน row/map ยังอยู่)
        // ถ้าคนนี้ inactive อยู่แล้ว = no-op (ไม่ได้ถูกนับ)
        statCounterRemove($pdo, (int)$citizen_id);

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