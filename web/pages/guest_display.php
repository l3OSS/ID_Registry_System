<?php
/**
 * Hybrid Guest Display Page
 * สำหรับปริ๊น QR ติดเคาน์เตอร์: ลูกค้าสแกนมาที่ URL กลาง
 * ระบบจะไปจับคู่กับข้อมูลล่าสุดที่เจ้าหน้าที่ส่งออกมาให้
 */
require_once 'config/db.php';

// 1. ตรวจสอบเบื้องต้น: หาว่ามี Admin คนไหนเพิ่งส่งข้อมูลมาใน 5 นาทีล่าสุดบ้าง
$stmt = $pdo->prepare("
    SELECT admin_id 
    FROM temp_sync_consent 
    WHERE status = 'pending' 
    AND updated_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) 
    ORDER BY updated_at DESC 
    LIMIT 1
");
$stmt->execute();
$found_admin = $stmt->fetchColumn();

// ถ้าเจอ admin ที่กำลังส่งข้อมูล หรือ เจ้าหน้าที่ล็อกอินอยู่ ให้ถือว่าเข้าหน้าจอได้
if (session_status() === PHP_SESSION_NONE) session_start();
$isValidMode = ($found_admin > 0) || (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0);
?>

<div id="consent-area" class="container mt-4 mt-md-5">
    <?php if (!$isValidMode): ?>
        <div class="text-center mt-5 animate__animated animate__fadeIn">
            <i class="bi bi-qr-code-scan text-primary" style="font-size: 4rem;"></i>
            <h4 class="mt-3 text-dark fw-bold">ยินดีต้อนรับ</h4>
            <p class="text-muted">กรุณาแจ้งเจ้าหน้าที่เพื่อเริ่มการลงทะเบียน<br>ข้อมูลจะปรากฏบนหน้าจอนี้อัตโนมัติ</p>
        </div>
    <?php else: ?>
        <div class="text-center animate__animated animate__fadeIn">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status"></div>
            <h4 class="mt-4 text-secondary fw-light">กำลังรอข้อมูลการลงทะเบียน...</h4>
        </div>
    <?php endif; ?>
</div>

<script>
/**
 * Sync Logic: Hybrid Mode for Static QR
 */
let currentStatus = 'none';
let syncToken = ''; // เก็บ Token ไว้ใช้กดยืนยันให้ถูกคน
const POLL_INTERVAL = 1500;

const syncService = setInterval(async () => {
    try {
        // ยิงไปที่ API กลาง (ไม่ต้องส่ง Token ไปแต่แรก เพราะเราใช้ QR แผ่นเดียว)
        const res = await fetch('api/sync_check.php');
        if (!res.ok) throw new Error('Network response error');
        
        const text = await res.text();
        let data;
        try {
            const jsonStart = text.indexOf('{');
            const jsonEnd = text.lastIndexOf('}') + 1;
            if (jsonStart === -1) return;
            data = JSON.parse(text.substring(jsonStart, jsonEnd));
        } catch (parseError) { return; }

        // --- Logic การจัดการสถานะ ---
        if (data.status === 'pending' && data.citizen_data) {
            if (currentStatus !== 'pending') {
                currentStatus = 'pending';
                // สำคัญ: เก็บ Token ที่ API ส่งมาให้ ไว้ใช้ตอนกด Confirm Action
                syncToken = data.sync_token || ''; 
                renderConsentForm(data.citizen_data, data.admin_id);
            }
        } 
        else if (data.status === 'confirmed') {
            if (currentStatus !== 'confirmed') {
                currentStatus = 'confirmed';
                showSuccessView();
            }
        }
        else if (data.status === 'none') {
            if (currentStatus !== 'none') {
                currentStatus = 'none';
                resetStandbyView();
            }
        }
    } catch (e) {
        console.error("Sync Error:", e);
    }
}, POLL_INTERVAL);

