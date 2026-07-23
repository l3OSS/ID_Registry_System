<?php
/**
 * Logic: Guest Check & Duplicate Detection
 * Handles data saving, photo processing, and duplicate record comparison.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';   // requirePermission()/userCan() — guest_check ถูก POST ตรง ไม่ผ่าน router
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/log.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/lang.php';   // ข้อความทั้งระบบ — POST ตรง ไม่ผ่าน index.php
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/tx.php';

// เส้นทาง "เขียนข้อมูลจริง" — เดิมตรวจแค่ checkLogin() ผู้ใช้ระดับใดก็ POST เข้ามาได้ (ซ่อนปุ่มในฟอร์มไม่ใช่การป้องกัน)
requirePermission('guests.register');
csrf_verify(); // P2/S2: guest_check ถูก POST ตรง ไม่ผ่าน router — ตรวจที่นี่
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
 * ค้นหา ID ที่อยู่จากฐานข้อมูล Master — ตรรกะจริงอยู่ที่ core/functions.php (ใช้ร่วมกับ guest_import/api)
 */
function lookupAddressId($pdo, $tambon, $amphoe, $province, $zipcode = null) {
    return lookupAddressIdByName($pdo, $tambon, $amphoe, $province, $zipcode);
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
        // P4: ห่อทั้งชุดงานใน runInTransaction (begin/commit/rollBack + retry deadlock ที่เดียว)
        $citizen_id = runInTransaction($pdo, function ($pdo) use ($post_data, $old_data, $decision, $action_type) {

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
    $address_id = lookupAddressId($pdo, $post_data['addr_tambon'], $post_data['addr_amphoe'], $post_data['addr_province'], $post_data['addr_zipcode'] ?? null);
}

        // ภูมิลำเนา (กล่อง 3) — สวิตช์เปิด = ใช้ที่อยู่ตามทะเบียนบ้าน จึงเก็บแค่ธง ไม่เก็บซ้ำ (กันข้อมูลสองชุดเพี้ยนกัน)
        $home_same = !empty($post_data['home_same_as_reg']) ? 1 : 0;
        $home_address_id  = null;
        $home_addr_number = null;
        if (!$home_same) {
            $home_address_id = !empty($post_data['home_address_id']) ? intval($post_data['home_address_id']) : null;
            if (!$home_address_id && !empty($post_data['home_addr_tambon'])) {
                $home_address_id = lookupAddressId($pdo, $post_data['home_addr_tambon'], $post_data['home_addr_amphoe'] ?? '', $post_data['home_addr_province'] ?? '', $post_data['home_addr_zipcode'] ?? null);
            }
            $home_addr_number = ($post_data['home_addr_number'] ?? '') !== '' ? $post_data['home_addr_number'] : null;
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
            // ส่วน INSERT สำหรับคนใหม่ (P7: กำหนด public_id 13 หลักไม่ซ้ำ)
            $public_id = generatePublicId($pdo);
            $sql = "INSERT INTO citizens (public_id, id_card_hash, id_card_enc, id_card_last4, prefix, firstname, lastname, gender, birthdate, address_id, addr_number, home_same_as_reg, home_address_id, home_addr_number, phone_enc, medical_info, notes, photo_path)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$public_id, $id_card_hash, $id_card_enc, $id_card_last4, $prefix, $firstname, $lastname, $gender, $birthdate, $address_id, $post_data['addr_number'], $home_same, $home_address_id, $home_addr_number, $phone_enc, $medical_info, $notes, $file_path_db]);
            $citizen_id = $pdo->lastInsertId();
        } else {
            $citizen_id = $old_data['id'];

            if ($decision == 'update') {
                // บันทึกเฉพาะเลข ID ที่อยู่ และเลขที่บ้านเท่านั้น
                $sql = "UPDATE citizens SET prefix=?, firstname=?, lastname=?, gender=?, birthdate=?, address_id=?, addr_number=?, home_same_as_reg=?, home_address_id=?, home_addr_number=?, phone_enc=?, medical_info=?, notes=?, photo_path=?, id_card_last4=?, updated_at=NOW()
                        WHERE id=?";
                $pdo->prepare($sql)->execute([$prefix, $firstname, $lastname, $gender, $birthdate, $address_id, $post_data['addr_number'], $home_same, $home_address_id, $home_addr_number, $phone_enc, $medical_info, $notes, $file_path_db, $id_card_last4, $citizen_id]);
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
        $stmt_stay = $pdo->prepare("SELECT id FROM stay_history WHERE citizen_id = ? AND status = 'Active' ORDER BY id DESC LIMIT 1");
        $stmt_stay->execute([$citizen_id]);
        $active_stay_id = $stmt_stay->fetchColumn();

        $location_type = ($post_data['location_type'] ?? '') === 'Outside' ? 'Outside' : 'Inside';
        // ฟอร์มถูกเปิดมาแบบ "แก้ไข" หรือไม่ (hidden field action จาก guest_form — ผ่านหน้าเปรียบเทียบมาด้วย)
        $is_edit = ($post_data['action'] ?? '') === 'update';

        if (!$active_stay_id) {
            $check_in_date = $post_data['check_in_date'] ?? date('Y-m-d H:i:s');
            $admin_id = $_SESSION['user_id'] ?? 0;

            $sql_stay = "INSERT INTO stay_history (citizen_id, check_in, location_type, status, admin_id)
                         VALUES (?, ?, ?, 'Active', ?)";
            $pdo->prepare($sql_stay)->execute([$citizen_id, $check_in_date, $location_type, $admin_id]);

            writeLog($pdo, 'CHECK_IN', "เช็คอินบุคคล ID: $citizen_id ผ่านการอ่านบัตร");
        } elseif ($is_edit) {
            // กำลังพักอยู่ + เปิดมาจากปุ่มแก้ไข → อัปเดตประเภทที่พักตามที่เลือก
            // ไม่แตะ check_in เดิม เพื่อคงวันเข้าพักจริงไว้
            $pdo->prepare("UPDATE stay_history SET location_type = ? WHERE id = ?")
                ->execute([$location_type, $active_stay_id]);
        }
        // กำลังพักอยู่ แต่มาจากฟอร์ม "เพิ่มข้อมูล" (ลงทะเบียนซ้ำ/อ่านบัตรใหม่) → คงประเภทที่พักเดิมไว้
        // เพราะ dropdown ในฟอร์มเปล่าเป็นค่าเริ่มต้น "พักในศูนย์" ไม่ใช่เจตนาของเจ้าหน้าที่

        return $citizen_id;
        }); // จบ runInTransaction (commit อัตโนมัติเมื่อสำเร็จ)

        // P7: redirect ด้วย public_id แทน PK ภายใน
        $pub_stmt = $pdo->prepare("SELECT public_id FROM citizens WHERE id = ?");
        $pub_stmt->execute([$citizen_id]);
        $redirect_pid = $pub_stmt->fetchColumn() ?: '';
        header("Location: ../index.php?page=guest_history&id=" . urlencode((string)$redirect_pid));
        exit();

    } catch (Exception $e) {
        error_log("Saving Error: " . $e->getMessage());
        die(e('guest.err_save')); // P3/S7: ไม่เปิดเผยรายละเอียด error ต่อผู้ใช้
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

// หน้าเปรียบเทียบข้อมูลซ้ำ (แยก view ออก partial - คงพฤติกรรมเดิม)
include __DIR__ . "/partials/guest_compare.php";
