<?php
/**
 * หน้าจัดการผังสถานที่พัก (แอดมินขึ้นไป — สิทธิ์ locations.manage)  [ยกมาจากโปรเจค Sec]
 *   ต้นไม้หมวดหมู่แบบเว็บบอร์ด: โซน → อาคาร/เรือน → ห้อง/เตียง … ลึกได้ถึง 5 ระดับ
 *   แต่ละโหนดมี 3 สวิตช์: is_shared (อาคารรวม) · assignable (เลือกเป็นจุดพักได้) · display_from (เริ่มแสดง path จากโหนดนี้)
 *
 * ถูก include ผ่าน index.php (csrf_verify() + ob_start() ทำที่ router แล้ว) → ใช้ redirect() ห้าม header() ตรง
 */

require_once 'core/auth.php';
require_once 'core/log.php';
require_once 'core/functions.php';
require_once 'core/locations.php';

if (!userCan('locations.manage')) {
    denyAccess(t('loc.err_no_permission'));
}

// ---------- บันทึก เพิ่ม/แก้ไข ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_loc'])) {
    $loc_id       = (int)($_POST['loc_id'] ?? 0);
    $parent_id    = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
    $name         = trim((string)($_POST['name'] ?? ''));
    $kind         = (int)($_POST['kind'] ?? 0);
    if (!isset(LOC_KINDS[$kind])) $kind = 0;
    $is_shared    = isset($_POST['is_shared']) ? 1 : 0;
    $assignable   = isset($_POST['assignable']) ? 1 : 0;
    $display_from = isset($_POST['display_from']) ? 1 : 0;

    if ($name === '') {
        $_SESSION['error_msg'] = t('loc.err_name');
    } elseif ($loc_id > 0) {
        // แก้ไข — ไม่ย้าย parent/ไม่แตะ depth (การย้ายกิ่งเก็บไว้เฟสถัดไป)
        $pdo->prepare("UPDATE locations SET name = ?, kind = ?, is_shared = ?, assignable = ?, display_from = ? WHERE id = ?")
            ->execute([$name, $kind, $is_shared, $assignable, $display_from, $loc_id]);
        writeLog($pdo, 'UPDATE_LOCATION', "แก้ไขผังสถานที่พัก: $name (ID: $loc_id)");
        $_SESSION['success_msg'] = t('loc.saved');
    } else {
        // เพิ่มใหม่ — คำนวณ depth จาก parent + กันเกินเพดาน 5 ระดับ
        $depth = 0;
        $parentOk = true;
        if ($parent_id !== null) {
            $prow = $pdo->prepare("SELECT depth FROM locations WHERE id = ?");
            $prow->execute([$parent_id]);
            $pdepth = $prow->fetchColumn();
            if ($pdepth === false) { $parentOk = false; }
            else { $depth = (int)$pdepth + 1; }
        }
        if (!$parentOk) {
            $_SESSION['error_msg'] = t('loc.err_parent');
        } elseif ($depth >= LOC_MAX_DEPTH) {
            $_SESSION['error_msg'] = t('loc.err_depth');
        } else {
            // sort_order = ต่อท้ายพี่น้อง
            $sq = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM locations WHERE parent_id " . ($parent_id === null ? "IS NULL" : "= ?"));
            $sq->execute($parent_id === null ? [] : [$parent_id]);
            $sort = (int)$sq->fetchColumn();
            $pdo->prepare("INSERT INTO locations (parent_id, name, kind, depth, is_shared, assignable, display_from, sort_order)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$parent_id, $name, $kind, $depth, $is_shared, $assignable, $display_from, $sort]);
            writeLog($pdo, 'CREATE_LOCATION', "เพิ่มผังสถานที่พัก: $name (depth $depth)");
            $_SESSION['success_msg'] = t('loc.saved');
        }
    }
    redirect('index.php?page=location_manage');
}

