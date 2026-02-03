<?php
/**
 * Page: Guest Registration Form
 * Handles new registration and editing of resident data.
 * Integrated with Smart Card Protocol and Tablet Sync.
 */

require_once 'core/auth.php';
require_once 'core/security.php';
require_once 'core/functions.php';

// Access Control: Minimum level 3 (Registrar)
checkPermission(3);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?? 0;
$citizen = null;
$action = ($id > 0) ? 'update' : 'insert';
$photo_show = "assets/noimg.jpg";
$selected_v = [];

// 1. Fetch Master Data
$v_master = $pdo->query("SELECT id, v_name FROM vulnerable_master ORDER BY id ASC")->fetchAll();

if ($id > 0) {
    // 2. Fetch Existing Citizen Data
    $sql = "SELECT c.*, al.subdistrict AS l_tambon, al.district AS l_amphoe, al.province AS l_province
            FROM citizens c
            LEFT JOIN address_lookup al ON c.address_id = al.id
            WHERE c.id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $citizen = $stmt->fetch();

    if ($citizen) {
        $citizen['id_card'] = decryptData($citizen['id_card_enc'] ?? '');
        $citizen['phone']   = !empty($citizen['phone_enc']) ? decryptData($citizen['phone_enc']) : '';
       
        // Address Logic: Prefer Lookup Data over raw text
        $citizen['addr_tambon']   = $citizen['l_tambon']   ?? $citizen['addr_tambon']   ?? '';
        $citizen['addr_amphoe']   = $citizen['l_amphoe']   ?? $citizen['addr_amphoe']   ?? '';
        $citizen['addr_province'] = $citizen['l_province'] ?? $citizen['addr_province'] ?? '';

        if (!empty($citizen['photo_path']) && file_exists($citizen['photo_path'])) {
            $photo_show = $citizen['photo_path'];
        }

        // Fetch selected vulnerable mapping
        $stmt_map = $pdo->prepare("SELECT v_id FROM citizen_vulnerable_map WHERE citizen_id = ?");
        $stmt_map->execute([$id]);
        $selected_v = $stmt_map->fetchAll(PDO::FETCH_COLUMN);

        writeLog($pdo, 'VIEW_DETAIL', "Viewed profile: {$citizen['firstname']} (ID: $id)");
    }
}
?>

