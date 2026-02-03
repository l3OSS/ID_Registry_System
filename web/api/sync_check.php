<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');
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

// 1. รับค่า Token จาก URL (ถ้ามี)
$token = $_GET['t'] ?? '';
$admin_id = (int)($_SESSION['user_id'] ?? 0);

$now = date('Y-m-d H:i:s'); 
$result = null;

if (!empty($token)) {
    // โหมดลูกค้า: ใช้เครื่องหมายคำถามทั้งหมด
    $stmt = $pdo->prepare(
        "SELECT status, citizen_data, admin_id, sync_token 
         FROM temp_sync_consent 
         WHERE sync_token = ? AND expires_at > ? 
         LIMIT 1"
    );
    $stmt->execute([$token, $now]); // ส่งตามลำดับ ? ตัวที่ 1 และ 2
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($admin_id > 0) {
    // โหมดเจ้าหน้าที่: แก้ไขบรรทัดนี้ที่เคยพัง
    $stmt = $pdo->prepare(
        "SELECT status, citizen_data, admin_id, sync_token 
         FROM temp_sync_consent 
         WHERE admin_id = ? AND (expires_at > ? OR expires_at IS NULL)
         LIMIT 1"
    );
    $stmt->execute([$admin_id, $now]); // ส่งตามลำดับ ? ตัวที่ 1 และ 2
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // โหมด QR กระดาษ
    $stmt = $pdo->prepare(
        "SELECT status, citizen_data, admin_id, sync_token 
         FROM temp_sync_consent 
         WHERE status = 'pending' AND expires_at > ? 
         ORDER BY updated_at DESC LIMIT 1"
    );
    $stmt->execute([$now]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
}
// 2. ส่งค่ากลับ (ต้องมี sync_token ส่งกลับไปด้วยเสมอ)
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
    'sync_token'   => $result['sync_token'] 
], JSON_UNESCAPED_UNICODE);