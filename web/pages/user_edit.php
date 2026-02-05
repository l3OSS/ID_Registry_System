<?php
/**
 * Page: Edit Staff Member Profile
 * Access: Level 1 (Developer) & Level 2 (Admin)
 */

require_once 'core/auth.php';
require_once 'core/security.php';
require_once 'core/log.php';

// --- 1. Access & Security Check ---
$can_access = (isset($_SESSION['role_level']) && ((int)$_SESSION['role_level'] === 1 || (int)$_SESSION['role_level'] === 2));

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

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$my_role = (int)$_SESSION['role_level'];
$my_id = (int)$_SESSION['user_id'];

// Fetch target user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    echo "<div class='container mt-5'><div class='alert alert-danger shadow-sm'>❌ ไม่พบข้อมูลผู้ใช้งานในระบบ</div></div>"; 
    exit;
}

/**
 * 🛡️ Hierarchy Check
 * Admin (2) cannot edit EngiNear (1) accounts.
 */
if ($my_role === 2 && (int)$target['role_level'] === 1) {
    echo "<div class='container mt-5'><div class='alert alert-danger shadow-sm'>คุณไม่มีสิทธิ์แก้ไขข้อมูลระดับ EngiNear</div></div>"; 
    exit;
}

// --- 2. Action: Save Changes ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username   = trim($_POST['username'] ?? '');
    $nickname   = trim($_POST['nickname'] ?? '');
    $password   = $_POST['user_pass_new'] ?? '';
    $confirm    = $_POST['user_pass_confirm'] ?? '';
    
    // Only EngiNear can change roles
    $role_level = ($my_role === 1) ? (int)$_POST['role_level'] : (int)$target['role_level'];

    // Duplicate Username Check
    $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id != ?");
    $stmt->execute([$username, $id]);    

    if ($stmt->fetch()) {
        echo "<script>alert('Username นี้ถูกใช้โดยคนอื่นแล้ว'); history.back();</script>";
        exit;
    } else {
        // Prepare Update Statement
        $sql = "UPDATE users SET username = :username, nickname = :nickname, role_level = :role_level";
        $params = [
            ':username'   => $username, 
            ':nickname'   => $nickname, 
            ':role_level' => $role_level,
            ':id'         => $id
        ];

        // Process Password Change if provided
        if (!empty($password)) {
            if (strlen($password) < 6 || $password !== $confirm) {
                echo "<script>alert('รหัสผ่านต้องมี 6 ตัวขึ้นไปและตรงกัน'); history.back();</script>";
                exit;
            }
            $sql .= ", password_hash = :password";
            $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = :id";
        
        try {
            $pdo->prepare($sql)->execute($params);
            writeLog($pdo, 'EDIT_USER', "แก้ไขข้อมูลผู้ใช้: $username (ID: $id)");
            echo "<script>alert('บันทึกข้อมูลสำเร็จ'); window.location='index.php?page=user_manage';</script>";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            echo "<script>alert('เกิดข้อผิดพลาดทางเทคนิค');</script>";
        }
    }
}
?>

<div class="container mt-4 mb-5">
    <div class="card shadow-sm mx-auto border-0 rounded-4 overflow-hidden" style="max-width: 600px;">
        <div class="card-header bg-warning text-dark py-3 border-0">
            <h5 class="mb-0 fw-bold"><i class="bi bi-pencil-square"></i> แก้ไขข้อมูลทีมงาน</h5>
        </div>
        <div class="card-body p-4 p-md-5">
            <form method="POST" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">ชื่อผู้ใช้ (Username)</label>
                    <input type="text" name="username" class="form-control border-2" 
                           value="<?php echo htmlspecialchars($target['username']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">ชื่อเล่น (Nickname)</label>
                    <input type="text" name="nickname" class="form-control border-2" 
                           value="<?php echo htmlspecialchars($target['nickname']); ?>">
                </div>

                
                <div class="mb-4">
                    <label class="form-label small fw-bold text-primary">ระดับสิทธิ์เข้าถึงระบบ</label>
                    <select name="role_level" class="form-select border-2 shadow-sm" <?php echo ($my_role !== 1) ? "disabled" : ""; ?>>
                        <option value="1" <?php echo ((int)$target['role_level'] === 1) ? 'selected' : ''; ?>>EngiNear ภารโรง</option>
                        <option value="2" <?php echo ((int)$target['role_level'] === 2) ? 'selected' : ''; ?>>Admin ผู้จัดการ</option>
                        <option value="3" <?php echo ((int)$target['role_level'] === 3) ? 'selected' : ''; ?>>Regis งานทะเบียน</option>
                    </select>
                    <?php if($my_role !== 1): ?>
                        <div class="form-text text-danger italic"><i class="bi bi-lock-fill"></i> เฉพาะ EngiNear เท่านั้นที่สามารถเปลี่ยนระดับสิทธิ์ได้</div>
                    <?php endif; ?>
                </div>

                <hr class="my-4 opacity-50">

                <div class="p-3 rounded-3 bg-light border mb-4">
                    <h6 class="fw-bold text-danger mb-3"><i class="bi bi-shield-lock"></i> เปลี่ยนรหัสผ่าน (หากต้องการ)</h6>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">รหัสผ่านใหม่</label>
                        <input type="password" name="user_pass_new" id="password" class="form-control border-0 shadow-sm" 
                               minlength="6" autocomplete="new-password" placeholder="ทิ้งว่างไว้หากไม่ต้องการเปลี่ยน">
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold">ยืนยันรหัสผ่านใหม่</label>
                        <input type="password" name="user_pass_confirm" id="confirm_password" class="form-control border-0 shadow-sm" 
                               minlength="6" oninput="checkPasswordMatch()">
                        <div class="invalid-feedback">รหัสผ่านไม่ตรงกันหรือสั้นเกินไป</div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold shadow-sm">
                        <i class="bi bi-check-circle-fill"></i> บันทึกการเปลี่ยนแปลง
                    </button>
                    <a href="index.php?page=user_manage" class="btn btn-link text-secondary text-decoration-none">
                        ย้อนกลับโดยไม่บันทึก
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/**
 * Real-time password matching validation
 */
function checkPasswordMatch() {
    const p = document.getElementById('password');
    const c = document.getElementById('confirm_password');
    const fb = c.nextElementSibling;

    if (p.value.length === 0 && c.value.length === 0) {
        c.classList.remove('is-invalid', 'is-valid');
        return;
    }

    if (p.value.length < 6 || p.value !== c.value) {
        c.classList.add('is-invalid');
        c.classList.remove('is-valid');
        fb.textContent = p.value.length < 6 ? 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร' : 'รหัสผ่านไม่ตรงกัน';
    } else {
        c.classList.remove('is-invalid');
        c.classList.add('is-valid');
    }
}
</script>