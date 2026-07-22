<?php
// Template Konfigurasi Database (Salin ke db_config.php di server)
$host = "localhost";
$user = "DB_USER_HERE";
$pass = "DB_PASS_HERE";
$dbname = "DB_NAME_HERE";

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    header('Content-Type: application/json');
    die(json_encode(["success" => false, "message" => "Koneksi database gagal: " . mysqli_connect_error()]));
}
