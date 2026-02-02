<?php
/**
 * Page: Dashboard
 * Summary statistics and quick access menu for staff.
 */

// --- 1. Load Dependencies & Security ---
require_once 'core/auth.php';
require_once 'core/functions.php';

// Ensure user is logged in
checkLogin();

/** * --- 2. Data Retrieval ---
 * Optimization: Use a single TRY-CATCH block for database operations
 */
try {
    // Basic Stay Statistics
    $count_active    = $pdo->query("SELECT COUNT(*) FROM stay_history WHERE status = 'Active'")->fetchColumn();
    $count_today_in  = $pdo->query("SELECT COUNT(*) FROM stay_history WHERE DATE(check_in) = CURDATE()")->fetchColumn();
    $count_today_out = $pdo->query("SELECT COUNT(*) FROM stay_history WHERE DATE(check_out) = CURDATE() AND status = 'Completed'")->fetchColumn();

    // Vulnerable Groups Statistics (Active Only)
    $sql_v = "SELECT m.v_name, m.v_color, COUNT(map.citizen_id) as total
              FROM vulnerable_master m
              LEFT JOIN citizen_vulnerable_map map ON m.id = map.v_id
              LEFT JOIN stay_history h ON map.citizen_id = h.citizen_id AND h.status = 'Active'
              GROUP BY m.id";
    $v_stats = $pdo->query($sql_v)->fetchAll();

    // Custom Fields Statistics (Active Only - Yes/No fields)
    $sql_c = "SELECT cm.field_name, COUNT(ccv.citizen_id) as total
              FROM custom_field_master cm
              LEFT JOIN citizen_custom_values ccv ON cm.id = ccv.field_id AND ccv.field_value = 'Yes'
              LEFT JOIN stay_history h ON ccv.citizen_id = h.citizen_id AND h.status = 'Active'
              WHERE cm.is_active = 1
              GROUP BY cm.id";
    $custom_stats = $pdo->query($sql_c)->fetchAll();

    // Recent Active Residents (Limit 5)
    $sql_recent = "SELECT c.id, c.prefix, c.firstname, c.lastname, c.photo_path, h.check_in, h.location_type
                   FROM stay_history h
                   JOIN citizens c ON h.citizen_id = c.id
                   WHERE h.status = 'Active'
                   ORDER BY h.check_in DESC LIMIT 5";
    $active_citizens = $pdo->query($sql_recent)->fetchAll();


    $stmt = $pdo->query("SELECT install_log FROM settings ORDER BY id DESC LIMIT 1");
    $setting = $stmt->fetch();

} catch (PDOException $e) {
    error_log("Dashboard Data Error: " . $e->getMessage());
    $error_db = "ไม่สามารถโหลดข้อมูลบางส่วนได้ กรุณาติดต่อผู้ดูแลระบบ";
}

