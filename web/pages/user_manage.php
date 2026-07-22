<?php
/**
 * Page: Staff Management
 * Access: Level 1 (Developer) & Level 2 (Admin)
 */

require_once 'core/auth.php';
require_once 'core/log.php';

// --- 1. Access & Security Check ---
$can_access = userCan('users.view');

if (!$can_access) denyAccess(t('user.err_no_access'));

$my_id = $_SESSION['user_id'];
$my_level = (int)$_SESSION['role_level'];

// --- 2. Action: Delete User (Strictly for Level 1) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = filter_input(INPUT_POST, 'delete_id', FILTER_VALIDATE_INT);
    
    // 1. ตรวจสอบเบื้องต้น
    if ($delete_id === $my_id) {
        $_SESSION['error_msg'] = t('umanage.err_no_self_delete');
    } elseif ($my_level !== 1) {
        $_SESSION['error_msg'] = t('umanage.err_insufficient');
    } elseif (isLastEngineer($pdo, $delete_id)) {
        $_SESSION['error_msg'] = t('umanage.err_last_engineer');
    } else {
        try {
            // 2. ดึงข้อมูลก่อนลบเพื่อทำ Log
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$delete_id]);
            $target = $stmt->fetch();

            if ($target) {
                // 3. ปลอดภัยกว่าด้วยการระบุเงื่อนไขใน SQL เพิ่ม (ป้องกันการเจาะข้ามสิทธิ์)
                $pdo->prepare("DELETE FROM users WHERE id = ? AND id != ?")->execute([$delete_id, $my_id]);
                writeLog($pdo, 'DELETE_USER', "ลบผู้ใช้: {$target['username']} (ID: $delete_id)");
                $_SESSION['success_msg'] = t('umanage.delete_success');
            } else {
                $_SESSION['error_msg'] = t('umanage.err_not_found');
            }
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = t('uedit.err_technical');
        }
    }
    
    // 🟢 แก้ไขปัญหา Headers already sent ด้วย JS
    echo "<script>window.location.href='index.php?page=user_manage';</script>";
    exit;
}

// --- 3. Data Retrieval ---
// Join with roles table to get descriptions and hierarchical names

$sql = "SELECT u.*, r.role_name, r.description 
        FROM users u 
        INNER JOIN roles r ON u.role_level = r.id 
        ORDER BY u.role_level ASC, u.id ASC";
$users = $pdo->query($sql)->fetchAll();
?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="bi bi-shield-lock-fill text-primary"></i> <?php echo e('umanage.title'); ?></h2>
            <p class="text-muted small mb-0"><?php echo e('umanage.subtitle'); ?></p>
        </div>
        <?php if($my_level === 1): ?>
            <a href="index.php?page=user_add" class="btn btn-primary rounded-pill shadow-sm px-4">
                <i class="bi bi-person-plus-fill"></i> <?php echo e('umanage.add_new'); ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="alert alert-success border-0 shadow-sm alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="alert alert-danger border-0 shadow-sm alert-dismissible fade show mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4 py-3" width="30%"><?php echo e('umanage.col_member'); ?></th>
                        <th width="25%"><?php echo e('umanage.col_role'); ?></th>
                        <th width="15%"><?php echo e('umanage.col_status'); ?></th>
                        <th class="text-end pe-4"><?php echo e('umanage.col_tools'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): 
                        $is_me = ((int)$u['id'] === $my_id);
                        $target_level = (int)$u['role_level'];

                        // Edit Logic: Level 1 edits everyone | Me | Level 2 edits Level 3
                        $can_edit = ($my_level === 1) || ($is_me) || ($my_level === 2 && $target_level === 3);
                        // Delete Logic: Level 1 only | No self-delete
                        $can_delete = ($my_level === 1 && !$is_me);
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center">
                                <img src="assets/Avatar/<?php echo htmlspecialchars($u['avatar_name'] ?: 'default.png'); ?>" 
                                     class="rounded-circle border border-2 shadow-sm me-3" 
                                     style="width: 48px; height: 48px; object-fit: cover;">
                                <div>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($u['nickname'] ?: 'Guest User'); ?></div>
                                    <small class="text-muted">@<?php echo htmlspecialchars($u['username']); ?></small>
                                    <?php if($is_me): ?>
                                        <span class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size: 0.65rem;"><?php echo e('umanage.your_account'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php 
                                $badgeClass = ($target_level === 1) ? 'bg-danger' : (($target_level === 2) ? 'bg-primary' : 'bg-info text-dark');
                            ?>
                            <span class="badge rounded-pill <?php echo $badgeClass; ?> mb-1">
                                <?php echo htmlspecialchars($u['role_name']); ?>
                            </span>
                            <div class="text-muted" style="font-size: 0.75rem; line-height: 1.2;">
                                <?php echo htmlspecialchars($u['description']); ?>
                            </div>
                        </td>
                        <td>
                            <?php echo $u['is_active'] 
                                ? '<span class="text-success small"><i class="bi bi-patch-check-fill"></i> ' . e('umanage.status_active') . '</span>'
                                : '<span class="text-danger small"><i class="bi bi-slash-circle-fill"></i> ' . e('umanage.status_suspended') . '</span>'; ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="btn-group shadow-sm rounded-pill overflow-hidden">
                                <?php if ($can_edit): ?>
                                    <a href="index.php?page=user_edit&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-warning px-3" title="<?php echo e('btn.edit'); ?>">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if ($can_delete): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo e('umanage.confirm_delete'); ?>');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_id" value="<?php echo $u['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger px-3" title="<?php echo e('btn.delete'); ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!$can_edit && !$can_delete): ?>
                                    <span class="btn btn-sm btn-light disabled px-3 text-muted"><i class="bi bi-lock-fill"></i></span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="mt-4 p-4 bg-white rounded-4 shadow-sm border">
        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-shield-shaded text-primary"></i> <?php echo e('umanage.roles_info'); ?></h6>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="fw-bold text-danger">EngiNear</div>
                <small class="text-muted"><?php echo e('umanage.role_eng_desc'); ?></small>
            </div>
            <div class="col-md-4">
                <div class="fw-bold text-primary">Admin</div>
                <small class="text-muted"><?php echo e('umanage.role_admin_desc'); ?></small>
            </div>
            <div class="col-md-4">
                <div class="fw-bold text-info text-dark">Regis</div>
                <small class="text-muted"><?php echo e('umanage.role_regis_desc'); ?></small>
            </div>
        </div>
    </div>
</div>