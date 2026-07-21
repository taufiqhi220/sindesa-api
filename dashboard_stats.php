<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';

$nik = isset($_GET['nik']) ? mysqli_real_escape_string($conn, $_GET['nik']) : '';

if (empty($nik)) {
    api_error("NIK tidak ditemukan");
}

// Cari ID User berdasarkan NIK
$sql_user = "SELECT id FROM users WHERE nik = '$nik' LIMIT 1";
$res_user = mysqli_query($conn, $sql_user);

if ($res_user && mysqli_num_rows($res_user) > 0) {
    $user = mysqli_fetch_assoc($res_user);
    $user_id = (int)$user['id'];

    // Hitung total pengajuan
    $sql_total = "SELECT COUNT(id) as total FROM pengajuan_surats WHERE user_id = '$user_id'";
    $res_total = mysqli_query($conn, $sql_total);
    $total_pengajuan = ($res_total) ? mysqli_fetch_assoc($res_total)['total'] : 0;

    // Hitung pengajuan yang sedang diproses (menunggu_verifikasi atau diproses_kades)
    $sql_proses = "SELECT COUNT(id) as proses FROM pengajuan_surats WHERE user_id = '$user_id' AND status IN ('menunggu_verifikasi', 'diproses_kades')";
    $res_proses = mysqli_query($conn, $sql_proses);
    $proses_pengajuan = ($res_proses) ? mysqli_fetch_assoc($res_proses)['proses'] : 0;

    // Layanan Sering Digunakan
    $sql_sering = "SELECT jenis_surat, COUNT(id) as count FROM pengajuan_surats WHERE user_id = '$user_id' GROUP BY jenis_surat ORDER BY count DESC LIMIT 2";
    $res_sering = mysqli_query($conn, $sql_sering);
    $sering_digunakan = [];
    if ($res_sering) {
        while ($row = mysqli_fetch_assoc($res_sering)) {
            $sering_digunakan[] = $row['jenis_surat'];
        }
    }
    
    // Default fallback if no history
    if (count($sering_digunakan) == 0) {
        $sering_digunakan = ['pengantar_ktp', 'pengantar_kk'];
    } elseif (count($sering_digunakan) == 1) {
        // If only 1, add a default second one to keep UI balanced
        $sering_digunakan[] = ($sering_digunakan[0] == 'pengantar_ktp') ? 'pengantar_kk' : 'pengantar_ktp';
    }

    $response = [
        "success" => true,
        "total_pengajuan" => (int)$total_pengajuan,
        "proses_pengajuan" => (int)$proses_pengajuan,
        "sering_digunakan" => $sering_digunakan
    ];
} else {
    $response = [
        "success" => false,
        "message" => "Warga dengan NIK $nik belum terdaftar",
        "total_pengajuan" => 0,
        "proses_pengajuan" => 0
    ];
}

mysqli_close($conn);
api_response($response);
