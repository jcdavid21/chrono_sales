<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
      crossorigin="anonymous" referrerpolicy="no-referrer">
          <link rel="stylesheet" href="assets/css/general.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <style>
        *{
            font-family: var(--font);
        }
    </style>
    <title>Log in</title>
</head>
<body>
    <div class="page-wrap center">
        <div class="login-container center">
            <div class="left-con">
                <div class="img">
                    <img src="assets/imgs/login.png" alt="">
                </div>
            </div>

            <div class="right-con">
                <div class="login-card">
                    <div class="brand-row">
                        <i class="fa-brands fa-sellsy"></i>
                        <p>Chrono Sales</p>
                    </div>
                    <h1>Sign In</h1>
                    <p class="subtitle">Sign in to access your account.</p>

                    <form id="loginForm" novalidate>
                        <label for="email">Email</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-user"></i>
                            <input type="email" id="email" name="email" placeholder="Enter your email" autocomplete="username" required>
                        </div>

                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                            <button type="button" id="togglePassword" class="icon-btn" aria-label="Show password">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>

                        <div class="options-row">
                            <label class="remember-wrap" for="rememberMe">
                                <input type="checkbox" id="rememberMe" name="rememberMe">
                                <span>Remember me</span>
                            </label>
                            <!-- <a href="#">Forgot password?</a> -->
                        </div>

                        <button type="submit" id="loginBtn" class="login-btn">
                            <span class="btn-text">Log in</span>
                            <span class="btn-loader" aria-hidden="true"></span>
                        </button>

                        <p id="feedback" class="feedback" role="status" aria-live="polite"></p>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="./assets/js/login.js"></script>
</body>
</html>