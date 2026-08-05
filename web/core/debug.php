<?php
/**
 * core/debug.php — โปรไฟเลอร์วัดเวลาประมวลผลแยกส่วน (เปิด/ปิดได้จุดเดียว)
 *
 * ┌───────────────────────────────────────────────────────────────────┐
 * │  จุดเปิด/ปิดดีบั๊ก "ที่เดียว":                                        │
 * │    • ตั้ง APP_DEBUG=1 ใน .env  → เปิด                                │
 * │    • ตั้ง APP_DEBUG=0 (หรือไม่ตั้ง) → ปิด                            │
 * │  (ไม่ต้องแก้โค้ดเพื่อสลับ — แค่แก้ค่าใน .env ค่าเดียว)                │
 * └───────────────────────────────────────────────────────────────────┘
 *
 * วิธีใช้ในโค้ด:
 *   dbg_boot();                         // เริ่มจับเวลารวม (เรียกครั้งเดียวใน index.php)
 *   dbg_start('label'); ... dbg_stop('label');   // จับช่วงแบบ manual (สะสมถ้าเรียกซ้ำ)
 *   $rows = dbg_measure('query', fn() => $pdo->query($sql)->fetchAll());  // จับ closure บรรทัดเดียว
 *   dbg_render();                       // พ่นแผงสรุป (footer เรียกให้แล้ว — แสดงเฉพาะเมื่อเปิด)
 *
 * เมื่อปิด (DEBUG_TIMING=false) ทุกฟังก์ชันเป็น no-op ต้นทุนแทบเป็นศูนย์ — วางทิ้งไว้ใน production ได้
 */

// จุดตั้งค่าเปิด/ปิดที่เดียว (ขับด้วย .env → APP_DEBUG)
if (!defined('DEBUG_TIMING')) {
    define('DEBUG_TIMING', (($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: '0') === '1'));
}

/** สถานะภายใน (คืนค่าแบบ reference เพื่อให้ทุกฟังก์ชันเขียนลง state เดียวกัน) */
function &_dbg_state(): array {
    static $s = ['start' => null, 'marks' => [], 'open' => []];
    return $s;
}

/** เริ่มจับเวลารวมของทั้ง request (idempotent) */
function dbg_boot(): void {
    $s = &_dbg_state();
    if ($s['start'] === null) $s['start'] = microtime(true);
}

/** เริ่มจับช่วงชื่อ $label */
function dbg_start(string $label): void {
    if (!DEBUG_TIMING) return;
    $s = &_dbg_state();
    $s['open'][$label] = microtime(true);
}

/** ปิดช่วงชื่อ $label แล้วสะสมเวลา + นับจำนวนครั้ง */
function dbg_stop(string $label): void {
    if (!DEBUG_TIMING) return;
    $s = &_dbg_state();
    if (!isset($s['open'][$label])) return;
    $dt = (microtime(true) - $s['open'][$label]) * 1000; // ms
    $s['marks'][$label]['t'] = ($s['marks'][$label]['t'] ?? 0) + $dt;
    $s['marks'][$label]['n'] = ($s['marks'][$label]['n'] ?? 0) + 1;
    unset($s['open'][$label]);
}

/** จับเวลา closure ในบรรทัดเดียว แล้วคืนค่าที่ closure คืน */
function dbg_measure(string $label, callable $fn) {
    if (!DEBUG_TIMING) return $fn();
    dbg_start($label);
    try { return $fn(); }
    finally { dbg_stop($label); }
}

/** เวลารวมตั้งแต่ dbg_boot() ถึงตอนนี้ (ms) */
function dbg_total(): float {
    $s = &_dbg_state();
    return $s['start'] === null ? 0.0 : (microtime(true) - $s['start']) * 1000;
}

/** พ่นแผงสรุปเวลา (แสดงเฉพาะเมื่อ DEBUG_TIMING เปิด) */
function dbg_render(): void {
    if (!DEBUG_TIMING) return;
    $s = &_dbg_state();
    $total = dbg_total();
    $mem   = memory_get_peak_usage(true) / 1048576;

    // เรียงช่วงที่ใช้เวลามากสุดขึ้นก่อน
    $marks = $s['marks'];
    uasort($marks, fn($a, $b) => $b['t'] <=> $a['t']);

    echo '<div id="dbgPanel" style="position:fixed;bottom:0;right:0;z-index:99999;max-width:420px;'
       . 'font:12px/1.5 monospace;background:#111;color:#0f0;border-top-left-radius:8px;'
       . 'box-shadow:0 0 12px rgba(0,0,0,.5);opacity:.94">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;'
       . 'background:#000;padding:4px 8px;color:#fff;cursor:pointer" '
       . 'onclick="var b=document.getElementById(\'dbgBody\');b.style.display=b.style.display===\'none\'?\'block\':\'none\'">';
    echo '<span>🐞 DEBUG · รวม <b style="color:#ff0">' . number_format($total, 1) . ' ms</b> · '
       . number_format($mem, 1) . ' MB</span><span style="color:#888">▲▼</span></div>';
    echo '<div id="dbgBody" style="padding:6px 8px;max-height:50vh;overflow:auto">';

    if (!$marks) {
        echo '<div style="color:#888">ไม่มีช่วงที่วัดไว้ (ยังไม่มี dbg_start/dbg_measure ในหน้านี้)</div>';
    } else {
        echo '<table style="border-collapse:collapse;width:100%">';
        echo '<tr style="color:#0ff"><td>ช่วง</td><td style="text-align:right">ครั้ง</td>'
           . '<td style="text-align:right">รวม ms</td><td style="text-align:right">%</td></tr>';
        foreach ($marks as $label => $m) {
            $pct = $total > 0 ? ($m['t'] / $total * 100) : 0;
            $bar = $pct >= 40 ? '#f33' : ($pct >= 15 ? '#fa0' : '#0f0');
            printf('<tr><td>%s</td><td style="text-align:right;color:#aaa">%s</td>'
                 . '<td style="text-align:right;color:%s">%s</td>'
                 . '<td style="text-align:right;color:%s">%.0f%%</td></tr>',
                htmlspecialchars($label), number_format($m['n']),
                $bar, number_format($m['t'], 1), $bar, $pct);
        }
        echo '</table>';
    }
    echo '</div></div>';
}
