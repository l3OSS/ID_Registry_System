<?php
require_once 'config/db.php'; 

// ปรับ Path ให้ตรงกับไฟล์ของคุณ
$json_path = 'raw_th_addr.json'; 

if (!file_exists($json_path)) die("❌ ไม่พบไฟล์ JSON");

$json_data = file_get_contents($json_path);
$data = json_decode($json_data, true);

if (!$data) die("❌ JSON รูปแบบไม่ถูกต้อง");

try {
    // 1. ล้างตารางเดิมก่อนเริ่ม
    $pdo->exec("TRUNCATE TABLE address_lookup");

    // 2. เริ่ม Transaction ครั้งเดียว (เร็วและปลอดภัย)
    $pdo->beginTransaction();

    $sql = "INSERT INTO address_lookup (subdistrict, district, province, zipcode, district_code) 
            VALUES (:sub, :dist, :prov, :zip, :code)";
    $stmt = $pdo->prepare($sql);

    $count = 0;
foreach ($data as $index => $item) {
    // ตรวจสอบและจัดการค่าที่อาจเป็น false หรือว่าง
    $subdistrict   = $item['district'] ?? '';
    $district      = $item['amphoe'] ?? '';
    $province      = $item['province'] ?? '';
    
    // ถ้า zipcode ว่าง ให้เป็น NULL
    $zipcode       = (!empty($item['zipcode'])) ? $item['zipcode'] : NULL;
    
    // ถ้า district_code เป็น false หรือว่าง ให้เป็น 0
    $district_code = ($item['district_code'] !== false && !empty($item['district_code'])) 
                     ? $item['district_code'] 
                     : 0;

    $params = [
        ':sub'  => $subdistrict,
        ':dist' => $district,
        ':prov' => $province,
        ':zip'  => $zipcode,
        ':code' => $district_code
    ];

    $stmt->execute($params);
    $count++;
}
    // 3. ถ้าทำงานครบทุกแถวค่อยสั่งบันทึกจริง
    $pdo->commit();
    echo "✅ นำเข้าข้อมูลสำเร็จทั้งหมด $count รายการ";

} catch (Exception $e) {
    // 4. หากผิดพลาดแม้แต่แถวเดียว ให้ยกเลิกทั้งหมดเพื่อความสะอาดของข้อมูล
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ เกิดข้อผิดพลาด: " . $e->getMessage();
}