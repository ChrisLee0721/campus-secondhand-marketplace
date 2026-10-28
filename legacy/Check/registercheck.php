
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration</title>
    <link rel="stylesheet" href="../CSS/style2.css" />
    <script src="../JavaScript/script.js"></script>
</head>
<body class="gradient">
    <div class="container">
        <div class="container-top">
        <?php
                // ini_set('display_errors', 1);
                // ini_set('display_startup_errors', 1);
                // error_reporting(E_ALL);

                include(__DIR__ . '/../login_register.php');
                include(__DIR__ . '/../db_connect.php');
                $message = "";

                $Email = filter_var($_POST['Email'], FILTER_SANITIZE_EMAIL);
                $Username = filter_var($_POST['Username'], FILTER_SANITIZE_STRING);
                $Password = $_POST['Password'];
                $ConfirmPassword = $_POST['ConfirmPassword']; 

                if (preg_match('/[^\p{L}\p{N}\s]/u', $Username)) {
                     $message = 'Invalid username';
                }
                if (strlen($Password) < 6) {
                     $message ='Password must be longer than six characters';
                }
                if (strlen($Username) < 1) {
                     $message ='Please enter your username';
                }
                if (strlen($Email) < 1) {
                     $message ='Please enter your email address';
                }

                if ($Password === $ConfirmPassword) {
                    try {
                        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tb_01 WHERE email = ?");
                        $stmt->execute([$Email]);
                        $count = $stmt->fetchColumn();

                        if ($count > 0) {
                             $message='Email already exists';
                        } else {
                            $hashedPassword = password_hash($Password, PASSWORD_DEFAULT);
                            $stmt = $conn->prepare("INSERT INTO tb_01 (email, username, password) VALUES (?, ?, ?)");
                            if ($stmt->execute([$Email, $Username, $hashedPassword])) {
                                 $message='Registration successful';
                            } else {
                                 $message='Email already exists' . $stmt->errorInfo()[2];
                            }
                        }
                    } catch (PDOException $e) {
                        $message='Unknown error' . $e->getMessage();
                    }
                } else {
                     $message='Password does not match';
                }            
        ?>
        <h1><?php echo $message;?></h1>
                   
        </div>
        <div class="container-botton ghost <?php if ($message=="Registration successful"){echo'fin';}?>">
            <button class="gradient">Well</button>
            </div>
        <div class="container-botton">
            <button class="gradient" id="backButton">Back</button>
            
        </div>
    </div>

</body>
</html>