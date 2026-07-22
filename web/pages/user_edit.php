<?php
/**
 * Page: Edit Staff Member Profile
 * Access: Level 1 (Developer) & Level 2 (Admin)
 */

require_once 'core/auth.php';
require_once 'core/security.php';
require_once 'core/log.php';

// --- 1. Access & Security Check ---
$can_access = userCan('users.edit');

if (!$can_access) denyAccess(t('user.err_no_access'));

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$my_role = (int)$_SESSION['role_level'];
$my_id = (int)$_SESSION['user_id'];

// Fetch target user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    echo "<div class='container mt-5'><div class='alert alert-danger shadow-sm'>" . e('uedit.not_found') . "</div></div>";
    exit;
}

/**
 * 🛡️ Hierarchy Check (ฝั่งเซิร์ฟเวอร์) — กันยิง URL ?page=user_edit&id=<คนอื่น>
 * canManage: EngiNear จัดการได้หมด · ตัวเองได้ · คนอื่นต้องระดับต่ำกว่า (Admin แก้ Admin คนอื่นไม่ได้)
 */
if (!canManage((int)$target['role_level'], (int)$target['id'])) {
    echo "<div class='container mt-5'><div class='alert alert-danger shadow-sm'>" . e('uedit.no_permission') . "</div></div>";
    exit;
}

// --- 2. Action: Save Changes ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username   = trim($_POST['username'] ?? '');
    $nickname   = trim($_POST['nickname'] ?? '');
    $password   = $_POST['user_pass_new'] ?? '';
    $confirm    = $_POST['user_pass_confirm'] ?? '';
    
    // เฉพาะ EngiNear เปลี่ยน role ได้ · ตรวจว่าค่าที่ส่งมาเป็น role ที่มีจริง ไม่งั้นคงค่าเดิม (กัน POST ปลอม)
    $role_level = (int)$target['role_level'];
    if ($my_role === 1) {
        $posted = (int)($_POST['role_level'] ?? 0);
        if (isset(ROLE_NAMES[$posted])) $role_level = $posted;
    }

    // กันลดระดับ EngiNear คนสุดท้าย (ไม่งั้นระบบไม่เหลือผู้ดูแลสูงสุด)
    if ($role_level !== 1 && isLastEngineer($pdo, $id)) {
        echo "<script>alert(" . json_encode(t('uedit.err_last_engineer'), JSON_UNESCAPED_UNICODE) . "); history.back();</script>"; exit;
    }
    // กันย้ายบัญชีเข้าบทบาทที่โควตาเต็ม (ไม่นับตัวเขาเองถ้าอยู่บทบาทนั้นแล้ว)
    if ($role_level !== (int)$target['role_level'] && roleQuotaFull($pdo, $role_level, $id)) {
        echo "<script>alert(" . json_encode(t('uedit.err_quota', ['role' => ROLE_NAMES[$role_level]]), JSON_UNESCAPED_UNICODE) . "); history.back();</script>"; exit;
    }

    // Duplicate Username Check
    $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id != ?");
    $stmt->execute([$username, $id]);    

    if ($stmt->fetch()) {
        echo "<script>alert(" . json_encode(t('uedit.err_dup_username'), JSON_UNESCAPED_UNICODE) . "); history.back();</script>";
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
            if (($pwErr = passwordPolicyError($password)) !== null) {
                echo "<script>alert(" . json_encode($pwErr, JSON_UNESCAPED_UNICODE) . "); history.back();</script>";
                exit;
            }
            if ($password !== $confirm) {
                echo "<script>alert(" . json_encode(t('uedit.err_pw_mismatch'), JSON_UNESCAPED_UNICODE) . "); history.back();</script>";
                exit;
            }
            $sql .= ", password_hash = :password";
            $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = :id";
        
        try {
            $pdo->prepare($sql)->execute($params);
            writeLog($pdo, 'EDIT_USER', "แก้ไขข้อมูลผู้ใช้: $username (ID: $id)");
            echo "<script>alert(" . json_encode(t('uedit.save_success'), JSON_UNESCAPED_UNICODE) . "); window.location='index.php?page=user_manage';</script>";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            echo "<script>alert(" . json_encode(t('uedit.err_technical'), JSON_UNESCAPED_UNICODE) . ");</script>";
        }
    }
}
?>

