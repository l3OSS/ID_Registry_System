<?php
require_once __DIR__ . '/../core/lang.php'; // ข้อความทั้งระบบ — จอ QR เปิดตรง ไม่ผ่าน index.php

/**
 * หน้าจอยินยอม PDPA (ฝั่งผู้พัก)
 *
 * เปิดได้ 2 ทาง:
 *   1) แท็บเล็ต/มือถือสแกน QR → `pages/guest_display.php?d=<display_key>` (เปิดตรง ไม่ผ่าน index.php)
 *      → ไฟล์นี้ต้องพ่น <head> เองทั้งหมด ไม่งั้นจอผู้พักได้ HTML เปล่าไม่มี CSS
 *   2) เจ้าหน้าที่เปิดดูผ่าน `index.php?page=guest_display` (header.php พ่น <head> ให้แล้ว)
 *
 * สไตล์ของหน้านี้เขียนเองทั้งหมด (ไม่พึ่ง Bootstrap/ไอคอนจาก CDN) เพราะจอผู้พักอยู่บน LAN
 * ที่อาจไม่มีอินเทอร์เน็ต — ฟอนต์ใช้ Sarabun ที่ self-host อยู่แล้ว ไอคอนเป็น inline SVG
 */
$__standalone = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'guest_display.php';

// Base URL ฝั่ง PHP (ใช้เรียก api/* — จอผู้พักอยู่คนละเครื่องกับเจ้าหน้าที่)
$protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'];
$currentDir  = dirname($_SERVER['PHP_SELF']);
$projectRoot = str_replace('/pages', '', $currentDir);
$fullBaseUrl = $protocol . '://' . $host . rtrim($projectRoot, '/') . '/';

// กุญแจประจำจอ (มาจาก QR: guest_display.php?d=xxx) — ใช้บูตสแตรป poll เมื่ออุปกรณ์ผู้พักไม่ได้ล็อกอิน
// ไม่มี d และไม่ได้ล็อกอิน = sync_check คืน "none" ตลอด (จอค้างที่หน้ารอ)
$displayKey = preg_replace('/[^a-f0-9]/', '', (string)($_GET['d'] ?? ''));

if ($__standalone):
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0d6efd">
<title><?php echo e('disp.pdpa_title'); ?></title>
<style>
@font-face { font-family:'Sarabun'; src:url('<?php echo $fullBaseUrl; ?>assets/fonts/Sarabun-Regular.ttf')  format('truetype'); font-weight:400; font-display:swap; }
@font-face { font-family:'Sarabun'; src:url('<?php echo $fullBaseUrl; ?>assets/fonts/Sarabun-Medium.ttf')   format('truetype'); font-weight:500; font-display:swap; }
@font-face { font-family:'Sarabun'; src:url('<?php echo $fullBaseUrl; ?>assets/fonts/Sarabun-SemiBold.ttf') format('truetype'); font-weight:600; font-display:swap; }
@font-face { font-family:'Sarabun'; src:url('<?php echo $fullBaseUrl; ?>assets/fonts/Sarabun-Bold.ttf')     format('truetype'); font-weight:700; font-display:swap; }
</style>
</head>
<body>
<?php endif; ?>

<div id="consent-area" class="pdpa-screen">
    <div class="pdpa-standby" role="status" aria-live="polite">
        <span class="pdpa-spinner" aria-hidden="true"></span>
        <p class="pdpa-standby-title"><?php echo e('disp.waiting'); ?></p>
    </div>
</div>

