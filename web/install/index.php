<?php
// install/index.php — ตัวติดตั้ง 2 โหมด: (1) ติดตั้งครั้งแรก (fresh) (2) อัพเดตจากเวอร์ชันเก่า (update)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../core/lang.php'; // ข้อความทั้งหน้าอยู่ที่ lang/th.php (ไม่พึ่ง DB)

$mode      = preg_replace('/[^a-z]/', '', $_GET['mode'] ?? '');
$installed = file_exists(__DIR__ . '/install.lock');

// อัพเดตหลังติดตั้ง = งานสงวนของ EngiNear เท่านั้น (กันเผลอเข้า/ยิงมั่ว)
// ติดตั้งครั้งแรก (ยังไม่มี install.lock) ไม่ติดล็อก เพราะยังไม่มีบัญชีให้ล็อกอิน
$engOK   = true;
$pending = true; // มี migration ค้างให้ทำไหม — โชว์ฟอร์มอัพเดตเฉพาะเมื่อค้างจริง (กันกดซ้ำทั้งที่ไม่มีอะไรต้องทำ)
if ($installed) {
    require_once __DIR__ . '/../core/rbac.php';   // นำ isEngineer() + $pdo (ผ่าน auth→config/db) มาด้วย
    $engOK = isEngineer();
    if ($engOK) {
        require_once __DIR__ . '/../core/migrate.php';
        $pending = updatePending($pdo);
    }
}

// S1: fresh install ถูกบล็อกเมื่อระบบติดตั้งแล้ว (กัน re-install attack) — ต้องอัพเดตผ่านโหมด update เท่านั้น
if ($mode === 'fresh' && $installed) {
    header('Location: ../index.php');
    exit();
}

// requirements (ใช้เฉพาะโหมด fresh) — นิยามที่เดียวใน requirements.php, process_install.php ตรวจซ้ำฝั่ง server
require_once __DIR__ . '/requirements.php';
$requirements = installRequirements();
$can_install  = !in_array(false, $requirements, true);

