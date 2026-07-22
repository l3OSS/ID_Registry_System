<?php
/**
 * Page: Login
 * Handles user authentication with secure password hashing.
 */

// login.php ถูก include ผ่าน index.php (router) เป็นปกติ ซึ่งโหลด db/log ให้แล้ว
// require_once ไว้ด้วยเผื่อเปิดไฟล์นี้ตรง ๆ — ไม่โหลดซ้ำ และไม่ต้องพึ่ง constant หลอก (PDO_CONNECTION ไม่เคยถูก define)
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/session.php'; // ค่าคงที่ SESSION_REMEMBER_LIFETIME (จำการเข้าสู่ระบบ)
require_once __DIR__ . '/../core/log.php';
require_once __DIR__ . '/../core/lang.php';   // ข้อความทั้งระบบ — เผื่อเปิดไฟล์นี้ตรง ๆ

$error = '';

// Tier1: กัน brute-force — นับ LOGIN_FAILED จาก IP เดียวกันในหน้าต่างเวลา
// (ใช้ activity_logs ที่มีอยู่แล้ว ไม่ต้องสร้างตารางใหม่)
const LOGIN_LOCK_THRESHOLD  = 5;   // ผิดกี่ครั้งจึงล็อก
const LOGIN_LOCK_WINDOW_MIN = 15;  // นับย้อนหลังกี่นาที / ระยะเวลาล็อก

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['user_pass_new'] ?? '';
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $lock_since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_WINDOW_MIN * 60);

    try {
        // 0. ตรวจ rate-limit ก่อน — ถ้าผิดถี่เกินไปจากไอพีนี้ ไม่ต้องเช็ครหัสผ่านเลย
        $chk = $pdo->prepare(
            "SELECT COUNT(*) FROM activity_logs
             WHERE action_type = 'LOGIN_FAILED' AND ip_address = ? AND created_at > ?"
        );
        $chk->execute([$client_ip, $lock_since]);
        $recent_fails = (int)$chk->fetchColumn();

        if ($recent_fails >= LOGIN_LOCK_THRESHOLD) {
            // 0. ถูกล็อกชั่วคราว — ไม่เช็ครหัสผ่าน และไม่บันทึกเป็น LOGIN_FAILED (กันตัวนับพองตัว)
            $error = t('login.err_rate_limited', ['min' => LOGIN_LOCK_WINDOW_MIN]);
            writeLog($pdo, 'LOGIN_BLOCKED', "Rate-limited login (IP {$client_ip} มี {$recent_fails} fails) username: {$username}");
        } else {
            // 1. ค้นหา User (เฉพาะบัญชีที่เปิดใช้งานอยู่)
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username AND is_active = 1 LIMIT 1");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            // 2. ตรวจสอบรหัสผ่าน
            if ($user && password_verify($password, $user['password_hash'])) {

                // "จำการเข้าสู่ระบบ" — ถ้าติ๊กไว้ ให้ cookie อยู่ข้ามการปิดเบราว์เซอร์ (30 วัน)
                // ต้องตั้ง cookie params ก่อน session_regenerate_id เพราะการ regenerate
                // จะส่ง Set-Cookie ใหม่ตาม params ปัจจุบัน (lifetime ที่ตั้งไว้ตรงนี้)
                if (!empty($_POST['remember'])) {
                    $cp = session_get_cookie_params();
                    session_set_cookie_params([
                        'lifetime' => SESSION_REMEMBER_LIFETIME,
                        'path'     => $cp['path'],
                        'domain'   => $cp['domain'],
                        'httponly' => true,
                        'samesite' => 'Lax',
                        'secure'   => $cp['secure'],
                    ]);
                }

                // 🛡️ Security: ป้องกัน Session Fixation โดยการสร้าง ID ใหม่หลังล็อกอินสำเร็จ
                session_regenerate_id(true);

                // 3. เก็บข้อมูลลง Session
                $_SESSION['user_id']     = $user['id'];
                $_SESSION['role_level']  = $user['role_level'];
                $_SESSION['nickname']    = $user['nickname'];
                $_SESSION['avatar_name'] = $user['avatar_name'];

                // บันทึก Log การเข้าใช้งาน
                writeLog($pdo, 'LOGIN', "เข้าสู่ระบบสำเร็จ (User ID: {$user['id']})");

                // Redirect ไปที่ Dashboard
                redirect('index.php?page=dashboard');

            } else {
                // กรณีข้อมูลผิดพลาด
                $error = t('login.err_invalid');

                // 🛡️ Log ความพยายามล็อกอินที่ล้มเหลว (Security Audit)
                writeLog($pdo, 'LOGIN_FAILED', "Login failure for username: $username");
            }
        }
    } catch (PDOException $e) {
        error_log("Login Error: " . $e->getMessage());
        $error = t('login.err_connection');
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e('login.page_title'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">

    <style>
        /* ฟอนต์ Sarabun self-host — ไม่พึ่ง Google Fonts */
        @font-face { font-family:'Sarabun'; src:url('./assets/fonts/Sarabun-Light.ttf') format('truetype');    font-weight:300; font-style:normal; font-display:swap; }
        @font-face { font-family:'Sarabun'; src:url('./assets/fonts/Sarabun-Regular.ttf') format('truetype');  font-weight:400; font-style:normal; font-display:swap; }
        @font-face { font-family:'Sarabun'; src:url('./assets/fonts/Sarabun-SemiBold.ttf') format('truetype'); font-weight:600; font-style:normal; font-display:swap; }
        @font-face { font-family:'Sarabun'; src:url('./assets/fonts/Sarabun-Bold.ttf') format('truetype');     font-weight:700; font-style:normal; font-display:swap; }
        :root { --primary-color: #0d6efd; }
        body { 
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Sarabun', sans-serif; 
            height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center;
        }
        .login-card { 
            width: 100%; max-width: 420px; padding: 2.5rem; background: #fff; 
            border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        }
        .login-icon { font-size: 3.5rem; color: var(--primary-color); }
        .form-control:focus { box-shadow: none; border-color: var(--primary-color); }
        .btn-login { border-radius: 10px; transition: 0.3s; }
        .btn-login:hover { transform: translateY(-2px); }
    </style>
</head>
<body>



<div class="login-card animate__animated animate__fadeIn">
    <div class="text-center mb-4">
        <div class="login-icon mb-2"><i class="bi bi-shield-lock-fill"></i></div>
        <h3 class="fw-bold text-dark mb-1"><?php echo e('app.fallback_name'); ?></h3>
        <p class="text-muted small"><?php echo e('login.subtitle'); ?></p>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm text-center py-2 mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label small fw-bold"><?php echo e('login.username_label'); ?></label>
            <div class="input-group border rounded-3 overflow-hidden">
                <span class="input-group-text bg-white border-0"><i class="bi bi-person text-muted"></i></span>
                <input type="text" name="username" class="form-control border-0 bg-white" required autofocus placeholder="Username" value="<?php echo htmlspecialchars($username ?? ''); ?>">
            </div>
        </div>
        
        <div class="mb-4">
            <label class="form-label small fw-bold"><?php echo e('login.password_label'); ?></label>
            <div class="input-group border rounded-3 overflow-hidden">
                <span class="input-group-text bg-white border-0"><i class="bi bi-key text-muted"></i></span>
                <input type="password" id="password" name="user_pass_new" autocomplete="new-password" class="form-control border-0 bg-white" required placeholder="<?php echo e('login.password_placeholder'); ?>">

                <button class="btn btn-white border-0 text-muted" type="button" onclick="togglePassword()">
                    <i class="bi bi-eye" id="toggleIcon"></i>
                </button>
            </div>
        </div>

        <div class="form-check form-switch mb-4">
            <input class="form-check-input" type="checkbox" role="switch" id="remember" name="remember" value="1">
            <label class="form-check-label small text-muted" for="remember"><?php echo e('login.remember_me'); ?></label>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-3 fw-bold shadow btn-login">
            <i class="bi bi-box-arrow-in-right me-2"></i> <?php echo e('login.submit'); ?>
        </button>
    </form>
    
    <div class="text-center mt-5">
        <hr class="opacity-25">
        <p class="text-muted mb-0" style="font-size: 0.75rem;">
<?php echo e('login.copyright', ['year' => date('Y')]); ?>
        </p>
    </div>
</div>

<script>
    function togglePassword() {
        const passInput = document.getElementById('password');
        const icon = document.getElementById('toggleIcon');
        if (passInput.type === 'password') {
            passInput.type = 'text';
            icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
            passInput.type = 'password';
            icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
    }
</script>

</body>
</html>