<?php
/**
 * cetak_surat.php - Endpoint untuk generate PDF surat dari mobile app
 * 
 * Endpoint ini memanggil route Laravel cetakPdf secara internal
 * Cara kerja: Redirect ke URL Laravel yang menghasilkan PDF stream
 * 
 * Mobile app akan membuka URL ini di browser untuk download/view PDF
 * SECURITY: Memerlukan token auth + ownership check
 */
require_once 'api_bootstrap.php';
require_once 'db_config.php';

if (!$conn) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["success" => false, "message" => "Koneksi database gagal"]);
    exit;
}

// Autentikasi wajib
$auth_user_id = require_auth($conn);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id === 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["success" => false, "message" => "ID Pengajuan tidak valid"]);
    exit;
}

// Verifikasi bahwa surat sudah berstatus selesai + ownership check
$stmt = $conn->prepare("SELECT id, status, jenis_surat, nomor_surat FROM pengajuan_surats WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param("ii", $id, $auth_user_id);
$stmt->execute();
$result = $stmt->get_result();

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

$dataTambahan = $surat->data_tambahan;
if (is_string($dataTambahan)) {
    $dataTambahan = json_decode($dataTambahan, true) ?? [];
}

if (isset($dataTambahan['kades_snapshot']) && is_array($dataTambahan['kades_snapshot'])) {
    $kades = (object) $dataTambahan['kades_snapshot'];
} else {
    $kades = App\Models\User::where('role', 'kades')->where('status', 'active')->first();
}

// Fallback: jika metode_ttd belum terekam di kolom utama, cek dari snapshot atau tentukan otomatis
if (empty($surat->metode_ttd)) {
    if (isset($dataTambahan['kades_snapshot']['metode_ttd'])) {
        $surat->metode_ttd = $dataTambahan['kades_snapshot']['metode_ttd'];
    } elseif (isset($kades->ttd_path) && !empty($kades->ttd_path)) {
        $surat->metode_ttd = 'konvensional';
    } else {
        $surat->metode_ttd = 'digital';
    }
}

// Cari dan encode file tanda tangan Kades ke base64 data URI (kebal terhadap symlink / permission bug)
if (isset($kades) && is_object($kades) && !empty($kades->ttd_path)) {
    $rawTtd = $kades->ttd_path;
    $cleanTtd = ltrim(str_replace(['storage/app/public/', 'public/storage/', 'storage/'], '', $rawTtd), '/');
    $filename = basename($cleanTtd);
    
    $searchPaths = [
        $laravelPath . '/storage/app/public/' . $cleanTtd,
        $laravelPath . '/storage/app/public/ttd/' . $filename,
        $laravelPath . '/storage/app/public/ttd_kades/' . $filename,
        $laravelPath . '/public/storage/' . $cleanTtd,
        $laravelPath . '/public/storage/ttd/' . $filename,
        $laravelPath . '/public/storage/ttd_kades/' . $filename,
        '/home/sindesa/sindesa-app/storage/app/public/' . $cleanTtd,
        '/home/sindesa/sindesa-app/storage/app/public/ttd/' . $filename,
        '/home/sindesa/sindesa-app/storage/app/public/ttd_kades/' . $filename,
        __DIR__ . '/../../storage/app/public/ttd/' . $filename,
        __DIR__ . '/../storage/app/public/ttd/' . $filename,
    ];
    
    foreach ($searchPaths as $path) {
        if (file_exists($path) && is_file($path)) {
            $mime = mime_content_type($path) ?: 'image/png';
            $kades->ttd_base64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
            break;
        }
    }
}

// Smart Scan: Jika ttd_base64 masih kosong, scan folder spesimen ttd kades apapun yang ada di storage server
if (isset($kades) && is_object($kades) && empty($kades->ttd_base64)) {
    $scanDirs = [
        $laravelPath . '/storage/app/public/ttd_kades',
        $laravelPath . '/storage/app/public/ttd',
        '/home/sindesa/sindesa-app/storage/app/public/ttd_kades',
        '/home/sindesa/sindesa-app/storage/app/public/ttd',
        $laravelPath . '/public/storage/ttd_kades',
        $laravelPath . '/public/storage/ttd',
    ];
    foreach ($scanDirs as $dir) {
        if (is_dir($dir)) {
            $files = @scandir($dir);
            if ($files) {
                foreach ($files as $f) {
                    if ($f !== '.' && $f !== '..' && !str_starts_with($f, '.')) {
                        $fPath = $dir . '/' . $f;
                        if (is_file($fPath)) {
                            $mime = mime_content_type($fPath) ?: 'image/png';
                            $kades->ttd_base64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fPath));
                            $kades->ttd_path = basename($dir) . '/' . $f;
                            break 2;
                        }
                    }
                }
            }
        }
    }
}

$viewName = str_replace(['pengantar_', 'keterangan_', '_'], ['', '', '-'], $surat->jenis_surat);
$viewPath = 'pdf.' . $viewName;

if (!view()->exists($viewPath)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["success" => false, "message" => "Template PDF belum tersedia untuk jenis surat ini"]);
    exit;
}

$pdf = Barryvdh\DomPDF\Facade\Pdf::loadView($viewPath, compact('surat', 'pengaturan', 'kades'))
    ->setPaper('a4', 'portrait')
    ->setOption('isRemoteEnabled', true)
    ->setOption('isHtml5ParserEnabled', true);
$namaFileAman = str_replace(['/', '\\'], '-', $surat->nomor_surat ?? 'Surat');

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Surat_' . $namaFileAman . '.pdf"');
echo $pdf->output();
exit;
