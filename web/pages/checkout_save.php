<?php
/**
 * Page: Checkout Save
 * Handle Guest Check-out process by updating stay_history status to 'Completed'
 */
require_once __DIR__ . '/../core/session.php';
start_secure_session();

// --- 1. Load Configurations & Core Modules ---
// ใช้ __DIR__ เพื่อให้ Path ถูกต้องเสมอไม่ว่าจะเรียกจากโฟลเดอร์ไหน
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/log.php';
require_once __DIR__ . '/../core/functions.php'; // resolveCitizenId (P7)
require_once __DIR__ . '/../core/lang.php';       // ข้อความทั้งระบบ — POST ตรง ไม่ผ่าน index.php
require_once __DIR__ . '/../core/csrf.php';       // ไฟล์นี้ถูก POST ตรง ไม่ผ่าน router จึงต้องตรวจเอง

// --- 2. Security Check ---
// ตรวจสอบว่าผู้ใช้มีสิทธิ์เข้าถึงหน้านี้หรือไม่ (ต้อง Login แล้ว)
checkLogin();

/**
 * 🛡️ เดิมหน้านี้รับค่าทาง **GET** และมีแค่ checkLogin() — เป็น state change ที่เรียกได้ด้วย URL เปล่า ๆ
 * แปลว่าแค่ฝัง <img src=".../checkout_save.php?stay_id=..&citizen_id=..."> ไว้หน้าไหนก็ได้
 * เจ้าหน้าที่ที่ล็อกอินค้างอยู่เปิดเจอ = ผู้พักถูกลงทะเบียนออกทันทีโดยไม่มีใครกดอะไร (CSRF)
 *
 * แก้เป็น: รับเฉพาะ POST + ตรวจ csrf token — แนวเดียวกับ guest_check.php ที่ถูก POST ตรงเหมือนกัน
 * (GET ไม่ควรเปลี่ยนสถานะข้อมูลอยู่แล้วตามหลัก HTTP · prefetch/crawler ของเบราว์เซอร์ก็ยิงโดนได้)
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ../index.php?page=guest_list');
    exit();
}
csrf_verify();

// ตั้งค่า Timezone ให้ตรงกับไทย
date_default_timezone_set('Asia/Bangkok');

// --- 3. Parameter Processing ---
$stay_id        = filter_input(INPUT_POST, 'stay_id', FILTER_VALIDATE_INT) ?? 0;
// P7: citizen_id ที่ส่งมาเป็น public_id — เก็บไว้ redirect กลับ + แปลงเป็น internal สำหรับ query
$citizen_public = preg_replace('/\D/', '', (string)($_POST['citizen_id'] ?? ''));
$citizen_id     = resolveCitizenId($pdo, $citizen_public);

if ($stay_id > 0 && $citizen_id > 0) {
    try {
        /**
         * 🏢 Update stay_history table
         * 1. Set check_out time to current (NOW())
         * 2. Change status to 'Completed'
         * Target only 'Active' status to prevent duplicate check-outs
         */
        $sql = "UPDATE stay_history 
                SET check_out = NOW(), status = 'Completed' 
                WHERE id = :stay_id AND citizen_id = :citizen_id AND status = 'Active'";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':stay_id'    => $stay_id, 
            ':citizen_id' => $citizen_id
        ]);

        if ($stmt->rowCount() > 0) {
            // ✅ SUCCESS
            $_SESSION['success_msg'] = t('checkout.success');
            
            // 🛡️ Activity Log Entry
            writeLog($pdo, 'CHECK_OUT', "Check-out Guest ID: $citizen_id (Stay Record ID: $stay_id)");
        } else {
            // ⚠️ WARNING: Record already checked-out or not found
            $_SESSION['error_msg'] = t('checkout.not_active');
        }

    } catch (PDOException $e) {
        // ไม่โยนรายละเอียด error ให้ผู้ใช้เห็น — log ไว้ฝั่งเซิร์ฟเวอร์ แล้วแสดงข้อความกลาง ๆ
        error_log("Checkout Failure: " . $e->getMessage());
        $_SESSION['error_msg'] = t('checkout.err_save');
    }
} else {
    $_SESSION['error_msg'] = t('checkout.err_invalid');
}

// --- 4. Redirection ---
// ส่งผู้ใช้กลับไปยังหน้าประวัติข้อมูลบุคคล
$redirect_url = "../index.php?page=guest_history" . ($citizen_public !== '' ? "&id=" . urlencode($citizen_public) : "");
header("Location: " . $redirect_url);
exit();