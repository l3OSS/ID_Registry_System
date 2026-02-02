<?php
declare(strict_types=1);
/**
 * API: Address ID
 * ใช้หา Primary Key (ID) จากตาราง address_lookup 
 * โดยพยายามหาจากรหัสตำบล (district_code) ก่อนเพื่อความแม่นยำสูง
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';


$district_code = trim($_GET['district_code'] ?? '');

// 1. รับค่าจาก $_GET มาก่อน
$raw_district = trim($_GET['district'] ?? '');
$raw_amphoe   = trim($_GET['amphoe'] ?? '');
$raw_province = trim($_GET['province'] ?? '');

// 2. ค่อยสั่งล้างคำนำหน้าขยะออก
$district = str_replace(['ตำบล', 'ต.', ' '], '', $raw_district);
$amphoe   = str_replace(['อำเภอ', 'อ.', ' '], '', $raw_amphoe);
$province = str_replace(['จังหวัด', 'จ.', ' '], '', $raw_province);

try {
    // พยายามหาจากรหัสก่อน (ถ้าส่งมา)
    if ($district_code !== '') {
        $stmt = $pdo->prepare("SELECT id FROM address_lookup WHERE district_code = ? LIMIT 1");
        $stmt->execute([$district_code]);
        if ($id = $stmt->fetchColumn()) {
            echo json_encode(['status' => 'success', 'address_id' => $id]);
            exit;
        }
    }

    // ถ้าไม่เจอ ให้หาจากชื่อ (ใช้ LIKE %...% เพื่อลดความเป๊ะเกินไป)
    $stmt = $pdo->prepare("SELECT id FROM address_lookup WHERE subdistrict LIKE ? AND district LIKE ? AND province LIKE ? LIMIT 1");
    $stmt->execute(["%$district%", "%$amphoe%", "%$province%"]);
    $id = $stmt->fetchColumn();

    echo json_encode($id ? ['status' => 'success', 'address_id' => $id] : ['status' => 'not_found']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error occurred'
    ]);
}