// ---------- ลบ (cascade ลูกทั้งหมด · stay_history.location_id → NULL) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_loc'])) {
    $loc_id = (int)$_POST['delete_loc'];
    if ($loc_id > 0) {
        $nm = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
        $nm->execute([$loc_id]);
        $name = (string)$nm->fetchColumn();
        $pdo->prepare("DELETE FROM locations WHERE id = ?")->execute([$loc_id]);
        writeLog($pdo, 'DELETE_LOCATION', "ลบผังสถานที่พัก: $name (ID: $loc_id)");
        $_SESSION['success_msg'] = t('loc.deleted');
    }
    redirect('index.php?page=location_manage');
}

// ---------- เลื่อนลำดับขึ้น/ลง (สลับกับพี่น้องข้างเคียง) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_loc'])) {
    $loc_id = (int)$_POST['move_loc'];
    $dir    = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
    $row = $pdo->prepare("SELECT parent_id FROM locations WHERE id = ?");
    $row->execute([$loc_id]);
    $parent = $row->fetchColumn();
    if ($parent !== false) {
        $parent = $parent !== null ? (int)$parent : null;
        $sib = $pdo->prepare("SELECT id FROM locations WHERE parent_id " . ($parent === null ? "IS NULL" : "= ?") . " ORDER BY sort_order, id");
        $sib->execute($parent === null ? [] : [$parent]);
        $ids = array_map('intval', $sib->fetchAll(PDO::FETCH_COLUMN));
        $idx = array_search($loc_id, $ids, true);
        $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($idx !== false && isset($ids[$swap])) {
            // reindex พี่น้องเป็น 1..n แล้วสลับตำแหน่งสองตัว → กันปัญหา sort_order ซ้ำ/ว่าง
            [$ids[$idx], $ids[$swap]] = [$ids[$swap], $ids[$idx]];
            $upd = $pdo->prepare("UPDATE locations SET sort_order = ? WHERE id = ?");
            foreach ($ids as $pos => $id) $upd->execute([$pos + 1, $id]);
            writeLog($pdo, 'REORDER_LOCATION', "เลื่อนลำดับผังสถานที่พัก (ID: $loc_id, $dir)");
        }
    }
    redirect('index.php?page=location_manage');
}

$success = $_SESSION['success_msg'] ?? null;
$error   = $_SESSION['error_msg'] ?? null;
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

$map = locationMap($pdo);

/** เรนเดอร์โหนดแบบ recursive (ต้นไม้ซ้อนชั้น) */
function renderLocNode(array $node, array $map, int $depth): void
{
    $kindClass = ['k-zone', 'k-kuti', 'k-room', 'k-special'][$node['kind']] ?? 'k-zone';
    $canAddChild = ($depth + 1) < LOC_MAX_DEPTH;
    $children = locationChildren($map, $node['id']);
    ?>
    <div class="loc-node d-flex align-items-center gap-2 flex-wrap" style="margin-left: <?= $depth * 24 ?>px">
        <span class="loc-kind <?= $kindClass ?>"><?= e(LOC_KINDS[$node['kind']] ?? '') ?></span>
        <span class="fw-bold"><?= htmlspecialchars($node['name']) ?></span>

        <?php if ($node['is_shared']): ?><span class="badge bg-info-subtle text-info border border-info"><i class="bi bi-diagram-3"></i> <?= e('loc.tag_shared') ?></span><?php endif; ?>
        <?php if ($node['assignable']): ?><span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check-circle"></i> <?= e('loc.tag_assign') ?></span><?php endif; ?>
        <?php if ($node['display_from']): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><i class="bi bi-eye"></i> <?= e('loc.tag_display') ?></span><?php endif; ?>

        <span class="ms-auto d-flex gap-1">
            <form method="POST" action="index.php?page=location_manage" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="dir" value="up">
                <button type="submit" name="move_loc" value="<?= $node['id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= e('loc.move_up') ?>"><i class="bi bi-arrow-up"></i></button>
            </form>
            <form method="POST" action="index.php?page=location_manage" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="dir" value="down">
                <button type="submit" name="move_loc" value="<?= $node['id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= e('loc.move_down') ?>"><i class="bi bi-arrow-down"></i></button>
            </form>
            <?php if ($canAddChild): ?>
            <button type="button" class="btn btn-sm btn-outline-primary loc-add-child"
                    data-parent="<?= $node['id'] ?>" data-parent-name="<?= htmlspecialchars($node['name'], ENT_QUOTES) ?>"
                    data-kind="<?= min($node['kind'] + 1, 3) ?>" title="<?= e('loc.add_child') ?>"><i class="bi bi-plus-lg"></i></button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-primary loc-edit"
                    data-id="<?= $node['id'] ?>" data-name="<?= htmlspecialchars($node['name'], ENT_QUOTES) ?>"
                    data-kind="<?= $node['kind'] ?>" data-shared="<?= $node['is_shared'] ?>"
                    data-assign="<?= $node['assignable'] ?>" data-display="<?= $node['display_from'] ?>"
                    title="<?= e('loc.edit') ?>"><i class="bi bi-pencil"></i></button>
            <form method="POST" action="index.php?page=location_manage" class="d-inline"
                  onsubmit="return confirm('<?= e('loc.delete_confirm') ?>');">
                <?= csrf_field() ?>
                <button type="submit" name="delete_loc" value="<?= $node['id'] ?>" class="btn btn-sm btn-outline-danger" title="<?= e('loc.delete') ?>"><i class="bi bi-trash"></i></button>
            </form>
        </span>
    </div>
    <?php
    foreach ($children as $child) renderLocNode($child, $map, $depth + 1);
}

