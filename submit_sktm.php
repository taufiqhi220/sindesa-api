<?php
// ==================================================
// SUBMIT PENGAJUAN SKTM (Surat Keterangan Tidak Mampu)
// ==================================================
require_once 'api_bootstrap.php';
require_once 'db_config.php';
require_once 'upload_helper.php';
require_once 'merge_helper.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

// Ambil data dari Android
$nik = $_POST['nik'] ?? '';

if (empty($nik)) {
    api_error("NIK tidak boleh kosong");
}

// 1. Upload berkas
$upload_dir = get_upload_dir('pengajuan');

// 2. Cari user_id berdasarkan NIK
$nik_safe = mysqli_real_escape_string($conn, $nik);
$res_user = mysqli_query($conn, "SELECT id FROM users WHERE nik = '$nik_safe' LIMIT 1");

if ($res_user && $user = mysqli_fetch_assoc($res_user)) {
    $user_id = (int)$user['id'];
    $token = strtoupper(bin2hex(random_bytes(8)));
    $keperluan_input = $_POST['keperluan'] ?? '';

    // Buat form_data array sesuai blade view (tidak-mampu.blade.php)
    // Key harus SAMA PERSIS dengan name="" di blade
    $form_data = [
        // Identitas Pemohon
        'nik' => $nik,
        'nama' => $_POST['nama'] ?? '',
        'tempat_lahir' => $_POST['tempat_lahir'] ?? '',
        'tanggal_lahir' => $_POST['tanggal_lahir'] ?? '',
        'jenis_kelamin' => $_POST['jenis_kelamin'] ?? '',
        'agama' => $_POST['agama'] ?? '',
        'pekerjaan' => $_POST['pekerjaan'] ?? '',
        'alamat' => $_POST['alamat'] ?? '',
        // Identitas Kepala Keluarga (blade: no_kk, nik_kepala_keluarga, nama_kepala_keluarga)
        'no_kk' => $_POST['no_kk'] ?? $_POST['no_kk_kk'] ?? '',
        'nik_kepala_keluarga' => $_POST['nik_kepala_keluarga'] ?? $_POST['nik_kk'] ?? '',
        'nama_kepala_keluarga' => $_POST['nama_kepala_keluarga'] ?? $_POST['nama_kk'] ?? '',
        'tempat_lahir_kk' => $_POST['tempat_lahir_kk'] ?? '',
        'tanggal_lahir_kk' => $_POST['tanggal_lahir_kk'] ?? '',
        'jenis_kelamin_kk' => $_POST['jenis_kelamin_kk'] ?? '',
        'agama_kk' => $_POST['agama_kk'] ?? '',
        'pekerjaan_kk' => $_POST['pekerjaan_kk'] ?? '',
        'alamat_kk' => $_POST['alamat_kk'] ?? '',
        // Tujuan SKTM (blade: keperluan)
        'keperluan' => $keperluan_input,
    ];
        
    // --- BEGIN UNIVERSAL AUTOFILL & FILE INJECTOR ---
    $res_full_user = mysqli_query($conn, "SELECT * FROM users WHERE id = '$user_id' LIMIT 1");
    $full_user = mysqli_fetch_assoc($res_full_user);
    
    $uploaded_paths = process_all_uploads($upload_dir, $full_user['nik']);
    
    $edit_id = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
    
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
    $keperluan_escaped = mysqli_real_escape_string($conn, "Surat Keterangan Tidak Mampu" . ($keperluan_input ? " ($keperluan_input)" : ""));

    // 4. Simpan ke tabel pengajuan_surats dengan format yang benar
    if ($edit_id > 0) {
        $sql = "UPDATE pengajuan_surats SET 
                data_tambahan = '$data_tambahan_escaped',
                updated_at = NOW()
                WHERE id = '$edit_id' AND user_id = '$user_id'";
    } else {
        $sql = "INSERT INTO pengajuan_surats (user_id, jenis_surat, keperluan, token_verifikasi, status, data_tambahan, created_at, updated_at)
            VALUES ('$user_id', 'keterangan_tidak_mampu', '$keperluan_escaped', '$token', 'menunggu_verifikasi', '$data_tambahan_escaped', NOW(), NOW())";
    }

    if (mysqli_query($conn, $sql)) {
        api_response(["success" => true, "message" => "Pengajuan SKTM berhasil dikirim"]);
    } else {
        api_error("Gagal simpan: " . mysqli_error($conn), 500);
    }
} else {
    api_error("NIK tidak terdaftar sebagai warga");
}

mysqli_close($conn);
