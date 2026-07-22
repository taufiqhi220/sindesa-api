<?php
/**
 * SINDESA API — Database Configuration
 * 
 * Jika ada file 'db_config.server.php' di server hosting, file tersebut akan dipakai.
 * Jika tidak ada, ia akan memakai konfigurasi default di bawah.
 */

if (file_exists(__DIR__ . '/db_config.server.php')) {
    require_once __DIR__ . '/db_config.server.php';
} else {
    $host   = "localhost";
    $user   = "root";
    $pass   = "";
    $dbname = "db_sindesa";

    $conn = mysqli_connect($host, $user, $pass, $dbname);

    if (!$conn) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        die(json_encode(["success" => false, "message" => "Koneksi database gagal: " . mysqli_connect_error()]));
    }

    mysqli_set_charset($conn, "utf8mb4");
}
