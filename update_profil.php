<?php
/**
 * SINDESA API — Update Profil
 * Endpoint: POST /update_profil.php
 */
require_once 'api_bootstrap.php';
require_once 'db_config.php';
require_once 'upload_helper.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

$nik = $_POST['nik'] ?? $_POST['new_nik'] ?? '';
if (empty($nik)) {
    api_error("NIK tidak boleh kosong");
}

// 1. Ambil data user yang ada
$nik_safe = mysqli_real_escape_string($conn, $nik);
$res_user = mysqli_query($conn, "SELECT * FROM users WHERE nik = '$nik_safe' LIMIT 1");
if (!$res_user || mysqli_num_rows($res_user) == 0) {
    api_error("User dengan NIK $nik tidak ditemukan");
}
$user = mysqli_fetch_assoc($res_user);
$user_id = (int)$user['id'];

// 2. Daftar field teks yang akan diupdate secara dinamis (hanya yang dikirim dari aplikasi)
$fields = [];

if (isset($_POST['new_nik']) && !empty($_POST['new_nik'])) {
    $new_nik = mysqli_real_escape_string($conn, $_POST['new_nik']);
    $fields[] = "nik = '$new_nik'";
}
if (isset($_POST['no_kk']) && $_POST['no_kk'] !== '') {
    $no_kk = mysqli_real_escape_string($conn, $_POST['no_kk']);
    $fields[] = "no_kk = '$no_kk'";
}
if (isset($_POST['nama']) && $_POST['nama'] !== '') {
    $nama = mysqli_real_escape_string($conn, $_POST['nama']);
    $fields[] = "name = '$nama'";
} elseif (isset($_POST['name']) && $_POST['name'] !== '') {
    $nama = mysqli_real_escape_string($conn, $_POST['name']);
    $fields[] = "name = '$nama'";
}
if (isset($_POST['email']) && $_POST['email'] !== '') {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $fields[] = "email = '$email'";
}
if (isset($_POST['no_hp']) && $_POST['no_hp'] !== '') {
    $no_hp = mysqli_real_escape_string($conn, $_POST['no_hp']);
    $fields[] = "no_hp = '$no_hp'";
    $fields[] = "phone = '$no_hp'";
}
if (isset($_POST['tempat_lahir']) && $_POST['tempat_lahir'] !== '') {
    $tempat_lahir = mysqli_real_escape_string($conn, $_POST['tempat_lahir']);
    $fields[] = "tempat_lahir = '$tempat_lahir'";
}
if (isset($_POST['tanggal_lahir']) && $_POST['tanggal_lahir'] !== '') {
    $tanggal_lahir = mysqli_real_escape_string($conn, $_POST['tanggal_lahir']);
    $fields[] = "tanggal_lahir = '$tanggal_lahir'";
}
if (isset($_POST['jenis_kelamin']) && $_POST['jenis_kelamin'] !== '') {
    $jenis_kelamin = mysqli_real_escape_string($conn, $_POST['jenis_kelamin']);
    $fields[] = "jenis_kelamin = '$jenis_kelamin'";
}
if (isset($_POST['agama']) && $_POST['agama'] !== '') {
    $agama = mysqli_real_escape_string($conn, $_POST['agama']);
    $fields[] = "agama = '$agama'";
}
if (isset($_POST['status_perkawinan']) && $_POST['status_perkawinan'] !== '') {
    $status_perkawinan = mysqli_real_escape_string($conn, $_POST['status_perkawinan']);
    $fields[] = "status_perkawinan = '$status_perkawinan'";
}
if (isset($_POST['pekerjaan']) && $_POST['pekerjaan'] !== '') {
    $pekerjaan = mysqli_real_escape_string($conn, $_POST['pekerjaan']);
    $fields[] = "pekerjaan = '$pekerjaan'";
}
if (isset($_POST['kewarganegaraan']) && $_POST['kewarganegaraan'] !== '') {
    $kewarganegaraan = mysqli_real_escape_string($conn, $_POST['kewarganegaraan']);
    $fields[] = "kewarganegaraan = '$kewarganegaraan'";
}
if (isset($_POST['alamat_lengkap']) && $_POST['alamat_lengkap'] !== '') {
    $alamat_lengkap = mysqli_real_escape_string($conn, $_POST['alamat_lengkap']);
    $fields[] = "alamat_lengkap = '$alamat_lengkap'";
}
if (isset($_POST['rt_rw']) && $_POST['rt_rw'] !== '') {
    $rt_rw = mysqli_real_escape_string($conn, $_POST['rt_rw']);
    $fields[] = "rt_rw = '$rt_rw'";
}

