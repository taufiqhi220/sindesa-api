<?php
/**
 * API Bootstrap — Sindesa API
 * 
 * Include file ini di AWAL setiap endpoint API.
 * Menangani: output buffering, error handling, CORS headers, preflight OPTIONS,
 *            dan fungsi autentikasi token (stateful DB-backed).
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
        // Gunakan CORS origin yang aman di shutdown juga
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, cors_allowed_origins())) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
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

/**
 * Daftar origin yang diizinkan untuk CORS.
 * Sesuaikan dengan domain resmi Sindesa.
 */
function cors_allowed_origins() {
    return [
        'https://sindesa-buttusawe.com',
        'https://www.sindesa-buttusawe.com',
        'https://api.sindesa-buttusawe.com',
        'https://sindesa.buttusawe.desa.id',
        'http://localhost',
        'http://localhost:8000',
        'http://127.0.0.1',
        'http://127.0.0.1:8000',
    ];
}

// 5. CORS Headers — whitelist domain resmi saja (bukan wildcard *)
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($requestOrigin, cors_allowed_origins())) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
} else {
    // Untuk request dari Android app (tidak ada Origin header), izinkan tanpa origin
    // Android native app mengirim request langsung tanpa Origin header
    if (empty($requestOrigin)) {
        // Tidak set CORS header — ini aman karena browser yang butuh CORS, bukan native app
    }
    // Jika ada Origin tapi tidak di whitelist, jangan set header → browser akan block
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

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
 * Google reCAPTCHA v3 Secret Key untuk Verifikasi Backend
 * CATATAN: Sebaiknya pindahkan ke db_config.server.php agar tidak terekspos di repo publik
 */
if (!defined('RECAPTCHA_V3_SECRET')) {
    define('RECAPTCHA_V3_SECRET', '6Lex9WItAAAAACH-V2qDWdo4ZbnS860sEPycshm3');
}

/**
 * Token TTL (Time-To-Live) dalam detik.
 * Default: 15 menit = 900 detik
 */
if (!defined('API_TOKEN_TTL_SECONDS')) {
    define('API_TOKEN_TTL_SECONDS', 900);
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
    
    // Bersihkan nama file
    $cleanPath = ltrim($fotoPath, '/');
    if (strpos($cleanPath, 'storage/app/public/') === 0) {
        $cleanPath = substr($cleanPath, strlen('storage/app/public/'));
    } elseif (strpos($cleanPath, 'public/storage/') === 0) {
        $cleanPath = substr($cleanPath, strlen('public/storage/'));
    } elseif (strpos($cleanPath, 'storage/') === 0) {
        $cleanPath = substr($cleanPath, strlen('storage/'));
    }
    
    $filename = basename($cleanPath);
    
    // Gunakan endpoint API langsung agar tidak terhalang oleh web session cookie redirect di domain utama
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $apiHost = $_SERVER['HTTP_HOST'] ?? 'api.sindesa-buttusawe.com';
    return $scheme . '://' . $apiHost . '/foto_profil.php?file=' . urlencode($filename);
}


// ============================================================
// AUTENTIKASI TOKEN — Stateful DB-backed Token
// ============================================================

/**
 * Generate token API baru untuk user.
 * Simpan ke tabel api_tokens dengan TTL yang ditentukan.
 * Hapus token lama milik user (single-session enforcement).
 *
 * @param mysqli $conn   Koneksi database
 * @param int    $userId ID user dari tabel users
 * @return string Token yang di-generate
 */
function generate_api_token($conn, $userId) {
    $userId = (int)$userId;
    
    // Hapus semua token lama milik user ini (single device session enforcement — Guideline §4)
    $stmt = $conn->prepare("DELETE FROM api_tokens WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
    
    // Hapus token kadaluarsa milik semua user (house-keeping)
    $conn->query("DELETE FROM api_tokens WHERE expires_at < NOW()");
    
    // Generate token baru (64 karakter hex = 32 bytes random)
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + API_TOKEN_TTL_SECONDS);
    
    $stmt = $conn->prepare("INSERT INTO api_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $token, $expiresAt);
    
    if (!$stmt->execute()) {
        error_log("generate_api_token: Gagal insert token untuk user_id=$userId: " . $stmt->error);
        $stmt->close();
        return '';
    }
    $stmt->close();
    
    return $token;
}

/**
 * Validasi token API dari header Authorization.
 * Return user_id jika valid, atau null jika invalid/kadaluarsa.
 *
 * @param mysqli $conn  Koneksi database
 * @param string $token Bearer token dari header
 * @return int|null     User ID jika valid, null jika tidak
 */
function validate_api_token($conn, $token) {
    if (empty($token)) return null;
    
    $stmt = $conn->prepare("SELECT user_id, expires_at FROM api_tokens WHERE token = ? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        return null;
    }
    
    $row = $result->fetch_assoc();
    $stmt->close();
    
    // Cek apakah token sudah kadaluarsa
    if (strtotime($row['expires_at']) < time()) {
        // Hapus token kadaluarsa
        $delStmt = $conn->prepare("DELETE FROM api_tokens WHERE token = ?");
        $delStmt->bind_param("s", $token);
        $delStmt->execute();
        $delStmt->close();
        return null;
    }
    
    // Token valid — perpanjang TTL (sliding expiration)
    $newExpiry = date('Y-m-d H:i:s', time() + API_TOKEN_TTL_SECONDS);
    $updStmt = $conn->prepare("UPDATE api_tokens SET expires_at = ? WHERE token = ?");
    $updStmt->bind_param("ss", $newExpiry, $token);
    $updStmt->execute();
    $updStmt->close();
    
    return (int)$row['user_id'];
}

/**
 * Extract Bearer token dari header Authorization.
 * 
 * @return string|null Token string atau null jika tidak ada
 */
function get_bearer_token() {
    $headers = null;
    
    // Coba ambil dari Apache/Nginx header
    if (function_exists('apache_request_headers')) {
        $apacheHeaders = apache_request_headers();
        // Header case-insensitive
        foreach ($apacheHeaders as $key => $val) {
            if (strtolower($key) === 'authorization') {
                $headers = $val;
                break;
            }
        }
    }
    
    // Fallback: ambil dari $_SERVER
    if ($headers === null) {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
    }
    
    if ($headers === null) return null;
    
    // Parse "Bearer <token>"
    if (preg_match('/Bearer\s+(\S+)/i', $headers, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Middleware: Wajibkan autentikasi token di endpoint.
 * Jika token tidak valid, langsung return error 401 dan exit.
 * Jika valid, return user_id.
 *
 * PENTING: Panggil setelah require 'db_config.php' karena butuh $conn.
 * 
 * @param mysqli $conn Koneksi database
 * @return int User ID dari token yang valid
 */
function require_auth($conn) {
    $token = get_bearer_token();
    
    if ($token === null) {
        api_response([
            "success" => false,
            "message" => "Akses ditolak: Token autentikasi tidak ditemukan. Silakan login terlebih dahulu.",
            "auth_error" => true
        ], 401);
    }
    
    $userId = validate_api_token($conn, $token);
    
    if ($userId === null) {
        api_response([
            "success" => false,
            "message" => "Sesi Anda telah berakhir. Silakan login kembali.",
            "auth_error" => true
        ], 401);
    }
    
    return $userId;
}

/**
 * Helper: Generate UUIDv4 untuk penamaan file.
 * Menghasilkan random UUID format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
 * 
 * @return string UUID v4
 */
function generate_uuid_v4() {
    $data = random_bytes(16);
    // Set version to 0100 (UUID v4)
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set bits 6-7 to 10
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
