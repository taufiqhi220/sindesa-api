<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';
require_once 'upload_helper.php';
require_once 'merge_helper.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

// Untuk surat kematian: NIK almarhum TIDAK perlu terdaftar sebagai warga.
// Yang harus terdaftar adalah NIK PELAPOR (orang yang melaporkan kematian).
$nik_almarhum = $_POST['nik_almarhum'] ?? '';
$nama_almarhum = $_POST['nama_almarhum'] ?? '';
$nik_pelapor = trim($_POST['nik'] ?? $_POST['nik_pelapor'] ?? $_POST['nik_pemohon'] ?? $_REQUEST['nik'] ?? '');

if (empty($nik_almarhum)) {
    api_error("NIK almarhum tidak boleh kosong");
}

// Jika nik_pelapor tidak dikirim dari form, coba ambil dari token/session
if (empty($nik_pelapor)) {
    // Fallback: cari dari header Authorization (Bearer token)
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? '';
    if (preg_match('/Bearer\s+(.+)/', $auth, $m)) {
        $token_val = mysqli_real_escape_string($conn, $m[1]);
        $res_tok = mysqli_query($conn, "SELECT nik FROM users WHERE remember_token = '$token_val' LIMIT 1");
        if ($res_tok && $row_tok = mysqli_fetch_assoc($res_tok)) {
            $nik_pelapor = $row_tok['nik'];
        }
    }
}

if (empty($nik_pelapor)) {
    api_error("NIK pelapor tidak boleh kosong. Pastikan Anda sudah login.");
}

$upload_dir = get_upload_dir('pengajuan');

// Cari user_id berdasarkan NIK PELAPOR (bukan almarhum)
$nik_pelapor_safe = mysqli_real_escape_string($conn, $nik_pelapor);
$res_user = mysqli_query($conn, "SELECT id FROM users WHERE nik = '$nik_pelapor_safe' LIMIT 1");
if ($res_user && $user = mysqli_fetch_assoc($res_user)) {
    $user_id = (int)$user['id'];
    $token = strtoupper(bin2hex(random_bytes(8)));

    // Buat data_tambahan JSON sesuai format website blade (kematian.blade.php)
    $form_data = [
        'nik_almarhum'              => $nik_almarhum,
        'kk_almarhum'               => $_POST['kk_almarhum'] ?? $_POST['no_kk_almarhum'] ?? '',
        'nama_almarhum'             => $nama_almarhum,
        'tempat_lahir_almarhum'     => $_POST['tempat_lahir_almarhum'] ?? '',
        'tanggal_lahir_almarhum'    => $_POST['tanggal_lahir_almarhum'] ?? '',
        'jenis_kelamin_almarhum'    => $_POST['jenis_kelamin_almarhum'] ?? '',
        'agama_almarhum'            => $_POST['agama_almarhum'] ?? '',
        'kewarganegaraan_almarhum'  => $_POST['kewarganegaraan_almarhum'] ?? 'Indonesia',
        'status_perkawinan_almarhum'=> $_POST['status_perkawinan_almarhum'] ?? '',
        'pekerjaan_almarhum'        => $_POST['pekerjaan_almarhum'] ?? '',
        'alamat_almarhum'           => $_POST['alamat_almarhum'] ?? '',
        // Keterangan Kematian (blade keys)
        'tanggal_kematian'          => $_POST['tanggal_kematian'] ?? $_POST['tanggal_wafat'] ?? '',
        'umur_kematian'             => $_POST['umur_kematian'] ?? $_POST['umur'] ?? '',
        'tempat_kematian'           => $_POST['tempat_kematian'] ?? '',
        'sebab_kematian'            => $_POST['sebab_kematian'] ?? '',
        // Data Pelapor
        'nama_pelapor'              => $_POST['nama_pelapor'] ?? '',
        'hubungan_pelapor'          => $_POST['hubungan_pelapor'] ?? '',
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
    
    // Untuk kematian: pelapor = user yang login, pemohon = user yang login
    $mega_merge['nik_pemohon']  = $full_user['nik'] ?? '';
    $mega_merge['nama_pemohon'] = $full_user['name'] ?? '';
    $mega_merge['nik_pelapor']  = $full_user['nik'] ?? '';
    $mega_merge['nama_pelapor'] = $_POST['nama_pelapor'] ?? $full_user['name'] ?? '';
    $data_tambahan = json_encode($mega_merge);
    // --- END UNIVERSAL AUTOFILL ---
    
    $data_tambahan_escaped = mysqli_real_escape_string($conn, $data_tambahan);
    $nama_alm_escaped = mysqli_real_escape_string($conn, $nama_almarhum);
    $keperluan = "Surat Keterangan Kematian (Alm. $nama_alm_escaped)";

    if ($edit_id > 0) {
        $sql = "UPDATE pengajuan_surats SET 
                data_tambahan = '$data_tambahan_escaped',
                updated_at = NOW()
                WHERE id = '$edit_id'";
    } else {
        $sql = "INSERT INTO pengajuan_surats (user_id, jenis_surat, keperluan, token_verifikasi, status, data_tambahan, created_at, updated_at)
            VALUES ('$user_id', 'keterangan_kematian', '$keperluan', '$token', 'menunggu_verifikasi', '$data_tambahan_escaped', NOW(), NOW())";
    }
    if (mysqli_query($conn, $sql)) {
        api_response(["success" => true, "message" => "Pengajuan Surat Kematian berhasil dikirim"]);
    } else {
        api_error("Gagal simpan: " . mysqli_error($conn), 500);
    }
} else {
    api_error("NIK pelapor tidak terdaftar sebagai warga. Pastikan Anda sudah login dengan akun yang terdaftar.");
}
mysqli_close($conn);
