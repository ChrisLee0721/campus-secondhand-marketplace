<?php
$servername = "127.0.0.1";
$database_username = "root";
$database_password = getenv('DB_PASSWORD') ?: '123456';
$database_name = "db_01";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$database_name", $database_username, $database_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // echo "Connected successfully"; 
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>