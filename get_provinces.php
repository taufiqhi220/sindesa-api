<?php
require_once 'api_bootstrap.php';
require_once 'db_config.php';

// Laravolt table: indonesia_provinces (code, name)
$query = "SELECT code, name FROM indonesia_provinces ORDER BY name ASC";
$result = mysqli_query($conn, $query);

$data = [];
if ($result) {
    while($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    api_response($data);
} else {
    api_error(mysqli_error($conn), 500);
}
