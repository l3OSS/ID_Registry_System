<?php
/**
 * Helper Functions for Data Formatting
 */

/**
 * แปลงวันที่ ค.ศ. เป็น พ.ศ. พร้อมรูปแบบภาษาไทย
 */
function dateThai($strDate) {
    if (empty($strDate) || in_array($strDate, ['0000-00-00', '0000-00-00 00:00:00', 'null'])) {
        return '<span class="text-muted">-</span>';
    }

    $timestamp = strtotime($strDate);

    // แก้ไขกรณีวันที่ถูกบันทึกเป็นปี พ.ศ. (25xx) ลงในฐานข้อมูลโดยตรง
    if (!$timestamp || $timestamp < 0) {
        $parts = explode('-', explode(' ', $strDate)[0]);
        if (count($parts) == 3 && $parts[0] > 2400) {
            $parts[0] -= 543;
            $timestamp = strtotime(implode('-', $parts));
        }
    }

    if (!$timestamp) return '<span class="text-muted">-</span>';

    $thaiMonths = ["","ม.ค.","ก.พ.","มี.ค.","เม.ย.","พ.ค.","มิ.ย.","ก.ค.","ส.ค.","ก.ย.","ต.ค.","พ.ย.","ธ.ค."];
    $y = date("Y", $timestamp) + 543;
    $m = $thaiMonths[date("n", $timestamp)];
    $d = date("j", $timestamp);
    $t = date("H:i", $timestamp);

    return "<strong>$d $m $y</strong><br><small class='text-muted'>$t น.</small>";
}

/**
 * คำนวณอายุจากวันเกิด (รองรับทั้ง ค.ศ. และ พ.ศ.)
 */
function calculateAge($birthdate) {
    if (empty($birthdate) || $birthdate == '0000-00-00') return 0;
    
    try {
        $date = new DateTime($birthdate);
        // ดักจับถ้าปีเป็น พ.ศ. ให้ถอยกลับมาเป็น ค.ศ. ก่อนคำนวณ
        if ($date->format('Y') > 2400) {
            $date->modify('-543 years');
        }
        $today = new DateTime();
        return $today->diff($date)->y;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * ดึงกลุ่มเป้าหมายพิเศษ (Vulnerable groups)
 */
function getVulnerableText($pdo, $citizen_id, $age = null) {
    $stmt = $pdo->prepare("
        SELECT m.v_name FROM citizen_vulnerable_map map 
        JOIN vulnerable_master m ON map.v_id = m.id WHERE map.citizen_id = ?
    ");
    $stmt->execute([$citizen_id]);
    $items = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($age !== null) {
        if ($age <= 5) $items[] = "เด็ก (0-5 ปี)";
        if ($age >= 60) $items[] = "ผู้สูงอายุ";
    }

    $items = array_unique($items);
    return !empty($items) ? implode(", ", $items) : "-";
}