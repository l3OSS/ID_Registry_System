<?php
/**
 * Page: System Log Viewer
 * Displays activity history with pagination and clear-log functionality for Developers.
 */

// --- 1. Access Control ---
require_once 'core/auth.php';
// --- 1. Access & Security Check ---
if (!isset($_SESSION['role_level']) || (int)$_SESSION['role_level'] > 3) {
    // หากไม่ใช่แอดมิน (เช่น เป็นคนทั่วไปที่หลุดเข้ามา) ให้เด้งออกหรือแสดงข้อความ
    header('Location: index.php');
    exit();
}



// --- 2. Action: Clear Logs (Developer Only) ---
if (isset($_POST['clear_logs']) && ($_SESSION['role_level'] ?? 99) == 1) {
    try {
        $pdo->beginTransaction();
        
        // 1. Delete all existing logs
        $pdo->exec("DELETE FROM activity_logs");

        // 2. Write a new log to record this cleanup action
        writeLog($pdo, 'CLEAR_LOGS', "ผู้ดูแลระบบระดับสูงสั่งล้างประวัติการใช้งานทั้งหมดในระบบ");

        $pdo->commit();
        echo "<script>alert('ล้างประวัติเรียบร้อยแล้ว'); window.location='index.php?page=log_viewer';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Log Cleanup Error: " . $e->getMessage());
        echo "<script>alert('เกิดข้อผิดพลาดในการล้างข้อมูล');</script>";
    }
}

// --- 3. Pagination Logic ---
$limit = 50;
$page = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

try {
    // Total row count for pagination
    $total_rows = $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
    $total_pages = ceil($total_rows / $limit);

    // Fetch logs with user details
    $sql = "SELECT logs.*, u.username, u.nickname 
            FROM activity_logs AS logs 
            LEFT JOIN users AS u ON logs.user_id = u.id 
            ORDER BY logs.created_at DESC 
            LIMIT :limit OFFSET :offset";
            
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Log Retrieval Error: " . $e->getMessage());
    $logs = [];
}
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="text-dark fw-bold mb-0">
                <i class="bi bi-clock-history text-primary"></i> ประวัติการใช้งานระบบ
            </h3>
            <p class="text-muted small mb-0">แสดงรายการกิจกรรมล่าสุด (หน้าละ <?php echo $limit; ?> รายการ)</p>
        </div>
        
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                <i class="bi bi-arrow-clockwise"></i> รีเฟรช
            </button>
            <?php if (($_SESSION['role_level'] ?? 99) == 1): ?>
            <form method="POST" onsubmit="return confirm('⚠️ ยืนยันการลบประวัติกิจกรรมทั้งหมด? ข้อมูลนี้จะไม่สามารถกู้คืนได้');">
                <button type="submit" name="clear_logs" class="btn btn-danger btn-sm shadow-sm">
                    <i class="bi bi-trash3"></i> ล้างประวัติทั้งหมด
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    
    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" width="150">วัน-เวลา</th>
                            <th width="150">ผู้ใช้งาน</th>
                            <th width="120">กิจกรรม</th>
                            <th>รายละเอียด</th>
                            <th width="130" class="text-center">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): foreach ($logs as $log): ?>
                        <tr>
                            <td class="ps-3">
                                <span class="d-block fw-bold"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></span>
                                <small class="text-muted"><?php echo date('d/m/Y', strtotime($log['created_at'])); ?></small>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($log['nickname'] ?: 'System'); ?></div>
                                <small class="text-muted">@<?php echo htmlspecialchars($log['username'] ?? 'auto'); ?></small>
                            </td>
                            <td>
                                <?php 
                                    $action = $log['action_type'];
                                    $badge = 'bg-secondary';
                                    if (preg_match('/INSERT|SAVE|CREATE/i', $action)) $badge = 'bg-success';
                                    elseif (preg_match('/UPDATE|EDIT/i', $action)) $badge = 'bg-warning text-dark';
                                    elseif (preg_match('/DELETE|CLEAR|REMOVE/i', $action)) $badge = 'bg-danger';
                                    elseif (preg_match('/LOGIN|AUTH/i', $action)) $badge = 'bg-primary';
                                ?>
                                <span class="badge <?php echo $badge; ?> px-2 py-1" style="font-size: 0.7rem;">
                                    <?php echo $action; ?>
                                </span>
                            </td>
                            <td>
                                <div class="text-wrap" style="max-width: 500px;"><?php echo htmlspecialchars($log['details']); ?></div>
                            </td>
                            <td class="text-center">
                                <code class="small text-secondary"><?php echo $log['ip_address'] ?: '-'; ?></code>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <i class="bi bi-inbox text-muted display-4"></i>
                                <p class="text-muted mt-2">ไม่พบประวัติการใช้งานในขณะนี้</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <nav class="mt-4">
        <ul class="pagination pagination-sm justify-content-center shadow-sm">
            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                <a class="page-link" href="index.php?page=log_viewer&p=<?php echo $page-1; ?>">ก่อนหน้า</a>
            </li>
            <?php 
            $range = 2;
            for ($i = 1; $i <= $total_pages; $i++): 
                if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)):
            ?>
                <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                    <a class="page-link" href="index.php?page=log_viewer&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
            <?php elseif ($i == $page - $range - 1 || $i == $page + $range + 1): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; endfor; ?>
            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                <a class="page-link" href="index.php?page=log_viewer&p=<?php echo $page+1; ?>">ถัดไป</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<style>
    .table th { font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; font-size: 0.8rem; }
    .pagination .page-link { color: #495057; }
    .pagination .active .page-link { background-color: #0d6efd; border-color: #0d6efd; }
</style>