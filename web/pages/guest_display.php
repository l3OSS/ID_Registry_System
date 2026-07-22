<?php require_once __DIR__ . '/../core/lang.php'; // ข้อความทั้งระบบ — จอ QR เปิดตรง ไม่ผ่าน index.php ?>
<div id="consent-area" class="container mt-5">
    <div class="text-center animate__animated animate__fadeIn">
        <div class="spinner-border text-primary" role="status"></div>
        <h4 class="mt-3 text-muted"><?php echo e('disp.waiting'); ?></h4>
    </div>
</div>

<?php
// คำนวณหา Base URL ในฝั่ง PHP
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$currentDir = dirname($_SERVER['PHP_SELF']); // จะได้ /Reg/pages หรือ /Reg
$projectRoot = str_replace('/pages', '', $currentDir); // ตัดโฟลเดอร์ย่อยออกเพื่อให้ได้ Root
$fullBaseUrl = $protocol . "://" . $host . rtrim($projectRoot, '/') . '/';

// กุญแจประจำจอ (มาจาก QR: guest_display.php?d=xxx) — ใช้บูตสแตรป poll เมื่ออุปกรณ์ผู้พักไม่ได้ล็อกอิน
// ไม่มี d และไม่ได้ล็อกอิน = sync_check คืน "none" ตลอด (จอค้างที่สปินเนอร์)
$displayKey = preg_replace('/[^a-f0-9]/', '', (string)($_GET['d'] ?? ''));
?>


<script>
/**
 * Tablet Sync Logic - Hybrid Version (Stable for Mobile & PC)
 */

// --- [1. Configuration & Global State] ---
let currentStatus = 'none';
let syncToken = ''; 
let syncService = null;
const POLL_INTERVAL = 1500;

// ตรวจสอบว่าเป็นอุปกรณ์พกพาหรือไม่
const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

const BASE_URL   = "<?php echo $fullBaseUrl; ?>";
const DISPLAY_KEY = "<?php echo $displayKey; ?>";
// ข้อความทั้งหมดในสคริปต์นี้ส่งมาจากไฟล์ภาษา (ห้ามฝังคำไทยใน JS)
const L = <?php echo json_encode([
    'saved_title'   => t('disp.saved_title'),
    'saved_thanks'  => t('disp.saved_thanks'),
    'standby_title' => t('disp.standby_title'),
    'standby_sub'   => t('disp.standby_sub'),
    'id_card_label' => t('disp.id_card_label'),
    'birth_label'   => t('disp.birth_label'),
    'address_label' => t('disp.address_label'),
    'pdpa_title'    => t('disp.pdpa_title'),
    'pdpa_text'     => t('disp.pdpa_text'),
    'confirm_btn'   => t('disp.confirm_btn'),
    'err_confirm'   => t('disp.err_confirm'),
], JSON_UNESCAPED_UNICODE); ?>;

// --- [2. UI Renderers & Helpers] ---

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showSuccessView() {
    document.getElementById('consent-area').innerHTML = `
        <div class="text-center mt-5 animate__animated animate__bounceIn">
            <i class="bi bi-check-circle-fill text-success" style="font-size: 5rem;"></i>
            <h2 class="mt-3 fw-bold">${L.saved_title}</h2>
            <p class="text-muted">${L.saved_thanks}</p>
        </div>`;
}

function resetStandbyView() {
    document.getElementById('consent-area').innerHTML = `
        <div class="text-center mt-5 animate__animated animate__fadeIn">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status"></div>
            <h4 class="mt-4 text-secondary">${L.standby_title}</h4>
            <p class="text-muted">${L.standby_sub}</p>
        </div>`;
}

