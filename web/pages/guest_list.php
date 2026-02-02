<?php
// pages/guest_list.php
require_once 'core/security.php';
require_once 'core/functions.php'; 

// --- 1. รับค่าการกรองจาก URL ---
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';
$gender_filter = isset($_GET['gender']) ? $_GET['gender'] : '';
$age_range     = isset($_GET['age_range']) ? trim($_GET['age_range']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active'; // Default ให้แสดงคนกำลังพัก
$search_date   = isset($_GET['search_date']) ? trim($_GET['search_date']) : '';
$v_filters     = isset($_GET['v_filter']) ? $_GET['v_filter'] : [];
$custom_search = isset($_GET['custom_search']) ? $_GET['custom_search'] : [];

// ตรวจสอบสถานะการกรองเพื่อใช้แสดงผล UI
$is_filtered = true; 

// เตรียมตัวแปรพื้นฐานสำหรับ Pagination
$items_per_page = isset($_GET['limit']) ? intval($_GET['limit']) : 50; 
if (!in_array($items_per_page, [50, 100, 500, 1000])) $items_per_page = 50;

$current_page   = isset($_GET['p']) ? (int)$_GET['p'] : 1;
if ($current_page < 1) $current_page = 1;

// $offset จะคำนวณจาก $items_per_page ล่าสุดโดยอัตโนมัติ
$offset = ($current_page - 1) * $items_per_page;

$params = [];
$conditions = [];
$citizens = []; 
$total_items = 0;
$total_pages = 0;

// --- 2. สร้างเงื่อนไข SQL Query ---
if ($is_filtered) {
    // 🔍 กรองข้อความ ชื่อ/เลขบัตร
    if (!empty($search)) {
        if (ctype_digit($search)) {
            if (strlen($search) == 13) {
                $conditions[] = "c.id_card_hash = :hash";
                $params[':hash'] = hashID($search);
            } else {
                $conditions[] = "c.id_card_last4 LIKE :last4";
                $params[':last4'] = "%$search%";
            }
        } else {
            $conditions[] = "(c.firstname LIKE :q1 OR c.lastname LIKE :q2 OR c.addr_tambon LIKE :q3 OR c.addr_amphoe LIKE :q4 OR c.addr_province LIKE :q5)";
            $params[':q1'] = "%$search%"; $params[':q2'] = "%$search%"; 
            $params[':q3'] = "%$search%"; $params[':q4'] = "%$search%"; $params[':q5'] = "%$search%";
        }
    }

    // 🔍 กรองเพศ
    if (!empty($gender_filter)) {
        $conditions[] = "c.gender = :gender";
        $params[':gender'] = $gender_filter;
    }

    // 🔍 กรองอายุ
    if (!empty($age_range)) {
        if (preg_match('/^(\d+)-(\d+)$/', $age_range, $matches)) {
            $conditions[] = "TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) BETWEEN :min_age AND :max_age";
            $params[':min_age'] = $matches[1];
            $params[':max_age'] = $matches[2];
        }
    }

    // 🔍 กรองสถานะ Active/Inactive
    if ($status_filter == 'active') {
        $conditions[] = "c.id IN (SELECT citizen_id FROM stay_history WHERE status = 'Active')";
    } elseif ($status_filter == 'inactive') {
        $conditions[] = "c.id NOT IN (SELECT citizen_id FROM stay_history WHERE status = 'Active')";
    }

    // 🔍 กรองกลุ่มเป้าหมายพิเศษ (Vulnerable)
    if (!empty($v_filters)) {
        $v_placeholders = [];
        foreach ($v_filters as $idx => $v_id) {
            $key = ":vid$idx";
            $v_placeholders[] = $key;
            $params[$key] = $v_id;
        }
        $conditions[] = "EXISTS (SELECT 1 FROM citizen_vulnerable_map map WHERE map.citizen_id = c.id AND map.v_id IN (" . implode(',', $v_placeholders) . "))";
    }

    // 🔍 ส่วนตัวกรองอัตโนมัติ (Custom Search Fields)
    if (!empty($custom_search)) {
        foreach ($custom_search as $field_id => $val) {
            if ($val == 'Yes') {
                $f_key = ":f_id" . $field_id;
                $v_key = ":f_val" . $field_id;
                $conditions[] = "EXISTS (
                    SELECT 1 FROM citizen_custom_values ccv 
                    WHERE ccv.citizen_id = c.id 
                    AND ccv.field_id = $f_key 
                    AND ccv.field_value = $v_key
                )";
                $params[$f_key] = $field_id;
                $params[$v_key] = 'Yes';
            }
        }
    }

    // 🔍 กรองจากวันที่เข้าพัก (ล่าสุด)
    if (!empty($search_date)) {
        // ใช้ Subquery ตรวจสอบว่าคนๆ นี้มีประวัติเข้าพักในวันที่เลือกหรือไม่
        $conditions[] = "EXISTS (
        SELECT 1 FROM stay_history sh 
        WHERE sh.citizen_id = c.id 
        AND DATE(sh.check_in) = :search_date
        )";
        $params[':search_date'] = $search_date;
    }

    $where_sql = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";

    try {
        $count_sql = "SELECT COUNT(DISTINCT c.id) FROM citizens c $where_sql";
        $stmt_count = $pdo->prepare($count_sql);
        $stmt_count->execute($params);
        $total_items = (int)$stmt_count->fetchColumn();
        $total_pages = ceil($total_items / $items_per_page);

        $sql = "SELECT c.*, al.province AS lookup_province, TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) AS age,
                    (SELECT MAX(check_in) FROM stay_history WHERE citizen_id = c.id) as last_stay_date 
            FROM citizens c 
            LEFT JOIN address_lookup al ON c.address_id = al.id 
            $where_sql 
            ORDER BY last_stay_date DESC, c.created_at DESC 
            LIMIT $items_per_page OFFSET $offset";


        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $citizens = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Search Error: " . $e->getMessage());
        $citizens = [];
    }
}

