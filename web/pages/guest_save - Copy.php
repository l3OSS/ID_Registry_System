<?php
/**
 * Core Logic: Guest Data Saving & Stay Processing
 * Handles multi-table transactions for citizens, mapping, and stay history.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/log.php';

// Access Control
checkLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../index.php?page=guest_list");
    exit();
}

/**
 * --- 1. Data Sanitization & Preparation ---
 */
$decision = $_POST['decision'] ?? 'new';
$existing_citizen_id = filter_input(INPUT_POST, 'existing_guest_id', FILTER_VALIDATE_INT) ?: 0;
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;

// Format Identity Data
$id_card_raw = str_replace(['-', ' '], '', $_POST['id_card'] ?? '');
$id_card_hash = hashID($id_card_raw);
$id_card_enc  = encryptData($id_card_raw);
$id_card_last4 = substr($id_card_raw, -4);

// Format Personal Data
$prefix    = trim($_POST['prefix'] ?? '');
$firstname = trim($_POST['firstname'] ?? '');
$lastname  = trim($_POST['lastname'] ?? '');
$gender    = $_POST['gender'] ?? '';
$prefix = trim($_POST['prefix'] ?? '');

// 1. ถ้าเป็น "พระ" หรือ "นาย" หรือคำนำหน้าที่เป็นชาย ให้บังคับเป็น Male
if (in_array($prefix, ['นาย', 'พระ', 'พระมหา', 'สามเณร', 'เด็กชาย', 'ด.ช.'])) {
    $gender = 'Male';
} 
// 2. ถ้าเป็นคำนำหน้าที่เป็นหญิง ให้บังคับเป็น Female
elseif (in_array($prefix, ['นาง', 'นางสาว', 'เด็กหญิง', 'ด.ญ.'])) {
    $gender = 'Female';
}
// 3. กรณีส่งมาจาก Smart Card เป็นภาษาไทย ให้แปลงเป็น English (ตาม Enum DB)
if ($gender === 'ชาย') $gender = 'Male';
if ($gender === 'หญิง') $gender = 'Female';

$birthdate = !empty($_POST['birthdate']) ? $_POST['birthdate'] : null;

// Handle Year (Auto-fix Buddhist Era to Christian Era)
if ($birthdate && (int)explode('-', $birthdate)[0] > 2400) {
    $parts = explode('-', $birthdate);
    $parts[0] -= 543;
    $birthdate = implode('-', $parts);
}

// Format Contact & Security Data
$phone_enc    = encryptData(trim($_POST['phone'] ?? ''));
$is_consent   = isset($_POST['pdpa_consent']) ? 1 : 0;
$check_in_at  = $_POST['check_in_date'] ?? date('Y-m-d H:i');
$loc_type     = $_POST['location_type'] ?? 'Inside';

/**
 * --- 2. Database Transaction Start ---
 */
