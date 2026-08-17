<?php
/**
 * SINDESA API — Secure Profile Photo Server
 * Endpoint: GET /foto_profil.php?file=xxx
 * 
 * Melayani foto profil warga secara langsung dan aman untuk aplikasi mobile,
 * tanpa terhalang oleh session cookie middleware di domain web utama.
 * 
 * SECURITY:
 * - Anti-Path Traversal (hanya melayani file di subfolder 'profil')
 * - Mencegah akses ke dokumen sensitif (KTP/KK/pengajuan diblokir)
 */
require_once 'api_bootstrap.php';
require_once 'upload_helper.php';

$file = $_GET['file'] ?? '';

if (empty($file)) {
    // Return default avatar jika parameter kosong
    output_default_avatar();
}

// 1. Bersihkan nama file dan cegah Path Traversal (../)
$filename = basename($file);
$cleanName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);

if (empty($cleanName)) {
    output_default_avatar();
}

// 2. Cari file di kandidat direktori storage server
$candidates = [
    "/home/sindesa/sindesa-app/storage/app/public/profil/",
    __DIR__ . "/../../storage/app/public/profil/",
    __DIR__ . "/../storage/app/public/profil/",
    __DIR__ . "/../../../storage/app/public/profil/",
    "D:/Programs/laragon/www/sindesa/storage/app/public/profil/",
    __DIR__ . "/uploads/profil/",
    __DIR__ . "/uploads/",
];

$filePath = null;
foreach ($candidates as $dir) {
    $target = rtrim($dir, '/') . '/' . $cleanName;
    if (file_exists($target) && is_file($target)) {
        $filePath = $target;
        break;
    }
}

// 3. Jika file fisik tidak ditemukan di subfolder profil, coba cari di root storage/app/public
if (!$filePath) {
    $altCandidates = [
        "/home/sindesa/sindesa-app/storage/app/public/" . ltrim($file, '/'),
        __DIR__ . "/../../storage/app/public/" . ltrim($file, '/'),
        __DIR__ . "/../storage/app/public/" . ltrim($file, '/'),
        "D:/Programs/laragon/www/sindesa/storage/app/public/" . ltrim($file, '/'),
    ];
    foreach ($altCandidates as $alt) {
        $real = realpath($alt);
        // Pastikan hanya melayani folder profil (bukan KTP/pengajuan)
        if ($real && is_file($real) && (strpos(str_replace('\\', '/', $real), '/profil/') !== false)) {
            $filePath = $real;
            break;
        }
    }
}

// 4. Jika tetap tidak ada, sajikan default avatar
if (!$filePath || !file_exists($filePath)) {
    output_default_avatar();
}

// 5. Sajikan gambar asli dengan Cache-Control dan Content-Type yang sesuai
$mime = mime_content_type($filePath) ?: 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: public, max-age=86400'); // Cache 24 jam
header('Access-Control-Allow-Origin: *');

readfile($filePath);
exit;

/**
 * Output default avatar SVG jika file belum ada
 */
function output_default_avatar() {
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    header('Access-Control-Allow-Origin: *');
    
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="128" height="128">
        <circle cx="64" cy="64" r="64" fill="#1a5e35"/>
        <circle cx="64" cy="48" r="24" fill="#ffffff" opacity="0.9"/>
        <path d="M64,78 C42,78 24,94 20,116 C32,124 47,128 64,128 C81,128 96,124 108,116 C104,94 86,78 64,78 Z" fill="#ffffff" opacity="0.9"/>
    </svg>';
    exit;
}
