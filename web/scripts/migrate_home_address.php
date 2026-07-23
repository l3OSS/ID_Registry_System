<?php
/**
 * Migration ภูมิลำเนา (CLI wrapper) — เพิ่ม citizens.home_same_as_reg / home_address_id / home_addr_number
 * logic จริงอยู่ที่ core/migrate.php :: migHomeAddress()
 * ใช้: php scripts/migrate_home_address.php            (dry-run)
 *      php scripts/migrate_home_address.php --apply    (เขียนจริง, idempotent)
 */
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../core/migrate.php';

$apply = in_array('--apply', $argv, true);

try {
    echo ($apply ? "✅ " : "🔎 ") . migHomeAddress($pdo, $apply) . "\n";
    if (!$apply) {
        echo "   (dry-run — ใส่ --apply เพื่อเขียนจริง)\n";
    }
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
