<?php
/**
 * SINDESA API — Get Profil
 * Endpoint: GET/POST /get_profil.php
 * 
 * Mengambil data profil user berdasarkan token autentikasi.
 * SECURITY: NIK tidak lagi digunakan sebagai parameter (Guideline §5).
 */
require_once 'api_bootstrap.php';
require_once 'db_config.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

// Autentikasi wajib — identifikasi user dari Bearer token
$auth_user_id = require_auth($conn);

// Ambil data profil user berdasarkan user_id dari token (bukan NIK)
$sql = "SELECT u.name, u.nik, u.no_kk, u.email, u.agama, u.jenis_kelamin, u.tempat_lahir, u.tanggal_lahir,
               u.status_perkawinan, u.pekerjaan, u.kewarganegaraan, u.alamat_lengkap, u.rt_rw,
               u.provinsi, u.kota, u.kecamatan, u.kelurahan_desa, u.no_hp, u.phone, u.foto_profil, u.status,
               p.name as prov_name,
               c.name as kota_name,
               d.name as kec_name,
               v.name as desa_name
        FROM users u
        LEFT JOIN indonesia_provinces p ON u.provinsi = p.code
        LEFT JOIN indonesia_cities c ON u.kota = c.code
        LEFT JOIN indonesia_districts d ON u.kecamatan = d.code
        LEFT JOIN indonesia_villages v ON u.kelurahan_desa = v.code
        WHERE u.id = ? LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $auth_user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // Gunakan no_hp jika ada, jika kosong gunakan phone dari web
    $noHpFinal = !empty($user['no_hp']) ? $user['no_hp'] : ($user['phone'] ?? '');
    
    // Tampilkan nama daerah jika ada, jika tidak fallback ke kode daerah
    $provinsiFinal = !empty($user['prov_name']) ? $user['prov_name'] : ($user['provinsi'] ?? '');
    $kotaFinal = !empty($user['kota_name']) ? $user['kota_name'] : ($user['kota'] ?? '');
    $kecamatanFinal = !empty($user['kec_name']) ? $user['kec_name'] : ($user['kecamatan'] ?? '');
    $desaFinal = !empty($user['desa_name']) ? $user['desa_name'] : ($user['kelurahan_desa'] ?? '');

    api_response([
        "success" => true,
        "message" => "Profil ditemukan",
        "data" => [
            "user" => [
                "id"                => (int)$auth_user_id,
                "nama"              => $user['name'] ?? '',
                "nik"               => $user['nik'] ?? '',
                "no_kk"             => $user['no_kk'] ?? '',
                "email"             => $user['email'] ?? '',
                "agama"             => $user['agama'] ?? '',
                "jenis_kelamin"     => $user['jenis_kelamin'] ?? '',
                "tempat_lahir"      => $user['tempat_lahir'] ?? '',
                "tanggal_lahir"     => $user['tanggal_lahir'] ?? '',
                "status_perkawinan" => $user['status_perkawinan'] ?? '',
                "pekerjaan"         => $user['pekerjaan'] ?? '',
                "kewarganegaraan"   => $user['kewarganegaraan'] ?? '',
                "alamat_lengkap"    => $user['alamat_lengkap'] ?? '',
                "rt_rw"             => $user['rt_rw'] ?? '',
                "provinsi"          => $provinsiFinal,
                "kota"              => $kotaFinal,
                "kecamatan"         => $kecamatanFinal,
                "kelurahan_desa"    => $desaFinal,
                "provinsi_code"     => $user['provinsi'] ?? '',
                "kota_code"         => $user['kota'] ?? '',
                "kecamatan_code"    => $user['kecamatan'] ?? '',
                "kelurahan_desa_code"=> $user['kelurahan_desa'] ?? '',
                "no_hp"             => $noHpFinal,
                "foto_profil"       => get_foto_profil_url($user['foto_profil'] ?? ''),
                "status"            => $user['status'] ?? 'inactive'
            ]
        ]
    ]);
} else {
    api_error("Profil tidak ditemukan");
}

$stmt->close();
mysqli_close($conn);
