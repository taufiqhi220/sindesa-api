<?php
/**
 * MEGA MERGE HELPER
 * 
 * Menggabungkan data user, file upload, dan data form dengan urutan BENAR:
 * - $full_user (data dasar dari DB)
 * - $existing_data (data form dari Android)
 * - $uploaded_paths (path file upload) ← HARUS TERAKHIR agar tidak ditimpa
 * 
 * BUG SEBELUMNYA: array_merge($full_user, $uploaded_paths, $existing_data)
 *   → $existing_data berisi key file_ dengan nilai null (karena tidak diupload dari form)
 *   → null dari $existing_data menimpa path file yang sudah benar di $uploaded_paths
 * 
 * FIX: Letakkan $uploaded_paths TERAKHIR agar selalu menang
 */
function mega_merge_data($full_user, $uploaded_paths, $existing_data_arr) {
    // Mulai dari data user
    $merged = $full_user ?? [];
    
    // Tambah data form (bisa timpa data user)
    // PENTING: array (termasuk array kosong []) selalu dipertahankan karena bisa berupa
    // data terstruktur seperti anggota_keluarga, alamat_asal, alamat_tujuan, dll.
    foreach ($existing_data_arr as $k => $v) {
        // FIX: Cek is_array DULU sebelum cek null/empty,
        // karena array kosong [] lolos is_array tapi juga lolos empty()
        if (is_array($v)) {
            // Selalu simpan array (meski kosong), karena array adalah data terstruktur yang valid
            $merged[$k] = $v;
        } elseif ($v !== null && $v !== '') {
            $merged[$k] = $v;
        }
    }
    
    // Tambah path file TERAKHIR (tidak boleh ditimpa apapun)
    foreach ($uploaded_paths as $k => $v) {
        if ($v !== null && $v !== '') {
            $merged[$k] = $v;
        }
    }
    
    return $merged;
}

/**
 * Inject keys pemohon & pelapor dari data user
 */
function inject_pemohon_pelapor(&$merged, $full_user) {
    $merged['nik_pemohon']           = $full_user['nik'] ?? '';
    $merged['nama_pemohon']          = $full_user['name'] ?? '';
    $merged['tempat_lahir_pemohon']  = $full_user['tempat_lahir'] ?? '';
    $merged['tanggal_lahir_pemohon'] = $full_user['tanggal_lahir'] ?? '';
    $merged['jenis_kelamin_pemohon'] = $full_user['jenis_kelamin'] ?? '';
    $merged['agama_pemohon']         = $full_user['agama'] ?? '';
    $merged['pekerjaan_pemohon']     = $full_user['pekerjaan'] ?? '';
    $merged['alamat_pemohon']        = $full_user['alamat_lengkap'] ?? '';
    
    $merged['nik_pelapor']           = $full_user['nik'] ?? '';
    $merged['nama_pelapor']          = $full_user['name'] ?? '';
    $merged['tempat_lahir_pelapor']  = $full_user['tempat_lahir'] ?? '';
    $merged['tanggal_lahir_pelapor'] = $full_user['tanggal_lahir'] ?? '';
    $merged['jenis_kelamin_pelapor'] = $full_user['jenis_kelamin'] ?? '';
    $merged['agama_pelapor']         = $full_user['agama'] ?? '';
    $merged['pekerjaan_pelapor']     = $full_user['pekerjaan'] ?? '';
    $merged['alamat_pelapor']        = $full_user['alamat_lengkap'] ?? '';
}

/**
 * Mapping khusus: nama field berkas → nama key yang dipakai di blade view
 * Ini penting agar key yang disimpan di DB sesuai dengan yang dibaca blade
 */
function get_blade_key($berkas_key) {
    $map = [
        // Usaha
        'berkas_usaha'          => 'file_foto_usaha',
        'berkas_foto_usaha'     => 'file_foto_usaha',
        // KTP
        'berkas_ktp_lama'       => 'file_ktp_lama',
        'berkas_ktp_ortu'       => 'file_ktp_ortu',
        'berkas_ktp_almarhum'   => 'file_ktp_almarhum',
        'berkas_ktp_pelapor'    => 'file_ktp_pelapor',
        // KK
        'berkas_kk_lama'        => 'file_kk_lama',
        'berkas_kk_almarhum'    => 'file_kk_almarhum',
        // Pernikahan & keluarga
        'berkas_nikah'          => 'file_buku_nikah',
        'berkas_buku_nikah'     => 'file_buku_nikah',
        // SKTM
        'berkas_dusun'          => 'file_pengantar',
        // Penghasilan
        'berkas_kk_ktp'         => 'file_kk',
        'berkas_anak'           => 'file_anak',
        // Beda Nama
        'berkas_dok1'           => 'file_dok1',
        'berkas_dok2'           => 'file_dok2',
        // Janda/Duda, Kehilangan, dll
        'berkas_bukti'          => 'file_bukti',
        // RS/Kematian (blade pakai file_keterangan_rs)
        'berkas_rs'             => 'file_keterangan_rs',
        // Akta Lahir
        'berkas_saksi'          => 'file_saksi',
        // Izin Keramaian
        'berkas_pengantar'      => 'file_pengantar',
        // Belum menikah
        'berkas_ortu'           => 'file_ktp_ortu',
        // Domisili, Pindah, KK dll (lain)
        'berkas_lain'           => 'file_lain',
        // Foto umum
        'berkas_foto'           => 'file_foto',
    ];
    // Jika ada di map, kembalikan mapping khusus
    // Jika tidak, otomatis: berkas_xxx -> file_xxx
    return $map[$berkas_key] ?? str_replace('berkas_', 'file_', $berkas_key);
}

/**
 * Proses semua file yang diupload dalam $_FILES
 * Mengembalikan array [blade_key => path, berkas_key => path]
 */
function process_all_uploads($upload_dir, $nik) {
    // Sanitasi NIK: hapus karakter selain angka untuk keamanan path
    $safe_nik = preg_replace('/[^0-9]/', '', $nik);
    if (empty($safe_nik)) $safe_nik = 'unknown';
    
    $uploaded_paths = [];
    foreach ($_FILES as $key => $file_data) {
        if (isset($file_data['error']) && $file_data['error'] == 0) {
            // Dapatkan blade_key yang sesuai dengan template
            $blade_key = get_blade_key($key);
            
            // Buat prefix dari nama field (hapus berkas_, ubah ke uppercase, hapus underscore)
            $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper(str_replace('berkas_', '', $key))));
            if (empty($prefix)) $prefix = 'FILE';
            
            $path = process_upload($key, $prefix, $safe_nik, $upload_dir);
            if ($path !== "") {
                $uploaded_paths[$blade_key] = $path;  // key sesuai blade (misal: file_foto_usaha)
                $uploaded_paths[$key] = $path;         // key asli (misal: berkas_usaha)
                
                // Alias tambahan untuk kompatibilitas Form Edit Laravel Web & PDF
                if ($key === 'berkas_rs' || $blade_key === 'file_keterangan_rs') {
                    $uploaded_paths['file_rs'] = $path;
                    $uploaded_paths['file_keterangan_rs'] = $path;
                }
                if ($key === 'berkas_nikah' || $key === 'berkas_buku_nikah' || $blade_key === 'file_buku_nikah') {
                    $uploaded_paths['file_nikah'] = $path;
                    $uploaded_paths['file_buku_nikah'] = $path;
                }
            }
        }
    }
    return $uploaded_paths;
}
