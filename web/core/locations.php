<?php
/**
 * core/locations.php — ผังสถานที่พักแบบลำดับชั้น (ตาราง locations)
 * (ยกมาจากโปรเจค Sec — โครงเดียวกัน ปรับป้ายชนิดให้ตรงบริบทศูนย์พักพิง)
 *
 * helper กลาง ใช้ร่วมทั้ง 3 ฝั่ง:
 *   - หน้าจัดการ  (pages/location_manage.php)
 *   - ฟอร์มลงทะเบียน (pages/guest_form.php → ตัวเลือกสวิตช์ · pages/guest_check.php → บันทึก location_id)
 *   - หน้าประวัติ/แดชบอร์ด (guest_history / dashboard → ป้ายแสดงผลตาม display_from)
 *
 * ต้นไม้อ้างตัวเอง (parent_id) ลึกได้ถึง 5 ระดับ · flags:
 *   is_shared    = อาคาร/เรือนรวม (มีห้องย่อย)
 *   assignable   = เลือกเป็น "จุดพัก" ได้จริง (โซนล้วนตั้ง 0)
 *   display_from = เริ่มแสดง path จากโหนดนี้ลงไป (อาคารรวมตั้ง 1 → โชว์ "อาคาร › ห้อง")
 */

require_once __DIR__ . '/functions.php';

const LOC_MAX_DEPTH = 5;   // 5 ระดับ (depth 0..4)

/** ป้ายชนิด (kind) — ใช้เป็นป้ายกำกับเท่านั้น ไม่บังคับความลึก */
const LOC_KINDS = [
    0 => 'โซน / พื้นที่',
    1 => 'อาคาร / เรือนพัก',
    2 => 'ห้อง / เตียง',
    3 => 'พิเศษ / อื่น ๆ',
];

/**
 * โหลดทั้งตารางเป็น map id => row (ตารางเล็ก — โหลดครั้งเดียวต่อคำขอ แล้วส่งต่อ helper อื่น)
 * ค่าเป็น int ทั้งหมดเพื่อเทียบ === ได้ตรง
 */
function locationMap(PDO $pdo, bool $activeOnly = false): array
{
    $where = $activeOnly ? "WHERE active = 1" : "";
    $rows = $pdo->query(
        "SELECT id, parent_id, name, kind, depth, is_shared, assignable, display_from, sort_order, active
         FROM locations $where ORDER BY sort_order, id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
        $r['id']        = (int)$r['id'];
        $r['parent_id'] = $r['parent_id'] !== null ? (int)$r['parent_id'] : null;
        foreach (['kind', 'depth', 'is_shared', 'assignable', 'display_from', 'sort_order', 'active'] as $k) {
            $r[$k] = (int)$r[$k];
        }
        $map[$r['id']] = $r;
    }
    return $map;
}

/** ลูกของโหนด $parentId (null = ระดับบนสุด) เรียงตาม sort_order แล้ว id */
function locationChildren(array $map, ?int $parentId): array
{
    $out = [];
    foreach ($map as $r) {
        if ($r['parent_id'] === $parentId) $out[] = $r;
    }
    usort($out, fn($a, $b) => [$a['sort_order'], $a['id']] <=> [$b['sort_order'], $b['id']]);
    return $out;
}

/** สร้างต้นไม้ซ้อนชั้น (สำหรับ json_encode → ตัวเลือกสวิตช์ในฟอร์ม) */
function locationTreeNested(array $map, ?int $parentId = null): array
{
    $out = [];
    foreach (locationChildren($map, $parentId) as $r) {
        $out[] = [
            'id'           => $r['id'],
            'name'         => $r['name'],
            'kind'         => $r['kind'],
            'is_shared'    => $r['is_shared'],
            'assignable'   => $r['assignable'],
            'display_from' => $r['display_from'],
            'children'     => locationTreeNested($map, $r['id']),
        ];
    }
    return $out;
}

/** path จาก root → $id (array ของ row บนลงล่าง) · กันลูปด้วย $seen */
function locationPath(array $map, int $id): array
{
    $chain = [];
    $seen  = [];
    $cur   = $id;
    while ($cur !== null && isset($map[$cur]) && !isset($seen[$cur])) {
        $seen[$cur] = true;
        array_unshift($chain, $map[$cur]);
        $cur = $map[$cur]['parent_id'];
    }
    return $chain;
}

/**
 * ป้ายแสดงผลของจุดพัก — เคารพ display_from
 * ปกติแสดงเฉพาะระดับสุดท้าย · ถ้าในสายบนมีโหนด display_from จะเริ่มแสดงตั้งแต่โหนดนั้นลงไป
 * (อาคารรวมตั้ง display_from=1 → "อาคารชาย ๑ › ห้อง ๑๐๑" แทน "ห้อง ๑๐๑" ลอย ๆ)
 */
function locationDisplayLabel(array $map, ?int $id): string
{
    if (!$id || !isset($map[$id])) return '';
    $chain = locationPath($map, $id);
    $n = count($chain);
    $start = $n - 1;                       // ค่าเริ่มต้น: แสดงเฉพาะ leaf
    for ($i = 0; $i < $n; $i++) {          // ใช้โหนด display_from ที่ตื้นที่สุด (ได้บริบทมากสุด)
        if ($chain[$i]['display_from']) { $start = $i; break; }
    }
    $names = array_map(fn($r) => $r['name'], array_slice($chain, $start));
    return implode(' › ', $names);
}

/** โหนดนี้เลือกเป็นจุดพักได้จริงไหม (มีอยู่ + assignable + active) — ใช้ตรวจฝั่งเซิร์ฟเวอร์ ห้ามเชื่อ client */
function locationIsAssignable(array $map, int $id): bool
{
    return isset($map[$id]) && $map[$id]['assignable'] === 1 && $map[$id]['active'] === 1;
}
