<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');

/**
 * API: Sync Reset
 * ล้างข้อมูลชั่วคราวทิ้งทั้งหมด เพื่อเตรียมรับแขกคนใหม่
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/session.php';
start_secure_session();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/lang.php';   // ข้อความ JSON — api เปิดตรง ไม่ผ่าน index.php
require_once __DIR__ . '/../core/csrf.php';

csrf_verify_json(); // P2/S2: ยืนยันด้วย session แอดมิน — token มาทาง header X-CSRF-Token

$admin_id = (int)($_SESSION['user_id'] ?? 0);

if ($admin_id <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    /**
     * 🐞 ของเดิมเขียน `status = 'none'` แต่คอลัมน์เป็น enum('pending','confirmed') —
     * ไม่มีค่า 'none' อยู่จริง · MariaDB โหมด STRICT_TRANS_TABLES จึงตอบ
     * `1265 Data truncated for column 'status'` ทั้ง UPDATE และ INSERT fallback
     * → endpoint นี้ throw ทุกครั้ง คืน 500 และ **ไม่เคยล้างแถวได้เลยสักครั้ง**
     * (เงียบมานานเพราะ guest_form.php เรียกด้วย `await fetch()` โดยไม่เช็ค res.ok)
     *
     * แก้เป็น "ลบแถวทิ้ง" ซึ่งตรงกับที่ระบบใช้แทนสถานะว่างอยู่แล้ว:
     *   - sync_check.php  ไม่เจอแถว → คืน {"status":"none"}
     *   - guest_display.php รองรับ data.status === 'none' อยู่แล้ว
     * จึงไม่ต้องเพิ่มค่าใน enum และ **ไม่ต้อง migrate ฐานข้อมูลเดิม** — อัปโค้ดอย่างเดียวพอ
     */

    // ต้องอ่าน citizen_data ให้ได้ชื่อไฟล์รูป "ก่อน" ลบแถว ไม่งั้น pointer หายแล้วตามไฟล์ไม่เจอ
    // = รูปบัตรค้างใน uploads/temp ถาวร (ของเดิมไม่เคยลบรูปเลย ต่างจาก sync_confirm.php)
    $sel = $pdo->prepare("SELECT citizen_data FROM temp_sync_consent WHERE admin_id = ? LIMIT 1");
    $sel->execute([$admin_id]);
    $prev = json_decode((string)($sel->fetchColumn() ?: '[]'), true) ?: [];
    if (!empty($prev['_img'])) {
        $path = __DIR__ . '/../uploads/temp/' . basename((string)$prev['_img']);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ไม่มีแถวอยู่แล้วก็ไม่เป็นไร — DELETE ที่ไม่โดนแถวไหนไม่ถือว่าผิดพลาด (ผลลัพธ์ = "ว่าง" เหมือนกัน)
    $pdo->prepare("DELETE FROM temp_sync_consent WHERE admin_id = ?")->execute([$admin_id]);

    echo json_encode([
        'success' => true,
        'message' => t('api.reset_success')
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}