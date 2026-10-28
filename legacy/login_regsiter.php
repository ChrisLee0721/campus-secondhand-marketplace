<!DOCTYPE html>
<html lang="zh_CN">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Document</title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css" integrity="sha512-KfkfwYDsLkIlwQp6LFnl8zNdLGxu9YAA1QvwINks4PhcElQSvqcyVLLD9aMhXd13uQjoXtEKNosOWaZqXgel0g==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/fontawesome.min.css" integrity="sha512-xX2rYBFJSj86W54Fyv1de80DWBq7zYLn2z0I9bIhQG+rxIF6XVJUpdGnsNHWRa6AvP89vtFupEPDP8eZAtu9qA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="CSS/style.css" />
	
</head>
<body>
	<div class="container" id="container">
		<div class="form-container sign-up-container">
		<!-- 赋予两个类选择器sign-up-container的优先级比form-container高 -->
			<form  action="Check\registercheck.php" method="POST">
				<h1>Create Account</h1>
				<div class="social-container">
					<a href="#"><i class="fab fa-facebook-f"></i></a>
					<a href="#"><i class="fab fa-google-plus-g"></i></a>
					<a href="#"><i class="fab fa-linkedin-in"></i></a>
				</div>
				<span>or use your email for registration</span>
				<input type="text" placeholder="Username" name="Username">
				<input type="Email" placeholder="Email"	name="Email">
				<input type="password" placeholder="Password" name="Password">
				<input type="password" placeholder="ConfirmPassword" name="ConfirmPassword">
				<button>Sing Up</button>
			</form>
		</div>
		<div class="form-container sign-in-container">
			<form action="Check\logincheck.php" method="POST">
				<h1>Sign in</h1>
				<div class="social-container">
					<a href="#"><i class="fab fa-facebook-f"></i></a>
					<a href="#"><i class="fab fa-google-plus-g"></i></a>
					<a href="#"><i class="fab fa-linkedin-in"></i></a>
				</div>
				<span>or use your account</span>
				<input type="email" placeholder="Email" name="Email">
				<input type="password" placeholder="Password" name="Password">
				<a href="#">Forgot your password?</a>
				<button>Sign In</button>
			</form>
		</div>
		<div class="overlay-container">	
			<div class="overlay">	
				<div class="overlay-panel overlay-left">
					<h1>Welcome Back!</h1>
					<p>Old Times  New Stories</p>
					<button class="ghost" id="signIn">Sign In</button>
				</div>
				<div class="overlay-panel overlay-right">
					<h1>Hello,Friend!</h1>
					<p>Your surplus  My need</p>
					<button class="ghost" id="signUp">Sign Up</button>
				</div>
			</div>
		</div>
	</div>
	<footer>
		<p>Hello!<i class="fa fa-heart"></i></p>
	</footer>
	<script src="JavaScript/script.js"></script>
</body>
</html>