<?php
declare(strict_types=1);
/**
 * API: Search Address (Select2 Support)
 * ค้นหาตำบล/อำเภอ/จังหวัด แบบพิมพ์ค้นหาอัตโนมัติ
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

$q = trim($_GET['q'] ?? '');
$results = [];

if (mb_strlen($q, 'UTF-8') < 2) {
    echo json_encode($results);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, subdistrict, district, province, zipcode
         FROM address_lookup
         WHERE subdistrict LIKE ?
            OR district LIKE ?
            OR province LIKE ?
         ORDER BY province, district, subdistrict
         LIMIT 20"
    );

    $search = "%{$q}%";
    $stmt->execute([$search, $search, $search]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $results[] = [
            'id'   => $row['id'],
            'text' => "{$row['subdistrict']} > {$row['district']} > {$row['province']} ({$row['zipcode']})"
        ];
    }

    echo json_encode($results, JSON_UNESCAPED_UNICODE);
} catch (PDOException) {
    http_response_code(500);
    echo json_encode([]);
}