<style>
/* ===== จอยินยอม PDPA — สไตล์เฉพาะหน้านี้ (ไม่พึ่ง Bootstrap เพื่อให้ใช้ได้แม้ไม่มีเน็ต) ===== */
.pdpa-screen {
    --pdpa-brand:      #0d6efd;   /* น้ำเงินหลักของระบบ (ตรงกับ Bootstrap primary เดิม) */
    --pdpa-brand-deep: #0a58ca;
    --pdpa-go:         #157347;   /* ปุ่มยืนยัน */
    --pdpa-go-deep:    #10603a;
    --pdpa-ink:        #1c2733;   /* ตัวหนังสือหลัก */
    --pdpa-muted:      #5a6673;   /* ป้ายกำกับ — เข้มพอให้ผ่าน 4.5:1 บนพื้นขาว */
    --pdpa-line:       #e3e8ef;
    --pdpa-tint:       #eaf2ff;   /* พื้นกล่อง PDPA */
    --pdpa-surface:    #ffffff;

    box-sizing: border-box;
    max-width: 33rem;
    margin: 0 auto;
    padding: clamp(1rem, 4vw, 2.5rem) clamp(0.75rem, 4vw, 1rem) 2.5rem;
    font-family: 'Sarabun', system-ui, -apple-system, 'Segoe UI', sans-serif;
    color: var(--pdpa-ink);
    line-height: 1.6;
}
.pdpa-screen *, .pdpa-screen *::before, .pdpa-screen *::after { box-sizing: inherit; }

