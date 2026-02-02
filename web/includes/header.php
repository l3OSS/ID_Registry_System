<?php
/**
 * Global Header Component
 * Contains Metadata, CSS, JS Libraries, and Navbar
 */

// --- 1. Header Logic ---
// Fetch role level safely (0 = Guest/Not set)
$current_role = $_SESSION['role_level'] ?? 0;
$nickname     = $_SESSION['nickname']   ?? $_SESSION['username'] ?? 'ผู้ใช้งาน';
$avatar       = $_SESSION['avatar_name'] ?? 'default.png';

// Define Application Name (สามารถย้ายไปไว้ใน config ได้)
$app_title = "ระบบเก็บข้อมูลการเข้าพัก (Reg System)";
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $app_title; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
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
        :root { --main-font: 'Sarabun', sans-serif; }
        body { font-family: var(--main-font); background-color: #f8f9fa; }
        .navbar-brand { font-weight: bold; }
        .tt-menu { z-index: 9999 !important; }
        .avatar-img { width: 30px; height: 30px; object-fit: cover; border: 1.5px solid #fff; }
        .main-content { min-height: 80vh; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4 shadow-sm">
    <div class="container">
        <a class="navbar-brand" href="index.php?page=dashboard">
            <i class="bi bi-shield-lock-fill"></i> Reg System ศูนย์จัดเก็บข้อมูลทะเบียน
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item">
                    <a class="nav-link" href="index.php?page=dashboard" title="หน้าหลัก">
                        <i class="bi bi-house-fill"></i>
                    </a>
                </li>
                
                <?php if (in_array($current_role, [1, 2, 3])): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php?page=guest_list">ทะเบียนผู้พัก</a>
                    </li>
                <?php endif; ?>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown"> 
                        <img src="assets/Avatar/<?php echo htmlspecialchars($avatar); ?>" class="rounded-circle me-2 avatar-img">          
                        <span><?php echo htmlspecialchars($nickname); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item" href="index.php?page=profile"><i class="bi bi-person-circle"></i> แก้ไขโปรไฟล์</a></li>
                        
                        <?php if (in_array($current_role, [1, 2])): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header">การจัดการระบบ</h6></li>
                            <li><a class="dropdown-item" href="index.php?page=user_manage"><i class="bi bi-people"></i> จัดการผู้ใช้</a></li>
                            <li><a class="dropdown-item" href="index.php?page=setting"><i class="bi bi-gear"></i> ตั้งค่าระบบ</a></li>
                            <li><a class="dropdown-item" href="index.php?page=log_viewer"><i class="bi bi-journal-text"></i> ดูประวัติระบบ (Log)</a></li>
                        <?php endif; ?>

                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="core/logout.php">
                                <i class="bi bi-box-arrow-right"></i> ออกจากระบบ
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container main-content">