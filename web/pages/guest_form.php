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
requirePermission('guests.register');

// สวิตช์ระบบยินยอม PDPA (setting.php) — ปิดแล้วจะไม่มีทั้งช่องยินยอมและปุ่มส่งไปแท็บเล็ต
$pdpa_on = pdpaEnabled($pdo);

// P7: URL ใช้ public_id → แปลงเป็น internal id
$public_id = preg_replace('/\D/', '', (string)($_GET['id'] ?? ''));
$id = resolveCitizenId($pdo, $public_id);
$citizen = null;
$action = ($id > 0) ? 'update' : 'insert';
$photo_show = "assets/noimg.jpg";
$selected_v = [];

// 1. Fetch Master Data
$v_master = $pdo->query("SELECT id, v_name FROM vulnerable_master ORDER BY id ASC")->fetchAll();

if ($id > 0) {
    // 2. Fetch Existing Citizen Data
    $sql = "SELECT c.*, al.subdistrict AS l_tambon, al.district AS l_amphoe, al.province AS l_province, al.zipcode AS l_zipcode,
                   hl.subdistrict AS h_tambon, hl.district AS h_amphoe, hl.province AS h_province, hl.zipcode AS h_zipcode
            FROM citizens c
            LEFT JOIN address_lookup al ON c.address_id = al.id
            LEFT JOIN address_lookup hl ON c.home_address_id = hl.id
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
        // รหัสไปรษณีย์ไม่ได้เก็บใน citizens — มาจาก address_lookup ตาม address_id เท่านั้น
        $citizen['addr_zipcode']  = $citizen['l_zipcode']  ?? '';

        // ภูมิลำเนา: เก็บเป็น home_address_id เหมือนกัน · same=1 → ใช้ที่อยู่ตามทะเบียนบ้าน (home_* ว่าง)
        $citizen['home_same_as_reg']   = (int)($citizen['home_same_as_reg'] ?? 1);
        $citizen['home_addr_tambon']   = $citizen['h_tambon']  ?? '';
        $citizen['home_addr_amphoe']   = $citizen['h_amphoe']  ?? '';
        $citizen['home_addr_province'] = $citizen['h_province'] ?? '';
        $citizen['home_addr_zipcode']  = $citizen['h_zipcode'] ?? '';

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
            <?php echo ($id > 0) ? e('form.title_edit') : e('form.title_new'); ?>
        </h3>
        <a href="index.php?page=guest_list" class="btn btn-outline-secondary btn-sm rounded-pill">
            <i class="bi bi-arrow-left"></i> <?php echo e('hist.back_to_list'); ?>
        </a>
    </div>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header bg-primary text-white py-3">
            <h5 class="mb-0"><i class="bi bi-person-lines-fill"></i> <?php echo e('form.card_title'); ?></h5>
        </div>
        <div class="card-body p-4">
            <form action="pages/guest_check.php" method="POST" id="mainCitizenForm">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($public_id); ?>">
                <input type="hidden" name="action" value="<?php echo $action; ?>">
                <input type="hidden" name="photo_base64" id="hidden_photo_data">
                <input type="hidden" name="address_id" id="address_id" value="<?php echo htmlspecialchars($citizen['address_id'] ?? ''); ?>">

                <div class="row mb-4">
                    <div class="col-md-12 text-end mb-3">
                        <button type="button" class="btn btn-warning btn-lg shadow-sm fw-bold" onclick="readSmartCard()">
                            <i class="bi bi-credit-card-2-front-fill"></i> <?php echo e('form.read_card'); ?>
                        </button>
                    </div>
                    <div class="col-md-3 text-center mb-3">
                        <div class="position-relative d-inline-block">
                            <img id="customer_photo" src="<?php echo $photo_show; ?>" class="img-thumbnail shadow-sm mb-2" style="width: 160px; height: 190px; object-fit: cover; border-radius: 12px;">
                            <div class="small text-muted"><?php echo e('form.photo_label'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label text-danger fw-bold"><?php echo e('form.id_card'); ?></label>
                                <input type="text" name="id_card" id="id_card" class="form-control form-control-lg border-primary" maxlength="13" placeholder="<?php echo e('form.id_card_ph'); ?>" required value="<?php echo htmlspecialchars($citizen['id_card'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?php echo e('form.prefix'); ?></label>
                                <div class="position-relative">
                                    <input type="text" name="prefix" id="prefix" class="form-control" placeholder="<?php echo e('form.prefix_ph'); ?>" autocomplete="off" value="<?php echo htmlspecialchars($citizen['prefix'] ?? ''); ?>">
                                    <div id="prefixSuggest" class="list-group position-absolute w-100 shadow-sm" style="z-index:1050; display:none; max-height:220px; overflow:auto;"></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold"><?php echo e('form.fullname'); ?></label>
                                <div class="input-group">
                                    <input type="text" name="firstname" id="firstname" class="form-control" placeholder="<?php echo e('form.firstname_ph'); ?>" required value="<?php echo htmlspecialchars($citizen['firstname'] ?? ''); ?>">
                                    <input type="text" name="lastname" id="lastname" class="form-control" placeholder="<?php echo e('form.lastname_ph'); ?>" required value="<?php echo htmlspecialchars($citizen['lastname'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo e('form.birthdate'); ?></label>
                                <input type="text" name="birthdate" id="birthdate" class="form-control thai-date" value="<?php echo htmlspecialchars($citizen['birthdate'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-primary fw-bold"><?php echo e('form.gender'); ?></label>
                                <select name="gender" id="gender" class="form-select border-primary">
                                    <option value=""><?php echo e('form.gender_ph'); ?></option>
                                    <option value="Male" <?php echo (($citizen['gender'] ?? '') =='Male') ? 'selected' : ''; ?>><?php echo e('common.male'); ?></option>
                                    <option value="Female" <?php echo (($citizen['gender'] ?? '') =='Female') ? 'selected' : ''; ?>><?php echo e('common.female'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo e('form.phone'); ?></label>
                                <input type="text" name="phone" id="phone" class="form-control" placeholder="089-123..." value="<?php echo htmlspecialchars($citizen['phone'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

               
                <div class="p-3 mb-4 border-0 rounded bg-light shadow-sm" style="border-left: 5px solid #0d6efd !important;">
                    <label class="fw-bold text-primary mb-3"><i class="bi bi-geo-alt-fill"></i> <?php echo e('form.addr_section'); ?></label>
                    <!-- 4 ช่องเดิมใช้ col-md (แบ่งเท่ากันจาก 10 คอลัมน์ที่เหลือ) เพื่อเว้นที่ให้รหัสไปรษณีย์ -->
                    <div class="row g-3">
                        <div class="col-md">
                            <label class="form-label small fw-bold"><?php echo e('form.addr_number'); ?></label>
                            <input type="text" name="addr_number" id="addr_number" class="form-control" placeholder="<?php echo e('form.addr_number_ph'); ?>" value="<?php echo htmlspecialchars($citizen['addr_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md">
                            <label class="form-label small fw-bold"><?php echo e('form.addr_tambon'); ?></label>
                            <input type="text" name="addr_tambon" id="addr_tambon" class="form-control" placeholder="<?php echo e('form.addr_tambon_ph'); ?>" value="<?php echo htmlspecialchars($citizen['addr_tambon'] ?? ''); ?>">
                        </div>
                        <div class="col-md">
                            <label class="form-label small fw-bold"><?php echo e('form.addr_amphoe'); ?></label>
                            <input type="text" name="addr_amphoe" id="addr_amphoe" class="form-control" value="<?php echo htmlspecialchars($citizen['addr_amphoe'] ?? ''); ?>">
                        </div>
                        <div class="col-md">
                            <label class="form-label small fw-bold"><?php echo e('form.addr_province'); ?></label>
                            <input type="text" name="addr_province" id="addr_province" class="form-control" value="<?php echo htmlspecialchars($citizen['addr_province'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold"><?php echo e('form.addr_zipcode'); ?></label>
                            <input type="text" name="addr_zipcode" id="addr_zipcode" class="form-control" maxlength="5" inputmode="numeric" placeholder="<?php echo e('form.addr_zipcode_ph'); ?>" value="<?php echo htmlspecialchars($citizen['addr_zipcode'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mt-2 text-muted" style="font-size: 0.85rem;">
                        <i class="bi bi-magic"></i> <?php echo e('form.addr_hint'); ?>
                    </div>
                </div>

                <?php /* เรคคอร์ดใหม่: สวิตช์ปิดไว้ก่อน (กรอกภูมิลำเนาเอง) · แก้ไข: ตามค่าที่บันทึกไว้ */ ?>
                <?php $home_same = ($id > 0) ? (int)($citizen['home_same_as_reg'] ?? 1) : 0; ?>
                <div class="p-3 mb-4 border-0 rounded bg-light shadow-sm" style="border-left: 5px solid #0dcaf0 !important;">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <label class="fw-bold text-primary mb-0"><i class="bi bi-signpost-2-fill"></i> <?php echo e('form.home_section'); ?></label>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" name="home_same_as_reg" id="home_same_as_reg" value="1" <?php echo $home_same ? 'checked' : ''; ?>>
                            <label class="form-check-label small fw-bold" for="home_same_as_reg"><?php echo e('form.home_same'); ?></label>
                        </div>
                    </div>
                    <input type="hidden" name="home_address_id" id="home_address_id" value="<?php echo htmlspecialchars((string)($citizen['home_address_id'] ?? '')); ?>">
                    <div class="row g-3" id="homeAddrBlock">
                        <div class="col-md">
                            <label class="form-label small fw-bold"><?php echo e('form.addr_number'); ?></label>
                            <input type="text" name="home_addr_number" id="home_addr_number" class="form-control home-addr" data-part="addr_number" placeholder="<?php echo e('form.addr_number_ph'); ?>" value="<?php echo htmlspecialchars($citizen['home_addr_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md">
                            <label class="form-label small fw-bold"><?php echo e('form.addr_tambon'); ?></label>
                            <input type="text" name="home_addr_tambon" id="home_addr_tambon" class="form-control home-addr" data-part="addr_tambon" placeholder="<?php echo e('form.addr_tambon_ph'); ?>" value="<?php echo htmlspecialchars($citizen['home_addr_tambon'] ?? ''); ?>">
                        </div>
                        <div class="col-md">
                            <label class="form-label small fw-bold"><?php echo e('form.addr_amphoe'); ?></label>
                            <input type="text" name="home_addr_amphoe" id="home_addr_amphoe" class="form-control home-addr" data-part="addr_amphoe" value="<?php echo htmlspecialchars($citizen['home_addr_amphoe'] ?? ''); ?>">
                        </div>
                        <div class="col-md">
                            <label class="form-label small fw-bold"><?php echo e('form.addr_province'); ?></label>
                            <input type="text" name="home_addr_province" id="home_addr_province" class="form-control home-addr" data-part="addr_province" value="<?php echo htmlspecialchars($citizen['home_addr_province'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold"><?php echo e('form.addr_zipcode'); ?></label>
                            <input type="text" name="home_addr_zipcode" id="home_addr_zipcode" class="form-control home-addr" data-part="addr_zipcode" maxlength="5" inputmode="numeric" placeholder="<?php echo e('form.addr_zipcode_ph'); ?>" value="<?php echo htmlspecialchars($citizen['home_addr_zipcode'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mt-2 text-muted" style="font-size: 0.85rem;">
                        <i class="bi bi-info-circle"></i> <?php echo e('form.home_same_hint'); ?>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="p-3 h-100 rounded border border-danger border-opacity-25 bg-white shadow-sm">
                            <h6 class="text-danger fw-bold border-bottom pb-2 mb-3"><i class="bi bi-heart-pulse-fill"></i> <?php echo e('form.special_groups'); ?></h6>
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
                                <input type="text" name="custom[<?=$cm['id']?>]" class="form-control form-control-sm" value="<?=htmlspecialchars($val ?? '')?>" placeholder="<?php echo e('form.custom_text_ph'); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                </div>

                    <div class="col-md-6">
                        <div class="p-3 h-100 rounded border border-primary border-opacity-25 bg-white shadow-sm">
                            <h6 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="bi bi-clipboard-pulse"></i> <?php echo e('form.health'); ?></h6>
                            <label class="form-label small"><?php echo e('form.medical_label'); ?></label>
                            <input type="text" name="medical_info" class="form-control mb-2" placeholder="<?php echo e('form.medical_ph'); ?>" value="<?php echo htmlspecialchars($citizen['medical_info'] ?? ''); ?>">
                            <label class="form-label small"><?php echo e('form.notes_label'); ?></label>
                            <textarea name="notes" class="form-control" placeholder="<?php echo e('form.notes_ph'); ?>" rows="1"><?php echo htmlspecialchars($citizen['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="p-3 mb-4 rounded border-success border-opacity-50 border bg-success bg-opacity-10">
                    <h6 class="text-success fw-bold border-bottom pb-2 mb-3"><i class="bi bi-house-door-fill"></i> <?php echo e('form.stay_section'); ?></h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small"><?php echo e('form.checkin_date'); ?></label>
                            <input type="text" name="check_in_date" id="check_in_date" class="form-control bg-white" required readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small"><?php echo e('form.place_type'); ?></label>
                            <select name="location_type" class="form-select">
                                <option value="Inside"><?php echo e('form.place_inside'); ?></option>
                                <option value="Outside"><?php echo e('form.place_outside'); ?></option>
                            </select>
                        </div>
                        <div class="col-12 border-top pt-3">
                            <div class="form-check mb-2">
                                <input class="form-check-input border-danger" type="checkbox" id="confirmData" required>
                                <label class="form-check-label fw-bold text-danger" for="confirmData"><?php echo e('form.confirm_data'); ?></label>
                            </div>
                            <?php if ($pdpa_on): ?>
                            <div class="form-check">
                                <input class="form-check-input border-primary" type="checkbox" id="pdpaConsent" name="pdpa_consent" value="1" required disabled>
                                <label class="form-check-label small fw-bold text-dark" for="pdpaConsent">
                                    <?php echo e('form.pdpa_consent'); ?>
                                </label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <?php if ($pdpa_on): ?>
                    <div class="col-md-5">
                        <button type="button" class="btn btn-info w-100 btn-lg shadow-sm fw-bold" onclick="sendToTablet()">
                            <i class="bi bi-tablet-landscape-fill"></i> <?php echo e('form.send_tablet'); ?>
                        </button>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-<?= $pdpa_on ? 5 : 10 ?>">
                        <button type="submit" class="btn btn-primary w-100 btn-lg shadow fw-bold">
                            <i class="bi bi-cloud-check-fill"></i> <?php echo e('form.save_checkin'); ?>
                        </button>
                    </div>
                    <div class="col-md-2">
                        <a href="index.php?page=guest_list" class="btn btn-outline-secondary w-100 btn-lg"><?php echo e('btn.cancel'); ?></a>
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

// ข้อความทั้งหมดในสคริปต์นี้มาจากไฟล์ภาษา (ห้ามฝังคำไทยใน JS)
const L = <?php echo json_encode([
    'opening_program'    => t('form.js_opening_program'),
    'reading_card'       => t('form.js_reading_card'),
    'success'            => t('form.js_success'),
    'card_read_ok'       => t('form.js_card_read_ok'),
    'error'              => t('form.js_error'),
    'bridge_unreachable' => t('form.js_bridge_unreachable'),
    'card_read_fail'     => t('form.js_card_read_fail'),
    'bridge_fail'        => t('form.js_bridge_fail'),
    'sending'            => t('form.js_sending'),
    'server_error'       => t('form.js_server_error'),
    'bad_response'       => t('form.js_bad_response'),
    'sent_title'         => t('form.js_sent_title'),
    'sent_text'          => t('form.js_sent_text'),
    'confirmed_title'    => t('form.js_confirmed_title'),
    'confirmed_text'     => t('form.js_confirmed_text'),
    'failed'             => t('form.js_failed'),
    'moo'                => t('form.js_moo'),
    'cancel'             => t('btn.cancel'),
], JSON_UNESCAPED_UNICODE); ?>;

// 1. Initializations
$(document).ready(function() {
    // ฟังก์ชันกลางสำหรับหา Address ID เพื่อลดความซ้ำซ้อนของโค้ด
    // opts: ระบุช่องปลายทางได้ เพื่อใช้ซ้ำกับกล่อง "ภูมิลำเนา" (ค่าเริ่มต้น = ที่อยู่ตามทะเบียนบ้าน)
    function lookupInternalAddress(t, a, p, opts) {
        if (!t || !a || !p) return;

        const idField  = (opts && opts.idField)  || '#address_id';
        const zipField = (opts && opts.zipField) || '#addr_zipcode';

        // ล้างคำนำหน้าขยะออกก่อนส่งไป API เพื่อให้ Match ง่ายขึ้น
        const cleanT = stripAddrPrefix(t);
        const cleanA = stripAddrPrefix(a);
        const cleanP = stripAddrPrefix(p);

        const url = `api/address_id.php?district=${encodeURIComponent(cleanT)}&amphoe=${encodeURIComponent(cleanA)}&province=${encodeURIComponent(cleanP)}`;

        fetch(url)
            .then(res => res.json())
            .then(res => {
                if (res.status === 'success') {
                    $(idField).val(res.address_id);
                    // เติมรหัสไปรษณีย์ให้เฉพาะตอนที่ยังว่าง (บัตรประชาชนไม่มีรหัสไปรษณีย์ / ไม่ทับค่าที่ผู้ใช้กรอกเอง)
                    if (res.zipcode && !$(zipField).val()) $(zipField).val(res.zipcode);
                    // .val() ไม่ยิง event → ถ้าสวิตช์ "ภูมิลำเนาเหมือนทะเบียนบ้าน" เปิดอยู่ ต้องสั่ง sync เอง
                    if (idField === '#address_id' && window._syncHomeAddr) window._syncHomeAddr();
                    console.log("Found Address ID:", res.address_id, idField);
                } else {
                    console.warn("Address ID not found in DB", idField);
                }
            })
            .catch(err => console.error("Fetch Error:", err));
    }

    // ภูมิลำเนา: ช่องปลายทางคนละชุดกับที่อยู่ตามทะเบียนบ้าน
    const HOME_TARGET = { idField: '#home_address_id', zipField: '#home_addr_zipcode' };
    function lookupHomeAddress() {
        lookupInternalAddress(
            $('#home_addr_tambon').val(),
            $('#home_addr_amphoe').val(),
            $('#home_addr_province').val(),
            HOME_TARGET
        );
    }

    // 1. Address Autocomplete (jquery.Thailand.js)
    if ($.Thailand) {
        $.Thailand({
            database: './assets/jquery.Thailand.js/database/db.json',
            $district: $('#addr_tambon'),
            $amphoe: $('#addr_amphoe'),
            $province: $('#addr_province'),
            $zipcode: $('#addr_zipcode'),
            // ชื่อ callback ของไลบรารีเวอร์ชันนี้คือ onDataFill (ไม่ใช่ onSelect — ของเดิมจึงไม่เคยถูกเรียก)
            onDataFill: function(data) {
                // เมื่อเลือกจากลิสต์ ให้เรียกฟังก์ชันหา ID ทันที
                lookupInternalAddress(data.district, data.amphoe, data.province);
            }
        });

        // กล่อง 3 "ภูมิลำเนา" — autocomplete ชุดของตัวเอง (ใช้ฐานข้อมูลไฟล์เดียวกัน)
        $.Thailand({
            database: './assets/jquery.Thailand.js/database/db.json',
            $district: $('#home_addr_tambon'),
            $amphoe: $('#home_addr_amphoe'),
            $province: $('#home_addr_province'),
            $zipcode: $('#home_addr_zipcode'),
            onDataFill: function(data) {
                lookupInternalAddress(data.district, data.amphoe, data.province, HOME_TARGET);
            }
        });
    }

    // 2. ดักจับตอน "ออกจากช่องกรอก" (Blur) กรณีพิมพ์เองแบบแมนนวล
    $('#addr_tambon, #addr_amphoe, #addr_province, #addr_zipcode').on('blur', function() {
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
    $('#addr_tambon, #addr_amphoe, #addr_province, #addr_zipcode').on('input', function(e) {
        if (e.isTrusted) { // ตรวจสอบว่าเป็นการพิมพ์จริงจากมนุษย์ ไม่ใช่สคริปต์เติมให้
            $('#address_id').val('');
        }
    });

    // 4. กล่อง 3 "ภูมิลำเนา" — blur/input ชุดของตัวเอง (ทำงานเฉพาะตอนสวิตช์ปิด = กรอกเอง)
    $('#home_addr_tambon, #home_addr_amphoe, #home_addr_province, #home_addr_zipcode').on('blur', function() {
        setTimeout(lookupHomeAddress, 200);
    });
    $('#home_addr_tambon, #home_addr_amphoe, #home_addr_province, #home_addr_zipcode').on('input', function(e) {
        if (e.isTrusted) $('#home_address_id').val('');
    });

    // 5. สวิตช์ "ที่อยู่เดียวกับที่อยู่ตามทะเบียนบ้าน"
    //    เปิด = ดึงที่อยู่จากกล่อง 2 มาแสดง + ล็อกช่อง (ฝั่งเซิร์ฟเวอร์เก็บแค่ธง same=1, home_* เป็น NULL)
    const homeSwitch = document.getElementById('home_same_as_reg');
    if (homeSwitch) {
        const syncHome = function () {
            const on = homeSwitch.checked;
            // ต้องกรอง [name] — typeahead โคลนช่องเป็น .tt-hint (คลาสเดิมแต่ไม่มี id/name) ไว้โชว์ตัวหนังสือจาง
            document.querySelectorAll('#homeAddrBlock input.home-addr[name]').forEach(function (inp) {
                const src = document.getElementById(inp.dataset.part);
                if (on) inp.value = src ? src.value : '';
                inp.readOnly = on;
                inp.classList.toggle('bg-body-secondary', on);
            });
            if (on) $('#home_address_id').val($('#address_id').val());
        };
        homeSwitch.addEventListener('change', syncHome);

        // แก้ที่อยู่ตามทะเบียนบ้านระหว่างที่สวิตช์เปิดอยู่ → ภูมิลำเนาตามไปด้วย
        $('#addr_number, #addr_tambon, #addr_amphoe, #addr_province, #addr_zipcode').on('input change', function () {
            if (homeSwitch.checked) syncHome();
        });
        // อ่านบัตร/แก้ไขข้อมูลเดิม: เติมค่าเริ่มต้นให้ตรงสถานะสวิตช์ตั้งแต่โหลดหน้า
        window._syncHomeAddr = syncHome;
        syncHome();
    }

    // วันที่ทั้งหมดแสดงเป็น พ.ศ. (เลขไทย) แต่ค่าที่ submit/เก็บ DB ยังเป็น ค.ศ. ISO (ดู initThaiDate)
    document.querySelectorAll('.thai-date').forEach(function (el) {
        const opts = {};
        // วันเกิด: เปลี่ยนแล้วให้ auto-check กลุ่มเปราะบางตามอายุ (เด็ก/ผู้สูงอายุ)
        if (el.id === 'birthdate') opts.onChange = function (_sel, str) { autoCheckAge(str); };
        initThaiDate(el, opts);
    });

    // เช็คอิน: วันที่+เวลา แสดงเป็น พ.ศ. — ค่าเริ่มต้น = ตอนนี้
    initThaiDate("#check_in_date", {
        enableTime: true, time_24hr: true, dateFormat: "Y-m-d H:i",
        defaultDate: new Date(), altFormat: "THAITIME"
    });
});

// 2. Smart Card Functions
async function readSmartCard() {
    const btn = document.querySelector('button[onclick="readSmartCard()"]');
    const originalContent = btn.innerHTML;
   
    // 1. เริ่มกระบวนการเรียกโปรแกรม Smart Card
    window.location.href = "smartcard://";
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + L.opening_program;
    btn.disabled = true;

    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + L.reading_card;

    try {
        // ลองเชื่อมต่อ local service ซ้ำ ๆ กัน cold start ของ Reg.exe (แทนการรอ 3 วิแบบตายตัว)
        const data = await fetchCardWithRetry(8, 600);

        if(data.error) throw new Error(data.error);

        // 2. จัดการ Clean ข้อมูลที่อยู่ (ย้ายมาไว้ตรงนี้เพื่อให้มีตัวแปร data ใช้งาน)
        const cleanT = stripAddrPrefix(data.Tambon);
        const cleanA = stripAddrPrefix(data.Amphoe);
        const cleanP = stripAddrPrefix(data.Province);

        // 3. Map ข้อมูลลงฟิลด์ต่างๆ (กันค่า undefined จากบัตรที่อ่านได้ไม่ครบ)
        setVal('id_card', data.CitizenID || '');
        setVal('firstname', data.Firstname || '');
        setVal('lastname', data.Lastname || '');
        setVal('prefix', data.Prefix || '');
        // บัตร/Reg.exe คืนวันเกิดเป็น MM/DD/YYYY → แปลงเป็น ค.ศ. ISO แล้วเซ็ตผ่าน flatpickr (แสดงผลเป็น พ.ศ.)
        const birthISO = normalizeBirthDate(data.BirthDate);
        if (window._fpDates && window._fpDates.birthdate) window._fpDates.birthdate.setDate(birthISO, true);
        else setVal('birthdate', birthISO);

        // ตรวจสอบเพศ
        let rawGender = data.Gender ? data.Gender.toString().trim() : "";
        let detectedGender = (rawGender == "1" || rawGender.toLowerCase() === "male" || rawGender === "ชาย") ? "Male" :
                             (rawGender == "2" || rawGender.toLowerCase() === "female" || rawGender === "หญิง") ? "Female" : "";
        setVal('gender', detectedGender);
       
        // ที่อยู่ (Text fields)
        setVal('addr_number', (data.HouseNo || '') + (data.Moo ? ` ${L.moo} ${data.Moo.replace(/\D/g,'')}` : ''));
        setVal('addr_tambon', cleanT);
        setVal('addr_amphoe', cleanA);
        setVal('addr_province', cleanP);
        // บัตรไม่มีรหัสไปรษณีย์ — ล้างค่าเก่าไว้ก่อน แล้วรอ address_id.php เติมให้ตามที่อยู่ใหม่
        setVal('addr_zipcode', '');

        // 4. 🎯 จุดชี้ขาด: เรียกหา Address ID จาก Database
        // ใช้ "ชื่อที่อยู่" ค้นหาแทน "เลขบัตร" เพื่อความแม่นยำในระบบฐานข้อมูล
        const addrUrl = `api/address_id.php?district=${encodeURIComponent(cleanT)}&amphoe=${encodeURIComponent(cleanA)}&province=${encodeURIComponent(cleanP)}`;
       
        fetch(addrUrl)
            .then(res => res.json())
            .then(res => {
                if(res.status === 'success') {
                    // ใส่ค่าลงใน Hidden Input โดยตรง
                    document.getElementById('address_id').value = res.address_id;
                    if (res.zipcode) document.getElementById('addr_zipcode').value = res.zipcode;
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

        autoCheckAge(birthISO);
        Swal.fire(L.success, L.card_read_ok, 'success');

    } catch (err) {
        console.error(err);
        const html = (err && err.name === 'BridgeUnreachable')
            ? L.bridge_unreachable
            : (L.card_read_fail + (err.message || err));
        Swal.fire({ icon: 'error', title: L.error, html: html });
    } finally {
        btn.innerHTML = originalContent;
        btn.disabled = false;
    }
}

// อ่านจาก local bridge — retry ตอน (ก) ต่อไม่ติด (Reg.exe ยังไม่บูต) และ (ข) Busy (คำสั่งก่อนยังทำงาน)
// สำคัญ: timeout ต้องยาวพอให้อ่านบัตรจริงเสร็จ ไม่งั้นจะ abort กลางคันแล้วยิงซ้ำทับ → ทำให้ bridge Busy เอง
async function fetchCardWithRetry(attempts = 12, delayMs = 800) {
    let lastBusy = null;
    for (let i = 0; i < attempts; i++) {
        let res;
        try {
            const ctrl = new AbortController();
            const to = setTimeout(() => ctrl.abort(), 20000); // ให้เวลาอ่านบัตร/ดึงรูปพอ (ไม่ตัดกลางคัน)
            res = await fetch('http://localhost:8888/read/', { signal: ctrl.signal });
            clearTimeout(to);
        } catch (e) {
            await new Promise(r => setTimeout(r, delayMs)); // ต่อไม่ติด/timeout → bridge ยังไม่พร้อม ลองใหม่
            continue;
        }
        const data = await res.json();
        // Busy = คำสั่งอ่านก่อนหน้ายังไม่เสร็จ → ชั่วคราว รอให้เคลียร์แล้วลองใหม่ (อย่าเด้ง error ทันที)
        if (data && data.error && /busy|กำลังอ่าน/i.test(data.error)) {
            lastBusy = data;
            await new Promise(r => setTimeout(r, 1200));
            continue;
        }
        return data; // สำเร็จ หรือ error จริง (เช่น ไม่มีบัตร) → คืนให้ผู้เรียกจัดการ
    }
    if (lastBusy) return lastBusy; // ยัง Busy จนครบ → คืน Busy ไปแสดง
    const err = new Error(L.bridge_fail);
    err.name = 'BridgeUnreachable';
    throw err;
}

// 3. Helper Logic

// ล้างคำนำหน้าที่อยู่ (ต./อ./จ. ฯลฯ) — รวมที่เดียว ให้ตรงกับ PHP stripAddrPrefix()
function stripAddrPrefix(s) {
    return String(s || '').replace(/ตำบล|แขวง|อำเภอ|เขต|จังหวัด|ต\.|อ\.|จ\./g, '').trim();
}

// autocomplete คำนำหน้าชื่อ — พิมพ์ "น" เสนอ นาย/นางสาว/นาง (คงเป็น text input)
// แสดงเฉพาะตอน field โฟกัส → ไม่เด้งตอนอ่านบัตร (setVal จะ dispatch input แต่ field ไม่โฟกัส)
document.addEventListener('DOMContentLoaded', function () {
    const TITLES = ['นาย', 'นางสาว', 'นาง', 'เด็กชาย', 'เด็กหญิง', 'ว่าที่ ร.ต.', 'ดร.'];
    const inp = document.getElementById('prefix');
    const box = document.getElementById('prefixSuggest');
    if (!inp || !box) return;

    function render() {
        const v = inp.value.trim();
        const matches = (document.activeElement === inp && v)
            ? TITLES.filter(t => t.startsWith(v) && t !== v)
            : [];
        if (!matches.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
        box.innerHTML = matches.map(t =>
            '<button type="button" class="list-group-item list-group-item-action py-1">' + t + '</button>'
        ).join('');
        box.style.display = 'block';
    }

    inp.addEventListener('input', render);
    inp.addEventListener('focus', render);
    inp.addEventListener('blur', function () { setTimeout(function () { box.style.display = 'none'; }, 150); });
    box.addEventListener('mousedown', function (e) { // mousedown ก่อน blur จึงเลือกได้
        if (e.target.tagName === 'BUTTON') {
            inp.value = e.target.textContent;
            box.style.display = 'none';
        }
    });
});

function setVal(id, value) {
    const el = document.getElementById(id);
    if (el) { el.value = value; el.dispatchEvent(new Event('input')); }
}

// แปลงวันเกิดจากบัตรให้เป็น YYYY-MM-DD (input type=date ต้องการรูปแบบนี้)
// รองรับ MM/DD/YYYY (จาก Reg.exe), YYYY-MM-DD และเผื่อกรณีบางเวอร์ชันคืนเป็น พ.ศ.
function normalizeBirthDate(raw) {
    if (!raw) return '';
    raw = String(raw).trim();
    let y, m, d;
    if (/^\d{4}-\d{1,2}-\d{1,2}/.test(raw)) {          // ISO อยู่แล้ว
        [y, m, d] = raw.split(/[-T ]/);
    } else if (/^\d{1,2}\/\d{1,2}\/\d{4}/.test(raw)) {  // MM/DD/YYYY จากบัตร
        [m, d, y] = raw.split('/');
    } else {
        return raw; // รูปแบบอื่น ปล่อยผ่าน (ให้ผู้ใช้แก้เอง)
    }
    y = parseInt(y, 10); m = parseInt(m, 10); d = parseInt(d, 10);
    if (y > 2400) y -= 543;                              // เผื่อได้ปีเป็น พ.ศ.
    if (m > 12 && d <= 12) { const t = m; m = d; d = t; } // เผื่อสลับ DD/MM มา
    if (!y || !m || !d || m > 12 || d > 31) return '';   // ค่าไม่สมเหตุผล → เว้นว่างให้กรอกเอง
    return `${y}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
}

// เลขอารบิก → เลขไทย (ใช้เฉพาะการแสดงผลบนหน้านี้)
function toThaiDigits(s) {
    const d = "๐๑๒๓๔๕๖๗๘๙";
    return String(s).replace(/[0-9]/g, function (m) { return d[+m]; });
}

// flatpickr แสดงผลเป็น พ.ศ. (เลขไทย) แต่ค่าใน input (ที่ submit) ยังเป็น ค.ศ. ISO อารบิกเสมอ
// วิธีการ: override formatDate — altFormat เป็น sentinel ("THAI"/"THAITIME") เพื่อแยกจาก dateFormat ที่เก็บค่า
const THAI_MONTHS_ABBR = ["ม.ค.","ก.พ.","มี.ค.","เม.ย.","พ.ค.","มิ.ย.","ก.ค.","ส.ค.","ก.ย.","ต.ค.","พ.ย.","ธ.ค."];
window._fpDates = {};
function initThaiDate(target, opts) {
    const cfg = Object.assign({
        dateFormat: "Y-m-d",   // ค่าที่เก็บ = ค.ศ. ISO
        altInput: true,
        altFormat: "THAI",     // ค่าที่แสดง = พ.ศ. (sentinel)
        locale: "th",
        onReady: function (_s, _d, fp) { installBuddhistYear(fp); }, // หัวปฏิทินโชว์ปี พ.ศ.
        formatDate: function (date, format) {
            const p = function (n) { return String(n).padStart(2, '0'); };
            const thaiD = toThaiDigits(date.getDate() + " " + THAI_MONTHS_ABBR[date.getMonth()] + " " + (date.getFullYear() + 543));
            if (format === "THAI")     return thaiD;
            if (format === "THAITIME") return thaiD + " (" + toThaiDigits(p(date.getHours()) + ":" + p(date.getMinutes())) + " น.)";
            // ค่าที่เก็บ (ค.ศ. อารบิก) — รองรับทั้งวันที่ล้วนและวันที่+เวลา
            let s = date.getFullYear() + "-" + p(date.getMonth() + 1) + "-" + p(date.getDate());
            if (String(format).indexOf("H:i") !== -1) s += " " + p(date.getHours()) + ":" + p(date.getMinutes());
            return s;
        }
    }, opts || {});
    const el = (typeof target === 'string') ? document.querySelector(target) : target;
    if (!el) return null;
    const fp = flatpickr(el, cfg);
    if (el.id) window._fpDates[el.id] = fp;
    return fp;
}

// หัวปฏิทิน flatpickr แสดง "ปี" เป็น พ.ศ. โดยที่สถานะภายใน flatpickr ยังเป็น ค.ศ. เสมอ
// วิธีการ: ครอบ property .value ของช่องปี — flatpickr อ่านได้ ค.ศ. (เขียน/พิมพ์/เลื่อน/สกอลล์ทำงานตามปกติ)
// แต่ตัวเลขที่แสดงบนจอถูกบวก 543 (getter ลบกลับ 543 ให้ flatpickr เห็นเป็น ค.ศ.)
function installBuddhistYear(fp) {
    const desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    (fp.yearElements || []).forEach(function (y) {
        if (!y || y._beWrapped) return;
        y._beWrapped = true;
        Object.defineProperty(y, 'value', {
            configurable: true,
            get: function () {
                const n = parseInt(desc.get.call(this), 10);      // ค่าบนจอ = พ.ศ.
                return isNaN(n) ? desc.get.call(this) : String(n - 543); // คืน ค.ศ. ให้ flatpickr
            },
            set: function (v) {
                const n = parseInt(v, 10);                        // flatpickr ส่ง ค.ศ. มา
                desc.set.call(this, isNaN(n) ? v : String(n + 543)); // เขียนลงจอเป็น พ.ศ.
            }
        });
        // ค่าที่ flatpickr เซ็ตไว้ตอนสร้าง (ยังเป็น ค.ศ. ดิบ ก่อนมี wrapper) → แปลงเป็น พ.ศ.
        desc.set.call(y, String(fp.currentYear + 543));
    });
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
        addr_zipcode: $('#addr_zipcode').val(),
        photo: $('#hidden_photo_data').val()
    };

    Swal.fire({ title: L.sending, allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        // CSRF token ของ session แอดมิน (P2/S2) — ส่งผ่าน header ไป sync_* ที่ยืนยันด้วย session
        const CSRF_TOKEN = "<?= csrf_token() ?>";

        // 1. Reset สถานะเดิม
        await fetch('api/sync_reset.php', {
            headers: { 'X-CSRF-Token': CSRF_TOKEN }
        });

        // 2. ส่งข้อมูลไปที่ Tablet
        const res = await fetch('api/sync_send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify(formData)
        });

        // ตรวจสอบว่า Response เป็น JSON หรือไม่
        const responseText = await res.text();
        try {
            const data = JSON.parse(responseText);
            if (!res.ok) throw new Error(data.message || L.server_error);
        } catch (e) {
            // ถ้าพังตรงนี้ แสดงว่าค่าที่ส่งกลับมาเป็น HTML (Error PHP)
            console.error("Server Error Response:", responseText);
            throw new Error(L.bad_response);
        }

        // 3. เริ่ม Loop ตรวจสอบสถานะการยืนยัน
        let timer;
        Swal.fire({
            title: L.sent_title,
            text: L.sent_text,
            icon: 'info',
            allowOutsideClick: false,
            showCancelButton: true,
            cancelButtonText: L.cancel,
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
                            Swal.fire(L.confirmed_title, L.confirmed_text, 'success');
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
        Swal.fire(L.failed, e.message, 'error');
    }
}
</script>