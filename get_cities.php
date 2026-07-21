<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';

// Parameter 'province_id' sent from Android
$province_code = isset($_GET['province_id']) ? mysqli_real_escape_string($conn, $_GET['province_id']) : '';

if (empty($province_code)) {
    api_response([]);
}

// Laravolt table: indonesia_cities uses 'province_code'
$query = "SELECT code, name FROM indonesia_cities WHERE province_code = '$province_code' ORDER BY name ASC";
$result = mysqli_query($conn, $query);

$data = [];
if ($result) {
    while($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
}
api_response($data);
