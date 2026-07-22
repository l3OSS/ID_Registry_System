<?php
/**
 * core/session.php (Tier1) — เริ่ม session พร้อม cookie flags ที่ปลอดภัย
 *
 *  httponly     : JavaScript อ่าน cookie ไม่ได้ → กัน XSS ขโมย session
 *  samesite=Lax : browser ไม่แนบ cookie ใน cross-site POST → เสริมการกัน CSRF
 *  secure       : ส่ง cookie เฉพาะเมื่อรันบน HTTPS
 *
 * เรียก start_secure_session() แทน session_start() ทุกจุดที่เป็น entry point
 * (idempotent — จุดแรกที่รันเป็นตัวกำหนด cookie params, จุดถัด ๆ ไปเป็น no-op)
 *
 * "จำการเข้าสู่ระบบ" (remember me): ตัวคุมว่า logout เมื่อปิดเบราว์เซอร์หรือไม่ คือ
 * 'lifetime' ของ cookie (0 = ปิดเบราว์เซอร์แล้วหลุด) — ตั้งตอนล็อกอินสำเร็จใน login.php
 * ส่วนค่านี้แค่ยืดอายุ session ฝั่งเซิร์ฟเวอร์ให้อยู่ได้นานพอ ไม่ให้ GC ลบทิ้งก่อนคุกกี้หมดอายุ
 */
if (!defined('SESSION_REMEMBER_LIFETIME')) {
    define('SESSION_REMEMBER_LIFETIME', 30 * 24 * 60 * 60); // 30 วัน (วินาที)
}

if (!function_exists('start_secure_session')) {
    function start_secure_session(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
              || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        // เก็บไฟล์ session ฝั่งเซิร์ฟเวอร์ไว้ให้นานพอสำหรับ "จำการเข้าสู่ระบบ"
        // (ค่า default 1440 วิ จะทำให้ผู้ที่ติ๊กจำไว้ถูก GC เตะออกภายใน 24 นาทีที่ไม่ได้ใช้งาน)
        ini_set('session.gc_maxlifetime', (string) SESSION_REMEMBER_LIFETIME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $https,
        ]);
        session_start();
    }
}