$v_master = $pdo->query("SELECT * FROM vulnerable_master ORDER BY id ASC")->fetchAll();
$export_query = http_build_query($_GET);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold"><i class="bi bi-people-fill text-primary"></i> รายชื่อบุคคล (Citizens)</h3>
    <a href="index.php?page=guest_form" class="btn btn-primary shadow-sm">
        <i class="bi bi-person-plus-fill"></i> + ลงทะเบียนใหม่
    </a>
</div>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-body bg-light rounded">
        <form method="GET" action="index.php" id="filterForm" class="row g-3">
            <input type="hidden" name="page" value="guest_list">
            
            <div class="col-md-4">
                <label class="small text-muted fw-bold">คำค้นหา (ชื่อ, สกุล, ที่อยู่, เลขท้ายบัตร)</label>
                <input type="text" name="search" class="form-control" placeholder="พิมพ์ข้อมูลที่ต้องการค้นหา..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="col-md-2">
                <label class="small text-muted fw-bold">เพศ</label>
                <select name="gender" class="form-select" onchange="this.form.submit()">
                    <option value="">- ทั้งหมด -</option>
                    <option value="Male" <?php if($gender_filter == 'Male') echo 'selected'; ?>>ชาย</option>
                    <option value="Female" <?php if($gender_filter == 'Female') echo 'selected'; ?>>หญิง</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="small text-muted fw-bold">สถานะ</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">- ทั้งหมด -</option>
                    <option value="active" <?php if($status_filter=='active') echo 'selected'; ?>>🟢 กำลังพัก</option>
                    <option value="inactive" <?php if($status_filter=='inactive') echo 'selected'; ?>>⚪ แจ้งออก</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="small text-muted fw-bold">ช่วงอายุ</label>
                <input type="text" name="age_range" class="form-control" placeholder="เช่น 60-99" value="<?php echo htmlspecialchars($age_range); ?>">
            </div>
            
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-search"></i> ค้นหา</button>
                <?php if($is_filtered): ?>
                    <a href="index.php?page=guest_list" class="btn btn-outline-secondary" title="ล้างค่า"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>

            <div class="col-12 pt-2 border-top">
                <label class="small text-muted mb-2 d-block fw-bold">กรองตามกลุ่มเป้าหมายพิเศษ:</label>
                <div class="d-flex flex-wrap gap-x-4 gap-y-2">

                <div class="col-md-2 me-3">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-calendar3"></i></span>
                        <input type="text" name="search_date" id="search_check_in_date" 
                               class="form-control bg-white" onchange="this.form.submit()"
                               placeholder="วันที่เข้าพัก..." 
                               value="<?php echo htmlspecialchars($_GET['search_date'] ?? ''); ?>" 
                               readonly>
                    </div>
                </div>

                    <?php foreach($v_master as $v): ?>
                    <div class="form-check form-switch me-2">
                        <input class="form-check-input" type="checkbox" name="v_filter[]" 
                               value="<?php echo $v['id']; ?>" 
                               id="vf_<?php echo $v['id']; ?>"
                               onchange="this.form.submit()"
                               <?php echo in_array($v['id'], $v_filters) ? 'checked' : ''; ?>>
                        <label class="form-check-label small fw-bold" for="vf_<?php echo $v['id']; ?>">
                            <?php echo $v['v_name']; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>

                    <?php
                    $searchable_fields = $pdo->query("SELECT * FROM custom_field_master WHERE field_type = 'checkbox' AND is_searchable = 1 AND is_active = 1")->fetchAll();
                    foreach ($searchable_fields as $sf):
                        $checked = isset($custom_search[$sf['id']]) ? 'checked' : '';
                    ?>
                    <div class="form-check form-switch me-2">
                        <input class="form-check-input" type="checkbox" 
                               name="custom_search[<?=$sf['id']?>]" 
                               id="cs_<?=$sf['id']?>"
                               value="Yes" 
                               onchange="this.form.submit()"
                               <?=$checked?>>
                        <label class="form-check-label small fw-bold text-primary" for="cs_<?=$sf['id']?>">
                            <?=$sf['field_name']?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($is_filtered): ?>