<div class="container mt-3 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="fw-bold text-dark">
            <?php echo ($id > 0) ? "✏️ แก้ไขข้อมูลบุคคล" : "📝 ลงทะเบียนเข้าพักใหม่"; ?>
        </h3>
        <a href="index.php?page=guest_list" class="btn btn-outline-secondary btn-sm rounded-pill">
            <i class="bi bi-arrow-left"></i> กลับหน้ารายชื่อ
        </a>
    </div>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header bg-primary text-white py-3">
            <h5 class="mb-0"><i class="bi bi-person-lines-fill"></i> แบบฟอร์มบันทึกข้อมูล</h5>
        </div>
        <div class="card-body p-4">
            <form action="pages/guest_check.php" method="POST" id="mainCitizenForm">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="hidden" name="action" value="<?php echo $action; ?>">
                <input type="hidden" name="photo_base64" id="hidden_photo_data">
                <input type="hidden" name="address_id" id="address_id" value="<?php echo htmlspecialchars($citizen['address_id'] ?? ''); ?>">

                <div class="row mb-4">
                    <div class="col-md-12 text-end mb-3">
                        <button type="button" class="btn btn-warning btn-lg shadow-sm fw-bold" onclick="readSmartCard()">
                            <i class="bi bi-credit-card-2-front-fill"></i> อ่านข้อมูลจากบัตรประชาชน
                        </button>
                    </div>
                    <div class="col-md-3 text-center mb-3">
                        <div class="position-relative d-inline-block">
                            <img id="customer_photo" src="<?php echo $photo_show; ?>" class="img-thumbnail shadow-sm mb-2" style="width: 160px; height: 190px; object-fit: cover; border-radius: 12px;">
                            <div class="small text-muted">รูปถ่ายหน้าบัตร</div>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label text-danger fw-bold">เลขประจำตัวประชาชน*</label>
                                <input type="text" name="id_card" id="id_card" class="form-control form-control-lg border-primary" maxlength="13" placeholder="เลขประจำตัว 13 หลัก" required value="<?php echo htmlspecialchars($citizen['id_card'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">คำนำหน้า</label>
                                <input type="text" name="prefix" id="prefix" class="form-control" placeholder="นาย, นางสาว" value="<?php echo htmlspecialchars($citizen['prefix'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ชื่อ - นามสกุล *</label>
                                <div class="input-group">
                                    <input type="text" name="firstname" id="firstname" class="form-control" placeholder="ชื่อ" required value="<?php echo htmlspecialchars($citizen['firstname'] ?? ''); ?>">
                                    <input type="text" name="lastname" id="lastname" class="form-control" placeholder="นามสกุล" required value="<?php echo htmlspecialchars($citizen['lastname'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">วันเดือนปีเกิด (ค.ศ.)</label>
                                <input type="date" name="birthdate" id="birthdate" class="form-control" value="<?php echo htmlspecialchars($citizen['birthdate'] ?? ''); ?>" onchange="autoCheckAge(this.value)">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-primary fw-bold">เพศ</label>
                                <select name="gender" id="gender" class="form-select border-primary">
                                    <option value="">- ระบุเพศ -</option>
                                    <option value="Male" <?php echo (($citizen['gender'] ?? '') =='Male') ? 'selected' : ''; ?>>ชาย</option>
                                    <option value="Female" <?php echo (($citizen['gender'] ?? '') =='Female') ? 'selected' : ''; ?>>หญิง</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">เบอร์โทรศัพท์ติดต่อ</label>
                                <input type="text" name="phone" id="phone" class="form-control" placeholder="089-123-4567" value="<?php echo htmlspecialchars($citizen['phone'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

               
                <div class="p-3 mb-4 border-0 rounded bg-light shadow-sm" style="border-left: 5px solid #0d6efd !important;">
                    <label class="fw-bold text-primary mb-3"><i class="bi bi-geo-alt-fill"></i> 2. ที่อยู่ตามทะเบียนบ้าน</label>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">บ้านเลขที่ / หมู่ที่</label>
                            <input type="text" name="addr_number" id="addr_number" class="form-control" placeholder="บ้านทรงไทย 123 ม.4" value="<?php echo htmlspecialchars($citizen['addr_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">ตำบล / แขวง</label>
                            <input type="text" name="addr_tambon" id="addr_tambon" class="form-control" placeholder="พิมพ์ชื่อตำบลเพื่อค้นหา" value="<?php echo htmlspecialchars($citizen['addr_tambon'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">อำเภอ / เขต</label>
                            <input type="text" name="addr_amphoe" id="addr_amphoe" class="form-control" value="<?php echo htmlspecialchars($citizen['addr_amphoe'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">จังหวัด</label>
                            <input type="text" name="addr_province" id="addr_province" class="form-control" value="<?php echo htmlspecialchars($citizen['addr_province'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mt-2 text-muted" style="font-size: 0.85rem;">
                        <i class="bi bi-magic"></i> พิมพ์เพื่อค้นหา ตำบล/อำเภอ/จังหวัด อัตโนมัติ
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="p-3 h-100 rounded border border-danger border-opacity-25 bg-white shadow-sm">
                            <h6 class="text-danger fw-bold border-bottom pb-2 mb-3"><i class="bi bi-heart-pulse-fill"></i> กลุ่มเป้าหมายพิเศษ</h6>
                            <div class="mb-3">
                                <?php foreach($v_master as $v): if (in_array($v['id'], [1, 2])) continue; ?>
                                <div class="form-check form-check-inline mb-2">
                                    <input class="form-check-input" type="checkbox" name="vulnerable[]" value="<?=$v['id']?>" id="v_<?=$v['id']?>" <?=in_array($v['id'], $selected_v) ? 'checked' : ''?>>
                                    <label class="form-check-label small fw-bold" for="v_<?=$v['id']?>"><?=$v['v_name']?></label>
                                </div>
                                <?php endforeach; ?>
                                <div class="d-none">
                                    <input type="checkbox" name="vulnerable[]" id="v_1" value="1" <?=in_array(1, $selected_v) ? 'checked' : ''?>>
                                    <input type="checkbox" name="vulnerable[]" id="v_2" value="2" <?=in_array(2, $selected_v) ? 'checked' : ''?>>
                                </div>
                            </div>

                    <?php
                        $custom_master = $pdo->query("SELECT * FROM custom_field_master WHERE is_active = 1")->fetchAll();                        
                        // แยกกลุ่มข้อมูลเพื่อให้แสดงผลสวยงาม
                        $checkbox_fields = array_filter($custom_master, function($f) { return $f['field_type'] != 'text'; });
                        $text_fields = array_filter($custom_master, function($f) { return $f['field_type'] == 'text'; });
                    ?>
                    <?php if(!empty($checkbox_fields)): ?>
                    <div class="row pt-2">
                        <?php foreach($checkbox_fields as $cm):
                            $val = "";
                            if ($id > 0) {
                                $stmt_val = $pdo->prepare("SELECT field_value FROM citizen_custom_values WHERE citizen_id = ? AND field_id = ?");
                                $stmt_val->execute([$id, $cm['id']]);
                                $val = $stmt_val->fetchColumn();
                            }
                        ?>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="form-check form-switch pt-1">
                                    <input class="form-check-input" type="checkbox" name="custom[<?=$cm['id']?>]" value="Yes" <?=$val == 'Yes' ? 'checked' : ''?>>
                                    <label class="form-check-label small fw-bold"><?=$cm['field_name']?></label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if(!empty($text_fields)): ?>
                    <div class="row mt-2">
                        <?php foreach($text_fields as $cm):
                            $val = "";
                            if ($id > 0) {
                                $stmt_val = $pdo->prepare("SELECT field_value FROM citizen_custom_values WHERE citizen_id = ? AND field_id = ?");
                                $stmt_val->execute([$id, $cm['id']]);
                                $val = $stmt_val->fetchColumn();
                            }
                        ?>
                            <div class="col-md-4 mb-3">
                                <label class="form-label small fw-bold text-secondary mb-1"><?=$cm['field_name']?></label>
                                <input type="text" name="custom[<?=$cm['id']?>]" class="form-control form-control-sm" value="<?=htmlspecialchars($val ?? '')?>" placeholder="ระบุข้อมูล...">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                </div>

                    <div class="col-md-6">
                        <div class="p-3 h-100 rounded border border-primary border-opacity-25 bg-white shadow-sm">
                            <h6 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="bi bi-clipboard-pulse"></i> ข้อมูลสุขภาพ</h6>
                            <label class="form-label small">โรคประจำตัว / แพ้ยา</label>
                            <input type="text" name="medical_info" class="form-control mb-2" placeholder="อาการเจ็บป่วย" value="<?php echo htmlspecialchars($citizen['medical_info'] ?? ''); ?>">
                            <label class="form-label small">หมายเหตุเพิ่มเติม</label>
                            <textarea name="notes" class="form-control" placeholder="ข้อมูลเจาะจงพิเศษ" rows="1"><?php echo htmlspecialchars($citizen['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="p-3 mb-4 rounded border-success border-opacity-50 border bg-success bg-opacity-10">
                    <h6 class="text-success fw-bold border-bottom pb-2 mb-3"><i class="bi bi-house-door-fill"></i> 5. การเข้าพักและคำยินยอม</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">วันที่เข้าพัก</label>
                            <input type="text" name="check_in_date" id="check_in_date" class="form-control bg-white" required readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">ประเภทสถานที่พัก</label>
                            <select name="location_type" class="form-select">
                                <option value="Inside">🏢 พักในศูนย์</option>
                                <option value="Outside">🏕️ พักนอกศูนย์ (เต็นท์/ศาลา)</option>
                            </select>
                        </div>
                        <div class="col-12 border-top pt-3">
                            <div class="form-check mb-2">
                                <input class="form-check-input border-danger" type="checkbox" id="confirmData" required>
                                <label class="form-check-label fw-bold text-danger" for="confirmData">ข้าพเจ้าตรวจสอบข้อมูลทั้งหมดแล้วว่าถูกต้อง</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input border-primary" type="checkbox" id="pdpaConsent" name="pdpa_consent" value="1" required disabled>
                                <label class="form-check-label small fw-bold text-dark" for="pdpaConsent">
                                    ยินยอมให้ระบบประมวลผลข้อมูลส่วนบุคคลตามนโยบาย PDPA (ส่งตรวจทานที่แท็บเล็ตเพื่อปลดล็อค)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-5">
                        <button type="button" class="btn btn-info w-100 btn-lg shadow-sm fw-bold" onclick="sendToTablet()">
                            <i class="bi bi-tablet-landscape-fill"></i> ส่งข้อมูลไปที่แท็บเล็ต
                        </button>
                    </div>
                    <div class="col-md-5">
                        <button type="submit" class="btn btn-primary w-100 btn-lg shadow fw-bold">
                            <i class="bi bi-cloud-check-fill"></i> บันทึกข้อมูลและเช็คอิน
                        </button>
                    </div>
                    <div class="col-md-2">
                        <a href="index.php?page=guest_list" class="btn btn-outline-secondary w-100 btn-lg">ยกเลิก</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/**
 * UI & API Interactions
 */

// 1. Initializations
$(document).ready(function() {
    // ฟังก์ชันกลางสำหรับหา Address ID เพื่อลดความซ้ำซ้อนของโค้ด
    function lookupInternalAddress(t, a, p) {
        if (!t || !a || !p) return;
       
        // ล้างคำนำหน้าขยะออกก่อนส่งไป API เพื่อให้ Match ง่ายขึ้น
        const cleanT = t.replace(/ตำบล|ต\./g, '').trim();
        const cleanA = a.replace(/อำเภอ|อ\./g, '').trim();
        const cleanP = p.replace(/จังหวัด|จ\./g, '').trim();

        const url = `api/address_id.php?district=${encodeURIComponent(cleanT)}&amphoe=${encodeURIComponent(cleanA)}&province=${encodeURIComponent(cleanP)}`;
       
        fetch(url)
            .then(res => res.json())
            .then(res => {
                if (res.status === 'success') {
                    $('#address_id').val(res.address_id);
                    console.log("Found Address ID:", res.address_id);
                } else {
                    console.warn("Address ID not found in DB");
                }
            })
            .catch(err => console.error("Fetch Error:", err));
    }

    // 1. Address Autocomplete (jquery.Thailand.js)
    if ($.Thailand) {
        $.Thailand({
            database: './assets/jquery.Thailand.js/database/db.json',
            $district: $('#addr_tambon'),
            $amphoe: $('#addr_amphoe'),
            $province: $('#addr_province'),
            onSelect: function(data) {
                // เมื่อเลือกจากลิสต์ ให้เรียกฟังก์ชันหา ID ทันที
                lookupInternalAddress(data.district, data.amphoe, data.province);
            }
        });
    }

    // 2. ดักจับตอน "ออกจากช่องกรอก" (Blur) กรณีพิมพ์เองแบบแมนนวล
    $('#addr_tambon, #addr_amphoe, #addr_province').on('blur', function() {
        // หน่วงเวลาเล็กน้อยเพื่อให้ค่าจาก Thailand.js เติมเสร็จก่อน (กรณีเลือกจากลิสต์)
        setTimeout(function() {
            lookupInternalAddress(
                $('#addr_tambon').val(),
                $('#addr_amphoe').val(),
                $('#addr_province').val()
            );
        }, 200);
    });

    // 3. Reset Address ID เมื่อมีการพิมพ์ (ป้องกันข้อมูลเก่าค้าง)
    $('#addr_tambon, #addr_amphoe, #addr_province').on('input', function(e) {
        if (e.isTrusted) { // ตรวจสอบว่าเป็นการพิมพ์จริงจากมนุษย์ ไม่ใช่สคริปต์เติมให้
            $('#address_id').val('');
        }
    });

    // DateTime Picker
    flatpickr("#check_in_date", {
        enableTime: true, time_24hr: true, dateFormat: "Y-m-d H:i",
        defaultDate: new Date(), locale: "th", altInput: true, altFormat: "j M Y (H:i น.)"
    });
});

// 2. Smart Card Functions
async function readSmartCard() {
    const btn = document.querySelector('button[onclick="readSmartCard()"]');
    const originalContent = btn.innerHTML;
   
    // 1. เริ่มกระบวนการเรียกโปรแกรม Smart Card
    window.location.href = "smartcard://";
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังเปิดโปรแกรม...';
    btn.disabled = true;

    // รอโปรแกรม Local Service บูต
    await new Promise(r => setTimeout(r, 3000));
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังดึงข้อมูลจากบัตร...';

    try {
        const response = await fetch('http://localhost:8888/read/');
        const data = await response.json(); // ได้รับข้อมูล data ที่นี่
       
        if(data.error) throw new Error(data.error);

        // 2. จัดการ Clean ข้อมูลที่อยู่ (ย้ายมาไว้ตรงนี้เพื่อให้มีตัวแปร data ใช้งาน)
        const cleanT = data.Tambon.replace(/ตำบล|ต\./g, '').trim();
        const cleanA = data.Amphoe.replace(/อำเภอ|อ\./g, '').trim();
        const cleanP = data.Province.replace(/จังหวัด|จ\./g, '').trim();

        // 3. Map ข้อมูลลงฟิลด์ต่างๆ
        setVal('id_card', data.CitizenID);
        setVal('firstname', data.Firstname);
        setVal('lastname', data.Lastname);
        setVal('prefix', data.Prefix);
        setVal('birthdate', data.BirthDate);

        // ตรวจสอบเพศ
        let rawGender = data.Gender ? data.Gender.toString().trim() : "";
        let detectedGender = (rawGender == "1" || rawGender.toLowerCase() === "male" || rawGender === "ชาย") ? "Male" :
                             (rawGender == "2" || rawGender.toLowerCase() === "female" || rawGender === "หญิง") ? "Female" : "";
        setVal('gender', detectedGender);
       
        // ที่อยู่ (Text fields)
        setVal('addr_number', data.HouseNo + (data.Moo ? ` หมู่ ${data.Moo.replace(/\D/g,'')}` : ''));
        setVal('addr_tambon', cleanT);
        setVal('addr_amphoe', cleanA);
        setVal('addr_province', cleanP);

        // 4. 🎯 จุดชี้ขาด: เรียกหา Address ID จาก Database
        // ใช้ "ชื่อที่อยู่" ค้นหาแทน "เลขบัตร" เพื่อความแม่นยำในระบบฐานข้อมูล
        const addrUrl = `api/address_id.php?district=${encodeURIComponent(cleanT)}&amphoe=${encodeURIComponent(cleanA)}&province=${encodeURIComponent(cleanP)}`;
       
        fetch(addrUrl)
            .then(res => res.json())
            .then(res => {
                if(res.status === 'success') {
                    // ใส่ค่าลงใน Hidden Input โดยตรง
                    document.getElementById('address_id').value = res.address_id;
                    console.log("Verified Address ID:", res.address_id);
                } else {
                    console.warn("Address not found in database lookup");
                }
            });

        // 5. จัดการรูปภาพ
        if(data.Photo) {
            document.getElementById('customer_photo').src = "data:image/jpeg;base64," + data.Photo;
            document.getElementById('hidden_photo_data').value = data.Photo;
        }

        autoCheckAge(data.BirthDate);
        Swal.fire('สำเร็จ', 'ดึงข้อมูลจากบัตรเรียบร้อยแล้ว', 'success');

    } catch (err) {
        console.error(err);
        Swal.fire('ผิดพลาด', 'ไม่สามารถอ่านบัตรได้: ' + err.message, 'error');
    } finally {
        btn.innerHTML = originalContent;
        btn.disabled = false;
    }
}

// 3. Helper Logic
function setVal(id, value) {
    const el = document.getElementById(id);
    if (el) { el.value = value; el.dispatchEvent(new Event('input')); }
}

function autoCheckAge(birthDateStr) {
    if(!birthDateStr) return;
    const birthDate = new Date(birthDateStr);
    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const m = today.getMonth() - birthDate.getMonth();
    // ถ้ายังไม่ถึงเดือนเกิด หรือถึงเดือนเกิดแต่ยังไม่ถึงวันเกิด ให้ลดอายุลง 1 ปี
    if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
        age--;
    }

    // อัปเดต Checkbox (ID 1: เด็กเล็ก <= 5 ปี, ID 2: ผู้สูงอายุ >= 60 ปี)
    if(document.getElementById('v_1')) document.getElementById('v_1').checked = (age >= 0 && age <= 5);
    if(document.getElementById('v_2')) document.getElementById('v_2').checked = (age >= 60);
}

// 4. Tablet Synchronization
async function sendToTablet() {
    const formData = {
        prefix: $('#prefix').val(),
        fname: $('#firstname').val(),
        lname: $('#lastname').val(),
        idCard: $('#id_card').val(),
        birthdate: $('#birthdate').val(),
        addr_number: $('#addr_number').val(),
        addr_tambon: $('#addr_tambon').val(),
        addr_amphoe: $('#addr_amphoe').val(),
        addr_province: $('#addr_province').val(),
        photo: $('#hidden_photo_data').val()
    };

    Swal.fire({ title: 'กำลังส่งข้อมูล...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        // 1. Reset สถานะเดิม
        await fetch('api/sync_reset.php');

        // 2. ส่งข้อมูลไปที่ Tablet
        const res = await fetch('api/sync_send.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(formData)
        });

        // ตรวจสอบว่า Response เป็น JSON หรือไม่
        const responseText = await res.text();
        try {
            const data = JSON.parse(responseText);
            if (!res.ok) throw new Error(data.message || 'เกิดข้อผิดพลาดที่เซิร์ฟเวอร์');
        } catch (e) {
            // ถ้าพังตรงนี้ แสดงว่าค่าที่ส่งกลับมาเป็น HTML (Error PHP)
            console.error("Server Error Response:", responseText);
            throw new Error("เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง (Check Console)");
        }

        // 3. เริ่ม Loop ตรวจสอบสถานะการยืนยัน
        let timer;
        Swal.fire({
            title: 'ส่งไปแท็บเล็ตแล้ว',
            text: 'รอผู้พักตรวจสอบข้อมูลและยืนยัน...',
            icon: 'info',
            allowOutsideClick: false,
            showCancelButton: true,
            cancelButtonText: 'ยกเลิก',
            didOpen: () => {
                Swal.showLoading();
                timer = setInterval(async () => {
                    try {
                        const checkRes = await fetch('api/sync_check.php');
                        const checkText = await checkRes.text(); // อ่านเป็น Text ก่อน
                        const check = JSON.parse(checkText); // ค่อยแปลงเป็น JSON

                        if(check.status === 'confirmed') {
                            clearInterval(timer);
                            $('#pdpaConsent').prop('disabled', false).prop('checked', true);
                            Swal.fire('ยืนยันแล้ว', 'ผู้พักกดยินยอมข้อมูลเรียบร้อย', 'success');
                        }
                    } catch (err) {
                        console.error("Polling Error:", err);
                    }
                }, 2000);
            },
            willClose: () => clearInterval(timer)
        });
    } catch (e) {
        console.error(e);
        Swal.fire('ล้มเหลว', e.message, 'error');
    }
}
</script>