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

$admin_id = (int)($_SESSION['user_id'] ?? 0);

if ($admin_id <= 0) {
ob_clean();
    http_response_code(200);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Session expired, please login again.'
    ]);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT status, citizen_data
     FROM temp_sync_consent
     WHERE admin_id = ?
     LIMIT 1"
);
$stmt->execute([$admin_id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$result) {
ob_clean();
    echo json_encode(['status' => 'none']);
    exit;
}

ob_clean();
echo json_encode([
    'status'       => $result['status'],
    'citizen_data' => json_decode($result['citizen_data'], true), // ถอด String เป็น Object ที่นี่เลย
    'admin_id'     => $admin_id
], JSON_UNESCAPED_UNICODE);