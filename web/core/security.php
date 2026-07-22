<?php
// Security Bridge

if (!class_exists('Dotenv\Dotenv')) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
}

// Load ENV variables
if (empty($_ENV['ENCRYPTION_KEY'])) {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();
    } catch (Exception $e) { }
}

if (!defined('SECRET_KEY')) {
    define('SECRET_KEY', $_ENV['ENCRYPTION_KEY'] ?? '');
}
// เมธอดเดิม — ใช้ "ถอด" ข้อมูลเก่าที่เข้ารหัสด้วย CBC (ยังอ่านได้จนกว่าจะ migrate ครบ)
define('CIPHER_METHOD', $_ENV['ENCRYPTION_METHOD'] ?? 'AES-256-CBC');
// P6: เมธอดใหม่ — GCM มี auth tag กันการแก้ไข ciphertext (integrity)
define('GCM_METHOD', 'aes-256-gcm');
define('GCM_PREFIX', 'g1:'); // มาร์คว่า blob เป็น GCM (ของเก่า CBC ไม่มี prefix)

/** คีย์ 32 ไบต์สำหรับ GCM (derive จาก SECRET_KEY ให้ความยาวคงที่เสมอ ไม่ว่า key ต้นทางยาวเท่าไร) */
function _gcmKey(): string {
    return hash('sha256', SECRET_KEY, true);
}

function encryptData($data) {
    if (empty($data)) return null;
    // P1/S6: ห้าม fallback เป็น plaintext เด็ดขาด — key หาย = config พัง ต้องหยุด
    if (empty(SECRET_KEY)) {
        error_log("Encryption Error: SECRET_KEY is missing.");
        throw new RuntimeException('SECRET_KEY (ENCRYPTION_KEY) is missing — ปฏิเสธการเข้ารหัสเพื่อไม่ให้เก็บข้อมูลอ่อนไหวเป็น plaintext');
    }
    // P6: AES-256-GCM → เก็บ iv(12B) + tag(16B) + ciphertext แล้วใส่ prefix
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($data, GCM_METHOD, _gcmKey(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('Encryption failed');
    }
    return GCM_PREFIX . base64_encode($iv . $tag . $ct);
}

function decryptData($data) {
    if (empty($data)) return '';
    if (empty(SECRET_KEY)) {
        throw new RuntimeException('SECRET_KEY (ENCRYPTION_KEY) is missing — ถอดรหัสไม่ได้');
    }

    // รูปแบบใหม่ (GCM) — มี prefix
    if (strncmp($data, GCM_PREFIX, strlen(GCM_PREFIX)) === 0) {
        $bin = base64_decode(substr($data, strlen(GCM_PREFIX)), true);
        if ($bin === false || strlen($bin) < 28) return 'Error Decrypting';
        $iv  = substr($bin, 0, 12);
        $tag = substr($bin, 12, 16);
        $ct  = substr($bin, 28);
        $pt  = openssl_decrypt($ct, GCM_METHOD, _gcmKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return $pt !== false ? $pt : 'Error Decrypting';
    }

    // รูปแบบเดิม (CBC ไม่มี prefix) — ยังถอดได้ระหว่างช่วงเปลี่ยนผ่าน
    $cdata = base64_decode($data);
    $iv_size = openssl_cipher_iv_length(CIPHER_METHOD);
    if (strlen($cdata) <= $iv_size) return 'Invalid Data';
    $iv = substr($cdata, 0, $iv_size);
    $text = substr($cdata, $iv_size);
    $decrypted = openssl_decrypt($text, CIPHER_METHOD, SECRET_KEY, 0, $iv);
    return $decrypted !== false ? $decrypted : 'Error Decrypting';
}

/**
 * P5: HMAC-SHA256 แบบผูก "โดเมนการใช้งาน" — กัน hash ข้ามฟิลด์ชนกัน
 * (เทียบ HashHelper->hmac(data, domain) ของ papyrus)
 */
function hmacData($data, string $domain) {
    if (empty($data)) return null;
    if (empty(SECRET_KEY)) {
        throw new RuntimeException('SECRET_KEY (ENCRYPTION_KEY) is missing — ปฏิเสธการ hash');
    }
    return hash_hmac('sha256', $domain . ':' . $data, SECRET_KEY);
}

/** hash เลขบัตร (คงชื่อเดิมให้ call site เรียกต่อได้) — P5: ผูก domain 'id_card' */
function hashID($data) {
    if (empty($data)) return null;
    return hmacData($data, 'id_card');
}
