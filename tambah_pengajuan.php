<?php
/**
 * SINDESA API — Tambah Pengajuan (Generic)
 * 
 * SECURITY: Memerlukan token autentikasi.
 */
require_once 'api_bootstrap.php';
require_once 'db_config.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

// Autentikasi wajib
$auth_user_id = require_auth($conn);

$jenis_surat = isset($_POST['jenis_surat']) ? mysqli_real_escape_string($conn, $_POST['jenis_surat']) : 'Layanan';

// User diidentifikasi dari token
$res_user = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$res_user->bind_param("i", $auth_user_id);
$res_user->execute();
$result = $res_user->get_result();

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $user_id = (int)$user['id'];
    $res_user->close();

    // Simpan ke tabel pengajuan_surats agar muncul di Riwayat
    $jenis_escaped = mysqli_real_escape_string($conn, $jenis_surat);
    $sql_insert = "INSERT INTO pengajuan_surats (user_id, jenis_surat, status, keterangan, created_at)
                   VALUES ('$user_id', '$jenis_escaped', 'Diproses', 'Menunggu verifikasi admin', NOW())";

    if (mysqli_query($conn, $sql_insert)) {
        api_response(["success" => true, "message" => "Pengajuan $jenis_surat berhasil dikirim"]);
    } else {
        api_error("Gagal mencatat riwayat: " . mysqli_error($conn), 500);
    }
} else {
    $res_user->close();
    api_error("User tidak ditemukan");
}

mysqli_close($conn);