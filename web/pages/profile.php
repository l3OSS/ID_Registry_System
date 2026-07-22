<?php
/**
 * Page: User Profile Management
 * Allows users to update their nickname, avatar, and password securely.
 */
checkLogin(); 

$message = '';
$user_id = $_SESSION['user_id'];

// 1. Fetch Current User Data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// 2. Prepare Avatar Gallery
$avatar_dir = 'assets/Avatar/';
$avatars = [];
if (is_dir($avatar_dir)) {
    // ดึงเฉพาะไฟล์รูปภาพและจัดเรียงชื่อไฟล์
    $files = scandir($avatar_dir);
    $avatars = array_filter($files, function($file) {
        return preg_match('/\.(jpg|jpeg|png)$/i', $file);
    });
}

// 3. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nickname = trim($_POST['nickname'] ?? '');
    $password = $_POST['password'] ?? ''; 
    $avatar_name = $_POST['avatar_name'] ?? 'default.png';
    
    // Validate Data
    if (empty($nickname)) {
        $message = '<div class="alert alert-danger shadow-sm">' . e('prof.err_nickname') . '</div>';
    } elseif (!empty($password) && ($pwErr = passwordPolicyError($password)) !== null) {
        $message = '<div class="alert alert-danger shadow-sm">❌ ' . htmlspecialchars($pwErr) . '</div>';
    } else {
        try {
            $pdo->beginTransaction();

            $sql = "UPDATE users SET nickname = :nickname, avatar_name = :avatar";
            $params = [
                ':nickname' => $nickname, 
                ':avatar'   => $avatar_name,
                ':id'       => $user_id
            ];

            // Only update password if provided
            if (!empty($password)) {
                $sql .= ", password_hash = :password";
                $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Sync Session Data for immediate UI update
            $_SESSION['nickname'] = $nickname;
            $_SESSION['avatar_name'] = $avatar_name; // อัปเดตรูปใน Header ทันที
            
            $pdo->commit();
            $message = '<div class="alert alert-success shadow-sm">' . e('prof.save_success') . '</div>';
            
            // Re-fetch fresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            writeLog($pdo, 'PROFILE_UPDATE', "User updated profile and avatar (UID: $user_id)");

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // ไม่เปิดเผยรายละเอียด error ให้ผู้ใช้ (อาจเผยชื่อตาราง/คอลัมน์/SQL) — log ไว้ฝั่งเซิร์ฟเวอร์แทน
            error_log("Profile Update Failure (UID: $user_id): " . $e->getMessage());
            $message = '<div class="alert alert-danger shadow-sm">' . e('prof.err_save') . '</div>';
        }
    }
}
?>

<div class="container mt-4 mb-5">
    <div class="card shadow-sm mx-auto border-0 rounded-4 overflow-hidden" style="max-width: 650px;">
        <div class="card-header bg-primary text-white text-center py-4 border-0">
            <h4 class="mb-0 fw-bold"><i class="bi bi-person-badge-fill"></i> <?php echo e('prof.title'); ?></h4>
        </div>
        <div class="card-body p-4 p-md-5">
            <?php echo $message; ?>
            
            <form method="POST" id="profileForm">
                <?= csrf_field() ?>
                <div class="text-center mb-5 position-relative">
                    <div class="d-inline-block position-relative">
                        <img src="assets/Avatar/<?php echo htmlspecialchars($user['avatar_name'] ?? 'default.png'); ?>" 
                             id="current_avatar" class="rounded-circle border border-4 border-white shadow" 
                             style="width: 130px; height: 130px; object-fit: cover; transition: 0.3s;">
                        <span class="position-absolute bottom-0 end-0 bg-primary text-white rounded-circle p-2 shadow-sm border border-2 border-white">
                            <i class="bi bi-camera-fill"></i>
                        </span>
                    </div>
                    <p class="text-muted small mt-3"><?php echo e('prof.avatar_caption'); ?></p>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted"><?php echo e('uedit.username_label'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-0"><i class="bi bi-lock-fill text-muted"></i></span>
                            <input type="text" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-primary"><?php echo e('prof.nickname_label'); ?></label>
                        <input type="text" name="nickname" class="form-control border-2" value="<?php echo htmlspecialchars($user['nickname'] ?? ''); ?>" required>
                    </div>
                </div>

                
                <div class="mb-4">
                    <label class="form-label small fw-bold text-muted mb-2"><?php echo e('prof.choose_avatar'); ?></label>
                    <div class="avatar-gallery d-flex flex-wrap gap-2 p-3 border rounded-3 bg-light overflow-auto" style="max-height: 180px;">
                        <?php foreach ($avatars as $avatar): ?>
                            <div class="avatar-item">
                                <input type="radio" name="avatar_name" value="<?php echo $avatar; ?>" 
                                       id="av_<?php echo $avatar; ?>" class="btn-check"
                                       <?php echo ($user['avatar_name'] == $avatar) ? 'checked' : ''; ?>
                                       onchange="updateAvatarPreview(this.value)">
                                <label class="avatar-label btn btn-outline-primary border-2 p-1 rounded-circle" for="av_<?php echo $avatar; ?>">
                                    <img src="assets/Avatar/<?php echo $avatar; ?>" class="rounded-circle" style="width: 50px; height: 50px; object-fit: cover;">
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <hr class="my-4 opacity-50">
                
                <div class="mb-4 bg-danger-subtle p-3 rounded-3 border border-danger-subtle">
                    <label class="form-label fw-bold text-danger"><i class="bi bi-shield-lock"></i> <?php echo e('prof.change_pw'); ?></label>
                    <input type="password" name="password" class="form-control border-danger border-opacity-25" minlength="6" placeholder="<?php echo e('prof.pw_ph'); ?>">
                    <div class="form-text text-danger small"><i class="bi bi-exclamation-circle"></i> <?php echo e('prof.pw_hint'); ?></div>
                </div>

                <div class="d-grid gap-2 mt-4">
                    <button type="submit" class="btn btn-primary btn-lg shadow-sm rounded-pill fw-bold">
                        <i class="bi bi-check2-circle"></i> <?php echo e('uedit.save_btn'); ?>
                    </button>
                    <a href="index.php?page=dashboard" class="btn btn-link text-decoration-none text-secondary">
                        <i class="bi bi-chevron-left"></i> <?php echo e('prof.back_home'); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    /* 🎨 UI Enhancements */
    .avatar-gallery::-webkit-scrollbar { width: 6px; }
    .avatar-gallery::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 10px; }
    
    .avatar-label { transition: transform 0.2s, box-shadow 0.2s; }
    .avatar-label:hover { transform: scale(1.1); }
    
    .btn-check:checked + .avatar-label {
        background-color: #0d6efd !important;
        border-color: #0d6efd !important;
        box-shadow: 0 0 10px rgba(13, 110, 253, 0.3);
        transform: scale(1.15);
    }
</style>

<script>
/**
 * Live update of avatar preview
 */
function updateAvatarPreview(fileName) {
    const preview = document.getElementById('current_avatar');
    preview.style.opacity = '0.3'; // Fade effect
    setTimeout(() => {
        preview.src = 'assets/Avatar/' + fileName;
        preview.style.opacity = '1';
    }, 150);
}
</script>