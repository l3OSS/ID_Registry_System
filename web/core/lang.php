<?php
/**
 * ระบบไฟล์ภาษา — ศูนย์กลางข้อความทั้งระบบ
 *
 * ข้อความที่ผู้ใช้เห็นทุกคำอยู่ใน lang/<code>.php (แผนที่ key => ข้อความ)
 * แก้คำในระบบ = แก้ที่ไฟล์ภาษาที่เดียว ไม่ต้องไล่หาในโค้ด
 *
 * การใช้งาน:
 *   t('nav.guests')                      → "ทะเบียนผู้พัก"
 *   t('msg.deleted', ['name' => 'สมชาย']) → แทนที่ :name ในข้อความ
 *   e('btn.save')                        → เหมือน t() แต่ผ่าน htmlspecialchars แล้ว (ใช้ใน HTML)
 *
 * ถ้า key ไม่มีจริง จะคืนค่า key กลับมาตรง ๆ (เช่น "nav.guests") เพื่อให้เห็นทันทีว่าตกหล่น
 * แทนที่จะแสดงช่องว่างเงียบ ๆ
 */
declare(strict_types=1);

const LANG_DEFAULT = 'th';

/**
 * โหลดไฟล์ภาษาเข้าหน่วยความจำ (ครั้งเดียวต่อ request)
 * @return array<string,string>
 */
function langLoad(string $code = LANG_DEFAULT): array
{
    static $cache = [];

    if (isset($cache[$code])) {
        return $cache[$code];
    }

    // กัน path traversal — รหัสภาษาต้องเป็นตัวอักษร/ขีดล่างเท่านั้น
    if (!preg_match('/^[a-z_]+$/', $code)) {
        $code = LANG_DEFAULT;
    }

    $file = __DIR__ . '/../lang/' . $code . '.php';
    if (!is_file($file)) {
        $file = __DIR__ . '/../lang/' . LANG_DEFAULT . '.php';
    }

    $cache[$code] = is_file($file) ? (array)require $file : [];
    return $cache[$code];
}

/**
 * ดึงข้อความตาม key พร้อมแทนที่ตัวแปร
 * @param array<string,string|int> $vars ตัวแปรในข้อความ เช่น ['name' => 'สมชาย'] จะแทนที่ :name
 */
function t(string $key, array $vars = []): string
{
    $lang = langLoad();
    $text = $lang[$key] ?? $key;

    foreach ($vars as $k => $v) {
        $text = str_replace(':' . $k, (string)$v, $text);
    }
    return $text;
}

/** เหมือน t() แต่ escape พร้อมวางใน HTML — ใช้ตัวนี้เป็นหลักในหน้าเว็บ */
function e(string $key, array $vars = []): string
{
    return htmlspecialchars(t($key, $vars), ENT_QUOTES, 'UTF-8');
}
