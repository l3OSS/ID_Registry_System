<?php
/**
 * RBAC — ระบบสิทธิ์แบบ "permission ตามชื่อ" (แทนการเทียบ role_level เป็นตัวเลข)
 * แนวคิดเดียวกับ Symfony/papyrus: โค้ดถามว่า "ทำ X ได้ไหม" (userCan) ไม่ใช่ "level <= n"
 *
 * role_level ใน DB (ตาราง roles): 1=EngiNear(สูงสุด), 2=Admin, 3=Regis(งานทะเบียน)
 * ผูก role → ชุด permission ไว้ที่เดียว — เพิ่ม/ปรับสิทธิ์แก้ที่ ROLE_PERMISSIONS ที่เดียว
 */

require_once __DIR__ . '/auth.php'; // ต้องมี checkLogin()

// role_level → ชุด permission ('*' = ทุกสิทธิ์)
const ROLE_PERMISSIONS = [
    1 => ['*'], // EngiNear (engineer) — สิทธิ์สูงสุด
    2 => ['users.view', 'users.edit', 'export.excel', 'logs.view',
          'guests.view', 'guests.register', 'guests.delete', 'profile.edit'], // Admin
    3 => ['guests.view', 'logs.view', 'guests.register', 'profile.edit'],       // Regis (งานทะเบียน)
];

/**
 * permission ทั้งหมดที่ระบบรู้จัก — ประกาศไว้ให้ครบเพื่อให้อ่านโค้ดแล้วรู้ว่ามีสิทธิ์อะไรบ้าง
 * สิทธิ์ที่ "ไม่ปรากฏ" ใน ROLE_PERMISSIONS ของ role ใดเลย = สงวนไว้ให้ EngiNear ผ่าน '*' เท่านั้น
 * (logs.delete / settings.manage / users.create — EngiNear เท่านั้น ตามนโยบายเจ้าของระบบ)
 */
const ALL_PERMISSIONS = [
    'profile.edit'    => 'แก้ไขโปรไฟล์ของตนเอง',
    'guests.view'     => 'ดูรายชื่อผู้เข้าพัก',
    'guests.register' => 'ลงทะเบียนผู้เข้าพัก',
    'guests.delete'   => 'ลบรายชื่อผู้เข้าพัก',
    'export.excel'    => 'ส่งออกข้อมูลเป็น Excel',
    'logs.view'       => 'ดูประวัติการใช้งานระบบ',
    'logs.delete'     => 'ล้างประวัติการใช้งาน (EngiNear เท่านั้น)',
    'users.view'      => 'ดูรายชื่อทีมงาน',
    'users.create'    => 'เพิ่มบัญชีทีมงาน (EngiNear เท่านั้น)',
    'users.edit'      => 'แก้ไขบัญชีทีมงาน',
    'settings.manage' => 'ตั้งค่าระบบ (EngiNear เท่านั้น)',
];

const ROLE_NAMES = [1 => 'EngiNear', 2 => 'Admin', 3 => 'Regis'];

/**
 * โควตาบัญชีต่อบทบาท — จำนวนสูงสุดที่มีได้ในระบบ (0 = ไม่จำกัด)
 * นโยบาย: EngiNear 1 คน (สร้างตอนติดตั้ง) · Admin 5 คน · Regis ไม่จำกัด
 * บังคับฝั่งเซิร์ฟเวอร์ทุกจุด — การปิด <option> ในฟอร์มเป็นแค่ UX
 */
const ROLE_QUOTAS = [1 => 1, 2 => 5, 3 => 0];

/**
 * บทบาทที่ "เพิ่มบัญชีใหม่" ได้จากหน้าจัดการทีมงาน
 * EngiNear ไม่อยู่ในรายการ — บัญชีเดียวของระบบถูกสร้างตอนติดตั้งเท่านั้น
 */
const CREATABLE_ROLES = [2, 3];

/** เพิ่มบัญชีใหม่ในบทบาทนี้ได้ไหม (ไม่เกี่ยวกับโควตา) */
function isCreatableRole(int $level): bool {
    return in_array($level, CREATABLE_ROLES, true);
}

