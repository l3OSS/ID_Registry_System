<?php
/**
 * Page: System Settings (Custom Fields)
 * ปรับปรุง: ปิดตัวเลือกค้นหาสำหรับฟิลด์ประเภท Text เพื่อป้องกันบั๊กหน้าลิสรายชื่อ
 */

require_once 'core/auth.php';
require_once 'core/log.php';

// --- 1. Access & Security Check ---
$can_access = userCan('settings.manage');

if (!$can_access) denyAccess(t('set.err_no_access'));

// --- 1. Action: Add New Field ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_field'])) {
    $name   = trim($_POST['field_name']);
    $type   = $_POST['field_type'];
    // ถ้าเป็น text ให้บังคับเป็น 0 เสมอเพื่อความปลอดภัย
    $search = ($type === 'text') ? 0 : (isset($_POST['is_searchable']) ? 1 : 0);
    
    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO custom_field_master (field_name, field_type, is_searchable, is_active) VALUES (?, ?, ?, 1)");
            $stmt->execute([$name, $type, $search]);
            writeLog($pdo, 'ADD_CUSTOM_FIELD', "เพิ่มฟิลด์ใหม่: $name ($type) [Searchable: $search]");
            $_SESSION['success_msg'] = t('set.add_field_success');
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = t('set.add_field_error');
        }
    }
}

// --- 2. Action: Update Existing Field ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_field'])) {
    $field_id = filter_input(INPUT_POST, 'field_id', FILTER_VALIDATE_INT);
    $name     = trim($_POST['field_name']);
    $active   = isset($_POST['is_active']) ? 1 : 0;
    
    // ดึงประเภทดั้งเดิมมาเช็คก่อนบันทึก
    $stmt_check = $pdo->prepare("SELECT field_type FROM custom_field_master WHERE id = ?");
    $stmt_check->execute([$field_id]);
    $f_type = $stmt_check->fetchColumn();

    $search   = ($f_type === 'text') ? 0 : (isset($_POST['is_searchable']) ? 1 : 0);

    if ($field_id && !empty($name)) {
        $stmt = $pdo->prepare("UPDATE custom_field_master SET field_name = ?, is_active = ?, is_searchable = ? WHERE id = ?");
        $stmt->execute([$name, $active, $search, $field_id]);
        $_SESSION['success_msg'] = t('set.update_success');
    }
}

// --- helper: ลบไฟล์โลโก้เก่า (เฉพาะที่อยู่ใต้ uploads/ · guard path traversal) — B5 ---
function deleteOldLogo(?string $path): void {
    if (!$path) return;
    $path = ltrim($path, '/');
    if (strncmp($path, 'uploads/', 8) !== 0 || strpos($path, '..') !== false) return;
    $full = __DIR__ . '/../' . $path;
    if (is_file($full)) @unlink($full);
}

/**
 * รีเซทระบบกลับสู่ "วินาทีแรกหลังติดตั้ง" (EngiNear เท่านั้น — ตรวจสิทธิ์/รหัสผ่านที่ผู้เรียก) — ยกจาก Sec
 *
 * ล้างข้อมูลผู้พัก/ทีมงาน/log ทั้งหมด + ตั้งลำดับ (AUTO_INCREMENT) กลับที่ 1 + จับเวลาติดตั้งใหม่
 *   คงไว้: บัญชี EngiNear ที่สั่งรีเซท ($keepUserId, รหัสผ่านเดิม), กุญแจเข้ารหัส (.env ไม่แตะ),
 *          ข้อมูลอ้างอิงที่ติดตั้งมา (roles/address_lookup) และ vulnerable_master 2 แถว default (เด็ก/ผู้สูงอายุ)
 *
 * การลบอยู่ในทรานแซกชัน (ล้ม→rollback = ไม่มีอะไรเปลี่ยน) · ALTER AUTO_INCREMENT เป็น DDL (implicit commit)
 * จึงทำหลัง commit · ไฟล์ในดิสก์ลบท้ายสุดหลัง DB สำเร็จ
 */