$roots = locationChildren($map, null);
?>

<style>
    .loc-node { padding: 8px 12px; margin: 6px 0; border: 1px solid var(--bs-border-color); border-radius: 8px; background: var(--bs-tertiary-bg); }
    .loc-kind { font-size: .72rem; font-weight: 600; padding: 2px 9px; border-radius: 20px; white-space: nowrap; }
    .loc-kind.k-zone    { background: rgba(192,122,30,.14); color: #a5641a; }
    .loc-kind.k-kuti    { background: rgba(59,110,143,.14); color: #2f5f80; }
    .loc-kind.k-room    { background: var(--bs-secondary-bg); color: var(--bs-secondary-color); }
    .loc-kind.k-special { background: rgba(63,125,94,.16); color: #2f6a4d; }
    [data-bs-theme="dark"] .loc-kind.k-zone { color: #e0a24a; } [data-bs-theme="dark"] .loc-kind.k-kuti { color: #7bb0cf; } [data-bs-theme="dark"] .loc-kind.k-special { color: #7fc39c; }
</style>

<div class="container mt-3 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="fw-bold mb-0"><i class="bi bi-diagram-3-fill"></i> <?= e('loc.title') ?></h3>
            <div class="text-body-secondary small"><?= e('loc.subtitle') ?></div>
        </div>
        <a href="index.php?page=dashboard" class="btn btn-outline-secondary btn-sm rounded-pill">
            <i class="bi bi-arrow-left"></i> <?= e('nav.dashboard') ?>
        </a>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card shadow-sm border-0 rounded-3 mb-4">
        <div class="card-header bg-primary text-white py-3 d-flex align-items-center">
            <h6 class="mb-0"><i class="bi bi-pencil-square"></i> <span id="locFormTitle"><?= e('loc.form_add_root') ?></span></h6>
        </div>
        <div class="card-body">
            <form method="POST" action="index.php?page=location_manage" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="loc_id" id="loc_id" value="0">
                <input type="hidden" name="parent_id" id="loc_parent_id" value="">

                <div class="col-md-4">
                    <label class="form-label small fw-bold"><?= e('loc.f_name') ?></label>
                    <input type="text" name="name" id="loc_name" class="form-control" placeholder="<?= e('loc.f_name_hint') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold"><?= e('loc.f_kind') ?></label>
                    <select name="kind" id="loc_kind" class="form-select">
                        <?php foreach (LOC_KINDS as $k => $label): ?>
                            <option value="<?= $k ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-bold d-block"><?= e('loc.flags') ?></label>
                    <div class="form-check form-switch form-check-inline">
                        <input class="form-check-input" type="checkbox" name="is_shared" id="loc_shared" value="1">
                        <label class="form-check-label small" for="loc_shared" title="<?= e('loc.flag_shared_hint') ?>"><?= e('loc.flag_shared') ?></label>
                    </div>
                    <div class="form-check form-switch form-check-inline">
                        <input class="form-check-input" type="checkbox" name="assignable" id="loc_assign" value="1" checked>
                        <label class="form-check-label small" for="loc_assign" title="<?= e('loc.flag_assignable_hint') ?>"><?= e('loc.flag_assignable') ?></label>
                    </div>
                    <div class="form-check form-switch form-check-inline">
                        <input class="form-check-input" type="checkbox" name="display_from" id="loc_display" value="1">
                        <label class="form-check-label small" for="loc_display" title="<?= e('loc.flag_display_hint') ?>"><?= e('loc.flag_display') ?></label>
                    </div>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" name="save_loc" value="1" class="btn btn-primary fw-bold">
                        <i class="bi bi-check-lg"></i> <?= e('loc.save') ?>
                    </button>
                    <button type="button" id="locFormReset" class="btn btn-outline-secondary">
                        <i class="bi bi-x-lg"></i> <?= e('loc.cancel') ?>
                    </button>
                    <span class="align-self-center text-body-secondary small ms-2"><i class="bi bi-info-circle"></i> <?= e('loc.max_depth_note') ?></span>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 mb-2">
        <button type="button" id="locAddRoot" class="btn btn-success btn-sm fw-bold">
            <i class="bi bi-plus-lg"></i> <?= e('loc.add_root') ?>
        </button>
        <span class="text-body-secondary small ms-2 d-flex gap-3 flex-wrap">
            <span><span class="badge bg-info-subtle text-info border border-info"><?= e('loc.tag_shared') ?></span> <?= e('loc.flag_shared') ?></span>
            <span><span class="badge bg-success-subtle text-success border border-success"><?= e('loc.tag_assign') ?></span> <?= e('loc.flag_assignable') ?></span>
            <span><span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><?= e('loc.tag_display') ?></span> <?= e('loc.flag_display') ?></span>
        </span>
    </div>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body">
            <?php if (empty($roots)): ?>
                <div class="text-center text-body-secondary py-4"><?= e('loc.empty') ?></div>
            <?php else: foreach ($roots as $root) renderLocNode($root, $map, 0); endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const f = {
        id:      document.getElementById('loc_id'),
        parent:  document.getElementById('loc_parent_id'),
        name:    document.getElementById('loc_name'),
        kind:    document.getElementById('loc_kind'),
        shared:  document.getElementById('loc_shared'),
        assign:  document.getElementById('loc_assign'),
        display: document.getElementById('loc_display'),
        title:   document.getElementById('locFormTitle'),
    };
    const T = {
        addRoot:  <?= json_encode(t('loc.form_add_root')) ?>,
        addUnder: <?= json_encode(t('loc.form_add_under')) ?>,
        edit:     <?= json_encode(t('loc.form_edit')) ?>,
    };

    function reset() {
        f.id.value = '0'; f.parent.value = '';
        f.name.value = ''; f.kind.value = '0';
        f.shared.checked = false; f.assign.checked = true; f.display.checked = false;
        f.title.textContent = T.addRoot;
    }
    function focusName() { f.name.focus(); window.scrollTo({ top: 0, behavior: 'smooth' }); }

    document.getElementById('locAddRoot').addEventListener('click', function () { reset(); focusName(); });
    document.getElementById('locFormReset').addEventListener('click', reset);

    document.querySelectorAll('.loc-add-child').forEach(function (btn) {
        btn.addEventListener('click', function () {
            reset();
            f.parent.value = btn.dataset.parent;
            f.kind.value = btn.dataset.kind || '0';
            f.title.textContent = T.addUnder.replace('%s', btn.dataset.parentName || '');
            focusName();
        });
    });

    document.querySelectorAll('.loc-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            reset();
            f.id.value = btn.dataset.id;
            f.name.value = btn.dataset.name || '';
            f.kind.value = btn.dataset.kind || '0';
            f.shared.checked  = btn.dataset.shared  === '1';
            f.assign.checked  = btn.dataset.assign  === '1';
            f.display.checked = btn.dataset.display === '1';
            f.title.textContent = T.edit.replace('%s', btn.dataset.name || '');
            focusName();
        });
    });
})();
</script>