function renderConsentForm(citizenJson, adminId) {
    const info = (typeof citizenJson === 'string') ? JSON.parse(citizenJson) : citizenJson;
    const consentArea = document.getElementById('consent-area');
    
    // จัดการส่วนรูปภาพ (ข้ามถ้าเป็นมือถือตามแผน)
    let imageHtml = '';
    if (!isMobile) {
        // S4: ดึงรูปผ่าน endpoint ที่ยืนยัน token (ไม่ใช้ path ชื่อเดาได้ตาม adminId อีกต่อไป)
        const imgSrc = `${BASE_URL}api/sync_image.php?t=${encodeURIComponent(syncToken)}&_=${new Date().getTime()}`;
        const noImg  = `${BASE_URL}assets/noimg.jpg`;
        imageHtml = `
            <div class="position-relative d-inline-block mb-3">
                <img src="${imgSrc}"
                     onerror="this.onerror=null; this.src='${noImg}';"
                     class="img-thumbnail shadow-sm"
                     style="width:180px; height:220px; object-fit:cover; border-radius:15px; border: 3px solid #0d6efd;">
            </div>`;
    }

    consentArea.innerHTML = `
        <div class="card shadow-lg border-0 rounded-4 overflow-hidden animate__animated animate__zoomIn">
            <div class="card-body p-4 text-center">
                ${imageHtml}
               
                <h2 class="text-primary fw-bold mb-1">${escapeHtml(info.full_name)}</h2>
                <p class="text-muted mb-3 fs-5">${L.id_card_label} ${escapeHtml(info.id_card)}</p>
               
                <div class="row text-start g-3 mb-4">
                    <div class="col-12 border-bottom pb-2">
                        <small class="text-muted d-block small-label">${L.birth_label}</small>
                        <span class="fw-bold fs-5">${escapeHtml(info.birth)}</span>
                    </div>
                    <div class="col-12 border-bottom pb-2">
                        <small class="text-muted d-block small-label">${L.address_label}</small>
                        <span class="fw-bold fs-5">${escapeHtml(info.address)}</span>
                    </div>
                </div>

                <div class="alert alert-info border-0 shadow-sm p-3 text-start mb-4" style="background-color: #e7f3ff;">
                    <h6 class="fw-bold text-primary"><i class="bi bi-shield-lock-fill"></i> ${L.pdpa_title}</h6>
                    <p class="small mb-0 text-dark">
                        ${L.pdpa_text}
                    </p>
                </div>

                <button onclick="confirmFromTablet()" class="btn btn-success btn-lg w-100 py-3 shadow rounded-pill fs-2 fw-bold animate__animated animate__pulse animate__infinite">
                    <i class="bi bi-check-circle-fill"></i> ${L.confirm_btn}
                </button>
            </div>
        </div>`;
}

// --- [3. Action Handlers] ---

async function confirmFromTablet() {
    try {
        const res = await fetch(BASE_URL + 'api/sync_confirm.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ t: syncToken })
        });
        if (res.ok) {
            currentStatus = 'confirmed';
            clearInterval(syncService); 
            showSuccessView();
        }
    } catch (e) {
        alert(L.err_confirm);
    }
}

// --- [4. Start Polling Service] ---

document.addEventListener("DOMContentLoaded", function() {
    syncService = setInterval(async () => {
        try {
            // ส่งทั้ง sync_token (ถ้ามีแล้ว) และ display key (บูตสแตรปครั้งแรก/หลัง reset)
            const q = new URLSearchParams({ t: syncToken, d: DISPLAY_KEY });
            const res = await fetch(BASE_URL + 'api/sync_check.php?' + q.toString());
            if (!res.ok) throw new Error('Network response error');
            
            const text = await res.text();
            let data;
            try {
                const jsonStart = text.indexOf('{');
                const jsonEnd = text.lastIndexOf('}') + 1;
                if (jsonStart === -1) return;
                data = JSON.parse(text.substring(jsonStart, jsonEnd));
            } catch (parseError) { return; }

            if (data.status === 'pending' && data.citizen_data) {
                if (currentStatus !== 'pending' || syncToken !== data.sync_token) {
                    currentStatus = 'pending';
                    syncToken = data.sync_token || ''; 
                    renderConsentForm(data.citizen_data, data.admin_id);
                }
            } 
            else if (data.status === 'confirmed') {
                if (currentStatus !== 'confirmed') {
                    currentStatus = 'confirmed';
                    clearInterval(syncService); 
                    showSuccessView();
                }
            }
            else if (data.status === 'none') {
                if (currentStatus !== 'none') {
                    currentStatus = 'none';
                    syncToken = ''; 
                    resetStandbyView();
                }
            }
        } catch (e) {
            console.error("Sync Error:", e);
        }
    }, POLL_INTERVAL);
});
</script>

<style>
    body { background-color: #f0f2f5; }
    .small-label { font-size: 0.85rem; letter-spacing: 0.5px; }
    .card { max-width: 600px; margin: 0 auto; }
    .animate__infinite { animation-iteration-count: infinite; }
</style>