<?php
// pages/guest_list.php
require_once 'core/security.php';
require_once 'core/functions.php';
require_once 'core/stats.php'; // ตัวนับ active_total (O(1)) สำหรับหน้า default
require_once __DIR__ . '/partials/guest_pager.php';

// guard สิทธิ์ฝั่งเซิร์ฟเวอร์ — เดิมอาศัยแค่ซ่อนเมนูใน header (ไม่ใช่การป้องกัน)
requirePermission('guests.view');

// --- 1. รับค่าการกรองจาก URL ---
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';
$gender_filter = isset($_GET['gender']) ? $_GET['gender'] : '';
$age_range     = isset($_GET['age_range']) ? thaiDigitsToArabic(trim($_GET['age_range'])) : ''; // รับเลขไทย → อารบิก
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
$page_ids = [];   // id ของแถวในหน้านี้ (ทุกเส้นทางผลิตตัวนี้ → hydrate ร่วมจุดเดียว)
$total_items = 0;
$total_pages = 0;

// --- 2. สร้างเงื่อนไข SQL Query ---
if ($is_filtered) {
    // แยกคำค้น "ข้อความ" (มีตัวอักษร) ออกจากคำค้น "เลข" (เลขบัตร) — เส้นทางต่างกัน
    $search_text    = ($search !== '' && !ctype_digit($search)) ? $search : '';
    $is_text_search = ($search_text !== '');

    // ---- 2.1 ตัวกรองร่วม (ทุกอย่างยกเว้นคำค้นข้อความ) — placeholder positional '?' ----
    // ใช้ซ้ำได้ในทุก UNION arm (EMULATE_PREPARES=false ห้าม named ซ้ำ → ใช้ ? แล้ว copy ค่าต่อ arm)
    $filter_parts = [];
    $filter_vals  = [];

    // เลขบัตร (เฉพาะตอนค้นเป็นตัวเลข — ไม่ใช่ค้นข้อความ)
    if ($search !== '' && ctype_digit($search)) {
        if (strlen($search) === 13) {
            $filter_parts[] = "c.id_card_hash = ?";
            $filter_vals[]  = hashID($search);
        } else {
            $filter_parts[] = "c.id_card_last4 LIKE ?";
            $filter_vals[]  = "$search%";
        }
    }
    if (!empty($gender_filter)) {
        $filter_parts[] = "c.gender = ?";
        $filter_vals[]  = $gender_filter;
    }
    if ($age_range !== '') {
        $age_lo = $age_hi = null;
        if (preg_match('/^(\d+)-(\d+)$/', $age_range, $m)) {
            // ช่วงอายุ "a-b" — เผื่อกรอกกลับด้าน (60-50) ให้สลับเป็น min..max
            $age_lo = min((int)$m[1], (int)$m[2]);
            $age_hi = max((int)$m[1], (int)$m[2]);
        } elseif (preg_match('/^(\d+)$/', $age_range, $m)) {
            // อายุตายตัวค่าเดียว "50" = อายุ 50 พอดี
            $age_lo = $age_hi = (int)$m[1];
        }
        // ไม่ match ทั้งคู่ (เช่น "-", "50-") → ไม่ filter (ป้อนไม่ครบ)
        if ($age_lo !== null) {
            // แปลงอายุ → ช่วงวันเกิด (sargable ใช้ index idx_birthdate) แทน TIMESTAMPDIFF ต่อแถว
            //   อายุ = N  ⟺  birthdate ∈ [today-(N+1)ปี+1วัน , today-Nปี]  (ให้ผลตรง TIMESTAMPDIFF(YEAR))
            $today  = new DateTime('today');
            $bd_hi  = (clone $today)->modify("-{$age_lo} year")->format('Y-m-d');
            $bd_lo  = (clone $today)->modify('-' . ($age_hi + 1) . ' year')->modify('+1 day')->format('Y-m-d');
            $filter_parts[] = "c.birthdate BETWEEN ? AND ?";
            $filter_vals[]  = $bd_lo;
            $filter_vals[]  = $bd_hi;
        }
    }
    // สถานะเข้าพัก — คอลัมน์ denormalized c.is_active (index idx_active_recent)
    if ($status_filter == 'active') {
        $filter_parts[] = "c.is_active = 1";
    } elseif ($status_filter == 'inactive') {
        $filter_parts[] = "c.is_active = 0";
    }
    // กลุ่มเป้าหมายพิเศษ (Vulnerable)
    if (!empty($v_filters)) {
        $ph = implode(',', array_fill(0, count($v_filters), '?'));
        $filter_parts[] = "EXISTS (SELECT 1 FROM citizen_vulnerable_map map WHERE map.citizen_id = c.id AND map.v_id IN ($ph))";
        foreach ($v_filters as $vid) $filter_vals[] = $vid;
    }
    // ตัวกรองอัตโนมัติ (Custom Search Fields)
    if (!empty($custom_search)) {
        foreach ($custom_search as $field_id => $val) {
            if ($val == 'Yes') {
                $filter_parts[] = "EXISTS (SELECT 1 FROM citizen_custom_values ccv WHERE ccv.citizen_id = c.id AND ccv.field_id = ? AND ccv.field_value = ?)";
                $filter_vals[]  = $field_id;
                $filter_vals[]  = 'Yes';
            }
        }
    }
    // วันที่เข้าพัก (ล่าสุด)
    if (!empty($search_date)) {
        $filter_parts[] = "EXISTS (SELECT 1 FROM stay_history sh WHERE sh.citizen_id = c.id AND DATE(sh.check_in) = ?)";
        $filter_vals[]  = $search_date;
    }
    $filter_sql = $filter_parts ? implode(' AND ', $filter_parts) : '';

    // ทั้งสองเส้นทางให้ผลนับได้ (ค้นข้อความ = นับ exact ถึง cap แล้วแสดง "มากกว่า N")
    $count_exact = true;
    $capped      = false;
    $too_broad   = false;  // filter กว้างเกิน 5 วิ → โดน max_statement_time ฆ่า (กันจอขาว)

try {
    if ($is_text_search) {
        /* ===== เส้นทางค้นข้อความ: UNION-per-arm (พอร์ตจาก wp-bhikkhu-scholar) =====
         * เลี่ยง OR ข้ามคอลัมน์ (full-scan/timeout) → arm ต่อคอลัมน์ ใช้ index ของตัวเอง
         * ตัดคำนำหน้าออกจากคำค้นก่อน (dictionary name_prefix) · ที่อยู่ resolve id บนตารางเล็กแล้ว IN()
         */
        $arms = searchTextArms($pdo, $search_text);

        if (!$arms) {
            // คำนำหน้าล้วน / ไม่มี arm → ไม่มีผล
            $total_items = 0;
            $total_pages = 0;
            $page_ids    = [];
        } else {
            $cap   = SEARCH_COUNT_CAP;
            $parts = [];
            $base  = [];
            foreach ($arms as [$asql, $avals]) {
                $w = $filter_sql !== '' ? "$filter_sql AND ($asql)" : "($asql)";
                // LIMIT ต่อ arm = cap → คำกว้างไม่ materialize ล้านแถว (ต้องครอบวงเล็บใน UNION)
                $parts[] = "(SELECT c.id, c.last_stay_at FROM citizens c WHERE $w LIMIT $cap)";
                $base    = array_merge($base, $filter_vals, $avals);
            }
            $union_all = implode(' UNION ALL ', $parts); // probe + pool (bounded ต่อ arm)
            $union     = implode(' UNION ', $parts);      // exact count (distinct ≤ cap/arm)

            // นับแบบ cap: probe เร็ว (UNION ALL + LIMIT cap+1) รู้ว่าเกินเพดานไหม
            dbg_start('list: COUNT (probe)');
            $probeSql = "SELECT COUNT(*) FROM ( $union_all LIMIT " . ($cap + 1) . " ) c";
            $st = $pdo->prepare($probeSql);
            $st->execute($base);
            $probe = (int)$st->fetchColumn();
            if ($probe > $cap) {
                $total_items = $cap;   // เกินเพดาน → แสดง "มากกว่า N"
                $capped      = true;
            } else {
                $st = $pdo->prepare("SELECT COUNT(*) FROM ( $union ) u");
                $st->execute($base);
                $total_items = (int)$st->fetchColumn();
            }
            $total_pages = (int)ceil($total_items / $items_per_page);
            dbg_stop('list: COUNT (probe)');

            // id ของหน้านี้ — pool = cap แถว (UNION ALL หยุดไว) แล้ว DISTINCT + เรียงตามความใหม่
            // (ผลไม่เกิน cap → pool ครบ = เรียงเป๊ะ · เกิน cap → cap แรก = เรียงโดยประมาณ)
            dbg_start('list: ดึง id หน้า');
            $idSql = "SELECT id FROM (
                        SELECT DISTINCT id, last_stay_at FROM ( $union_all LIMIT $cap ) ua
                      ) d
                      ORDER BY last_stay_at DESC
                      LIMIT $items_per_page OFFSET $offset";
            $st = $pdo->prepare($idSql);
            $st->execute($base);
            $page_ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            dbg_stop('list: ดึง id หน้า');
            // hydrate ร่วมหลัง if/else (ใช้ $page_ids)
        }
    } else {
        /* ===== เส้นทางไม่มีคำค้นข้อความ (ว่าง / เลขบัตร / filter dropdown) =====
         * A. exact/fast — active-only (O(1)) | เลขบัตร 13 หลัก (equality) → นับ exact + เรียงตรง index
         * B. capped     — filter อื่น ๆ → probe+pool เหมือนค้นข้อความ + guard กันค้าง (max_statement_time)
         */
        $where_sql = $filter_sql !== '' ? "WHERE $filter_sql" : "";

        // fast-path: หน้า default (คนกำลังพัก ไม่มี filter อื่น) = อ่านตัวนับสรุป O(1)
        $only_status    = ($search === '' && $gender_filter === '' && $age_range === ''
                           && empty($v_filters) && empty($custom_search) && $search_date === '');
        $is_hash_search = ($search !== '' && ctype_digit($search) && strlen($search) === 13);
        $is_exact_fast  = ($only_status && $status_filter === 'active') || $is_hash_search;

        // กันค้าง: query เกิน 5 วิ ถูกฆ่า (โยน 1969) → แจ้ง "เงื่อนไขกว้างเกินไป" แทนจอขาว
        try { $pdo->exec("SET max_statement_time=5"); } catch (\Throwable $e) { /* เฉพาะ MariaDB */ }

        if ($is_exact_fast) {
            // ----- A. exact/fast (นับตรง index/PK ได้เร็ว) -----
            dbg_start('list: COUNT');
            if ($only_status && $status_filter === 'active') {
                $total_items = statActiveTotal($pdo);           // O(1)
            } else {
                $cst = $pdo->prepare("SELECT COUNT(*) FROM citizens c $where_sql");
                $cst->execute($filter_vals);
                $total_items = (int)$cst->fetchColumn();
            }
            $total_pages = (int)ceil($total_items / $items_per_page);
            dbg_stop('list: COUNT');

            // เรียง last_stay_at อย่างเดียว (ตรง index idx_active_recent) — tiebreaker นอก index = filesort 14.5M
            dbg_start('list: ดึง id หน้า');
            $idSql = "SELECT c.id FROM citizens c $where_sql
                      ORDER BY c.last_stay_at DESC
                      LIMIT $items_per_page OFFSET $offset";
            $st = $pdo->prepare($idSql);
            $st->execute($filter_vals);
            $page_ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            dbg_stop('list: ดึง id หน้า');
        } else {
            // ----- B. capped: probe+pool (filter dropdown / inactive / all / last4-prefix) -----
            $cap = SEARCH_COUNT_CAP;
            try {
                // probe: หยุดที่ cap+1 → รู้ว่าเกินเพดานไหมโดยไม่นับ matches มหาศาล
                dbg_start('list: COUNT (probe)');
                $probeSql = "SELECT COUNT(*) FROM (SELECT c.id FROM citizens c $where_sql LIMIT " . ($cap + 1) . ") x";
                $st = $pdo->prepare($probeSql);
                $st->execute($filter_vals);
                $probe = (int)$st->fetchColumn();
                if ($probe > $cap) { $total_items = $cap; $capped = true; }
                else               { $total_items = $probe; }
                $total_pages = (int)ceil($total_items / $items_per_page);
                dbg_stop('list: COUNT (probe)');

                // pool = cap แถวใหม่สุด (ORDER ก่อน LIMIT) แล้วเลือกหน้า (เกิน cap = เรียงโดยประมาณ)
                dbg_start('list: ดึง id หน้า');
                $idSql = "SELECT id FROM (
                            SELECT c.id, c.last_stay_at FROM citizens c $where_sql
                            ORDER BY c.last_stay_at DESC LIMIT $cap
                          ) d
                          ORDER BY last_stay_at DESC
                          LIMIT $items_per_page OFFSET $offset";
                $st = $pdo->prepare($idSql);
                $st->execute($filter_vals);
                $page_ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                dbg_stop('list: ดึง id หน้า');
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1969) {   // ER_STATEMENT_TIMEOUT (MariaDB)
                    $too_broad   = true;
                    $count_exact = false;
                    $total_items = 0;
                    $total_pages = 0;
                    $page_ids    = [];
                } else {
                    throw $e;
                }
            }
        }
    }

    // hydrate ร่วมทุกเส้นทาง: JOIN address เฉพาะ id ของหน้านี้ (≤ items_per_page แถว) + คงลำดับด้วย FIELD()
    dbg_start('list: hydrate หน้า');
    if (!empty($page_ids)) {
        $inList = implode(',', $page_ids); // int ล้วน (จาก DB) — inline ปลอดภัย
        $hsql = "SELECT c.*,
                        al.subdistrict AS lookup_tambon,
                        al.district AS lookup_amphoe,
                        al.province AS lookup_province,
                        hl.province AS home_province,
                        TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) AS age,
                        c.last_stay_at AS last_stay_date
                 FROM citizens c
                 LEFT JOIN address_lookup al ON c.address_id = al.id
                 LEFT JOIN address_lookup hl ON c.home_address_id = hl.id
                 WHERE c.id IN ($inList)
                 ORDER BY FIELD(c.id, $inList)";
        $citizens = $pdo->query($hsql)->fetchAll();
    } else {
        $citizens = [];
    }
    dbg_stop('list: hydrate หน้า');

    // ยุบ N+1: ดึงกลุ่มเปราะบาง + ฟิลด์พิเศษ ของทุกแถวในหน้านี้ด้วย query เดียว (เดิม 3 query/แถว)
    dbg_start('list: batch vulnerable/custom (1 query/ชุด)');
    $v_by_cid = [];
    $c_by_cid = [];
    $page_ids2 = array_map(fn($r) => (int)$r['id'], $citizens);
    if ($page_ids2) {
        $in = implode(',', array_fill(0, count($page_ids2), '?'));
        $stmt_v = $pdo->prepare("SELECT map.citizen_id, m.v_name, m.v_color
                                 FROM citizen_vulnerable_map map
                                 JOIN vulnerable_master m ON map.v_id = m.id
                                 WHERE map.citizen_id IN ($in)");
        $stmt_v->execute($page_ids2);
        foreach ($stmt_v->fetchAll() as $row) { $v_by_cid[(int)$row['citizen_id']][] = $row; }

        $stmt_c = $pdo->prepare("SELECT v.citizen_id, m.field_name
                                 FROM citizen_custom_values v
                                 JOIN custom_field_master m ON v.field_id = m.id
                                 WHERE v.field_value = 'Yes' AND v.citizen_id IN ($in)");
        $stmt_c->execute($page_ids2);
        foreach ($stmt_c->fetchAll() as $row) { $c_by_cid[(int)$row['citizen_id']][] = $row; }
    }
    dbg_stop('list: batch vulnerable/custom (1 query/ชุด)');
    } catch (PDOException $e) {
        error_log("Search Error: " . $e->getMessage());
        $citizens = [];
    }
}

