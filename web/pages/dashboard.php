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
    $sql_recent = "SELECT c.id, c.public_id, c.prefix, c.firstname, c.lastname, c.photo_path, h.check_in, h.location_type
                   FROM stay_history h
                   JOIN citizens c ON h.citizen_id = c.id
                   WHERE h.status = 'Active'
                   ORDER BY h.check_in DESC LIMIT 5";
    $active_citizens = $pdo->query($sql_recent)->fetchAll();


    $stmt = $pdo->query("SELECT install_log FROM settings ORDER BY id DESC LIMIT 1");
    $setting = $stmt->fetch();

} catch (PDOException $e) {
    error_log("Dashboard Data Error: " . $e->getMessage());
    $error_db = t('dash.err_db');
}

// User Greeting Data
$nickname = $_SESSION['nickname'] ?? $_SESSION['username'] ?? t('user.anonymous');
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="text-primary fw-bold"><i class="bi bi-grid-1x2-fill"></i> <?php echo e('dash.title'); ?></h2>
            <p class="text-muted mb-0"><?php echo e('dash.greeting_hello'); ?><strong><?php echo htmlspecialchars($nickname); ?></strong> <?php echo e('dash.greeting_welcome'); ?></p>
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
                    <h6 class="opacity-75"><?php echo e('dash.stat_current'); ?></h6>
                    <h1 class="display-5 fw-bold"><?php echo number_format($count_active); ?></h1>
                    <i class="bi bi-people fill position-absolute end-0 bottom-0 me-2 mb-0 fs-1 opacity-25" style="font-size: 5rem !important;"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm bg-success text-white h-100 overflow-hidden p-2">
                <div class="card-body position-relative">
                    <h6 class="opacity-75"><?php echo e('dash.stat_today_in'); ?></h6>
                    <h1 class="display-5 fw-bold"><?php echo number_format($count_today_in); ?></h1>
                    <i class="bi bi-person-plus-fill position-absolute end-0 bottom-0 me-2 mb-0 fs-1 opacity-25" style="font-size: 5rem !important;"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm bg-secondary text-white h-100 overflow-hidden p-2">
                <div class="card-body position-relative">
                    <h6 class="opacity-75"><?php echo e('dash.stat_today_out'); ?></h6>
                    <h1 class="display-5 fw-bold"><?php echo number_format($count_today_out); ?></h1>
                    <i class="bi bi-box-arrow-right position-absolute end-0 bottom-0 me-2 mb-0 fs-1 opacity-25" style="font-size: 5rem !important;"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="mb-0 text-danger fw-bold"><i class="bi bi-heart-pulse-fill"></i> <?php echo e('dash.special_groups'); ?></h5>
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
                    <h5 class="mb-0 text-primary fw-bold"><i class="bi bi-clock-history"></i> <?php echo e('dash.recent'); ?></h5>
                    <a href="index.php?page=guest_list&status=active" class="btn btn-sm btn-outline-primary"><?php echo e('dash.view_all'); ?></a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <tbody>
                            <?php if(empty($active_citizens)): ?>
                                <tr><td colspan="4" class="text-center p-5 text-muted"><?php echo e('dash.no_active'); ?></td></tr>
                            <?php endif; ?>
                            <?php foreach($active_citizens as $ag): ?>
                            <tr>
                                <td class="ps-3" width="70">
                                    <img src="<?php echo (!empty($ag['photo_path']) && file_exists($ag['photo_path'])) ? htmlspecialchars($ag['photo_path']) : 'assets/noimg.jpg'; ?>" 
                                         class="rounded shadow-sm" style="width:50px; height:55px; object-fit:cover;">
                                </td>
                                <td>
                                    <span class="fw-bold d-block"><?php echo htmlspecialchars($ag['prefix'].$ag['firstname'].' '.$ag['lastname']); ?></span>
                                    <small class="text-muted"><i class="bi bi-calendar-event"></i> <?php echo date('d/m/Y H:i', strtotime($ag['check_in'])); ?> <?php echo e('common.time_unit'); ?></small>
                                </td>
                                <td>
                                    <span class="badge rounded-pill bg-<?php echo ($ag['location_type']=='Inside')?'info':'warning'; ?> text-dark">
                                        <?php echo ($ag['location_type']=='Inside')?e('common.inside'):e('common.outside'); ?>
                                    </span>
                                </td>
                                <td class="text-end pe-3">
                                    <a href="index.php?page=guest_history&id=<?php echo htmlspecialchars($ag['public_id'] ?? ''); ?>" class="btn btn-sm btn-light rounded-circle shadow-sm">
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
                    <h5 class="mb-4 text-dark fw-bold border-bottom pb-2"><?php echo e('dash.quick_menu'); ?></h5>
                    <a href="index.php?page=guest_form" class="btn btn-primary w-100 py-3 mb-3 fw-bold rounded-3 shadow-sm">
                        <i class="bi bi-person-plus-fill"></i> <?php echo e('dash.register_new'); ?>
                    </a>
                    <a href="index.php?page=guest_list" class="btn btn-outline-dark w-100 py-3 mb-3 rounded-3">
                        <i class="bi bi-search"></i> <?php echo e('dash.search_names'); ?>
                    </a>
                    <?php if (pdpaEnabled($pdo)): ?>
                    <button type="button" class="btn btn-outline-success w-100 py-3 mb-3 rounded-3" data-bs-toggle="modal" data-bs-target="#qrDisplayModal">
                        <i class="bi bi-qr-code"></i> <?php echo e('dash.make_qr'); ?>
                    </button>
                    <?php endif; ?>

                        <?php
