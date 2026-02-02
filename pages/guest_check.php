<?php
/**
 * Logic: Guest Check & Duplicate Detection
 * Handles data saving, photo processing, and duplicate record comparison.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/log.php'; 
require_once __DIR__ . '/../core/functions.php';

checkLogin();
date_default_timezone_set('Asia/Bangkok');

// --- 1. Helper Functions ---

/**
 * จัดการรูปแบบวันที่เช็คอินให้เป็นมาตรฐาน Database
 */
function processCheckInDate($input_date) {
    if (empty($input_date)) return date('Y-m-d H:i:s');
    $input_date = trim($input_date);
    
    // กรณี ISO Format
    if (preg_match('/^(20|19)\d{2}-\d{2}-\d{2}/', $input_date)) {
        return (strlen($input_date) <= 16) ? $input_date . ":00" : $input_date;
    }
    
    // กรณีปี พ.ศ. หรือรูปแบบอื่นๆ
    $parts = preg_split('/[\/\-\s:]/', $input_date);
    if (count($parts) >= 3) {
        $year = intval($parts[2]);
        if ($year > 2400) $year -= 543;
        return sprintf("%04d-%02d-%02d %02d:%02d:00", $year, $parts[1], $parts[0], $parts[3] ?? 0, $parts[4] ?? 0);
    }
    return date('Y-m-d H:i:s');
}

/**
 * ค้นหา ID ที่อยู่จากฐานข้อมูล Master
 */
function lookupAddressId($pdo, $tambon, $amphoe, $province) {
    $t = str_replace(['ตำบล', 'ต.'], '', trim($tambon));
    $a = str_replace(['อำเภอ', 'อ.'], '', trim($amphoe));
    $p = str_replace(['จังหวัด', 'จ.'], '', trim($province));

    $stmt = $pdo->prepare("SELECT id FROM address_lookup WHERE subdistrict LIKE ? AND district LIKE ? AND province LIKE ? LIMIT 1");
    $stmt->execute(["%$t%", "%$a%", "%$p%"]);
    $res = $stmt->fetch();
    return $res ? $res['id'] : null;
}

/**
 * สร้าง Hidden Inputs สำหรับรักษาค่า POST ระหว่างการเปลี่ยนหน้า
 */
function renderHiddenInputs($data, $prefix = '') {
    foreach ($data as $key => $value) {
        $name = ($prefix === '') ? $key : "{$prefix}[{$key}]";
        if (is_array($value)) {
            renderHiddenInputs($value, $name);
        } else {
            echo '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">' . PHP_EOL;
        }
    }
}

// --- 2. Main Saving Logic (Transaction) ---

