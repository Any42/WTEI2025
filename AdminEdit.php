<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Get employee data if ID is provided
$employee = null;
if (isset($_GET['id'])) {
    $employeeID = $conn->real_escape_string($_GET['id']);
    $query = "SELECT * FROM empuser WHERE EmployeeID = '$employeeID'";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        $employee = $result->fetch_assoc();
    } else {
        $_SESSION['error'] = "Employee not found.";
        header("Location: AdminEmployees.php");
        exit;
    }
} else {
    $_SESSION['error'] = "No employee ID provided.";
    header("Location: AdminEmployees.php");
    exit;
}

// Handle form submission for updating employee
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_employee'])) {
    // Get form data and sanitize
    $employeeName = $conn->real_escape_string(trim($_POST['employeeName']));
    $employeeEmail = $conn->real_escape_string(trim($_POST['employeeEmail']));
    $dateHired = $conn->real_escape_string($_POST['dateHired']);
    $birthdate = $conn->real_escape_string($_POST['birthdate']);
    $age = $conn->real_escape_string(trim($_POST['age']));
    $lengthOfService = $conn->real_escape_string(trim($_POST['lengthOfService']));
    $bloodType = $conn->real_escape_string(trim($_POST['bloodType']));
    $tin = $conn->real_escape_string(trim($_POST['tin']));
    $sss = $conn->real_escape_string(trim($_POST['sss']));
    $phic = $conn->real_escape_string(trim($_POST['phic']));
    $hdmf = $conn->real_escape_string(trim($_POST['hdmf']));
    $presentHomeAddress = $conn->real_escape_string(trim($_POST['presentHomeAddress']));
    $permanentHomeAddress = $conn->real_escape_string(trim($_POST['permanentHomeAddress']));
    
    // Safety check: if permanent address is empty or "0", use present address
    if (empty($permanentHomeAddress) || $permanentHomeAddress === '0') {
        $permanentHomeAddress = $presentHomeAddress;
    }
    $lastDayEmployed = $conn->real_escape_string(trim($_POST['lastDayEmployed']));
    $dateTransferred = $conn->real_escape_string(trim($_POST['dateTransferred']));
    $areaOfAssignment = $conn->real_escape_string(trim($_POST['areaOfAssignment']));
    $department = $conn->real_escape_string($_POST['department']);
    $position = $conn->real_escape_string($_POST['position'] ?? 'Employee');
    $shift = $conn->real_escape_string($_POST['shift'] ?? '');
    $contact = $conn->real_escape_string(trim($_POST['contact'] ?? ''));
    $baseSalary = isset($_POST['baseSalary']) && trim($_POST['baseSalary']) !== '' ? floatval($_POST['baseSalary']) : 0.00;
    $riceAllowance = isset($_POST['riceAllowance']) && trim($_POST['riceAllowance']) !== '' ? intval($_POST['riceAllowance']) : 0;
    $medicalAllowance = isset($_POST['medicalAllowance']) && trim($_POST['medicalAllowance']) !== '' ? intval($_POST['medicalAllowance']) : 0;
    $laundryAllowance = isset($_POST['laundryAllowance']) && trim($_POST['laundryAllowance']) !== '' ? intval($_POST['laundryAllowance']) : 0;
    $leavePayCounts = isset($_POST['leavePayCounts']) && trim($_POST['leavePayCounts']) !== '' ? intval($_POST['leavePayCounts']) : 10;
    $status = $conn->real_escape_string($_POST['status']);

    // Check if password was provided
    $passwordUpdate = "";
    if (!empty($_POST['employeePassword'])) {
        $hashedPassword = password_hash($_POST['employeePassword'], PASSWORD_DEFAULT);
        $passwordUpdate = ", Password = '$hashedPassword'";
    }

    // Check if email is being changed to one that already exists
    if ($employeeEmail !== $employee['EmployeeEmail']) {
        $checkEmailStmt = $conn->prepare("SELECT EmployeeID FROM empuser WHERE EmployeeEmail = ? AND EmployeeID != ?");
        $checkEmailStmt->bind_param("ss", $employeeEmail, $employeeID);
        $checkEmailStmt->execute();
        if ($checkEmailStmt->get_result()->num_rows > 0) {
            $_SESSION['error'] = "Email '" . htmlspecialchars($employeeEmail) . "' already exists.";
            $checkEmailStmt->close();
            header("Location: AdminEdit.php?id=" . $employeeID);
            exit;
        }
        $checkEmailStmt->close();
    }

    // Update query
    $updateQuery = "UPDATE empuser SET 
        EmployeeName = '$employeeName',
        EmployeeEmail = '$employeeEmail',
        DateHired = '$dateHired',
        Birthdate = '$birthdate',
        Age = '$age',
        LengthOfService = '$lengthOfService',
        BloodType = '$bloodType',
        TIN = '$tin',
        SSS = '$sss',
        PHIC = '$phic',
        HDMF = '$hdmf',
        PresentHomeAddress = '$presentHomeAddress',
        PermanentHomeAddress = '$permanentHomeAddress',
        LastDayEmployed = " . ($lastDayEmployed ? "'$lastDayEmployed'" : "NULL") . ",
        DateTransferred = " . ($dateTransferred ? "'$dateTransferred'" : "NULL") . ",
        AreaOfAssignment = '$areaOfAssignment',
        Department = '$department',
        Position = '$position',
        Shift = '$shift',
        Contact = '$contact',
        base_salary = $baseSalary,
        rice_allowance = $riceAllowance,
        medical_allowance = $medicalAllowance,
        laundry_allowance = $laundryAllowance,
        leave_pay_counts = $leavePayCounts,
        Status = '$status'
        $passwordUpdate
        WHERE EmployeeID = '$employeeID'";

    if ($conn->query($updateQuery)) {
        // Check if hire date was changed and update absent records accordingly
        if ($dateHired !== $employee['DateHired']) {
            require_once 'auto_absent_attendance.php';
            $absent_result = AutoAbsentAttendance::updateAbsentRecordsForDateChange(
                $conn, 
                $employeeID, 
                $employee['DateHired'], 
                $dateHired, 
                $shift
            );
            
            $success_message = "Employee updated successfully!";
            if ($absent_result['success']) {
                if ($absent_result['records_created'] > 0 || $absent_result['records_removed'] > 0) {
                    $success_message .= " (Absent records updated: {$absent_result['records_created']} created, {$absent_result['records_removed']} removed)";
                }
            }
            $_SESSION['success'] = $success_message;
        } else {
            $_SESSION['success'] = "Employee updated successfully!";
        }
        header("Location: AdminEmployees.php");
        exit;
    } else {
        $_SESSION['error'] = "Error updating employee: " . $conn->error;
        header("Location: AdminEdit.php?id=" . $employeeID);
        exit;
    }
}

