<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';

$nik = isset($_GET['nik']) ? mysqli_real_escape_string($conn, $_GET['nik']) : '';

if (empty($nik)) {
    api_error("NIK tidak ditemukan");
}

// Mapping jenis_surat snake_case ke nama yang user-friendly untuk Android
$namaJenisSurat = [
    'pengantar_akta_lahir'     => 'Pengantar Akta Lahir',
    'pengantar_ktp'            => 'Pengantar KTP',
    'pengantar_kk'             => 'Pengantar KK',
    'keterangan_kematian'      => 'Surat Keterangan Kematian',
    'keterangan_pindah'        => 'Surat Keterangan Pindah',
    'keterangan_domisili'      => 'Surat Keterangan Domisili',
    'keterangan_belum_menikah' => 'Surat Belum Menikah',
    'keterangan_janda_duda'    => 'Surat Keterangan Janda/Duda',
    'keterangan_beda_nama'     => 'Surat Beda Nama',
    'keterangan_kehilangan'    => 'Surat Keterangan Kehilangan',
    'pengantar_skck'           => 'Pengantar SKCK',
    'keterangan_usaha'         => 'Surat Keterangan Usaha',
    'izin_keramaian'           => 'Surat Izin Keramaian',
    'keterangan_tidak_mampu'   => 'SKTM (Tidak Mampu)',
    'keterangan_penghasilan'   => 'Surat Keterangan Penghasilan',
];

// Mapping status snake_case ke nama yang user-friendly untuk Android
$namaStatus = [
    'menunggu_verifikasi' => 'Menunggu Verifikasi',
    'diproses_kades'      => 'Diproses Kepala Desa',
    'selesai'             => 'Selesai',
    'ditolak'             => 'Ditolak',
];

// 1. Cari ID User berdasarkan NIK
$sql_user = "SELECT id FROM users WHERE nik = '$nik' LIMIT 1";
$res_user = mysqli_query($conn, $sql_user);

if ($res_user && mysqli_num_rows($res_user) > 0) {
    $user = mysqli_fetch_assoc($res_user);
    $user_id = $user['id'];

    // 2. Ambil Riwayat berdasarkan user_id (termasuk kolom baru)
    $user_id_safe = (int)$user_id;
    $sql = "SELECT id, jenis_surat, keperluan, status, nomor_surat, metode_ttd, pesan_penolakan, token_verifikasi, file_surat, created_at, updated_at FROM pengajuan_surats WHERE user_id = '$user_id_safe' ORDER BY id DESC";
    $result = mysqli_query($conn, $sql);
    
    if (!$result) {
        api_error("Error Query: " . mysqli_error($conn), 500);
    }

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $jenis = $row['jenis_surat'] ?? '';
        $status = $row['status'] ?? '';
        
        // Konversi ke nama yang user-friendly
        $jenisNama = $namaJenisSurat[$jenis] ?? ucwords(str_replace('_', ' ', $jenis));
        $statusNama = $namaStatus[$status] ?? ucwords(str_replace('_', ' ', $status));
        
        // Buat keterangan dari keperluan atau pesan_penolakan
        $keterangan = '';
        if ($status === 'ditolak' && !empty($row['pesan_penolakan'])) {
            $keterangan = 'Ditolak: ' . $row['pesan_penolakan'];
        } elseif (!empty($row['keperluan'])) {
            $keterangan = $row['keperluan'];
        }

        // Mapping metode_ttd untuk label yang user-friendly
        $metode_ttd = $row['metode_ttd'] ?? '';
        $labelTtd = '';
        if (!empty($metode_ttd)) {
            $ttdMap = [
                'digital'      => 'Tanda Tangan Digital',
                'konvensional' => 'Tanda Tangan Basah',
                'manual'       => 'Tanda Tangan Manual',
            ];
            $labelTtd = $ttdMap[$metode_ttd] ?? ucwords($metode_ttd);
        }

        $data[] = [
            "id" => (int)$row['id'],
            "jenis_surat" => $jenisNama,
            "jenis_surat_raw" => $jenis,
            "tanggal" => $row['created_at'] ?? '-',
            "status" => $statusNama,
            "status_raw" => $status,
            "nomor_surat" => $row['nomor_surat'] ?? '',
            "metode_ttd" => $metode_ttd,
            "metode_ttd_label" => $labelTtd,
            "keterangan" => $keterangan,
            "pesan_penolakan" => $row['pesan_penolakan'] ?? '',
            "token" => $row['token_verifikasi'] ?? '',
            "file_surat" => $row['file_surat'] ?? '',
            "updated_at" => $row['updated_at'] ?? '-'
        ];
    }
    
    $response = [
        "success" => true,
        "message" => "Ditemukan " . count($data) . " data",
        "data" => $data
    ];
} else {
    $response = [
        "success" => false,
        "message" => "Warga dengan NIK $nik belum terdaftar",
        "data" => []
    ];
}

mysqli_close($conn);
api_response($response);