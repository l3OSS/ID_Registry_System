<?php
/**
 * scripts/backup_db.php (CLI wrapper) — สำรอง DB ก่อนแตะ schema/ข้อมูลเข้ารหัส
 * logic จริงอยู่ที่ core/migrate.php :: migBackup()
 * ใช้: & 'C:\wamp64\bin\php\php8.3.28\php.exe' scripts/backup_db.php
 * ผลลัพธ์: backups/reg_<timestamp>.sql (โฟลเดอร์ backups/ ถูก .gitignore)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (!file_exists($root . '/.env')) {
    fwrite(STDERR, "❌ ไม่พบไฟล์ .env — ยังไม่ได้ติดตั้งระบบ?\n");
    exit(1);
}
Dotenv\Dotenv::createImmutable($root)->load();
require_once $root . '/core/migrate.php';

$db = $_ENV['DB_NAME'] ?? '';
if ($db === '' || ($_ENV['DB_USER'] ?? '') === '') {
    fwrite(STDERR, "❌ ค่า DB ใน .env ไม่ครบ\n");
    exit(1);
}

echo "▶ กำลังสำรอง `$db` ...\n";
$res = migBackup([
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'db'   => $db,
    'user' => $_ENV['DB_USER'] ?? '',
    'pass' => $_ENV['DB_PASS'] ?? '',
    'root' => $root,
]);

if ($res['ok']) {
    echo "✅ " . $res['msg'] . "\n";
    exit(0);
}
fwrite(STDERR, "❌ " . $res['msg'] . "\n");
exit(1);
