<?php
/**
 * Page: System Settings (Custom Fields)
 * ปรับปรุง: ปิดตัวเลือกค้นหาสำหรับฟิลด์ประเภท Text เพื่อป้องกันบั๊กหน้าลิสรายชื่อ
 */

require_once 'core/auth.php';
require_once 'core/log.php';

// --- 1. Access & Security Check ---
$can_access = (isset($_SESSION['role_level']) && (int)$_SESSION['role_level'] === 1);

if (!$can_access) {
    echo '
    <div class="container mt-5">
        <div class="alert alert-danger shadow-sm border-0 p-4 rounded-3 text-center">
            <i class="bi bi-exclamation-octagon-fill fs-1 d-block mb-3"></i>
            <h4 class="fw-bold">ขออภัย คุณไม่มีสิทธิ์เข้าถึงการตั้งค่าระบบ</h4>
            <a href="index.php" class="btn btn-outline-danger rounded-pill px-4">
                <i class="bi bi-arrow-left"></i> กลับหน้าหลัก
            </a>
        </div>
    </div>';
    exit;
}

// --- 1. Action: Add New Field ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_field'])) {
    $name   = trim($_POST['field_name']);
    $type   = $_POST['field_type'];
    // ถ้าเป็น text ให้บังคับเป็น 0 เสมอเพื่อความปลอดภัย
    $search = ($type === 'text') ? 0 : (isset($_POST['is_searchable']) ? 1 : 0);
    
    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO custom_field_master (field_name, field_type, is_searchable, is_active) VALUES (?, ?, ?, 1)");
            $stmt->execute([$name, $type, $search]);
            writeLog($pdo, 'ADD_CUSTOM_FIELD', "เพิ่มฟิลด์ใหม่: $name ($type) [Searchable: $search]");
            $_SESSION['success_msg'] = "เพิ่มฟิลด์ใหม่เรียบร้อยแล้ว";
        } catch (PDOException $e) {
            $_SESSION['error_msg'] = "ไม่สามารถเพิ่มฟิลด์ได้: ชื่ออาจซ้ำ";
        }
    }
}

// --- 2. Action: Update Existing Field ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_field'])) {
    $field_id = filter_input(INPUT_POST, 'field_id', FILTER_VALIDATE_INT);
    $name     = trim($_POST['field_name']);
    $active   = isset($_POST['is_active']) ? 1 : 0;
    
    // ดึงประเภทดั้งเดิมมาเช็คก่อนบันทึก
    $stmt_check = $pdo->prepare("SELECT field_type FROM custom_field_master WHERE id = ?");
    $stmt_check->execute([$field_id]);
    $f_type = $stmt_check->fetchColumn();

    $search   = ($f_type === 'text') ? 0 : (isset($_POST['is_searchable']) ? 1 : 0);

    if ($field_id && !empty($name)) {
        $stmt = $pdo->prepare("UPDATE custom_field_master SET field_name = ?, is_active = ?, is_searchable = ? WHERE id = ?");
        $stmt->execute([$name, $active, $search, $field_id]);
        $_SESSION['success_msg'] = "อัปเดตข้อมูลเรียบร้อยแล้ว";
    }
}

