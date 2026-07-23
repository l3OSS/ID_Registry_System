<?php
/**
 * install/process_update.php — โหมด "อัพเดตจากเวอร์ชันเก่า"
 * สำรอง DB → รัน migration ทั้งหมด (P8/P7/P5+P6) แบบ idempotent บนฐานข้อมูลเดิม
 * ไม่แตะ .env / บัญชีผู้ใช้ / ข้อมูลที่ถูกต้องอยู่แล้ว
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$root = dirname(__DIR__);

// 1. ต้องเป็น POST + ตรวจ nonce (กัน drive-by)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?mode=update');
    exit();
}
if (empty($_POST['update_token']) || empty($_SESSION['update_token'])
    || !hash_equals($_SESSION['update_token'], (string)$_POST['update_token'])) {
    http_response_code(400);
    die('❌ คำขอไม่ถูกต้อง (token หมดอายุ) — กรุณาเริ่มจากหน้าอัพเดตใหม่');
}
unset($_SESSION['update_token']); // ใช้ครั้งเดียว

// 2. ต้องมีการติดตั้งเดิมจริง (มี .env)
if (!file_exists($root . '/.env')) {
    die('❌ ไม่พบไฟล์ .env — ระบบยังไม่เคยติดตั้ง จึงอัพเดตไม่ได้ (ใช้โหมดติดตั้งครั้งแรก)');
}

require_once $root . '/config/db.php';    // $pdo + โหลด .env
require_once $root . '/core/migrate.php'; // engine กลาง

// 3. ตรวจว่าเป็น DB ของ Reg จริง (มีตาราง citizens)
$hasCitizens = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citizens'"
)->fetchColumn();
if (!$hasCitizens) {
    die('❌ ไม่พบตาราง citizens ในฐานข้อมูล — ไม่ใช่การติดตั้ง Reg ที่ถูกต้อง');
}

$steps = []; // [ok(bool), text]

// 4. สำรอง DB ก่อนเสมอ — ถ้าล้มเหลว หยุดทันที (ไม่ยอมแตะข้อมูลโดยไม่มี backup)
$bk = migBackup([
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'db'   => $_ENV['DB_NAME'] ?? '',
    'user' => $_ENV['DB_USER'] ?? '',
    'pass' => $_ENV['DB_PASS'] ?? '',
    'root' => $root,
]);
$steps[] = [$bk['ok'], 'สำรองฐานข้อมูล: ' . $bk['msg']];
$backup_failed = !$bk['ok'];

// 5. รัน migration (เฉพาะเมื่อ backup สำเร็จ) — แต่ละขั้นแยก try/catch เพื่อรายงานครบ
if (!$backup_failed) {
    foreach ([
        'P8 (activity_logs append-only)' => fn() => migP8Triggers($pdo),
        'P7 (public_id)'                 => fn() => migP7PublicId($pdo, true),
        'display_key (จอยินยอม/QR)'      => fn() => migDisplayKey($pdo, true),
        'pdpa_enabled (สวิตช์ PDPA)'      => fn() => migPdpaToggle($pdo, true),
        'site_url + qr_ip'               => fn() => migSiteUrl($pdo, true),
        'ภูมิลำเนา (citizens.home_*)'      => fn() => migHomeAddress($pdo, true),
        'P5+P6 (re-encrypt GCM)'         => fn() => migP5P6Reencrypt($pdo, true),
    ] as $label => $fn) {
        try {
            $steps[] = [true, $fn()];
        } catch (Throwable $e) {
            $steps[] = [false, "$label ล้มเหลว: " . $e->getMessage()];
        }
    }
}

$all_ok = !in_array(false, array_map(fn($s) => $s[0], $steps), true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ผลการอัพเดต - Reg</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>body{background:#f4f7f6}.rc{max-width:720px;margin:50px auto;border-radius:15px}</style>
</head>
<body>
<div class="container">
    <div class="card rc shadow-lg border-0">
        <div class="card-header <?php echo $all_ok ? 'bg-success' : 'bg-danger'; ?> text-white p-4 text-center">
            <h3 class="mb-0 fw-bold">
                <i class="bi <?php echo $all_ok ? 'bi-check-circle-fill' : 'bi-exclamation-octagon-fill'; ?>"></i>
                <?php echo $all_ok ? 'อัพเดตสำเร็จ' : 'อัพเดตไม่สมบูรณ์'; ?>
            </h3>
        </div>
        <div class="card-body p-4">
            <?php if ($backup_failed): ?>
                <div class="alert alert-danger border-0">
                    <strong>หยุดการอัพเดต:</strong> สำรองฐานข้อมูลไม่สำเร็จ จึงไม่แตะข้อมูลใด ๆ
                    (ตรวจว่ามี <code>mysqldump</code> หรือกำหนด env <code>MYSQLDUMP</code>)
                </div>
            <?php endif; ?>
            <ul class="list-group mb-4">
                <?php foreach ($steps as [$ok, $text]): ?>
                    <li class="list-group-item">
                        <i class="bi <?php echo $ok ? 'bi-check-circle text-success' : 'bi-x-circle text-danger'; ?>"></i>
                        <?php echo nl2br(htmlspecialchars($text)); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($all_ok): ?>
                <div class="alert alert-warning border-0 small">
                    <i class="bi bi-shield-lock"></i> <strong>แนะนำ:</strong> ลบโฟลเดอร์ <code>install/</code> ออกหลังอัพเดตเสร็จ
                </div>
            <?php endif; ?>
            <a href="../index.php" class="btn btn-primary w-100 fw-bold">เข้าสู่ระบบ</a>
        </div>
    </div>
</div>
</body>
</html>
