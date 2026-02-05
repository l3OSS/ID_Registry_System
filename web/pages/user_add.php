<?php
/**
 * Page: Add New Staff Member
 * Access Level: Developer (1) only.
 */

require_once 'core/auth.php';
require_once 'core/security.php';
require_once 'core/log.php';

// --- 1. Access & Security Check ---
$can_access = (isset($_SESSION['role_level']) && (int)$_SESSION['role_level'] === 1);

if (!$can_access) {
    echo '
    <div class="container mt-5">
        <div class="alert alert-danger shadow-sm border-0 p-4 rounded-3 text-center">
            <i class="bi bi-exclamation-octagon-fill fs-1 d-block mb-3"></i>
            <h4 class="fw-bold">ขออภัย คุณไม่มีสิทธิ์เข้าถึงหน้าจัดการผู้ใช้</h4>
            <a href="index.php" class="btn btn-outline-danger rounded-pill px-4">
                <i class="bi bi-arrow-left"></i> กลับหน้าหลัก
            </a>
        </div>
    </div>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Data Sanitization
    $username   = trim($_POST['new-username'] ?? '');
    $password   = $_POST['user_pass_new'] ?? '';
    $confirm    = $_POST['user_pass_confirm'] ?? '';
    $nickname   = trim($_POST['nickname'] ?? '');
    $role_level = filter_input(INPUT_POST, 'role_level', FILTER_VALIDATE_INT);

    // 2. Validation
    if (strlen($password) < 6) {
        $_SESSION['error_msg'] = "❌ รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร";
    } elseif ($password !== $confirm) {
        $_SESSION['error_msg'] = "❌ รหัสผ่านทั้งสองช่องไม่ตรงกัน";
    } elseif (empty($username) || empty($role_level)) {
        $_SESSION['error_msg'] = "❌ กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน";
    } else {
        try {
            // 3. Duplicate Username Check
            $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
            $stmt->execute([$username]);

            if ($stmt->fetch()) {
                $_SESSION['error_msg'] = "❌ ชื่อผู้ใช้นี้ (Username) ถูกใช้งานแล้ว กรุณาใช้ชื่ออื่น";
            } else {
                // 4. Secure Password Hashing
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // 5. Insert New Record
                $sql = "INSERT INTO users (username, password_hash, nickname, role_level, avatar_name, is_active) 
                        VALUES (?, ?, ?, ?, 'default.png', 1)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$username, $password_hash, $nickname, $role_level]);

                writeLog($pdo, 'ADD_USER', "เพิ่มผู้ใช้งานใหม่: $username (สิทธิ์ระดับ $role_level)");
                
                // Success Redirect
                echo "<script>alert('✅ เพิ่มผู้ใช้งานเรียบร้อยแล้ว'); window.location='index.php?page=user_manage';</script>";
                exit;
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $_SESSION['error_msg'] = "❌ เกิดข้อผิดพลาดในระบบฐานข้อมูล";
        }
    }
}
?>

<div class="container mt-4 mb-5">
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php?page=user_manage">จัดการผู้ใช้งาน</a></li>
            <li class="breadcrumb-item active" aria-current="page">เพิ่มผู้ใช้งานใหม่</li>
        </ol>
    </nav>

    <div class="card shadow-sm mx-auto border-0 rounded-4 overflow-hidden" style="max-width: 550px;">
        <div class="card-header bg-success text-white py-3 border-0">
            <h5 class="mb-0 fw-bold"><i class="bi bi-person-plus-fill"></i> เพิ่มทีมงานใหม่เข้าสู่ระบบ</h5>
        </div>
        <div class="card-body p-4 p-md-5 bg-white">
            
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger shadow-sm border-0 py-2 mb-4">
                    <i class="bi bi-exclamation-octagon-fill me-2"></i> <?php echo $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="addUserForm" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">ชื่อผู้ใช้ (Username) *</label>
                    <div class="input-group border rounded-3 overflow-hidden">
                        <span class="input-group-text bg-light border-0"><i class="bi bi-person"></i></span>
                        <input type="text" name="new-username" class="form-control border-0" required 
                               placeholder="สำหรับเข้าสู่ระบบ" autofocus autocomplete="username">
                    </div>
                </div>

                
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label small fw-bold text-muted">รหัสผ่าน (Password) *</label>
                        <div class="input-group border rounded-3 overflow-hidden">
                            <span class="input-group-text bg-light border-0"><i class="bi bi-key"></i></span>
                            <input type="password" name="user_pass_new" id="password" class="form-control border-0" 
                                   required minlength="6" placeholder="รหัสผ่าน 6+ ตัวอักษร" autocomplete="new-password">
                            <button class="btn btn-white border-0 text-muted" type="button" onclick="togglePassword()">
                                <i class="bi bi-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold text-muted">ยืนยันรหัสผ่าน *</label>
                        <div class="input-group border rounded-3 overflow-hidden">
                            <span class="input-group-text bg-light border-0"><i class="bi bi-shield-check"></i></span>
                            <input type="password" name="user_pass_confirm" id="confirm_password" class="form-control border-0" 
                                   required minlength="6" placeholder="กรอกรหัสผ่านอีกครั้ง" oninput="checkPasswordMatch()">
                            <div class="invalid-feedback ps-3 pb-1">รหัสผ่านไม่ตรงกัน</div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">ชื่อเล่น / นามแฝง</label>
                    <input type="text" name="nickname" class="form-control border-2" placeholder="ชื่อที่จะแสดงในระบบ">
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-primary">ระดับสิทธิ์การเข้าถึง (Permissions) *</label>
                    <select name="role_level" class="form-select border-primary border-2 shadow-sm" required>
                        <option value="3">🏢 Regis - งานทะเบียน</option>
                        <option value="2">⚖️ Admin - ผู้จัดการ</option>
                        <option value="1">🛠️ Enginear - ภารโรง</option>
                    </select>
                </div>

                <div class="d-grid gap-2 mt-4">
                    <button type="submit" class="btn btn-success btn-lg shadow-sm fw-bold rounded-pill">
                        <i class="bi bi-person-check-fill me-2"></i> สร้างบัญชีผู้ใช้งาน
                    </button>
                    <a href="index.php?page=user_manage" class="btn btn-link text-decoration-none text-secondary">
                        ยกเลิกและย้อนกลับ
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/**
 * UI Functions for Password Management
 */
function togglePassword() {
    const fields = [document.getElementById('password'), document.getElementById('confirm_password')];
    const icon = document.getElementById('toggleIcon');
    const isPass = fields[0].type === 'password';

    fields.forEach(f => f.type = isPass ? 'text' : 'password');
    icon.classList.toggle('bi-eye');
    icon.classList.toggle('bi-eye-slash');
}

function checkPasswordMatch() {
    const p = document.getElementById('password');
    const c = document.getElementById('confirm_password');
    
    if (c.value.length === 0) {
        c.classList.remove('is-invalid', 'is-valid');
        return;
    }

    if (p.value !== c.value || p.value.length < 6) {
        c.classList.add('is-invalid');
        c.classList.remove('is-valid');
    } else {
        c.classList.remove('is-invalid');
        c.classList.add('is-valid');
    }
}
</script>