// nonce สำหรับโหมด update (กัน drive-by POST)
if ($mode === 'update' && $installed && $engOK && $pending) {
    $_SESSION['update_token'] = bin2hex(random_bytes(16));
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?php echo e('inst.page_title'); ?></title>
    <link href="../assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/bootstrap-icons/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; }
        .install-card { max-width: 700px; margin: 50px auto; border-radius: 15px; }
        .step-header { border-bottom: 2px solid #eee; margin-bottom: 20px; padding-bottom: 10px; }
        .mode-card { cursor: pointer; transition: .2s; border: 2px solid #e9ecef; }
        .mode-card:hover { border-color: #0d6efd; transform: translateY(-3px); }
    </style>
</head>
<body>
<div class="container">
    <div class="card install-card shadow-lg border-0">
        <div class="card-header bg-primary text-white p-4 text-center">
            <h2 class="mb-0 fw-bold"><?php echo e('inst.brand'); ?></h2>
            <p class="mb-0 opacity-75"><?php echo e('inst.subtitle'); ?></p>
        </div>
        <div class="card-body p-4">

<?php if ($mode !== 'fresh' && $mode !== 'update'): // ---------- LANDING ---------- ?>

            <div class="step-header">
                <h5 class="fw-bold"><i class="bi bi-signpost-split"></i> <?php echo e('inst.choose'); ?></h5>
            </div>
            <?php if ($installed): ?>
                <div class="alert alert-info border-0 small"><i class="bi bi-info-circle"></i> <?php echo t('inst.already_note'); ?></div>
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <a href="index.php?mode=fresh" class="text-decoration-none <?php echo $installed ? 'pe-none opacity-50' : ''; ?>">
                        <div class="card mode-card h-100 text-center p-4">
                            <i class="bi bi-stars text-primary display-4"></i>
                            <h5 class="fw-bold mt-3"><?php echo e('inst.fresh_title'); ?></h5>
                            <p class="text-muted small mb-0"><?php echo e('inst.fresh_desc'); ?><?php echo $installed ? e('inst.fresh_locked') : ''; ?></p>
                        </div>
                    </a>
                </div>
                <div class="col-md-6">
                    <a href="index.php?mode=update" class="text-decoration-none <?php echo $installed ? '' : 'opacity-75'; ?>">
                        <div class="card mode-card h-100 text-center p-4">
                            <i class="bi bi-arrow-repeat text-success display-4"></i>
                            <h5 class="fw-bold mt-3"><?php echo e('inst.update_title'); ?></h5>
                            <p class="text-muted small mb-0"><?php echo e('inst.update_desc'); ?></p>
                        </div>
                    </a>
                </div>
            </div>

<?php elseif ($mode === 'update'): // ---------- UPDATE ---------- ?>

            <div class="step-header">
                <h5 class="fw-bold"><i class="bi bi-arrow-repeat"></i> <?php echo e('inst.update_title'); ?></h5>
            </div>
            <?php if (!$installed): ?>
                <div class="alert alert-warning border-0"><i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo e('inst.not_installed'); ?><a href="index.php?mode=fresh"><?php echo e('inst.do_install_link'); ?></a>
                </div>
            <?php elseif (!$engOK): ?>
                <div class="alert alert-danger border-0"><i class="bi bi-shield-lock"></i>
                    <?php echo t('inst.upd_login_required'); ?>
                </div>
                <a href="../index.php?page=login" class="btn btn-outline-primary"><i class="bi bi-box-arrow-in-right"></i> <?php echo e('inst.upd_login_btn'); ?></a>
            <?php elseif (!$pending): ?>
                <div class="alert alert-success border-0"><i class="bi bi-check-circle-fill"></i>
                    <?php echo t('inst.upd_none_pending'); ?>
                </div>
                <a href="../index.php" class="btn btn-outline-secondary"><?php echo e('btn.back'); ?></a>
            <?php else: ?>
                <p class="text-muted"><?php echo t('inst.update_intro'); ?></p>
                <ul class="mb-4">
                    <li><?php echo t('inst.update_li_backup'); ?></li>
                    <li><?php echo t('inst.update_li_p8'); ?></li>
                    <li><?php echo t('inst.update_li_p7'); ?></li>
                    <li><?php echo t('inst.update_li_p5p6'); ?></li>
                </ul>
                <div class="alert alert-info border-0 small"><i class="bi bi-shield-check"></i>
                    <?php echo e('inst.update_idem'); ?>
                </div>
                <div class="alert alert-warning border-0 small">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo t('inst.update_warn_title'); ?>
                    <ul class="mb-0 mt-1 ps-3">
                        <li><?php echo t('inst.update_warn_li1'); ?></li>
                        <li><?php echo t('inst.update_warn_li2'); ?></li>
                    </ul>
                </div>
                <form action="process_update.php" method="POST" onsubmit="return confirm('<?php echo e('inst.update_confirm_js'); ?>');">
                    <input type="hidden" name="update_token" value="<?php echo htmlspecialchars($_SESSION['update_token']); ?>">
                    <div class="d-flex gap-2">
                        <a href="index.php" class="btn btn-outline-secondary w-50"><?php echo e('btn.back'); ?></a>
                        <button type="submit" class="btn btn-success w-50 fw-bold"><i class="bi bi-arrow-repeat"></i> <?php echo e('inst.update_start_btn'); ?></button>
                    </div>
                </form>
            <?php endif; ?>

<?php else: // ---------- FRESH (mode === 'fresh') ---------- ?>

            <div class="step-header">
                <h5 class="fw-bold"><i class="bi bi-gear-wide-connected"></i> <?php echo e('inst.step_check'); ?></h5>
            </div>
            <ul class="list-group mb-4">
                <?php foreach($requirements as $label => $pass): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center small">
                    <?php echo htmlspecialchars($label); ?>
                    <?php echo $pass ? '<span class="text-success fw-bold"><i class="bi bi-check-circle"></i> ' . e('inst.req_pass') . '</span>' : '<span class="text-danger fw-bold"><i class="bi bi-x-circle"></i> ' . e('inst.req_fail') . '</span>'; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($can_install): ?>
            <form action="process_install.php" method="POST">

                <div class="step-header">
                    <h5 class="fw-bold"><i class="bi bi-database-fill-gear"></i> <?php echo e('inst.step_db'); ?></h5>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label small"><?php echo e('inst.lbl_db_host'); ?></label>
                        <input type="text" name="db_host" class="form-control" value="localhost" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small"><?php echo e('inst.lbl_db_port'); ?></label>
                        <input type="text" name="db_port" class="form-control" value="3306" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small"><?php echo e('inst.lbl_db_name'); ?></label>
                        <input type="text" name="db_name" class="form-control" placeholder="<?php echo e('inst.ph_db_name'); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small"><?php echo e('inst.lbl_db_user'); ?></label>
                        <input type="text" name="db_user" class="form-control" value="root" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small"><?php echo e('inst.lbl_db_pass'); ?></label>
                        <input type="password" name="db_pass" class="form-control" placeholder="<?php echo e('inst.ph_db_pass'); ?>">
                    </div>
                </div>

                <div class="step-header">
                    <h5 class="fw-bold"><i class="bi bi-person-badge-fill"></i> <?php echo e('inst.step_admin'); ?></h5>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-12">
                        <label class="form-label small"><?php echo e('inst.lbl_admin_user'); ?></label>
                        <input type="text" name="admin_user" class="form-control" placeholder="<?php echo e('inst.ph_admin_user'); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small"><?php echo e('inst.lbl_admin_pass'); ?></label>
                        <input type="password" name="admin_pass" id="admin_pass" class="form-control" minlength="6" required oninput="checkPasswordMatch()">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small"><?php echo e('inst.lbl_admin_pass2'); ?></label>
                        <input type="password" name="admin_pass_confirm" id="admin_pass_confirm" class="form-control" minlength="6" required oninput="checkPasswordMatch()">
                        <div id="password-feedback" class="small mt-1"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small"><?php echo e('inst.lbl_nickname'); ?></label>
                        <input type="text" name="admin_nickname" class="form-control" placeholder="<?php echo e('inst.ph_nickname'); ?>" required>
                    </div>
                </div>

                <div class="card shadow border-0 rounded-4">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <i class="bi bi-file-earmark-medical text-primary display-4"></i>
                            <h4 class="fw-bold mt-2"><?php echo e('inst.terms_title'); ?></h4>
                            <p class="text-muted small"><?php echo e('inst.terms_sub'); ?></p>
                        </div>

                        <div class="form-control bg-light p-3 mb-4" style="height: 250px; overflow-y: scroll; font-size: 0.9rem; line-height: 1.6;">
                            <?php echo nl2br(htmlspecialchars(file_get_contents('terms.txt'))); ?>
                        </div>

                        <div class="alert alert-warning border-0 small shadow-sm mb-4">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo t('inst.env_warn'); ?>
                        </div>

                        <div class="form-check mb-4">
                            <input class="form-check-input border-primary" type="checkbox" id="agreeCheckbox">
                            <label class="form-check-label fw-bold" for="agreeCheckbox">
                                <?php echo e('inst.agree_label'); ?>
                            </label>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary w-50" onclick="if(confirm('<?php echo e('inst.cancel_confirm_js'); ?>')) window.location.href='index.php';"><?php echo e('btn.cancel'); ?></button>
                            <button type="submit" id="installBtn" class="btn btn-primary w-50 fw-bold" disabled>
                                <?php echo e('inst.install_btn'); ?> <i class="bi bi-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <script>
            function validateForm() {
                const isAgreed = document.getElementById('agreeCheckbox').checked;
                const pass = document.getElementById('admin_pass').value;
                const confirm = document.getElementById('admin_pass_confirm').value;
                // client hint เท่านั้น — server บังคับ policy จริง (passwordPolicyError: ยาว >= 6)
                const isPasswordValid = (pass.length >= 6 && pass === confirm);
                document.getElementById('installBtn').disabled = !(isAgreed && isPasswordValid);
            }

            function checkPasswordMatch() {
                const pass = document.getElementById('admin_pass');
                const confirm = document.getElementById('admin_pass_confirm');
                const feedback = document.getElementById('password-feedback');
                if (pass.value.length < 6) {
                    feedback.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> <?php echo e('inst.js_pw_min'); ?></span>';
                } else if (pass.value !== confirm.value) {
                    feedback.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> <?php echo e('inst.js_pw_mismatch'); ?></span>';
                } else {
                    feedback.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> <?php echo e('inst.js_pw_ok'); ?></span>';
                }
                validateForm();
            }
            document.getElementById('agreeCheckbox').addEventListener('change', validateForm);
            </script>
            <?php endif; ?>

<?php endif; // ---------- end modes ---------- ?>

        </div>
    </div>
</div>
</body>
</html>
