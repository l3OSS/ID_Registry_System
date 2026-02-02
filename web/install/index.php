<?php
// install/index.php
$requirements = [
    'PHP Version (8.1+)' => PHP_VERSION_ID >= 80100,
    'PDO MySQL'          => extension_loaded('pdo_mysql'),
    'OpenSSL (Security)' => extension_loaded('openssl'),
    'Folder: config/'    => is_writable('../config'),
    'Folder: core/'      => is_writable('../core'),
    'Folder: uploads/'   => is_writable('../uploads'),
];
$can_install = !in_array(false, $requirements);
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
    </style>
</head>
<body>
<div class="container">
    <div class="card install-card shadow-lg border-0">
        <div class="card-header bg-primary text-white p-4 text-center">
            <h2 class="mb-0 fw-bold">Citizen Registration Setup</h2>
            <p class="mb-0 opacity-75">เริ่มต้นติดตั้งระบบจัดการข้อมูลผู้พัก</p>
        </div>
        <div class="card-body p-4">
            
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
                    <div class="col-md-6">
                        <label class="form-label small">Database Host</label>
                        <input type="text" name="db_host" class="form-control" value="localhost" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Database Name</label>
                        <input type="text" name="db_name" class="form-control" placeholder="เช่น db_registration" required>
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
    <h5 class="fw-bold"><i class="bi bi-person-badge-fill"></i> 4. สร้างบัญชีผู้ดูแลระบบ</h5>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-12">
        <label class="form-label small">Username (ID สำหรับล็อคอิน)</label>
        <input type="text" name="admin_user" class="form-control" placeholder="เช่น admin" required>
    </div>
    <div class="col-md-6">
        <label class="form-label small">Password (อย่างน้อย 6 ตัวอักษร)</label>
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
            <?php echo nl2br(file_get_contents('terms.txt')); ?>
        </div>

        <div class="alert alert-warning border-0 small shadow-sm mb-4">
            <i class="bi bi-exclamation-triangle-fill"></i> 
            <strong>คำเตือน:</strong> หากไฟล์ .env หาย ข้อมูลที่เข้ารหัสไว้จะกู้คืนไม่ได้ โปรดสำรองข้อมูลเสมอ
        </div>

        <div class="form-check mb-4">
            <input class="form-check-input border-primary" type="checkbox" id="agreeCheckbox" onchange="document.getElementById('installBtn').disabled = !this.checked;">
            <label class="form-check-label fw-bold" for="agreeCheckbox">
                ข้าพเจ้าได้อ่านและยอมรับข้อตกลงการใช้งานระบบ (PDPA Compliant)
            </label>
        </div>

        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary w-50" onclick="if(confirm('ยกเลิกการติดตั้ง?')) window.location.href='../login.php';">ยกเลิก</button>
            <button type="submit" id="installBtn" class="btn btn-primary w-50 fw-bold" disabled>
                ดำเนินการต่อและเริ่มติดตั้ง <i class="bi bi-chevron-right"></i>
            </button>
        </div>
        </form>
    </div>
</div>

<script>
function toggleSubmit() {
    const isChecked = document.getElementById('agreeCheckbox').checked;
    document.getElementById('nextBtn').disabled = !isChecked;
}
</script>


<script>
// ฟังก์ชันจัดการปุ่มกดและเงื่อนไขรหัสผ่าน
function validateForm() {
    const isAgreed = document.getElementById('agreeCheckbox').checked;
    const pass = document.getElementById('admin_pass').value;
    const confirm = document.getElementById('admin_pass_confirm').value;
    const installBtn = document.getElementById('installBtn');
    
    // เงื่อนไข: ต้องยอมรับข้อตกลง + รหัสผ่านต้องยาว 6+ + รหัสผ่านต้องตรงกัน
    const isPasswordValid = (pass.length >= 6 && pass === confirm);
    
    // ถ้าผ่านเงื่อนไขทั้งหมด ให้เปิดใช้งานปุ่ม
    installBtn.disabled = !(isAgreed && isPasswordValid);
}

// ผูกฟังก์ชันเข้ากับ Event ต่างๆ
document.getElementById('agreeCheckbox').addEventListener('change', validateForm);
document.getElementById('admin_pass').addEventListener('input', checkPasswordMatch);
document.getElementById('admin_pass_confirm').addEventListener('input', checkPasswordMatch);

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
    
    // เรียกฟังก์ชันหลักเพื่อเช็คว่าปุ่มควรเปิดหรือยัง
    validateForm();
            }
</script>

             <?php endif; ?>

        </div>
    </div>
</div>



</body>
</html>