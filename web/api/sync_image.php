<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');
/**
 * API: Sync Image (S4)
 * เสิร์ฟรูปบัตร PDPA ชั่วคราวเฉพาะเมื่อยืนยันตัวตนแล้ว:
 *   - มี sync_token ที่ถูกต้องและยังไม่หมดอายุ (ฝั่งแท็บเล็ต/ลูกค้า), หรือ
 *   - เป็น session แอดมินเจ้าของแถว (ฝั่งเจ้าหน้าที่)
 * ชื่อไฟล์จริงถูกเก็บใน citizen_data._img ฝั่ง server (ไม่ถูกส่งให้ client)
 */
require_once __DIR__ . '/../core/session.php';
start_secure_session();
require_once __DIR__ . '/../config/db.php';

$token    = $_GET['t'] ?? '';
$admin_id = (int)($_SESSION['user_id'] ?? 0);
$now      = date('Y-m-d H:i:s');

// ใช้ครั้งเดียว: เสิร์ฟรูปเฉพาะแถวที่ยัง pending — ยืนยันแล้ว/หมดอายุแล้ว = 404
$citizen_json = false;
if ($token !== '') {
    $stmt = $pdo->prepare(
        "SELECT citizen_data FROM temp_sync_consent
         WHERE sync_token = ? AND status = 'pending' AND expires_at > ? LIMIT 1"
    );
    $stmt->execute([$token, $now]);
    $citizen_json = $stmt->fetchColumn();
} elseif ($admin_id > 0) {
    $stmt = $pdo->prepare(
        "SELECT citizen_data FROM temp_sync_consent
         WHERE admin_id = ? AND status = 'pending' AND (expires_at > ? OR expires_at IS NULL) LIMIT 1"
    );
    $stmt->execute([$admin_id, $now]);
    $citizen_json = $stmt->fetchColumn();
}

if ($citizen_json === false) {
    http_response_code(404);
    exit;
}

$data = json_decode((string)$citizen_json, true) ?: [];
$name = isset($data['_img']) ? basename((string)$data['_img']) : '';
if ($name === '') {
    http_response_code(404);
    exit;
}

$path = __DIR__ . '/../uploads/temp/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Cache-Control: no-store, private');
header('Content-Length: ' . filesize($path));
readfile($path);
