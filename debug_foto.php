<?php
/**
 * SINDESA API — Debug Upload & Foto Profil
 * File diagnostik sementara. HAPUS setelah selesai debugging!
 * 
 * Akses: GET api.sindesa-buttusawe.com/debug_foto.php?nik=xxx
 */
require_once 'api_bootstrap.php';
require_once 'db_config.php';
require_once 'upload_helper.php';

$nik = $_GET['nik'] ?? '';

$info = [
    "php_os" => PHP_OS,
    "api_dir" => __DIR__,
    "website_url" => defined('WEBSITE_URL') ? WEBSITE_URL : 'NOT DEFINED',
    "http_host" => $_SERVER['HTTP_HOST'] ?? 'unknown',
];

// 1. Cek path-path kandidat upload
$candidates = [
    "../storage/app/public/" => realpath("../storage/app/public/"),
    "../../storage/app/public/" => realpath("../../storage/app/public/"),
    "../../../storage/app/public/" => realpath("../../../storage/app/public/"),
    "/home/sindesa/sindesa-app/storage/app/public/" => realpath("/home/sindesa/sindesa-app/storage/app/public/"),
    "../sindesa-app/storage/app/public/" => realpath("../sindesa-app/storage/app/public/"),
    "../sindesa/storage/app/public/" => realpath("../sindesa/storage/app/public/"),
    __DIR__ . "/storage/" => realpath(__DIR__ . "/storage/"),
    __DIR__ . "/uploads/" => realpath(__DIR__ . "/uploads/"),
];

$candidate_results = [];
foreach ($candidates as $path => $resolved) {
    $candidate_results[$path] = [
        "exists" => is_dir($path),
        "resolved" => $resolved ?: 'NOT FOUND',
    ];
}
$info["upload_candidates"] = $candidate_results;

// 2. Cek hasil get_upload_dir
$info["get_upload_dir_profil"] = get_upload_dir('profil');
$info["get_upload_dir_profil_writable"] = is_writable(get_upload_dir('profil'));

// 3. Cek data foto_profil di database
if (!empty($nik) && $conn) {
    $nik_safe = mysqli_real_escape_string($conn, $nik);
    $res = mysqli_query($conn, "SELECT id, nik, name, foto_profil FROM users WHERE nik = '$nik_safe' LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) {
        $user = mysqli_fetch_assoc($res);
        $info["db_user"] = [
            "id" => $user['id'],
            "nik" => $user['nik'],
            "name" => $user['name'],
            "foto_profil_raw" => $user['foto_profil'],
            "foto_profil_url" => get_foto_profil_url($user['foto_profil']),
        ];
        
        // 4. Cek apakah file foto fisik ada di disk
        if (!empty($user['foto_profil'])) {
            $fotoPath = $user['foto_profil'];
            // Bersihkan prefix
            $cleanPath = ltrim($fotoPath, '/');
            if (strpos($cleanPath, 'storage/app/public/') === 0) {
                $cleanPath = substr($cleanPath, strlen('storage/app/public/'));
            } elseif (strpos($cleanPath, 'public/storage/') === 0) {
                $cleanPath = substr($cleanPath, strlen('public/storage/'));
            } elseif (strpos($cleanPath, 'storage/') === 0) {
                $cleanPath = substr($cleanPath, strlen('storage/'));
            }
            
            // Cek beberapa lokasi yang mungkin
            $possibleLocations = [
                "get_upload_dir result" => get_upload_dir('profil') . basename($cleanPath),
                "../storage/app/public/" . $cleanPath => realpath("../storage/app/public/" . $cleanPath),
                "../sindesa-app/storage/app/public/" . $cleanPath => realpath("../sindesa-app/storage/app/public/" . $cleanPath),
                "../sindesa/storage/app/public/" . $cleanPath => realpath("../sindesa/storage/app/public/" . $cleanPath),
            ];
            
            $fileCheckResults = [];
            foreach ($possibleLocations as $label => $resolved) {
                $fileCheckResults[$label] = [
                    "file_exists" => file_exists($label) || (!empty($resolved) && file_exists($resolved)),
                    "resolved" => $resolved ?: 'NOT RESOLVED',
                ];
            }
            $info["file_check"] = $fileCheckResults;
        }
    } else {
        $info["db_user"] = "User dengan NIK $nik tidak ditemukan";
    }
} else {
    $info["db_user"] = "Tambahkan ?nik=xxx untuk cek data user";
}

// 5. Struktur direktori di sekitar API
$parent_dir = dirname(__DIR__);
$info["parent_dir"] = $parent_dir;
$info["parent_contents"] = [];
if (is_dir($parent_dir)) {
    $items = scandir($parent_dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $parent_dir . '/' . $item;
        $info["parent_contents"][] = $item . (is_dir($full) ? '/' : '') ;
    }
}

api_response(["success" => true, "debug" => $info]);
