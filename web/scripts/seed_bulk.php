<?php
/**
 * scripts/seed_bulk.php — ตัวสุ่มข้อมูลผู้พักจำนวนมากสำหรับทดสอบประสิทธิภาพ (load test)
 *
 * ใช้ชื่อ-นามสกุลจริงจาก Design/data_700.csv เป็น pool แล้วสุ่ม เลขบัตร/ที่อยู่/วันเกิด/เบอร์โทร เอง
 * เข้ารหัสจริงทุกแถว (id_card_enc/phone_enc = GCM · id_card_hash = HMAC) — ระบบค้นหา/ถอดรหัสได้เหมือนข้อมูลจริง
 * เขียนลง DB ด้วย LOAD DATA LOCAL INFILE เป็นก้อน (เร็วสุด) — fallback เป็น multi-row INSERT ถ้าปิด local_infile
 *
 * การรัน (ชี้ php ของ WAMP):
 *   & 'C:\wamp64\bin\php\php8.3.28\php.exe' scripts/seed_bulk.php --count=15000000 --fresh
 *
 * ธง:
 *   --count=N        จำนวนคนที่จะสร้าง (ค่าเริ่มต้น 10000 — ทดลองก่อนยิงเต็ม)
 *   --fresh          TRUNCATE citizens/stay_history/map ก่อน seed (⚠️ ลบข้อมูลเดิม — สำรอง DB ก่อน)
 *   --batch=N        จำนวนแถวต่อรอบ LOAD DATA (ค่าเริ่มต้น 100000)
 *   --no-load-data   บังคับใช้ multi-row INSERT แทน LOAD DATA (กรณี local_infile ปิด)
 *
 * ⚠️ ก่อนรัน --fresh ให้สำรอง DB ก่อน:  php scripts/backup_db.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../core/security.php';   // โหลด vendor + .env + encryptData()/hashID()

// ---------- อ่านค่าคอนฟิกจาก .env (dotenv โหลดให้แล้วตอน require security.php) ----------
$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? '3306';
$db   = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';
if ($db === '' || $user === '') { exit("Error: DB config incomplete in .env\n"); }
if (empty(SECRET_KEY)) { exit("Error: ENCRYPTION_KEY missing in .env — ต้องมีคีย์จึงจะเข้ารหัสได้\n"); }

// ---------- พาร์สอาร์กิวเมนต์ ----------
$opts       = getopt('', ['count::', 'fresh', 'batch::', 'no-load-data', 'age-min::', 'age-max::']);
$TARGET     = max(1, (int)($opts['count'] ?? 10000));
$FRESH      = isset($opts['fresh']);
$BATCH      = max(1000, (int)($opts['batch'] ?? 100000));
$USE_LOAD   = !isset($opts['no-load-data']);
// ช่วงอายุที่จะสุ่ม (ปี) — จำกัดบนไม่ให้ปีเกิดต่ำกว่า ค.ศ.1000 (ขอบล่างของชนิด DATE ใน MySQL)
$AGE_MIN    = max(0, (int)($opts['age-min'] ?? 1));
$AGE_MAX    = (int)($opts['age-max'] ?? 800);
$maxAgeByDate = (int)date('Y') - 1000;               // อายุมากสุดที่ยังได้ปีเกิด >= 1000
if ($AGE_MAX > $maxAgeByDate) $AGE_MAX = $maxAgeByDate;
if ($AGE_MAX < $AGE_MIN) $AGE_MAX = $AGE_MIN;

// ---------- ที่เก็บไฟล์ CSV ชั่วคราวสำหรับ LOAD DATA ----------
$TMP_DIR = sys_get_temp_dir() . '/reg_seed_' . getmypid();
@mkdir($TMP_DIR, 0777, true);
$f_cit  = $TMP_DIR . '/citizens.tsv';
$f_stay = $TMP_DIR . '/stay.tsv';
$f_vul  = $TMP_DIR . '/vul.tsv';
$f_cust = $TMP_DIR . '/cust.tsv';
register_shutdown_function(function () use ($TMP_DIR) {
    foreach (glob($TMP_DIR . '/*') as $g) @unlink($g);
    @rmdir($TMP_DIR);
});

// ---------- เชื่อม DB (เปิด LOCAL INFILE เพื่อใช้ LOAD DATA) ----------
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
$pdoOpts = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
if ($USE_LOAD) { $pdoOpts[PDO::MYSQL_ATTR_LOCAL_INFILE] = true; }
try {
    $pdo = new PDO($dsn, $user, $pass, $pdoOpts);
} catch (PDOException $e) {
    exit("DB connection failed: " . $e->getMessage() . "\n");
}

echo "== Reg bulk seeder ==\n";
echo "DB=$db · target=" . number_format($TARGET) . " · batch=" . number_format($BATCH)
   . " · mode=" . ($USE_LOAD ? 'LOAD DATA' : 'INSERT') . ($FRESH ? " · FRESH" : "")
   . " · age=$AGE_MIN-$AGE_MAX ปี\n";

// ---------- pool ชื่อ-นามสกุล จาก Design/data_700.csv (prefix, firstname, lastname, gender) ----------
$poolFile = __DIR__ . '/../Design/data_700.csv';
if (!is_file($poolFile)) { exit("Error: ไม่พบ pool ชื่อ: $poolFile\n"); }
$names = [];   // [prefix, first, last, gender]
if (($fh = fopen($poolFile, 'r')) !== false) {
    fgetcsv($fh); // ข้ามหัวตาราง
    while (($r = fgetcsv($fh)) !== false) {
        $prefix = trim((string)($r[0] ?? ''));
        $first  = trim((string)($r[1] ?? ''));
        $last   = trim((string)($r[2] ?? ''));
        $gender = trim((string)($r[6] ?? '')) === 'Female' ? 'Female' : 'Male';
        if ($first === '' || $last === '') continue;
        $names[] = [$prefix, $first, $last, $gender];
    }
    fclose($fh);
}
$nameCount = count($names);
if ($nameCount === 0) { exit("Error: pool ชื่อว่างเปล่า\n"); }
echo "name pool = " . number_format($nameCount) . " รายการ\n";

// ---------- pool ที่อยู่จาก address_lookup จริง ----------
$addrRows = $pdo->query("SELECT id, subdistrict, district, province, zipcode FROM address_lookup")->fetchAll();
$addrCount = count($addrRows);
if ($addrCount === 0) { exit("Error: address_lookup ว่าง — ต้องมี master data ที่อยู่ก่อน\n"); }
echo "address pool = " . number_format($addrCount) . " รายการ\n";

// ---------- กลุ่มเปราะบางที่มีอยู่จริง (1=เด็ก, 2=ผู้สูงอายุ) ----------
$vulIds = $pdo->query("SELECT id FROM vulnerable_master")->fetchAll(PDO::FETCH_COLUMN);
$V_CHILD   = in_array(1, array_map('intval', $vulIds), true) ? 1 : 0;
$V_ELDERLY = in_array(2, array_map('intval', $vulIds), true) ? 2 : 0;

// ---------- custom fields ที่เปิดใช้งาน (req2: สุ่มค่า "กลุ่มเป้าหมายพิเศษ" ส่วน custom) ----------
// checkbox → ติ๊ก 'Yes' แบบสุ่ม · text → สุ่มคำจากคลังคำ (ค่ากลุ่มอายุ 0-5/ผู้สูงอายุ ยังตามอายุจริง)
$custMaster = $pdo->query("SELECT id, field_type FROM custom_field_master WHERE is_active = 1")->fetchAll();
$CUST_CHECK = []; $CUST_TEXT = [];
foreach ($custMaster as $cf) {
    if ($cf['field_type'] === 'text') $CUST_TEXT[] = (int)$cf['id'];
    else $CUST_CHECK[] = (int)$cf['id'];
}
$CUST_VOCAB = [
    'ต้องการความช่วยเหลือ', 'มีโรคประจำตัว', 'แพ้อาหาร/ยา', 'ตั้งครรภ์',
    'พิการทางการเคลื่อนไหว', 'ผู้ป่วยติดเตียง', 'ต้องการล่ามภาษา', 'ผู้ดูแลเด็กเล็ก',
    'ไม่มีผู้ดูแล', 'ต้องการยาประจำ', 'มีสัตว์เลี้ยง', 'ต้องการรถเข็น',
];
$CUST_VOCAB_N = count($CUST_VOCAB);
echo "custom fields = checkbox:" . count($CUST_CHECK) . " · text:" . count($CUST_TEXT) . "\n";

// ---------- admin id สำหรับ stay_history.admin_id (FK → users) ----------
$adminId = (int)$pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($adminId === 0) { exit("Error: ไม่มีผู้ใช้ในตาราง users — ติดตั้งระบบให้เสร็จก่อน\n"); }

// ---------- FRESH: ล้างตารางที่เกี่ยวข้อง ----------
if ($FRESH) {
    echo "FRESH: กำลัง TRUNCATE citizens / stay_history / map ...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach (['citizen_vulnerable_map', 'citizen_custom_values', 'stay_history', 'citizens'] as $t) {
        $pdo->exec("TRUNCATE TABLE `$t`");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

// ---------- ฐาน id (กำหนด id เอง เพื่อผูก stay/map ได้โดยไม่ต้อง query กลับ) ----------
$baseId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM citizens")->fetchColumn() + 1;
$basePublic = 1000000000000;   // public_id 13 หลัก (unique: base + seq)
$baseIdCard = 100000000000;    // เลขบัตร: body 12 หลัก (base + seq) แล้วต่อ checksum → 13 หลัก
// ลำดับสะสม: เริ่มนับต่อจากจำนวนแถวที่มีอยู่ (baseId-1) เพื่อกัน public_id/เลขบัตรชนกับ seed เดิมเวลาเพิ่มต่อท้าย (ไม่ใช้ --fresh)
$seqOffset  = $baseId - 1;
echo "เริ่มที่ citizens.id = " . number_format($baseId) . " (seq offset = " . number_format($seqOffset) . ")\n\n";

/** หลักตรวจ (หลักที่ 13) ของเลขบัตรไทย จาก body 12 หลัก */
function seed_checkDigit(string $body12): int {
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int)$body12[$i] * (13 - $i);
    return (11 - ($sum % 11)) % 10;
}

