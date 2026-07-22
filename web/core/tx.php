<?php
/**
 * Transaction helper (P4 — เทียบ TransactionWrapper ของ papyrus)
 * ห่อ begin/commit/rollBack ไว้ที่เดียว + retry อัตโนมัติเมื่อเจอ deadlock/lock-wait
 */

/**
 * รัน $fn ภายใน transaction เดียว
 *  - สำเร็จ → commit แล้วคืนค่าที่ $fn return
 *  - error → rollBack แล้ว rethrow (deadlock 1213 / lock wait 1205 จะ retry ให้)
 *
 * @param PDO      $pdo
 * @param callable $fn         รับ PDO เป็นอาร์กิวเมนต์ (function(PDO $pdo) { ... })
 * @param int      $maxRetries จำนวนครั้งสูงสุดเมื่อเจอ deadlock
 * @return mixed                ค่าที่ $fn คืน
 */
function runInTransaction(PDO $pdo, callable $fn, int $maxRetries = 3) {
    $attempt = 0;
    while (true) {
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $sqlCode = ($e instanceof PDOException) ? (int)($e->errorInfo[1] ?? 0) : 0;
            // 1213 = deadlock, 1205 = lock wait timeout → ลองใหม่แบบ backoff
            if (in_array($sqlCode, [1213, 1205], true) && ++$attempt < $maxRetries) {
                usleep(100000 * $attempt); // 0.1s, 0.2s, ...
                continue;
            }
            throw $e;
        }
    }
}
