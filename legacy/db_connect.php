<?php
$servername = "127.0.0.1";
$database_username = "root";
$database_password = getenv('DB_PASSWORD') ?: '123456';
$database_name = "db_01";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$database_name", $database_username, $database_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // echo "连接成功"; 
} catch(PDOException $e) {
    echo "连接失败: " . $e->getMessage();
}
?>