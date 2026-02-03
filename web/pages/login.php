<?php
/**
 * Page: Login
 * Handles user authentication with secure password hashing.
 */

// ป้องกันการเข้าถึงไฟล์โดยตรง (หากคุณมีระบบ Router ใน index.php)
if (!defined('PDO_CONNECTION')) {
    // กรณีเรียกใช้ผ่านหน้าเดี่ยวๆ ต้องเรียกไฟล์ config ด้วย
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../core/log.php';
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['user_pass_new'] ?? '';

    try {
        // 1. ค้นหา User (เฉพาะบัญชีที่เปิดใช้งานอยู่)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username AND is_active = 1 LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        // 2. ตรวจสอบรหัสผ่าน
        if ($user && password_verify($password, $user['password_hash'])) {
            
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
            header("Location: index.php?page=dashboard");
            exit();
            
        } else {
            // กรณีข้อมูลผิดพลาด
            $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
            
            // 🛡️ Log ความพยายามล็อกอินที่ล้มเหลว (Security Audit)
            writeLog($pdo, 'LOGIN_FAILED', "Login failure for username: $username");
        }
    } catch (PDOException $e) {
        error_log("Login Error: " . $e->getMessage());
        $error = "เกิดข้อผิดพลาดในการเชื่อมต่อระบบ";
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - Registration System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
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
        <h3 class="fw-bold text-dark mb-1">Reg System</h3>
        <p class="text-muted small">ระบบบริหารจัดการข้อมูลทะเบียน</p>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm text-center py-2 mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="mb-3">
            <label class="form-label small fw-bold">ชื่อผู้ใช้ (Username)</label>
            <div class="input-group border rounded-3 overflow-hidden">
                <span class="input-group-text bg-white border-0"><i class="bi bi-person text-muted"></i></span>
                <input type="text" name="username" class="form-control border-0 bg-white" required autofocus placeholder="Username" value="<?php echo htmlspecialchars($username ?? ''); ?>">
            </div>
        </div>
        
        <div class="mb-4">
            <label class="form-label small fw-bold">รหัสผ่าน (Password)</label>
            <div class="input-group border rounded-3 overflow-hidden">
                <span class="input-group-text bg-white border-0"><i class="bi bi-key text-muted"></i></span>
                <input type="password" id="password" name="user_pass_new" autocomplete="new-password" class="form-control border-0 bg-white" required placeholder="กรอกรหัสผ่าน">

                <button class="btn btn-white border-0 text-muted" type="button" onclick="togglePassword()">
                    <i class="bi bi-eye" id="toggleIcon"></i>
                </button>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary w-100 py-3 fw-bold shadow btn-login">
            <i class="bi bi-box-arrow-in-right me-2"></i> เข้าสู่ระบบ
        </button>
    </form>
    
    <div class="text-center mt-5">
        <hr class="opacity-25">
        <p class="text-muted mb-0" style="font-size: 0.75rem;">
            &copy; <?php echo date('Y'); ?> Offline Registration System.
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