/** escape ค่าให้ปลอดภัยสำหรับไฟล์ TSV (FIELDS TERMINATED BY \t, LINES BY \n) */
function seed_tsv($v): string {
    if ($v === null) return '\\N';
    return str_replace(["\\", "\t", "\n", "\r"], ['\\\\', '\\t', '\\n', '\\r'], (string)$v);
}

// ---------- เตรียม statement fallback (multi-row INSERT) ----------
$CIT_COLS  = "(id,public_id,id_card_hash,id_card_enc,id_card_last4,prefix,firstname,lastname,gender,birthdate,phone_enc,addr_number,addr_tambon,addr_amphoe,addr_province,address_id,addr_zipcode,home_same_as_reg,created_at)";
$STAY_COLS = "(citizen_id,check_in,check_out,location_type,status,admin_id)";
$VUL_COLS  = "(citizen_id,v_id)";
$CUST_COLS = "(citizen_id,field_id,field_value)";

$now      = time();
$startRun = microtime(true);
$done     = 0;

// buffers (สำหรับ INSERT mode) / ไฟล์ (สำหรับ LOAD mode)
$bufCit = []; $bufStay = []; $bufVul = []; $bufCust = [];
$hCit = $hStay = $hVul = $hCust = null;

$openFiles = function () use (&$hCit, &$hStay, &$hVul, &$hCust, $f_cit, $f_stay, $f_vul, $f_cust) {
    $hCit = fopen($f_cit, 'w'); $hStay = fopen($f_stay, 'w');
    $hVul = fopen($f_vul, 'w'); $hCust = fopen($f_cust, 'w');
};
if ($USE_LOAD) $openFiles();

