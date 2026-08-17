<?php
/**
 * SINDESA API — Hapus / Batalkan Pengajuan Surat (STANDALONE)
 * 
 * SECURITY: Memerlukan token autentikasi + ownership check.
 * Hanya bisa hapus pengajuan milik sendiri yang masih menunggu verifikasi.
 */
require_once 'api_bootstrap.php';
require_once 'db_config.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

// Autentikasi wajib
$auth_user_id = require_auth($conn);

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
    api_error("ID pengajuan tidak valid (id=$id)");
}

// Query + ownership check (IDOR protection)
$stmt = $conn->prepare("SELECT id, status FROM pengajuan_surats WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param("ii", $id, $auth_user_id);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows == 0) {
    $stmt->close();
    api_error("Pengajuan tidak ditemukan atau Anda tidak memiliki akses");
}

$row = $res->fetch_assoc();
$stmt->close();

$status = strtolower(trim(str_replace([' ', '-'], '_', $row['status'] ?? '')));

// Cek status — hanya hapus jika menunggu verifikasi
if (strpos($status, 'menunggu') !== false) {
    $del_stmt = $conn->prepare("DELETE FROM pengajuan_surats WHERE id = ? AND user_id = ?");
    $del_stmt->bind_param("ii", $id, $auth_user_id);
    if ($del_stmt->execute()) {
        $del_stmt->close();
        api_response(["success" => true, "message" => "Pengajuan surat berhasil dibatalkan"]);
    } else {
        $del_stmt->close();
        api_error("Gagal hapus dari DB: " . $conn->error, 500);
    }
} else {
    api_error("Tidak bisa dihapus. Status: " . $row['status']);
}

mysqli_close($conn);
