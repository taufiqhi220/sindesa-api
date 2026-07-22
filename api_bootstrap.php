<?php
/**
 * API Bootstrap — Sindesa API
 * 
 * Include file ini di AWAL setiap endpoint API.
 * Menangani: output buffering, error handling, CORS headers, preflight OPTIONS.
 * 
 * Penggunaan:
 *   require_once 'api_bootstrap.php';
 *   require_once 'db_config.php';
 *   // ... kode endpoint ...
 */

// 1. Output Buffering — tangkap output sampah (warning/notice) agar tidak merusak JSON
ob_start();

// 2. Error Handling — JANGAN tampilkan error ke output, log ke file saja
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.txt');

// 3. Content-Type JSON
header('Content-Type: application/json; charset=utf-8');

// 4. CORS Headers — agar Android app bisa akses API dari domain manapun
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// 5. Handle OPTIONS Preflight — langsung respond 200 tanpa proses lebih lanjut
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit;
}

/**
 * Helper: Kirim JSON response yang bersih
 * Membersihkan output buffer sebelum echo JSON agar tidak ada sampah.
 * 
 * @param array $data Data array untuk di-encode ke JSON
 * @param int $httpCode HTTP status code (default 200)
 */
function api_response($data, $httpCode = 200) {
    http_response_code($httpCode);
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Helper: Kirim error response
 * 
 * @param string $message Pesan error
 * @param int $httpCode HTTP status code (default 400)
 */
function api_error($message, $httpCode = 200) {
    api_response(["success" => false, "message" => $message], $httpCode);
}
