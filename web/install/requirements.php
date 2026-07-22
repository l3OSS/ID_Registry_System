<?php
// install/requirements.php — รายการตรวจความพร้อมเซิร์ฟเวอร์ (ที่เดียว ใช้ร่วม UI + process_install)
// เดิมรายการนี้อยู่ใน index.php และเช็กเฉพาะฝั่งหน้าจอ → POST ตรงเข้า process_install.php ข้ามได้
// และตรวจ writable ไม่ครบ ทำให้ติดตั้งค้างครึ่งทางได้ (สร้าง DB สำเร็จ แต่เขียน .env ไม่ได้)

/** @return array<string,bool> label => ผ่านหรือไม่ */
function installRequirements(): array
{
    $root = dirname(__DIR__);

    return [
        'PHP Version (8.1+)'          => PHP_VERSION_ID >= 80100,
        'PDO MySQL'                   => extension_loaded('pdo_mysql'),
        'OpenSSL (Security)'          => extension_loaded('openssl'),
        'Mbstring'                    => extension_loaded('mbstring'),
        'Composer (vendor/)'          => file_exists($root . '/vendor/autoload.php'),
        'Folder: uploads/ (เขียนได้)' => is_dir($root . '/uploads') && is_writable($root . '/uploads'),
        'เขียนไฟล์ .env ที่รากได้'    => is_writable($root),
        'เขียนไฟล์ install.lock ได้'  => is_writable(__DIR__),
    ];
}

/** @return string[] ชื่อรายการที่ไม่ผ่าน (ว่าง = พร้อมติดตั้ง) */
function installRequirementsFailed(): array
{
    return array_keys(array_filter(installRequirements(), static fn(bool $ok): bool => !$ok));
}
