<?php
/**
 * API Bootstrap — Sindesa API
 * 
 * Include file ini di AWAL setiap endpoint API.
 * Menangani: output buffering, error handling, CORS headers, preflight OPTIONS.
 */

// 1. Output Buffering — tangkap output sampah (warning/notice) agar tidak merusak JSON
if (!ob_get_level()) {
    ob_start();
}

// 2. Error Handling — JANGAN tampilkan error ke HTML output, log ke file debug.txt
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.txt');

// 3. Register Shutdown Function — jika ada Fatal Error atau die(), PASTIKAN output tetap terkirim sebagai JSON (bukan respon kosong)
register_shutdown_function(function() {
    $error = error_get_last();
    // Tangkap fatal error (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR)
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            "success" => false,
            "message" => "Server Error: " . $error['message'] . " in " . basename($error['file']) . ":" . $error['line']
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Flush buffer normal jika tidak ada fatal error
        if (ob_get_length()) ob_end_flush();
    }
});

// 4. Content-Type JSON
header('Content-Type: application/json; charset=utf-8');

// 5. CORS Headers — agar Android app bisa akses API dari domain manapun
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// 6. Handle OPTIONS Preflight — langsung respond 200 tanpa proses lebih lanjut
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    if (ob_get_length()) ob_end_clean();
    exit;
}

/**
 * Helper: Kirim JSON response yang bersih
 */
function api_response($data, $httpCode = 200) {
    http_response_code($httpCode);
    if (ob_get_length()) ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Helper: Kirim error response (default HTTP 200 agar Retrofit Android parse JSON)
 */
function api_error($message, $httpCode = 200) {
    api_response(["success" => false, "message" => $message], $httpCode);
}

/**
 * URL Website Laravel Sindesa (tempat file storage foto profil disajikan)
 * Sesuaikan dengan domain website Laravel Anda.
 * 
 * PENTING: URL ini HARUS diakhiri dengan / (slash)
 */
if (!defined('WEBSITE_URL')) {
    // Auto-detect: coba ambil dari env atau gunakan default
    // Prioritas: environment variable > auto-detect dari API URL > fallback
    $apiHost = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($apiHost, 'api.') === 0) {
        // Jika API di api.domain.com, website kemungkinan di domain.com atau app.domain.com
        $webHost = substr($apiHost, 4); // Hapus 'api.' prefix
        define('WEBSITE_URL', 'https://' . $webHost . '/');
    } else {
        // Fallback: gunakan domain yang sama dengan API
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        define('WEBSITE_URL', $scheme . '://' . $apiHost . '/');
    }
}

/**
 * Helper: Konversi path foto_profil relatif menjadi URL lengkap yang bisa diakses
 * 
 * @param string|null $fotoPath Path relatif dari database (misal: "profil/PROFIL_123.jpg")
 * @return string URL lengkap atau string kosong
 */
function get_foto_profil_url($fotoPath) {
    if (empty($fotoPath)) return '';
    
    // Jika sudah berupa URL lengkap, kembalikan apa adanya
    if (strpos($fotoPath, 'http://') === 0 || strpos($fotoPath, 'https://') === 0) {
        return $fotoPath;
    }
    
    // Bersihkan prefix yang mungkin tersimpan di DB
    $cleanPath = ltrim($fotoPath, '/');
    if (strpos($cleanPath, 'storage/app/public/') === 0) {
        $cleanPath = substr($cleanPath, strlen('storage/app/public/'));
    } elseif (strpos($cleanPath, 'public/storage/') === 0) {
        $cleanPath = substr($cleanPath, strlen('public/storage/'));
    } elseif (strpos($cleanPath, 'storage/') === 0) {
        $cleanPath = substr($cleanPath, strlen('storage/'));
    }
    
    return WEBSITE_URL . 'storage/' . $cleanPath;
}
