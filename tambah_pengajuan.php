<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

$nik = isset($_POST['nik']) ? mysqli_real_escape_string($conn, $_POST['nik']) : '';
$jenis_surat = isset($_POST['jenis_surat']) ? mysqli_real_escape_string($conn, $_POST['jenis_surat']) : 'Layanan';

if (empty($nik)) {
    api_error("NIK tidak boleh kosong");
}

// 1. Cari user_id berdasarkan NIK
$sql_user = "SELECT id FROM users WHERE nik = '$nik' LIMIT 1";
$res_user = mysqli_query($conn, $sql_user);

if ($res_user && mysqli_num_rows($res_user) > 0) {
    $user = mysqli_fetch_assoc($res_user);
    $user_id = (int)$user['id'];

    // 2. Simpan ke tabel pengajuan_surats agar muncul di Riwayat
    // Kita simpan jenis_surat, user_id, dan status default 'Diproses'
    $sql_insert = "INSERT INTO pengajuan_surats (user_id, jenis_surat, status, keterangan, created_at)
                   VALUES ('$user_id', '$jenis_surat', 'Diproses', 'Menunggu verifikasi admin', NOW())";

    if (mysqli_query($conn, $sql_insert)) {
        api_response(["success" => true, "message" => "Pengajuan $jenis_surat berhasil dikirim"]);
    } else {
        api_error("Gagal mencatat riwayat: " . mysqli_error($conn), 500);
    }
} else {
    api_error("NIK tidak terdaftar sebagai warga");
}

mysqli_close($conn);