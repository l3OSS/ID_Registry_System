<?php
/**
 * Migration ปีเกิดอย่างเดียว (CLI wrapper) — เพิ่ม citizens.birth_year_only
 * logic จริงอยู่ที่ core/migrate.php :: migBirthYearOnly()
 * ใช้: php scripts/migrate_birth_year_only.php            (dry-run)
 *      php scripts/migrate_birth_year_only.php --apply    (เขียนจริง, idempotent)
 */
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../core/migrate.php';

$apply = in_array('--apply', $argv, true);

try {
    echo ($apply ? "✅ " : "🔎 ") . migBirthYearOnly($pdo, $apply) . "\n";
    if (!$apply) {
        echo "   (dry-run — ใส่ --apply เพื่อเขียนจริง)\n";
    }
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
