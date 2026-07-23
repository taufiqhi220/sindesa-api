<?php
/**
 * SINDESA API — Hapus / Batalkan Pengajuan Surat
 * Keamanan: Hanya bisa dihapus jika status masih 'menunggu_verifikasi'
 */
require_once 'api_bootstrap.php';
require_once 'db_config.php';
require_once 'upload_helper.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true) ?? [];

$id = 0;
if (!empty($_POST['id'])) $id = (int)$_POST['id'];
elseif (!empty($_POST['id_pengajuan'])) $id = (int)$_POST['id_pengajuan'];
elseif (!empty($json_input['id'])) $id = (int)$json_input['id'];
elseif (!empty($json_input['id_pengajuan'])) $id = (int)$json_input['id_pengajuan'];
elseif (!empty($_REQUEST['id'])) $id = (int)$_REQUEST['id'];
elseif (!empty($_REQUEST['id_pengajuan'])) $id = (int)$_REQUEST['id_pengajuan'];

if ($id <= 0) {
    api_response(["success" => false, "message" => "ID pengajuan tidak valid"]);
}

// Cari pengajuan surat berdasarkan ID
$id_safe = (int)$id;
$res = mysqli_query($conn, "SELECT id, status, data_tambahan FROM pengajuan_surats WHERE id = $id_safe LIMIT 1");
if (!$res || mysqli_num_rows($res) == 0) {
    api_response(["success" => false, "message" => "Pengajuan surat dengan ID $id tidak ditemukan"]);
}

$pengajuan = mysqli_fetch_assoc($res);
$raw_status = $pengajuan['status'] ?? '';
$status_clean = strtolower(trim(str_replace([' ', '-'], '_', $raw_status)));

// Keamanan: Hanya bisa dihapus jika belum diproses oleh operator (menunggu_verifikasi)
if ($status_clean === 'menunggu_verifikasi' || $status_clean === 'menunggu' || $status_clean === 'menunggu_verifikasi_operator' || strpos($status_clean, 'menunggu') !== false) {
    // Hapus file fisik pendukung jika ada
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

    $del_query = "DELETE FROM pengajuan_surats WHERE id = $id_safe";
    if (mysqli_query($conn, $del_query)) {
        api_response(["success" => true, "message" => "Pengajuan surat berhasil dibatalkan"]);
    } else {
        api_response(["success" => false, "message" => "Gagal menghapus pengajuan dari database: " . mysqli_error($conn)]);
    }
} else {
    api_response(["success" => false, "message" => "Gagal membatalkan. Status surat saat ini: '$raw_status'. Surat sudah mulai diproses oleh petugas."]);
}
