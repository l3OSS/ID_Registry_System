<?php
/**
 * System Activity Logging
 */

function writeLog($pdo, $action_type, $details) {
    $user_id = $_SESSION['user_id'] ?? NULL;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!$pdo) return;

    try {
        $sql = "INSERT INTO activity_logs (user_id, action_type, details, ip_address, created_at) 
                VALUES (?, ?, ?, ?, NOW())";
        $pdo->prepare($sql)->execute([$user_id, $action_type, $details, $ip_address]);
    } catch (PDOException $e) {
        // บันทึกลง System Error Log ของ PHP หากฐานข้อมูลมีปัญหา
        error_log("Critical Log Failure: " . $e->getMessage());
    }
}