/** ยิงก้อนปัจจุบันลง DB แล้วเคลียร์ buffer/ไฟล์ */
$flush = function () use (
    &$bufCit, &$bufStay, &$bufVul, &$bufCust, &$hCit, &$hStay, &$hVul, &$hCust,
    $USE_LOAD, $pdo, $f_cit, $f_stay, $f_vul, $f_cust,
    $CIT_COLS, $STAY_COLS, $VUL_COLS, $CUST_COLS, $openFiles
) {
    if ($USE_LOAD) {
        fclose($hCit); fclose($hStay); fclose($hVul); fclose($hCust);
        $load = function (string $file, string $table, string $cols) use ($pdo) {
            if (!is_file($file) || filesize($file) === 0) return;
            $p = str_replace('\\', '/', $file);
            $pdo->exec(
                "LOAD DATA LOCAL INFILE '$p' INTO TABLE `$table` "
              . "FIELDS TERMINATED BY '\\t' ESCAPED BY '\\\\' LINES TERMINATED BY '\\n' $cols"
            );
        };
        $pdo->beginTransaction();
        $load($f_cit,  'citizens',                $CIT_COLS);
        $load($f_stay, 'stay_history',            $STAY_COLS);
        $load($f_vul,  'citizen_vulnerable_map',  $VUL_COLS);
        $load($f_cust, 'citizen_custom_values',   $CUST_COLS);
        $pdo->commit();
        $openFiles();   // เปิดไฟล์ใหม่ (truncate) สำหรับก้อนถัดไป
        return;
    }
    // INSERT mode (fallback เมื่อ local_infile ปิด)
    if ($bufCit) {
        $ph = implode(',', array_fill(0, 19, '?'));
        $sql = "INSERT INTO citizens $CIT_COLS VALUES " . implode(',', array_fill(0, count($bufCit), "($ph)"));
        $pdo->prepare($sql)->execute(array_merge(...$bufCit));
    }
    if ($bufStay) {
        $ph = implode(',', array_fill(0, 6, '?'));
        $sql = "INSERT INTO stay_history $STAY_COLS VALUES " . implode(',', array_fill(0, count($bufStay), "($ph)"));
        $pdo->prepare($sql)->execute(array_merge(...$bufStay));
    }
    if ($bufVul) {
        $ph = implode(',', array_fill(0, 2, '?'));
        $sql = "INSERT INTO citizen_vulnerable_map $VUL_COLS VALUES " . implode(',', array_fill(0, count($bufVul), "($ph)"));
        $pdo->prepare($sql)->execute(array_merge(...$bufVul));
    }
    if ($bufCust) {
        $ph = implode(',', array_fill(0, 3, '?'));
        $sql = "INSERT INTO citizen_custom_values $CUST_COLS VALUES " . implode(',', array_fill(0, count($bufCust), "($ph)"));
        $pdo->prepare($sql)->execute(array_merge(...$bufCust));
    }
    $bufCit = $bufStay = $bufVul = $bufCust = [];
};

