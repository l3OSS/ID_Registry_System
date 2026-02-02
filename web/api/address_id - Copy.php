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
$district      = trim($_GET['district'] ?? '');
$amphoe        = trim($_GET['amphoe'] ?? '');
$province      = trim($_GET['province'] ?? '');

try {
    if ($district_code !== '') {
        $stmt = $pdo->prepare(
            "SELECT id FROM address_lookup WHERE district_code = ? LIMIT 1"
        );
        $stmt->execute([$district_code]);
        if ($id = $stmt->fetchColumn()) {
            echo json_encode(['status' => 'success', 'address_id' => $id]);
            exit;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM address_lookup
         WHERE subdistrict LIKE ?
           AND district LIKE ?
           AND province LIKE ?
         LIMIT 1"
    );
    $stmt->execute(["%$district%", "%$amphoe%", "%$province%"]);

    $id = $stmt->fetchColumn();

    echo json_encode(
        $id
            ? ['status' => 'success', 'address_id' => $id]
            : ['status' => 'not_found']
    );
} catch (PDOException) {
    http_response_code(500);
    echo json_encode(['status' => 'error']);
}
