<div id="consent-area" class="container mt-5">
    <div class="text-center animate__animated animate__fadeIn">
        <div class="spinner-border text-primary" role="status"></div>
        <h4 class="mt-3 text-muted">รอรับข้อมูลการลงทะเบียนจากเจ้าหน้าที่...</h4>
    </div>
</div>

<script>
/**
 * Tablet Sync Logic for Guest Registration
 * Uses long-polling to check for new data from Admin.
 */

// 1. Configuration & Global State
let currentStatus = 'none';
const POLL_INTERVAL = 1500; // Check every 1.5 seconds

// 2. Main Sync Process
const syncService = setInterval(async () => {
    try {
        const res = await fetch('api/sync_check.php');
        if (!res.ok) throw new Error('Network response was not ok');
       
        // --- ส่วนที่แก้ไข: กรองขยะ HTML ออกก่อน Parse JSON ---
        const text = await res.text(); // อ่านเป็นข้อความดิบก่อน
        let data;
        try {
            // ค้นหาตำแหน่งเริ่มต้น { และสิ้นสุด } ของ JSON เพื่อตัดขยะ <br> หรือช่องว่างทิ้ง
            const jsonStart = text.indexOf('{');
            const jsonEnd = text.lastIndexOf('}') + 1;
            if (jsonStart === -1) return; // ถ้าไม่พบรูปแบบ JSON ให้ข้ามรอบนี้ไป
           
            const cleanJson = text.substring(jsonStart, jsonEnd);
            data = JSON.parse(cleanJson);
        } catch (parseError) {
            console.warn("JSON ขัดข้อง (พบขยะ):", text);
            return; // ข้ามไปรอบถัดไป
        }
        // --- จบส่วนที่แก้ไข ---

        // Handle Logic based on status from DB
        if (data.status === 'pending' && data.citizen_data) {
            if (currentStatus !== 'pending') {
                currentStatus = 'pending';
                renderConsentForm(data.citizen_data, data.admin_id);
            }
        }
        else if (data.status === 'confirmed') {
             // เพิ่มกรณีสถานะยืนยันแล้ว เพื่อให้หน้าจอเปลี่ยนสถานะ
             if (currentStatus !== 'confirmed') {
                currentStatus = 'confirmed';
                // แสดงหน้าจอขอบคุณ
             }
        }
        else {
            if (currentStatus !== 'none') {
                currentStatus = 'none';
                resetStandbyView();
            }
        }
    } catch (e) {
        console.error("Sync Error:", e);
    }
}, POLL_INTERVAL);

// 3. UI Renderers
function renderConsentForm(citizenJson, adminId) {
    const info = (typeof citizenJson === 'string') ? JSON.parse(citizenJson) : citizenJson;
    const consentArea = document.getElementById('consent-area');
   
    // 🖼️ แก้ปัญหาเรื่องรูป:
    // ใช้รูปจากโฟลเดอร์ชั่วคราวที่ Admin ส่งมา (Cache busting ด้วย timestamp)
    const imgSrc = `uploads/temp/view_${adminId}.jpg?t=${new Date().getTime()}`;
    const noImg  = 'assets/noimg.jpg';

    consentArea.innerHTML = `
        <div class="card shadow-lg border-0 rounded-4 overflow-hidden animate__animated animate__zoomIn">
            <div class="card-body p-4 text-center">
                <div class="position-relative d-inline-block mb-3">
                    <img src="${imgSrc}"
                         onerror="this.onerror=null; this.src='${noImg}';"
                         class="img-thumbnail shadow-sm"
                         style="width:180px; height:220px; object-fit:cover; border-radius:15px; border: 3px solid #0d6efd;">
                </div>
               
                <h2 class="text-primary fw-bold mb-1">${escapeHtml(info.full_name)}</h2>
                <p class="text-muted mb-3 fs-5">เลขประจำตัวประชาชน: ${escapeHtml(info.id_card)}</p>
               
                <div class="row text-start g-3 mb-4">
                    <div class="col-12 border-bottom pb-2">
                        <small class="text-muted d-block small-label">วันเดือนปีเกิด</small>
                        <span class="fw-bold fs-5">${escapeHtml(info.birth)}</span>
                    </div>
                    <div class="col-12 border-bottom pb-2">
                        <small class="text-muted d-block small-label">ที่อยู่ตามบัตร</small>
                        <span class="fw-bold fs-5">${escapeHtml(info.address)}</span>
                    </div>
                </div>

                <div class="alert alert-info border-0 shadow-sm p-3 text-start mb-4" style="background-color: #e7f3ff;">
                    <h6 class="fw-bold text-primary"><i class="bi bi-shield-lock-fill"></i> นโยบายความเป็นส่วนตัว (PDPA)</h6>
                    <p class="small mb-0 text-dark">
                        ข้าพเจ้ายินยอมให้ศูนย์จัดเก็บและประมวลผลข้อมูลส่วนบุคคลข้างต้น เพื่อวัตถุประสงค์ในการลงทะเบียนเข้าพักและรักษาความปลอดภัยเท่านั้น
                    </p>
                </div>

                <button onclick="confirmFromTablet()" class="btn btn-success btn-lg w-100 py-3 shadow rounded-pill fs-2 fw-bold animate__animated animate__pulse animate__infinite">
                    <i class="bi bi-check-circle-fill"></i> ยืนยันข้อมูลถูกต้อง
                </button>
            </div>
        </div>
    `;
}

function resetStandbyView() {
    document.getElementById('consent-area').innerHTML = `
        <div class="text-center mt-5 animate__animated animate__fadeIn">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status"></div>
            <h4 class="mt-4 text-secondary">กรุณารอเจ้าหน้าที่ส่งข้อมูลลงทะเบียน</h4>
            <p class="text-muted">โปรดเตรียมบัตรประชาชนของท่านให้พร้อม</p>
        </div>`;
}

// 4. Action Handlers
async function confirmFromTablet() {
    try {
        const res = await fetch('api/sync_confirm.php');
        if (res.ok) {
            currentStatus = 'confirmed';
            document.getElementById('consent-area').innerHTML = `
                <div class="text-center mt-5 animate__animated animate__bounceIn">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 5rem;"></i>
                    <h2 class="mt-3 fw-bold">บันทึกข้อมูลเรียบร้อย</h2>
                    <p class="text-muted">ขอบคุณที่ให้ความร่วมมือครับ</p>
                </div>`;
        }
    } catch (e) {
        alert("เกิดข้อผิดพลาดในการยืนยันข้อมูล");
    }
}

// Helper: Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<style>
    body { background-color: #f0f2f5; }
    .small-label { font-size: 0.85rem; letter-spacing: 0.5px; }
    .card { max-width: 600px; margin: 0 auto; }
</style>