/** โควตาของบทบาทนี้ (0 = ไม่จำกัด) */
function roleQuota(int $level): int {
    return ROLE_QUOTAS[$level] ?? 0;
}

/** นับบัญชีในบทบาทนี้ — $excludeUserId ใช้ตอนย้ายบทบาทของคนที่มีบัญชีอยู่แล้ว (ไม่นับตัวเขาเอง) */
function countUsersInRole(PDO $pdo, int $level, int $excludeUserId = 0): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_level = ? AND id <> ?");
    $stmt->execute([$level, $excludeUserId]);
    return (int)$stmt->fetchColumn();
}

/** ที่ว่างที่เหลือของบทบาทนี้ (null = ไม่จำกัด) */
function roleSeatsLeft(PDO $pdo, int $level, int $excludeUserId = 0): ?int {
    $quota = roleQuota($level);
    if ($quota === 0) return null;
    return max(0, $quota - countUsersInRole($pdo, $level, $excludeUserId));
}

/** โควตาเต็มหรือยัง — เรียกก่อนเพิ่มบัญชีใหม่ หรือก่อนย้ายบัญชีเดิมเข้าบทบาทนี้ */
function roleQuotaFull(PDO $pdo, int $level, int $excludeUserId = 0): bool {
    return roleSeatsLeft($pdo, $level, $excludeUserId) === 0;
}

/** ข้อความบอกโควตาสำหรับแสดงในหน้าเว็บ */
function roleQuotaLabel(PDO $pdo, int $level, int $excludeUserId = 0): string {
    $left = roleSeatsLeft($pdo, $level, $excludeUserId);
    if ($left === null) return 'ไม่จำกัด';
    return $left === 0 ? 'เต็มแล้ว' : "เหลืออีก $left ที่";
}

/** เป็น EngiNear คนสุดท้ายไหม — ลบหรือลดระดับคนนี้แล้วระบบจะไม่เหลือผู้ดูแลสูงสุด */
function isLastEngineer(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("SELECT role_level FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    if ((int)$stmt->fetchColumn() !== 1) return false;
    return countUsersInRole($pdo, 1, $userId) === 0;
}

function currentRoleLevel(): int {
    return (int)($_SESSION['role_level'] ?? 99);
}

/** ผู้ใช้ปัจจุบันมีสิทธิ์ $perm หรือไม่ */
function userCan(string $perm): bool {
    $perms = ROLE_PERMISSIONS[currentRoleLevel()] ?? [];
    return in_array('*', $perms, true) || in_array($perm, $perms, true);
}

/** เป็น engineer (สิทธิ์สูงสุด) ไหม — ใช้กับงานที่สงวนไว้จริง ๆ เช่นล้าง audit log */
function isEngineer(): bool {
    return currentRoleLevel() === 1;
}

/** Guard หน้า/แอ็กชัน: ต้องล็อกอิน + มีสิทธิ์ $perm ไม่งั้นเด้ง 403 */
function requirePermission(string $perm): void {
    checkLogin();
    if (!userCan($perm)) {
        redirect('index.php?page=403');
    }
}

/**
 * แสดงหน้าปฏิเสธการเข้าถึงแบบ inline (คงรูปแบบเดิม) แล้วหยุด
 * รวมบล็อก "ไม่มีสิทธิ์" ที่เคย copy-paste ใน setting/export/user_* ให้เป็นที่เดียว
 */
function denyAccess(string $message = 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้'): void {
    echo '
    <div class="container mt-5">
        <div class="alert alert-danger shadow-sm border-0 p-4 rounded-3 text-center">
            <i class="bi bi-exclamation-octagon-fill fs-1 d-block mb-3"></i>
            <h4 class="fw-bold">' . htmlspecialchars($message) . '</h4>
            <a href="index.php" class="btn btn-outline-danger rounded-pill px-4">
                <i class="bi bi-arrow-left"></i> กลับหน้าหลัก
            </a>
        </div>
    </div>';
    exit;
}
