<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';

// Parameter 'regency_id' dikirim dari ApiService.kt
$city_code = isset($_GET['regency_id']) ? mysqli_real_escape_string($conn, $_GET['regency_id']) : '';

if (empty($city_code)) {
    api_response([]);
}

// Tabel Laravolt: indonesia_districts menggunakan 'city_code'
$query = "SELECT code, name FROM indonesia_districts WHERE city_code = '$city_code' ORDER BY name ASC";
$result = mysqli_query($conn, $query);

$data = [];
if ($result) {
    while($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
}
api_response($data);