for ($n = 0; $n < $TARGET; $n++) {
    $id  = $baseId + $n;
    $seq = $seqOffset + $n;                             // ลำดับสะสม (กันชนกับ seed เดิม)
    $pub = (string)($basePublic + $seq);

    // เลขบัตร 13 หลัก (checksum ถูก, unique)
    $body = str_pad((string)($baseIdCard + $seq), 12, '0', STR_PAD_LEFT);
    $body[0] = (string)(($seq % 8) + 1);               // หลักแรก 1-8 (ให้ดูสมจริง)
    $idcard = $body . seed_checkDigit($body);
    $last4  = substr($idcard, -4);

    // ชื่อจาก pool
    [$prefix, $first, $last, $gender] = $names[$n % $nameCount];

    // วันเกิดสุ่มตามช่วงอายุที่กำหนด (default 1-800 ปี)
    $age = random_int($AGE_MIN, $AGE_MAX);
    $bYear  = (int)date('Y') - $age;
    $bMonth = random_int(1, 12);
    $bDay   = random_int(1, 28);
    $birth  = sprintf('%04d-%02d-%02d', $bYear, $bMonth, $bDay);

    // req3: อายุ < 15 ปี → เด็กหญิง/เด็กชาย ตามเพศ · อายุ >= 15 → กันไม่ให้ติดคำนำหน้าเด็กที่ติดมาจาก pool
    if ($age < 15) {
        $prefix = ($gender === 'Female') ? 'เด็กหญิง' : 'เด็กชาย';
    } elseif ($prefix === 'เด็กหญิง' || $prefix === 'เด็กชาย') {
        $prefix = ($gender === 'Female') ? 'นางสาว' : 'นาย';
    }

    // ที่อยู่จาก address_lookup จริง
    $a = $addrRows[random_int(0, $addrCount - 1)];
    $addrNo = (string)random_int(1, 999) . '/' . random_int(0, 20);

    // เบอร์โทรสุ่ม
    $phone = '0' . random_int(6, 9) . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

    // เข้ารหัสจริง
    $idEnc    = encryptData($idcard);
    $phoneEnc = encryptData($phone);
    $idHash   = hashID($idcard);

    // เวลาเข้าพัก/สร้าง — กระจายย้อนหลัง 90 วัน (ให้ dashboard วันนี้มีข้อมูลบ้าง)
    $ts = date('Y-m-d H:i:s', $now - random_int(0, 90 * 86400));

    if ($USE_LOAD) {
        // ลำดับต้องตรงกับ $CIT_COLS
        fwrite($hCit, implode("\t", array_map('seed_tsv', [
            $id, $pub, $idHash, $idEnc, $last4, $prefix, $first, $last, $gender, $birth,
            $phoneEnc, $addrNo, $a['subdistrict'], $a['district'], $a['province'],
            $a['id'], $a['zipcode'], 1, $ts,
        ])) . "\n");
        fwrite($hStay, implode("\t", array_map('seed_tsv', [
            $id, $ts, null, 'Inside', 'Active', $adminId,
        ])) . "\n");
    } else {
        $bufCit[]  = [$id, $pub, $idHash, $idEnc, $last4, $prefix, $first, $last, $gender, $birth,
                      $phoneEnc, $addrNo, $a['subdistrict'], $a['district'], $a['province'],
                      $a['id'], $a['zipcode'], 1, $ts];
        $bufStay[] = [$id, $ts, null, 'Inside', 'Active', $adminId];
    }

    // auto-tag กลุ่มเปราะบางตามอายุ (เหมือน guest_check) — req2 ส่วนกลุ่มอายุ = ตามจริง
    if ($age <= 5 && $V_CHILD) {
        if ($USE_LOAD) fwrite($hVul, seed_tsv($id) . "\t" . seed_tsv($V_CHILD) . "\n");
        else $bufVul[] = [$id, $V_CHILD];
    } elseif ($age >= 60 && $V_ELDERLY) {
        if ($USE_LOAD) fwrite($hVul, seed_tsv($id) . "\t" . seed_tsv($V_ELDERLY) . "\n");
        else $bufVul[] = [$id, $V_ELDERLY];
    }

    // req2: สุ่มค่า custom fields — checkbox ~40% ติ๊ก 'Yes' · text ~35% มีค่าจากคลังคำ
    foreach ($CUST_CHECK as $fid) {
        if (random_int(1, 100) <= 40) {
            if ($USE_LOAD) fwrite($hCust, seed_tsv($id) . "\t" . seed_tsv($fid) . "\t" . seed_tsv('Yes') . "\n");
            else $bufCust[] = [$id, $fid, 'Yes'];
        }
    }
    foreach ($CUST_TEXT as $fid) {
        if (random_int(1, 100) <= 35) {
            $tv = $CUST_VOCAB[random_int(0, $CUST_VOCAB_N - 1)];
            if ($USE_LOAD) fwrite($hCust, seed_tsv($id) . "\t" . seed_tsv($fid) . "\t" . seed_tsv($tv) . "\n");
            else $bufCust[] = [$id, $fid, $tv];
        }
    }

    $done++;
    if ($done % $BATCH === 0) {
        $flush();
        $rate = $done / max(0.001, microtime(true) - $startRun);
        $eta  = ($TARGET - $done) / max(1, $rate);
        printf("  %s / %s  (%s แถว/วิ · เหลือ ~%d นาที)\n",
            number_format($done), number_format($TARGET), number_format((int)$rate), (int)($eta / 60));
    }
}
$flush();

