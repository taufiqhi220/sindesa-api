<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

$username_or_email = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if(empty($username_or_email) || empty($password)) {
    api_error("Username dan Password wajib diisi");
}

// Berdasarkan screenshot, kolomnya adalah 'nik' dan 'email'
$query = "SELECT u.*, 
                 p.name as prov_name, 
                 c.name as kota_name, 
                 d.name as kec_name, 
                 v.name as desa_name 
          FROM users u
          LEFT JOIN indonesia_provinces p ON u.provinsi = p.code
          LEFT JOIN indonesia_cities c ON u.kota = c.code
          LEFT JOIN indonesia_districts d ON u.kecamatan = d.code
          LEFT JOIN indonesia_villages v ON u.kelurahan_desa = v.code
          WHERE (u.nik = ? OR u.email = ?) AND u.role = 'warga'";

$stmt = $conn->prepare($query);
if (!$stmt) {
    api_error("Error Query: " . $conn->error, 500);
}

$stmt->bind_param("ss", $username_or_email, $username_or_email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // Verifikasi Password (Mendukung Hash Bcrypt Laravel & Plain Text untuk Testing)
    if (password_verify($password, $user['password']) || $password == $user['password']) {
        
        // Gunakan no_hp jika ada, jika kosong gunakan phone dari web
        $noHpFinal = !empty($user['no_hp']) ? $user['no_hp'] : ($user['phone'] ?? '');
        
        // Tampilkan nama daerah jika ada, jika tidak fallback ke kode daerah
        $provinsiFinal = !empty($user['prov_name']) ? $user['prov_name'] : ($user['provinsi'] ?? '');
        $kotaFinal = !empty($user['kota_name']) ? $user['kota_name'] : ($user['kota'] ?? '');
        $kecamatanFinal = !empty($user['kec_name']) ? $user['kec_name'] : ($user['kecamatan'] ?? '');
        $desaFinal = !empty($user['desa_name']) ? $user['desa_name'] : ($user['kelurahan_desa'] ?? '');

        api_response([
            "success" => true,
            "message" => "Login Berhasil",
            "data" => [
                "user" => [
                    "nama"  => $user['name'],
                    "nik"   => $user['nik'], 
                    "email" => $user['email'],
                    "no_kk" => $user['no_kk'] ?? '',
                    "agama" => $user['agama'] ?? '',
                    "jenis_kelamin" => $user['jenis_kelamin'] ?? '',
                    "tempat_lahir" => $user['tempat_lahir'] ?? '',
                    "tanggal_lahir" => $user['tanggal_lahir'] ?? '',
                    "status_perkawinan" => $user['status_perkawinan'] ?? '',
                    "pekerjaan" => $user['pekerjaan'] ?? '',
                    "kewarganegaraan" => $user['kewarganegaraan'] ?? '',
                    "alamat_lengkap" => $user['alamat_lengkap'] ?? '',
                    "rt_rw" => $user['rt_rw'] ?? '',
                    "provinsi" => $provinsiFinal,
                    "kota" => $kotaFinal,
                    "kecamatan" => $kecamatanFinal,
                    "kelurahan_desa" => $desaFinal,
                    "provinsi_code" => $user['provinsi'] ?? '',
                    "kota_code" => $user['kota'] ?? '',
                    "kecamatan_code" => $user['kecamatan'] ?? '',
                    "kelurahan_desa_code" => $user['kelurahan_desa'] ?? '',
                    "no_hp" => $noHpFinal,
                    "foto_profil" => get_foto_profil_url($user['foto_profil'] ?? ''),
                    "status" => $user['status'] ?? 'inactive'
                ],
                "token" => "token_dummy_testing"
            ]
        ]);
    } else {
        api_error("Password salah");
    }
} else {
    api_error("Akun tidak ditemukan atau Anda bukan Warga");
}

$stmt->close();
$conn->close();