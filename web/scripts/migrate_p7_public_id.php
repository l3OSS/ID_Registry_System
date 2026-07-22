<?php
/**
 * Migration P7 (CLI wrapper) — citizens.public_id (13 หลัก) + backfill
 * logic จริงอยู่ที่ core/migrate.php :: migP7PublicId()
 * ใช้: php scripts/migrate_p7_public_id.php [--apply]   (idempotent)
 *   ไม่มี --apply = dry-run
 *
 * ⚠ แตะ schema → backup ก่อน (scripts/backup_db.php)
 */
declare(strict_types=1);
$apply = in_array('--apply', $argv, true);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../core/migrate.php';

echo $apply ? "== APPLY MODE (เขียนจริง) ==\n" : "== DRY-RUN (ใส่ --apply เพื่อทำจริง) ==\n";
echo migP7PublicId($pdo, $apply) . "\n";
echo "เสร็จสิ้น" . ($apply ? "" : " (dry-run)") . "\n";