<div class="row mb-3">
    <div class="col-12">
        <div class="alert alert-info d-flex align-items-center shadow-sm border-0 py-2">
            <i class="bi bi-info-circle-fill me-2 fs-5"></i>
            <div>พบข้อมูลที่ตรงตามเงื่อนไขทั้งหมด <strong><?php echo number_format($total_items); ?></strong> ราย</div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (in_array($_SESSION['role_level'], [1, 2]) && $is_filtered && $total_items > 0): ?>
<div class="d-flex justify-content-end mb-3">
    <a href="pages/export_excel.php?<?php echo $export_query; ?>" class="btn btn-success btn-sm shadow-sm">
        <i class="bi bi-file-earmark-excel"></i> ส่งออก Excel (<?php echo $total_items; ?> รายการ)
    </a>

    <label class="small fw-bold text-muted"> </label>
    <select class="form-select form-select-sm" style="width: auto; margin-left: 12px; " onchange="changeLimit(this.value)">
        <option value="50" <?php echo ($items_per_page == 50) ? 'selected' : ''; ?>>50 รายการ</option>
        <option value="100" <?php echo ($items_per_page == 100) ? 'selected' : ''; ?>>100 รายการ</option>
        <option value="500" <?php echo ($items_per_page == 500) ? 'selected' : ''; ?>>500 รายการ</option>
        <option value="500" <?php echo ($items_per_page == 1000) ? 'selected' : ''; ?>>1000 รายการ</option>
    </select>
</div>
<?php endif; ?>

