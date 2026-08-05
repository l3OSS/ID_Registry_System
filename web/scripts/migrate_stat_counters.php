<?php
/**
 * scripts/migrate_stat_counters.php — สร้าง + backfill ตาราง stat_counters
 * ตัวนับสรุปสำหรับแดชบอร์ด (active_total + vuln:<v_id>) ให้อ่านแบบ O(1) ทุกขนาดข้อมูล
 *
 * รัน:  php scripts/migrate_stat_counters.php [--dry-run]
 * idempotent — statRebuildAll() ล้างแล้วคำนวณใหม่จากของจริงทุกครั้ง (ใช้ reconcile เมื่อสงสัยเพี้ยนได้)
 *
 * ⚠️ backfill เป็น query หนักตัวเดียว (นับ active ต่อกลุ่มเปราะบางจากทั้งตาราง) — รันแบบ CLI
 * ที่ไม่มี max_execution_time ดีกว่ารันในเว็บ
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");

require_once __DIR__ . '/../core/security.php';   // โหลด .env
require_once __DIR__ . '/../core/migrate.php';     // migStatCounters()
require_once __DIR__ . '/../core/stats.php';       // statRebuildAll()

$opts  = getopt('', ['dry-run']);
$apply = !isset($opts['dry-run']);

$h=$_ENV['DB_HOST']??'localhost';$P=$_ENV['DB_PORT']??'3306';$d=$_ENV['DB_NAME'];$u=$_ENV['DB_USER'];$p=$_ENV['DB_PASS'];
$pdo=new PDO("mysql:host=$h;port=$P;dbname=$d;charset=utf8mb4",$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

echo "== migrate_stat_counters ==\n";
echo migStatCounters($pdo, $apply) . "\n";

if (!$apply) { echo "(dry-run) ไม่ backfill\n"; exit; }

$t = microtime(true);
statRebuildAll($pdo);
printf("backfill เสร็จใน %.1f วินาที\n", microtime(true) - $t);

echo "-- ค่าปัจจุบัน --\n";
foreach ($pdo->query("SELECT ckey, cval FROM stat_counters ORDER BY ckey") as $r) {
    printf("  %-20s = %s\n", $r['ckey'], number_format((int)$r['cval']));
}
