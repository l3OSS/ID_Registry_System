<?php
/**
 * Global Header Component
 * Contains Metadata, CSS, JS Libraries, and Navbar
 */

// --- 1. Header Logic ---
$nickname     = $_SESSION['nickname']   ?? $_SESSION['username'] ?? t('user.anonymous');
$avatar       = $_SESSION['avatar_name'] ?? 'default.png';

// ค่าหัวเว็บ (ชื่อ/สร้อย/โลโก้) — ตั้งค่าได้ที่หน้า "ตั้งค่าระบบ" · $pdo มาจาก index.php
$settings      = function_exists('appSettings') ? appSettings($pdo) : ['app_name' => t('app.fallback_name'), 'site_subtitle' => '', 'logo_path' => ''];
$app_title     = ($settings['app_name'] ?? '') !== '' ? $settings['app_name'] : t('app.fallback_name');
$site_subtitle = $settings['site_subtitle'] ?? '';
$logo_path     = $settings['logo_path'] ?? '';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <!-- ตั้งโหมดมืด/สว่างจาก localStorage ก่อนเรนเดอร์ — กันหน้ากระพริบสีตอนโหลด -->
    <script>(function(){try{var t=localStorage.getItem('reg-theme');if(t)document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $app_title; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

    <link rel="stylesheet" href="./assets/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="./assets/jquery.Thailand.js/dist/jquery.Thailand.min.css">

    <script src="./assets/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="./assets/flatpickr/flatpickr.min.js"></script>
    <script src="./assets/flatpickr/dist/l10n/th.js"></script>
    
    <script src="./assets/jquery.Thailand.js/dependencies/JQL.min.js"></script>
    <script src="./assets/jquery.Thailand.js/dependencies/typeahead.bundle.js"></script>
    <script src="./assets/jquery.Thailand.js/dist/jquery.Thailand.min.js"></script>

    <style>
        /* ฟอนต์ Sarabun self-host (assets/fonts/) — ไม่พึ่ง Google Fonts CDN · ครอบคลุมน้ำหนักที่ใช้จริง */
        @font-face { font-family:'Sarabun'; src:url('./assets/fonts/Sarabun-Light.ttf') format('truetype');    font-weight:300; font-style:normal; font-display:swap; }
        @font-face { font-family:'Sarabun'; src:url('./assets/fonts/Sarabun-Regular.ttf') format('truetype');  font-weight:400; font-style:normal; font-display:swap; }
        @font-face { font-family:'Sarabun'; src:url('./assets/fonts/Sarabun-Medium.ttf') format('truetype');   font-weight:500; font-style:normal; font-display:swap; }
        @font-face { font-family:'Sarabun'; src:url('./assets/fonts/Sarabun-SemiBold.ttf') format('truetype'); font-weight:600; font-style:normal; font-display:swap; }
        @font-face { font-family:'Sarabun'; src:url('./assets/fonts/Sarabun-Bold.ttf') format('truetype');     font-weight:700; font-style:normal; font-display:swap; }
        @font-face { font-family:'Sarabun'; src:url('./assets/fonts/Sarabun-Italic.ttf') format('truetype');   font-weight:400; font-style:italic; font-display:swap; }
        @font-face { font-family:'Sarabun'; src:url('./assets/fonts/Sarabun-BoldItalic.ttf') format('truetype'); font-weight:700; font-style:italic; font-display:swap; }

        :root { --main-font: 'Sarabun', sans-serif; }
        body { font-family: var(--main-font); background-color: #f8f9fa; }
        [data-bs-theme="dark"] body { background-color: var(--bs-body-bg); } /* โหมดมืดใช้พื้นของ Bootstrap */
        #themeToggle { border: 0; color: rgba(255,255,255,.85); }
        #themeToggle:hover { color: #fff; }
        .navbar-brand { font-weight: bold; }
        .tt-menu { z-index: 9999 !important; }
        .avatar-img { width: 30px; height: 30px; object-fit: cover; border: 1.5px solid #fff; }
        .main-content { min-height: 80vh; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4 shadow-sm">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php?page=dashboard">
            <?php if ($logo_path && file_exists($logo_path)): ?>
                <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="<?php echo e('header.logo_alt'); ?>" style="height:34px; width:auto; object-fit:contain;">
            <?php else: ?>
                <i class="bi bi-shield-lock-fill"></i>
            <?php endif; ?>
            <span>
                <?php echo htmlspecialchars($app_title); ?>
                <?php if ($site_subtitle !== ''): ?><small class="d-block fw-normal" style="font-size:.7rem; opacity:.8; line-height:1;"><?php echo htmlspecialchars($site_subtitle); ?></small><?php endif; ?>
            </span>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item">
                    <a class="nav-link" href="index.php?page=dashboard" title="<?php echo e('nav.dashboard'); ?>">
                        <i class="bi bi-house-fill"></i>
                    </a>
                </li>
                
                <?php if (userCan('guests.view')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php?page=guest_list"><?php echo e('nav.guests'); ?></a>
                    </li>
                <?php endif; ?>

                <li class="nav-item">
                    <button type="button" id="themeToggle" class="btn btn-link nav-link" title="<?php echo e('theme.toggle'); ?>" aria-label="<?php echo e('theme.toggle'); ?>">
                        <i class="bi bi-moon-stars-fill"></i>
                    </button>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                        <img src="assets/Avatar/<?php echo htmlspecialchars($avatar); ?>" class="rounded-circle me-2 avatar-img">          
                        <span><?php echo htmlspecialchars($nickname); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item" href="index.php?page=profile"><i class="bi bi-person-circle"></i> <?php echo e('nav.profile'); ?></a></li>

                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header"><?php echo e('nav.admin_section'); ?></h6></li>
                        <?php if (userCan('users.view')): ?>
                            <li><a class="dropdown-item" href="index.php?page=user_manage"><i class="bi bi-people"></i> <?php echo e('nav.team'); ?></a></li>
                        <?php endif; ?>
                        <?php if (userCan('settings.manage')): ?>
                            <li><a class="dropdown-item" href="index.php?page=setting"><i class="bi bi-gear"></i> <?php echo e('nav.settings'); ?></a></li>
                        <?php endif; ?>
                            <li><a class="dropdown-item" href="index.php?page=log_viewer"><i class="bi bi-journal-text"></i> <?php echo e('nav.logs'); ?></a></li>

                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="core/logout.php">
                                <i class="bi bi-box-arrow-right"></i> <?php echo e('nav.logout'); ?>
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<script>
// ปุ่มสลับโหมดมืด/สว่าง — เก็บสถานะใน localStorage (ต่อเบราว์เซอร์) · ค่าเริ่มต้น = สว่าง
(function () {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    function syncIcon() {
        var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        btn.innerHTML = dark ? '<i class="bi bi-sun-fill"></i>' : '<i class="bi bi-moon-stars-fill"></i>';
    }
    syncIcon();
    btn.addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        try { localStorage.setItem('reg-theme', next); } catch (e) {}
        syncIcon();
    });
})();
</script>

<div class="container main-content">