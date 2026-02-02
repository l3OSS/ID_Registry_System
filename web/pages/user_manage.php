<?php
/**
 * Page: Staff Management
 * Access: Level 1 (Developer) & Level 2 (Admin)
 */

require_once 'core/auth.php';
require_once 'core/log.php';

// --- 1. Security Check ---
checkPermission(2);

$my_id = $_SESSION['user_id'];
$my_level = (int)$_SESSION['role_level'];

// --- 2. Action: Delete User (Strictly for Level 1) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = filter_input(INPUT_POST, 'delete_id', FILTER_VALIDATE_INT);
    
    // Fetch target user info for validation
    $stmt = $pdo->prepare("SELECT role_level, username FROM users WHERE id = ?");
    $stmt->execute([$delete_id]);
    $target = $stmt->fetch();

    if (!$target) {
        $_SESSION['error_msg'] = "ไม่พบผู้ใช้งานในระบบ";
    } elseif ($delete_id === $my_id) {
        $_SESSION['error_msg'] = "ไม่อนุญาตให้ลบบัญชีตัวเอง";
    } elseif ($my_level !== 1) {
        $_SESSION['error_msg'] = "สิทธิ์ของคุณไม่เพียงพอสำหรับการลบผู้ใช้งาน";
    } else {
        // Execute Deletion
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$delete_id]);
        writeLog($pdo, 'DELETE_USER', "ลบผู้ใช้: {$target['username']} (ID: $delete_id)");
        $_SESSION['success_msg'] = "ลบข้อมูลทีมงานเรียบร้อยแล้ว";
    }
    
    header("Location: index.php?page=user_manage");
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
            <h2 class="fw-bold text-dark mb-1"><i class="bi bi-shield-lock-fill text-primary"></i> จัดการทีมงาน</h2>
            <p class="text-muted small mb-0">บริหารจัดการบัญชีผู้ใช้และระดับสิทธิ์การเข้าถึงระบบ</p>
        </div>
        <?php if($my_level === 1): ?>
            <a href="index.php?page=user_add" class="btn btn-primary rounded-pill shadow-sm px-4">
                <i class="bi bi-person-plus-fill"></i> เพิ่มทีมงานใหม่
            </a>
        <?php endif; ?>
    </div>

    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="alert alert-success border-0 shadow-sm alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4 py-3" width="30%">ทีมงาน</th>
                        <th width="25%">ระดับสิทธิ์ (Role)</th>
                        <th width="15%">สถานะ</th>
                        <th class="text-end pe-4">เครื่องมือจัดการ</th>
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
                                        <span class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size: 0.65rem;">บัญชีของคุณ</span>
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
                                ? '<span class="text-success small"><i class="bi bi-patch-check-fill"></i> ปกติ</span>' 
                                : '<span class="text-danger small"><i class="bi bi-slash-circle-fill"></i> ระงับ</span>'; ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="btn-group shadow-sm rounded-pill overflow-hidden">
                                <?php if ($can_edit): ?>
                                    <a href="index.php?page=user_edit&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-warning px-3" title="แก้ไข">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if ($can_delete): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('❗ ยืนยันการลบทีมงาน? ข้อมูลนี้จะหายไปจากระบบทันที');">
                                        <input type="hidden" name="delete_id" value="<?php echo $u['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger px-3" title="ลบ">
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
        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-shield-shaded text-primary"></i> ข้อมูลระดับสิทธิ์การจัดการ</h6>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="fw-bold text-danger">EngiNear</div>
                <small class="text-muted">ควบคุมระบบได้ทั้งหมด เพิ่ม/ลบ และแก้ไขทีมงานได้ทุกระดับสิทธิ์</small>
            </div>
            <div class="col-md-4">
                <div class="fw-bold text-primary">Admin</div>
                <small class="text-muted">จัดการข้อมูลผู้พัก และจัดการทีมงานระดับทะเบียนได้</small>
            </div>
            <div class="col-md-4">
                <div class="fw-bold text-info text-dark">Regis</div>
                <small class="text-muted">ดูแลงานทะเบียนรายวัน และแก้ไขข้อมูลโปรไฟล์ของตนเองเท่านั้น</small>
            </div>
        </div>
    </div>
</div>