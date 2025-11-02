<?php
session_start();

// Check if user is logged in and is HR
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'hr') {
    header("Location: login.php");
    exit;
}

// Get HR information
$hr_id = $_SESSION['userid'];
$hr_name = $_SESSION['username'];

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Align PHP and MySQL session timezones to Asia/Manila for consistent date/time handling
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");

// Get HR details
$hr_query = "SELECT * FROM hr_accounts WHERE hr_id = ?";
$stmt = $conn->prepare($hr_query);
$stmt->bind_param("i", $hr_id);
$stmt->execute();
$hr_result = $stmt->get_result();
$hr_details = $hr_result->fetch_assoc();

// Get department filter
$department_filter = isset($_GET['department']) ? $_GET['department'] : '';

// Get employees with filters
$emp_query = "SELECT EmployeeID, EmployeeName, EmployeeEmail, Department, Position, 
          base_salary, Contact, IFNULL(Status, 'Active') as Status
          FROM empuser WHERE 1=1";
          
if (!empty($department_filter)) {
    $emp_query .= " AND Department = '" . $conn->real_escape_string($department_filter) . "'";
}
$emp_query .= " ORDER BY EmployeeName";

$emp_result = $conn->query($emp_query);
$employees = [];
if ($emp_result && $emp_result->num_rows > 0) {
    while ($row = $emp_result->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Get department heads
$depthead_query = "SELECT DeptHeadID as EmployeeID, EmployeeName, EmployeeEmail, Department, Position, 
          base_salary, Contact, IFNULL(Status, 'Active') as Status 
          FROM deptheaduser 
          ORDER BY EmployeeName";
          
$depthead_result = $conn->query($depthead_query);
$deptheads = [];
if ($depthead_result && $depthead_result->num_rows > 0) {
    while ($row = $depthead_result->fetch_assoc()) {
        $deptheads[] = $row;
    }
}

// Get distinct departments for filter
$departments_query = "SELECT DISTINCT Department FROM empuser ORDER BY Department";
$departments_result = $conn->query($departments_query);

$totalEmployees = count($employees);




// Get present today count - timezone-aware
$today = date('Y-m-d');

// Count distinct employees who are marked as present today (use DATE() for datetime columns)
$presentQuery = "SELECT COUNT(DISTINCT EmployeeID) as present 
                 FROM attendance 
                 WHERE DATE(attendance_date) = ? 
                 AND attendance_type = 'present'";

$stmt = $conn->prepare($presentQuery);
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();
$presentToday = $result->fetch_assoc()['present'] ?? 0;
$stmt->close();

// Alternative Method 2: If you want more detailed information
// This will also show late/early status breakdown
$detailedPresentQuery = "SELECT 
    COUNT(DISTINCT EmployeeID) as total_present,
    COUNT(DISTINCT CASE WHEN status = 'late' THEN EmployeeID END) as late_employees,
    COUNT(DISTINCT CASE WHEN status = 'early' THEN EmployeeID END) as early_employees,
    COUNT(DISTINCT CASE WHEN status IS NULL THEN EmployeeID END) as on_time_employees
    FROM attendance 
    WHERE DATE(attendance_date) = ? 
    AND attendance_type = 'present'";

$stmt = $conn->prepare($detailedPresentQuery);
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();
$attendanceDetails = $result->fetch_assoc();
$stmt->close();

// You can use either $presentToday for simple count
// Or $attendanceDetails['total_present'] for the same result with more details


// Get monthly payroll total
$currentMonth = date('m');
$currentYear = date('Y');
$payrollQuery = "SELECT SUM(net_pay) as total FROM payroll WHERE MONTH(payment_date) = '$currentMonth' AND YEAR(payment_date) = '$currentYear'";
$payrollResult = $conn->query($payrollQuery);
$monthlyPayroll = $payrollResult->fetch_assoc()['total'] ?? 0;
$allDepartments = [
    'Treasury', 'HR', 'Sales', 'Tax', 'Admin', 
    'Finance', 'Accounting', 'Marketing', 'CMCD', 'Security'
];
// Get all employees with updated column names
$employees = [];
$query = "SELECT e.NO, e.EmployeeID, e.EmployeeName, e.EmployeeEmail, e.Department, e.Position, 
          e.base_salary, e.rice_allowance, e.medical_allowance, e.laundry_allowance, e.Contact, IFNULL(e.Status, 'active') as Status, e.history, e.DateHired, 
          e.Birthdate, e.Age, e.LengthOfService, e.BloodType, e.TIN, e.SSS, e.PHIC, e.HDMF, 
          e.PresentHomeAddress, e.PermanentHomeAddress, e.LastDayEmployed, 
          e.DateTransferred, e.AreaOfAssignment, e.Shift
          FROM empuser e 
          ORDER BY e.NO DESC";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Group employees by department for the department view
$employeesByDepartment = [];
foreach ($employees as $employee) {
    $dept = $employee['Department'] ?? 'Other';
    if (!isset($employeesByDepartment[$dept])) {
        $employeesByDepartment[$dept] = [];
    }
    $employeesByDepartment[$dept][] = $employee;
}





$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management - WTEI</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
/* Base Styles */
        /* Unified UI/UX for HRHome.php and AdminEmployees.php */
        :root {
            --primary-color: #112D4E;      /* Dark blue */
            --secondary-color: #3F72AF;    /* Medium blue */
            --accent-color: #DBE2EF;       /* Light blue/gray */
            --background-color: #F9F7F7;   /* Off white */
            --text-color: #FFFFFF;         /* Changed to white for all text */
            --border-color: #DBE2EF;       /* Light blue for borders */
            --shadow-color: rgba(17, 45, 78, 0.1); /* Primary color shadow */
            --success-color: #16C79A;      /* Green for success */
            --warning-color: #FF6B35;      /* Orange for warnings */
            --error-color: #ff4757;        /* Red for errors */
            --info-color: #11698E;         /* Blue for info */
            --sidebar-width: 320px;
            --sidebar-collapsed-width: 90px;
            --transition-speed: 0.3s;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--background-color);
            display: flex;
            min-height: 100vh;
            color: var(--text-dark);
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
            transition: all var(--transition-speed) ease;
            z-index: 1000;
            overflow-y: auto;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 25px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 15px;
        }

        .logo {
            width: 100%;
            max-width: 250px;
            height: auto;
            margin: 0 auto 15px;
            display: block;
            transition: all var(--transition-speed) ease;
        }

        .portal-name {
    color:rgb(247, 247, 247);
    font-size: 22px;
    font-weight: 600;
    margin-bottom: 20px;
    letter-spacing: 0.5px;
    margin-left: 80px;
    opacity: 0.9;
}
        .menu {
            padding: 0 15px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex-grow: 1;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 16px 20px;
            color: var(--text-color); /* Changed to white */
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            position: relative;
        }

        .menu-item:hover {
            background-color: rgba(255, 255, 255, 0.15);
            color: var(--text-color); /* Changed to white */
            transform: translateX(5px);
        }

        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.2);
            color: var(--text-color); /* Changed to white */
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .menu-item i {
            font-size: 20px;
            width: 30px;
            text-align: center;
            margin-right: 15px;
            transition: all var(--transition-speed) ease;
            color: var(--text-color); /* Added to ensure icons are white */
        }

        .menu-item span {
            font-weight: 500;
            transition: opacity var(--transition-speed) ease;
            color: var(--text-color); /* Added to ensure text is white */
        }

        .logout-container {
            padding: 20px 15px;
            margin-top: auto; /* This pushes the logout to the bottom */
        }

        .logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 14px;
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--text-color); /* Changed to white */
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .logout-btn i {
            margin-right: 10px;
            font-size: 18px;
            color: var(--text-color); /* Added to ensure icon is white */
        }

        /* Toggle Button */
        .sidebar-toggle {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 40px;
            height: 40px;
            background-color: var(--secondary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1001;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            transition: all var(--transition-speed) ease;
        }

        .sidebar-toggle:hover {
            background-color: var(--primary-color);
            transform: scale(1.1);
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 30px;
            flex: 1;
            transition: margin-left var(--transition-speed) ease;
        }

        .content-header {
            margin-bottom: 30px;
        }

        .content-header h1 {
            font-size: 28px;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .content-header p {
            color: var(--secondary-color);
            font-size: 16px;
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .card h3 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .card p {
            color: var(--secondary-color);
        }

        /* Collapsed State */
        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.collapsed .portal-name,
        .sidebar.collapsed .menu-item span {
            opacity: 0;
            visibility: hidden;
        }

        .sidebar.collapsed .logo {
            max-width: 60px;
        }

        .sidebar.collapsed .menu-item {
            padding: 16px 15px;
            justify-content: center;
        }

        .sidebar.collapsed .menu-item i {
            margin-right: 0;
        }

        .sidebar.collapsed .logout-btn span {
            display: none;
        }

        .sidebar.collapsed .logout-btn {
            padding: 14px 10px;
            justify-content: center;
        }

        .sidebar.collapsed .logout-btn i {
            margin-right: 0;
        }

        /* When sidebar is collapsed, adjust main content */
        .sidebar.collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: var(--sidebar-width);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .sidebar-toggle {
                display: flex;
            }
            
            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 576px) {
            .sidebar {
                width: 100%;
            }
            
            .sidebar.collapsed {
                width: 70px;
            }
        }

        /* Animation for menu items */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .menu-item {
            animation: fadeIn 0.4s ease forwards;
        }

        .menu-item:nth-child(1) { animation-delay: 0.1s; }
        .menu-item:nth-child(2) { animation-delay: 0.2s; }
        .menu-item:nth-child(3) { animation-delay: 0.3s; }
        .menu-item:nth-child(4) { animation-delay: 0.4s; }

/* Main Content */
.main-content {
    flex-grow: 1;
    padding: 30px;
    margin-left: var(--sidebar-width);
    overflow-y: auto;
    transition: all 0.3s ease;
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
    border-bottom: 2px solid var(--border-color);
}

.header h1 {
    font-size: 28px;
    color: var(--primary-color);
    font-weight: 600;
}

.header-actions {
    display: flex;
    gap: 15px;
    align-items: center;
}

.search-box {
    position: relative;
    width: 300px;
}

.search-box input {
    width: 100%;
    padding: 12px 40px 12px 20px;
    border: 2px solid #eee;
    border-radius: 12px;
    font-size: 14px;
    background-color: #fff;
    transition: all 0.3s;
}

.search-box input:focus {
    outline: none;
    border-color: var(--secondary-color);
    box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
}

.search-box i {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--primary-color);
}

/* Dashboard Cards */
.dashboard-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 25px;
    margin-bottom: 30px;
}