function performSystemReset(PDO $pdo, int $keepUserId): void
{
    // 1) เก็บ path ไฟล์ที่ต้องลบ (โลโก้ + รูปผู้พัก) ก่อนล้าง DB
    $files = [];
    $logo = (string)($pdo->query("SELECT logo_path FROM settings WHERE id = 1")->fetchColumn() ?: '');
    if ($logo !== '') $files[] = $logo;
    foreach ($pdo->query("SELECT photo_path FROM citizens WHERE photo_path IS NOT NULL AND photo_path <> ''")
                 ->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $files[] = (string)$p;
    }

    // 2) ล้างข้อมูล (ลูก→แม่) ในทรานแซกชัน + ปลด trigger append-only ของ activity_logs ชั่วคราว
    $pdo->beginTransaction();
    try {
        $pdo->exec("SET @allow_log_purge = 1");

        // เรียงลูกก่อนแม่ กัน FK — temp_sync_consent อ้าง admin (users) จึงลบก่อน users
        foreach ([
            'citizen_custom_values',
            'citizen_vulnerable_map',
            'stay_history',
            'temp_sync_consent',
            'citizens',
            'custom_field_master',
            'activity_logs',
        ] as $t) {
            $pdo->exec("DELETE FROM `$t`");
        }

        // ลบทีมงานอื่นทั้งหมด เหลือเฉพาะ EngiNear ที่กำลังรีเซท (คงรหัสผ่าน/โปรไฟล์เดิม)
        $pdo->prepare("DELETE FROM users WHERE id <> ?")->execute([$keepUserId]);

        // คืน vulnerable_master กลับค่า seed หลังติดตั้ง (auto-check อายุใช้ id 1,2)
        $pdo->exec("DELETE FROM citizen_vulnerable_map"); // เผื่อยังมี (ลบไปแล้วข้างบน — กันเหนียว)
        $pdo->exec("DELETE FROM vulnerable_master");
        $pdo->exec("INSERT INTO vulnerable_master (id, v_name, v_color) VALUES (1, '0-5 ขวบ', 'info'), (2, 'ผู้สูงอายุ', 'warning')");

        // รีเซทค่าหัวเว็บกลับค่าเริ่มต้น + จับเวลาติดตั้งใหม่ (คง id = 1)
        $install_log = json_encode([
            'installed_at'   => date('Y-m-d H:i:s'),
            'terms_accepted' => true,
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'reset'          => true,
        ], JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            "UPDATE settings SET app_name = 'Reg System', site_subtitle = NULL, logo_path = NULL, pdpa_enabled = 1, site_url = NULL, qr_ip = '192.168.1.50', install_log = ? WHERE id = 1"
        )->execute([$install_log]);

        $pdo->exec("SET @allow_log_purge = 0");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // 3) ตั้งลำดับ (AUTO_INCREMENT) กลับที่ 1 — DDL (implicit commit) จึงทำนอกทรานแซกชัน
    foreach ([
        'citizens', 'citizen_custom_values', 'citizen_vulnerable_map',
        'stay_history', 'temp_sync_consent', 'custom_field_master', 'activity_logs',
    ] as $t) {
        try {
            $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1");
        } catch (Throwable $e) {
            error_log("Reset AUTO_INCREMENT ($t) failed: " . $e->getMessage());
        }
    }
    // vulnerable_master มี id 1,2 อยู่ → ลำดับถัดไป = 3
    try { $pdo->exec("ALTER TABLE `vulnerable_master` AUTO_INCREMENT = 3"); } catch (Throwable $e) {}

    // 4) ลบไฟล์อัปโหลด (โลโก้/รูปผู้พัก) — deleteOldLogo() guard เฉพาะใต้ uploads/ + กัน path traversal
    foreach ($files as $f) deleteOldLogo($f);
    // ลบรูป temp ของการยินยอม PDPA ที่ค้าง (uploads/temp/*)
    foreach (glob(__DIR__ . '/../uploads/temp/*') ?: [] as $tmp) {
        if (is_file($tmp)) @unlink($tmp);
    }

    // 5) จับเวลาติดตั้งใหม่ที่ install.lock (ถ้าโฟลเดอร์ install/ ยังอยู่)
    $lock = __DIR__ . '/../install/install.lock';
    if (is_file($lock)) @file_put_contents($lock, date('Y-m-d H:i:s'));
}

// --- Action: รีเซทระบบกลับสู่สถานะหลังติดตั้ง (EngiNear + ยืนยันรหัสผ่าน + พิมพ์คำ RESET) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_system'])) {
    if (!isEngineer()) {
        denyAccess(t('reset.err_perm'));
    }

    $confirm_word = trim($_POST['reset_confirm_word'] ?? '');
    $cur_pass     = (string)($_POST['reset_password'] ?? '');
    $uid          = (int)($_SESSION['user_id'] ?? 0);

    // ดึง hash รหัสผ่านปัจจุบันของ EngiNear ที่กำลังทำรายการ
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND role_level = 1");
    $stmt->execute([$uid]);
    $hash = (string)($stmt->fetchColumn() ?: '');

    if ($confirm_word !== 'RESET') {
        $_SESSION['error_msg'] = t('reset.err_word');
    } elseif ($hash === '' || !password_verify($cur_pass, $hash)) {
        $_SESSION['error_msg'] = t('reset.err_pass');
    } else {
        try {
            performSystemReset($pdo, $uid);
            // เขียน log แรกหลังรีเซท (activity_logs ถูกล้าง+รีเซทลำดับแล้ว → แถวนี้ id = 1)
            writeLog($pdo, 'SYSTEM_RESET', 'EngiNear รีเซทระบบกลับสู่สถานะหลังติดตั้ง (ล้างข้อมูลทั้งหมด)');
            $_SESSION['success_msg'] = t('reset.done');
        } catch (Throwable $e) {
            error_log('System reset error: ' . $e->getMessage());
            $_SESSION['error_msg'] = t('reset.err_fail');
        }
    }
    redirect('index.php?page=setting');
}

