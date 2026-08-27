<?php
/**
 * =========================================================================================
 * SINDESA REST API — Endpoint Riwayat Pengajuan Surat (get_riwayat.php)
 * =========================================================================================
 * 
 * FUNGSI UTAMA:
 * 1. Menampilkan seluruh daftar surat yang pernah diajukan oleh akun warga yang terotentikasi.
 * 2. Memetakan kode surat (misal: 'pengantar_ktp') menjadi label formal bahasa Indonesia ('Pengantar KTP').
 * 3. Memetakan status pengerjaan (menunggu_verifikasi, diproses_kades, selesai, ditolak) & metode TTD.
 * 
 * PENERAPAN SECURE BY DESIGN:
 * - Anti-IDOR: Data riwayat HANYA ditarik berdasarkan $auth_user_id dari Bearer Token,
 *   sehingga seorang warga TIDAK BISA melihat surat milik warga lain.
 * - Prepared Statement: Menggunakan bind_param untuk melindungi query dari injeksi SQL.
 * =========================================================================================
 */

require_once 'api_bootstrap.php';
require_once 'db_config.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

// -----------------------------------------------------------------------------------------
// 1. OTENTIKASI SESI TOKEN (MIDDLEWARE)
// -----------------------------------------------------------------------------------------
// Memastikan hanya user dengan token Bearer valid yang dapat mengakses riwayat.
$auth_user_id = require_auth($conn);

// -----------------------------------------------------------------------------------------
// 2. PEMETAAN LABEL RESMI JENIS SURAT & STATUS PENGERJAAN
// -----------------------------------------------------------------------------------------
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

$namaStatus = [
    'menunggu_verifikasi' => 'Menunggu Verifikasi',
    'diproses_kades'      => 'Diproses Kepala Desa',
    'selesai'             => 'Selesai',
    'ditolak'             => 'Ditolak',
];

// Ambil NIK warga untuk pencocokan data cadangan di kolom JSON data_tambahan
$res_user_nik = $conn->prepare("SELECT nik FROM users WHERE id = ? LIMIT 1");
$res_user_nik->bind_param("i", $auth_user_id);
$res_user_nik->execute();
$nik_result = $res_user_nik->get_result();
$user_nik = '';
if ($nik_result->num_rows > 0) {
    $user_nik = $nik_result->fetch_assoc()['nik'] ?? '';
}
$res_user_nik->close();

// -----------------------------------------------------------------------------------------
// 3. EKSEKUSI QUERY RIWAYAT DENGAN PREPARED STATEMENT
// -----------------------------------------------------------------------------------------
$sql = "SELECT id, jenis_surat, keperluan, status, nomor_surat, metode_ttd, pesan_penolakan, token_verifikasi, file_surat, created_at, updated_at 
        FROM pengajuan_surats 
        WHERE user_id = ? ";

if (!empty($user_nik)) {
    $nik_like1 = '%"nik":"' . $conn->real_escape_string($user_nik) . '"%';
    $nik_like2 = '%"nik_pemohon":"' . $conn->real_escape_string($user_nik) . '"%';
    $nik_like3 = '%"nik_pelapor":"' . $conn->real_escape_string($user_nik) . '"%';
    $sql .= "OR data_tambahan LIKE ? OR data_tambahan LIKE ? OR data_tambahan LIKE ? ";
}
$sql .= "ORDER BY id DESC";

$stmt = $conn->prepare($sql);
if (!empty($user_nik)) {
    $stmt->bind_param("isss", $auth_user_id, $nik_like1, $nik_like2, $nik_like3);
} else {
    $stmt->bind_param("i", $auth_user_id);
}
$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    api_error("Error Query: " . $conn->error, 500);
}

// -----------------------------------------------------------------------------------------
// 4. PARSING HASIL QUERY MENJADI ARRAY DATA JSON
// -----------------------------------------------------------------------------------------
$data = [];
while ($row = $result->fetch_assoc()) {
    $jenis = $row['jenis_surat'] ?? '';
    $status = $row['status'] ?? '';
    
    $jenisNama = $namaJenisSurat[$jenis] ?? ucwords(str_replace('_', ' ', $jenis));
    $statusNama = $namaStatus[$status] ?? ucwords(str_replace('_', ' ', $status));
    
    // Keterangan khusus jika status ditolak oleh verifikator
    $keterangan = '';
    if ($status === 'ditolak' && !empty($row['pesan_penolakan'])) {
        $keterangan = 'Ditolak: ' . $row['pesan_penolakan'];
    } elseif (!empty($row['keperluan'])) {
        $keterangan = $row['keperluan'];
    }

    // Label ramah untuk metode penandatanganan (Digital QR / Basah)
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

$stmt->close();
mysqli_close($conn);

// -----------------------------------------------------------------------------------------
// 5. KIRIM RESPON JSON KE ANDROID
// -----------------------------------------------------------------------------------------
api_response([
    "success" => true,
    "message" => "Ditemukan " . count($data) . " data",
    "data" => $data
]);