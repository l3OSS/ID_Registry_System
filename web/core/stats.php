<?php
/**
 * core/stats.php — Summary counter สำหรับหน้าแดชบอร์ด (ทำให้ O(1) ทุกขนาดข้อมูล)
 *
 * ปัญหา: "จำนวนผู้พัก active" และ "จำนวน active ต่อกลุ่มเปราะบาง" นับสดเป็น O(n) —
 * บนข้อมูล 15M ที่ทุกคน active การนับกลุ่มผู้สูงอายุ 14M แถวใช้เวลา ~27 วินาที (join map → citizens ทีละแถว).
 * ไม่มี index ใดทำให้ "นับ 14M แถว" เร็วได้ — ต้อง maintain ตัวนับไว้ล่วงหน้า.
 *
 * สถาปัตย์: ตาราง key/value `stat_counters` (ckey PK, cval BIGINT)
 *   - active_total          = จำนวน citizens ที่ is_active = 1
 *   - vuln:<v_id>           = จำนวน citizens ที่ is_active = 1 และติดกลุ่มเปราะบาง v_id
 *
 * รักษาค่าที่ write path (แนวเดียวกับ denorm is_active/last_stay_at ที่มีอยู่แล้ว — ไม่ใช้ trigger
 * เพราะ seed ใช้ LOAD DATA จะยิง trigger 14M ครั้ง). ทุกจุดที่ is_active หรือชุดแท็กของ "คนหนึ่ง"
 * เปลี่ยน ต้องคร่อมด้วย statCounterRemove() ก่อนแก้ + statCounterAdd() หลังแก้.
 *
 * Invariant: counters = Σ ของ citizens ที่ active อยู่ ณ ปัจจุบันเสมอ.
 * self-healing: statRebuildAll() คำนวณใหม่ทั้งหมดจากของจริง (ใช้ตอน migrate/seed/reconcile).
 *
 * ⚠️ best-effort: ตัวนับพลาด "ต้องไม่" ทำให้บันทึกผู้พัก/เช็คเอาต์ล้ม — จับ exception ภายใน
 * แล้ว error_log ไว้ (ค่าที่เพี้ยนกู้ได้ด้วย statRebuildAll; แต่ข้อมูลผู้พักที่หายกู้ไม่ได้).
 */
declare(strict_types=1);

/**
 * ปรับตัวนับตาม "ผลรวมที่คนคนนี้มีส่วนร่วม" ณ สถานะปัจจุบันใน DB
 *   $sign = +1 → บวกส่วนร่วมเข้าไป (เรียก "หลัง" แก้สถานะ/แท็กเสร็จ)
 *   $sign = -1 → ลบส่วนร่วมออก      (เรียก "ก่อน" แก้สถานะ/แท็ก)
 * อ่าน is_active + แท็กจาก DB จริง (ไม่พึ่ง $post_data) → สะท้อนสิ่งที่เขียนลงจริง.
 * คนที่ is_active != 1 ไม่นับอะไรเลย (ทั้ง active_total และ vuln) → ออกทันที.
 */
function statApplyCitizen(PDO $pdo, int $citizenId, int $sign): void
{
    if ($citizenId <= 0 || ($sign !== 1 && $sign !== -1)) {
        return;
    }
    try {
        $st = $pdo->prepare("SELECT is_active FROM citizens WHERE id = ?");
        $st->execute([$citizenId]);
        $active = (int)($st->fetchColumn() ?: 0);
        if ($active !== 1) {
            return; // inactive = ไม่มีส่วนร่วมในตัวนับใด ๆ
        }

        // active_total
        $pdo->prepare(
            "INSERT INTO stat_counters (ckey, cval) VALUES ('active_total', ?)
             ON DUPLICATE KEY UPDATE cval = cval + VALUES(cval)"
        )->execute([$sign]);

        // vuln:<v_id> — หนึ่งแถวต่อกลุ่มเปราะบางที่คนนี้ติด (สร้างแถวเองถ้ายังไม่มี)
        $pdo->prepare(
            "INSERT INTO stat_counters (ckey, cval)
             SELECT CONCAT('vuln:', m.v_id), ?
             FROM citizen_vulnerable_map m
             WHERE m.citizen_id = ?
             ON DUPLICATE KEY UPDATE cval = cval + VALUES(cval)"
        )->execute([$sign, $citizenId]);
    } catch (\Throwable $e) {
        // best-effort: อย่าให้ตัวนับพังการบันทึกจริง
        error_log('statApplyCitizen(' . $citizenId . ',' . $sign . ') failed: ' . $e->getMessage());
    }
}

/** เรียก "หลัง" สถานะ/แท็กของ citizen ถูกเขียนลง DB เรียบร้อย */
function statCounterAdd(PDO $pdo, int $citizenId): void
{
    statApplyCitizen($pdo, $citizenId, 1);
}

/** เรียก "ก่อน" จะแก้สถานะ/แท็ก (ต้องอ่านสถานะเก่าได้ครบ — row + map ยังอยู่) */
function statCounterRemove(PDO $pdo, int $citizenId): void
{
    statApplyCitizen($pdo, $citizenId, -1);
}

/**
 * สร้างตาราง stat_counters ถ้ายังไม่มี (idempotent) — ใช้ใน migration + ก่อน rebuild
 */
function statEnsureTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stat_counters (
            ckey VARCHAR(64) NOT NULL PRIMARY KEY,
            cval BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * คำนวณตัวนับใหม่ทั้งหมดจากของจริง (source of truth = citizens.is_active + citizen_vulnerable_map)
 * ใช้ตอน migrate ครั้งแรก / หลัง seed / reconcile เมื่อสงสัยว่าเพี้ยน.
 * นี่คือ query หนักตัวเดียว (บน 15M worst-case ~เศษวินาทีถึงสิบวินาที) — one-time เท่านั้น.
 */
function statRebuildAll(PDO $pdo): void
{
    statEnsureTable($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM stat_counters");
        $pdo->exec(
            "INSERT INTO stat_counters (ckey, cval)
             VALUES ('active_total', (SELECT COUNT(*) FROM citizens WHERE is_active = 1))"
        );
        $pdo->exec(
            "INSERT INTO stat_counters (ckey, cval)
             SELECT CONCAT('vuln:', m.v_id), COUNT(*)
             FROM citizen_vulnerable_map m
             JOIN citizens c ON c.id = m.citizen_id AND c.is_active = 1
             GROUP BY m.v_id"
        );
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** อ่านจำนวนผู้พัก active (O(1)) */
function statActiveTotal(PDO $pdo): int
{
    try {
        $st = $pdo->query("SELECT cval FROM stat_counters WHERE ckey = 'active_total'");
        return (int)($st->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        return 0;
    }
}
