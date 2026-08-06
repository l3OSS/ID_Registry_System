<?php
/**
 * scripts/shuffle_lastname.php — สลับ (shuffle) ค่า `lastname` ในตาราง citizens แบบทีละช่วง id
 *
 * จุดประสงค์: pseudonymize ข้อมูลทดสอบด้วยการสับนามสกุลไปมา โดย "ทำเป็นก้อนตามช่วง id"
 * เพื่อกัน DB ค้าง/ล็อกยาว — แต่ละก้อน (default 500,000 แถว) = 1 transaction แล้วปล่อยล็อกก่อนก้อนถัดไป
 *
 * วิธีสับ: อ่าน (id, lastname) ของก้อน → shuffle รายการ lastname (Fisher–Yates ของ PHP)
 *          → เขียนกลับผ่าน temp table + UPDATE JOIN (multiset ของนามสกุลคงเดิม แค่สลับเจ้าของ)
 * ไม่แตะคอลัมน์อื่น (hash/enc/public_id/is_active ฯลฯ) · lastname ไม่ได้เข้ารหัส จึงสับตรง ๆ ได้
 *
 * การรัน (ชี้ php ของ WAMP):
 *   & 'C:\wamp64\bin\php\php8.3.28\php.exe' scripts/shuffle_lastname.php --chunk=500000
 *
 * ธง:
 *   --chunk=N     จำนวนแถวต่อก้อน/ต่อ transaction (ค่าเริ่มต้น 500000)
 *   --start-id=N  เริ่มที่ id นี้ (ค่าเริ่มต้น = MIN(id))
 *   --end-id=N    จบที่ id นี้ (ค่าเริ่มต้น = MAX(id))
 *   --sub=N       ขนาด batch ตอน INSERT ลง temp table (ค่าเริ่มต้น 5000)
 *   --dry-run     แสดงช่วง id ที่จะทำ โดยไม่เขียน DB
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
ini_set('memory_limit', '1024M');           // ถือ id+lastname ของทั้งก้อนไว้ในหน่วยความจำ

require_once __DIR__ . '/../config/db.php';  // โหลด .env + สร้าง $pdo
if (!isset($pdo)) { exit("Error: ไม่พบ \$pdo จาก config/db.php\n"); }

// ---------- พาร์สอาร์กิวเมนต์ ----------
$opts  = getopt('', ['chunk::', 'start-id::', 'end-id::', 'sub::', 'dry-run']);
$CHUNK = max(1000, (int)($opts['chunk'] ?? 500000));
$SUB   = max(500,  (int)($opts['sub']   ?? 5000));
$DRY   = isset($opts['dry-run']);

// ---------- ขอบเขต id ----------
$minId = (int)$pdo->query("SELECT MIN(id) FROM citizens")->fetchColumn();
$maxId = (int)$pdo->query("SELECT MAX(id) FROM citizens")->fetchColumn();
if ($maxId === 0) { exit("citizens ว่าง — ไม่มีอะไรให้สับ\n"); }
$START = isset($opts['start-id']) ? max($minId, (int)$opts['start-id']) : $minId;
$END   = isset($opts['end-id'])   ? min($maxId, (int)$opts['end-id'])   : $maxId;
if ($END < $START) { exit("ช่วง id ไม่ถูกต้อง (start > end)\n"); }

echo "== shuffle lastname ==\n";
echo "DB=" . ($_ENV['DB_NAME'] ?? '?') . " · id " . number_format($START) . ".." . number_format($END)
   . " · chunk=" . number_format($CHUNK) . ($DRY ? " · DRY-RUN" : "") . "\n\n";

// ให้ transaction ก้อนใหญ่รอล็อกได้นานขึ้นเล็กน้อยกันพลาดกลางคัน
try { $pdo->exec("SET SESSION innodb_lock_wait_timeout = 120"); } catch (Throwable $e) {}

$startRun = microtime(true);
$totRows  = 0;
$ci       = 0;

for ($lo = $START; $lo <= $END; $lo += $CHUNK) {
    $hi = min($lo + $CHUNK - 1, $END);
    $ci++;
    $t0 = microtime(true);

    // อ่านก้อนนี้ (id, lastname)
    $stmt = $pdo->prepare("SELECT id, lastname FROM citizens WHERE id BETWEEN ? AND ?");
    $stmt->execute([$lo, $hi]);
    $rows = $stmt->fetchAll(PDO::FETCH_NUM);
    $cnt  = count($rows);

    if ($cnt < 2) {
        printf("chunk %d [%s..%s] rows=%d — ข้าม (น้อยเกินกว่าจะสับ)\n",
            $ci, number_format($lo), number_format($hi), $cnt);
        $totRows += $cnt;
        continue;
    }

    $ids = [];
    $lns = [];
    foreach ($rows as $r) { $ids[] = (int)$r[0]; $lns[] = $r[1]; }
    unset($rows);

    shuffle($lns);   // สลับลำดับนามสกุลภายในก้อน (multiset คงเดิม)

    if ($DRY) {
        printf("chunk %d [%s..%s] rows=%d (dry)\n", $ci, number_format($lo), number_format($hi), $cnt);
        $totRows += $cnt;
        continue;
    }

    // เขียนกลับผ่าน temp table + UPDATE JOIN (1 transaction ต่อก้อน)
    $pdo->beginTransaction();
    $pdo->exec("CREATE TEMPORARY TABLE _shuf_ln (id BIGINT UNSIGNED PRIMARY KEY, ln VARCHAR(255)) "
             . "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    for ($i = 0; $i < $cnt; $i += $SUB) {
        $slice = min($SUB, $cnt - $i);
        $ph    = implode(',', array_fill(0, $slice, '(?,?)'));
        $vals  = [];
        for ($j = 0; $j < $slice; $j++) { $vals[] = $ids[$i + $j]; $vals[] = $lns[$i + $j]; }
        $pdo->prepare("INSERT INTO _shuf_ln (id, ln) VALUES $ph")->execute($vals);
    }
    $pdo->exec("UPDATE citizens c JOIN _shuf_ln s ON c.id = s.id SET c.lastname = s.ln");
    $pdo->exec("DROP TEMPORARY TABLE _shuf_ln");
    $pdo->commit();

    $totRows += $cnt;
    $rate = $totRows / max(0.001, microtime(true) - $startRun);
    printf("chunk %d [%s..%s] rows=%d · %.1f วิ (สะสม %s · %s แถว/วิ)\n",
        $ci, number_format($lo), number_format($hi), $cnt, microtime(true) - $t0,
        number_format($totRows), number_format((int)$rate));
}

printf("\n✅ เสร็จ %d ก้อน · สับ %s แถว · รวม %.1f วิ\n",
    $ci, number_format($totRows), microtime(true) - $startRun);
