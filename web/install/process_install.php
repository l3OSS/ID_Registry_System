<?php
// install/process_install.php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

// 1. รับค่าและเตรียมข้อมูล
$db_host = $_POST['db_host'] ?? 'localhost';
$db_name = $_POST['db_name'] ?? '';
$db_user = $_POST['db_user'] ?? '';
$db_pass = $_POST['db_pass'] ?? '';

$admin_user     = $_POST['admin_user'] ?? '';
$admin_pass_raw = $_POST['admin_pass'] ?? '';
$admin_pass_confirm = $_POST['admin_pass_confirm'] ?? '';
$admin_nickname = $_POST['admin_nickname'] ?? '';

// 🟢 ตรวจสอบความถูกต้องของรหัสผ่าน
if (strlen($admin_pass_raw) < 6) {
    die("❌ เกิดข้อผิดพลาด: รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร <button onclick='history.back()'>กลับไปแก้ไข</button>");
}
if ($admin_pass_raw !== $admin_pass_confirm) {
    die("❌ เกิดข้อผิดพลาด: รหัสผ่านทั้งสองช่องไม่ตรงกัน <button onclick='history.back()'>กลับไปแก้ไข</button>");
}

$admin_pass_hash = password_hash($admin_pass_raw, PASSWORD_DEFAULT);

try {
    // 2. เชื่อมต่อและสร้าง Database
    $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name` ");

    // 3. รันไฟล์ SQL Master Data
    $sql_file = 'sql/master_data.sql';
    if (!file_exists($sql_file)) throw new Exception("ไม่พบไฟล์ sql/master_data.sql");
    $sql_content = file_get_contents($sql_file);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);
    $pdo->exec($sql_content);

    // 4. สร้างบัญชี Admin
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, nickname, role_level) VALUES (?, ?, ?, 1)");
    $stmt->execute([$admin_user, $admin_pass_hash, $admin_nickname]);

    // 5. 🛡️ สร้างไฟล์ .env (เก็บกุญแจและความลับ)
    // สุ่มกุญแจ 32 bytes แล้ว Encode เป็น Base64 เพื่อใช้กับ AES-256-CBC
    $secure_key = base64_encode(openssl_random_pseudo_bytes(32)); 
    
    $env_content = "# Database Settings\n"
                 . "DB_HOST=\"$db_host\"\n"
                 . "DB_NAME=\"$db_name\"\n"
                 . "DB_USER=\"$db_user\"\n"
                 . "DB_PASS=\"$db_pass\"\n\n"
                 . "# Security Keys - Generated at: " . date('Y-m-d H:i:s') . "\n"
                 . "ENCRYPTION_KEY=\"$secure_key\"\n"
                 . "ENCRYPTION_METHOD=\"AES-256-CBC\"\n";
    
    file_put_contents('../.env', $env_content);

    // 6. บันทึกประวัติการติดตั้งลงตาราง settings
    $install_log = json_encode([
        'installed_at' => date('Y-m-d H:i:s'),
        'terms_accepted' => true,
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'db_name' => $db_name
    ], JSON_UNESCAPED_UNICODE);

    $sql_settings = "INSERT INTO settings (id, install_log) VALUES (1, ?) 
                     ON DUPLICATE KEY UPDATE install_log = ?";
    $pdo->prepare($sql_settings)->execute([$install_log, $install_log]);


    // 7. [ข้ามส่วน security.php] -> ไฟล์นี้จะถูก Commit ไว้ใน core/ เรียบร้อยแล้ว


    // 8. ⚙️ สร้างไฟล์ config/db.php (ดึงค่าจาก .env แทนการ Hardcode)
    $db_code = "<?php\n"
        . "// Database connection using .env variables\n"
        . "if (!class_exists('Dotenv\Dotenv')) {\n"
        . "    \$autoload = dirname(__DIR__) . '/vendor/autoload.php';\n"
        . "    if (file_exists(\$autoload)) {\n"
        . "        require_once \$autoload;\n"
        . "    }\n"
        . "}\n\n"
        . "if (empty(\$_ENV['DB_HOST'])) {\n"
        . "    try {\n"
        . "        \$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');\n"
        . "        \$dotenv->load();\n"
        . "    } catch (Exception \$e) { }\n"
        . "}\n\n"
        . "\$host = \$_ENV['DB_HOST'] ?? 'localhost';\n"
        . "\$db   = \$_ENV['DB_NAME'] ?? '';\n"
        . "\$user = \$_ENV['DB_USER'] ?? '';\n"
        . "\$pass = \$_ENV['DB_PASS'] ?? '';\n"
        . "\$charset = 'utf8mb4';\n\n"
        . "\$dsn = \"mysql:host=\$host;dbname=\$db;charset=\$charset\";\n"
        . "\$options = [\n"
        . "    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n"
        . "    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n"
        . "    PDO::ATTR_EMULATE_PREPARES   => false,\n"
        . "];\n\n"
        . "try {\n"
        . "    \$pdo = new PDO(\$dsn, \$user, \$pass, \$options);\n"
        . "} catch (PDOException \$e) {\n"
        . "    die('Connection failed: ' . \$e->getMessage());\n"
        . "}\n";

    file_put_contents('../config/db.php', $db_code);

    // 9. ล็อคการติดตั้ง
    file_put_contents('install.lock', date('Y-m-d H:i:s'));

    // 🟢 แสดงผลสำเร็จ
    echo "<div style='font-family:sans-serif; text-align:center; padding:50px;'>";
    echo "<h1 style='color:green;'>✅ ติดตั้งระบบเรียบร้อยแล้ว!</h1>";
    echo "<p>ระบบได้สร้างไฟล์ <b>.env</b> และตั้งค่าฐานข้อมูลให้คุณแล้ว</p>";
    echo "<div style='background:#fff3cd; padding:20px; border-radius:10px; display:inline-block; margin-top:20px;'>";
    echo "<p style='color:#856404;'><b>🛡️ คำแนะนำด้านความปลอดภัย:</b></p>";
    echo "<p>1. ไฟล์ <b>.env</b> ถูกสร้างขึ้นที่ Root Directory (กรุณาอย่าลบทิ้ง)</p>";
    echo "<p>2. กรุณาลบโฟลเดอร์ <b>/install</b> ออกจาก Server ทันที</p>";
    echo "</div>";
    echo "<br><br><a href='../index.php' style='padding:15px 30px; background:blue; color:white; text-decoration:none; border-radius:30px; font-weight:bold;'>เข้าสู่ระบบได้เลย</a>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div style='font-family:sans-serif; color:red; padding:30px; border:1px solid red;'>";
    echo "<h3>❌ การติดตั้งล้มเหลว</h3>";
    echo "<p>สาเหตุ: " . $e->getMessage() . "</p>";
    echo "<button onclick='history.back()'>กลับไปแก้ไข</button>";
    echo "</div>";
}