// --- 3. Action: ตั้งค่าหัวเว็บ (ชื่อ/สร้อย/โลโก้) — B8 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_general'])) {
    $app_name = trim($_POST['app_name'] ?? '');
    $subtitle = trim($_POST['site_subtitle'] ?? '');
    $row = $pdo->query("SELECT id, logo_path FROM settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $sid       = (int)($row['id'] ?? 1);
    $logo_path = $row['logo_path'] ?? null;

    if (isset($_POST['reset_logo'])) { deleteOldLogo($logo_path); $logo_path = null; }

    if (!empty($_FILES['logo']['name']) && ($_FILES['logo']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'], true) && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
            @mkdir(__DIR__ . '/../uploads', 0755, true);
            $new = 'uploads/logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__ . '/../' . $new)) {
                deleteOldLogo($logo_path); // ลบเก่าเมื่อเปลี่ยนสำเร็จ
                $logo_path = $new;
            }
        } else {
            $_SESSION['error_msg'] = t('set.err_logo');
        }
    }

    if ($app_name === '') {
        $_SESSION['error_msg'] = t('set.err_app_name');
    } else {
        $pdo->prepare("UPDATE settings SET app_name = ?, site_subtitle = ?, logo_path = ? WHERE id = ?")
            ->execute([$app_name, $subtitle, $logo_path, $sid]);
        writeLog($pdo, 'UPDATE_SETTINGS', "อัปเดตหัวเว็บ: $app_name");
        if (empty($_SESSION['error_msg'])) $_SESSION['success_msg'] = t('set.header_saved');
    }
    redirect('index.php?page=setting');
}

