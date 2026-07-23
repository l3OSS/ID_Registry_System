<?php
/**
 * core/migrate.php — Engine กลางของ migration + backup
 * ใช้ร่วมทั้ง CLI (scripts/migrate_*.php, backup_db.php) และ web updater (install/process_update.php)
 * เพื่อให้ logic มีที่เดียว (single source of truth)
 *
 * ทุกฟังก์ชัน migration เป็น idempotent — รันซ้ำ/รันบน schema ใหม่แล้วปลอดภัย
 * คืน string สรุปผล (หลายบรรทัดได้) · โยน exception เมื่อพลาดจริง (ให้ผู้เรียกจัดการ)
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';  // generatePublicId
require_once __DIR__ . '/security.php';   // decryptData/encryptData/hashID, GCM_PREFIX

/**
 * P8 — สร้าง trigger append-only บน activity_logs (idempotent: DROP IF EXISTS ก่อน)
 * DB user ต้องมีสิทธิ์ TRIGGER
 */
function migP8Triggers(PDO $pdo): string
{
    $pdo->exec("DROP TRIGGER IF EXISTS trg_activity_logs_no_update");
    $pdo->exec("DROP TRIGGER IF EXISTS trg_activity_logs_no_delete");

    $pdo->exec("
        CREATE TRIGGER trg_activity_logs_no_update
        BEFORE UPDATE ON activity_logs
        FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_logs is append-only (UPDATE blocked)'
    ");
    $pdo->exec("
        CREATE TRIGGER trg_activity_logs_no_delete
        BEFORE DELETE ON activity_logs
        FOR EACH ROW
        BEGIN
            IF COALESCE(@allow_log_purge, 0) <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_logs is append-only (DELETE blocked; engineer purge only)';
            END IF;
        END
    ");
    return "P8: สร้าง trigger append-only บน activity_logs แล้ว (no_update / no_delete)";
}

/**
 * P7 — เพิ่มคอลัมน์ citizens.public_id (char(13) + unique) ถ้ายังไม่มี แล้ว backfill แถวที่ว่าง
 */
function migP7PublicId(PDO $pdo, bool $apply = true): string
{
    $hasCol = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citizens' AND COLUMN_NAME = 'public_id'"
    )->fetchColumn();

    $lines = [];
    if (!$hasCol) {
        if ($apply) {
            $pdo->exec("ALTER TABLE citizens ADD COLUMN public_id CHAR(13) NULL AFTER id");
            $lines[] = "P7: เพิ่มคอลัมน์ public_id แล้ว";
        } else {
            $lines[] = "P7: (dry-run) จะเพิ่มคอลัมน์ public_id";
        }
    } else {
        $lines[] = "P7: มีคอลัมน์ public_id อยู่แล้ว";
    }

    // unique key (เพิ่มถ้ายังไม่มี — เฉพาะเมื่อคอลัมน์มีจริง)
    if (($hasCol || $apply)) {
        $hasIdx = (bool)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citizens' AND INDEX_NAME = 'uniq_public_id'"
        )->fetchColumn();
        if (!$hasIdx && $apply) {
            $pdo->exec("ALTER TABLE citizens ADD UNIQUE KEY uniq_public_id (public_id)");
            $lines[] = "P7: เพิ่ม unique key uniq_public_id แล้ว";
        }
    }

    // backfill
    if ($hasCol || $apply) {
        $need = (int)$pdo->query(
            "SELECT COUNT(*) FROM citizens WHERE public_id IS NULL OR public_id = ''"
        )->fetchColumn();
        if ($apply && $need > 0) {
            $rows = $pdo->query("SELECT id FROM citizens WHERE public_id IS NULL OR public_id = ''")
                        ->fetchAll(PDO::FETCH_COLUMN);
            $upd = $pdo->prepare("UPDATE citizens SET public_id = ? WHERE id = ?");
            foreach ($rows as $cid) {
                $upd->execute([generatePublicId($pdo), (int)$cid]);
            }
            $lines[] = "P7: backfill public_id $need แถว";
        } else {
            $lines[] = "P7: แถวที่ต้อง backfill = $need" . ($apply ? " (ไม่ต้องทำ)" : " (dry-run)");
        }
    }

    return implode("\n", $lines);
}

/**
 * display_key — เพิ่มคอลัมน์ users.display_key (char(32) + unique) ถ้ายังไม่มี
 * ใช้เป็น capability สำหรับจอยินยอม (QR) ให้อุปกรณ์ผู้พักที่ไม่ได้ล็อกอิน poll ได้
 * (หลัง S5 ปิดโหมด broadcast แล้ว อุปกรณ์ที่ไม่มี session/token จะไม่เห็นข้อมูลเลย)
 */
