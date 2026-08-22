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

require_once __DIR__ . '/../core/lang.php'; // ข้อความทั้งหมดอยู่ที่ lang/th.php

$root = dirname(__DIR__);

// 1. ต้องเป็น POST + ตรวจ nonce (กัน drive-by)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?mode=update');
    exit();
}
if (empty($_POST['update_token']) || empty($_SESSION['update_token'])
    || !hash_equals($_SESSION['update_token'], (string)$_POST['update_token'])) {
    http_response_code(400);
    die(e('inst.upd_bad_token'));
}
unset($_SESSION['update_token']); // ใช้ครั้งเดียว

// 2. ต้องมีการติดตั้งเดิมจริง (มี .env)
if (!file_exists($root . '/.env')) {
    die(e('inst.upd_no_env'));
}

require_once $root . '/config/db.php';    // $pdo + โหลด .env
require_once $root . '/core/migrate.php'; // engine กลาง

// 3. ตรวจว่าเป็น DB ของ Reg จริง (มีตาราง citizens)
$hasCitizens = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citizens'"
)->fetchColumn();
if (!$hasCitizens) {
    die(e('inst.upd_no_citizens'));
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
$steps[] = [$bk['ok'], t('inst.upd_step_backup') . $bk['msg']];
$backup_failed = !$bk['ok'];

// 5. รัน migration (เฉพาะเมื่อ backup สำเร็จ) — แต่ละขั้นแยก try/catch เพื่อรายงานครบ
if (!$backup_failed) {
    foreach ([
        t('inst.upd_step_p8')          => fn() => migP8Triggers($pdo),
        t('inst.upd_step_p7')          => fn() => migP7PublicId($pdo, true),
        t('inst.upd_step_display_key') => fn() => migDisplayKey($pdo, true),
        t('inst.upd_step_pdpa')        => fn() => migPdpaToggle($pdo, true),
        t('inst.upd_step_siteurl')     => fn() => migSiteUrl($pdo, true),
        t('inst.upd_step_entity')      => fn() => migEntityTerm($pdo, true),
        t('inst.upd_step_viewer')      => fn() => migViewerRole($pdo, true),
        t('inst.upd_step_home')        => fn() => migHomeAddress($pdo, true),
        t('inst.upd_step_prefix')      => fn() => migNamePrefix($pdo, true),
        t('inst.upd_step_stay')        => function () use ($pdo) {
            $msg = migStayDenorm($pdo, true);
            // backfill สำหรับ DB เดิม (single statement เหมาะกับขนาดปกติ · ข้อมูลจำนวนมากใช้ scripts/migrate_stay_denorm.php)
            $pdo->exec("UPDATE citizens c
                LEFT JOIN (SELECT citizen_id, MAX(check_in) AS last_at, MAX(status='Active') AS act
                           FROM stay_history GROUP BY citizen_id) s ON s.citizen_id = c.id
                SET c.last_stay_at = s.last_at, c.is_active = COALESCE(s.act, 0)");
            return $msg . t('inst.upd_backfill_stay');
        },
        t('inst.upd_step_stat')        => function () use ($pdo) {
            $msg = migStatCounters($pdo, true);
            // backfill ค่าจริง (single statement เหมาะกับขนาดปกติ · ข้อมูลจำนวนมากใช้ scripts/migrate_stat_counters.php)
            require_once dirname(__DIR__) . '/core/stats.php';
            statRebuildAll($pdo);
            return $msg . t('inst.upd_backfill_stat');
        },
        t('inst.upd_step_p5p6')        => fn() => migP5P6Reencrypt($pdo, true),
    ] as $label => $fn) {
        try {
            $steps[] = [true, $fn()];
        } catch (Throwable $e) {
            $steps[] = [false, $label . t('inst.upd_step_failed') . $e->getMessage()];
        }
    }
}

$all_ok = !in_array(false, array_map(fn($s) => $s[0], $steps), true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?php echo e('inst.upd_page_title'); ?></title>
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
                <?php echo $all_ok ? e('inst.upd_ok') : e('inst.upd_incomplete'); ?>
            </h3>
        </div>
        <div class="card-body p-4">
            <?php if ($backup_failed): ?>
                <div class="alert alert-danger border-0">
                    <?php echo t('inst.upd_backup_fail'); ?>
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
                    <i class="bi bi-shield-lock"></i> <?php echo t('inst.upd_rm_install'); ?>
                </div>
            <?php endif; ?>
            <a href="../index.php" class="btn btn-primary w-100 fw-bold"><?php echo e('inst.upd_login_btn'); ?></a>
        </div>
    </div>
</div>
</body>
</html>
