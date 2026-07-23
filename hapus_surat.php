<?php
/**
 * SINDESA API — Hapus / Batalkan Pengajuan Surat (STANDALONE)
 * File ini tidak depend pada file lain. Semua inline.
 */

// Output buffering
if (!ob_get_level()) ob_start();

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_hapus.txt');

// Shutdown handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(["success" => false, "message" => "Fatal: " . $error['message'] . " di " . basename($error['file']) . ":" . $error['line']]);
    } else {
        if (ob_get_length()) ob_end_flush();
    }
});

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    if (ob_get_length()) ob_end_clean();
    exit;
}

// Database — coba server config dulu, fallback ke default
if (file_exists(__DIR__ . '/db_config.server.php')) {
    require_once __DIR__ . '/db_config.server.php';
} else {
    $host   = "localhost";
    $user   = "root";
    $pass   = "";
    $dbname = "db_sindesa";
    $conn = mysqli_connect($host, $user, $pass, $dbname);
    if (!$conn) {
        if (ob_get_length()) ob_clean();
        echo json_encode(["success" => false, "message" => "DB gagal: " . mysqli_connect_error()]);
        exit;
    }
    mysqli_set_charset($conn, "utf8mb4");
}

// Baca ID dari semua kemungkinan input
$raw = file_get_contents('php://input');
$json = @json_decode($raw, true);
if (!is_array($json)) $json = [];

$id = 0;
if (!empty($_POST['id']))              $id = (int)$_POST['id'];
elseif (!empty($_GET['id']))           $id = (int)$_GET['id'];
elseif (!empty($json['id']))           $id = (int)$json['id'];
elseif (!empty($_REQUEST['id']))       $id = (int)$_REQUEST['id'];
elseif (!empty($_POST['id_pengajuan']))    $id = (int)$_POST['id_pengajuan'];
elseif (!empty($_GET['id_pengajuan']))     $id = (int)$_GET['id_pengajuan'];
elseif (!empty($json['id_pengajuan']))     $id = (int)$json['id_pengajuan'];

if ($id <= 0) {
    if (ob_get_length()) ob_clean();
    echo json_encode(["success" => false, "message" => "ID pengajuan tidak valid (id=$id)"]);
    exit;
}

// Query
$res = mysqli_query($conn, "SELECT id, status FROM pengajuan_surats WHERE id = $id LIMIT 1");
if (!$res || mysqli_num_rows($res) == 0) {
    if (ob_get_length()) ob_clean();
    echo json_encode(["success" => false, "message" => "Pengajuan ID $id tidak ditemukan"]);
    exit;
}

$row = mysqli_fetch_assoc($res);
$status = strtolower(trim(str_replace([' ', '-'], '_', $row['status'] ?? '')));

// Cek status — hanya hapus jika menunggu verifikasi
if (strpos($status, 'menunggu') !== false) {
    $ok = mysqli_query($conn, "DELETE FROM pengajuan_surats WHERE id = $id");
    if ($ok) {
        if (ob_get_length()) ob_clean();
        echo json_encode(["success" => true, "message" => "Pengajuan surat berhasil dibatalkan"]);
        exit;
    } else {
        if (ob_get_length()) ob_clean();
        echo json_encode(["success" => false, "message" => "Gagal hapus dari DB: " . mysqli_error($conn)]);
        exit;
    }
} else {
    if (ob_get_length()) ob_clean();
    echo json_encode(["success" => false, "message" => "Tidak bisa dihapus. Status: " . $row['status']]);
    exit;
}
