<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');

/**
 * API: Sync Reset
 * ล้างข้อมูลชั่วคราวทิ้งทั้งหมด เพื่อเตรียมรับแขกคนใหม่
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

$admin_id = (int)($_SESSION['user_id'] ?? 0);

if ($admin_id <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // แก้ไขจุดนี้: ล้างทุกอย่างรวมถึง Token และวันหมดอายุ
    $stmt = $pdo->prepare(
        "UPDATE temp_sync_consent
         SET status = 'none', 
             citizen_data = NULL,
             sync_token = NULL,
             expires_at = NULL,
             updated_at = CURRENT_TIMESTAMP
         WHERE admin_id = ?"
    );
    $stmt->execute([$admin_id]);

    // กรณีที่ Admin นี้ยังไม่มีแถวข้อมูลในตาราง (เช่น เพิ่งติดตั้งระบบ)
    if ($stmt->rowCount() === 0) {
        $pdo->prepare(
            "INSERT INTO temp_sync_consent (admin_id, status, citizen_data, sync_token, expires_at)
             VALUES (?, 'none', NULL, NULL, NULL)"
        )->execute([$admin_id]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'ล้างข้อมูลหน้าจอเรียบร้อยแล้ว'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}