$conn->close();

$allDepartments = [
    'Treasury', 'HR', 'Sales', 'Tax', 'Admin', 
    'Finance', 'Accounting', 'Marketing', 'CMCD', 'Security'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Employee - WTEI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #F9F7F7;
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 280px;
            background-color: #112D4E;
            padding: 20px 0;
            box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            color: white;
            position: fixed;
            height: 100vh;
        }
        
        .logo {
            font-weight: bold;
            font-size: 32px;
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 20px;
            color: #DBE2EF;
            letter-spacing: 2px;
        }
        
        .menu {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            padding: 0 15px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 15px 25px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
            border-radius: 12px;
            margin-bottom: 5px;
        }
        
        .menu-item:hover, .menu-item.active {
            background-color: #3F72AF;
            color: #DBE2EF;
            transform: translateX(5px);
        }
        
        .menu-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            font-size: 18px;
        }
        
        .logout-btn {
            background-color: #3F72AF;
            color: white;
            border: none;
            padding: 15px;
            margin: 20px;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .logout-btn:hover {
            background-color: #DBE2EF;
            color: #112D4E;
            transform: translateY(-2px);
        }
        
        .logout-btn i {
            margin-right: 10px;
        }
        
        .main-content {
            flex-grow: 1;
            padding: 30px;
            margin-left: 280px;
            overflow-y: auto;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background-color: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border-bottom: 2px solid #DBE2EF;
        }
        
        .header h1 {
            font-size: 28px;
            color: #112D4E;
            font-weight: 600;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
        }
        
        .btn {
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-primary {
            background-color: #3F72AF;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #112D4E;
        }
        
        .btn-secondary {
            background-color: #DBE2EF;
            color: #112D4E;
        }
        
        .btn-secondary:hover {
            background-color: #3F72AF;
            color: white;
        }
        
        /* Alert Styles */
        .alert {
            padding: 16px;
            margin-bottom: 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
        }
        
        .alert-success {
            background-color: rgba(22, 199, 154, 0.1);
            color: #16C79A;
            border: 1px solid rgba(22, 199, 154, 0.2);
        }
        
        .alert-error {
            background-color: rgba(255, 71, 87, 0.1);
            color: #ff4757;
            border: 1px solid rgba(255, 71, 87, 0.2);
        }
        
        .alert i {
            margin-right: 10px;
            font-size: 18px;
        }
        
        /* Edit Employee Form Styles */
        .edit-employee-container {
            background-color: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }
        
        .edit-employee-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #DBE2EF;
        }
        
        .edit-employee-header h2 {
            font-size: 24px;
            color: #112D4E;
            font-weight: 600;
        }
        
        .edit-employee-header p {
            color: #666;
            margin-top: 5px;
        }
        
        .edit-employee-form {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px 25px;
        }
        
        .form-group {
            margin-bottom: 22px;
        }
        
        .form-group-span-2 {
            grid-column: span 2;
        }
        
        .form-group label {
            font-weight: 600;
            color: #112D4E;
            font-size: 15px;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control {
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 10px 15px;
            height: 50px;
            background-color: #f9f9f9;
            width: 100%;
            box-sizing: border-box;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #3F72AF;
            box-shadow: 0 0 0 2px rgba(63, 114, 175, 0.1);
            background-color: #fff;
            outline: none;
        }
        
        textarea.form-control {
            height: auto;
            min-height: 100px;
        }
        
        .form-actions {
            grid-column: span 5;
            display: flex;
            justify-content: flex-end;
            gap: 30px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        .form-actions .btn {
            padding: 20px 50px;
            margin-left: 20px;
            border-radius: 8px;
            font-weight: 500;
        }
        /* Custom Logout Confirmation Modal */
        .logout-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .logout-modal-content {
            background-color: #fff;
            margin: 15% auto;
            padding: 0;
            border-radius: 15px;
            width: 400px;
            max-width: 90%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .logout-modal-header {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            text-align: center;
            position: relative;
        }

        .logout-modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .logout-modal-header .close {
            position: absolute;
            right: 15px;
            top: 15px;
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.3s;
        }

        .logout-modal-header .close:hover {
            opacity: 1;
        }

        .logout-modal-body {
            padding: 25px;
            text-align: center;
        }

        .logout-modal-body .icon {
            font-size: 48px;
            color: #ff6b6b;
            margin-bottom: 15px;
        }

        .logout-modal-body p {
            margin: 0 0 25px 0;
            color: #555;
            font-size: 16px;
            line-height: 1.5;
        }

        .logout-modal-footer {
            padding: 0 25px 25px 25px;
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .logout-modal-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 100px;
        }

        .logout-modal-btn.cancel {
            background-color: #f8f9fa;
            color: #6c757d;
            border: 2px solid #dee2e6;
        }

        .logout-modal-btn.cancel:hover {
            background-color: #e9ecef;
            border-color: #adb5bd;
        }

        .logout-modal-btn.confirm {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            border: 2px solid transparent;
        }

        .logout-modal-btn.confirm:hover {
            background: linear-gradient(135deg, #ee5a52, #dc3545);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
        }

        .logout-modal-btn:active {
            transform: translateY(0);
        }

        /* Responsive design */
        @media (max-width: 480px) {
            .logout-modal-content {
                width: 95%;
                margin: 20% auto;
            }
            
            .logout-modal-footer {
                flex-direction: column;
            }
            
            .logout-modal-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <img src="LOGO/NewLogoFinalSheesh.png" class="logo" style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding:-250px; padding-bottom: 20px;">
        <div class="menu">
            <a href="AdminHome.php" class="menu-item">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="AdminEmployees.php" class="menu-item active">
                <i class="fas fa-users"></i> Employees
            </a>
            <a href="AdminAttendance.php" class="menu-item">
                <i class="fas fa-calendar-check"></i> Attendance
            </a>
            <a href="AdminPayroll.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i> Payroll
            </a>
            <a href="AdminHistory.php" class="menu-item">
                <i class="fas fa-history"></i> History
            </a>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
    
    <div class="main-content">
        <div class="header">
            <h1>Edit Employee</h1>
            <div class="header-actions">
                <a href="AdminEmployees.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Employees
                </a>
            </div>
        </div>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php 
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>
        
        <div class="edit-employee-container">
            <div class="edit-employee-header">
                <h2>Edit Employee Information</h2>
                <p>Employee ID: <?php echo htmlspecialchars($employee['EmployeeID']); ?></p>
            </div>
            
            <form class="edit-employee-form" method="POST" action="AdminEdit.php?id=<?php echo htmlspecialchars($employee['EmployeeID']); ?>">
                <input type="hidden" name="update_employee" value="1">
                
                <!-- Basic Information -->
                <div class="form-group">
                    <label for="employeeID">Employee ID</label>
                    <input type="text" class="form-control" id="employeeID" value="<?php echo htmlspecialchars($employee['EmployeeID']); ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label for="employeeName">Employee Name</label>
                    <input type="text" class="form-control" id="employeeName" name="employeeName" value="<?php echo htmlspecialchars($employee['EmployeeName']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="employeeEmail">Email</label>
                    <input type="email" class="form-control" id="employeeEmail" name="employeeEmail" value="<?php echo htmlspecialchars($employee['EmployeeEmail']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="employeePassword">New Password (leave blank to keep current)</label>
                    <input type="password" class="form-control" id="employeePassword" name="employeePassword">
                </div>
                
                <div class="form-group">
                    <label for="dateHired">Date Hired</label>
                    <input type="date" class="form-control" id="dateHired" name="dateHired" value="<?php 
                        if (!empty($employee['DateHired']) && $employee['DateHired'] !== '0000-00-00') {
                            // Handle different date formats
                            $dateHired = $employee['DateHired'];
                            if (strpos($dateHired, '-') !== false) {
                                // Already in Y-m-d format or similar
                                echo date('Y-m-d', strtotime($dateHired));
                            } else {
                                // Try to parse other formats
                                $timestamp = strtotime($dateHired);
                                echo $timestamp ? date('Y-m-d', $timestamp) : '';
                            }
                        } else {
                            echo '';
                        }
                    ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="birthdate">Birthdate</label>
                    <input type="date" class="form-control" id="birthdate" name="birthdate" value="<?php 
                        if (!empty($employee['Birthdate']) && $employee['Birthdate'] !== '0000-00-00') {
                            // Handle different date formats
                            $birthdate = $employee['Birthdate'];
                            if (strpos($birthdate, '-') !== false) {
                                // Already in Y-m-d format or similar
                                echo date('Y-m-d', strtotime($birthdate));
                            } else {
                                // Try to parse other formats
                                $timestamp = strtotime($birthdate);
                                echo $timestamp ? date('Y-m-d', $timestamp) : '';
                            }
                        } else {
                            echo '';
                        }
                    ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="age">Age</label>
                    <input type="number" class="form-control" id="age" name="age" min="18" max="65" value="<?php echo htmlspecialchars($employee['Age']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="lengthOfService">Length of Service (years)</label>
                    <input type="text" class="form-control" id="lengthOfService" name="lengthOfService" value="<?php echo htmlspecialchars($employee['LengthOfService']); ?>" required>
                </div>
                
                <!-- Government IDs and Personal Info -->
                <div class="form-group">
                    <label for="bloodType">Blood Type</label>
                    <select class="form-control" id="bloodType" name="bloodType" required>
                        <option value="">Select Blood Type</option>
                        <option value="A+" <?php echo $employee['BloodType'] === 'A+' ? 'selected' : ''; ?>>A+</option>
                        <option value="A-" <?php echo $employee['BloodType'] === 'A-' ? 'selected' : ''; ?>>A-</option>
                        <option value="B+" <?php echo $employee['BloodType'] === 'B+' ? 'selected' : ''; ?>>B+</option>
                        <option value="B-" <?php echo $employee['BloodType'] === 'B-' ? 'selected' : ''; ?>>B-</option>
                        <option value="AB+" <?php echo $employee['BloodType'] === 'AB+' ? 'selected' : ''; ?>>AB+</option>
                        <option value="AB-" <?php echo $employee['BloodType'] === 'AB-' ? 'selected' : ''; ?>>AB-</option>
                        <option value="O+" <?php echo $employee['BloodType'] === 'O+' ? 'selected' : ''; ?>>O+</option>
                        <option value="O-" <?php echo $employee['BloodType'] === 'O-' ? 'selected' : ''; ?>>O-</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="tin">TIN</label>
                    <input type="text" class="form-control" id="tin" name="tin" value="<?php echo htmlspecialchars($employee['TIN']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="sss">SSS</label>
                    <input type="text" class="form-control" id="sss" name="sss" value="<?php echo htmlspecialchars($employee['SSS']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="phic">PHIC</label>
                    <input type="text" class="form-control" id="phic" name="phic" value="<?php echo htmlspecialchars($employee['PHIC']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="hdmf">HDMF</label>
                    <input type="text" class="form-control" id="hdmf" name="hdmf" value="<?php echo htmlspecialchars($employee['HDMF']); ?>" required>
                </div>
                
                <!-- Addresses -->
                <div class="form-group form-group-span-2">
                    <label for="presentHomeAddress">Present Home Address</label>
                    <textarea class="form-control" id="presentHomeAddress" name="presentHomeAddress" rows="2" required><?php echo htmlspecialchars($employee['PresentHomeAddress']); ?></textarea>
                </div>
                
                <div class="form-group form-group-span-2">
                    <label for="permanentHomeAddress">Permanent Home Address</label>
                    <textarea class="form-control" id="permanentHomeAddress" name="permanentHomeAddress" rows="2" required><?php 
                        $perm = trim((string)($employee['PermanentHomeAddress'] ?? ''));
                        $pres = trim((string)($employee['PresentHomeAddress'] ?? ''));
                        echo htmlspecialchars($perm === '' || $perm === '0' ? $pres : $perm);
                    ?></textarea>
                </div>
                
                <!-- Work Information -->
                <div class="form-group">
                    <label for="department">Department</label>
                    <select class="form-control" id="department" name="department" required>
                        <option value="">Select Department</option>
                        <?php foreach ($allDepartments as $dept): ?>
                            <option value="<?php echo $dept; ?>" <?php echo $employee['Department'] === $dept ? 'selected' : ''; ?>><?php echo $dept; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="position">Position</label>
                    <input type="text" class="form-control" id="position" name="position" value="<?php echo htmlspecialchars($employee['Position']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="shift">Shift</label>
                    <select class="form-control" id="shift" name="shift" required>
                        <option value="08:00-17:00" <?php echo ($employee['Shift']==='08:00-17:00'?'selected':''); ?>>8:00 AM - 5:00 PM</option>
                        <option value="08:30-17:30" <?php echo ($employee['Shift']==='08:30-17:30'?'selected':''); ?>>8:30 AM - 5:30 PM</option>
                        <option value="09:00-18:00" <?php echo ($employee['Shift']==='09:00-18:00'?'selected':''); ?>>9:00 AM - 6:00 PM</option>
                        <option value="22:00-06:00" <?php echo ($employee['Shift']==='22:00-06:00'?'selected':''); ?>>Night Shift (10:00 PM - 6:00 AM)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="contact">Contact Number</label>
                    <input type="text" class="form-control" id="contact" name="contact" value="<?php echo htmlspecialchars($employee['Contact']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="areaOfAssignment">Area of Assignment</label>
                    <input type="text" class="form-control" id="areaOfAssignment" name="areaOfAssignment" value="<?php echo htmlspecialchars($employee['AreaOfAssignment']); ?>" required>
                </div>
                
                <!-- Financial Information -->
                <div class="form-group">
                    <label for="baseSalary">Base Salary</label>
                    <input type="number" step="0.01" class="form-control" id="baseSalary" name="baseSalary" value="<?php echo htmlspecialchars($employee['base_salary']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="riceAllowance">Rice Allowance (₱)</label>
                    <input type="number" step="0.01" class="form-control" id="riceAllowance" name="riceAllowance" value="<?php echo htmlspecialchars($employee['rice_allowance'] ?? 0); ?>" min="0">
                </div>
                
                <div class="form-group">
                    <label for="medicalAllowance">Medical Allowance (₱)</label>
                    <input type="number" step="0.01" class="form-control" id="medicalAllowance" name="medicalAllowance" value="<?php echo htmlspecialchars($employee['medical_allowance'] ?? 0); ?>" min="0">
                </div>
                
                <div class="form-group">
                    <label for="laundryAllowance">Laundry Allowance (₱)</label>
                    <input type="number" step="0.01" class="form-control" id="laundryAllowance" name="laundryAllowance" value="<?php echo htmlspecialchars($employee['laundry_allowance'] ?? 0); ?>" min="0">
                </div>
                
                <div class="form-group">
                    <label for="leavePayCounts">Leave Pay Counts</label>
                    <input type="number" class="form-control" id="leavePayCounts" name="leavePayCounts" value="<?php echo htmlspecialchars($employee['leave_pay_counts'] ?? 10); ?>" min="0" max="10" required>
                    <small class="form-text text-muted">Number of leave days with pay (maximum 10)</small>
                </div>
                
                <!-- Employment Dates -->
                <div class="form-group">
                    <label for="lastDayEmployed">Last Day Employed (if applicable)</label>
                    <input type="date" class="form-control" id="lastDayEmployed" name="lastDayEmployed" value="<?php 
                        $lde = isset($employee['LastDayEmployed']) ? trim((string)$employee['LastDayEmployed']) : '';
                        echo ($lde !== '' && $lde !== '0000-00-00') ? date('Y-m-d', strtotime($lde)) : '';
                    ?>">
                </div>
                
                <div class="form-group">
                    <label for="dateTransferred">Date Transferred (if applicable)</label>
                    <input type="date" class="form-control" id="dateTransferred" name="dateTransferred" value="<?php 
                        $dtx = isset($employee['DateTransferred']) ? trim((string)$employee['DateTransferred']) : '';
                        echo ($dtx !== '' && $dtx !== '0000-00-00') ? date('Y-m-d', strtotime($dtx)) : '';
                    ?>">
                </div>
                
                <div class="form-group">
                    <label for="status">Status</label>
                    <select class="form-control" id="status" name="status" required>
                        <option value="Active" <?php echo $employee['Status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $employee['Status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='AdminEmployees.php'">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Employee</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Logout confirmation functions
        function confirmLogout() {
            document.getElementById('logoutModal').style.display = 'block';
            return false; // Prevent default link behavior
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').style.display = 'none';
        }

        function proceedLogout() {
            // Close modal and proceed with logout
            closeLogoutModal();
            window.location.href = 'logout.php';
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('logoutModal');
            if (event.target === modal) {
                closeLogoutModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeLogoutModal();
            }
        });
        
        // Calculate age when birthdate changes
        document.getElementById('birthdate').addEventListener('change', function() {
            const birthdate = new Date(this.value);
            const today = new Date();
            let age = today.getFullYear() - birthdate.getFullYear();
            const monthDiff = today.getMonth() - birthdate.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthdate.getDate())) {
                age--;
            }
            
            document.getElementById('age').value = age;
        });
        
        // Calculate length of service when date hired changes
        document.getElementById('dateHired').addEventListener('change', function() {
            const dateHired = new Date(this.value);
            const today = new Date();
            let years = today.getFullYear() - dateHired.getFullYear();
            const monthDiff = today.getMonth() - dateHired.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dateHired.getDate())) {
                years--;
            }
            
            document.getElementById('lengthOfService').value = years;
        });
        
    // Function to copy present address to permanent address
    function copyPresentToPermanent() {
        const sameAsPresentCheckbox = document.getElementById('sameAsPresent');
        const presentAddressInput = document.getElementById('presentHomeAddress');
        const permanentAddressInput = document.getElementById('permanentHomeAddress');
        
        if (sameAsPresentCheckbox && presentAddressInput && permanentAddressInput) {
            if (sameAsPresentCheckbox.checked) {
                // Copy the value
                permanentAddressInput.value = presentAddressInput.value;
                // Make it readonly instead of disabled (so it still gets submitted)
                permanentAddressInput.readOnly = true;
                permanentAddressInput.style.backgroundColor = '#f0f0f0';
                permanentAddressInput.style.cursor = 'not-allowed';
                // Remove the required attribute so form validation doesn't complain
                permanentAddressInput.removeAttribute('required');
            } else {
                // Allow editing again
                permanentAddressInput.readOnly = false;
                permanentAddressInput.style.backgroundColor = '';
                permanentAddressInput.style.cursor = '';
                // Add back the required attribute
                permanentAddressInput.setAttribute('required', 'required');
            }
        }
    }
    
    // Auto-update permanent address when present address changes (if checkbox is checked)
    const presentAddressInput = document.getElementById('presentHomeAddress');
    if (presentAddressInput) {
        presentAddressInput.addEventListener('input', function() {
            const sameAsPresentCheckbox = document.getElementById('sameAsPresent');
            if (sameAsPresentCheckbox && sameAsPresentCheckbox.checked) {
                document.getElementById('permanentHomeAddress').value = this.value;
            }
        });
    }
    
    // On page load, check if addresses are identical and auto-check the checkbox
    document.addEventListener('DOMContentLoaded', function() {
        const presentAddress = document.getElementById('presentHomeAddress').value;
        const permanentAddress = document.getElementById('permanentHomeAddress').value;
        const checkbox = document.getElementById('sameAsPresent');
        
        if (presentAddress === permanentAddress && presentAddress !== '') {
            checkbox.checked = true;
            copyPresentToPermanent();
        }
    });
    
    // Ensure permanent address is set before form submission if checkbox is checked
    const editEmployeeForm = document.querySelector('form');
    if (editEmployeeForm) {
        editEmployeeForm.addEventListener('submit', function(e) {
            const sameAsPresentCheckbox = document.getElementById('sameAsPresent');
            const presentAddressInput = document.getElementById('presentHomeAddress');
            const permanentAddressInput = document.getElementById('permanentHomeAddress');
            
            if (sameAsPresentCheckbox && sameAsPresentCheckbox.checked && presentAddressInput && permanentAddressInput) {
                // Ensure permanent address matches present address before submission
                permanentAddressInput.value = presentAddressInput.value;
                console.log('Form submission: Setting permanent address to:', presentAddressInput.value);
            }
        });
    }
    
    </script>

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="logout-modal">
        <div class="logout-modal-content">
            <div class="logout-modal-header">
                <h3><i class="fas fa-sign-out-alt"></i> Confirm Logout</h3>
                <span class="close" onclick="closeLogoutModal()">&times;</span>
            </div>
            <div class="logout-modal-body">
                <div class="icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <p>Are you sure you want to logout?<br>This will end your current session and you'll need to login again.</p>
            </div>
            <div class="logout-modal-footer">
                <button class="logout-modal-btn cancel" onclick="closeLogoutModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="logout-modal-btn confirm" onclick="proceedLogout()">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>
    </div>
</body>
</html>