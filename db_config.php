<?php
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "db_sindesa";

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    header('Content-Type: application/json');
    die(json_encode(["success" => false, "message" => "Koneksi database gagal: " . mysqli_connect_error()]));
}
// JANGAN PAKAI TANDA PENUTUP PHP DI SINI AGAR TIDAK ADA SPASI KOSONG