.card {
    background-color: white;
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
}

.card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: linear-gradient(to right, var(--info-color), var(--success-color));
}

.card-content {
    position: relative;
    z-index: 1;
}

.card-title {
    font-size: 14px;
    color: var(--primary-color);
    margin-bottom: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.card-value {
    font-size: 28px;
    font-weight: 600;
    color: var(--info-color);
    margin-bottom: 15px;
}

.card-icon {
    position: absolute;
    right: 20px;
    bottom: 20px;
    font-size: 48px;
    color: rgba(22, 199, 154, 0.1);
}

/* Buttons */
.btn {
    padding: 10px 18px;
    border-radius: 8px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.btn-primary {
    background-color: var(--secondary-color);
    color: white;
}

.btn-primary:hover {
    background-color: var(--primary-color);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}

.btn-secondary {
    background-color: var(--accent-color);
    color: var(--primary-color);
}

.btn-secondary:hover {
    background-color: var(--secondary-color);
    color: white;
}

/* View Toggle */
.view-toggle {
    display: flex;
    gap: 10px;
}

.view-btn {
    padding: 10px 20px;
    border: 2px solid var(--secondary-color);
    background-color: white;
    color: var(--secondary-color);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
}

.view-btn.active, .view-btn:hover {
    background-color: var(--secondary-color);
    color: white;
    transform: translateY(-2px);
}

/* Alerts */
.alert {
    padding: 16px;
    margin-bottom: 20px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 500;
    display: flex;
    align-items: center;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        transform: translateY(-10px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.alert-success {
    background-color: rgba(22, 199, 154, 0.1);
    color: var(--success-color);
    border: 1px solid rgba(22, 199, 154, 0.2);
}

.alert-error {
    background-color: rgba(255, 71, 87, 0.1);
    color: var(--error-color);
    border: 1px solid rgba(255, 71, 87, 0.2);
}

.alert i {
    margin-right: 10px;
    font-size: 18px;
}

/* Tables */
.employee-table {
    background-color: white;
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
}

table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin-top: 20px;
}

table th, table td {
    padding: 16px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

table th {
    color: var(--primary-color);
    font-weight: 600;
    font-size: 14px;
    background-color: var(--accent-color);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

table th:first-child {
    border-top-left-radius: 12px;
}

table th:last-child {
    border-top-right-radius: 12px;
}

table td {
    color: #333;
}

table tr:hover {
    background-color: var(--accent-color);
    cursor: pointer;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.view-btn-action {
    background-color: var(--info-color);
    color: white;
}

.edit-btn {
    background-color: var(--success-color);
    color: white;
}

.delete-btn {
    background-color: var(--error-color);
    color: white;
}

.fingerprint-btn {
    background-color: var(--warning-color);
    color: white;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.action-btn i {
    margin-right: 5px;
    font-size: 14px;
}

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(17, 45, 78, 0.6);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: #ffffff;
            border-radius: 24px;
            width: 100%;
            max-width: 1200px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(17, 45, 78, 0.35);
            position: relative;
            display: flex;
            flex-direction: column;
            animation: modalSlideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes modalSlideUp {
            from { 
                opacity: 0;
                transform: translateY(50px) scale(0.95);
            }
            to { 
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .close-modal {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.9);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
            color: var(--primary-color);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .close-modal:hover {
            background: var(--error-color);
            color: white;
            transform: rotate(90deg) scale(1.1);
            box-shadow: 0 6px 16px rgba(255, 71, 87, 0.3);
        }

        /* Department detail header and back button */
        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .back-btn {
            padding: 10px 18px;
            border-radius: 10px;
            border: 1px solid rgba(17, 45, 78, 0.12);
            background: linear-gradient(180deg, #ffffff, #f6f9ff);
            color: var(--primary-color);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 6px 16px rgba(17,45,78,0.08);
            transition: transform .15s ease, box-shadow .2s ease, background .2s ease;
        }

        .back-btn i { color: var(--secondary-color); }
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(17,45,78,0.12);
            background: linear-gradient(180deg, #ffffff, #eef4ff);
        }

        /* Modal sections and grid */
        .info-section { 
            margin-bottom: 35px;
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid #e8edf2;
            transition: all 0.3s ease;
        }

        .info-section:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            border-color: var(--secondary-color);
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: var(--primary-color);
            text-transform: uppercase;
            margin: 0 0 20px 0;
            padding-bottom: 12px;
            border-bottom: 3px solid var(--secondary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title::before {
            content: '';
            width: 6px;
            height: 24px;
            background: linear-gradient(180deg, var(--secondary-color), var(--primary-color));
            border-radius: 3px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }
        @media (max-width: 992px) { .info-grid { gap: 16px; } }
        @media (max-width: 640px) { .info-grid { grid-template-columns: 1fr; } }
        
        @media (max-width: 992px) { .employee-info .info-row { grid-template-columns: 180px 1fr; } }
        @media (max-width: 768px) { .employee-info .info-row { grid-template-columns: 160px 1fr; } }
        @media (max-width: 480px) { .employee-info .info-row { grid-template-columns: 1fr; } }

        .modal-header {
            padding: 0;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            position: relative;
            overflow: hidden;
        }

        .modal-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .modal-header-content {
            padding: 40px 30px 30px;
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .employee-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.05));
            border: 4px solid rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            color: white;
            font-weight: 600;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
        }

        .modal-header-text {
            flex: 1;
        }

        .modal-header h2 {
            margin: 0 0 8px 0;
            color: white;
            font-size: 28px;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .modal-header-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .modal-header-subtitle span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .modal-header-subtitle i {
            font-size: 14px;
            opacity: 0.8;
        }

        .employee-info {
            padding: 30px;
            background: #f8fafb;
            overflow-y: auto;
            max-height: calc(90vh - 180px);
        }

        .employee-info::-webkit-scrollbar {
            width: 8px;
        }

        .employee-info::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .employee-info::-webkit-scrollbar-thumb {
            background: var(--secondary-color);
            border-radius: 10px;
        }

        .employee-info::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }

        .employee-info .info-row {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 15px;
            align-items: center;
            padding: 0;
            border-radius: 0;
            border: none;
            background: transparent;
            margin-bottom: 18px;
        }

        .info-label {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-label i {
            width: 20px;
            text-align: center;
            color: var(--secondary-color);
            font-size: 16px;
        }

        .info-value {
            color: #2d3748;
            font-weight: 500;
            word-break: break-word;
            white-space: pre-wrap;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            min-height: 44px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        }

        .info-value:hover {
            border-color: var(--secondary-color);
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.1);
            transform: translateY(-1px);
        }

        .info-row.full-row .info-value { 
            align-items: flex-start;
            min-height: 80px;
        }

/* Department Cards */
.departments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}

.department-card {
    background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(63, 114, 175, 0.1);
}

.department-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 30px rgba(63, 114, 175, 0.15);
    border-color: rgba(63, 114, 175, 0.2);
}

.department-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: linear-gradient(to right, var(--secondary-color), var(--primary-color));
}

.department-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(219, 226, 239, 0.5);
}

.department-name {
    font-size: 20px;
    font-weight: 600;
    color: var(--primary-color);
    position: relative;
    padding-left: 30px;
}

.department-name::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    background-color: var(--secondary-color);
    border-radius: 6px;
    opacity: 0.7;
}

.employee-count {
    background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
    color: white;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    box-shadow: 0 4px 8px rgba(63, 114, 175, 0.3);
}

.department-preview {
    margin-top: 15px;
    min-height: 160px;
}

.preview-employee {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    padding: 10px;
    background-color: rgba(219, 226, 239, 0.3);
    border-radius: 8px;
    transition: all 0.2s ease;
}

.preview-employee:hover {
    background-color: rgba(63, 114, 175, 0.1);
    transform: translateX(5px);
}

.preview-employee i {
    margin-right: 10px;
    color: var(--secondary-color);
    width: 18px;
    text-align: center;
    font-size: 14px;
}

.preview-employee-name {
    font-weight: 500;
    color: var(--primary-color);
    font-size: 14px;
}

.preview-employee-position {
    font-size: 12px;
    color: #6c757d;
    margin-top: 2px;
}

.empty-slot {
    height: 38px;
    background-color: rgba(219, 226, 239, 0.2);
    border-radius: 8px;
    margin-bottom: 10px;
    border: 1px dashed rgba(63, 114, 175, 0.3);
}

.more-employees {
    color: var(--secondary-color);
    font-size: 13px;
    font-weight: 500;
    text-align: center;
    margin-top: 10px;
    padding: 8px;
    background-color: rgba(63, 114, 175, 0.1);
    border-radius: 8px;
}

.department-icon {
    position: absolute;
    bottom: 15px;
    right: 15px;
    font-size: 40px;
    color: rgba(63, 114, 175, 0.1);
    z-index: 0;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-top: 30px;
}

.pagination button {
    padding: 10px 20px;
    border: none;
    background-color: white;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    color: var(--primary-color);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
}

.pagination button:hover {
    background-color: var(--accent-color);
    transform: translateY(-2px);
}

.pagination button.active {
    background-color: var(--success-color);
    color: white;
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 25px;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
}

.status-badge::before {
    content: '';
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.status-active {
    background: linear-gradient(135deg, rgba(22, 199, 154, 0.15), rgba(22, 199, 154, 0.25));
    color: var(--success-color);
    border: 2px solid var(--success-color);
}

.status-active::before {
    background-color: var(--success-color);
}

.status-inactive {
    background: linear-gradient(135deg, rgba(255, 71, 87, 0.15), rgba(255, 71, 87, 0.25));
    color: var(--error-color);
    border: 2px solid var(--error-color);
}

.status-inactive::before {
    background-color: var(--error-color);
}

.status-badge:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.night-shift-badge {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    color: #ffffff;
    padding: 8px 12px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 4px 12px rgba(26, 26, 46, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.night-shift-badge i {
    color: #ffd700;
    font-size: 14px;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .dashboard-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .departments-grid {
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    }

    .modal-header-content {
        padding: 30px 25px 25px;
    }

    .employee-avatar {
        width: 85px;
        height: 85px;
        font-size: 36px;
    }

    .modal-header h2 {
        font-size: 24px;
    }
}

@media (max-width: 992px) {
    .sidebar {
        width: 250px;
        transform: translateX(-100%);
        z-index: 1000;
    }
    
    .sidebar.active {
        transform: translateX(0);
    }
    
    .main-content {
        margin-left: 0;
        width: 100%;
    }
    
    .header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .header-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .search-box {
        width: 100%;
    }
    
    .view-toggle {
        width: 100%;
        justify-content: space-between;
    }
    
    .view-btn {
        flex-grow: 1;
        text-align: center;
        justify-content: center;
    }

    .employee-info .info-row {
        grid-template-columns: 160px 1fr;
    }

    .info-section {
        padding: 20px;
    }
}

@media (max-width: 768px) {
    .dashboard-cards {
        grid-template-columns: 1fr;
    }
    
    .departments-grid {
        grid-template-columns: 1fr;
    }
    
    .employee-table {
        padding: 15px;
        overflow-x: auto;
    }
    
    table {
        min-width: 600px;
    }

    .modal-content {
        max-width: 95%;
        border-radius: 20px;
    }

    .modal-header-content {
        flex-direction: column;
        text-align: center;
        padding: 25px 20px 20px;
    }

    .employee-avatar {
        width: 80px;
        height: 80px;
        font-size: 32px;
    }

    .modal-header h2 {
        font-size: 22px;
    }

    .modal-header-subtitle {
        flex-direction: column;
        gap: 8px;
    }

    .employee-info {
        padding: 20px;
    }

    .employee-info .info-row {
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .info-label {
        font-size: 13px;
    }

    .info-value {
        min-height: 40px;
        padding: 10px 14px;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }

    .section-title {
        font-size: 14px;
    }

    .info-section {
        padding: 18px;
        margin-bottom: 20px;
    }

    .close-modal {
        width: 36px;
        height: 36px;
        font-size: 20px;
        top: 15px;
        right: 15px;
    }
}

@media (max-width: 576px) {
    .main-content {
        padding: 15px;
    }
    
    .header {
        padding: 15px;
    }
    
    .card {
        padding: 15px;
    }
    
    .department-card {
        padding: 15px;
    }
    
    .action-buttons {
        flex-wrap: wrap;
    }
    
    .action-btn {
        padding: 6px 10px;
        font-size: 12px;
    }

    .modal {
        padding: 10px;
    }

    .modal-content {
        max-height: 95vh;
    }

    .employee-avatar {
        width: 70px;
        height: 70px;
        font-size: 28px;
    }

    .modal-header h2 {
        font-size: 20px;
    }

    .modal-header-subtitle {
        font-size: 14px;
    }

    .employee-info {
        padding: 15px;
        max-height: calc(95vh - 150px);
    }

    .info-section {
        padding: 15px;
        margin-bottom: 15px;
    }

    .close-modal {
        width: 32px;
        height: 32px;
        font-size: 18px;
        top: 12px;
        right: 12px;
    }
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
    <div class="sidebar" id="sidebar">
        <div class="logo">
        <img src="LOGO/newLogo_transparent.png" class="logo" style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding:-250px; padding-bottom: 20px;">       

        </div>
        <div class="portal-name">
        <i class="fas fa-users-cog"></i>
        <span>HR Portal</span>
        </div>
        <div class="menu">
            <a href="HRHome.php" class="menu-item">
                <i class="fas fa-th-large"></i>
                <span>Dashboard</span>
            </a>
            <a href="HREmployees.php" class="menu-item active">
                <i class="fas fa-users"></i>
                <span>Employees</span>
            </a>
            <a href="HRAttendance.php" class="menu-item">
                <i class="fas fa-calendar-check"></i>
                <span>Attendance</span>
            </a>
            <a href="HRhistory.php" class="menu-item">
                <i class="fas fa-history"></i> History
            </a>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
    <!-- Employee Info Modal -->
    <div id="employeeModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal()">&times;</button>
            <div class="modal-header">
                <div class="modal-header-content">
                    <div class="employee-avatar" id="empAvatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="modal-header-text">
                        <h2 id="modalEmpName">Employee Profile</h2>
                        <div class="modal-header-subtitle">
                            <span><i class="fas fa-id-badge"></i> <span id="modalEmpId">-</span></span>
                            <span><i class="fas fa-building"></i> <span id="modalEmpDept">-</span></span>
                            <span><i class="fas fa-briefcase"></i> <span id="modalEmpPos">-</span></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="employee-info">
                <div class="info-section">
                    <div class="section-title">Basic Information</div>
                    <div class="info-grid">
                        <div class="info-row"><div class="info-label"><i class="fas fa-user"></i>Full Name</div><div class="info-value" id="empName"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-id-card"></i>Employee ID</div><div class="info-value" id="empId"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-envelope"></i>Email</div><div class="info-value" id="empEmail"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-phone"></i>Contact</div><div class="info-value" id="empContact"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-building"></i>Department</div><div class="info-value" id="empDepartment"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-briefcase"></i>Position</div><div class="info-value" id="empPosition"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-clock"></i>Shift</div><div class="info-value" id="empShift"></div></div>
                        <div class="info-row" id="nightShiftDifferential" style="display: none;"><div class="info-label"><i class="fas fa-moon"></i>Night Shift Differential</div><div class="info-value night-shift-badge">NSD (10PM-6AM) - 10% Additional Pay</div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-circle-check"></i>Status</div><div class="info-value"><span id="empStatus" class="status-badge"></span></div></div>
                    </div>
                </div>
                <div class="info-section">
                    <div class="section-title">Compensation</div>
                    <div class="info-grid">
                        <div class="info-row"><div class="info-label"><i class="fas fa-money-bill-wave"></i>Base Salary</div><div class="info-value">₱<span id="empSalary"></span></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-rice"></i>Rice Allowance</div><div class="info-value">₱<span id="empRiceAllowance"></span></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-heart"></i>Medical Allowance</div><div class="info-value">₱<span id="empMedicalAllowance"></span></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-tshirt"></i>Laundry Allowance</div><div class="info-value">₱<span id="empLaundryAllowance"></span></div></div>
                    </div>
                </div>
                <div class="info-section">
                    <div class="section-title">Personal & Employment Details</div>
                    <div class="info-grid">
                        <div class="info-row"><div class="info-label"><i class="fas fa-calendar-plus"></i>Date Hired</div><div class="info-value" id="empDateHired"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-birthday-cake"></i>Birthdate</div><div class="info-value" id="empBirthdate"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-hashtag"></i>Age</div><div class="info-value" id="empAge"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-hourglass-half"></i>Length of Service</div><div class="info-value" id="empLengthService"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-tint"></i>Blood Type</div><div class="info-value" id="empBloodType"></div></div>
                    </div>
                </div>
                <div class="info-section">
                    <div class="section-title">Government IDs & Contributions</div>
                    <div class="info-grid">
                        <div class="info-row"><div class="info-label"><i class="fas fa-file-invoice"></i>TIN</div><div class="info-value" id="empTIN"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-shield-alt"></i>SSS</div><div class="info-value" id="empSSS"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-heartbeat"></i>PHIC</div><div class="info-value" id="empPHIC"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-home"></i>HDMF</div><div class="info-value" id="empHDMF"></div></div>
                    </div>
                </div>
                <div class="info-section">
                    <div class="section-title">Work Assignments & History</div>
                    <div class="info-grid">
                        <div class="info-row"><div class="info-label"><i class="fas fa-map-marker-alt"></i>Area of Assignment</div><div class="info-value" id="empArea"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-exchange-alt"></i>Date Transferred</div><div class="info-value" id="empDateTransferred"></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-calendar-times"></i>Last Day Employed</div><div class="info-value" id="empLastDay"></div></div>
                        <div class="info-row full-row" style="grid-column: 1 / -1;"><div class="info-label"><i class="fas fa-history"></i>Employment History</div><div class="info-value" id="empHistory"></div></div>
                    </div>
                </div>
                <div class="info-section">
                    <div class="section-title">Address Information</div>
                    <div class="info-grid">
                        <div class="info-row full-row" style="grid-column: 1 / -1;"><div class="info-label"><i class="fas fa-map-marked-alt"></i>Present Address</div><div class="info-value" id="empPresentAddress"></div></div>
                        <div class="info-row full-row" style="grid-column: 1 / -1;"><div class="info-label"><i class="fas fa-map-marked"></i>Permanent Address</div><div class="info-value" id="empPermanentAddress"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="header">
            <h1>Employee Management</h1>
            <div class="header-actions">
                <div class="view-toggle">
                    <a href="#" class="view-btn" onclick="showListView()">
                        <i class="fas fa-list"></i> List View
                    </a>
                    <a href="#" class="view-btn active" onclick="showDepartmentView()">
                        <i class="fas fa-building"></i> Department View
                    </a>
                </div>
                
            </div>
        </div>

       <!-- Dashboard Cards (Only 3 cards now) -->
       <div class="dashboard-cards" style="grid-template-columns: repeat(3, 1fr);">
            <div class="card">
                <div class="card-content">
                    <div class="card-title">Total Employees</div>
                    <div class="card-value"><?php echo $totalEmployees; ?></div>
                    <div class="card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-content">
                    <div class="card-title">Present Today</div>
                    <div class="card-value"><?php echo $presentToday; ?></div>
                    <div class="card-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-content">
                    <div class="card-title">Monthly Payroll</div>
                    <div class="card-value">₱<?php echo number_format($monthlyPayroll, 2); ?></div>
                    <div class="card-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php 
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php 
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>
       <!-- Departments Grid View -->
<div id="departmentsView" class="departments-grid">
    <?php foreach ($allDepartments as $department): ?>
        <?php $deptEmployees = $employeesByDepartment[$department] ?? []; ?>
        <div class="department-card" onclick="showDepartmentDetail('<?php echo $department; ?>')">
            <div class="department-header">
                <div class="department-name"><?php echo $department; ?></div>
                <div class="employee-count"><?php echo count($deptEmployees); ?> employees</div>
            </div>
            <div class="department-preview">
                <?php for ($i = 0; $i < min(3, count($deptEmployees)); $i++): ?>
                    <div class="preview-employee" onclick="event.stopPropagation(); showEmployeeModal(<?php echo htmlspecialchars(json_encode([
                        'name' => $deptEmployees[$i]['EmployeeName'] ?? '',
                        'email' => $deptEmployees[$i]['EmployeeEmail'] ?? '',
                        'department' => $deptEmployees[$i]['Department'] ?? '',
                        'position' => $deptEmployees[$i]['Position'] ?? '',
                        'contact' => $deptEmployees[$i]['Contact'] ?? '',
                        'salary' => $deptEmployees[$i]['base_salary'] ?? 0,
                        'riceAllowance' => $deptEmployees[$i]['rice_allowance'] ?? 0,
                        'medicalAllowance' => $deptEmployees[$i]['medical_allowance'] ?? 0,
                        'laundryAllowance' => $deptEmployees[$i]['laundry_allowance'] ?? 0,
                        'status' => $deptEmployees[$i]['Status'] ?? 'active',
                        'history' => $deptEmployees[$i]['history'] ?? '',
                        'empId' => $deptEmployees[$i]['EmployeeID'] ?? '',
                        'dateHired' => $deptEmployees[$i]['DateHired'] ?? '',
                        'birthdate' => $deptEmployees[$i]['Birthdate'] ?? '',
                        'tin' => $deptEmployees[$i]['TIN'] ?? '',
                        'sss' => $deptEmployees[$i]['SSS'] ?? '',
                        'phic' => $deptEmployees[$i]['PHIC'] ?? '',
                        'hdmf' => $deptEmployees[$i]['HDMF'] ?? '',
                        'age' => $deptEmployees[$i]['Age'] ?? '',
                        'lengthService' => $deptEmployees[$i]['LengthOfService'] ?? '',
                        'bloodType' => $deptEmployees[$i]['BloodType'] ?? '',
                        'presentAddress' => $deptEmployees[$i]['PresentHomeAddress'] ?? '',
                        'permanentAddress' => $deptEmployees[$i]['PermanentHomeAddress'] ?? '',
                        'lastDayEmployed' => $deptEmployees[$i]['LastDayEmployed'] ?? '',
                        'dateTransferred' => $deptEmployees[$i]['DateTransferred'] ?? '',
                        'areaOfAssignment' => $deptEmployees[$i]['AreaOfAssignment'] ?? '',
                        'shift' => $deptEmployees[$i]['Shift'] ?? ''
                    ]), ENT_QUOTES); ?>)">
                        <i class="fas fa-user"></i> <?php echo $deptEmployees[$i]['EmployeeName']; ?> - <?php echo $deptEmployees[$i]['Position']; ?>
                    </div>
                <?php endfor; ?>
                <?php if (count($deptEmployees) > 3): ?>
                    <div class="more-employees">+<?php echo count($deptEmployees) - 3; ?> more employees...</div>
                <?php elseif (count($deptEmployees) === 0): ?>
                    <div class="more-employees">No employees in this department</div>
                <?php endif; ?>
            </div>
            <div class="department-icon">
                <?php 
                $icons = [
                    'Treasury' => 'university',
                    'HR' => 'users',
                    'Sales' => 'handshake',
                    'Tax' => 'file-invoice-dollar',
                    'Admin' => 'user-shield',
                    'Finance' => 'chart-line',
                    'Accounting' => 'calculator',
                    'Marketing' => 'bullhorn',
                    'CMCD' => 'project-diagram',
                    'Security' => 'shield-alt'
                ];
                $icon = $icons[$department] ?? 'building';
                ?>
                <i class="fas fa-<?php echo $icon; ?>"></i>
            </div>
        </div>
    <?php endforeach; ?>
</div>

        <!-- Department Detail View -->
        <div id="departmentDetail" class="department-detail">
            <div class="detail-header">
                <h2 id="departmentTitle">Department Employees</h2>
                <button class="back-btn" onclick="showDepartmentView()">
                    <i class="fas fa-arrow-left"></i> Back to Departments
                </button>
            </div>
            
            <table class="employees-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Position</th>
                        <th>Salary</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                    
                <tbody id="departmentEmployeesTable">
                    <!-- Employee rows will be populated here by JavaScript -->
                </tbody>
            </table>
        </div>

        <!-- List View (Also needs to be updated) -->
<div id="listView" class="employee-table" style="display: none;">
    <div class="search-box" style="max-width: 360px;">
        <input type="text" id="listSearchInput" placeholder="Search employees by name, ID, email...">
        <i class="fas fa-search"></i>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Department</th>
                <th>Position</th>
                <th>Salary</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($employees)): ?>
                <tr>
                    <td colspan="7" style="text-align: center;">No employees found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($employees as $employee): ?>
                    <tr onclick="showEmployeeModal(<?php echo htmlspecialchars(json_encode([
                        'name' => $employee['EmployeeName'] ?? '',
                        'email' => $employee['EmployeeEmail'] ?? '',
                        'department' => $employee['Department'] ?? '',
                        'position' => $employee['Position'] ?? '',
                        'contact' => $employee['Contact'] ?? '',
                        'salary' => $employee['base_salary'] ?? 0,
                        'riceAllowance' => $employee['rice_allowance'] ?? 0,
                        'medicalAllowance' => $employee['medical_allowance'] ?? 0,
                        'laundryAllowance' => $employee['laundry_allowance'] ?? 0,
                        'status' => $employee['Status'] ?? 'active',
                        'history' => $employee['history'] ?? '',
                        'empId' => $employee['EmployeeID'] ?? '',
                        'dateHired' => $employee['DateHired'] ?? '',
                        'birthdate' => $employee['Birthdate'] ?? '',
                        'tin' => $employee['TIN'] ?? '',
                        'sss' => $employee['SSS'] ?? '',
                        'phic' => $employee['PHIC'] ?? '',
                        'hdmf' => $employee['HDMF'] ?? '',
                        'age' => $employee['Age'] ?? '',
                        'lengthService' => $employee['LengthOfService'] ?? '',
                        'bloodType' => $employee['BloodType'] ?? '',
                        'presentAddress' => $employee['PresentHomeAddress'] ?? '',
                        'permanentAddress' => $employee['PermanentHomeAddress'] ?? '',
                        'lastDayEmployed' => $employee['LastDayEmployed'] ?? '',
                        'dateTransferred' => $employee['DateTransferred'] ?? '',
                        'areaOfAssignment' => $employee['AreaOfAssignment'] ?? '',
                        'shift' => $employee['Shift'] ?? ''
                    ]), ENT_QUOTES); ?>)">
                        <td><?php echo $employee['EmployeeID'] ?? ''; ?></td>
                        <td><?php echo $employee['EmployeeName'] ?? ''; ?></td>
                        <td><?php echo $employee['EmployeeEmail'] ?? ''; ?></td>
                        <td><?php echo $employee['Department'] ?? ''; ?></td>
                        <td><?php echo $employee['Position'] ?? ''; ?></td>
                        <td>₱<?php echo number_format($employee['base_salary'] ?? 0, 2); ?></td>
                        <td>
                            <button class="action-btn view-btn-action" onclick="event.stopPropagation(); showEmployeeModal(<?php echo htmlspecialchars(json_encode([
                                'name' => $employee['EmployeeName'] ?? '',
                                'email' => $employee['EmployeeEmail'] ?? '',
                                'department' => $employee['Department'] ?? '',
                                'position' => $employee['Position'] ?? '',
                                'contact' => $employee['Contact'] ?? '',
                                'salary' => $employee['base_salary'] ?? 0,
                                'riceAllowance' => $employee['rice_allowance'] ?? 0,
                                'medicalAllowance' => $employee['medical_allowance'] ?? 0,
                                'laundryAllowance' => $employee['laundry_allowance'] ?? 0,
                                'status' => $employee['Status'] ?? 'active',
                                'history' => $employee['history'] ?? '',
                                'empId' => $employee['EmployeeID'] ?? '',
                                'dateHired' => $employee['DateHired'] ?? '',
                                'birthdate' => $employee['Birthdate'] ?? '',
                                'tin' => $employee['TIN'] ?? '',
                                'sss' => $employee['SSS'] ?? '',
                                'phic' => $employee['PHIC'] ?? '',
                                'hdmf' => $employee['HDMF'] ?? '',
                                'age' => $employee['Age'] ?? '',
                                'lengthService' => $employee['LengthOfService'] ?? '',
                                'bloodType' => $employee['BloodType'] ?? '',
                                'presentAddress' => $employee['PresentHomeAddress'] ?? '',
                                'permanentAddress' => $employee['PermanentHomeAddress'] ?? '',
                                'lastDayEmployed' => $employee['LastDayEmployed'] ?? '',
                                'dateTransferred' => $employee['DateTransferred'] ?? '',
                                'areaOfAssignment' => $employee['AreaOfAssignment'] ?? '',
                                'shift' => 'Regular'
                            ]), ENT_QUOTES); ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div class="pagination">
        <button><i class="fas fa-chevron-left"></i></button>
        <button class="active">1</button>
        <button>2</button>
        <button>3</button>
        <button><i class="fas fa-chevron-right"></i></button>
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
       
       // Updated JavaScript function to handle employee data properly