function migDisplayKey(PDO $pdo, bool $apply = true): string
{
    $hasCol = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'display_key'"
    )->fetchColumn();

    if (!$hasCol) {
        if (!$apply) {
            return "display_key: (dry-run) จะเพิ่มคอลัมน์ users.display_key + unique key";
        }
        $pdo->exec("ALTER TABLE users ADD COLUMN display_key CHAR(32) NULL AFTER is_active");
        $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uniq_display_key (display_key)");
        return "display_key: เพิ่มคอลัมน์ users.display_key + unique key แล้ว";
    }
    return "display_key: มีคอลัมน์ users.display_key อยู่แล้ว";
}

/**
 * pdpa_enabled — สวิตช์เปิด/ปิดระบบยินยอมให้บันทึกข้อมูล (PDPA) ในตาราง settings
 * ค่าเริ่มต้น 1 (เปิด) เพื่อให้ระบบเดิมทำงานเหมือนเดิมหลังอัปเดต
 */
function migPdpaToggle(PDO $pdo, bool $apply = true): string
{
    $hasCol = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'pdpa_enabled'"
    )->fetchColumn();

    if (!$hasCol) {
        if (!$apply) {
            return "pdpa_enabled: (dry-run) จะเพิ่มคอลัมน์ settings.pdpa_enabled (default 1)";
        }
        $pdo->exec("ALTER TABLE settings ADD COLUMN pdpa_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER logo_path");
        return "pdpa_enabled: เพิ่มคอลัมน์ settings.pdpa_enabled แล้ว (ค่าเริ่มต้น = เปิด)";
    }
    return "pdpa_enabled: มีคอลัมน์ settings.pdpa_enabled อยู่แล้ว";
}

/**
 * site_url + qr_ip — ที่อยู่เว็บของระบบ (สำหรับสร้าง URL เต็ม เช่น QR หน้ายินยอม)
 * site_url = URL ราก เช่น http://localhost/reg/web · qr_ip = โฮสต์/ไอพีในวง LAN สำหรับให้แท็บเล็ตสแกน
 * idempotent: เพิ่มเฉพาะคอลัมน์ที่ยังไม่มี
 */
function migSiteUrl(PDO $pdo, bool $apply = true): string
{
    $cols = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings'
           AND COLUMN_NAME IN ('site_url', 'qr_ip')"
    )->fetchAll(PDO::FETCH_COLUMN);

    $todo = [];
    if (!in_array('site_url', $cols, true)) {
        $todo[] = "ADD COLUMN site_url VARCHAR(255) DEFAULT NULL AFTER pdpa_enabled";
    }
    if (!in_array('qr_ip', $cols, true)) {
        $todo[] = "ADD COLUMN qr_ip VARCHAR(64) NOT NULL DEFAULT '192.168.1.50' AFTER site_url";
    }
    if (!$todo) return "site_url/qr_ip: มีคอลัมน์ครบแล้ว";
    if (!$apply) return "site_url/qr_ip: (dry-run) จะเพิ่ม " . implode(', ', $todo);

    $pdo->exec("ALTER TABLE settings " . implode(', ', $todo));
    return "site_url/qr_ip: เพิ่มคอลัมน์แล้ว (" . count($todo) . ")";
}

/**
 * ภูมิลำเนา (กล่อง 3 ในหน้าเพิ่มข้อมูล) — เก็บเป็นเลข address_id เหมือนที่อยู่ตามทะเบียนบ้าน
 * home_same_as_reg = 1 (ค่าเริ่มต้น) แปลว่าใช้ที่อยู่ตามทะเบียนบ้าน → home_* เป็น NULL
 * idempotent: เพิ่มเฉพาะคอลัมน์ที่ยังไม่มี · แถวเดิมได้ค่าเริ่มต้น 1 = พฤติกรรมเดิม (ภูมิลำเนา = ที่อยู่ทะเบียนบ้าน)
 */
function migHomeAddress(PDO $pdo, bool $apply = true): string
{
    $cols = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citizens'
           AND COLUMN_NAME IN ('home_same_as_reg', 'home_address_id', 'home_addr_number')"
    )->fetchAll(PDO::FETCH_COLUMN);

    $todo = [];
    if (!in_array('home_same_as_reg', $cols, true)) {
        $todo[] = "ADD COLUMN home_same_as_reg TINYINT(1) NOT NULL DEFAULT 1 AFTER addr_zipcode";
    }
    if (!in_array('home_address_id', $cols, true)) {
        $todo[] = "ADD COLUMN home_address_id INT DEFAULT NULL AFTER home_same_as_reg";
    }
    if (!in_array('home_addr_number', $cols, true)) {
        $todo[] = "ADD COLUMN home_addr_number VARCHAR(50) DEFAULT NULL AFTER home_address_id";
    }

    if (!$todo) return "ภูมิลำเนา: มีคอลัมน์ home_* ครบแล้ว";
    if (!$apply) return "ภูมิลำเนา: (dry-run) จะเพิ่ม " . implode(', ', $todo);

    $pdo->exec("ALTER TABLE citizens " . implode(', ', $todo));
    return "ภูมิลำเนา: เพิ่มคอลัมน์ citizens.home_* แล้ว (" . count($todo) . ")";
}

