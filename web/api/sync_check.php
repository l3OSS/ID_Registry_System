<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');
/**
 * API: Sync Check
 * แท็บเล็ตจะคอยเรียกไฟล์นี้ทุก 1-2 วินาที เพื่อดูว่ามีข้อมูลใหม่ส่งมาหรือยัง
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/session.php';
start_secure_session();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/security.php';

// 1. รับค่า Token / display key จาก URL (ถ้ามี)
$token       = $_GET['t'] ?? '';
$display_key = preg_replace('/[^a-f0-9]/', '', (string)($_GET['d'] ?? '')); // hex เท่านั้น
$admin_id    = (int)($_SESSION['user_id'] ?? 0);

$now = date('Y-m-d H:i:s');
$result = null;

// S5: ต้องมี capability (sync_token / display_key) หรือ session แอดมินเสมอ — ห้ามคืนข้อมูลแบบ
// broadcast ให้ใครก็ได้ที่ไม่ยืนยันตัวตน (เดิมโหมด "QR กระดาษ" รั่วข้อมูลผู้พักล่าสุด)
if (!empty($token)) {
    // โหมดลูกค้า: ยืนยันด้วย sync_token ที่สุ่ม (ต้องรู้ค่าถึงเรียกได้)
    $stmt = $pdo->prepare(
        "SELECT status, citizen_data, admin_id, sync_token
         FROM temp_sync_consent
         WHERE sync_token = ? AND expires_at > ?
         LIMIT 1"
    );
    $stmt->execute([$token, $now]); // ส่งตามลำดับ ? ตัวที่ 1 และ 2
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($display_key !== '') {
    // โหมดจอยินยอม (QR): อุปกรณ์ผู้พักไม่ได้ล็อกอินและยังไม่มี sync_token — ยืนยันด้วย display_key
    // ของเจ้าหน้าที่ (สุ่ม 128-bit, ต้องรู้ค่าถึงเรียกได้) แล้วคืนคิวของเจ้าหน้าที่คนนั้น
    $stmt = $pdo->prepare(
        "SELECT t.status, t.citizen_data, t.admin_id, t.sync_token
         FROM temp_sync_consent t
         JOIN users u ON u.id = t.admin_id
         WHERE u.display_key = ? AND u.is_active = 1
           AND (t.expires_at > ? OR t.expires_at IS NULL)
         LIMIT 1"
    );
    $stmt->execute([$display_key, $now]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($admin_id > 0) {
    // โหมดเจ้าหน้าที่: ยืนยันด้วย session (แท็บเล็ต/PC ที่ล็อกอินบัญชีเดียวกับที่ส่งข้อมูล)
    $stmt = $pdo->prepare(
        "SELECT status, citizen_data, admin_id, sync_token
         FROM temp_sync_consent
         WHERE admin_id = ? AND (expires_at > ? OR expires_at IS NULL)
         LIMIT 1"
    );
    $stmt->execute([$admin_id, $now]); // ส่งตามลำดับ ? ตัวที่ 1 และ 2
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
}
// ไม่มี token / display_key / session → $result คง null → คืน {"status":"none"} ด้านล่าง
// 2. ส่งค่ากลับ (ต้องมี sync_token ส่งกลับไปด้วยเสมอ)
if (!$result) {
    ob_clean();
    echo json_encode(['status' => 'none']);
    exit;
}

// ใช้ครั้งเดียว: ข้อมูลผู้พักถูกเปิดเผยเฉพาะตอนสถานะ pending เท่านั้น
// เมื่อยืนยันแล้ว (confirmed) คืนแค่สถานะ — ผู้ที่ถือ token/QR เดิมอ่านข้อมูลซ้ำไม่ได้อีก
$is_pending = ($result['status'] === 'pending');

$citizen = null;
if ($is_pending) {
    $citizen = json_decode($result['citizen_data'] ?? '[]', true);
    if (is_array($citizen)) {
        unset($citizen['_img']); // S4: ไม่ส่งชื่อไฟล์รูปให้ client — ดึงรูปผ่าน api/sync_image.php ด้วย token แทน
    }
}

ob_clean();
echo json_encode([
    'status'       => $result['status'],
    'citizen_data' => $citizen,
    'admin_id'     => (int)$result['admin_id'],
    'sync_token'   => $is_pending ? $result['sync_token'] : null
], JSON_UNESCAPED_UNICODE);