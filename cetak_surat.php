<?php
/**
 * cetak_surat.php - Endpoint untuk generate PDF surat dari mobile app
 * 
 * Endpoint ini memanggil route Laravel cetakPdf secara internal
 * Cara kerja: Redirect ke URL Laravel yang menghasilkan PDF stream
 * 
 * Mobile app akan membuka URL ini di browser untuk download/view PDF
 */
error_reporting(0);
ini_set('display_errors', 0);

// CORS headers (cetak_surat output PDF, bukan JSON, jadi tidak pakai api_bootstrap.php sepenuhnya)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id === 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["success" => false, "message" => "ID Pengajuan tidak valid"]);
    exit;
}

// Verifikasi bahwa surat sudah berstatus selesai
require_once 'db_config.php';
if (!$conn) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["success" => false, "message" => "Koneksi database gagal"]);
    exit;
}

$sql = "SELECT id, status, jenis_surat, nomor_surat FROM pengajuan_surats WHERE id = $id LIMIT 1";
$result = mysqli_query($conn, $sql);

if (!$result || mysqli_num_rows($result) === 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["success" => false, "message" => "Surat tidak ditemukan"]);
    mysqli_close($conn);
    exit;
}

$row = mysqli_fetch_assoc($result);

if ($row['status'] !== 'selesai') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["success" => false, "message" => "Surat belum selesai diproses"]);
    mysqli_close($conn);
    exit;
}

mysqli_close($conn);

// Redirect ke route Laravel untuk cetak PDF
// Route: /warga/surat/{id}/cetak (via WargaSuratController@cetakPdf)
// Karena ini dari mobile tanpa session Laravel, kita generate PDF langsung

// Load Laravel bootstrap (Cari folder laravel otomatis di ../.., .., ../sindesa-app, dll)
$laravelCandidates = [
    realpath(__DIR__ . '/../..'),             // Jika API di sindesa-app/public/api.domain.com/
    realpath(__DIR__ . '/..'),                // Jika API di sindesa-app/public_api/
    realpath(__DIR__ . '/../sindesa-app'),
    realpath(__DIR__ . '/../sindesa'),
    '/home/sindesa/sindesa-app'
];

$laravelPath = null;
foreach ($laravelCandidates as $cand) {
    if ($cand && file_exists($cand . '/vendor/autoload.php')) {
        $laravelPath = $cand;
        break;
    }
}

if (!$laravelPath) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["success" => false, "message" => "Laravel path not found"]);
    exit;
}

require $laravelPath . '/vendor/autoload.php';
$app = require_once $laravelPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Boot the application
$kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

// PENTING: Set public_path() agar mengarah ke folder public Laravel yang benar
// Tanpa ini, public_path() bisa salah saat dipanggil dari luar sindesa/public/
$app->bind('path.public', function() use ($laravelPath) {
    return $laravelPath . '/public';
});

// Generate PDF using the same logic as KadesDashboard@cetakPdf
$surat = App\Models\PengajuanSurat::with('user')->findOrFail($id);
$pengaturan = App\Models\PengaturanSurat::first();

$dataTambahan = $surat->data_tambahan ?? [];
if (isset($dataTambahan['kades_snapshot'])) {
    $kades = (object) $dataTambahan['kades_snapshot'];
} else {
    $kades = App\Models\User::where('role', 'kades')->where('status', 'active')->first();
}

$viewName = str_replace(['pengantar_', 'keterangan_', '_'], ['', '', '-'], $surat->jenis_surat);
$viewPath = 'pdf.' . $viewName;

if (!view()->exists($viewPath)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["success" => false, "message" => "Template PDF belum tersedia untuk jenis surat ini"]);
    exit;
}

$pdf = Barryvdh\DomPDF\Facade\Pdf::loadView($viewPath, compact('surat', 'pengaturan', 'kades'));
$namaFileAman = str_replace(['/', '\\'], '-', $surat->nomor_surat ?? 'Surat');

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Surat_' . $namaFileAman . '.pdf"');
echo $pdf->output();
exit;
