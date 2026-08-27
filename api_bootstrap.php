<?php
/**
 * =========================================================================================
 * SINDESA REST API — File Bootstrap Utama (api_bootstrap.php)
 * =========================================================================================
 * 
 * FUNGSI UTAMA:
 * 1. Di-include di BARIS PERTAMA pada SELURUH file endpoint API di direktori sindesa-api/.
 * 2. Output Buffering & Error Handling: Menjaga respon selalu berupa JSON murni tanpa tercampur teks error PHP.
 * 3. Whitelist CORS Security: Membatasi akses lintas domain hanya untuk domain resmi Sindesa.
 * 4. Manajemen Token Sesi Stateful (Stateful DB-Backed Token):
 *    - generate_api_token: Membuat token acak 64-karakter hex (random_bytes) dengan masa aktif 30 hari.
 *    - validate_api_token: Memvalidasi token & memperpanjang masa aktif secara otomatis (Sliding Expiration).
 *    - require_auth: Middleware keamanan yang menolak akses tidak sah (HTTP 401 Unauthorized).
 * 5. Anonimisasi Berkas UUIDv4: Mengubah nama file upload menjadi UUID acak demi kepatuhan UU PDP.
 * =========================================================================================
 */

// =========================================================================================
// 1. OUTPUT BUFFERING
// =========================================================================================
// Menangkap semua output tak terduga (misal warning atau notice PHP) di memori buffer
// agar tidak merusak format JSON yang dikirimkan ke aplikasi Android.
if (!ob_get_level()) {
    ob_start();
}

// =========================================================================================
// 2. ERROR HANDLING & LOGGING (SECURE BY DESIGN)
// =========================================================================================
// JANGAN menampilkan error detail ke output client (display_errors = 0) untuk mencegah Information Leakage.
// Seluruh error dicatat secara aman ke file log internal 'debug.txt'.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.txt');

// =========================================================================================
// 3. SHUTDOWN FUNCTION (PENANGANAN FATAL ERROR SERVER)
// =========================================================================================
// Jika terjadi Fatal Error atau fungsi die() terpicu, fungsi ini menjamin server tetap
// merespons dalam format JSON standar (bukan halaman kosong atau crash 500 mentah).
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, cors_allowed_origins())) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        echo json_encode([
            "success" => false,
            "message" => "Server Error: " . $error['message'] . " in " . basename($error['file']) . ":" . $error['line']
        ], JSON_UNESCAPED_UNICODE);
    } else {
        if (ob_get_length()) ob_end_flush();
    }
});

// Set default header respon selalu berupa JSON UTF-8
header('Content-Type: application/json; charset=utf-8');

// =========================================================================================
// 4. DAFTAR DOMAIN WHITELIST CORS (CROSS-ORIGIN RESOURCE SHARING)
// =========================================================================================
/**
 * Mengembalikan daftar domain resmi yang diizinkan memanggil API.
 * Mencegah eksploitasi CSRF / CORS Misconfiguration dari website asing.
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

// Memasang header CORS sesuai domain pemanggil jika terdaftar di whitelist
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($requestOrigin, cors_allowed_origins())) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Menangani permintaan Preflight OPTIONS dari browser
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    if (ob_get_length()) ob_end_clean();
    exit;
}

// =========================================================================================
// 5. HELPER PENGIRIMAN RESPON JSON
// =========================================================================================
/**
 * Mengirimkan respon data sukses dalam format JSON yang bersih dan menghentikan eksekusi script.
 */
function api_response($data, $httpCode = 200) {
    http_response_code($httpCode);
    if (ob_get_length()) ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Mengirimkan respon pesan kegagalan / error dalam format JSON.
 */
function api_error($message, $httpCode = 200) {
    api_response(["success" => false, "message" => $message], $httpCode);
}

// =========================================================================================
// 6. KONSTANTA MASA AKTIF TOKEN SESI MOBILE (SLIDING EXPIRATION)
// =========================================================================================
/**
 * Masa aktif token sesi mobile disetel ke 30 Hari (2.592.000 detik).
 * Menggunakan mekanisme Sliding Expiration: setiap kali aplikasi dibuka dan dipakai,
 * masa aktif token otomatis diperpanjang 30 hari ke depan, sehingga warga tidak perlu
 * berulang kali login setiap hari.
 */
if (!defined('API_TOKEN_TTL_SECONDS')) {
    define('API_TOKEN_TTL_SECONDS', 2592000); // 30 Hari (2.592.000 detik)
}

if (!defined('WEBSITE_URL')) {
    $apiHost = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($apiHost, 'api.') === 0) {
        $webHost = substr($apiHost, 4);
        define('WEBSITE_URL', 'https://' . $webHost . '/');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        define('WEBSITE_URL', $scheme . '://' . $apiHost . '/');
    }
}

