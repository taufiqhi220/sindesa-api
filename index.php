<?php
require_once 'api_bootstrap.php';

api_response([
    "success" => true,
    "message" => "Sindesa API is running",
    "version" => "1.0.0",
    "status" => "active"
]);
