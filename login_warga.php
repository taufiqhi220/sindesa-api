<?php
/**
 * =========================================================================================
 * SINDESA REST API — Endpoint Otentikasi Login Warga (login_warga.php)
 * =========================================================================================
 * 
 * FUNGSI UTAMA:
 * 1. Menerima input kredensial (NIK / Email dan Password) dari aplikasi Android.
 * 2. Memvalidasi kata sandi menggunakan hash standar industri Bcrypt (password_verify).
 * 3. Menghasilkan Bearer Token API dengan masa aktif 30 hari (generate_api_token).
 * 4. Mengembalikan biodata lengkap warga beserta Token otentikasi ke aplikasi mobile.
 * 
 * PENERAPAN SECURE BY DESIGN:
 * - Anti-SQL Injection: Menggunakan Prepared Statements (bind_param "ss").
 * - Password Security: Hash Bcrypt satu arah (Plain-text password ditolak keras).
 * - Role-Based Access Control (RBAC): Membatasi login HANYA untuk role='warga'.
 * - Stateful Token: Token dicatat di tabel api_tokens dan token lama otomatis dihapus.
 * =========================================================================================
 */

require_once 'api_bootstrap.php';
require_once 'db_config.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

// -----------------------------------------------------------------------------------------
// 1. SANITASI & VALIDASI INPUT DARI APLIKASI
// -----------------------------------------------------------------------------------------
$username_or_email = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($username_or_email) || empty($password)) {
    api_error("NIK/Email dan Password wajib diisi", 400);
}

// -----------------------------------------------------------------------------------------
// 2. QUERY MENCARI DATA WARGA (PREPARED STATEMENT & RBAC)
// -----------------------------------------------------------------------------------------
// Memastikan hanya role='warga' yang bisa login ke aplikasi mobile Sindesa
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

// -----------------------------------------------------------------------------------------
// 3. VERIFIKASI KATA SANDI (BCRYPT HASH VERIFICATION)
// -----------------------------------------------------------------------------------------
if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // Verifikasi password menggunakan Bcrypt standar keamanan Laravel
    if (password_verify($password, $user['password'])) {
        
        // ---------------------------------------------------------------------------------
        // 4. MEMBUAT TOKEN SESI BARU (30 HARI SLIDING EXPIRATION)
        // ---------------------------------------------------------------------------------
        $token = generate_api_token($conn, (int)$user['id']);
        
        if (empty($token)) {
            api_error("Gagal membuat sesi login. Silakan coba lagi.", 500);
        }
        
        // Format nomor telepon & nama wilayah
        $noHpFinal = !empty($user['no_hp']) ? $user['no_hp'] : ($user['phone'] ?? '');
        $provinsiFinal = !empty($user['prov_name']) ? $user['prov_name'] : ($user['provinsi'] ?? '');
        $kotaFinal = !empty($user['kota_name']) ? $user['kota_name'] : ($user['kota'] ?? '');
        $kecamatanFinal = !empty($user['kec_name']) ? $user['kec_name'] : ($user['kecamatan'] ?? '');
        $desaFinal = !empty($user['desa_name']) ? $user['desa_name'] : ($user['kelurahan_desa'] ?? '');

        // ---------------------------------------------------------------------------------
        // 5. KIRIM DATA RESPON LOGIN SUKSES
        // ---------------------------------------------------------------------------------
        api_response([
            "success" => true,
            "message" => "Login Berhasil",
            "data" => [
                "user" => [
                    "id"                 => (int)$user['id'],
                    "nama"               => $user['name'],
                    "nik"                => $user['nik'], 
                    "email"              => $user['email'],
                    "no_kk"              => $user['no_kk'] ?? '',
                    "agama"              => $user['agama'] ?? '',
                    "jenis_kelamin"      => $user['jenis_kelamin'] ?? '',
                    "tempat_lahir"       => $user['tempat_lahir'] ?? '',
                    "tanggal_lahir"      => $user['tanggal_lahir'] ?? '',
                    "status_perkawinan"  => $user['status_perkawinan'] ?? '',
                    "pekerjaan"          => $user['pekerjaan'] ?? '',
                    "kewarganegaraan"    => $user['kewarganegaraan'] ?? '',
                    "alamat_lengkap"     => $user['alamat_lengkap'] ?? '',
                    "rt_rw"              => $user['rt_rw'] ?? '',
                    "provinsi"           => $provinsiFinal,
                    "kota"               => $kotaFinal,
                    "kecamatan"          => $kecamatanFinal,
                    "kelurahan_desa"     => $desaFinal,
                    "provinsi_code"      => $user['provinsi'] ?? '',
                    "kota_code"          => $user['kota'] ?? '',
                    "kecamatan_code"     => $user['kecamatan'] ?? '',
                    "kelurahan_desa_code"=> $user['kelurahan_desa'] ?? '',
                    "no_hp"              => $noHpFinal,
                    "foto_profil"        => get_foto_profil_url($user['foto_profil'] ?? ''),
                    "status"             => $user['status'] ?? 'inactive'
                ],
                "token" => $token,
                "token_expires_in" => API_TOKEN_TTL_SECONDS
            ]
        ]);
    } else {
        api_error("Password salah", 401);
    }
} else {
    api_error("Akun tidak ditemukan atau Anda bukan Warga", 404);
}

$stmt->close();
$conn->close();