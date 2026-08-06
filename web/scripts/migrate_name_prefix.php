<?php
/**
 * scripts/migrate_name_prefix.php — dictionary คำนำหน้าชื่อ + index สำหรับค้นหาใหม่ (UNION-per-arm)
 *   สร้างตาราง name_prefix + index citizens (idx_lastname / idx_address_id / idx_home_address_id)
 *   + backfill dictionary จาก citizens.prefix ที่มีอยู่
 *
 * รัน:  php scripts/migrate_name_prefix.php [--dry-run]
 * idempotent — สร้าง/เพิ่มเฉพาะที่ยังไม่มี · backfill เป็น INSERT IGNORE (รันซ้ำได้)
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");

require_once __DIR__ . '/../core/security.php';   // โหลด .env
require_once __DIR__ . '/../core/migrate.php';     // migNamePrefix()

$opts  = getopt('', ['dry-run']);
$apply = !isset($opts['dry-run']);

$h=$_ENV['DB_HOST']??'localhost';$P=$_ENV['DB_PORT']??'3306';$d=$_ENV['DB_NAME'];$u=$_ENV['DB_USER'];$p=$_ENV['DB_PASS'];
$pdo=new PDO("mysql:host=$h;port=$P;dbname=$d;charset=utf8mb4",$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

echo "== migrate_name_prefix ==\n";
echo migNamePrefix($pdo, $apply) . "\n";

if ($apply) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM name_prefix")->fetchColumn();
    echo "-- คำนำหน้าใน dictionary: $n รายการ --\n";
    foreach ($pdo->query("SELECT name FROM name_prefix ORDER BY name") as $r) {
        echo "  " . $r['name'] . "\n";
    }
}
