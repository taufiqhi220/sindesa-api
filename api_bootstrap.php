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
