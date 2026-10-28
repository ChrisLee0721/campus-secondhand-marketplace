<?php
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

include(__DIR__ . '/../login_register.php');
include(__DIR__ . '/../db_connect.php');

session_start();
$Email = filter_var($_POST['Email'], FILTER_SANITIZE_EMAIL);
$Password = $_POST['Password'];

try {
    $stmt = $conn->prepare("SELECT id, password FROM tb_01 WHERE email = ?");
    $stmt->execute([$Email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($Password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        echo "登录成功";
    } else {
        echo "电邮或密码错误";
    }
} catch (PDOException $e) {
    echo "错误: " . $e->getMessage();
}
?>
