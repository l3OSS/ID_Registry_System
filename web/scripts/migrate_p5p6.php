<?php
/**
 * Migration P5+P6 (CLI wrapper) — re-hash (domain-separated) + re-encrypt PII เป็น GCM
 * logic จริงอยู่ที่ core/migrate.php :: migP5P6Reencrypt()
 * ใช้: php scripts/migrate_p5p6.php            → DRY-RUN
 *      php scripts/migrate_p5p6.php --apply    → เขียนจริง (ห่อ transaction)
 *
 * ⚠ backup ก่อนรัน --apply เสมอ · idempotent + integrity check ด้วย id_card_last4
 */
declare(strict_types=1);
$apply = in_array('--apply', $argv, true);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../core/migrate.php';

echo "==================================================\n";
echo " Migration P5+P6  |  MODE: " . ($apply ? "APPLY (เขียนจริง)" : "DRY-RUN (ไม่เขียน)") . "\n";
echo "==================================================\n";
try {
    echo migP5P6Reencrypt($pdo, $apply) . "\n";
} catch (Throwable $e) {
    echo "❌ ERROR: rollback แล้ว — " . $e->getMessage() . "\n";
    exit(1);
}
