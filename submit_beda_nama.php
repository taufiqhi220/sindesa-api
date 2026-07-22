<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';
require_once 'upload_helper.php';
require_once 'merge_helper.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

// NIK pengaju diutamakan dari user yang login (nik/nik_pemohon), baru fallback ke nik_dok1
$nik = trim($_POST['nik'] ?? $_POST['nik_pemohon'] ?? $_POST['nik_dok1'] ?? $_REQUEST['nik'] ?? '');
if (empty($nik)) {
    api_error("NIK tidak boleh kosong");
}

$upload_dir = get_upload_dir('pengajuan');

$nik_safe = mysqli_real_escape_string($conn, $nik);
$res_user = mysqli_query($conn, "SELECT id FROM users WHERE nik = '$nik_safe' LIMIT 1");
if ($res_user && $user = mysqli_fetch_assoc($res_user)) {
    $user_id = (int)$user['id'];
    $token = strtoupper(bin2hex(random_bytes(8)));
    $perbedaan = $_POST['data_berbeda'] ?? '';

    // Buat data_tambahan JSON sesuai format website
    $form_data = [
        'nik_dok1' => $nik,
        'nama_dok1' => $_POST['nama_dok1'] ?? '',
        'tempat_lahir_dok1' => $_POST['tempat_lahir_dok1'] ?? '',
        'tanggal_lahir_dok1' => $_POST['tanggal_lahir_dok1'] ?? '',
        'jenis_kelamin_dok1' => $_POST['jenis_kelamin_dok1'] ?? '',
        'alamat_dok1' => $_POST['alamat_dok1'] ?? '',
        'nama_dokumen2' => $_POST['nama_dokumen2'] ?? '',
        'nomor_dok2' => $_POST['nomor_dok2'] ?? '',
        'nama_dok2' => $_POST['nama_dok2'] ?? '',
        'tempat_lahir_dok2' => $_POST['tempat_lahir_dok2'] ?? '',
        'tanggal_lahir_dok2' => $_POST['tanggal_lahir_dok2'] ?? '',
        'jenis_kelamin_dok2' => $_POST['jenis_kelamin_dok2'] ?? '',
        'alamat_dok2' => $_POST['alamat_dok2'] ?? '',
        'data_berbeda' => $perbedaan,
        'acuan_kebenaran' => $_POST['acuan_kebenaran'] ?? '',
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
    $perbedaan_escaped = mysqli_real_escape_string($conn, $perbedaan);
    $keperluan = "Penyamaan Data Kependudukan ($perbedaan_escaped)";

    if ($edit_id > 0) {
        $sql = "UPDATE pengajuan_surats SET 
                data_tambahan = '$data_tambahan_escaped',
                updated_at = NOW()
                WHERE id = '$edit_id' AND user_id = '$user_id'";
    } else {
        $sql = "INSERT INTO pengajuan_surats (user_id, jenis_surat, keperluan, token_verifikasi, status, data_tambahan, created_at, updated_at)
            VALUES ('$user_id', 'keterangan_beda_nama', '$keperluan', '$token', 'menunggu_verifikasi', '$data_tambahan_escaped', NOW(), NOW())";
    }
    if (mysqli_query($conn, $sql)) {
        api_response(["success" => true, "message" => "Pengajuan Surat Beda Nama berhasil dikirim"]);
    } else {
        api_error("Gagal simpan: " . mysqli_error($conn), 500);
    }
} else {
    api_error("NIK tidak terdaftar sebagai warga");
}
mysqli_close($conn);
