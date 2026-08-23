<?php
/**
 * Migration — ผังสถานที่พักแบบลำดับชั้น (ตาราง locations + stay_history.location_id)
 * ยกมาจากโปรเจค Sec · logic จริงอยู่ที่ core/migrate.php :: migLocations()
 *
 * ใช้:
 *   php scripts/migrate_locations.php            ลงมือจริง (สร้างตาราง/คอลัมน์)
 *   php scripts/migrate_locations.php --dry      ดูว่าจะทำอะไร ไม่แตะ DB
 *   php scripts/migrate_locations.php --seed     ลงมือจริง + seed ผังตัวอย่าง (เฉพาะเมื่อ locations ว่าง)
 */
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../core/migrate.php';

$apply = !in_array('--dry', $argv ?? [], true);
$seed  = in_array('--seed', $argv ?? [], true);

try {
    echo "✅ " . migLocations($pdo, $apply) . "\n";

    if ($seed && $apply) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM locations")->fetchColumn();
        if ($n > 0) {
            echo "ℹ️  locations มีข้อมูลอยู่แล้ว ($n โหนด) — ข้าม seed (กันซ้ำ)\n";
        } else {
            seedDemoLocations($pdo);
            $total = (int)$pdo->query("SELECT COUNT(*) FROM locations")->fetchColumn();
            $spots = (int)$pdo->query("SELECT COUNT(*) FROM locations WHERE assignable = 1")->fetchColumn();
            echo "🌱 seed ผังตัวอย่างแล้ว — $total โหนด · $spots จุดพักที่เลือกได้\n";
        }
    }

    if (!$apply) echo "\n(dry-run — ยังไม่ได้เขียนอะไรลง DB)\n";
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * ผังตัวอย่างศูนย์พักพิง — 3 โซน · 9 โหนด · 5 จุดพักที่เลือกได้
 *   โซนพักชาย
 *     ├ อาคารชาย ๑  (รวม · display_from)
 *     │   ├ ห้อง ๑๐๑   ← พักได้
 *     │   └ ห้อง ๑๐๒   ← พักได้
 *     └ เต็นท์ชายรวม  ← พักได้
 *   โซนพักหญิง
 *     └ อาคารหญิง ๑   ← พักได้
 *   จุดพักนอกศูนย์
 *     └ ที่พักภายนอก/บ้านญาติ  ← พักได้
 */
function seedDemoLocations(PDO $pdo): void
{
    $ins = $pdo->prepare(
        "INSERT INTO locations (parent_id, name, kind, depth, is_shared, assignable, display_from, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $add = function (?int $parent, string $name, int $kind, int $depth, int $shared, int $assign, int $disp, int $sort) use ($pdo, $ins): int {
        $ins->execute([$parent, $name, $kind, $depth, $shared, $assign, $disp, $sort]);
        return (int)$pdo->lastInsertId();
    };

    // โซนพักชาย
    $z1 = $add(null, 'โซนพักชาย',        0, 0, 0, 0, 0, 1);
    $b1 = $add($z1,  'อาคารชาย ๑',       1, 1, 1, 0, 1, 1);   // อาคารรวม + display_from
    $add($b1,  'ห้อง ๑๐๑',        2, 2, 0, 1, 0, 1);
    $add($b1,  'ห้อง ๑๐๒',        2, 2, 0, 1, 0, 2);
    $add($z1,  'เต็นท์ชายรวม',      1, 1, 0, 1, 0, 2);        // เดี่ยว

    // โซนพักหญิง
    $z2 = $add(null, 'โซนพักหญิง',       0, 0, 0, 0, 0, 2);
    $add($z2,  'อาคารหญิง ๑',       1, 1, 0, 1, 0, 1);

    // จุดพักนอกศูนย์
    $z3 = $add(null, 'จุดพักนอกศูนย์',    3, 0, 0, 0, 0, 3);
    $add($z3,  'ที่พักภายนอก / บ้านญาติ', 1, 1, 0, 1, 0, 1);
}
