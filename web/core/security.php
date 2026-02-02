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
define('CIPHER_METHOD', $_ENV['ENCRYPTION_METHOD'] ?? 'AES-256-CBC');

function encryptData($data) {
    if (empty($data)) return null;
    if (empty(SECRET_KEY)) {
        error_log("Encryption Error: SECRET_KEY is missing.");
        return $data;
    }
    if (empty($data)) return null;
    $ivLength = openssl_cipher_iv_length(CIPHER_METHOD);
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($data, CIPHER_METHOD, SECRET_KEY, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decryptData($data) {
    if (empty($data)) return '';
    $cdata = base64_decode($data);
    $iv_size = openssl_cipher_iv_length(CIPHER_METHOD);
    if (strlen($cdata) <= $iv_size) return 'Invalid Data';
    $iv = substr($cdata, 0, $iv_size);
    $text = substr($cdata, $iv_size);
    $decrypted = openssl_decrypt($text, CIPHER_METHOD, SECRET_KEY, 0, $iv);
    return $decrypted !== false ? $decrypted : 'Error Decrypting';
}

function hashID($data) {
    if (empty($data)) return null;
    return hash_hmac('sha256', $data, SECRET_KEY);
}
