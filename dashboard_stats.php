<?php
/**
 * =========================================================================================
 * SINDESA REST API — Endpoint Statistik Dashboard (dashboard_stats.php)
 * =========================================================================================
 * 
 * FUNGSI UTAMA:
 * 1. Menghitung jumlah 'Total Surat' yang pernah diajukan oleh akun warga yang login.
 * 2. Menghitung jumlah 'Surat Sedang Diproses' (status: 'menunggu_verifikasi' atau 'diproses_kades').
 * 3. Menghitung 2 jenis layanan surat yang paling sering diajukan untuk kartu pintasan cepat di Dashboard.
 * 
 * PENERAPAN SECURE BY DESIGN:
 * - Anti-IDOR: Statistik dihitung secara eksklusif hanya untuk $auth_user_id dari token valid.
 * =========================================================================================
 */

require_once 'api_bootstrap.php';
require_once 'db_config.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

// -----------------------------------------------------------------------------------------
// 1. OTENTIKASI SESI TOKEN (MIDDLEWARE)
// -----------------------------------------------------------------------------------------
$auth_user_id = require_auth($conn);

// -----------------------------------------------------------------------------------------
// 2. AMBIL NIK WARGA DARI BASIS DATA
// -----------------------------------------------------------------------------------------
$res_nik = $conn->prepare("SELECT nik FROM users WHERE id = ? LIMIT 1");
$res_nik->bind_param("i", $auth_user_id);
$res_nik->execute();
$nik_result = $res_nik->get_result();
$user_nik = '';
if ($nik_result->num_rows > 0) {
    $user_nik = $nik_result->fetch_assoc()['nik'] ?? '';
}
$res_nik->close();

$nik_clean = $conn->real_escape_string($user_nik);
$user_clause = "(user_id = '$auth_user_id'";
if (!empty($nik_clean)) {
    $user_clause .= " OR data_tambahan LIKE '%\"nik\":\"$nik_clean\"%' OR data_tambahan LIKE '%\"nik_pemohon\":\"$nik_clean\"%'";
}
$user_clause .= ")";

// -----------------------------------------------------------------------------------------
// 3. HITUNG TOTAL PENGAJUAN SURAT WARGA
// -----------------------------------------------------------------------------------------
$sql_total = "SELECT COUNT(id) as total FROM pengajuan_surats WHERE $user_clause";
$res_total = mysqli_query($conn, $sql_total);
$total_pengajuan = ($res_total) ? mysqli_fetch_assoc($res_total)['total'] : 0;

// -----------------------------------------------------------------------------------------
// 4. HITUNG SURAT YANG SEDANG DALAM PROSES PENGERJAAN
// -----------------------------------------------------------------------------------------
$sql_proses = "SELECT COUNT(id) as proses FROM pengajuan_surats WHERE $user_clause AND status IN ('menunggu_verifikasi', 'diproses_kades')";
$res_proses = mysqli_query($conn, $sql_proses);
$proses_pengajuan = ($res_proses) ? mysqli_fetch_assoc($res_proses)['proses'] : 0;

// -----------------------------------------------------------------------------------------
// 5. MENGAMBIL 2 LAYANAN YANG PALING SERING DIGUNAKAN (TOP 2 SERVICES)
// -----------------------------------------------------------------------------------------
$sql_sering = "SELECT jenis_surat, COUNT(id) as count FROM pengajuan_surats WHERE $user_clause GROUP BY jenis_surat ORDER BY count DESC LIMIT 2";
$res_sering = mysqli_query($conn, $sql_sering);
$sering_digunakan = [];
if ($res_sering) {
    while ($row = mysqli_fetch_assoc($res_sering)) {
        $sering_digunakan[] = $row['jenis_surat'];
    }
}

// Fallback bawaan jika belum ada histori surat sama sekali
if (count($sering_digunakan) == 0) {
    $sering_digunakan = ['pengantar_ktp', 'pengantar_kk'];
} elseif (count($sering_digunakan) == 1) {
    $sering_digunakan[] = ($sering_digunakan[0] == 'pengantar_ktp') ? 'pengantar_kk' : 'pengantar_ktp';
}

mysqli_close($conn);

// -----------------------------------------------------------------------------------------
// 6. KIRIM HASIL REKAP STATISTIK
// -----------------------------------------------------------------------------------------
api_response([
    "success" => true,
    "total_pengajuan" => (int)$total_pengajuan,
    "proses_pengajuan" => (int)$proses_pengajuan,
    "sering_digunakan" => $sering_digunakan
]);
