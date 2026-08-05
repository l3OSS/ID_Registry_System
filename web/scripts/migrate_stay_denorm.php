<?php
/**
 * scripts/migrate_stay_denorm.php — เพิ่ม + backfill citizens.is_active / last_stay_at
 * แก้คอขวดหน้ารายชื่อ/แดชบอร์ด (correlated subquery + active subquery) บนข้อมูลจำนวนมาก
 *
 * รัน:  php scripts/migrate_stay_denorm.php [--dry-run] [--chunk=100000]
 * idempotent — รันซ้ำได้ · backfill ทำเป็นแบตช์ตามช่วง id (เบา ไม่ล็อกทั้งตาราง)
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");

require_once __DIR__ . '/../core/security.php';   // โหลด .env
require_once __DIR__ . '/../core/migrate.php';     // migStayDenorm()

$opts   = getopt('', ['dry-run', 'chunk::']);
$apply  = !isset($opts['dry-run']);
$chunk  = max(10000, (int)($opts['chunk'] ?? 100000));

$h=$_ENV['DB_HOST']??'localhost';$P=$_ENV['DB_PORT']??'3306';$d=$_ENV['DB_NAME'];$u=$_ENV['DB_USER'];$p=$_ENV['DB_PASS'];
$pdo=new PDO("mysql:host=$h;port=$P;dbname=$d;charset=utf8mb4",$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

echo "== migrate_stay_denorm ==\n";
echo migStayDenorm($pdo, $apply) . "\n";

if (!$apply) { echo "(dry-run) ไม่ backfill\n"; exit; }

// ---------- backfill แบบแบตช์ตามช่วง id ----------
$min = (int)$pdo->query("SELECT COALESCE(MIN(id),0) FROM citizens")->fetchColumn();
$max = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM citizens")->fetchColumn();
if ($max === 0) { echo "citizens ว่าง — ไม่ต้อง backfill\n"; exit; }

echo "backfill id $min..$max (chunk=" . number_format($chunk) . ")\n";
$sql = "UPDATE citizens c
        LEFT JOIN (
            SELECT citizen_id, MAX(check_in) AS last_at, MAX(status='Active') AS act
            FROM stay_history
            WHERE citizen_id BETWEEN :lo AND :hi
            GROUP BY citizen_id
        ) s ON s.citizen_id = c.id
        SET c.last_stay_at = s.last_at,
            c.is_active     = COALESCE(s.act, 0)
        WHERE c.id BETWEEN :lo AND :hi";
$stmt = $pdo->prepare($sql);

$start = microtime(true);
$done = 0;
for ($lo = $min; $lo <= $max; $lo += $chunk) {
    $hi = $lo + $chunk - 1;
    $stmt->execute([':lo' => $lo, ':hi' => $hi]);
    $done += $stmt->rowCount();
    $rate = ($lo - $min + $chunk) / max(0.001, microtime(true) - $start);
    printf("  id ถึง %s  (~%s แถว/วิ)\n", number_format($hi), number_format((int)$rate));
}
$active = (int)$pdo->query("SELECT COUNT(*) FROM citizens WHERE is_active=1")->fetchColumn();
printf("✅ backfill เสร็จใน %.1f วิ · is_active=1 มี %s คน\n", microtime(true) - $start, number_format($active));
