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
 * ตัวแปรระดับ request ที่ถูกฉีดเข้า t() ทุกครั้ง (configurable terminology)
 * ใช้กับ :entity — คำเรียกหน่วยข้อมูลหลัก (ผู้พัก/สมาชิก/ผู้ป่วย ฯลฯ) ที่ตั้งค่าได้ต่อองค์กร
 * bootstrap (index.php) เรียก langSetGlobal('entity', settings.entity_term) หลังโหลด core
 * @param array<string,string>|null $set ถ้าส่งมา = merge เข้าชุด global (ไม่ส่ง = อ่านอย่างเดียว)
 * @return array<string,string>
 */
function langGlobals(?array $set = null): array
{
    static $g = [];
    if ($set !== null) {
        foreach ($set as $k => $v) $g[$k] = (string)$v;
    }
    return $g;
}

/** ตั้งค่า global ตัวเดียว — น้ำตาลสำหรับ langGlobals(['k'=>'v']) */
function langSetGlobal(string $key, string $value): void
{
    langGlobals([$key => $value]);
}

/**
 * ดึงข้อความตาม key พร้อมแทนที่ตัวแปร
 * ลำดับความสำคัญของตัวแปร: ต่อ-call > global (settings) > ค่าเริ่มต้นจากไฟล์ภาษา
 *   - :entity เติมอัตโนมัติจาก settings.entity_term (ผ่าน langGlobals) — ถ้าไม่ตั้ง ใช้ 'entity.default' ในไฟล์ภาษา
 * @param array<string,string|int> $vars ตัวแปรในข้อความ เช่น ['name' => 'สมชาย'] จะแทนที่ :name
 */
function t(string $key, array $vars = []): string
{
    $lang = langLoad();
    $text = $lang[$key] ?? $key;

    // ค่าเริ่มต้นของ entity มาจากไฟล์ภาษา — ถูก override ด้วย global (settings) และ per-call ตามลำดับ
    $vars = $vars + langGlobals() + ['entity' => ($lang['entity.default'] ?? 'ผู้พัก')];

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
