<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // First check HR accounts
    $sql = "SELECT hr_id, username, password, full_name FROM hr_accounts 
            WHERE username = ? OR email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // HR user found
        $row = $result->fetch_assoc();
        if ($password === $row['password'] || 
            (function_exists('password_verify') && password_verify($password, $row['password']))) {
            $_SESSION['loggedin'] = true;
            $_SESSION['userid'] = $row['hr_id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = 'hr';
            
            header("Location: HRHome.php");
            exit;
        } else {
            $error = "Invalid password!";
        }
    } else {
        // If not found in HR table, check admin table
        $sql = "SELECT AdminID, AdminName, AdminUsername, AdminEmail, AdminPassword FROM adminuser 
                WHERE AdminUsername = ? OR AdminEmail = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Admin user found
            $row = $result->fetch_assoc();
            if ($password === $row['AdminPassword'] || 
                (function_exists('password_verify') && password_verify($password, $row['AdminPassword']))) {
                $_SESSION['loggedin'] = true;
                $_SESSION['userid'] = $row['AdminID'];
                $_SESSION['username'] = $row['AdminName'];
                $_SESSION['role'] = 'admin';
                
                header("Location: AdminHome.php");
                exit;
            } else {
                $error = "Invalid password!";
            }
        } else {
            // If not found in admin table, check employee table
            $sql_emp = "SELECT EmployeeID, EmployeeName, EmployeeEmail, Password, Department, Position, Role, Status FROM empuser WHERE (EmployeeID = ? OR EmployeeEmail = ?)";
            $stmt_emp = $conn->prepare($sql_emp);
            $stmt_emp->bind_param("ss", $username, $username);
            $stmt_emp->execute();
            $result_emp = $stmt_emp->get_result();

            if ($result_emp->num_rows > 0) {
                // Employee user found
                $row_emp = $result_emp->fetch_assoc();
                if ($row_emp['Status'] !== 'active') {
                    $error = "Your account is not active. Please contact HR.";
                } else {
                    if ($password === $row_emp['Password'] || (function_exists('password_verify') && password_verify($password, $row_emp['Password']))) {
                        $_SESSION['loggedin'] = true;
                        $_SESSION['employee_id'] = $row_emp['EmployeeID'];
                        $_SESSION['username'] = $row_emp['EmployeeName'];
                        $_SESSION['role'] = 'employee';
                        $_SESSION['department'] = $row_emp['Department'];
                        $_SESSION['position'] = $row_emp['Position'];
                        $_SESSION['email'] = $row_emp['EmployeeEmail'];
                        header("Location: EmployeeHome.php");
                        exit;
                    } else {
                        $error = "Invalid password for employee!";
                    }
                }
                $stmt_emp->close();
            } else {
                // If not in employee table, check deptheaduser table
                $sql_dh = "SELECT DeptHeadID, EmployeeName, EmployeeEmail, Password, Department, Role, Status FROM deptheaduser WHERE (EmployeeID = ? OR EmployeeEmail = ?)";
                $stmt_dh = $conn->prepare($sql_dh);
                $stmt_dh->bind_param("ss", $username, $username);
                $stmt_dh->execute();
                $result_dh = $stmt_dh->get_result();

                if ($result_dh->num_rows > 0) {
                    $row_dh = $result_dh->fetch_assoc();
                    if ($row_dh['Status'] !== 'active') {
                        $error = "Your Department Head account is not active. Please contact Admin.";
                    } else {
                        if ($password === $row_dh['Password'] || (function_exists('password_verify') && password_verify($password, $row_dh['Password']))) {
                            $_SESSION['loggedin'] = true;
                            $_SESSION['userid'] = $row_dh['DeptHeadID'];
                            $_SESSION['username'] = $row_dh['EmployeeName'];
                            $_SESSION['role'] = 'depthead';
                            $_SESSION['user_department'] = $row_dh['Department'];
                            $_SESSION['email'] = $row_dh['EmployeeEmail'];
                            header("Location: DeptHeadDashboard.php");
                            exit;
                        } else {
                            $error = "Invalid password for Department Head!";
                        }
                    }
                    $stmt_dh->close();
                } else {
                    $error = "User not found!";
                }
            }
        }
    }
    
    if (isset($stmt) && $result->num_rows == 0) {
        $stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - WTEI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, #F5EFE6 0%, #E8DFCA 50%, #CBDCEB 100%);
            padding: 20px;
            color: #0f172a;
        }

        .auth-layout { width: 100%; max-width: 540px; animation: containerEnter 600ms ease-out both; }

        .login-container {
            width: 100%;
            max-width: 520px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfcff 60%, #F5EFE6 100%);
            border: 1px solid #E8DFCA;
            border-radius: 26px 18px 26px 18px;
            box-shadow: 0 18px 44px rgba(15, 23, 42, 0.12), 0 6px 14px rgba(15, 23, 42, 0.06);
            overflow: hidden;
            position: relative;
            animation: slideInRight 650ms cubic-bezier(.2,.7,.2,1) both 80ms;
            backdrop-filter: saturate(1.05);
        }
        .login-container::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -60px;
            width: 220px;
            height: 220px;
            background: radial-gradient(closest-side, rgba(109,148,197,0.22), rgba(109,148,197,0));
            filter: blur(10px);
            border-radius: 50%;
        }
        .login-container::after {
            content: '';
            position: absolute;
            bottom: -45px;
            left: -55px;
            width: 200px;
            height: 200px;
            background: radial-gradient(closest-side, rgba(232,223,202,0.28), rgba(232,223,202,0));
            filter: blur(12px);
            border-radius: 50%;
        }
        .login-container::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 18px;
            padding: 1px;
            background: linear-gradient(135deg, rgba(203,220,235,0.9), rgba(232,223,202,0.9));
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
                    mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
                    mask-composite: exclude;
            pointer-events: none;
        }
        .login-container::after {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 180px;
            height: 180px;
            background: radial-gradient(closest-side, rgba(109,148,197,0.18), rgba(109,148,197,0));
            border-radius: 50%;
            filter: blur(2px);
            pointer-events: none;
        }

        .login-header {
            background: linear-gradient(135deg, #6D94C5 0%, #87A9D3 100%);
            padding: 22px 20px 16px 20px;
            text-align: center;
            position: relative;
            overflow: hidden;
            border-bottom: none;
        }
        .login-header::before {
            content: '';
            position: absolute;
            bottom: -35px;
            left: -10%;
            width: 120%;
            height: 80px;
            background: radial-gradient(100% 80px at 50% 0, rgba(255,255,255,0.35), rgba(255,255,255,0));
            filter: blur(6px);
        }
        .login-header .logo-large {
            width: 180px;
            height: 180px;
            object-fit: contain;
            filter: drop-shadow(0 16px 36px rgba(0,0,0,0.24));
            animation: bobIn 800ms cubic-bezier(.2,.7,.2,1) both, floatZoom 8s ease-in-out infinite alternate 900ms;
        }
        .login-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: -20%;
            width: 140%;
            height: 3px;
            background: linear-gradient(90deg, rgba(219,226,239,0) 0%, rgba(219,226,239,0.9) 50%, rgba(219,226,239,0) 100%);
            animation: glowSweep 4s ease-in-out infinite;
        }

        .logo {
            width: 130px;
            height: 130px;
            object-fit: contain;
            filter: drop-shadow(0 6px 18px rgba(0,0,0,0.25));
            animation: logoFloat 5s ease-in-out infinite alternate;
        }

        .login-form {
            padding: 28px 26px 32px 26px;
            background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, rgba(247,249,253,0.98) 65%, rgba(232,223,202,0.25) 100%);
            position: relative;
        }
        .login-form::after {
            content: '';
            position: absolute;
            top: 14px;
            left: 14px;
            right: 14px;
            height: 10px;
            background: linear-gradient(90deg, rgba(203,220,235,0.45), rgba(232,223,202,0.45));
            border-radius: 10px;
            filter: blur(8px);
            opacity: 0.65;
            pointer-events: none;
        }
        .login-form::before {
            content: '';
            position: absolute;
            top: -30px;
            right: -30px;
            width: 140px;
            height: 140px;
            background: radial-gradient(circle at 30% 30%, rgba(63,114,175,0.15), rgba(17,45,78,0.08));
            border-radius: 20px;
            transform: rotate(20deg);
            pointer-events: none;
        }

        .form-title {
            text-align: center;
            margin-bottom: 18px;
            color: #0f172a;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.2px;
            animation: titleFade 600ms ease-out both 100ms;
        }

        .form-subtitle {
            text-align: center;
            margin-bottom: 24px;
            color: #5b6473;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #112D4E;
            font-weight: 600;
            font-size: 14px;
        }

        .input-wrapper {
            position: relative;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid #CBDCEB;
            transition: all 0.25s ease;
        }

        .input-wrapper:focus-within {
            border-color: #6D94C5;
            box-shadow: 0 0 0 4px rgba(109, 148, 197, 0.18);
            transform: translateY(-1px);
        }

        .form-group input {
            width: 100%;
            padding: 14px 44px 14px 14px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            background: transparent;
            color: #0f172a;
            outline: none;
            transition: color 0.2s ease;
        }

        .form-group input::placeholder {
            color: #6c757d;
        }

        .input-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #7b8697;
            font-size: 16px;
            transition: transform 0.2s ease, color 0.2s ease;
        }
        .input-wrapper:focus-within .input-icon { transform: translateY(-50%) scale(1.06); color: #6D94C5; }

        .login-btn {
            width: 100%;
            padding: 14px;
            background: #6D94C5;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 8px 18px rgba(109, 148, 197, 0.28);
        }
        .login-btn:focus { outline: none; box-shadow: 0 0 0 4px rgba(109,148,197,0.25), 0 10px 22px rgba(109,148,197,0.30); }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(109, 148, 197, 0.30);
            filter: brightness(1.02);
        }

        .login-btn:active {
            transform: translateY(0);
            filter: none;
        }

        .error-message {
            background: #fff5f5;
            border: 1px solid #ffe3e3;
            color: #e03131;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
        }

        .error-message i {
            margin-right: 10px;
        }

        .forgot-password {
            text-align: center;
            margin-top: 20px;
        }

        .forgot-password a {
            color: #6D94C5;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.2s ease;
        }

        .forgot-password a:hover {
            color: #4f74a7;
        }

        .divider {
            margin: 25px 0;
            text-align: center;
            position: relative;
            animation: fadeIn 700ms ease-out both 120ms;
        }

        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e9ecef;
        }

        .divider span {
            background: #f8f9fa;
            padding: 0 15px;
            color: #6c757d;
            font-size: 12px;
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            
            .login-container {
                border-radius: 15px;
            }

            .login-header {
                padding: 25px 20px;
            }

            .logo {
                width: 130px;
                height: 130px;
            }

            .login-form {
                padding: 30px 25px;
            }

            .form-title {
                font-size: 20px;
            }
        }

        /* Animations */
        @keyframes cardFadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-22px) scale(.98); }
            to { opacity: 1; transform: translateX(0) scale(1); }
        }
        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(22px) scale(.98); }
            to { opacity: 1; transform: translateX(0) scale(1); }
        }
        @keyframes logoFloat {
            from { transform: translateY(0); }
            to { transform: translateY(-6px); }
        }
        @keyframes floatZoom {
            0% { transform: translateY(0) scale(1); }
            100% { transform: translateY(-10px) scale(1.02); }
        }
        @keyframes containerEnter {
            from { opacity: 0; filter: blur(3px); }
            to { opacity: 1; filter: blur(0); }
        }
        @keyframes bobIn {
            0% { opacity: 0; transform: translateY(18px) scale(.92); }
            60% { opacity: 1; transform: translateY(-4px) scale(1.03); }
            100% { transform: translateY(0) scale(1); }
        }
        @keyframes glowSweep {
            0%, 100% { opacity: 0.25; transform: translateX(0); }
            50% { opacity: 0.6; transform: translateX(6%); }
        }
        @keyframes titleFade {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Respect reduced motion */
        @media (prefers-reduced-motion: reduce) {
            .login-container,
            .logo,
            .login-header::after,
            .form-title,
            .divider { animation: none !important; }
            .input-wrapper:focus-within { transform: none; }
        }
    </style>
</head>
<body>
    <div class="auth-layout">
    <div class="login-container">
        <div class="login-header">
                <img src="LOGO/newLogo_transparent.png" class="logo-large" alt="WTEI Logo">
        </div>
        
        <div class="login-form">
            <h2 class="form-title">Welcome Back</h2>
            <div class="form-subtitle">Sign in to continue to your WTEI workspace</div>
            
            <?php if (isset($error)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" autocomplete="off">
                <div class="form-group">
                    <label for="username">Employee ID / Email</label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" required 
                               placeholder="Enter your Employee ID or Email" 
                               autocomplete="off" 
                               data-lpignore="true">
                        <i class="input-icon fas fa-user"></i>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" required 
                               placeholder="Enter your password" 
                               autocomplete="new-password" 
                               data-lpignore="true">
                        <i class="input-icon fas fa-lock"></i>
                    </div>
                </div>
                
                <button type="submit" class="login-btn">
                    Sign In
                </button>
            </form>
            
            <div class="divider">
                <span>Need help?</span>
            </div>
            
            <div class="forgot-password">
                <a href="forgot-password.php">Forgot your password?</a>
            </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Simple form validation
            const form = document.querySelector('form');
            const loginBtn = document.querySelector('.login-btn');
            
            form.addEventListener('submit', function(e) {
                const username = document.getElementById('username').value.trim();
                const password = document.getElementById('password').value;
                
                if (!username || !password) {
                    e.preventDefault();
                    alert('Please fill in all fields');
                    return;
                }
                
                // Show loading state
                loginBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing In...';
                loginBtn.disabled = true;
            });
            
            // Enter key navigation
            const inputs = document.querySelectorAll('input');
            inputs.forEach((input, index) => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        if (index < inputs.length - 1) {
                            inputs[index + 1].focus();
                        } else {
                            form.submit();
                        }
                    }
                });
            });
            
            // Prevent browser password save prompt
            setTimeout(() => {
                const passwordField = document.getElementById('password');
                passwordField.setAttribute('readonly', true);
                passwordField.addEventListener('focus', function() {
                    this.removeAttribute('readonly');
                });
            }, 100);
        });
    </script>
</body>
</html>