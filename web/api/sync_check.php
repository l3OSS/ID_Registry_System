<?php
declare(strict_types=1);
/**
 * API: Sync Check
 * แท็บเล็ตจะคอยเรียกไฟล์นี้ทุก 1-2 วินาที เพื่อดูว่ามีข้อมูลใหม่ส่งมาหรือยัง
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/security.php';

$token = $_GET['t'] ?? '';
$admin_id = (int)($_SESSION['user_id'] ?? 0);

$result = null;

if (!empty($token)) {
    // โหมดลูกค้า (มี Token): เช็คความถูกต้องและเวลาหมดอายุ
    $stmt = $pdo->prepare(
        "SELECT status, citizen_data, admin_id, sync_token 
         FROM temp_sync_consent 
         WHERE sync_token = ? AND expires_at > NOW() 
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($admin_id > 0) {
    // โหมดเจ้าหน้าที่ (มี Session): เช็คตาม admin_id ปกติ
    $stmt = $pdo->prepare(
        "SELECT status, citizen_data, admin_id, sync_token 
         FROM temp_sync_consent 
         WHERE admin_id = ? 
         LIMIT 1"
    );
    $stmt->execute([$admin_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // กรณีสแกน QR กระดาษ (ไม่มีทั้ง Token และ Session): 
    // ให้หาข้อมูลที่เพิ่งอัปเดตล่าสุดภายใน 5 นาทีที่ยังมีสถานะ pending
    $stmt = $pdo->prepare(
        "SELECT status, citizen_data, admin_id, sync_token 
         FROM temp_sync_consent 
         WHERE status = 'pending' AND expires_at > NOW() 
         ORDER BY updated_at DESC LIMIT 1"
    );
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$result) {
    ob_clean();
    echo json_encode(['status' => 'none']);
    exit;
}

ob_clean();
echo json_encode([
    'status'       => $result['status'],
    'citizen_data' => json_decode($result['citizen_data'] ?? '[]', true),
    'admin_id'     => (int)$result['admin_id'],
    'sync_token'   => $result['sync_token'] // ส่ง Token กลับไปให้มือถือลูกค้าใช้
], JSON_UNESCAPED_UNICODE);