<div class="container mt-4 mb-5">
    <div class="card shadow-sm mx-auto border-0 rounded-4 overflow-hidden" style="max-width: 600px;">
        <div class="card-header bg-warning text-dark py-3 border-0">
            <h5 class="mb-0 fw-bold"><i class="bi bi-pencil-square"></i> <?php echo e('uedit.card_title'); ?></h5>
        </div>
        <div class="card-body p-4 p-md-5">
            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted"><?php echo e('uedit.username_label'); ?></label>
                    <input type="text" name="username" class="form-control border-2" 
                           value="<?php echo htmlspecialchars($target['username']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted"><?php echo e('uedit.nickname_label'); ?></label>
                    <input type="text" name="nickname" class="form-control border-2" 
                           value="<?php echo htmlspecialchars($target['nickname']); ?>">
                </div>

                
                <div class="mb-4">
                    <label class="form-label small fw-bold text-primary"><?php echo e('uedit.role_label'); ?></label>
                    <select name="role_level" class="form-select border-2 shadow-sm" <?php echo ($my_role !== 1) ? "disabled" : ""; ?>>
                        <option value="1" <?php echo ((int)$target['role_level'] === 1) ? 'selected' : ''; ?>><?php echo e('uedit.role_1'); ?></option>
                        <option value="2" <?php echo ((int)$target['role_level'] === 2) ? 'selected' : ''; ?>><?php echo e('uedit.role_2'); ?></option>
                        <option value="3" <?php echo ((int)$target['role_level'] === 3) ? 'selected' : ''; ?>><?php echo e('uedit.role_3'); ?></option>
                    </select>
                    <?php if($my_role !== 1): ?>
                        <div class="form-text text-danger italic"><i class="bi bi-lock-fill"></i> <?php echo e('uedit.only_engineer'); ?></div>
                    <?php endif; ?>
                </div>

                <hr class="my-4 opacity-50">

                <div class="p-3 rounded-3 bg-light border mb-4">
                    <h6 class="fw-bold text-danger mb-3"><i class="bi bi-shield-lock"></i> <?php echo e('uedit.change_pw'); ?></h6>
                    <div class="mb-3">
                        <label class="form-label small fw-bold"><?php echo e('uedit.new_pw_label'); ?></label>
                        <input type="password" name="user_pass_new" id="password" class="form-control border-0 shadow-sm"
                               minlength="6" autocomplete="new-password" placeholder="<?php echo e('uedit.new_pw_ph'); ?>">
                        <div class="form-text small"><?php echo e('user.password_ph'); ?></div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold"><?php echo e('uedit.confirm_new_label'); ?></label>
                        <input type="password" name="user_pass_confirm" id="confirm_password" class="form-control border-0 shadow-sm"
                               minlength="6" oninput="checkPasswordMatch()">
                        <div class="invalid-feedback"><?php echo e('uedit.pw_invalid_hint'); ?></div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold shadow-sm">
                        <i class="bi bi-check-circle-fill"></i> <?php echo e('uedit.save_btn'); ?>
                    </button>
                    <a href="index.php?page=user_manage" class="btn btn-link text-secondary text-decoration-none">
                        <?php echo e('uedit.back_no_save'); ?>
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
const L = <?php echo json_encode([
    'pw_too_short' => t('uedit.js_pw_too_short'),
    'pw_mismatch'  => t('uedit.js_pw_mismatch'),
], JSON_UNESCAPED_UNICODE); ?>;
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
        fb.textContent = p.value.length < 6 ? L.pw_too_short : L.pw_mismatch;
    } else {
        c.classList.remove('is-invalid');
        c.classList.add('is-valid');
    }
}
</script>