<?php
/**
 * Migration P8 (CLI wrapper) — activity_logs append-only ด้วย trigger
 * logic จริงอยู่ที่ core/migrate.php :: migP8Triggers()
 * ใช้: php scripts/migrate_p8_audit.php   (idempotent)
 */
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../core/migrate.php';

try {
    echo "✅ " . migP8Triggers($pdo) . "\n";
    foreach ($pdo->query("SHOW TRIGGERS LIKE 'activity_logs'") as $t) {
        echo "  - {$t['Trigger']} : {$t['Timing']} {$t['Event']}\n";
    }
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n   (ผู้ใช้ DB ต้องมีสิทธิ์ TRIGGER)\n";
    exit(1);
}
