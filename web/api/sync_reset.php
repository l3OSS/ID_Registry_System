<?php
declare(strict_types=1);
/**
 * API: Sync Reset
 * ล้างหน้าจอยืนยันเพื่อกลับเป็นหน้าว่าง
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
    echo json_encode([
        'success' => false,
        'message' => 'กรุณาเข้าสู่ระบบก่อนดำเนินการ'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "UPDATE temp_sync_consent
         SET status = 'pending',
             citizen_data = NULL,
             updated_at = CURRENT_TIMESTAMP
         WHERE admin_id = ?"
    );
    $stmt->execute([$admin_id]);

    if ($stmt->rowCount() === 0) {
        $pdo->prepare(
            "INSERT INTO temp_sync_consent (admin_id, status, citizen_data)
             VALUES (?, 'pending', NULL)"
        )->execute([$admin_id]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'ระบบ Reset สถานะหน้าจอเรียบร้อยแล้ว'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
}