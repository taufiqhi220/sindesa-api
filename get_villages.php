<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';

// Parameter 'district_id' dikirim dari ApiService.kt
$district_code = isset($_GET['district_id']) ? mysqli_real_escape_string($conn, $_GET['district_id']) : '';

if (empty($district_code)) {
    api_response([]);
}

// Tabel Laravolt: indonesia_villages menggunakan 'district_code' sebagai foreign key
$query = "SELECT code, name FROM indonesia_villages WHERE district_code = '$district_code' ORDER BY name ASC";
$result = mysqli_query($conn, $query);

$data = [];
if ($result) {
    while($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
}
api_response($data);