// Handler Wilayah (Provinsi, Kota, Kecamatan, Desa)
if (isset($_POST['provinsi']) && $_POST['provinsi'] !== '') {
    $p = mysqli_real_escape_string($conn, $_POST['provinsi']);
    $res = mysqli_query($conn, "SELECT code FROM indonesia_provinces WHERE name = '$p' LIMIT 1");
    $code = ($res && mysqli_num_rows($res) > 0) ? mysqli_fetch_assoc($res)['code'] : $p;
    $fields[] = "provinsi = '$code'";
}
if (isset($_POST['kota']) && $_POST['kota'] !== '') {
    $c = mysqli_real_escape_string($conn, $_POST['kota']);
    $res = mysqli_query($conn, "SELECT code FROM indonesia_cities WHERE name = '$c' LIMIT 1");
    $code = ($res && mysqli_num_rows($res) > 0) ? mysqli_fetch_assoc($res)['code'] : $c;
    $fields[] = "kota = '$code'";
}
if (isset($_POST['kecamatan']) && $_POST['kecamatan'] !== '') {
    $d = mysqli_real_escape_string($conn, $_POST['kecamatan']);
    $res = mysqli_query($conn, "SELECT code FROM indonesia_districts WHERE name = '$d' LIMIT 1");
    $code = ($res && mysqli_num_rows($res) > 0) ? mysqli_fetch_assoc($res)['code'] : $d;
    $fields[] = "kecamatan = '$code'";
}
if (isset($_POST['kelurahan_desa']) && $_POST['kelurahan_desa'] !== '') {
    $v = mysqli_real_escape_string($conn, $_POST['kelurahan_desa']);
    $res = mysqli_query($conn, "SELECT code FROM indonesia_villages WHERE name = '$v' LIMIT 1");
    $code = ($res && mysqli_num_rows($res) > 0) ? mysqli_fetch_assoc($res)['code'] : $v;
    $fields[] = "kelurahan_desa = '$code'";
}

// Password update jika ada
if (isset($_POST['password']) && !empty($_POST['password'])) {
    $pass = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $fields[] = "password = '$pass'";
}

// 3. Process Foto Profil upload dari $_FILES (dukung berbagai nama key: foto_profil, foto, avatar, berkas_foto, image)
$file_key = null;
$possible_keys = ['foto_profil', 'foto', 'avatar', 'berkas_foto', 'image'];
foreach ($possible_keys as $k) {
    if (isset($_FILES[$k])) {
        if ($_FILES[$k]['error'] === UPLOAD_ERR_OK) {
            $file_key = $k;
            break;
        } elseif ($_FILES[$k]['error'] !== UPLOAD_ERR_NO_FILE) {
            $errCode = $_FILES[$k]['error'];
            $errMsg = "Gagal mengunggah foto profil (Error $errCode).";
            if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                $errMsg = "Ukuran foto profil terlalu besar (Max 5MB).";
            }
            api_error($errMsg, 400);
        }
    }
}

if ($file_key !== null) {
    $upload_dir = get_upload_dir('profil');
    $safe_nik = preg_replace('/[^0-9]/', '', $nik);
    $foto_path = process_upload($file_key, 'PROFIL', $safe_nik, $upload_dir, 'profil');
    if (!empty($foto_path)) {
        $fields[] = "foto_profil = '$foto_path'";
    }
}

if (empty($fields)) {
    api_response(["success" => true, "message" => "Tidak ada perubahan data", "foto_profil" => $user['foto_profil'] ?? '']);
}

$sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = '$user_id'";

if (mysqli_query($conn, $sql)) {
    $res_fresh = mysqli_query($conn, "SELECT foto_profil FROM users WHERE id = '$user_id' LIMIT 1");
    $fresh_user = mysqli_fetch_assoc($res_fresh);
    api_response([
        "success" => true,
        "message" => "Profil berhasil diperbarui",
        "foto_profil" => $fresh_user['foto_profil'] ?? ''
    ]);
} else {
    api_error("Gagal memperbarui profil: " . mysqli_error($conn), 500);
}

mysqli_close($conn);