$log = null;
if ($setting && !empty($setting['install_log'])) {
    $log = json_decode($setting['install_log'], true);
}

if ($log && json_last_error() === JSON_ERROR_NONE) {
    echo '<div class="alert alert-warning border-0 card-body p-4 d-flex flex-column justify-content-center mt-2">';
    echo '<span><i class="bi bi-box-seam"></i> ' . e('dash.version') . ' <strong>' . htmlspecialchars(APP_VERSION) . '</strong></span>';
    echo '<span>' . e('dash.installed_at') . ' ' . htmlspecialchars((string)($log['installed_at'] ?? '-')) . '</span>';
    echo '</div>';
} else {
    echo '<div class="alert alert-warning border-0 shadow-sm">';
    echo '<i class="bi bi-exclamation-triangle-fill"></i> ' . e('dash.no_install_log');
    echo '<br><small>' . e('dash.install_hint') . ' <a href="install/">Setup Wizard</a></small>';
    echo '<br><small>' . e('dash.version') . ' ' . htmlspecialchars(APP_VERSION) . '</small>';
    echo '</div>';
}
?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// URL หน้ายินยอม (guest_display) สำหรับ QR — ประกอบจาก site_url + qr_ip (setting.php) + display_key ประจำตัว
$__s        = appSettings($pdo);
$__siteUrl  = $__s['site_url'] !== '' ? $__s['site_url'] : detectSiteUrl();
$__dispKey  = getOrCreateDisplayKey($pdo, (int)$_SESSION['user_id']);
$qr_display_url = buildDisplayQrUrl($__siteUrl, $__s['qr_ip'], $__dispKey);
?>
<!-- Modal: QR Code หน้ายินยอม PDPA (จอแท็บเล็ต) -->
<div class="modal fade" id="qrDisplayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-success fw-bold"><i class="bi bi-qr-code"></i> <?php echo e('qr.modal_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo e('btn.close'); ?>"></button>
            </div>
            <div class="modal-body text-center">
                <p class="text-muted small mb-3"><?php echo e('qr.scan_hint'); ?></p>
                <canvas id="qrCanvas" class="border rounded p-2 bg-white" data-url="<?= htmlspecialchars($qr_display_url) ?>"></canvas>
                <div class="mt-2"><a id="qrOpenLink" href="<?= htmlspecialchars($qr_display_url) ?>" target="_blank" rel="noopener" class="small text-break" dir="ltr"><?= htmlspecialchars($qr_display_url) ?></a></div>
                <div class="form-text mt-2"><i class="bi bi-gear"></i> <?php echo t('qr.config_hint'); ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php echo e('btn.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<script src="./assets/js/qrious.min.js"></script>
<script>
    /**
     * QR Code generator (สร้างฝั่ง browser ล้วน — ค่า URL ประกอบเสร็จจากฝั่ง server แล้ว)
     */
    (function () {
        const modalEl = document.getElementById('qrDisplayModal');
        const canvas  = document.getElementById('qrCanvas');
        let qr = null;

        modalEl.addEventListener('shown.bs.modal', function () {
            if (!qr) qr = new QRious({ element: canvas, size: 240, level: 'M' });
            qr.value = canvas.dataset.url;
        });
    })();
</script>

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