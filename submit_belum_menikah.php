<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';
require_once 'upload_helper.php';
require_once 'merge_helper.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

$nik = $_POST['nik'] ?? '';
if (empty($nik)) {
    api_error("NIK tidak boleh kosong");
}

$upload_dir = get_upload_dir('pengajuan');

$nik_safe = mysqli_real_escape_string($conn, $nik);
$res_user = mysqli_query($conn, "SELECT id, name, nik, tempat_lahir, tanggal_lahir, jenis_kelamin, agama, pekerjaan, alamat_lengkap FROM users WHERE nik = '$nik_safe' LIMIT 1");
if ($res_user && $user = mysqli_fetch_assoc($res_user)) {
    $user_id = (int)$user['id'];
    $token = strtoupper(bin2hex(random_bytes(8)));

    // Buat data_tambahan JSON sesuai format website
    $form_data = [
        'nik_pemohon' => $user['nik'],
        'nama_pemohon' => $user['name'],
        'tempat_lahir_pemohon' => $user['tempat_lahir'],
        'tanggal_lahir_pemohon' => $user['tanggal_lahir'],
        'jenis_kelamin_pemohon' => $user['jenis_kelamin'],
        'agama_pemohon' => $user['agama'],
        'pekerjaan_pemohon' => $user['pekerjaan'],
        'alamat_pemohon' => $user['alamat_lengkap'],
        'nik_bapak' => $_POST['nik_bapak'] ?? '',
        'nama_bapak' => $_POST['nama_bapak'] ?? '',
        'tempat_lahir_bapak' => $_POST['tempat_lahir_bapak'] ?? '',
        'tanggal_lahir_bapak' => $_POST['tanggal_lahir_bapak'] ?? '',
        'agama_bapak' => $_POST['agama_bapak'] ?? '',
        'pekerjaan_bapak' => $_POST['pekerjaan_bapak'] ?? '',
        'alamat_bapak' => $_POST['alamat_bapak'] ?? '',
        'nik_ibu' => $_POST['nik_ibu'] ?? '',
        'nama_ibu' => $_POST['nama_ibu'] ?? '',
        'tempat_lahir_ibu' => $_POST['tempat_lahir_ibu'] ?? '',
        'tanggal_lahir_ibu' => $_POST['tanggal_lahir_ibu'] ?? '',
        'agama_ibu' => $_POST['agama_ibu'] ?? '',
        'pekerjaan_ibu' => $_POST['pekerjaan_ibu'] ?? '',
        'alamat_ibu' => $_POST['alamat_ibu'] ?? '',
    ];
    
    // --- BEGIN UNIVERSAL AUTOFILL & FILE INJECTOR ---
    $res_full_user = mysqli_query($conn, "SELECT * FROM users WHERE id = '$user_id' LIMIT 1");
    $full_user = mysqli_fetch_assoc($res_full_user);
    $uploaded_paths = process_all_uploads($upload_dir, $full_user['nik']);
    $edit_id = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
    
    // Jika sedang edit, ambil file path yang lama agar tidak hilang jika tidak upload ulang
    if ($edit_id > 0) {
        $res_old = mysqli_query($conn, "SELECT data_tambahan FROM pengajuan_surats WHERE id = '$edit_id' LIMIT 1");
        if ($res_old && mysqli_num_rows($res_old) > 0) {
            $old_data = json_decode(mysqli_fetch_assoc($res_old)['data_tambahan'], true) ?? [];
            foreach ($old_data as $key => $val) {
                if (strpos($key, 'file_') === 0 || strpos($key, 'berkas_') === 0 || strpos($key, 'foto_') === 0) {
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

    if ($edit_id > 0) {
        $sql = "UPDATE pengajuan_surats SET 
                data_tambahan = '$data_tambahan_escaped',
                updated_at = NOW()
                WHERE id = '$edit_id' AND user_id = '$user_id'";
        if (mysqli_query($conn, $sql)) {
            api_response(["success" => true, "message" => "Pengajuan Surat Belum Menikah berhasil diperbarui"]);
        } else {
            api_error("Gagal memperbarui: " . mysqli_error($conn), 500);
        }
    } else {
        $sql = "INSERT INTO pengajuan_surats (user_id, jenis_surat, keperluan, token_verifikasi, status, data_tambahan, created_at, updated_at)
                VALUES ('$user_id', 'keterangan_belum_menikah', 'Surat Keterangan Belum Pernah Menikah', '$token', 'menunggu_verifikasi', '$data_tambahan_escaped', NOW(), NOW())";
        if (mysqli_query($conn, $sql)) {
            api_response(["success" => true, "message" => "Pengajuan Surat Belum Menikah berhasil dikirim"]);
        } else {
            api_error("Gagal simpan: " . mysqli_error($conn), 500);
        }
    }
} else {
    api_error("NIK tidak terdaftar sebagai warga");
}
mysqli_close($conn);