/**
 * P5+P6 — re-hash id_card_hash (domain-separated) + re-encrypt PII เป็น GCM
 * idempotent + integrity check ด้วย id_card_last4 (กันเขียนทับข้อมูลดีด้วยขยะถ้า key เพี้ยน)
 */
function migP5P6Reencrypt(PDO $pdo, bool $apply = true): string
{
    $rows = $pdo->query(
        "SELECT id, id_card_enc, id_card_hash, id_card_last4, phone_enc FROM citizens"
    )->fetchAll(PDO::FETCH_ASSOC);

    $plan = [];
    $ok = 0; $skip = 0;

    foreach ($rows as $r) {
        $id  = $r['id'];
        $raw = decryptData($r['id_card_enc']);
        if (in_array($raw, ['Error Decrypting', 'Invalid Data', '', null], true)) {
            $skip++; continue;
        }
        if ((string)$r['id_card_last4'] !== '' && substr($raw, -4) !== (string)$r['id_card_last4']) {
            $skip++; continue; // last4 ไม่ตรง — ข้าม
        }

        $newHash = hashID($raw);      // P5
        $newEnc  = encryptData($raw); // P6 GCM

        $newPhone = $r['phone_enc'];
        if (!empty($r['phone_enc'])) {
            $rawPhone = decryptData($r['phone_enc']);
            if (!in_array($rawPhone, ['Error Decrypting', 'Invalid Data', '', null], true)) {
                $newPhone = encryptData($rawPhone);
            }
        }
        $plan[$id] = [$newHash, $newEnc, $newPhone];
        $ok++;
    }

    if (!$apply) {
        return "P5+P6: (dry-run) จะอัปเดต $ok แถว, ข้าม $skip แถว";
    }
    if (empty($plan)) {
        return "P5+P6: ไม่มีอะไรต้องเขียน (ข้าม $skip แถว)";
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE citizens SET id_card_hash=?, id_card_enc=?, phone_enc=? WHERE id=?");
        foreach ($plan as $id => $v) {
            $stmt->execute([$v[0], $v[1], $v[2], $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return "P5+P6: อัปเดต " . count($plan) . " แถว (ข้าม $skip แถว)";
}

/**
 * สำรอง DB ด้วย mysqldump → backups/reg_<timestamp>.sql
 * @param array $cfg  ['host','port','db','user','pass','root']
 * @return array ['ok'=>bool, 'msg'=>string, 'file'=>?string]
 */
function migBackup(array $cfg): array
{
    $root = $cfg['root'] ?? dirname(__DIR__);

    // หา mysqldump (env MYSQLDUMP → WAMP → PATH)
    $dump = getenv('MYSQLDUMP') ?: '';
    if (!$dump || !is_file($dump)) {
        $dump = '';
        foreach (['C:/wamp64/bin/mariadb/*/bin/mysqldump.exe',
                  'C:/wamp64/bin/mysql/*/bin/mysqldump.exe'] as $pat) {
            $hits = glob($pat);
            if ($hits) { sort($hits); $dump = end($hits); break; }
        }
        if (!$dump) { $dump = 'mysqldump'; }
    }

    $backupDir = $root . '/backups';
    if (!is_dir($backupDir)) { mkdir($backupDir, 0755, true); }
    $outFile = $backupDir . '/reg_' . date('Ymd_His') . '.sql';

    $cmd = escapeshellarg($dump)
         . ' --host=' . escapeshellarg((string)$cfg['host'])
         . ' --port=' . escapeshellarg((string)$cfg['port'])
         . ' --user=' . escapeshellarg((string)$cfg['user'])
         . ' --single-transaction --routines --events --default-character-set=utf8mb4 '
         . escapeshellarg((string)$cfg['db']);

    $env = [
        'MYSQL_PWD'  => (string)($cfg['pass'] ?? ''),
        'PATH'       => getenv('PATH') ?: '',
        'SystemRoot' => getenv('SystemRoot') ?: (getenv('SYSTEMROOT') ?: 'C:\\WINDOWS'),
        'TEMP'       => getenv('TEMP') ?: '',
        'TMP'        => getenv('TMP') ?: '',
    ];
    $descriptor = [1 => ['file', $outFile, 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptor, $pipes, $root, $env);
    if (!is_resource($proc)) {
        return ['ok' => false, 'msg' => "เรียก mysqldump ไม่สำเร็จ ($dump)", 'file' => null];
    }
    $err  = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code !== 0) {
        @unlink($outFile);
        return ['ok' => false, 'msg' => "mysqldump ล้มเหลว (exit $code): $err", 'file' => null];
    }
    $size = is_file($outFile) ? filesize($outFile) : 0;
    if ($size < 100) {
        return ['ok' => false, 'msg' => "ไฟล์ผลลัพธ์เล็กผิดปกติ ($size bytes)", 'file' => $outFile];
    }
    return ['ok' => true, 'msg' => sprintf('backup สำเร็จ: %s (%.1f KB)', basename($outFile), $size / 1024), 'file' => $outFile];
}
