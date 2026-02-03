<?php
declare(strict_types=1);
/**
 * API: Sync Confirm
 * Update status เป็น confirmed
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = $input['t'] ?? ''; // รับ Token จากมือถือลูกค้า
$admin_id = (int)($_SESSION['user_id'] ?? 0);

if (!empty($token)) {
    // กรณีที่ 1: ยืนยันผ่าน Token (มือถือลูกค้าสแกน QR)
    $stmt = $pdo->prepare(
        "UPDATE temp_sync_consent 
         SET status = 'confirmed' 
         WHERE sync_token = ? AND status = 'pending' AND expires_at > NOW()"
    );
    $stmt->execute([$token]);
} elseif ($admin_id > 0) {
    // กรณีที่ 2: ยืนยันผ่าน Session Admin (แท็บเล็ตเดิม)
    $stmt = $pdo->prepare(
        "UPDATE temp_sync_consent 
         SET status = 'confirmed' 
         WHERE admin_id = ? AND status = 'pending'"
    );
    $stmt->execute([$admin_id]);
} else {
    // ถ้าไม่มีทั้งสองอย่าง
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// เช็คว่ามีการอัปเดตจริงไหม (ถ้า Token ผิดหรือหมดอายุ rowCount จะเป็น 0)
if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'ยืนยันไม่สำเร็จ ข้อมูลอาจหมดอายุ']);
}