// User Greeting Data
$nickname = $_SESSION['nickname'] ?? $_SESSION['username'] ?? 'ผู้ใช้งาน';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="text-primary fw-bold"><i class="bi bi-grid-1x2-fill"></i> ระบบบริหารจัดการผู้เข้าพัก</h2>
            <p class="text-muted mb-0">สวัสดีคุณ<strong><?php echo htmlspecialchars($nickname); ?></strong> ยินดีต้อนรับเข้าสู่ระบบจัดการ</p>
        </div>
        <div class="text-end d-none d-md-block">
            <h5 class="mb-0 text-dark"><?php echo date('d/m/Y'); ?></h5>
            <span class="badge bg-dark rounded-pill" id="clock">00:00:00</span>
        </div>
    </div>

    
    <div class="row g-3 mb-4 text-white">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm bg-primary text-white h-100 overflow-hidden p-2">
                <div class="card-body position-relative">
                    <h6 class="opacity-75">ผู้เข้าพักปัจจุบัน</h6>
                    <h1 class="display-5 fw-bold"><?php echo number_format($count_active); ?></h1>
                    <i class="bi bi-people fill position-absolute end-0 bottom-0 me-2 mb-0 fs-1 opacity-25" style="font-size: 5rem !important;"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm bg-success text-white h-100 overflow-hidden p-2">
                <div class="card-body position-relative">
                    <h6 class="opacity-75">เข้าพักวันนี้</h6>
                    <h1 class="display-5 fw-bold"><?php echo number_format($count_today_in); ?></h1>
                    <i class="bi bi-person-plus-fill position-absolute end-0 bottom-0 me-2 mb-0 fs-1 opacity-25" style="font-size: 5rem !important;"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm bg-secondary text-white h-100 overflow-hidden p-2">
                <div class="card-body position-relative">
                    <h6 class="opacity-75">แจ้งออกวันนี้</h6>
                    <h1 class="display-5 fw-bold"><?php echo number_format($count_today_out); ?></h1>
                    <i class="bi bi-box-arrow-right position-absolute end-0 bottom-0 me-2 mb-0 fs-1 opacity-25" style="font-size: 5rem !important;"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="mb-0 text-danger fw-bold"><i class="bi bi-heart-pulse-fill"></i> สถานะกลุ่มเป้าหมายพิเศษ</h5>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <?php foreach($v_stats as $vs): ?>
                <div class="col-md-3 col-6">
                    <div class="border rounded p-3 text-center bg-light shadow-sm h-100 border-top-0 border-end-0 border-bottom-0 border-start-4 border-<?php echo $vs['v_color']; ?>">
                        <h6 class="text-muted small mb-2"><?php echo htmlspecialchars($vs['v_name']); ?></h6>
                        <h3 class="mb-0 text-<?php echo $vs['v_color']; ?> fw-bold"><?php echo $vs['total']; ?></h3>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php foreach($custom_stats as $cs): if($cs['total'] > 0): ?>
                <div class="col-md-3 col-6">
                    <div class="border rounded p-3 text-center bg-light shadow-sm h-100 border-start border-success border-4 border-top-0 border-end-0 border-bottom-0">
                        <h6 class="text-muted small mb-2"><?php echo htmlspecialchars($cs['field_name']); ?></h6>
                        <h3 class="mb-0 text-success fw-bold"><?php echo $cs['total']; ?></h3>
                    </div>
                </div>
                <?php endif; endforeach; ?>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-primary fw-bold"><i class="bi bi-clock-history"></i> ผู้เข้าพักล่าสุด</h5>
                    <a href="index.php?page=guest_list&status=active" class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <tbody>
                            <?php if(empty($active_citizens)): ?>
                                <tr><td colspan="4" class="text-center p-5 text-muted">ยังไม่มีผู้เข้าพักในขณะนี้</td></tr>
                            <?php endif; ?>
                            <?php foreach($active_citizens as $ag): ?>
                            <tr>
                                <td class="ps-3" width="70">
                                    <img src="<?php echo (!empty($ag['photo_path']) && file_exists($ag['photo_path'])) ? htmlspecialchars($ag['photo_path']) : 'assets/noimg.jpg'; ?>" 
                                         class="rounded shadow-sm" style="width:50px; height:55px; object-fit:cover;">
                                </td>
                                <td>
                                    <span class="fw-bold d-block"><?php echo htmlspecialchars($ag['prefix'].$ag['firstname'].' '.$ag['lastname']); ?></span>
                                    <small class="text-muted"><i class="bi bi-calendar-event"></i> <?php echo date('d/m/Y H:i', strtotime($ag['check_in'])); ?> น.</small>
                                </td>
                                <td>
                                    <span class="badge rounded-pill bg-<?php echo ($ag['location_type']=='Inside')?'info':'warning'; ?> text-dark">
                                        <?php echo ($ag['location_type']=='Inside')?'ในศูนย์':'นอกศูนย์'; ?>
                                    </span>
                                </td>
                                <td class="text-end pe-3">
                                    <a href="index.php?page=guest_history&id=<?php echo $ag['id']; ?>" class="btn btn-sm btn-light rounded-circle shadow-sm">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm bg-white h-100">
                <div class="card-body p-4 d-flex flex-column justify-content-center">
                    <h5 class="mb-4 text-dark fw-bold border-bottom pb-2">เมนูเข้าใช้งานด่วน</h5>
                    <a href="index.php?page=guest_form" class="btn btn-primary w-100 py-3 mb-3 fw-bold rounded-3 shadow-sm">
                        <i class="bi bi-person-plus-fill"></i> ลงทะเบียนใหม่
                    </a>
                    <a href="index.php?page=guest_list" class="btn btn-outline-dark w-100 py-3 mb-3 rounded-3">
                        <i class="bi bi-search"></i> ค้นหารายชื่อ
                    </a>

                        <?php
$log = null;
if ($setting && !empty($setting['install_log'])) {
    $log = json_decode($setting['install_log'], true);
}

if ($log && json_last_error() === JSON_ERROR_NONE) {
    echo '<div class="alert alert-warning border-0 card-body p-4 d-flex flex-column justify-content-center mt-2">';
    echo "ติดตั้งเมื่อ: " . $log['installed_at'];
    echo '</div>';
} else {
    echo '<div class="alert alert-warning border-0 shadow-sm">';
    echo '<i class="bi bi-exclamation-triangle-fill"></i> ยังไม่มีประวัติการติดตั้งในระบบ';
    echo '<br><small>กรุณาดำเนินการติดตั้งผ่านหน้า <a href="install/">Setup Wizard</a></small>';
    echo '</div>';
}
?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    /**
     * Digital Clock Script
     */
    function updateClock() {
        const now = new Date();
        const options = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false };
        document.getElementById('clock').innerText = now.toLocaleTimeString('th-TH', options);
    }
    setInterval(updateClock, 1000);
    updateClock();
</script>