// --- UI Renderers ---
function renderConsentForm(info, adminId) {
    const consentArea = document.getElementById('consent-area');
    // Cache busting สำหรับรูปภาพ
    const imgSrc = `uploads/temp/view_${adminId}.jpg?v=${new Date().getTime()}`;
    const noImg  = 'assets/noimg.jpg';

    consentArea.innerHTML = `
        <div class="card shadow-lg border-0 rounded-4 overflow-hidden animate__animated animate__zoomIn">
            <div class="card-body p-4 text-center">
                <div class="position-relative d-inline-block mb-3">
                    <img src="${imgSrc}" 
                         onerror="this.onerror=null; this.src='${noImg}';" 
                         class="img-thumbnail shadow-sm" 
                         style="width:160px; height:200px; object-fit:cover; border-radius:15px; border: 3px solid #0d6efd;">
                </div>
                
                <h2 class="text-primary fw-bold mb-1">${escapeHtml(info.full_name)}</h2>
                <p class="text-muted mb-3 fs-5">ID: ${escapeHtml(info.id_card)}</p>
                
                <div class="text-start bg-light p-3 rounded-3 mb-4">
                    <div class="mb-2">
                        <small class="text-muted d-block small-label">วันเดือนปีเกิด</small>
                        <span class="fw-bold fs-6">${escapeHtml(info.birth)}</span>
                    </div>
                    <div>
                        <small class="text-muted d-block small-label">ที่อยู่ตามบัตร</small>
                        <span class="fw-bold fs-6">${escapeHtml(info.address)}</span>
                    </div>
                </div>

                <div class="alert alert-info border-0 p-3 text-start mb-4" style="font-size:0.9rem; background-color: #e7f3ff;">
                    <h6 class="fw-bold text-primary"><i class="bi bi-shield-lock-fill"></i> นโยบายความเป็นส่วนตัว (PDPA)</h6>
                    <p class="small mb-0">ข้าพเจ้ายินยอมให้จัดเก็บข้อมูลเพื่อใช้ในการลงทะเบียนเข้าพักตามระเบียบความปลอดภัยเท่านั้น</p>
                </div>

                <button onclick="confirmAction()" class="btn btn-success btn-lg w-100 py-3 shadow rounded-pill fw-bold animate__animated animate__pulse animate__infinite">
                    <i class="bi bi-check-circle-fill"></i> ยืนยันข้อมูลถูกต้อง
                </button>
            </div>
        </div>
    `;
}

function showSuccessView() {
    document.getElementById('consent-area').innerHTML = `
        <div class="text-center mt-5 animate__animated animate__bounceIn">
            <i class="bi bi-check-circle-fill text-success" style="font-size: 5rem;"></i>
            <h2 class="mt-3 fw-bold">ยืนยันเรียบร้อย</h2>
            <p class="text-muted">ขอบคุณค่ะ ข้อมูลของท่านถูกส่งให้เจ้าหน้าที่แล้ว</p>
        </div>`;
}

function resetStandbyView() {
    document.getElementById('consent-area').innerHTML = `
        <div class="text-center mt-5 animate__animated animate__fadeIn">
            <div class="spinner-border text-primary" role="status"></div>
            <h4 class="mt-4 text-secondary fw-bold">กรุณารอข้อมูลจากเจ้าหน้าที่</h4>
        </div>`;
}

// --- Action Handlers ---
async function confirmAction() {
    try {
        // ส่ง Token (t) แนบไปใน JSON เพื่อให้ api/sync_confirm.php รู้ว่ายืนยันคนไหน
        const res = await fetch('api/sync_confirm.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ t: syncToken })
        });
        
        if (res.ok) {
            currentStatus = 'confirmed';
            showSuccessView();
        }
    } catch (e) {
        alert("การยืนยันล้มเหลว กรุณาลองใหม่");
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<style>
    body { background-color: #f0f2f5; }
    .small-label { font-size: 0.75rem; text-transform: uppercase; color: #6c757d; letter-spacing: 0.5px; }
    .card { max-width: 500px; margin: 0 auto; }
    /* ปรับแต่งให้เต็มจอในมือถือ */
    @media (max-width: 576px) {
        .card { border-radius: 0 !important; margin-top: -20px; border: none; }
        .container { padding-left: 0; padding-right: 0; }
    }
</style>