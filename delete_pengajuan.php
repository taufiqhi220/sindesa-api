<?php
/**
 * SINDESA API — Hapus / Batalkan Pengajuan Surat
 * Keamanan: Hanya bisa dihapus jika status masih 'menunggu_verifikasi'
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/upload_helper.php';

$id = 0;
if (!empty($_POST['id'])) $id = (int)$_POST['id'];
elseif (!empty($_POST['id_pengajuan'])) $id = (int)$_POST['id_pengajuan'];
elseif (!empty($_REQUEST['id'])) $id = (int)$_REQUEST['id'];

if ($id <= 0) {
    api_response(["success" => false, "message" => "ID pengajuan tidak valid"]);
}

// Cari pengajuan surat berdasarkan ID
$res = mysqli_query($conn, "SELECT id, status, data_tambahan FROM pengajuan_surats WHERE id = '$id' LIMIT 1");
if (!$res || mysqli_num_rows($res) == 0) {
    api_response(["success" => false, "message" => "Pengajuan surat tidak ditemukan"]);
}

$pengajuan = mysqli_fetch_assoc($res);
$status = strtolower($pengajuan['status'] ?? '');

// Keamanan: Hanya bisa dihapus jika belum diproses oleh operator (menunggu_verifikasi)
if ($status === 'menunggu_verifikasi' || $status === 'menunggu') {
    // Opsional: Hapus file fisik pendukung jika ada
    if (!empty($pengajuan['data_tambahan'])) {
        $data_tambahan = json_decode($pengajuan['data_tambahan'], true) ?? [];
        foreach ($data_tambahan as $key => $val) {
            if (is_string($val) && (strpos($key, 'file_') === 0 || strpos($key, 'berkas_') === 0 || strpos($key, 'foto_') === 0)) {
                $target_path = __DIR__ . '/' . ltrim($val, '/');
                if (file_exists($target_path) && is_file($target_path)) {
                    @unlink($target_path);
                }
            }
        }
    }

    $del_query = "DELETE FROM pengajuan_surats WHERE id = '$id'";
    if (mysqli_query($conn, $del_query)) {
        api_response(["success" => true, "message" => "Pengajuan surat berhasil dibatalkan"]);
    } else {
        api_response(["success" => false, "message" => "Gagal menghapus pengajuan dari database: " . mysqli_error($conn)]);
    }
} else {
    api_response(["success" => false, "message" => "Gagal membatalkan. Surat sudah mulai diproses oleh petugas."]);
}