try {
    $pdo->beginTransaction();
    $citizen_id = 0;
    $log_action = "";

    // Group common citizen data for SQL
    $citizen_params = [
        ':enc'     => $id_card_enc,
        ':last4'   => $id_card_last4,
        ':prefix'  => $prefix,
        ':fname'   => $firstname,
        ':lname'   => $lastname,
        ':gender'  => $gender,
        ':dob'     => $birthdate,
        ':anum'    => trim($_POST['addr_number'] ?? ''),
        ':atam'    => trim($_POST['addr_tambon'] ?? ''),
        ':aamp'    => trim($_POST['addr_amphoe'] ?? ''),
        ':aprov'   => trim($_POST['addr_province'] ?? ''),
        ':phone'   => $phone_enc,
        ':med'     => trim($_POST['medical_info'] ?? ''),
        ':notes'   => trim($_POST['notes'] ?? ''),
        ':consent' => $is_consent
    ];

    // --- CASE A: Update or Re-use existing record ---
    if ($existing_citizen_id > 0) {
        $citizen_id = $existing_citizen_id;
        if ($decision === 'update') {
            $sql = "UPDATE citizens SET id_card_enc=:enc, id_card_last4=:last4, prefix=:prefix, firstname=:fname, lastname=:lname, 
                    gender=:gender, birthdate=:dob, addr_number=:anum, addr_tambon=:atam, addr_amphoe=:aamp, addr_province=:aprov, 
                    phone_enc=:phone, medical_info=:med, notes=:notes, is_consent=:consent, updated_at=NOW() WHERE id=:cid";
            $pdo->prepare($sql)->execute(array_merge($citizen_params, [':cid' => $citizen_id]));
            $log_action = "UPDATE_CITIZEN";
        } else {
            // Keep Old: Just update consent status
            $pdo->prepare("UPDATE citizens SET is_consent = ?, updated_at = NOW() WHERE id = ?")->execute([$is_consent, $citizen_id]);
            $log_action = "VERIFY_EXISTING";
        }
    } 
    // --- CASE B: New Record or Edit from existing ID ---
    else {
        if ($id > 0) {
            $citizen_id = $id;
            $sql = "UPDATE citizens SET id_card_enc=:enc, id_card_hash=:hash, id_card_last4=:last4, prefix=:prefix, firstname=:fname, lastname=:lname, 
                    gender=:gender, birthdate=:dob, addr_number=:anum, addr_tambon=:atam, addr_amphoe=:aamp, addr_province=:aprov, 
                    phone_enc=:phone, medical_info=:med, notes=:notes, is_consent=:consent, updated_at=NOW() WHERE id=:cid";
            $pdo->prepare($sql)->execute(array_merge($citizen_params, [':cid' => $citizen_id, ':hash' => $id_card_hash]));
            $log_action = "EDIT_CITIZEN";
        } else {
            $sql = "INSERT INTO citizens (id_card_hash, id_card_enc, id_card_last4, prefix, firstname, lastname, gender, birthdate, addr_number, addr_tambon, addr_amphoe, addr_province, phone_enc, medical_info, notes, is_consent) 
                    VALUES (:hash, :enc, :last4, :prefix, :fname, :lname, :gender, :dob, :anum, :atam, :aamp, :aprov, :phone, :med, :notes, :consent)";
            $pdo->prepare($sql)->execute(array_merge($citizen_params, [':hash' => $id_card_hash]));
            $citizen_id = $pdo->lastInsertId();
            $log_action = "REGISTER_NEW";
        }
    }

    // --- 3. Vulnerable & Custom Fields Mapping ---
    // Clear and Rewrite Mapping for Data Integrity
    $pdo->prepare("DELETE FROM citizen_vulnerable_map WHERE citizen_id = ?")->execute([$citizen_id]);
    if (!empty($_POST['vulnerable']) && is_array($_POST['vulnerable'])) {
        $stmt = $pdo->prepare("INSERT INTO citizen_vulnerable_map (citizen_id, v_id) VALUES (?, ?)");
        foreach ($_POST['vulnerable'] as $v_id) {
            if ($v_id > 0) $stmt->execute([$citizen_id, $v_id]);
        }
    }

    $pdo->prepare("DELETE FROM citizen_custom_values WHERE citizen_id = ?")->execute([$citizen_id]);
    if (!empty($_POST['custom']) && is_array($_POST['custom'])) {
        $stmt = $pdo->prepare("INSERT INTO citizen_custom_values (citizen_id, field_id, field_value) VALUES (?, ?, ?)");
        foreach ($_POST['custom'] as $f_id => $f_val) {
            $val = trim($f_val);
            if ($val !== '') $stmt->execute([$citizen_id, $f_id, $val]);
        }
    }

    // --- 4. Stay History Processing ---
    $msg = "สำเร็จ: บันทึกข้อมูลเรียบร้อย";
    $active_check = $pdo->prepare("SELECT id FROM stay_history WHERE citizen_id = ? AND status = 'Active'");
    $active_check->execute([$citizen_id]);

    if ($res = $active_check->fetch()) {
        $msg .= " (บุคคลนี้อยู่ระหว่างการเข้าพักเดิม)";
    } else {
        $sql_stay = "INSERT INTO stay_history (citizen_id, check_in, location_type, status, admin_id) VALUES (?, ?, ?, 'Active', ?)";
        $pdo->prepare($sql_stay)->execute([$citizen_id, $check_in_at, $loc_type, $_SESSION['user_id']]);
        $msg .= " และแจ้งเข้าพัก (Check-in) สำเร็จ";
        writeLog($pdo, 'CHECK_IN', "Guest ID: $citizen_id checked in at $loc_type");
    }

    writeLog($pdo, $log_action, "$log_action for Citizen ID: $citizen_id");
    $pdo->commit();

    // Final Redirect
    echo "<script>alert('$msg'); window.location='../index.php?page=guest_list';</script>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Save Error: " . $e->getMessage());
    die("❌ เกิดข้อผิดพลาดทางเทคนิค: " . $e->getMessage());
}