function showEmployeeModal(employeeData) {
    const set = (id, val, fallback = 'N/A') => { 
        const element = document.getElementById(id);
        if (element) {
            element.innerText = (val && String(val).trim().length) ? val : fallback; 
        }
    };
    
    const formatMoney = (num) => {
        const n = Number(num);
        if (isNaN(n)) return '0.00';
        return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    // Get initials for avatar
    const getInitials = (name) => {
        if (!name) return 'N/A';
        const parts = name.trim().split(' ');
        if (parts.length >= 2) {
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }
        return name.substring(0, 2).toUpperCase();
    };

    // Set avatar initials
    const avatarElement = document.getElementById('empAvatar');
    if (avatarElement) {
        avatarElement.innerHTML = getInitials(employeeData.name);
    }

    // Set header information
    set('modalEmpName', employeeData.name);
    set('modalEmpId', employeeData.empId);
    set('modalEmpDept', employeeData.department);
    set('modalEmpPos', employeeData.position);

    // Set all the employee data
    set('empName', employeeData.name);
    set('empId', employeeData.empId);
    set('empEmail', employeeData.email);
    set('empDepartment', employeeData.department);
    set('empPosition', employeeData.position);
    set('empContact', employeeData.contact);
    set('empDateHired', employeeData.dateHired);
    set('empBirthdate', employeeData.birthdate);
    set('empTIN', employeeData.tin);
    set('empSSS', employeeData.sss);
    set('empPHIC', employeeData.phic);
    set('empHDMF', employeeData.hdmf);
    set('empHistory', employeeData.history, 'No history available');
    set('empAge', employeeData.age);
    set('empLengthService', employeeData.lengthService);
    set('empBloodType', employeeData.bloodType);
    set('empPresentAddress', employeeData.presentAddress);
    set('empPermanentAddress', employeeData.permanentAddress);
    set('empLastDay', employeeData.lastDayEmployed);
    set('empDateTransferred', employeeData.dateTransferred);
    set('empArea', employeeData.areaOfAssignment);
    // Handle shift display with NSD differential
    const shiftElement = document.getElementById('empShift');
    const nightShiftElement = document.getElementById('nightShiftDifferential');
    
    if (employeeData.shift === '22:00-06:00') {
        shiftElement.innerText = 'Night Shift (10:00 PM - 6:00 AM)';
        nightShiftElement.style.display = 'flex';
    } else {
        shiftElement.innerText = employeeData.shift || 'Not Available';
        nightShiftElement.style.display = 'none';
    }
    
    // Handle salary with proper formatting
    const salaryElement = document.getElementById('empSalary');
    
    if (salaryElement) {
        salaryElement.innerText = formatMoney(employeeData.salary);
    }
    
    // Handle allowances with proper formatting
    const riceAllowanceElement = document.getElementById('empRiceAllowance');
    if (riceAllowanceElement) {
        riceAllowanceElement.innerText = formatMoney(employeeData.riceAllowance || 0);
    }
    
    const medicalAllowanceElement = document.getElementById('empMedicalAllowance');
    if (medicalAllowanceElement) {
        medicalAllowanceElement.innerText = formatMoney(employeeData.medicalAllowance || 0);
    }
    
    const laundryAllowanceElement = document.getElementById('empLaundryAllowance');
    if (laundryAllowanceElement) {
        laundryAllowanceElement.innerText = formatMoney(employeeData.laundryAllowance || 0);
    }
    
    // Handle status with proper styling
    const statusElement = document.getElementById('empStatus');
    if (statusElement) {
        const safeStatus = (employeeData.status || 'active').toString();
        statusElement.innerText = safeStatus.toUpperCase();
        statusElement.className = 'status-badge';
        const normalized = safeStatus.trim().toLowerCase();
        if (normalized === 'active') {
            statusElement.classList.add('status-active');
        } else {
            statusElement.classList.add('status-inactive');
        }
    }
    
    // Show the modal with animation
    const modal = document.getElementById('employeeModal');
    if (modal) {
        modal.style.display = 'flex';
        // Trigger reflow to enable animation
        modal.offsetHeight;
    }
}

// Close employee modal
function closeModal() {
    const modal = document.getElementById('employeeModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Close when clicking outside modal content
window.addEventListener('click', function(event) {
    const modal = document.getElementById('employeeModal');
    if (modal && event.target === modal) {
        closeModal();
    }
});

// Close on Escape key
window.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeModal();
    }
});

        

        

        // Filter and search functions
        function filterEmployees(department) {
            window.location.href = 'HREmployees.php?department=' + encodeURIComponent(department);
        }

        function searchEmployees(query) {
            const listView = document.getElementById('listView');
            if (!listView) return;
            const rows = listView.querySelectorAll('tbody tr');
            const q = (query || '').toLowerCase();
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(q) ? '' : 'none';
            });
        }

        // (removed duplicated window onclick override)
         // Department view functions
         function showDepartmentView() {
            document.getElementById('departmentsView').style.display = 'grid';
            document.getElementById('departmentDetail').style.display = 'none';
            document.getElementById('listView').style.display = 'none';
            
            // Update active button state
            document.querySelector('.view-toggle .view-btn').classList.remove('active');
            document.querySelector('.view-toggle .view-btn:nth-child(2)').classList.add('active');
        }

        function showListView() {
            document.getElementById('departmentsView').style.display = 'none';
            document.getElementById('departmentDetail').style.display = 'none';
            document.getElementById('listView').style.display = 'block';
            
            // Update active button state
            document.querySelector('.view-toggle .view-btn').classList.add('active');
            document.querySelector('.view-toggle .view-btn:nth-child(2)').classList.remove('active');
            // Bind search input for list view
            const listSearch = document.getElementById('listSearchInput');
            if (listSearch && !listSearch._bound) {
                listSearch.addEventListener('input', function(){ searchEmployees(this.value); });
                listSearch._bound = true;
            }
        }

        // Update the showDepartmentDetail function to use the new modal format
