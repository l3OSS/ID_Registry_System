<?php
/**
 * scripts/bench_queries.php — วัดเวลา query จริงของหน้าที่หนักเมื่อข้อมูลเยอะ (ข้อ 1)
 * รัน: php scripts/bench_queries.php
 * วัดเฉพาะฝั่ง DB (ไม่รวมเรนเดอร์ PHP) เพื่อชี้คอขวดที่ต้องแก้
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require_once __DIR__ . '/../core/security.php';
$h=$_ENV['DB_HOST']??'localhost';$P=$_ENV['DB_PORT']??'3306';$d=$_ENV['DB_NAME'];$u=$_ENV['DB_USER'];$p=$_ENV['DB_PASS'];
$pdo=new PDO("mysql:host=$h;port=$P;dbname=$d;charset=utf8mb4",$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

function bench(string $label, callable $fn): void {
    $t = microtime(true);
    $info = $fn();
    printf("%-52s %8.1f ms   %s\n", $label, (microtime(true)-$t)*1000, $info);
}

echo "total citizens = " . number_format($pdo->query("SELECT COUNT(*) FROM citizens")->fetchColumn()) . "\n";
echo str_repeat('-', 90) . "\n";

// 1) guest_list หน้าแรก (default status=active) — COUNT + ดึงข้อมูลพร้อม ORDER BY correlated subquery
bench('guest_list COUNT(DISTINCT) + active subquery', function() use($pdo){
    $n=$pdo->query("SELECT COUNT(DISTINCT c.id) FROM citizens c
        LEFT JOIN address_lookup al ON c.address_id=al.id
        LEFT JOIN address_lookup hl ON c.home_address_id=hl.id
        WHERE c.id IN (SELECT citizen_id FROM stay_history WHERE status='Active')")->fetchColumn();
    return "rows=".number_format($n);
});

bench('guest_list ดึง 50 แถว (ORDER BY MAX subquery)', function() use($pdo){
    $r=$pdo->query("SELECT c.*, (SELECT MAX(check_in) FROM stay_history WHERE citizen_id=c.id) AS last_stay_date
        FROM citizens c
        LEFT JOIN address_lookup al ON c.address_id=al.id
        WHERE c.id IN (SELECT citizen_id FROM stay_history WHERE status='Active')
        ORDER BY last_stay_date DESC, c.created_at DESC LIMIT 50")->fetchAll();
    return "got=".count($r);
});

// 2) ค้นชื่อ LIKE '%...%'
bench("ค้นชื่อ LIKE '%สม%' (leading wildcard)", function() use($pdo){
    $r=$pdo->query("SELECT c.id FROM citizens c WHERE c.firstname LIKE '%สม%' LIMIT 50")->fetchAll();
    return "got=".count($r);
});

// 3) ค้นเลขบัตร 13 หลักด้วย hash (ควรเร็ว — มี index)
bench('ค้นเลขบัตรด้วย id_card_hash (index)', function() use($pdo){
    $st=$pdo->prepare("SELECT id FROM citizens WHERE id_card_hash=? LIMIT 1");
    $st->execute([hashID('1000000000009')]);
    return "found=".($st->fetchColumn()?'Y':'N');
});

// 4) OFFSET ลึก
bench('OFFSET ลึก LIMIT 50 OFFSET 5,000,000', function() use($pdo){
    $r=$pdo->query("SELECT id FROM citizens ORDER BY id LIMIT 50 OFFSET 5000000")->fetchAll();
    return "got=".count($r);
});

// 5) dashboard
bench("dashboard DATE(check_in)=CURDATE() (ห่อฟังก์ชัน)", function() use($pdo){
    return "n=".$pdo->query("SELECT COUNT(*) FROM stay_history WHERE DATE(check_in)=CURDATE()")->fetchColumn();
});
bench("dashboard COUNT active", function() use($pdo){
    return "n=".$pdo->query("SELECT COUNT(*) FROM stay_history WHERE status='Active'")->fetchColumn();
});
