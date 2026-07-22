<?php
/**
 * Migration display_key (CLI wrapper) — เพิ่ม users.display_key สำหรับจอยินยอม (QR)
 * logic จริงอยู่ที่ core/migrate.php :: migDisplayKey()
 * ใช้: php scripts/migrate_display_key.php            (dry-run)
 *      php scripts/migrate_display_key.php --apply    (เขียนจริง, idempotent)
 */
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../core/migrate.php';

$apply = in_array('--apply', $argv, true);

try {
    echo ($apply ? "✅ " : "🔎 ") . migDisplayKey($pdo, $apply) . "\n";
    if (!$apply) {
        echo "   (dry-run — ใส่ --apply เพื่อเขียนจริง)\n";
    }
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
