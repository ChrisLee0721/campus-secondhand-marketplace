<!DOCTYPE html>
<html lang="zh_CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户注册</title>
</head>
    <style>
        #DivForm,#divtwo{
            float: right;
            width: 25vw;
        }
        body{
            background-color: #011627;
        }
        Main,#BackGroundColorDiv,#DivForm,#divtwo{
            height: 60vh;
        }
        #BackGroundColorDiv,#RegButton,p{
            border-radius: 0.6vh;
        }
        p,input{
            text-indent: 1vh;
        }
        .FristTableInput{   
            border: none;
            border-bottom: 0.1vh solid black;
            font-size: 1.5vh;
            outline: none;
            margin:2vh;
            width: 100%;
            height: 1.5vh;
            padding-bottom: 1vh;
        }
        #DivForm{
            box-shadow: 10vh 10vh 60vh black;
            background-color: white;
        }
        #divtwo{
            display: flex;
            flex-direction: column;
            padding-top: 12.7vh;
            width: fit-content;
        }
        .FormReg{
            padding:0 2vw;
            margin-top: 4vh;
            flex-direction: column;
            display: flex;
            align-items: center;
        }
        #BackGroundColorDiv{
            background-color: rgb(65, 234, 212);
            width: 50vw;    
            margin: 15vh auto;
            box-shadow: 10px 10px 50px rgb(255,255,255,0.3);
            overflow: hidden;
        }
        h1{
            font-size: 3vh;
            margin-bottom: 4vh;
            width: 10vw;
            text-align: center;
        }
        #RegButton{
            width: 6vw;
            height: 4vh;
            font-size: 2vh;
            margin-top: 4vh;
            background-color: #0b0033;
            box-shadow: 10px 10px 30px rgb(152,165,149,0.30);
            color: aliceblue;
        }
        p{
            height: 3.5vh;
            font-size: 1.65vh;
            background-color: #EFE9F4;
            width:fit-content;
            padding: 1.3vh 2vh 0vh 1vh;
            margin: 1vh 2vw 1vh auto;
            box-shadow: 10px 10px 30px rgb(0, 0, 0,0.10);
        }
    </style>
    <?php
        $f='注册成功';
        $ff="<script>alert ( '{$f}' )</script>";
        $fff="<script>location.href='LeeLapYuen.php'</script>";
        $p="PASS";
    ?>
<body>
    <?php
            if ( isset ( $_POST['Submit'] ) )    {
                $UserName = $_POST['UserName'];
                $Password = $_POST['Password'];
                $Email = $_POST['Email'];
                $Password2 = $_POST['aPassword'];                                          
            }
    ?>
        <div id="Main">
            <div id="BackGroundColorDiv">
                <div id="DivForm">
                    <form action="" method="POST" class="FormReg">
                        <h1>用户注册</h1>
                        <input  type="text"      
                                value="<?php if ( isset ( $_POST['UserName'] ) ){ echo$_POST['UserName'];}?>" 
                                name="UserName"
                                placeholder="用户名"
                                class="FristTableInput">
                                <!--required="required"-->
                        <input  type="text"    
                                value="<?php if ( isset ( $_POST['Email'] ) ){ echo$_POST['Email'];}?>" 
                                name="Email"
                                placeholder="电子邮箱"
                                class="FristTableInput">      
                                <!--type="Email"-->
                                <!--required="required"-->
                        <input  type="Password" 
                                value="<?php if ( isset ( $_POST['Password'] ) ){ echo$_POST['Password'];}?>" 
                                name="Password" 
                                placeholder="密码"
                                class="FristTableInput">
                                <!--required="required"-->
                        <input  type="Password" 
                                value="<?php if ( isset ( $_POST['aPassword'] ) ){ echo$_POST['aPassword'];}?>" 
                                name="aPassword"
                                placeholder="确认密码"
                                class="FristTableInput">                
                                <!--required="required"-->
                        <input type="submit" name="Submit" value="注册" id="RegButton">
                    </form>
                </div>
                <div id="divtwo">
                    <p>
                        <?php
                            if ( isset ( $_POST['Submit'] ) )    { 
                                if ( empty ( $UserName ) )   { 
                                    $UserNameConfirm=0; 
                                    echo'请输入用户名';  
                                }   else    {
                                    $UserNameConfirm=1;
                                    echo$p;
                                }
                            }
                        ?>
                    </p>
                    <p>
                        <?php
                            if ( isset ( $_POST['Submit'] ) )    { 
                                if ( empty ( $Email ) )  {
                                    $EmailsConfirm=0;
                                    echo'请输入你的电子邮箱';
                                }   else    {
                                    if ( filter_var ( $Email, FILTER_VALIDATE_EMAIL ) )  {   
                                        $EmailsConfirm=1;
                                        echo$p;      
                                    }   else    {
                                        $error=2;
                                        echo'请输入有效的电子邮箱';
                                    }
                                }
                            }
                        ?>
                    </p>
                    <p>
                        <?php
                            if ( isset ( $_POST['Submit'] ) )    { 
                                if ( empty ( $Password ) )   {
                                    $PasswordConfirm=0;
                                    echo'请输入密码';
                                }   else    {
                                    if  ( strlen ( $Password ) <6 )    {
                                        $PasswordConfirm=0;
                                        echo'请确认输入六位以上的有效密码';
                                    }   else    {
                                        $PasswordConfirm=1;
                                        echo$p; 
                                    }
                                }
                            }        
                        ?>
                    </p>
                    <p>
                        <?php
                            if ( isset ( $_POST['Submit'] ) )    { 
                                if ( empty ( $Password2 ) )  {
                                    $Password2Confirm=0;
                                    echo'再次输入以确认输入的密码';
                                }   else    {
                                    if  ( strlen ( $Password ) <6 )    {
                                        echo'请确认输入六位以上的有效密码';
                                    }   else    {
                                        if ( $Password2==$Password )    {
                                        $Password2Confirm=1;
                                        echo$p; 
                                        }   else    {
                                        $Password2Confirm=0;
                                        echo'请确保两次输入的密码一致';
                                        }
                                    }
                               }
                            }
                        ?>
                    </p>
                </div>
            </div>   
        </div>
    <?php
        if ( $UserNameConfirm=="1"&&$PasswordConfirm=="1"&&$EmailsConfirm=="1"&&$Password2Confirm=="1" )   {
            echo$ff;
            echo$fff;
        }
    ?>
</body>
</html>
