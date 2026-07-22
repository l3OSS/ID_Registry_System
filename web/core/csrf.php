<?php
/**
 * CSRF Protection (P2/S2)
 * ใช้ token ต่อ session — ฝัง csrf_field() ในฟอร์ม POST แล้ว csrf_verify() ที่ฝั่งรับ
 */

require_once __DIR__ . '/session.php';
start_secure_session();

/** คืน token ปัจจุบันของ session (สร้างครั้งแรกถ้ายังไม่มี) */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** HTML hidden field สำหรับฝังในฟอร์ม */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** ตรวจ token (ใช้ hash_equals กัน timing attack) — คืน bool
 *  อ่านจาก $_POST['csrf_token'] หรือ header X-CSRF-Token (สำหรับ AJAX/JSON) */
function csrf_check(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';
    }
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Guard ต้นไฟล์ที่รับ POST — no-op ถ้าไม่ใช่ POST
 * ถ้า token ไม่ผ่าน หยุดทันที (419)
 */
function csrf_verify(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (!csrf_check()) {
        http_response_code(419);
        if (function_exists('writeLog') && isset($GLOBALS['pdo'])) {
            @writeLog($GLOBALS['pdo'], 'CSRF_FAIL', 'CSRF token ไม่ถูกต้อง/หมดอายุ');
        }
        die('เซสชันหมดอายุหรือคำขอไม่ถูกต้อง (CSRF) — กรุณารีเฟรชหน้าแล้วลองใหม่');
    }
}

/**
 * Guard สำหรับ endpoint แบบ JSON/AJAX — ตรวจ token ทุก method (endpoint พวกนี้ state-changing เสมอ)
 * ถ้าไม่ผ่าน ตอบ JSON 419 แล้วจบ (ไม่ die HTML)
 */
function csrf_verify_json(): void {
    if (!csrf_check()) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'CSRF token ไม่ถูกต้องหรือหมดอายุ']);
        exit;
    }
}
