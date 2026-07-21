<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id === 0) {
    api_error("ID Pengajuan tidak valid");
}

$sql = "SELECT * FROM pengajuan_surats WHERE id = $id LIMIT 1";
$result = mysqli_query($conn, $sql);

if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    // Parse data_tambahan from JSON string
    $data_tambahan = json_decode($row['data_tambahan'], true);
    
    api_response([
        "success" => true,
        "message" => "Detail pengajuan berhasil diambil",
        "data" => [
            "id" => $row['id'],
            "user_id" => $row['user_id'],
            "jenis_surat" => $row['jenis_surat'],
            "status" => $row['status'],
            "nomor_surat" => $row['nomor_surat'] ?? '',
            "metode_ttd" => $row['metode_ttd'] ?? '',
            "pesan_penolakan" => $row['pesan_penolakan'] ?? '',
            "keterangan_operator" => $row['keterangan_operator'] ?? '',
            "token_verifikasi" => $row['token_verifikasi'] ?? '',
            "file_surat" => $row['file_surat'] ?? '',
            "data_tambahan" => $data_tambahan
        ]
    ]);
} else {
    api_error("Pengajuan tidak ditemukan");
}

mysqli_close($conn);
