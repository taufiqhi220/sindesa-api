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
$res_user = mysqli_query($conn, "SELECT id FROM users WHERE nik = '$nik_safe' LIMIT 1");
if ($res_user && $user = mysqli_fetch_assoc($res_user)) {
    $user_id = (int)$user['id'];
    $token = strtoupper(bin2hex(random_bytes(8)));
    $jenis_acara = $_POST['jenis_acara'] ?? '';
    $tanggal_mulai = $_POST['tanggal_mulai'] ?? '';
    $lokasi = $_POST['lokasi_acara'] ?? '';

    // Buat data_tambahan JSON sesuai format website blade
    $form_data = [
        // Identitas Penanggung Jawab (sesuai blade: nik_penanggung_jawab, nama_penanggung_jawab)
        'nik_penanggung_jawab' => $nik,
        'nama_penanggung_jawab' => $_POST['nama'] ?? '',
        'tempat_lahir' => $_POST['tempat_lahir'] ?? '',
        'tanggal_lahir' => $_POST['tanggal_lahir'] ?? '',
        'jenis_kelamin' => $_POST['jenis_kelamin'] ?? '',
        'agama' => $_POST['agama'] ?? '',
        'pekerjaan' => $_POST['pekerjaan'] ?? '',
        'alamat' => $_POST['alamat'] ?? '',
        // Rincian Acara Keramaian
        'jenis_acara' => $jenis_acara,
        'tanggal_mulai' => $tanggal_mulai,
        'tanggal_selesai' => $_POST['tanggal_selesai'] ?? '',
        'lokasi_acara' => $lokasi,
    ];
    
    // --- BEGIN UNIVERSAL AUTOFILL & FILE INJECTOR ---
    $res_full_user = mysqli_query($conn, "SELECT * FROM users WHERE id = '$user_id' LIMIT 1");
    $full_user = mysqli_fetch_assoc($res_full_user);
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
    $base_user = array_merge($full_user ?? [], $old_data ?? []);
    $mega_merge = mega_merge_data($base_user, $uploaded_paths, $form_data);
    inject_pemohon_pelapor($mega_merge, $full_user);
    $data_tambahan = json_encode($mega_merge);
    // --- END UNIVERSAL AUTOFILL ---
    
    $data_tambahan_escaped = mysqli_real_escape_string($conn, $data_tambahan);
    $jenis_acara_escaped = mysqli_real_escape_string($conn, $jenis_acara);
    $keperluan = "Izin Keramaian ($jenis_acara_escaped)";

    if ($edit_id > 0) {
        $sql = "UPDATE pengajuan_surats SET 
                data_tambahan = '$data_tambahan_escaped',
                updated_at = NOW()
                WHERE id = '$edit_id'";
    } else {
        $sql = "INSERT INTO pengajuan_surats (user_id, jenis_surat, keperluan, token_verifikasi, status, data_tambahan, created_at, updated_at)
            VALUES ('$user_id', 'izin_keramaian', '$keperluan', '$token', 'menunggu_verifikasi', '$data_tambahan_escaped', NOW(), NOW())";
    }
    if (mysqli_query($conn, $sql)) {
        api_response(["success" => true, "message" => "Pengajuan Surat Izin Keramaian berhasil dikirim"]);
    } else {
        api_error("Gagal simpan: " . mysqli_error($conn), 500);
    }
} else {
    api_error("NIK tidak terdaftar sebagai warga");
}
mysqli_close($conn);
