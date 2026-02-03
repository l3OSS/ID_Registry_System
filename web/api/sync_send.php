<?php
declare(strict_types=1);
/**
 * API: Sync Send
 * Admin ส่งข้อมูลผู้พักที่เพิ่งอ่านจากบัตรไปเก็บในตารางชั่วคราวเพื่อให้แท็บเล็ตดึงไปโชว์
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

$admin_id = (int)($_SESSION['user_id'] ?? 0);
$rawData  = json_decode(file_get_contents('php://input'), true);

if ($admin_id <= 0 || empty($rawData)) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

// สร้าง Token แบบสุ่ม 16 ตัวอักษร และตั้งเวลาหมดอายุ 10 นาที
$sync_token = bin2hex(random_bytes(8)); 
$expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

/* Address */
$addr_parts = array_filter([
    $rawData['addr_number'] ?? '',
    !empty($rawData['addr_tambon'])   ? 'ต.' . $rawData['addr_tambon']   : '',
    !empty($rawData['addr_amphoe'])   ? 'อ.' . $rawData['addr_amphoe']   : '',
    !empty($rawData['addr_province']) ? 'จ.' . $rawData['addr_province'] : '',
]);

$full_address = $addr_parts ? implode(' ', $addr_parts) : 'ไม่ระบุข้อมูลที่อยู่';

/* Birth */
$birth = $rawData['birthdate'] ?? 'ไม่ระบุ';
if (strlen($birth) === 4) {
    $birth = 'พ.ศ. ' . $birth;
}

/* Data */
$data = [
    'full_name' => trim(
        ($rawData['prefix'] ?? '') .
        ($rawData['fname'] ?? '') . ' ' .
        ($rawData['lname'] ?? '')
    ),
    'id_card' => $rawData['idCard'] ?? '-----------',
    'birth'   => $birth,
    'address' => $full_address
];

/* Image */
$uploadDir  = '../uploads/temp/';
$imagePath = $uploadDir . "view_{$admin_id}.jpg";

if (!empty($rawData['photo']) && strlen($rawData['photo']) > 100) {
    is_dir($uploadDir) || mkdir($uploadDir, 0777, true);
    $img = base64_decode(
        str_replace(' ', '+', preg_replace('#^data:image/\w+;base64,#', '', $rawData['photo']))
    );
    file_put_contents($imagePath, $img);
} elseif (file_exists($imagePath)) {
    unlink($imagePath);
}

/* Save DB พร้อม Token และเวลาหมดอายุ */
$stmt = $pdo->prepare(
    "INSERT INTO temp_sync_consent (admin_id, sync_token, citizen_data, status, expires_at)
     VALUES (?, ?, ?, 'pending', ?)
     ON DUPLICATE KEY UPDATE 
        sync_token = VALUES(sync_token), 
        citizen_data = VALUES(citizen_data), 
        status = 'pending',
        expires_at = VALUES(expires_at),
        updated_at = CURRENT_TIMESTAMP"
);
$stmt->execute([$admin_id, $sync_token, json_encode($data, JSON_UNESCAPED_UNICODE), $expires_at]);

echo json_encode(['success' => true, 'token' => $sync_token]);
