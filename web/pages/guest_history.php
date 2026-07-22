<?php
/**
 * Page: Guest History & Profile
 * Displays detailed resident profile, health info, and chronological stay history.
 * Supports PDF/Print view and secure deletion.
 */

require_once 'core/auth.php';
require_once 'core/security.php';
require_once 'core/functions.php';

checkLogin();

// P7: URL ใช้ public_id → เก็บไว้สำหรับสร้างลิงก์ + แปลงเป็น internal id สำหรับ query
$public_id = preg_replace('/\D/', '', (string)($_GET['id'] ?? ''));
$id = resolveCitizenId($pdo, $public_id);

// --- 1. Fetch Comprehensive Citizen Profile ---
$sql_citizen = "SELECT c.*, al.subdistrict, al.district, al.province, al.zipcode,
                       TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) AS age 
                FROM citizens c 
                LEFT JOIN address_lookup al ON c.address_id = al.id 
                WHERE c.id = :id";
$stmt = $pdo->prepare($sql_citizen);
$stmt->execute([':id' => $id]);
$citizen = $stmt->fetch();

if (!$citizen) {
    echo "<div class='container mt-5'><div class='alert alert-danger shadow-sm'>" . e('hist.not_found') . "</div></div>";
    exit();
}

// Decrypt sensitive info
$phone_dec = !empty($citizen['phone_enc']) ? decryptData($citizen['phone_enc']) : '-';
$fullname = htmlspecialchars($citizen['prefix'] . $citizen['firstname'] . ' ' . $citizen['lastname']);
$photo_show = (!empty($citizen['photo_path']) && file_exists($citizen['photo_path'])) ? $citizen['photo_path'] : "assets/noimg.jpg";

