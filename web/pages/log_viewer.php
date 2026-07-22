<?php
/**
 * Page: System Log Viewer
 * Displays activity history with pagination and clear-log functionality for Developers.
 */

// --- 1. Access Control ---
require_once 'core/auth.php';
require_once 'core/rbac.php';
requirePermission('logs.view'); // ดู log ได้ทุก role ที่ล็อกอิน

// --- 2. Action: Clear Logs (P8: เฉพาะ engineer + ปลด escape hatch ของ trigger append-only) ---
if (isset($_POST['clear_logs']) && userCan('logs.delete')) {
    try {
        $pdo->beginTransaction();

        // ปลดล็อก trigger append-only ชั่วคราวสำหรับ session นี้ (มีเฉพาะเส้นทาง engineer)
        $pdo->exec("SET @allow_log_purge = 1");

        // 1. Delete all existing logs
        $pdo->exec("DELETE FROM activity_logs");

        // ปิด escape hatch กลับทันทีหลังลบ
        $pdo->exec("SET @allow_log_purge = 0");

        // 2. Write a new log to record this cleanup action (INSERT ยังทำได้)
        writeLog($pdo, 'CLEAR_LOGS', "ผู้ดูแลระบบระดับสูงสั่งล้างประวัติการใช้งานทั้งหมดในระบบ");

        $pdo->commit();
        echo "<script>alert(" . json_encode(t('log.clear_success'), JSON_UNESCAPED_UNICODE) . "); window.location='index.php?page=log_viewer';</script>";
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Log Cleanup Error: " . $e->getMessage());
        echo "<script>alert(" . json_encode(t('log.clear_error'), JSON_UNESCAPED_UNICODE) . ");</script>";
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
                <i class="bi bi-clock-history text-primary"></i> <?php echo e('log.title'); ?>
            </h3>
            <p class="text-muted small mb-0"><?php echo e('log.subtitle', ['limit' => $limit]); ?></p>
        </div>
        
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                <i class="bi bi-arrow-clockwise"></i> <?php echo e('log.refresh'); ?>
            </button>
            <?php if (userCan('logs.delete')): ?>
            <form method="POST" onsubmit="return confirm('<?php echo e('log.confirm_clear'); ?>');">
                <?= csrf_field() ?>
                <button type="submit" name="clear_logs" class="btn btn-danger btn-sm shadow-sm">
                    <i class="bi bi-trash3"></i> <?php echo e('log.clear_all'); ?>
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
                            <th class="ps-3" width="150"><?php echo e('log.col_datetime'); ?></th>
                            <th width="150"><?php echo e('log.col_user'); ?></th>
                            <th width="120"><?php echo e('log.col_action'); ?></th>
                            <th><?php echo e('log.col_details'); ?></th>
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
                                <p class="text-muted mt-2"><?php echo e('log.no_logs'); ?></p>
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
                <a class="page-link" href="index.php?page=log_viewer&p=<?php echo $page-1; ?>"><?php echo e('log.prev'); ?></a>
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
                <a class="page-link" href="index.php?page=log_viewer&p=<?php echo $page+1; ?>"><?php echo e('log.next'); ?></a>
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