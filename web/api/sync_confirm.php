<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');
/**
 * API: Sync Confirm
 * Update status เป็น confirmed
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/session.php';
start_secure_session();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/lang.php';   // ข้อความ JSON — api เปิดตรง ไม่ผ่าน index.php

// 1. รับค่า Token จาก JSON Body ที่ส่งมาจากหน้าจอ guest_display.php
$input = json_decode(file_get_contents('php://input'), true);
$token = $input['t'] ?? ''; 
$admin_id = (int)($_SESSION['user_id'] ?? 0);
$now = date('Y-m-d H:i:s');

try {
    if (!empty($token)) {
        // --- กรณีลูกค้า (สแกน QR): ยืนยันผ่าน Token ---
        // ต้องเช็ค: Token ตรง, สถานะเดิมต้องเป็น pending และข้อมูลต้องยังไม่หมดอายุ
        $stmt = $pdo->prepare(
            "UPDATE temp_sync_consent 
             SET status = 'confirmed', updated_at = CURRENT_TIMESTAMP
             WHERE sync_token = ? AND status = 'pending' AND expires_at > ?"
        );
        $stmt->execute([$token, $now]);
    } elseif ($admin_id > 0) {
        // --- กรณีเจ้าหน้าที่ (กดจาก Tablet/PC): ยืนยันผ่าน Session ---
        $stmt = $pdo->prepare(
            "UPDATE temp_sync_consent 
             SET status = 'confirmed', updated_at = CURRENT_TIMESTAMP
             WHERE admin_id = ? AND status = 'pending'"
        );
        $stmt->execute([$admin_id]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid Request']);
        exit;
    }

    // เช็คว่ามีการ Update แถวข้อมูลจริงหรือไม่
    if ($stmt->rowCount() > 0) {
        // ยืนยันแล้ว = จบการใช้งานข้อมูลชุดนี้ → ลบรูปบัตรชั่วคราวทิ้งทันที (ไม่รอหมดอายุ)
        // และตัด _img ออกจาก citizen_data เพื่อไม่ให้เหลือ pointer ค้างในตาราง
        $sel = !empty($token)
            ? $pdo->prepare("SELECT admin_id, citizen_data FROM temp_sync_consent WHERE sync_token = ? LIMIT 1")
            : $pdo->prepare("SELECT admin_id, citizen_data FROM temp_sync_consent WHERE admin_id = ? LIMIT 1");
        $sel->execute([!empty($token) ? $token : $admin_id]);

        if ($row = $sel->fetch(PDO::FETCH_ASSOC)) {
            $data = json_decode((string)$row['citizen_data'], true) ?: [];
            if (!empty($data['_img'])) {
                $path = __DIR__ . '/../uploads/temp/' . basename((string)$data['_img']);
                if (is_file($path)) {
                    @unlink($path);
                }
                unset($data['_img']);
                $pdo->prepare("UPDATE temp_sync_consent SET citizen_data = ? WHERE admin_id = ?")
                    ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), (int)$row['admin_id']]);
            }
        }

        echo json_encode(['success' => true]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => t('api.confirm_failed')
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
