<?php
// ==================================================
// SUBMIT PENGAJUAN KTP BARU / PERUBAHAN KTP
// ==================================================
require_once 'api_bootstrap.php';
require_once 'db_config.php';
require_once 'upload_helper.php';
require_once 'merge_helper.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

// Ambil data dari Android
$nik = trim($_POST['nik'] ?? $_POST['nik_pemohon'] ?? $_POST['nik_pelapor'] ?? $_REQUEST['nik'] ?? '');
$no_kk = $_POST['no_kk'] ?? '';
$nama = $_POST['nama'] ?? '';
$tempat_lahir = $_POST['tempat_lahir'] ?? '';
$tanggal_lahir = $_POST['tanggal_lahir'] ?? '';
$jenis_kelamin = $_POST['jenis_kelamin'] ?? '';
$agama = $_POST['agama'] ?? '';
$status_perkawinan = $_POST['status_perkawinan'] ?? '';
$pekerjaan = $_POST['pekerjaan'] ?? '';
$alamat = $_POST['alamat'] ?? '';

if (empty($nik)) {
    api_error("NIK tidak boleh kosong");
}

// 1. Upload berkas
$upload_dir = get_upload_dir('pengajuan');

// 2. Cari user_id berdasarkan NIK
$nik_safe = mysqli_real_escape_string($conn, $nik);
$sql_user = "SELECT id FROM users WHERE nik = '$nik_safe' LIMIT 1";
$res_user = mysqli_query($conn, $sql_user);

if ($res_user && mysqli_num_rows($res_user) > 0) {
    $user = mysqli_fetch_assoc($res_user);
    $user_id = (int)$user['id'];
    $token = strtoupper(bin2hex(random_bytes(8)));

    // 3. Buat data form (TANPA path file — path file diambil dari proses upload)
    $form_data = [
        'nik' => $nik,
        'no_kk' => $no_kk,
        'nama' => $nama,
        'tempat_lahir' => $tempat_lahir,
        'tanggal_lahir' => $tanggal_lahir,
        'jenis_kelamin' => $jenis_kelamin,
        'agama' => $agama,
        'status_perkawinan' => $status_perkawinan,
        'pekerjaan' => $pekerjaan,
        'alamat' => $alamat,
    ];
        
    // --- BEGIN UNIVERSAL AUTOFILL & FILE INJECTOR ---
    $res_full_user = mysqli_query($conn, "SELECT * FROM users WHERE id = '$user_id' LIMIT 1");
    $full_user = mysqli_fetch_assoc($res_full_user);
    
    // Proses semua file upload
    $uploaded_paths = process_all_uploads($upload_dir, $full_user['nik']);
    
    $edit_id = 0;
    if (!empty($_POST['edit_id'])) $edit_id = (int)$_POST['edit_id'];
    elseif (!empty($_POST['id'])) $edit_id = (int)$_POST['id'];
    elseif (!empty($_POST['id_pengajuan'])) $edit_id = (int)$_POST['id_pengajuan'];
    elseif (!empty($_REQUEST['edit_id'])) $edit_id = (int)$_REQUEST['edit_id'];
    elseif (!empty($_REQUEST['id'])) $edit_id = (int)$_REQUEST['id'];
    
    if ($edit_id > 0) {
        $res_old = mysqli_query($conn, "SELECT data_tambahan FROM pengajuan_surats WHERE id = '$edit_id' LIMIT 1");
        if ($res_old && mysqli_num_rows($res_old) > 0) {
            $old_data = json_decode(mysqli_fetch_assoc($res_old)['data_tambahan'], true) ?? [];
            foreach ($old_data as $key => $val) {
                if (strpos($key, "file_") === 0 || strpos($key, "berkas_") === 0 || strpos($key, "foto_") === 0) {
                    if (!isset($uploaded_paths[$key]) && !empty($val)) {
                        $uploaded_paths[$key] = $val;
                    }
                }
            }
        }
    }
    $mega_merge = mega_merge_data($full_user, $uploaded_paths, $form_data);
    inject_pemohon_pelapor($mega_merge, $full_user);

    $data_tambahan = json_encode($mega_merge);
    // --- END UNIVERSAL AUTOFILL ---
    
    $data_tambahan_escaped = mysqli_real_escape_string($conn, $data_tambahan);

    // 4. Simpan ke tabel pengajuan_surats dengan format yang benar
    if ($edit_id > 0) {
        $sql_riwayat = "UPDATE pengajuan_surats SET 
                data_tambahan = '$data_tambahan_escaped',
                updated_at = NOW()
                WHERE id = '$edit_id'";
    } else {
        $sql_riwayat = "INSERT INTO pengajuan_surats (user_id, jenis_surat, keperluan, token_verifikasi, status, data_tambahan, created_at, updated_at)
                    VALUES ('$user_id', 'pengantar_ktp', 'Pembuatan / Pembaharuan KTP', '$token', 'menunggu_verifikasi', '$data_tambahan_escaped', NOW(), NOW())";
    }

    if (mysqli_query($conn, $sql_riwayat)) {
        api_response(["success" => true, "message" => "Pengajuan KTP berhasil dikirim"]);
    } else {
        api_error("Gagal simpan ke database: " . mysqli_error($conn), 500);
    }
} else {
    api_error("NIK tidak terdaftar sebagai warga");
}

mysqli_close($conn);