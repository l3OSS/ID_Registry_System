<?php
// install/index.php — ตัวติดตั้ง 2 โหมด: (1) ติดตั้งครั้งแรก (fresh) (2) อัพเดตจากเวอร์ชันเก่า (update)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mode      = preg_replace('/[^a-z]/', '', $_GET['mode'] ?? '');
$installed = file_exists(__DIR__ . '/install.lock');

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
if ($mode === 'update') {
    $_SESSION['update_token'] = bin2hex(random_bytes(16));
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ระบบติดตั้ง - Citizen Registration CMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
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
            <h2 class="mb-0 fw-bold">Citizen Registration Setup</h2>
            <p class="mb-0 opacity-75">เริ่มต้นติดตั้ง / อัพเดตระบบจัดการข้อมูลผู้พัก</p>
        </div>
        <div class="card-body p-4">

<?php if ($mode !== 'fresh' && $mode !== 'update'): // ---------- LANDING ---------- ?>

            <div class="step-header">
                <h5 class="fw-bold"><i class="bi bi-signpost-split"></i> เลือกรูปแบบการติดตั้ง</h5>
            </div>
            <?php if ($installed): ?>
                <div class="alert alert-info border-0 small"><i class="bi bi-info-circle"></i> ระบบนี้ <strong>ติดตั้งแล้ว</strong> — โดยปกติควรเลือก "อัพเดต" เท่านั้น</div>
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <a href="index.php?mode=fresh" class="text-decoration-none <?php echo $installed ? 'pe-none opacity-50' : ''; ?>">
                        <div class="card mode-card h-100 text-center p-4">
                            <i class="bi bi-stars text-primary display-4"></i>
                            <h5 class="fw-bold mt-3">ติดตั้งครั้งแรก</h5>
                            <p class="text-muted small mb-0">สร้างฐานข้อมูลใหม่ + บัญชีผู้ดูแล + ไฟล์ .env<?php echo $installed ? ' (ถูกล็อก: ติดตั้งแล้ว)' : ''; ?></p>
                        </div>
                    </a>
                </div>
                <div class="col-md-6">
                    <a href="index.php?mode=update" class="text-decoration-none <?php echo $installed ? '' : 'opacity-75'; ?>">
                        <div class="card mode-card h-100 text-center p-4">
                            <i class="bi bi-arrow-repeat text-success display-4"></i>
                            <h5 class="fw-bold mt-3">อัพเดตจากเวอร์ชันเก่า</h5>
                            <p class="text-muted small mb-0">สำรอง DB + รัน migration บนข้อมูลเดิม (ไม่ล้างข้อมูล)</p>
                        </div>
                    </a>
                </div>
            </div>

<?php elseif ($mode === 'update'): // ---------- UPDATE ---------- ?>

            <div class="step-header">
                <h5 class="fw-bold"><i class="bi bi-arrow-repeat"></i> อัพเดตจากเวอร์ชันเก่า</h5>
            </div>
            <?php if (!$installed): ?>
                <div class="alert alert-warning border-0"><i class="bi bi-exclamation-triangle-fill"></i>
                    ยังไม่พบการติดตั้งเดิม (`install.lock`) — หากยังไม่เคยติดตั้ง กรุณา
                    <a href="index.php?mode=fresh">ติดตั้งครั้งแรก</a> ก่อน
                </div>
            <?php else: ?>
                <p class="text-muted">ระบบจะดำเนินการต่อไปนี้บนฐานข้อมูลเดิม (อ่านค่าเชื่อมต่อจาก <code>.env</code>):</p>
                <ul class="mb-4">
                    <li><strong>สำรองฐานข้อมูล</strong> อัตโนมัติ → <code>backups/</code></li>
                    <li>P8 — trigger append-only ของ <code>activity_logs</code></li>
                    <li>P7 — เพิ่ม <code>public_id</code> + backfill</li>
                    <li>P5+P6 — re-encrypt ข้อมูลอ่อนไหวเป็น GCM (idempotent)</li>
                </ul>
                <div class="alert alert-info border-0 small"><i class="bi bi-shield-check"></i>
                    ทุกขั้นตอน idempotent — รันซ้ำได้ ไม่แตะบัญชีผู้ใช้/รหัสผ่าน/ไฟล์ .env และไม่ล้างข้อมูล
                </div>
                <div class="alert alert-warning border-0 small">
                    <i class="bi bi-exclamation-triangle-fill"></i> <strong>สำรองข้อมูลก่อนอัพเดต</strong>
                    <ul class="mb-0 mt-1 ps-3">
                        <li>P5+P6 <strong>เขียนทับข้อมูลที่เข้ารหัสไว้เดิม</strong> (เลขบัตร/เบอร์โทร) — ตัวติดตั้งสำรอง DB ให้อัตโนมัติและจะหยุดถ้าสำรองไม่สำเร็จ แต่ควรสำรองเองไว้อีกชุด</li>
                        <li>สำรองไฟล์ <code>.env</code> ด้วย — ห้ามเปลี่ยน <code>ENCRYPTION_KEY</code> ก่อนอัพเดต มิฉะนั้นข้อมูลเดิมจะถอดรหัสไม่ได้อีก</li>
                    </ul>
                </div>
                <form action="process_update.php" method="POST" onsubmit="return confirm('สำรองฐานข้อมูลและไฟล์ .env ไว้แล้วใช่หรือไม่? กด OK เพื่อเริ่มอัพเดต');">
                    <input type="hidden" name="update_token" value="<?php echo htmlspecialchars($_SESSION['update_token']); ?>">
                    <div class="d-flex gap-2">
                        <a href="index.php" class="btn btn-outline-secondary w-50">ย้อนกลับ</a>
                        <button type="submit" class="btn btn-success w-50 fw-bold"><i class="bi bi-arrow-repeat"></i> เริ่มอัพเดต</button>
                    </div>
                </form>
            <?php endif; ?>

<?php else: // ---------- FRESH (mode === 'fresh') ---------- ?>

            <div class="step-header">
                <h5 class="fw-bold"><i class="bi bi-gear-wide-connected"></i> 1. ตรวจสอบความพร้อมของเซิร์ฟเวอร์</h5>
            </div>
            <ul class="list-group mb-4">
                <?php foreach($requirements as $label => $pass): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center small">
                    <?php echo $label; ?>
                    <?php echo $pass ? '<span class="text-success fw-bold"><i class="bi bi-check-circle"></i> ผ่าน</span>' : '<span class="text-danger fw-bold"><i class="bi bi-x-circle"></i> ล้มเหลว</span>'; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($can_install): ?>
            <form action="process_install.php" method="POST">

                <div class="step-header">
                    <h5 class="fw-bold"><i class="bi bi-database-fill-gear"></i> 2. ตั้งค่าฐานข้อมูล</h5>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label small">Database Host</label>
                        <input type="text" name="db_host" class="form-control" value="localhost" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Port</label>
                        <input type="text" name="db_port" class="form-control" value="3306" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Database Name</label>
                        <input type="text" name="db_name" class="form-control" placeholder="เช่น reg" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Username</label>
                        <input type="text" name="db_user" class="form-control" value="root" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Database Password</label>
                        <input type="password" name="db_pass" class="form-control" placeholder="เว้นว่างได้หากไม่ได้ตั้งไว้">
                    </div>
                </div>

                <div class="step-header">
                    <h5 class="fw-bold"><i class="bi bi-person-badge-fill"></i> 3. สร้างบัญชีผู้ดูแลระบบ</h5>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-12">
                        <label class="form-label small">Username (ID สำหรับล็อคอิน)</label>
                        <input type="text" name="admin_user" class="form-control" placeholder="เช่น admin" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Password (อย่างน้อย 6 ตัว — ตัวเลขล้วนได้)</label>
                        <input type="password" name="admin_pass" id="admin_pass" class="form-control" minlength="6" required oninput="checkPasswordMatch()">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">ยืนยัน Password อีกครั้ง</label>
                        <input type="password" name="admin_pass_confirm" id="admin_pass_confirm" class="form-control" minlength="6" required oninput="checkPasswordMatch()">
                        <div id="password-feedback" class="small mt-1"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">นามแฝง</label>
                        <input type="text" name="admin_nickname" class="form-control" placeholder="เช่น แอดมินหลัก" required>
                    </div>
                </div>

                <div class="card shadow border-0 rounded-4">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <i class="bi bi-file-earmark-medical text-primary display-4"></i>
                            <h4 class="fw-bold mt-2">ยืนยันข้อตกลงก่อนเริ่มติดตั้ง</h4>
                            <p class="text-muted small">โปรดอ่านเงื่อนไขด้านความปลอดภัยและกฎหมายข้อมูลส่วนบุคคล</p>
                        </div>

                        <div class="form-control bg-light p-3 mb-4" style="height: 250px; overflow-y: scroll; font-size: 0.9rem; line-height: 1.6;">
                            <?php echo nl2br(htmlspecialchars(file_get_contents('terms.txt'))); ?>
                        </div>

                        <div class="alert alert-warning border-0 small shadow-sm mb-4">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <strong>คำเตือน:</strong> หากไฟล์ .env หาย ข้อมูลที่เข้ารหัสไว้จะกู้คืนไม่ได้ โปรดสำรองข้อมูลเสมอ
                        </div>

                        <div class="form-check mb-4">
                            <input class="form-check-input border-primary" type="checkbox" id="agreeCheckbox">
                            <label class="form-check-label fw-bold" for="agreeCheckbox">
                                ข้าพเจ้าได้อ่านและยอมรับข้อตกลงการใช้งานระบบ (PDPA Compliant)
                            </label>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary w-50" onclick="if(confirm('ยกเลิกการติดตั้ง?')) window.location.href='index.php';">ยกเลิก</button>
                            <button type="submit" id="installBtn" class="btn btn-primary w-50 fw-bold" disabled>
                                ดำเนินการต่อและเริ่มติดตั้ง <i class="bi bi-chevron-right"></i>
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
                    feedback.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร</span>';
                } else if (pass.value !== confirm.value) {
                    feedback.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> รหัสผ่านไม่ตรงกัน</span>';
                } else {
                    feedback.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> รหัสผ่านใช้ได้และตรงกัน</span>';
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