function saveCitizenAndStay($pdo, $post_data, $old_data = null, $decision = null, $action_type = 'insert') {
    try {
        $pdo->beginTransaction();

        $id_card_raw = str_replace(['-', ' '], '', $post_data['id_card']);
        $id_card_hash = hashID($id_card_raw);
        $id_card_enc = encryptData($id_card_raw);
        $id_card_last4 = substr($id_card_raw, -4);
        
        $prefix = $post_data['prefix'] ?? '';
        $firstname = $post_data['firstname'] ?? '';
        $lastname = $post_data['lastname'] ?? '';
        $birthdate = !empty($post_data['birthdate']) ? $post_data['birthdate'] : NULL;
        
        $gender_input = $post_data['gender'] ?? '';
        $gender = null;
        if(in_array($gender_input, ['Male','ชาย','1'])) $gender = 'Male';
        elseif(in_array($gender_input, ['Female','หญิง','2'])) $gender = 'Female';

        $phone_enc = (!empty($post_data['phone'])) ? encryptData($post_data['phone']) : ($old_data['phone_enc'] ?? '');
        $medical_info = (!empty($post_data['medical_info'])) ? $post_data['medical_info'] : ($old_data['medical_info'] ?? '');
        $notes = (!empty($post_data['notes'])) ? $post_data['notes'] : ($old_data['notes'] ?? '');

        $address_id = !empty($post_data['address_id']) ? intval($post_data['address_id']) : null;
        // Fail-safe: ถ้า ID เป็นค่าว่าง ให้หาจากชื่อตำบล/อำเภอ/จังหวัด
if (!$address_id && !empty($post_data['addr_tambon'])) {
    $address_id = lookupAddressId($pdo, $post_data['addr_tambon'], $post_data['addr_amphoe'], $post_data['addr_province']);
}

        // จัดการรูปภาพ
        $photo_base64 = $post_data['photo_base64'] ?? '';
        $file_path_db = $old_data['photo_path'] ?? "";

        if (!empty($photo_base64)) {
            $folder_path = "../uploads/" . (date("Y") + 543) . "/" . date("m") . "/";
            if (!file_exists($folder_path)) mkdir($folder_path, 0777, true);
            $file_name = substr($id_card_hash, 0, 10) . "_" . date("YmdHis") . ".jpg";
            $decoded_image = base64_decode(explode(',', $photo_base64)[1] ?? $photo_base64);
            if($decoded_image && file_put_contents($folder_path . $file_name, $decoded_image)) {
                if (!empty($old_data['photo_path']) && file_exists("../" . $old_data['photo_path'])) {
                    unlink("../" . $old_data['photo_path']);
                }
                $file_path_db = str_replace('../', '', $folder_path) . $file_name;
            }
        }

        $age = 0;
if (!empty($birthdate) && $birthdate != '0000-00-00') {
    $birthDateObj = new DateTime($birthdate);
    $today = new DateTime();
    $age = $today->diff($birthDateObj)->y;
}

// ตรวจสอบและเพิ่มกลุ่มเป้าหมายอัตโนมัติลงใน Array vulnerable
if (!isset($post_data['vulnerable'])) {
    $post_data['vulnerable'] = [];
}

// ID 1 = เด็ก (0-5 ปี), ID 2 = ผู้สูงอายุ (60 ปีขึ้นไป)
// ตรวจสอบตาม Master Table ของคุณ
if ($age <= 5 && !in_array(1, $post_data['vulnerable'])) {
    $post_data['vulnerable'][] = 1; 
}
if ($age >= 60 && !in_array(2, $post_data['vulnerable'])) {
    $post_data['vulnerable'][] = 2;
}

        $citizen_id = 0;
        if (!$old_data) {
            // ส่วน INSERT สำหรับคนใหม่
            $sql = "INSERT INTO citizens (id_card_hash, id_card_enc, id_card_last4, prefix, firstname, lastname, gender, birthdate, address_id, addr_number, phone_enc, medical_info, notes, photo_path) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id_card_hash, $id_card_enc, $id_card_last4, $prefix, $firstname, $lastname, $gender, $birthdate, $address_id, $post_data['addr_number'], $phone_enc, $medical_info, $notes, $file_path_db]);
            $citizen_id = $pdo->lastInsertId();
        } else {
            $citizen_id = $old_data['id'];

            if ($decision == 'update') {
                // บันทึกเฉพาะเลข ID ที่อยู่ และเลขที่บ้านเท่านั้น
                $sql = "UPDATE citizens SET prefix=?, firstname=?, lastname=?, gender=?, birthdate=?, address_id=?, addr_number=?, phone_enc=?, medical_info=?, notes=?, photo_path=?, id_card_last4=?, updated_at=NOW() 
                        WHERE id=?";   
                $pdo->prepare($sql)->execute([$prefix, $firstname, $lastname, $gender, $birthdate, $address_id, $post_data['addr_number'], $phone_enc, $medical_info, $notes, $file_path_db, $id_card_last4, $citizen_id]);
                $log_action = "UPDATE_CITIZEN";
            }
        }

        // จัดการ Mapping (ล้างของเก่าแล้วลงใหม่)
        $pdo->prepare("DELETE FROM citizen_vulnerable_map WHERE citizen_id = ?")->execute([$citizen_id]);
        if (isset($post_data['vulnerable'])) {
            foreach ((array)$post_data['vulnerable'] as $v_id) {
                $pdo->prepare("INSERT INTO citizen_vulnerable_map (citizen_id, v_id) VALUES (?, ?)")->execute([$citizen_id, $v_id]);
            }
        }

        $pdo->prepare("DELETE FROM citizen_custom_values WHERE citizen_id = ?")->execute([$citizen_id]);
        if (isset($post_data['custom']) && is_array($post_data['custom'])) {
            foreach ($post_data['custom'] as $f_id => $f_val) {
                if (trim($f_val) !== '') {
                    $pdo->prepare("INSERT INTO citizen_custom_values (citizen_id, field_id, field_value) VALUES (?, ?, ?)")->execute([$citizen_id, $f_id, trim($f_val)]);
                }
            }
        }

        // --- 🏢 เพิ่มส่วนการเข้าพัก (Stay History) ---
        $stmt_stay = $pdo->prepare("SELECT id FROM stay_history WHERE citizen_id = ? AND status = 'Active'");
        $stmt_stay->execute([$citizen_id]);
        
        if (!$stmt_stay->fetch()) {
            $check_in_date = $post_data['check_in_date'] ?? date('Y-m-d H:i:s');
            $location_type = $post_data['location_type'] ?? 'Inside';
            $admin_id = $_SESSION['user_id'] ?? 0;

            $sql_stay = "INSERT INTO stay_history (citizen_id, check_in, location_type, status, admin_id) 
                         VALUES (?, ?, ?, 'Active', ?)";
            $pdo->prepare($sql_stay)->execute([$citizen_id, $check_in_date, $location_type, $admin_id]);
            
            writeLog($pdo, 'CHECK_IN', "เช็คอินบุคคล ID: $citizen_id ผ่านการอ่านบัตร");
        }

        $pdo->commit();
        header("Location: ../index.php?page=guest_history&id=" . $citizen_id);
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Saving Error: " . $e->getMessage());
        die("เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage());
    }
}

// --- 3. Routing & View Logic ---

$post_data = $_POST;
if (empty($post_data)) { header("Location: ../index.php?page=guest_form"); exit(); }

// Handle user decision from comparison page
if (isset($post_data['decision'])) {
    $stmt = $pdo->prepare("SELECT * FROM citizens WHERE id = ?");
    $stmt->execute([$post_data['existing_guest_id']]);
    saveCitizenAndStay($pdo, $post_data, $stmt->fetch(), $post_data['decision']);
    exit();
}

// Check for duplicate
$id_card_hash = hashID(str_replace(['-', ' '], '', $post_data['id_card'] ?? ''));
$stmt = $pdo->prepare("SELECT c.*, a.subdistrict, a.district, a.province FROM citizens c LEFT JOIN address_lookup a ON c.address_id = a.id WHERE c.id_card_hash = ?");
$stmt->execute([$id_card_hash]);
$old_data = $stmt->fetch();

if (!$old_data) {
    saveCitizenAndStay($pdo, $post_data);
    exit();
}

// If duplicate found, show comparison view
$stmt_stay = $pdo->prepare("SELECT check_in FROM stay_history WHERE citizen_id = ? AND status = 'Active'");
$stmt_stay->execute([$old_data['id']]);
$active_stay = $stmt_stay->fetch();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ตรวจสอบข้อมูลซ้ำ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .compare-card { border-radius: 15px; overflow: hidden; }
        .old-data { background-color: #fff4f4; }
        .new-data { background-color: #f4fff4; }
    </style>
</head>
<body class="bg-light">
<div class="container mt-5 mb-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="alert alert-warning shadow-sm border-start border-5 border-warning mb-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill fs-1 me-3"></i>
                    <div>
                        <h5 class="fw-bold mb-1">พบประวัติข้อมูลซ้ำในระบบ</h5>
                        <p class="mb-0">บุคคลนี้มีข้อมูลอยู่แล้ว คุณต้องการใช้ข้อมูลเดิมหรืออัปเดตใหม่ตามบัตรประชาชน?</p>
                    </div>
                </div>
            </div>

            
            <div class="card shadow-sm compare-card">
                <div class="card-header bg-dark text-white py-3">
                    <h5 class="mb-0"><i class="bi bi-arrow-left-right"></i> เปรียบเทียบข้อมูล</h5>
                </div>
                <form action="guest_check.php" method="POST">
                    <?php 
                    renderHiddenInputs($post_data); 
                    if(!isset($post_data['address_id']) && isset($address_id)) {
                        echo '<input type="hidden" name="address_id" value="'.$address_id.'">';
                            }
                    ?>
                    <input type="hidden" name="existing_guest_id" value="<?php echo $old_data['id']; ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light">
                                <tr class="text-center">
                                    <th width="20%">หัวข้อ</th>
                                    <th width="40%">💾 ข้อมูลเดิม</th>
                                    <th width="40%">📝 ข้อมูลใหม่</th>
                                </tr>
                            </thead>
                            <?php
                            $diff_name = ($old_data['firstname'] !== $post_data['firstname'] || $old_data['lastname'] !== $post_data['lastname']);
                            $diff_addr = ($old_data['subdistrict'] !== $post_data['addr_tambon'] || $old_data['province'] !== $post_data['addr_province']);
                            ?>

                            

<div class="row g-0 border-bottom">
    <div class="col-6 p-3 text-center old-data">
        <img src="../<?php echo !empty($old_data['photo_path']) ? $old_data['photo_path'] : 'assets/noimg.jpg'; ?>" class="img-thumbnail" style="height: 150px;">
        <div class="small mt-1 text-muted">รูปเดิมในระบบ</div>
    </div>
    <div class="col-6 p-3 text-center new-data">
        <img src="<?php echo !empty($post_data['photo_base64']) ? 'data:image/jpeg;base64,' . preg_replace('#^data:image/\w+;base64,#i', '', $post_data['photo_base64']) : '../assets/noimg.jpg'; ?>" class="img-thumbnail" style="height: 150px;">
        <div class="small mt-1 text-muted">รูปใหม่จากบัตร</div>
    </div>
</div>

                            <tbody>
                                <tr>
                                    <td class="fw-bold bg-light">ชื่อ-นามสกุล</td>
                                    <td class="text-center old-data"><?php echo htmlspecialchars($old_data['prefix'].$old_data['firstname'].' '.$old_data['lastname']); ?></td>
                                    <td class="text-center new-data <?php echo $diff_name ? 'text-danger fw-bold' : ''; ?>">
                                        <?php echo htmlspecialchars($post_data['prefix'].$post_data['firstname'].' '.$post_data['lastname']); ?>
                                        <?php echo $diff_name ? ' <i class="bi bi-exclamation-circle"></i>' : ''; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="fw-bold bg-light">เลขบัตรประชาชน</td>
                                    <td class="text-center old-data"><?php echo decryptData($old_data['id_card_enc']); ?></td>
                                    <td class="text-center new-data"><?php echo $post_data['id_card']; ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold bg-light">ที่อยู่</td>
                                    <td class="text-center old-data"><?php echo ($old_data['subdistrict']) ? "ต.{$old_data['subdistrict']} อ.{$old_data['district']} จ.{$old_data['province']}" : "ไม่ได้ระบุ"; ?></td>
                                    <td class="text-center new-data"> <?php echo "ต.{$post_data['addr_tambon']} อ.{$post_data['addr_amphoe']} จ.{$post_data['addr_province']}"; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="card-footer bg-white p-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <button type="submit" name="decision" value="update" class="btn btn-success w-100 py-3 fw-bold shadow-sm">
                                    <i class="bi bi-pencil-square"></i> อัปเดตข้อมูลใหม่
                                </button>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" name="decision" value="keep_old" class="btn btn-primary w-100 py-3 fw-bold shadow-sm">
                                    <i class="bi bi-shield-check"></i> ใช้ข้อมูลเดิม
                                </button>
                            </div>
                            <div class="col-md-4">
                                <a href="../index.php?page=guest_form" class="btn btn-outline-secondary w-100 py-3">
                                    <i class="bi bi-x-circle"></i> ยกเลิก
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>