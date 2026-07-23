<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');
/**
 * API: Sync Send
 * Admin ส่งข้อมูลผู้พักที่เพิ่งอ่านจากบัตรไปเก็บในตารางชั่วคราวเพื่อให้แท็บเล็ตดึงไปโชว์
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/session.php';
start_secure_session();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/lang.php';   // ข้อความ JSON — api เปิดตรง ไม่ผ่าน index.php
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/functions.php'; // pdpaEnabled()

csrf_verify_json(); // P2/S2: ยืนยันด้วย session แอดมิน — token มาทาง header X-CSRF-Token

// สวิตช์ระบบยินยอม PDPA ปิดอยู่ → ไม่ต้องส่งข้อมูลไปแท็บเล็ต (defense-in-depth คู่กับ UI ที่ซ่อนปุ่ม)
if (!pdpaEnabled($pdo)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('api.pdpa_disabled')]);
    exit;
}

$admin_id = (int)($_SESSION['user_id'] ?? 0);
$rawData  = json_decode(file_get_contents('php://input'), true);

if ($admin_id <= 0 || empty($rawData)) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

/* Address */
$addr_parts = array_filter([
    $rawData['addr_number'] ?? '',
    !empty($rawData['addr_tambon'])   ? t('addr.tambon') . $rawData['addr_tambon']   : '',
    !empty($rawData['addr_amphoe'])   ? t('addr.amphoe') . $rawData['addr_amphoe']   : '',
    !empty($rawData['addr_province']) ? t('addr.changwat') . $rawData['addr_province'] : '',
    !empty($rawData['addr_zipcode'])  ? preg_replace('/\D/', '', (string)$rawData['addr_zipcode']) : '',
]);

$full_address = $addr_parts ? implode(' ', $addr_parts) : t('api.no_address');

/* Birth */
$birth = $rawData['birthdate'] ?? t('api.unspecified');
if (strlen($birth) === 4) {
    $birth = t('api.be_prefix') . $birth;
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

/* Image (S4: ชื่อไฟล์สุ่มเดาไม่ได้ + เสิร์ฟผ่าน api/sync_image.php ที่ยืนยันตัวตน + สิทธิ์ 0755) */
$uploadDir = __DIR__ . '/../uploads/temp/';
$img_name  = null;

// ลบรูปเก่าของแอดมินคนนี้ก่อน (กันไฟล์ค้างสะสมเมื่อชื่อสุ่มไม่ทับกัน)
$prevStmt = $pdo->prepare("SELECT citizen_data FROM temp_sync_consent WHERE admin_id = ? LIMIT 1");
$prevStmt->execute([$admin_id]);
$prevData = json_decode((string)($prevStmt->fetchColumn() ?: '[]'), true) ?: [];
if (!empty($prevData['_img'])) {
    $oldPath = $uploadDir . basename((string)$prevData['_img']);
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

if (!empty($rawData['photo']) && strlen($rawData['photo']) > 100) {
    is_dir($uploadDir) || mkdir($uploadDir, 0755, true);
    $img = base64_decode(
        str_replace(' ', '+', preg_replace('#^data:image/\w+;base64,#', '', $rawData['photo']))
    );
    $img_name = 'v_' . bin2hex(random_bytes(16)) . '.jpg';
    file_put_contents($uploadDir . $img_name, $img);
}

// เก็บชื่อไฟล์รูปไว้ฝั่ง server เท่านั้น (จะถูกตัดออกก่อนส่งให้ client ใน sync_check)
if ($img_name !== null) {
    $data['_img'] = $img_name;
}

// 1. สร้าง sync_token + เวลาหมดอายุ (วางไว้ก่อนส่วน Save DB)
// อายุสั้น 3 นาที: ข้อมูลผู้พัก (ชื่อ/เลขบัตร/รูป) ค้างอยู่ในคิวชั่วคราวนานเท่าไรก็เสี่ยงเท่านั้น
$sync_token = bin2hex(random_bytes(8));
$date = new DateTime("now", new DateTimeZone('Asia/Bangkok'));
$date->modify('+' . CONSENT_TTL_MINUTES . ' minutes');
$expires_at = $date->format('Y-m-d H:i:s');

// 2. ปรับ SQL INSERT/UPDATE ให้เก็บค่าใหม่ลงไปด้วย
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

// 3. ส่ง Token กลับไปให้หน้าเจ้าหน้าที่ด้วย (เพื่อเอาไปทำเงื่อนไขเช็ค)
echo json_encode(['success' => true, 'token' => $sync_token]);