<?php
/**
 * 404 Not Found Page
 * Displayed when the requested page does not exist.
 */
?>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <div class="error-template">
                <h1 class="display-1 fw-bold text-secondary">404</h1>
                <h2 class="mb-4">ขออภัย! ไม่พบหน้าที่คุณต้องการ</h2>
                
                <div class="error-details mb-4 text-muted">
                    หน้าที่คุณพยายามเข้าถึงอาจถูกลบไปแล้ว, เปลี่ยนชื่อ หรือไม่มีอยู่จริงในระบบ
                </div>

                <div class="error-actions">
                    <a href="index.php?page=dashboard" class="btn btn-primary btn-lg shadow-sm">
                        <i class="bi bi-house-door-fill"></i> กลับสู่หน้าหลัก
                    </a>
                    <button onclick="history.back()" class="btn btn-outline-secondary btn-lg shadow-sm ms-2">
                        <i class="bi bi-arrow-left"></i> ย้อนกลับ
                    </button>
                </div>

                <?php if (isset($_GET['page'])): ?>
                    <div class="mt-5 small text-muted border-top pt-3">
                        Path: <code>pages/<?php echo htmlspecialchars($_GET['page']); ?>.php</code>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    /* ปรับแต่งความสวยงามเพิ่มเติม */
    .error-template { padding: 40px 15px; }
    .display-1 { 
        font-size: 8rem; 
        opacity: 0.3;
        margin-bottom: -10px;
    }
</style>