<?php
/**
 * SINDESA API — Get Detail Pengajuan
 * Endpoint: GET /get_detail_pengajuan.php?id=xxx
 * 
 * SECURITY: Memerlukan token autentikasi + ownership check (IDOR protection).
 */
require_once 'api_bootstrap.php';
require_once 'db_config.php';

if (!$conn) {
    api_error("Koneksi database gagal", 500);
}

// Autentikasi wajib
$auth_user_id = require_auth($conn);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id === 0) {
    api_error("ID Pengajuan tidak valid");
}

// Gunakan prepared statement + ownership check
$stmt = $conn->prepare("SELECT * FROM pengajuan_surats WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param("ii", $id, $auth_user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    // Parse data_tambahan from JSON string
    $data_tambahan = json_decode($row['data_tambahan'], true);
    if (is_array($data_tambahan)) {
        unset($data_tambahan['id']);
        unset($data_tambahan['password']);
        unset($data_tambahan['remember_token']);
        unset($data_tambahan['role']);
    }
    
    api_response([
        "success" => true,
        "message" => "Detail pengajuan berhasil diambil",
        "data" => [
            "id" => (int)$row['id'],
            "user_id" => (int)$row['user_id'],
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
    api_error("Pengajuan tidak ditemukan atau Anda tidak memiliki akses");
}

$stmt->close();
mysqli_close($conn);