// --- 3b. Action: ตั้งค่าที่อยู่เว็บ (site_url) + IP สำหรับ QR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_url'])) {
    $site_url = rtrim(trim($_POST['site_url'] ?? ''), '/');   // ตัด / ท้ายกันซ้อน
    $qr_ip    = trim($_POST['qr_ip'] ?? '');
    if ($qr_ip === '') $qr_ip = '192.168.1.50';
    $sid = (int)($pdo->query("SELECT id FROM settings ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);

    $pdo->prepare("UPDATE settings SET site_url = ?, qr_ip = ? WHERE id = ?")
        ->execute([$site_url !== '' ? $site_url : null, $qr_ip, $sid]);
    writeLog($pdo, 'UPDATE_SETTINGS', "อัปเดตที่อยู่เว็บ: " . ($site_url ?: '(auto)') . " · QR IP: $qr_ip");
    $_SESSION['success_msg'] = t('set.url_saved');
    redirect('index.php?page=setting');
}

// --- 4. Action: เปิด/ปิดระบบยินยอมให้บันทึกข้อมูล (PDPA) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pdpa'])) {
    $enabled = isset($_POST['pdpa_enabled']) ? 1 : 0;
    $sid = (int)($pdo->query("SELECT id FROM settings ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);

    $pdo->prepare("UPDATE settings SET pdpa_enabled = ? WHERE id = ?")->execute([$enabled, $sid]);
    writeLog($pdo, 'UPDATE_SETTINGS', 'ตั้งค่าระบบยินยอม PDPA: ' . ($enabled ? 'เปิด' : 'ปิด'));
    $_SESSION['success_msg'] = $enabled ? t('set.pdpa_saved_on') : t('set.pdpa_saved_off');
    redirect('index.php?page=setting');
}

$fields = $pdo->query("SELECT * FROM custom_field_master ORDER BY id ASC")->fetchAll();
?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-dark"><i class="bi bi-gear-fill text-primary"></i> <?php echo e('set.title'); ?></h3>
    </div>

    <?php if (!empty($_SESSION['success_msg'])): ?>
        <div class="alert alert-success border-0 shadow-sm"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_msg'])): ?>
        <div class="alert alert-danger border-0 shadow-sm"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div>
    <?php endif; ?>

    <?php $s = appSettings($pdo); ?>
    <div class="card shadow-sm mb-4 border-0 rounded-3">
        <div class="card-header bg-primary text-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-window-sidebar"></i> <?php echo e('set.header_card'); ?></h6>
        </div>
        <div class="card-body p-4">
            <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-5">
                    <label class="form-label small fw-bold"><?php echo e('set.app_name_label'); ?></label>
                    <input type="text" name="app_name" class="form-control" value="<?= htmlspecialchars($s['app_name']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold"><?php echo e('set.subtitle_label'); ?></label>
                    <input type="text" name="site_subtitle" class="form-control" value="<?= htmlspecialchars($s['site_subtitle']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold"><?php echo e('set.logo_label'); ?></label>
                    <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg" class="form-control">
                </div>
                <div class="col-12 d-flex align-items-center gap-3 flex-wrap">
                    <?php if (!empty($s['logo_path']) && file_exists($s['logo_path'])): ?>
                        <img src="<?= htmlspecialchars($s['logo_path']) ?>" style="height:40px;" class="border rounded p-1 bg-white">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="reset_logo" id="resetLogo">
                            <label class="form-check-label small" for="resetLogo"><?php echo e('set.remove_logo'); ?></label>
                        </div>
                    <?php else: ?>
                        <span class="text-body-secondary small"><?php echo e('set.no_logo'); ?></span>
                    <?php endif; ?>
                    <button type="submit" name="save_general" class="btn btn-success ms-auto shadow-sm"><i class="bi bi-save"></i> <?php echo e('set.save_header'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4 border-0 rounded-3">
        <div class="card-header bg-primary text-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-globe"></i> <?php echo e('set.url_card'); ?></h6>
        </div>
        <div class="card-body p-4">
            <form method="POST" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-8">
                    <label class="form-label small fw-bold"><?php echo e('set.site_url_label'); ?></label>
                    <input type="text" name="site_url" class="form-control" dir="ltr"
                           value="<?= htmlspecialchars($s['site_url']) ?>"
                           placeholder="<?= htmlspecialchars(detectSiteUrl()) ?>">
                    <div class="form-text"><?php echo t('set.site_url_help'); ?></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold"><?php echo e('set.qr_ip_label'); ?></label>
                    <input type="text" name="qr_ip" class="form-control" dir="ltr"
                           value="<?= htmlspecialchars($s['qr_ip']) ?>" placeholder="192.168.1.50">
                    <div class="form-text"><?php echo t('set.qr_ip_help'); ?></div>
                </div>
                <div class="col-12">
                    <div class="alert alert-light border small mb-0 py-2">
                        <i class="bi bi-qr-code"></i> <?php echo e('set.qr_preview_label'); ?>
                        <code class="text-break" dir="ltr"><?= htmlspecialchars(
                            buildDisplayQrUrl($s['site_url'] !== '' ? $s['site_url'] : detectSiteUrl(), $s['qr_ip'], '1e23db0bad1d275065678fe818690b19')
                        ) ?></code>
                    </div>
                </div>
                <div class="col-12 text-end">
                    <button type="submit" name="save_url" class="btn btn-success shadow-sm"><i class="bi bi-save"></i> <?php echo e('set.save_url_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4 border-0 rounded-3">
        <div class="card-header bg-primary text-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-shield-lock"></i> <?php echo e('set.pdpa_card'); ?></h6>
        </div>
        <div class="card-body p-4">
            <form method="POST" class="row g-3 align-items-center">
                <?= csrf_field() ?>
                <div class="col-md-9">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="pdpa_enabled" id="pdpaEnabled" role="switch"
                               <?= $s['pdpa_enabled'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="pdpaEnabled"><?php echo e('set.pdpa_switch'); ?></label>
                    </div>
                    <div class="form-text mt-2"><i class="bi bi-info-circle"></i> <?php echo t('set.pdpa_help'); ?></div>
                </div>
                <div class="col-md-3">
                    <button type="submit" name="save_pdpa" class="btn btn-success w-100 shadow-sm"><i class="bi bi-save"></i> <?php echo e('btn.save'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4 border-0 rounded-3">
        <div class="card-header bg-primary text-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-plus-circle"></i> <?php echo e('set.add_field_card'); ?></h6>
        </div>
        <div class="card-body p-4">
            <form method="POST" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-5">
                    <label class="form-label small fw-bold"><?php echo e('set.field_name_label'); ?></label>
                    <input type="text" name="field_name" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold"><?php echo e('set.field_type_label'); ?></label>
                    <select name="field_type" id="field_type_add" class="form-select" onchange="toggleSearchOption('add')">
                        <option value="text">🔤 Textbox</option>
                        <option value="checkbox" selected>✅ Checkbox</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="is_searchable" id="isSearchAdd" checked>
                        <label class="form-check-label small fw-bold" for="isSearchAdd"><?php echo e('set.show_in_search'); ?></label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" name="add_field" class="btn btn-primary w-100 shadow-sm"><?php echo e('set.add_field_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-responsive bg-white shadow-sm rounded-3">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4"><?php echo e('set.field_name_label'); ?></th>
                    <th><?php echo e('set.col_type'); ?></th>
                    <th class="text-center"><?php echo e('set.col_active'); ?></th>
                    <th class="text-center"><?php echo e('set.col_search'); ?></th>
                    <th class="text-end pe-4"><?php echo e('list.col_manage'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($fields as $f): ?>
                <tr>
                    <td class="ps-4 fw-bold"><?php echo htmlspecialchars($f['field_name']); ?></td>
                    <td>
                        <span class="badge bg-light text-dark border">
                            <?php echo $f['field_type'] === 'text' ? 'Text' : 'Checkbox'; ?>
                        </span>
                    </td>
                    <td class="text-center"><?php echo $f['is_active'] ? e('set.status_on') : e('set.status_off'); ?></td>
                    <td class="text-center">
                        <?php if($f['field_type'] === 'text'): ?>
                            <span class="text-muted small">-</span>
                        <?php else: ?>
                            <?php echo $f['is_searchable'] ? '<i class="bi bi-search text-primary"></i>' : '<i class="bi bi-dash"></i>'; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                        <button class="btn btn-sm btn-outline-warning rounded-pill" onclick='openEditModal(<?php echo json_encode($f); ?>)'><?php echo e('btn.edit'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (isEngineer()): ?>
    <div class="card shadow-sm mt-4 border-danger border-opacity-50 rounded-3">
        <div class="card-header bg-danger text-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-exclamation-octagon-fill"></i> <?php echo e('reset.danger_zone'); ?></h6>
        </div>
        <div class="card-body p-4">
            <p class="small text-danger-emphasis mb-3"><i class="bi bi-radioactive"></i> <?php echo t('reset.warn'); ?></p>
            <form method="POST" id="resetSystemForm" class="row g-3 align-items-end" autocomplete="off"
                  onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('reset.js_confirm'), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>);">
                <?= csrf_field() ?>
                <div class="col-md-4">
                    <label class="form-label small fw-bold"><?php echo e('reset.confirm_word_label'); ?></label>
                    <input type="text" name="reset_confirm_word" class="form-control" placeholder="RESET" required autocomplete="off">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold"><?php echo e('reset.password_label'); ?></label>
                    <input type="password" name="reset_password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="col-md-4">
                    <button type="submit" name="reset_system" value="1" class="btn btn-danger w-100 shadow-sm fw-bold">
                        <i class="bi bi-arrow-counterclockwise"></i> <?php echo e('reset.btn'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="editFieldModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold"><?php echo e('set.edit_modal_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="field_id" id="edit_field_id">
                <input type="hidden" id="edit_field_type"> <div class="mb-4">
                    <label class="form-label fw-bold small"><?php echo e('set.field_name_label'); ?></label>
                    <input type="text" name="field_name" id="edit_field_name" class="form-control" required>
                </div>

                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 border rounded bg-light">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                                <label class="form-check-label fw-bold small"><?php echo e('set.enable'); ?></label>
                            </div>
                        </div>
                    </div>
                    <div id="search_option_wrapper" class="col-6">
                        <div class="p-3 border rounded bg-light">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_searchable" id="edit_is_searchable">
                                <label class="form-check-label fw-bold small"><?php echo e('set.use_in_search'); ?></label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="update_field" class="btn btn-primary rounded-pill px-4"><?php echo e('btn.save'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
/**
 * จัดการการแสดงผลตัวเลือกค้นหา
 */
function toggleSearchOption(mode) {
    if(mode === 'add') {
        const type = document.getElementById('field_type_add').value;
        const searchSwitch = document.getElementById('isSearchAdd');
        if(type === 'text') {
            searchSwitch.checked = false;
            searchSwitch.disabled = true;
        } else {
            searchSwitch.disabled = false;
        }
    }
}

function openEditModal(field) {
    document.getElementById('edit_field_id').value = field.id;
    document.getElementById('edit_field_name').value = field.field_name;
    document.getElementById('edit_is_active').checked = parseInt(field.is_active) === 1;
    document.getElementById('edit_field_type').value = field.field_type;
    
    const searchSwitch = document.getElementById('edit_is_searchable');
    const searchWrapper = document.getElementById('search_option_wrapper');

    if(field.field_type === 'text') {
        searchSwitch.checked = false;
        searchWrapper.style.opacity = '0.5';
        searchSwitch.disabled = true;
    } else {
        searchSwitch.checked = parseInt(field.is_searchable) === 1;
        searchWrapper.style.opacity = '1';
        searchSwitch.disabled = false;
    }
    
    new bootstrap.Modal(document.getElementById('editFieldModal')).show();
}

// รันครั้งแรกเพื่อเซ็ตสถานะหน้า Add
document.addEventListener('DOMContentLoaded', () => toggleSearchOption('add'));
</script>