// ---------- ตั้ง AUTO_INCREMENT ต่อจาก id สุดท้าย ----------
$pdo->exec("ALTER TABLE citizens AUTO_INCREMENT = " . ($baseId + $TARGET));

// ---------- backfill สถานะ denorm + ตัวนับแดชบอร์ด (ให้ DB พร้อมทดสอบทันที) ----------
// seed เขียน citizens โดยไม่ตั้ง is_active/last_stay_at → ต้อง backfill จาก stay_history
echo "backfill is_active/last_stay_at ...\n";
$tb = microtime(true);
$pdo->exec("UPDATE citizens c
    LEFT JOIN (SELECT citizen_id, MAX(check_in) AS last_at, MAX(status='Active') AS act
               FROM stay_history GROUP BY citizen_id) s ON s.citizen_id = c.id
    SET c.last_stay_at = s.last_at, c.is_active = COALESCE(s.act, 0)");
printf("  เสร็จใน %.1f วิ\n", microtime(true) - $tb);

echo "backfill stat_counters ...\n";
$tb = microtime(true);
require_once __DIR__ . '/../core/stats.php';
statRebuildAll($pdo);
printf("  เสร็จใน %.1f วิ\n", microtime(true) - $tb);

// dictionary คำนำหน้า (ค้นหา) — เก็บคำนำหน้าที่ seed ใช้จริงเข้า name_prefix
echo "backfill name_prefix ...\n";
try {
    $pdo->exec("INSERT IGNORE INTO name_prefix (name)
                SELECT DISTINCT TRIM(prefix) FROM citizens
                WHERE prefix IS NOT NULL AND TRIM(prefix) <> ''");
    echo "  เสร็จ (" . (int)$pdo->query("SELECT COUNT(*) FROM name_prefix")->fetchColumn() . " รายการ)\n";
} catch (\Throwable $e) {
    echo "  ข้าม (name_prefix ยังไม่ได้ migrate?): " . $e->getMessage() . "\n";
}

$elapsed = microtime(true) - $startRun;
echo "\n✅ เสร็จ: สร้าง " . number_format($TARGET) . " คน ใน " . number_format($elapsed, 1) . " วิ"
   . " (" . number_format((int)($TARGET / max(0.001, $elapsed))) . " แถว/วิ)\n";
echo "citizens.id ช่วง " . number_format($baseId) . " - " . number_format($baseId + $TARGET - 1) . "\n";
