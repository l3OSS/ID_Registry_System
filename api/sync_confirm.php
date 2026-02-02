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

$admin_id = (int)($_SESSION['user_id'] ?? 0);

if ($admin_id <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$stmt = $pdo->prepare(
    "UPDATE temp_sync_consent 
     SET status = 'confirmed' 
     WHERE admin_id = ?"
);
$stmt->execute([$admin_id]);

echo json_encode(['success' => true]);