/**
 * Helper: Mengonversi path foto_profil relatif dari database menjadi URL endpoint resmi API.
 */
function get_foto_profil_url($fotoPath) {
    if (empty($fotoPath)) return '';
    
    $cleanPath = ltrim($fotoPath, '/');
    if (strpos($cleanPath, 'storage/app/public/') === 0) {
        $cleanPath = substr($cleanPath, strlen('storage/app/public/'));
    } elseif (strpos($cleanPath, 'public/storage/') === 0) {
        $cleanPath = substr($cleanPath, strlen('public/storage/'));
    } elseif (strpos($cleanPath, 'storage/') === 0) {
        $cleanPath = substr($cleanPath, strlen('storage/'));
    }
    
    $filename = basename($cleanPath);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $apiHost = $_SERVER['HTTP_HOST'] ?? 'api.sindesa-buttusawe.com';
    return $scheme . '://' . $apiHost . '/foto_profil.php?file=' . urlencode($filename);
}

// =========================================================================================
// 7. MANAJEMEN OTENTIKASI SESI TOKEN (STATEFUL DB-BACKED TOKEN)
// =========================================================================================
/**
 * Membuat Token API baru untuk user setelah berhasil memvalidasi kredensial login.
 * - Menghapus token lama user (Single Active Device Session Enforcement).
 * - Menghapus token kadaluarsa milik seluruh user (Database House-Keeping).
 * - Menyimpan token baru ke tabel api_tokens dengan waktu expires_at = NOW() + 30 Hari.
 */
function generate_api_token($conn, $userId) {
    $userId = (int)$userId;
    
    // Hapus sesi login lama milik user ini
    $stmt = $conn->prepare("DELETE FROM api_tokens WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
    
    // Pembersihan berkala token yang sudah kadaluarsa
    $conn->query("DELETE FROM api_tokens WHERE expires_at < NOW()");
    
    // Generate token acak kriptografis 64-karakter heksadesimal (32 bytes)
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
 * Memvalidasi Bearer Token yang dikirimkan oleh aplikasi Android:
 * - Memeriksa keberadaan token di tabel api_tokens.
 * - Memeriksa apakah token sudah kadaluarsa (expires_at < time()).
 * - Jika VALID: Memperpanjang masa aktif token secara otomatis (Sliding Expiration).
 * - Mengembalikan user_id jika valid, atau null jika tidak valid/kadaluarsa.
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
    
    // Cek apakah token sudah melewati batas waktu kadaluarsa
    if (strtotime($row['expires_at']) < time()) {
        // Hapus token yang sudah hangus
        $delStmt = $conn->prepare("DELETE FROM api_tokens WHERE token = ?");
        $delStmt->bind_param("s", $token);
        $delStmt->execute();
        $delStmt->close();
        return null;
    }
    
    // TOKEN VALID: Perpanjang masa aktif 30 hari lagi (Sliding Expiration)
    $newExpiry = date('Y-m-d H:i:s', time() + API_TOKEN_TTL_SECONDS);
    $updStmt = $conn->prepare("UPDATE api_tokens SET expires_at = ? WHERE token = ?");
    $updStmt->bind_param("ss", $newExpiry, $token);
    $updStmt->execute();
    $updStmt->close();
    
    return (int)$row['user_id'];
}

/**
 * Mengekstrak string Bearer Token dari Header HTTP "Authorization: Bearer <token>".
 */
function get_bearer_token() {
    $headers = null;
    
    if (function_exists('apache_request_headers')) {
        $apacheHeaders = apache_request_headers();
        foreach ($apacheHeaders as $key => $val) {
            if (strtolower($key) === 'authorization') {
                $headers = $val;
                break;
            }
        }
    }
    
    if ($headers === null) {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
    }
    
    if ($headers === null) return null;
    
    if (preg_match('/Bearer\s+(\S+)/i', $headers, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Middleware Wajib Autentikasi (require_auth):
 * Dipanggil di setiap endpoint terproteksi untuk memastikan pengakses adalah warga yang terotentikasi.
 * Jika tidak valid -> Mengembalikan respon HTTP 401 Unauthorized dan menghentikan proses.
 * Jika valid -> Mengembalikan ID warga ($auth_user_id) untuk penguncian query database (Anti-IDOR).
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

// =========================================================================================
// 8. HELPER GENERATE UUIDv4 (ANONIMISASI NAMA FILE / KEPATUHAN UU PDP)
// =========================================================================================
/**
 * Menghasilkan UUID acak versi 4 (format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx).
 * Digunakan untuk mengacak nama file KTP dan KK yang diunggah warga agar tidak memuat NIK/Nama asli.
 */
function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