<div class="table-responsive bg-white shadow-sm rounded p-3">
    <table class="table table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th colspan="2">ชื่อ - นามสกุล</th>
                <th>อายุ</th>
                <th>เพศ</th>
                <th>สถานะ</th> 
                <th>กลุ่มพิเศษ/ข้อมูลเสริม</th>
                <th>จังหวัด</th>
                <th class="text-end">จัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($citizens) > 0): ?>
                <?php foreach ($citizens as $c): 
                    $img_src = (!empty($c['photo_path']) && file_exists($c['photo_path'])) ? $c['photo_path'] : "assets/noimg.jpg";
                    $fullname = $c['prefix'] . $c['firstname'] . ' ' . $c['lastname'];
                    
                    $chk_stmt = $pdo->prepare("SELECT COUNT(*) FROM stay_history WHERE citizen_id = ? AND status = 'Active'");
                    $chk_stmt->execute([$c['id']]);
                    $is_staying = $chk_stmt->fetchColumn() > 0;

                    // ดึงกลุ่มเป้าหมายพิเศษ
                    $stmt_v = $pdo->prepare("SELECT m.v_name, m.v_color FROM citizen_vulnerable_map map JOIN vulnerable_master m ON map.v_id = m.id WHERE map.citizen_id = ?");
                    $stmt_v->execute([$c['id']]);
                    $v_items = $stmt_v->fetchAll();

                    // ดึงข้อมูลเสริม (เฉพาะที่เป็น Checkbox และติ๊ก 'Yes')
                    $stmt_c = $pdo->prepare("SELECT m.field_name FROM citizen_custom_values v JOIN custom_field_master m ON v.field_id = m.id WHERE v.citizen_id = ? AND v.field_value = 'Yes'");
                    $stmt_c->execute([$c['id']]);
                    $c_items = $stmt_c->fetchAll();
                ?>
                <tr>
                    <td width="50">
                        <img src="<?php echo $img_src; ?>" class="rounded border shadow-sm" style="width: 45px; height: 50px; object-fit: cover;">
                    </td>
                    <td>
                        <div class="fw-bold"><?php echo htmlspecialchars($fullname); ?></div>
                        <small class="text-muted"><i class="bi bi-card-text"></i> ●●●●<?php echo $c['id_card_last4']; ?></small>
                    </td>
                    <td>
                        <?php 
                            // 1. ตรวจสอบว่ามีข้อมูลวันเกิด และอายุที่คำนวณได้ไม่เป็นค่าติดลบ
                            if (!empty($c['birthdate']) && $c['birthdate'] !== '0000-00-00' && $c['age'] >= 0) {
                                echo $c['age'] . " ปี";
            
                                // 💡 แถม: ถ้าอายุ 60 ปีขึ้นไป ให้แสดงไอคอนผู้สูงอายุ
                                if ($c['age'] >= 60) {
                                    echo ' <i class="bi bi-person-heart text-danger" title="ผู้สูงอายุ"></i>';
                                }
                            } else {
                                // 2. ถ้าไม่มีข้อมูล หรือข้อมูลผิดปกติ แสดงเครื่องหมาย -
                                echo '<span class="text-muted" title="ไม่ทราบวันเกิด">-</span>';
                            }
                        ?>
                    </td>
                    <td>
                        <span class="badge rounded-pill <?php echo ($c['gender'] == 'Male') ? 'bg-primary' : 'bg-danger'; ?> bg-opacity-10 <?php echo ($c['gender'] == 'Male') ? 'text-primary' : 'text-danger'; ?> border">
                            <?php echo ($c['gender'] == 'Male') ? 'ชาย' : 'หญิง'; ?>
                        </span>
                    </td>
                    <td>
                        <?php echo $is_staying ? '<span class="badge bg-success shadow-sm">กำลังพัก</span>' : '<span class="badge bg-light text-muted border">แจ้งออก</span>'; ?>
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach($v_items as $vi): ?>
                                <span class="badge bg-<?php echo $vi['v_color']; ?> fw-normal" style="font-size: 0.7rem;"><?php echo $vi['v_name']; ?></span>
                            <?php endforeach; ?>
                            <?php foreach($c_items as $ci): ?>
                                <span class="badge bg-info text-dark fw-normal" style="font-size: 0.7rem;"><i class="bi bi-plus-square"></i> <?php echo $ci['field_name']; ?></span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td><small class="text-muted"><?php echo htmlspecialchars($c['lookup_province'] ?? $c['addr_province'] ?? '-'); ?></small></td>
                    <td class="text-end">
                        <div class="btn-group shadow-sm">
                            <a href="index.php?page=guest_history&id=<?php echo $c['id']; ?>" class="btn btn-sm btn-info text-white"><i class="bi bi-clock-history"></i></a>
                            <a href="index.php?page=guest_form&id=<?php echo $c['id']; ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil-square"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" class="text-center py-5 text-muted"><h5>ไม่พบข้อมูล</h5></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="d-flex justify-content-end mb-3">
        <select class="form-select form-select-sm" style="width: auto;" onchange="changeLimit(this.value)">
            <option value="50" <?php echo ($items_per_page == 50) ? 'selected' : ''; ?>>50 รายการ</option>
            <option value="100" <?php echo ($items_per_page == 100) ? 'selected' : ''; ?>>100 รายการ</option>
            <option value="500" <?php echo ($items_per_page == 500) ? 'selected' : ''; ?>>500 รายการ</option>
            <option value="500" <?php echo ($items_per_page == 1000) ? 'selected' : ''; ?>>1000 รายการ</option>
        </select>
        <a href="#" id="back-to-top" class="text-muted fs-2" style="margin-left: 12px;"><i class="bi bi-arrow-up-circle-fill"></i></a>
    </div>

