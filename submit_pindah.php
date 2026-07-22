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
    // Proses teks anggota keluarga yang ikut pindah
    // User mengisi sebagai teks bebas (satu baris = satu anggota)
    $keluarga_ikut_raw = trim($_POST['keluarga_ikut'] ?? '');
    
    // Coba parse sebagai JSON array (jika Android mengirim JSON di masa depan)
    $anggota_keluarga_arr = json_decode($keluarga_ikut_raw, true);
    if (!is_array($anggota_keluarga_arr)) {
        // Bukan JSON → simpan sebagai teks biasa dan konversi ke array sederhana
        $anggota_keluarga_arr = [];
        if (!empty($keluarga_ikut_raw)) {
            // Pecah per baris atau per koma
            $lines = preg_split('/[\r\n,]+/', $keluarga_ikut_raw);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $anggota_keluarga_arr[] = ['nama' => $line, 'nik' => '', 'jenis_kelamin' => '', 'tanggal_lahir' => '', 'status_perkawinan' => '', 'keterangan' => ''];
                }
            }
        }
    }

    $form_data = [
        'nik' => $_POST['nik'] ?? '',
        'no_kk' => $_POST['no_kk'] ?? '',
        'nama' => $_POST['nama'] ?? '',
        'tempat_lahir' => $_POST['tempat_lahir'] ?? '',
        'tanggal_lahir' => $_POST['tanggal_lahir'] ?? '',
        'jenis_kelamin' => $_POST['jenis_kelamin'] ?? '',
        'agama' => $_POST['agama'] ?? '',
        'status_perkawinan' => $_POST['status_perkawinan'] ?? '',
        'pekerjaan' => $_POST['pekerjaan'] ?? '',
        'pendidikan' => $_POST['pendidikan'] ?? '',
        'alamat_asal' => [
            'dusun' => $_POST['dusun_asal'] ?? '',
            'rt' => $_POST['rt_asal'] ?? '',
            'rw' => $_POST['rw_asal'] ?? '',
        ],
        'alamat_tujuan' => [
            'jalan' => $_POST['alamat_tujuan'] ?? '',
            'rt' => $_POST['rt_tujuan'] ?? '',
            'rw' => $_POST['rw_tujuan'] ?? '',
            'desa' => $_POST['desa_tujuan'] ?? '',
            'kecamatan' => $_POST['kec_tujuan'] ?? '',
            'kabupaten' => $_POST['kab_tujuan'] ?? '',
            'provinsi' => $_POST['prov_tujuan'] ?? '',
            'kode_pos' => $_POST['pos_tujuan'] ?? '',
        ],
        'alasan_pindah'         => $_POST['alasan_pindah'] ?? '',
        'tanggal_pindah'        => $_POST['tanggal_pindah'] ?? '',
        'anggota_keluarga'      => $anggota_keluarga_arr,  // Array terstruktur
        'anggota_keluarga_teks' => $keluarga_ikut_raw,     // Teks asli user (fallback)
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
    
    $old_data = [];
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
    $kab_tujuan = mysqli_real_escape_string($conn, $_POST['kab_tujuan'] ?? '');
    $keperluan = mysqli_real_escape_string($conn, "Pindah ke $kab_tujuan");
    
    if ($edit_id > 0) {
        $sql = "UPDATE pengajuan_surats SET 
                data_tambahan = '$data_tambahan_escaped',
                updated_at = NOW()
                WHERE id = '$edit_id'";
    } else {
        $sql = "INSERT INTO pengajuan_surats (user_id, jenis_surat, keperluan, token_verifikasi, status, data_tambahan, created_at, updated_at)
            VALUES ('$user_id', 'keterangan_pindah', '$keperluan', '$token', 'menunggu_verifikasi', '$data_tambahan_escaped', NOW(), NOW())";
    }
    if (mysqli_query($conn, $sql)) {
        api_response(["success" => true, "message" => "Pengajuan Surat Pindah berhasil dikirim"]);
    } else {
        api_error("Gagal simpan: " . mysqli_error($conn), 500);
    }
} else {
    api_error("NIK tidak terdaftar sebagai warga");
}
mysqli_close($conn);