// --- 2. Fetch Mapping Data (Vulnerable Groups & Custom Fields) ---
// Vulnerable Groups
$stmt_v = $pdo->prepare("SELECT m.v_name, m.v_color FROM citizen_vulnerable_map map
                         JOIN vulnerable_master m ON map.v_id = m.id WHERE map.citizen_id = ?");
$stmt_v->execute([$id]);
$vulnerables = $stmt_v->fetchAll();

// Custom Selection (Checkbox types set to 'Yes')
$stmt_c = $pdo->prepare("
    SELECT m.field_name, v.field_value, m.field_type 
    FROM citizen_custom_values v
    JOIN custom_field_master m ON v.field_id = m.id
    WHERE v.citizen_id = ? 
      AND m.is_active = 1 
      AND (
          (m.field_type = 'checkbox' AND v.field_value = 'Yes') OR 
          (m.field_type = 'text' AND v.field_value IS NOT NULL AND v.field_value != '')
      )
");
$stmt_c->execute([$id]);
$custom_data = $stmt_c->fetchAll();

// --- 3. Fetch Chronological Stay History ---
$sql_history = "SELECT h.*, u.nickname as admin_name 
                FROM stay_history h 
                LEFT JOIN users u ON h.admin_id = u.id 
                WHERE h.citizen_id = :cid ORDER BY h.check_in DESC";
$stmt_h = $pdo->prepare($sql_history);
$stmt_h->execute([':cid' => $id]);
$history = $stmt_h->fetchAll();

// Security Audit
writeLog($pdo, 'VIEW_HISTORY', "Accessed profile: $fullname (ID: $id)");
?>

<style>
    /* 🖨️ Optimized Print Styles */
    @media print {
        .no-print, .btn, .navbar, .breadcrumb { display: none !important; }
        .container { width: 100% !important; max-width: 100% !important; margin: 0 !important; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; margin-bottom: 10px !important; }
        .card-header { background-color: #f8f9fa !important; color: #000 !important; }
        body { background: #fff !important; font-size: 10pt; }
        .badge { border: 1px solid #000 !important; color: #000 !important; background: transparent !important; }
    }
    .profile-img { width: 120px; height: 145px; object-fit: cover; border-radius: 10px; }
</style>

<div class="container mt-4 mb-5">
    <div class="card shadow-sm mb-4 border-0 border-start border-primary border-5 rounded-3">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-auto">
                     <img src="<?php echo $photo_show; ?>" class="profile-img border shadow-sm">
                </div>
                <div class="col">
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div class="mb-2">
                            <h2 class="mb-1 text-primary fw-bold"><?php echo $fullname; ?></h2>
                            <div class="mb-2">
                                <span class="badge bg-light text-dark border">
                                    <?php echo ($citizen['birthdate'] && $citizen['birthdate'] != '0000-00-00') ? e('hist.age_years', ['age' => $citizen['age']]) : e('hist.no_birthdate'); ?>
                                </span>
                                <span class="ms-2 text-muted small"><i class="bi bi-credit-card"></i> : ●●●●<?php echo $citizen['id_card_last4']; ?></span>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <?php foreach($vulnerables as $v): ?>
                                    <span class="badge bg-<?php echo $v['v_color']; ?>-subtle text-<?php echo $v['v_color']; ?> border border-<?php echo $v['v_color']; ?> shadow-sm small">
                                        <i class="bi bi-heart-pulse"></i> <?php echo $v['v_name']; ?>
                                    </span>
                                <?php endforeach; ?>

                                <?php foreach($custom_data as $cd): ?>
                                    <?php if($cd['field_type'] === 'checkbox'): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info shadow-sm small">
                                            <i class="bi bi-plus-circle"></i> <?php echo htmlspecialchars($cd['field_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border shadow-sm small">
                                            <i class="bi bi-chat-left-text text-primary"></i> 
                                            <span class="text-muted"><?php echo htmlspecialchars($cd['field_name']); ?>:</span> 
                                            <?php echo htmlspecialchars($cd['field_value']); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="no-print d-flex gap-2 mb-2">
                            <button onclick="window.print()" class="btn btn-outline-dark shadow-sm btn-sm">
                                <i class="bi bi-printer"></i> <?php echo e('btn.print'); ?>
                            </button>
                            <a href="index.php?page=guest_form&id=<?php echo htmlspecialchars($public_id); ?>" class="btn btn-warning shadow-sm btn-sm fw-bold">
                                <i class="bi bi-pencil-square"></i> <?php echo e('hist.edit'); ?>
                            </a>
                            <?php if(userCan('guests.delete')): ?>
                                <button onclick="confirmDelete('<?php echo htmlspecialchars($public_id); ?>')" class="btn btn-danger shadow-sm btn-sm">
                                    <i class="bi bi-trash"></i> <?php echo e('hist.delete'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row mt-3 g-3">
                        <div class="col-md-4">
                            <strong><i class="bi bi-telephone-fill text-primary"></i> <?php echo e('hist.phone'); ?></strong>
                            <span class="text-dark"><?php echo htmlspecialchars($phone_dec); ?></span>
                        </div>
                        <div class="col-md-8">
                            <strong><i class="bi bi-geo-alt-fill text-primary"></i> <?php echo e('hist.address'); ?></strong>
                            <span class="text-dark">
                                <?php 
                                    $a = array_filter([
                                        $citizen['addr_number'] ? "".$citizen['addr_number'] : "",
                                        $citizen['subdistrict'] ? t('addr.tambon').$citizen['subdistrict'] : "",
                                        $citizen['district'] ? t('addr.amphoe').$citizen['district'] : "",
                                        $citizen['province'] ? t('addr.changwat').$citizen['province'] : "",
                                        $citizen['zipcode'] ?? ""
                                    ]);
                                    echo !empty($a) ? implode(' ', $a) : "-";
                                ?>
                            </span>
                        </div>
                        <div class="col-12 mt-2">
                            <div class="p-3 bg-light rounded-3 border border-dashed border-danger-subtle">
                                <strong class="text-danger"><i class="bi bi-clipboard2-pulse-fill"></i> <?php echo e('hist.medical'); ?></strong>
                                <span class="ms-2"><?php echo htmlspecialchars($citizen['medical_info'] ?: ''); ?></span>
                                
                                <div class="mt-1 small text-muted italic">
                                    <strong class="text-success"><i class="bi bi-exclamation-circle"></i> <?php echo e('hist.notes'); ?></strong> <?php echo htmlspecialchars($citizen['notes'] ?? ''); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-clock-history text-primary"></i> <?php echo e('hist.all_stays'); ?></h5>
            <a href="index.php?page=guest_list" class="btn btn-sm btn-outline-secondary no-print rounded-pill px-3">
                <i class="bi bi-arrow-left"></i> <?php echo e('hist.back_to_list'); ?>
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4 py-3" width="200"><?php echo e('hist.col_checkin'); ?></th>
                        <th class="py-3" width="200"><?php echo e('hist.col_checkout'); ?></th>
                        <th class="py-3"><?php echo e('hist.col_place'); ?></th>
                        <th class="py-3"><?php echo e('hist.col_status'); ?></th>
                        <th class="py-3"><?php echo e('hist.col_recorder'); ?></th>
                        <th class="py-3 text-end pe-4 no-print"><?php echo e('hist.col_manage'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($history)): foreach ($history as $h): ?>
                        <tr>
                            <td class="ps-4">
                                <?php echo dateThai($h['check_in'] ?: $h['created_at']); ?>
                            </td>
                            <td>
                                <?php echo ($h['status'] === 'Active') ? '<span class="text-muted italic small">' . e('hist.not_checked_out') . '</span>' : dateThai($h['check_out']); ?>
                            </td>
                            <td>
                                <span class="badge rounded-pill <?php echo ($h['location_type'] === 'Inside') ? 'bg-info-subtle text-info border border-info' : 'bg-warning-subtle text-warning-emphasis border border-warning'; ?> px-3">
                                    <i class="bi <?php echo ($h['location_type'] === 'Inside') ? 'bi-building' : 'bi-house-door'; ?>"></i> 
                                    <?php echo ($h['location_type'] === 'Inside') ? e('common.inside') : e('common.outside'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($h['status'] === 'Active'): ?>
                                    <span class="badge bg-success rounded-pill px-3"><?php echo e('hist.status_active'); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border rounded-pill px-3"><?php echo e('hist.status_out'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted fw-bold"><?php echo htmlspecialchars($h['admin_name'] ?: 'System'); ?></small></td>
                            <td class="text-end pe-4 no-print">
                                <?php if ($h['status'] === 'Active'): ?>
                                    <a href="pages/checkout_save.php?stay_id=<?php echo $h['id']; ?>&citizen_id=<?php echo htmlspecialchars($public_id); ?>"
                                       class="btn btn-danger btn-sm rounded-pill px-3 shadow-sm"
                                       onclick="return confirm('<?php echo e('hist.confirm_checkout'); ?>');">
                                        <i class="bi bi-box-arrow-right"></i> <?php echo e('hist.checkout_btn'); ?>
                                    </a>
                                <?php else: ?>
                                    <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted"><?php echo e('hist.no_history'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
/**
 * Delete Confirmation with SweetAlert2
 */
var L = <?php echo json_encode([
    'del_title'   => t('hist.js_del_title'),
    'del_text'    => t('hist.js_del_text'),
    'del_confirm' => t('hist.js_del_confirm'),
    'cancel'      => t('btn.cancel'),
], JSON_UNESCAPED_UNICODE); ?>;
function confirmDelete(id) {
    Swal.fire({
        title: L.del_title,
        text: L.del_text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: L.del_confirm,
        cancelButtonText: L.cancel,
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `pages/guest_delete.php?id=${id}`;
        }
    });
}
</script>