function showDepartmentDetail(department) {
    document.getElementById('departmentsView').style.display = 'none';
    document.getElementById('departmentDetail').style.display = 'block';
    document.getElementById('departmentTitle').innerText = department + ' Employees';
    
    const tableBody = document.getElementById('departmentEmployeesTable');
    tableBody.innerHTML = '';
    
    const deptEmployees = <?php echo json_encode($employeesByDepartment); ?>[department] || [];
    
    if (deptEmployees.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="7" class="no-employees">No employees found in this department</td></tr>';
        return;
    }
    
    deptEmployees.forEach(emp => {
        const employeeData = {
            name: emp.EmployeeName || '',
            email: emp.EmployeeEmail || '',
            department: emp.Department || '',
            position: emp.Position || '',
            contact: emp.Contact || '',
            salary: emp.base_salary || 0,
            riceAllowance: emp.rice_allowance || 0,
            medicalAllowance: emp.medical_allowance || 0,
            laundryAllowance: emp.laundry_allowance || 0,
            status: emp.Status || 'active',
            history: emp.history || '',
            empId: emp.EmployeeID || '',
            dateHired: emp.DateHired || '',
            birthdate: emp.Birthdate || '',
            tin: emp.TIN || '',
            sss: emp.SSS || '',
            phic: emp.PHIC || '',
            hdmf: emp.HDMF || '',
            age: emp.Age || '',
            lengthService: emp.LengthOfService || '',
            bloodType: emp.BloodType || '',
            presentAddress: emp.PresentHomeAddress || '',
            permanentAddress: emp.PermanentHomeAddress || '',
            lastDayEmployed: emp.LastDayEmployed || '',
            dateTransferred: emp.DateTransferred || '',
            areaOfAssignment: emp.AreaOfAssignment || '',
            shift: emp.Shift || ''
        };
        
        const row = document.createElement('tr');
        row.onclick = () => showEmployeeModal(employeeData);
        
        // Determine status class (case-insensitive)
        const empStatus = (emp.Status || 'active').toString();
        const statusClass = empStatus.toLowerCase().trim() === 'active' ? 'status-active' : 'status-inactive';
        
        row.innerHTML = `
            <td>${emp.EmployeeID || ''}</td>
            <td>${emp.EmployeeName || ''}</td>
            <td>${emp.EmployeeEmail || ''}</td>
            <td>${emp.Position || ''}</td>
            <td>₱${emp.base_salary ? Number(emp.base_salary).toFixed(2) : '0.00'}</td>
            <td><span class="status-badge ${statusClass}">${empStatus.toUpperCase()}</span></td>
            <td><button class="action-btn view-btn-action"><i class="fas fa-eye"></i> View</button></td>
        `;
        
        const viewBtn = row.querySelector('.view-btn-action');
        viewBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            showEmployeeModal(employeeData);
        });
        
        tableBody.appendChild(row);
    });
}

        // Initialize with department view
        showDepartmentView();
        // Toggle sidebar collapse/expand (guard missing IDs)
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle && sidebar) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                const icon = this.querySelector('i');
                if (icon) {
                    if (sidebar.classList.contains('collapsed')) {
                        icon.classList.remove('fa-bars');
                        icon.classList.add('fa-chevron-right');
                    } else {
                        icon.classList.remove('fa-chevron-right');
                        icon.classList.add('fa-bars');
                    }
                }
            });
        }

        // Mobile responsiveness (guard missing elements)
        function checkScreenSize() {
            if (!sidebar || !sidebarToggle) return;
            if (window.innerWidth <= 992) {
                sidebar.classList.remove('collapsed');
                sidebarToggle.style.display = 'flex';
            } else {
                sidebarToggle.style.display = 'none';
            }
        }

        // Initialize on load and resize
        window.addEventListener('load', checkScreenSize);
        window.addEventListener('resize', checkScreenSize);

        // Mobile menu toggle (guard missing elements)
        if (sidebarToggle && sidebar) {
            sidebarToggle.addEventListener('click', function() {
                if (window.innerWidth <= 992) {
                    sidebar.classList.toggle('mobile-open');
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