$fields = $pdo->query("SELECT * FROM custom_field_master ORDER BY id ASC")->fetchAll();
?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-dark"><i class="bi bi-gear-fill text-primary"></i> ตั้งค่าฟิลด์ข้อมูลเสริม</h3>
    </div>

    <div class="card shadow-sm mb-4 border-0 rounded-3">
        <div class="card-header bg-primary text-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-plus-circle"></i> เพิ่มฟิลด์เก็บข้อมูลใหม่</h6>
        </div>
        <div class="card-body p-4">
            <form method="POST" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-bold">ชื่อหัวข้อข้อมูล</label>
                    <input type="text" name="field_name" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">ประเภทช่องกรอก</label>
                    <select name="field_type" id="field_type_add" class="form-select" onchange="toggleSearchOption('add')">
                        <option value="text">🔤 ช่องพิมพ์ข้อความ (Text)</option>
                        <option value="checkbox" selected>✅ ตัวเลือก ใช่/ไม่ใช่ (Checkbox)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="is_searchable" id="isSearchAdd" checked>
                        <label class="form-check-label small fw-bold" for="isSearchAdd">แสดงหน้าค้นหา</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" name="add_field" class="btn btn-primary w-100 shadow-sm">เพิ่มฟิลด์</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-responsive bg-white shadow-sm rounded-3">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">ชื่อหัวข้อข้อมูล</th>
                    <th>ประเภท</th>
                    <th class="text-center">สถานะใช้งาน</th>
                    <th class="text-center">การค้นหา</th>
                    <th class="text-end pe-4">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($fields as $f): ?>
                <tr>
                    <td class="ps-4 fw-bold"><?php echo htmlspecialchars($f['field_name']); ?></td>
                    <td>
                        <span class="badge bg-light text-dark border">
                            <?php echo $f['field_type'] === 'text' ? 'Text' : 'Checkbox'; ?>
                        </span>
                    </td>
                    <td class="text-center"><?php echo $f['is_active'] ? 'เปิด' : 'ปิด'; ?></td>
                    <td class="text-center">
                        <?php if($f['field_type'] === 'text'): ?>
                            <span class="text-muted small">-</span>
                        <?php else: ?>
                            <?php echo $f['is_searchable'] ? '<i class="bi bi-search text-primary"></i>' : '<i class="bi bi-dash"></i>'; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                        <button class="btn btn-sm btn-outline-warning rounded-pill" onclick='openEditModal(<?php echo json_encode($f); ?>)'>แก้ไข</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="editFieldModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold">แก้ไขข้อมูลหัวข้อเสริม</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="field_id" id="edit_field_id">
                <input type="hidden" id="edit_field_type"> <div class="mb-4">
                    <label class="form-label fw-bold small">ชื่อหัวข้อข้อมูล</label>
                    <input type="text" name="field_name" id="edit_field_name" class="form-control" required>
                </div>

                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 border rounded bg-light">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                                <label class="form-check-label fw-bold small">เปิดใช้งาน</label>
                            </div>
                        </div>
                    </div>
                    <div id="search_option_wrapper" class="col-6">
                        <div class="p-3 border rounded bg-light">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_searchable" id="edit_is_searchable">
                                <label class="form-check-label fw-bold small">ใช้ในการค้นหา</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="update_field" class="btn btn-primary rounded-pill px-4">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
/**
 * จัดการการแสดงผลตัวเลือกค้นหา
 */
function toggleSearchOption(mode) {
    if(mode === 'add') {
        const type = document.getElementById('field_type_add').value;
        const searchSwitch = document.getElementById('isSearchAdd');
        if(type === 'text') {
            searchSwitch.checked = false;
            searchSwitch.disabled = true;
        } else {
            searchSwitch.disabled = false;
        }
    }
}

function openEditModal(field) {
    document.getElementById('edit_field_id').value = field.id;
    document.getElementById('edit_field_name').value = field.field_name;
    document.getElementById('edit_is_active').checked = parseInt(field.is_active) === 1;
    document.getElementById('edit_field_type').value = field.field_type;
    
    const searchSwitch = document.getElementById('edit_is_searchable');
    const searchWrapper = document.getElementById('search_option_wrapper');

    if(field.field_type === 'text') {
        searchSwitch.checked = false;
        searchWrapper.style.opacity = '0.5';
        searchSwitch.disabled = true;
    } else {
        searchSwitch.checked = parseInt(field.is_searchable) === 1;
        searchWrapper.style.opacity = '1';
        searchSwitch.disabled = false;
    }
    
    new bootstrap.Modal(document.getElementById('editFieldModal')).show();
}

// รันครั้งแรกเพื่อเซ็ตสถานะหน้า Add
document.addEventListener('DOMContentLoaded', () => toggleSearchOption('add'));
</script>