<script>
function changeLimit(limit) {
    // สร้าง URL ใหม่โดยเอาค่าปัจจุบันในหน้าเว็บมา แล้วเปลี่ยน limit และรีเซ็ต p เป็น 1
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('limit', limit);
    urlParams.set('p', 1); // รีเซ็ตหน้ากลับไปหน้า 1 เสมอ
    window.location.href = 'index.php?' + urlParams.toString();
}

// ฟังก์ชันวันที่ภาษาไทย
function clearDate() {
    document.querySelector("#search_check_in_date")._flatpickr.clear();
}
</script>

</div>

    <?php if ($total_pages > 1): ?>
    <nav class="mt-4">
        <ul class="pagination pagination-sm justify-content-center">
            <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>">
                <a class="page-link" href="index.php?<?php echo http_build_query(array_merge($_GET, ['p' => $current_page - 1])); ?>"><i class="bi bi-chevron-left"></i></a>
            </li>
            <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, max(1, $current_page - 2) + 4); $i++): ?>
            <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                <a class="page-link" href="index.php?<?php echo http_build_query(array_merge($_GET, ['p' => $i])); ?>"><?php echo $i; ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>">
                <a class="page-link" href="index.php?<?php echo http_build_query(array_merge($_GET, ['p' => $current_page + 1])); ?>"><i class="bi bi-chevron-right"></i></a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>


<script>
// ทำฟังก์ชันเสริมสำหรับล้างตัวกรอง
document.addEventListener('DOMContentLoaded', function() {
    // หากต้องการให้ช่องค้นหาทำงาน Auto เมื่อหยุดพิมพ์ สามารถเพิ่ม Logic ตรงนี้ได้
});


$(document).ready(function() {
    // เรียกใช้งาน Flatpickr สำหรับช่องค้นหา
    flatpickr("#search_check_in_date", {
        dateFormat: "Y-m-d", // ส่งค่าไป Server เป็นปี-เดือน-วัน (Format มาตรฐาน DB)
        locale: "th",        // แสดงผลภาษาไทย
        altInput: true,      // เปิดการใช้งานช่อง Input แสดงผลสำรอง
        altFormat: "j M Y",  // รูปแบบวันที่ที่โชว์ให้ User เห็น (เช่น 31 ม.ค. 2026)
        disableMobile: "true" // ป้องกันคีย์บอร์ดมือถือเด้งขึ้นมาบังปฏิทิน
    });
});
</script>