/* พื้นหลังทั้งหน้า — ตั้งเฉพาะตอนเปิดตรงจาก QR (ผ่าน index.php ให้ header.php คุมเอง) */
body.pdpa-body { margin: 0; background: #eef1f6; min-height: 100vh; }

/* ---------- การ์ดข้อมูล ---------- */
.pdpa-card {
    background: var(--pdpa-surface);
    border-radius: 16px;
    box-shadow: 0 1px 2px rgba(16, 24, 40, .06), 0 8px 24px rgba(16, 24, 40, .08);
    padding: clamp(1.25rem, 5vw, 2rem);
    text-align: center;
}

.pdpa-photo {
    width: clamp(8.5rem, 34vw, 10.5rem);
    aspect-ratio: 1 / 1;
    object-fit: cover;
    border-radius: 14px;
    border: 3px solid var(--pdpa-brand);
    background: #f2f5f9;
    display: block;
    margin: 0 auto 1.25rem;
}

.pdpa-name {
    margin: 0 0 .25rem;
    font-size: clamp(1.5rem, 6vw, 1.875rem);
    font-weight: 700;
    line-height: 1.25;
    color: var(--pdpa-brand);
    text-wrap: balance;
}
.pdpa-idcard {
    margin: 0 0 1.5rem;
    font-size: 1rem;
    color: var(--pdpa-muted);
}
.pdpa-idcard b { font-weight: 600; color: #3d4854; letter-spacing: .02em; }

/* ---------- แถวข้อมูล (ป้ายเล็ก + ค่าตัวหนา) ---------- */
.pdpa-facts { text-align: left; margin: 0 0 1.5rem; }
.pdpa-fact { padding: .625rem 0; border-bottom: 1px solid var(--pdpa-line); }
.pdpa-fact:first-child { padding-top: 0; }
.pdpa-fact-label {
    display: block;
    font-size: .8125rem;
    color: var(--pdpa-muted);
    margin-bottom: .125rem;
}
.pdpa-fact-value { font-size: 1.125rem; font-weight: 600; word-break: break-word; }

/* ---------- กล่องนโยบาย ---------- */
.pdpa-policy {
    text-align: left;
    background: var(--pdpa-tint);
    border-radius: 12px;
    padding: 1rem 1.125rem;
    margin-bottom: 1.5rem;
}
.pdpa-policy-title {
    display: flex; align-items: center; gap: .5rem;
    margin: 0 0 .375rem;
    font-size: 1rem; font-weight: 700;
    color: var(--pdpa-brand-deep);
}
.pdpa-policy-title svg { flex: none; }
.pdpa-policy-text { margin: 0; font-size: .9375rem; color: #2b3947; }

/* ---------- ปุ่มยืนยัน ---------- */
.pdpa-confirm {
    display: flex; align-items: center; justify-content: center; gap: .625rem;
    width: 100%;
    min-height: 3.75rem;
    padding: .875rem 1.25rem;
    border: 0;
    border-radius: 999px;
    background: var(--pdpa-go);
    color: #fff;
    font-family: inherit;
    font-size: clamp(1.25rem, 5vw, 1.5rem);
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(21, 115, 71, .28);
    transition: background-color .18s ease-out, transform .18s ease-out, box-shadow .18s ease-out;
    -webkit-tap-highlight-color: transparent;
}
.pdpa-confirm:hover  { background: var(--pdpa-go-deep); }
.pdpa-confirm:active { transform: translateY(1px) scale(.995); box-shadow: 0 2px 6px rgba(21, 115, 71, .3); }
.pdpa-confirm:focus-visible { outline: 3px solid #9ec5fe; outline-offset: 3px; }
.pdpa-confirm[disabled] { background: #7fa593; box-shadow: none; cursor: progress; }
.pdpa-confirm svg { flex: none; }

/* ---------- หน้ารอ / หน้าสำเร็จ ---------- */
/* จอผู้พักเป็นคีออสก์ ตั้งทิ้งไว้ทั้งวัน — สองสถานะนี้ต้องอยู่กลางจอ ไม่ใช่ลอยอยู่ด้านบน */
.pdpa-standby, .pdpa-done {
    text-align: center;
    padding: 1rem;
    min-height: min(68vh, 32rem);
    display: grid;
    align-content: center;
    justify-items: center;   /* สปินเนอร์เป็น grid item — ถ้าไม่สั่ง จะไปชิดซ้ายทั้งที่ตัวหนังสืออยู่กลาง */
}
.pdpa-standby-title { margin: 1.25rem 0 .25rem; font-size: 1.25rem; font-weight: 600; color: #3d4854; }
.pdpa-standby-sub   { margin: 0; color: var(--pdpa-muted); }
.pdpa-spinner {
    display: inline-block; width: 2.75rem; height: 2.75rem;
    border: 4px solid #d7deea; border-top-color: var(--pdpa-brand);
    border-radius: 50%;
    animation: pdpa-spin 900ms linear infinite;
}
@keyframes pdpa-spin { to { transform: rotate(360deg); } }
.pdpa-done-icon { color: var(--pdpa-go); }
.pdpa-done-title { margin: 1rem 0 .375rem; font-size: 1.625rem; font-weight: 700; }
.pdpa-done-sub   { margin: 0; color: var(--pdpa-muted); font-size: 1.0625rem; }

/* เข้าจอครั้งเดียวตอนข้อมูลมาถึง — บอกสถานะว่า "ข้อมูลมาแล้ว" ไม่ใช่ลูกเล่น */
.pdpa-enter { animation: pdpa-rise 260ms cubic-bezier(.22, 1, .36, 1) both; }
@keyframes pdpa-rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }

@media (prefers-reduced-motion: reduce) {
    .pdpa-enter { animation: none; }
    .pdpa-confirm { transition: none; }
    .pdpa-spinner { animation-duration: 2.4s; }
}
</style>

<script>
/**
 * Tablet Sync Logic - Hybrid Version (Stable for Mobile & PC)
 */

// --- [1. Configuration & Global State] ---
let currentStatus = 'none';
let syncToken = '';
let syncService = null;
const POLL_INTERVAL = 1500;

const BASE_URL    = "<?php echo $fullBaseUrl; ?>";
const DISPLAY_KEY = "<?php echo $displayKey; ?>";
const STANDALONE  = <?php echo $__standalone ? 'true' : 'false'; ?>;
if (STANDALONE) document.body.classList.add('pdpa-body');

// ไอคอน inline (ไม่พึ่งฟอนต์ไอคอนจาก CDN — จอผู้พักอาจไม่มีเน็ต)
const ICON = {
    shield: '<svg width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0a1 1 0 0 1 .4.08l5 2A1 1 0 0 1 14 3c0 4.86-2.3 8.4-5.65 10.32a1 1 0 0 1-.7 0C4.3 11.4 2 7.86 2 3a1 1 0 0 1 .6-.92l5-2A1 1 0 0 1 8 0Zm2.5 5.5a.75.75 0 0 0-1.28-.53L7.25 6.94l-.72-.72a.75.75 0 1 0-1.06 1.06l1.25 1.25a.75.75 0 0 0 1.06 0l2.5-2.5a.75.75 0 0 0 .22-.53Z"/></svg>',
    check:  '<svg width="26" height="26" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0Zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05Z"/></svg>',
    done:   '<svg width="76" height="76" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0Zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05Z"/></svg>'
};

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
        <div class="pdpa-done pdpa-enter" role="status" aria-live="polite">
            <div class="pdpa-done-icon">${ICON.done}</div>
            <h2 class="pdpa-done-title">${L.saved_title}</h2>
            <p class="pdpa-done-sub">${L.saved_thanks}</p>
        </div>`;
}

function resetStandbyView() {
    document.getElementById('consent-area').innerHTML = `
        <div class="pdpa-standby" role="status" aria-live="polite">
            <span class="pdpa-spinner" aria-hidden="true"></span>
            <p class="pdpa-standby-title">${L.standby_title}</p>
            <p class="pdpa-standby-sub">${L.standby_sub}</p>
        </div>`;
}

function renderConsentForm(citizenJson, adminId) {
    const info = (typeof citizenJson === 'string') ? JSON.parse(citizenJson) : citizenJson;
    const consentArea = document.getElementById('consent-area');

    // รูปผู้พัก — แสดงทุกอุปกรณ์ (รวมมือถือ) · ไม่มีรูปในระบบ (sync_image ตอบ 404) → ใช้รูปเริ่มต้น
    // S4: ดึงรูปผ่าน endpoint ที่ยืนยัน token (ไม่ใช้ path ชื่อเดาได้ตาม adminId อีกต่อไป)
    const imgSrc = `${BASE_URL}api/sync_image.php?t=${encodeURIComponent(syncToken)}&_=${new Date().getTime()}`;
    const noImg  = `${BASE_URL}assets/noimg.jpg`;
    const imageHtml = `<img src="${imgSrc}" onerror="this.onerror=null; this.src='${noImg}';" class="pdpa-photo" alt="">`;

    consentArea.innerHTML = `
        <div class="pdpa-card pdpa-enter">
            ${imageHtml}

            <h1 class="pdpa-name">${escapeHtml(info.full_name)}</h1>
            <p class="pdpa-idcard">${L.id_card_label} <b>${escapeHtml(info.id_card)}</b></p>

            <div class="pdpa-facts">
                <div class="pdpa-fact">
                    <span class="pdpa-fact-label">${L.birth_label}</span>
                    <span class="pdpa-fact-value">${escapeHtml(info.birth)}</span>
                </div>
                <div class="pdpa-fact">
                    <span class="pdpa-fact-label">${L.address_label}</span>
                    <span class="pdpa-fact-value">${escapeHtml(info.address)}</span>
                </div>
            </div>

            <div class="pdpa-policy">
                <h2 class="pdpa-policy-title">${ICON.shield} ${L.pdpa_title}</h2>
                <p class="pdpa-policy-text">${L.pdpa_text}</p>
            </div>

            <button type="button" id="pdpaConfirmBtn" class="pdpa-confirm" onclick="confirmFromTablet(this)">
                ${ICON.check} <span>${L.confirm_btn}</span>
            </button>
        </div>`;
}

// --- [3. Action Handlers] ---

async function confirmFromTablet(btn) {
    // กันกดซ้ำระหว่างรอเซิร์ฟเวอร์ (แตะรัวบนแท็บเล็ตได้ง่าย)
    if (btn) {
        if (btn.disabled) return;
        btn.disabled = true;
    }
    try {
        const res = await fetch(BASE_URL + 'api/sync_confirm.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ t: syncToken })
        });
        if (!res.ok) throw new Error('confirm failed');
        currentStatus = 'confirmed';
        clearInterval(syncService);
        showSuccessView();
    } catch (e) {
        if (btn) btn.disabled = false;   // ปล่อยให้กดใหม่ได้ถ้าส่งไม่สำเร็จ
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

<?php if ($__standalone): ?>
</body>
</html>
<?php endif; ?>