$v_master = $pdo->query("SELECT * FROM vulnerable_master ORDER BY id ASC")->fetchAll();
$export_query = http_build_query($_GET);

// แถบแบ่งหน้า (พอร์ตรูปแบบจาก wp-bhikkhu-scholar เหมือน Sec) — เรนเดอร์ครั้งเดียว ใช้ทั้งบน (ในกล่องสรุปผล) และล่าง (แถวเดียวกับดร็อบดาว)
$pagerHtml = guestPagerHtml($_GET, $current_page, (int)$total_pages, $count_exact, false);
?>

<style>
/* แถบแบ่งหน้า guest_list (พอร์ตเลย์เอาต์จาก wp-bhikkhu-scholar) — สรุปผล/ดร็อบดาวซ้าย ดันแบ่งหน้าไปขวา */
.gl-pager{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.gl-page-jump{width:auto}
.gl-page-jump .gl-page-input{flex:0 0 62px;width:62px;text-align:center}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold"><i class="bi bi-people-fill text-primary"></i> <?php echo e('list.title'); ?></h3>
    <a href="index.php?page=guest_form" class="btn btn-primary shadow-sm">
        <i class="bi bi-person-plus-fill"></i> + <?php echo e('list.register_new'); ?>
    </a>
</div>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-body bg-light rounded">
        <form method="GET" action="index.php" id="filterForm" class="row g-3">
            <input type="hidden" name="page" value="guest_list">
            
            <div class="col-md-4">
                <label class="small text-muted fw-bold"><?php echo e('list.search_label'); ?></label>
                <input type="text" name="search" class="form-control" placeholder="<?php echo e('list.search_placeholder'); ?>" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="col-md-2">
                <label class="small text-muted fw-bold"><?php echo e('list.gender'); ?></label>
                <select name="gender" class="form-select" onchange="this.form.submit()">
                    <option value=""><?php echo e('common.all'); ?></option>
                    <option value="Male" <?php if($gender_filter == 'Male') echo 'selected'; ?>><?php echo e('common.male'); ?></option>
                    <option value="Female" <?php if($gender_filter == 'Female') echo 'selected'; ?>><?php echo e('common.female'); ?></option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="small text-muted fw-bold"><?php echo e('list.status'); ?></label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value=""><?php echo e('common.all'); ?></option>
                    <option value="active" <?php if($status_filter=='active') echo 'selected'; ?>><?php echo e('list.status_active_opt'); ?></option>
                    <option value="inactive" <?php if($status_filter=='inactive') echo 'selected'; ?>><?php echo e('list.status_inactive_opt'); ?></option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="small text-muted fw-bold"><?php echo e('list.age_range'); ?></label>
                <input type="text" name="age_range" id="age_range_input" class="form-control" inputmode="numeric" placeholder="<?php echo e('list.age_range_ph'); ?>" value="<?php echo htmlspecialchars($age_range); ?>">
            </div>
            
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-search"></i> <?php echo e('btn.search'); ?></button>
                <?php if($is_filtered): ?>
                    <a href="index.php?page=guest_list" class="btn btn-outline-secondary" title="<?php echo e('list.clear'); ?>"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>

            <div class="col-12 pt-2 border-top">
                <label class="small text-muted mb-2 d-block fw-bold"><?php echo e('list.filter_special'); ?></label>
                <div class="d-flex flex-wrap gap-x-4 gap-y-2">

                <div class="col-md-2 me-3">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-calendar3"></i></span>
                        <input type="text" name="search_date" id="search_check_in_date" 
                               class="form-control bg-white" onchange="this.form.submit()"
                               placeholder="<?php echo e('list.date_ph'); ?>"
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
        <?php if (!empty($too_broad)): ?>
        <div class="alert alert-warning d-flex align-items-center shadow-sm border-0 py-2">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <div><?php echo e('list.filter_too_broad'); ?></div>
        </div>
        <?php else: ?>
        <div class="alert alert-info d-flex align-items-center flex-wrap gap-2 shadow-sm border-0 py-2">
            <div class="d-flex align-items-center">
                <i class="bi bi-info-circle-fill me-2 fs-5"></i>
                <?php if (!empty($capped)): ?>
                <div><?php echo e('list.found_prefix'); ?> <strong><?php echo e('list.more_than'); ?> <?php echo number_format(SEARCH_COUNT_CAP); ?></strong> <?php echo e('list.found_suffix'); ?></div>
                <?php else: ?>
                <div><?php echo e('list.found_prefix'); ?> <strong><?php echo number_format($total_items); ?></strong> <?php echo e('list.found_suffix'); ?></div>
                <?php endif; ?>
            </div>
            <?php if ($pagerHtml !== ''): ?>
            <div class="ms-auto"><?php echo $pagerHtml; ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-end mb-3">
<?php if (userCan('export.excel') && $is_filtered && ($total_items > 0 || count($citizens) > 0)): ?>
    <a href="pages/export_excel.php?<?php echo $export_query; ?>" class="btn btn-success btn-sm shadow-sm" title="<?php echo e('list.export_all_hint'); ?>">
        <i class="bi bi-file-earmark-excel"></i> <?php echo e('list.export_excel'); ?><?php if (!empty($capped)): ?> (<?php echo e('list.export_all'); ?>)<?php elseif ($count_exact): ?> (<?php echo number_format($total_items); ?> <?php echo e('common.items_unit'); ?>)<?php endif; ?>
    </a>
<?php endif; ?>
<?php if (userCan('guests.register')): ?>
    <a href="index.php?page=guest_import" class="btn btn-warning btn-sm shadow-sm ms-2">
        <i class="bi bi-file-earmark-arrow-up"></i> <?php echo e('imp.list_btn'); ?>
    </a>
<?php endif; ?>

    <label class="small fw-bold text-muted"> </label>
    <select class="form-select form-select-sm" style="width: auto; margin-left: 12px; " onchange="changeLimit(this.value)">
        <option value="50" <?php echo ($items_per_page == 50) ? 'selected' : ''; ?>>50 <?php echo e('common.items_unit'); ?></option>
        <option value="100" <?php echo ($items_per_page == 100) ? 'selected' : ''; ?>>100 <?php echo e('common.items_unit'); ?></option>
        <option value="500" <?php echo ($items_per_page == 500) ? 'selected' : ''; ?>>500 <?php echo e('common.items_unit'); ?></option>
        <option value="1000" <?php echo ($items_per_page == 1000) ? 'selected' : ''; ?>>1000 <?php echo e('common.items_unit'); ?></option>
    </select>
</div>

<div class="table-responsive bg-white shadow-sm rounded p-3">
    <table class="table table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th colspan="2"><?php echo e('list.col_name'); ?></th>
                <th><?php echo e('list.col_age'); ?></th>
                <th><?php echo e('list.col_gender'); ?></th>
                <th><?php echo e('list.col_status'); ?></th>
                <th><?php echo e('list.col_special'); ?></th>
                <th><?php echo e('list.col_province'); ?></th>
                <th class="text-end"><?php echo e('list.col_manage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($citizens) > 0): ?>
                <?php foreach ($citizens as $c): 
                    $img_src = (!empty($c['photo_path']) && file_exists($c['photo_path'])) ? $c['photo_path'] : "assets/noimg.jpg";
                    $fullname = $c['prefix'] . $c['firstname'] . ' ' . $c['lastname'];
                    
                    // สถานะ/กลุ่มพิเศษ อ่านจากค่าที่ denormalize + batch ไว้แล้ว (ไม่มี query ต่อแถว)
                    $is_staying = (bool)$c['is_active'];
                    $v_items = $v_by_cid[(int)$c['id']] ?? [];
                    $c_items = $c_by_cid[(int)$c['id']] ?? [];
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
                                echo $c['age'] . " " . e('common.age_unit');

                                // 💡 แถม: ถ้าอายุ 60 ปีขึ้นไป ให้แสดงไอคอนผู้สูงอายุ
                                if ($c['age'] >= 60) {
                                    echo ' <i class="bi bi-person-heart text-danger" title="' . e('list.elderly') . '"></i>';
                                }
                            } else {
                                // 2. ถ้าไม่มีข้อมูล หรือข้อมูลผิดปกติ แสดงเครื่องหมาย -
                                echo '<span class="text-muted" title="' . e('list.unknown_birth') . '">-</span>';
                            }
                        ?>
                    </td>
                    <td>
                        <span class="badge rounded-pill <?php echo ($c['gender'] == 'Male') ? 'bg-primary' : 'bg-danger'; ?> bg-opacity-10 <?php echo ($c['gender'] == 'Male') ? 'text-primary' : 'text-danger'; ?> border">
                            <?php echo ($c['gender'] == 'Male') ? e('common.male') : e('common.female'); ?>
                        </span>
                    </td>
                    <td>
                        <?php echo $is_staying ? '<span class="badge bg-success shadow-sm">' . e('list.badge_staying') . '</span>' : '<span class="badge bg-light text-muted border">' . e('list.badge_out') . '</span>'; ?>
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
                    <?php // จังหวัด: ภูมิลำเนาก่อน — ไม่มีค่อยใช้ที่อยู่ตามทะเบียนบ้าน
                          $prov = pickDisplayAddress(
                              ['province' => $c['home_province'] ?? ''],
                              ['province' => $c['lookup_province'] ?? $c['addr_province'] ?? '']
                          ); ?>
                    <td><small class="text-muted"><?php echo htmlspecialchars($prov['province'] !== '' ? $prov['province'] : '-'); ?></small></td>
                    <td class="text-end">
                        <div class="btn-group shadow-sm">
                            <a href="index.php?page=guest_history&id=<?php echo htmlspecialchars($c['public_id'] ?? ''); ?>" class="btn btn-sm btn-info text-white"><i class="bi bi-clock-history"></i></a>
                            <a href="index.php?page=guest_form&id=<?php echo htmlspecialchars($c['public_id'] ?? ''); ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil-square"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" class="text-center py-5 text-muted"><h5><?php echo e('list.no_data'); ?></h5></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
        <?php if ($pagerHtml !== ''): ?>
            <?php echo $pagerHtml; ?>
        <?php endif; ?>
        <div class="d-flex align-items-center ms-auto">
            <select class="form-select form-select-sm" style="width: auto;" onchange="changeLimit(this.value)">
                <option value="50" <?php echo ($items_per_page == 50) ? 'selected' : ''; ?>>50 <?php echo e('common.items_unit'); ?></option>
                <option value="100" <?php echo ($items_per_page == 100) ? 'selected' : ''; ?>>100 <?php echo e('common.items_unit'); ?></option>
                <option value="500" <?php echo ($items_per_page == 500) ? 'selected' : ''; ?>>500 <?php echo e('common.items_unit'); ?></option>
                <option value="1000" <?php echo ($items_per_page == 1000) ? 'selected' : ''; ?>>1000 <?php echo e('common.items_unit'); ?></option>
            </select>
            <a href="#" id="back-to-top" class="text-muted fs-2" style="margin-left: 12px;"><i class="bi bi-arrow-up-circle-fill"></i></a>
        </div>
    </div>

<script>
function changeLimit(limit) {
    // สร้าง URL ใหม่โดยเอาค่าปัจจุบันในหน้าเว็บมา แล้วเปลี่ยน limit และรีเซ็ต p เป็น 1
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('limit', limit);
    urlParams.set('p', 1); // รีเซ็ตหน้ากลับไปหน้า 1 เสมอ
    window.location.href = 'index.php?' + urlParams.toString();
}
</script>

</div>

    <?php // แถบแบ่งหน้าล่างย้ายไปรวมแถวเดียวกับดร็อบดาว "รายการต่อหน้า" ด้านบนแล้ว (ดู $pagerHtml) ?>
</div>


<script>
// ช่อง "ไปหน้า" ของแถบแบ่งหน้า (มีได้ทั้งบน/ล่าง → ใช้ event delegation ตัวเดียว)
(function () {
    function jump(input) {
        const max = parseInt(input.dataset.max || '1', 10) || 1;
        let p = parseInt((input.value || '').replace(/\D/g, ''), 10);
        if (!p || p < 1) p = 1;
        if (p > max) p = max;
        const u = new URLSearchParams(window.location.search);
        u.set('page', 'guest_list');
        u.set('p', p);
        window.location.href = 'index.php?' + u.toString();
    }
    document.addEventListener('click', function (e) {
        const go = e.target.closest('.gl-page-go');
        if (!go) return;
        const inp = go.closest('.gl-page-jump').querySelector('.gl-page-input');
        if (inp) jump(inp);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        const inp = e.target.closest ? e.target.closest('.gl-page-input') : null;
        if (inp) { e.preventDefault(); jump(inp); }
    });

    // ช่องช่วงอายุ: กันพิมพ์อักขระอื่นทันที (เหลือเฉพาะ 0-9 ๐-๙ และ "-")
    const ageInp = document.getElementById('age_range_input');
    if (ageInp) {
        ageInp.addEventListener('input', function () {
            const cleaned = this.value.replace(/[^0-9๐-๙-]/g, '');
            if (cleaned !== this.value) this.value = cleaned;
        });
    }
})();


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
