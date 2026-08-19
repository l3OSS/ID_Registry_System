<?php
// install/requirements.php — รายการตรวจความพร้อมเซิร์ฟเวอร์ (ที่เดียว ใช้ร่วม UI + process_install)
// เดิมรายการนี้อยู่ใน index.php และเช็กเฉพาะฝั่งหน้าจอ → POST ตรงเข้า process_install.php ข้ามได้
// และตรวจ writable ไม่ครบ ทำให้ติดตั้งค้างครึ่งทางได้ (สร้าง DB สำเร็จ แต่เขียน .env ไม่ได้)

require_once __DIR__ . '/../core/lang.php'; // ป้ายรายการตรวจดึงจาก lang/th.php

/** @return array<string,bool> label => ผ่านหรือไม่ */
function installRequirements(): array
{
    $root = dirname(__DIR__);

    // key ของ array คือป้ายที่แสดง (index.php + process_install ใช้ต่อ) — ดึงคำแปลจาก lang
    return [
        t('inst.req_php')      => PHP_VERSION_ID >= 80100,
        t('inst.req_pdo')      => extension_loaded('pdo_mysql'),
        t('inst.req_openssl')  => extension_loaded('openssl'),
        t('inst.req_mbstring') => extension_loaded('mbstring'),
        t('inst.req_composer') => file_exists($root . '/vendor/autoload.php'),
        t('inst.req_uploads')  => is_dir($root . '/uploads') && is_writable($root . '/uploads'),
        t('inst.req_env')      => is_writable($root),
        t('inst.req_lock')     => is_writable(__DIR__),
    ];
}

/** @return string[] ชื่อรายการที่ไม่ผ่าน (ว่าง = พร้อมติดตั้ง) */
function installRequirementsFailed(): array
{
    return array_keys(array_filter(installRequirements(), static fn(bool $ok): bool => !$ok));
}
