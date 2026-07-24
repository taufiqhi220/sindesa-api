<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';

// 2. Tangkap semua data dari Android (19 Field)
$nama               = $_POST['nama'] ?? '';
$nik                = $_POST['nik'] ?? '';
$no_kk              = $_POST['no_kk'] ?? '';
$agama              = $_POST['agama'] ?? '';
$jenis_kelamin      = $_POST['jenis_kelamin'] ?? '';
$tempat_lahir       = $_POST['tempat_lahir'] ?? '';
$tanggal_lahir      = $_POST['tanggal_lahir'] ?? '';
$status_perkawinan  = $_POST['status_perkawinan'] ?? '';
$pekerjaan          = $_POST['pekerjaan'] ?? '';
$kewarganegaraan    = $_POST['kewarganegaraan'] ?? '';
$alamat_lengkap     = $_POST['alamat_lengkap'] ?? '';
$rt_rw              = $_POST['rt_rw'] ?? '';
$provinsi           = $_POST['provinsi'] ?? '';
$kota               = $_POST['kota'] ?? '';
$kecamatan          = $_POST['kecamatan'] ?? '';
$kelurahan_desa     = $_POST['kelurahan_desa'] ?? '';
$no_hp              = $_POST['no_hp'] ?? '';
$email              = $_POST['email'] ?? '';
$password           = $_POST['password'] ?? '';
$recaptcha_token    = $_POST['recaptcha_token'] ?? $_POST['g-recaptcha-response'] ?? '';

// 3. Validasi Dasar
if (empty($nik) || empty($nama) || empty($password) || empty($email)) {
    api_error("NIK, Nama, Email, dan Password wajib diisi");
}

// 3.1 Verifikasi Google reCAPTCHA v3 (jika secret key terkonfigurasi)
$recaptcha_secret = defined('RECAPTCHA_V3_SECRET') ? RECAPTCHA_V3_SECRET : '';
if (!empty($recaptcha_token) && strpos($recaptcha_token, 'mock_') !== 0 && !empty($recaptcha_secret)) {
    $verify_url = "https://www.google.com/recaptcha/api/siteverify";
    $post_data = http_build_query([
        'secret'   => $recaptcha_secret,
        'response' => $recaptcha_token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);

    $opts = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => $post_data,
            'timeout' => 5
        ]
    ];
    $context  = stream_context_create($opts);
    $verify_res = @file_get_contents($verify_url, false, $context);
    
    if ($verify_res !== false) {
        $result = json_decode($verify_res, true);
        if (empty($result['success']) || (isset($result['score']) && $result['score'] < 0.5)) {
            api_error("Verifikasi Captcha gagal. Terdeteksi aktivitas mencurigakan.", 400);
        }
    }
}

// 4. Cek apakah NIK atau Email sudah terdaftar
$cek = $conn->prepare("SELECT id FROM users WHERE nik = ? OR email = ?");
$cek->bind_param("ss", $nik, $email);
$cek->execute();
if ($cek->get_result()->num_rows > 0) {
    api_error("NIK atau Email sudah terdaftar");
}

// 5. Handle Upload Foto KTP
$foto_ktp_path = "";
if (isset($_FILES['foto_ktp']) && $_FILES['foto_ktp']['error'] == 0) {
    $target_dir = "uploads/ktp/";
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $file_ext = pathinfo($_FILES["foto_ktp"]["name"], PATHINFO_EXTENSION);
    $new_filename = "KTP_" . $nik . "_" . time() . "." . $file_ext;
    $target_file = $target_dir . $new_filename;

    if (move_uploaded_file($_FILES["foto_ktp"]["tmp_name"], $target_file)) {
        $foto_ktp_path = $target_file;
    } else {
        api_error("Gagal mengunggah foto KTP");
    }
} else {
    api_error("Foto KTP wajib diunggah");
}

// 6. Hash Password (Bcrypt)
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// 7. Simpan ke Database (Sesuaikan dengan kolom tabel 'users' Anda)
$query = "INSERT INTO users (
    name, email, password, role, nik, no_kk, agama, jenis_kelamin, 
    tempat_lahir, tanggal_lahir, status_perkawinan, pekerjaan, 
    kewarganegaraan, alamat_lengkap, rt_rw, provinsi, kota, 
    kecamatan, kelurahan_desa, no_hp, foto_ktp, created_at, updated_at
) VALUES (
    ?, ?, ?, 'warga', ?, ?, ?, ?, 
    ?, ?, ?, ?, 
    ?, ?, ?, ?, ?, 
    ?, ?, ?, ?, NOW(), NOW()
)";

$stmt = $conn->prepare($query);

// "ssssssssssssssssssss" = 20 string parameter (termasuk foto_ktp)
$stmt->bind_param(
    "ssssssssssssssssssss",
    $nama, $email, $hashed_password, $nik, $no_kk, $agama, $jenis_kelamin,
    $tempat_lahir, $tanggal_lahir, $status_perkawinan, $pekerjaan,
    $kewarganegaraan, $alamat_lengkap, $rt_rw, $provinsi, $kota,
    $kecamatan, $kelurahan_desa, $no_hp, $foto_ktp_path
);

if ($stmt->execute()) {
    api_response(["success" => true, "message" => "Registrasi Akun Warga Berhasil"]);
} else {
    api_error("Gagal menyimpan data: " . $conn->error, 500);
}

$stmt->close();
$conn->close();