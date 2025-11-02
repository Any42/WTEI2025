<?php
session_start();

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'depthead'])) {
    header("Location: Login.php");
    exit;
}

// Include centralized payroll computations
require_once 'payroll_computations.php';

// Get user information
$user_id = $_SESSION['userid'];
$user_name = $_SESSION['username'];
$user_role = $_SESSION['role'];

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Get payroll period
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$department_filter = isset($_GET['department']) ? $conn->real_escape_string($_GET['department']) : '';

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $current_month)) {
    $current_month = date('Y-m');
}

// Additional deductions are now handled by payroll_computations.php
// No need to query deductions table as it has been removed

// Get employees for payroll generation - use pre-calculated total_hours from attendance table
$employees_query = "SELECT e.*, 
                   COUNT(DISTINCT DATE(a.attendance_date)) as days_worked,
                   COALESCE(SUM(a.total_hours), 0) as total_hours,
                   COALESCE(e.base_salary, 0) as base_salary
                   FROM empuser e
                   LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID 
                   AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                   AND a.time_in IS NOT NULL
                   WHERE 1=1";

$params = [$current_month];
$types = "s";

if ($department_filter) {
    $employees_query .= " AND e.Department = ?";
    $params[] = $department_filter;
    $types .= "s";
}

$employees_query .= " GROUP BY e.EmployeeID ORDER BY e.Department, e.EmployeeName";

$stmt = $conn->prepare($employees_query);
$employees = [];
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();
}

// Get departments for filter
$departments = [];
$dept_query = "SELECT DISTINCT Department FROM empuser WHERE Department IS NOT NULL AND Department != '' ORDER BY Department";
$dept_result = $conn->query($dept_query);
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row['Department'];
    }
}

// Calculate payroll analytics
$total_employees = count($employees);
$total_salary = 0;
$total_deductions = 0;
$total_net_pay = 0;

foreach ($employees as $employee) {
    $payroll_data = calculatePayroll($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
    $total_salary += $payroll_data['gross_pay'];
    $total_deductions += $payroll_data['total_deductions'];
    $total_net_pay += $payroll_data['net_pay'];
}

// Get available months for dropdown
$months_query = "SELECT DISTINCT DATE_FORMAT(attendance_date, '%Y-%m') as month 
                 FROM attendance 
                 ORDER BY month DESC";
$months_result = $conn->query($months_query);
$available_months = [];
if ($months_result) {
    while ($row = $months_result->fetch_assoc()) {
        $available_months[] = $row['month'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll History - WTEI</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="depthead-styles.css/depthead-styles.css?v=<?php echo time(); ?>">
    
    <style>
    /* Base Styles */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Poppins', sans-serif;
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
        transition: all 0.3s ease;
        z-index: 100;
    }
    
    .logo {
        font-weight: bold;
        font-size: 32px;
        padding: 25px;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 20px;
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
        background-color: rgba(219, 226, 239, 0.2);
        color: #F9F7F7;
        transform: translateX(5px);
    }

    .menu-item i {
        margin-right: 15px;
        width: 20px;
        text-align: center;
        font-size: 18px;
    }

    .logout-btn {
        background-color: #DBE2EF;
        color: #112D4E;
        border: 1px solid #3F72AF;
        padding: 15px;
        margin: 20px;
        border-radius: 12px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: all 0.3s;
        font-weight: 500;
    }

    .logout-btn:hover {
        background-color: #3F72AF;
        color: white;
        transform: translateY(-2px);
    }

    .logout-btn i {
        margin-right: 10px;
    }

    /* User Role Indicator */
    .user-role-indicator {
        margin: 0;
        padding: 0;
    }

    .role-badge {
        background: linear-gradient(135deg, #3F72AF 0%, #2563af 50%, #112D4E 100%);
        border-radius: 12px;
        padding: 12px 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
        border: 2px solid transparent;
        position: relative;
        overflow: hidden;
        min-width: 120px;
    }

    .role-badge::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.6s ease;
    }

    .role-badge:hover::before {
        left: 100%;
    }

    .role-badge:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(63, 114, 175, 0.3);
        border-color: rgba(255, 255, 255, 0.3);
    }

    .role-badge.admin {
        background: linear-gradient(135deg, #3F72AF 0%, #2563af 50%, #112D4E 100%);
    }

    .role-badge.admin:hover {
        box-shadow: 0 8px 25px rgba(63, 114, 175, 0.4);
    }

    .role-badge.depthead {
        background: linear-gradient(135deg, #28a745 0%, #20c997 50%, #17a2b8 100%);
    }

    .role-badge.depthead:hover {
        box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
    }

    .role-icon {
        width: 35px;
        height: 35px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        color: white;
        transition: all 0.3s ease;
        border: 2px solid rgba(255, 255, 255, 0.3);
    }

    .role-badge:hover .role-icon {
        transform: scale(1.1);
        background: rgba(255, 255, 255, 0.3);
        border-color: rgba(255, 255, 255, 0.5);
    }

    .role-info {
        flex: 1;
        min-width: 0;
    }

    .role-title {
        font-size: 14px;
        font-weight: 700;
        color: white;
        margin: 0 0 2px 0;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    }

    .role-subtitle {
        font-size: 12px;
        color: rgba(255, 255, 255, 0.8);
        margin: 0;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 150px;
    }

    /* Role-specific styling */
    .role-badge.admin .role-icon {
        background: rgba(255, 255, 255, 0.25);
        border-color: rgba(255, 255, 255, 0.4);
    }

    .role-badge.depthead .role-icon {
        background: rgba(255, 255, 255, 0.2);
        border-color: rgba(255, 255, 255, 0.3);
    }

    .main-content {
        flex: 1;
        margin-left: 280px;
        padding: 20px;
        transition: margin-left 0.3s ease;
    }

    .header {
        background: white;
        padding: 20px 30px;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .header-left {
        display: flex;
        align-items: center;
    }

    .header-right {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .header h1 {
        color: #112D4E;
        font-size: 28px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .header-actions {
        display: flex;
        gap: 15px;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-secondary {
        background: #6c757d;
        color: white;
    }

    .btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }

    .btn-primary {
        background: #3F72AF;
        color: white;
    }

    .btn-primary:hover {
        background: #112D4E;
        transform: translateY(-2px);
    }

    /* Luxury Mathematical/Computational Theme */
    :root {
        --primary-dark: #112D4E;
        --primary-blue: #3F72AF;
        --accent-cyan: #DBE2EF;
        --accent-purple: #F9F7F7;
        --bg-light: #F9F7F7;
        --text-dark: #112D4E;
        --border-light: #DBE2EF;
        --gradient-math: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        --gradient-data: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        --shadow-soft: 0 4px 15px rgba(0, 0, 0, 0.08);
        --shadow-hover: 0 8px 25px rgba(63, 114, 175, 0.2);
        
        /* Luxury Color Palette - Clear Premium Theme */
        --luxury-silver: #B8B8B8;
        --luxury-platinum: #D3D3D3;
        --luxury-steel: #A0A0A0;
        --luxury-pearl: #F5F5F5;
        --luxury-accent: #2C5F8A;
        --luxury-gradient: linear-gradient(135deg, #2C5F8A 0%, #4A90E2 50%, #87CEEB 100%);
        --luxury-shadow: 0 20px 40px rgba(44, 95, 138, 0.25);
        --luxury-glow: 0 0 30px rgba(44, 95, 138, 0.4);
    }

    /* Luxury Animations */
    @keyframes luxuryGlow {
        0%, 100% { box-shadow: 0 0 20px rgba(44, 95, 138, 0.3); }
        50% { box-shadow: 0 0 40px rgba(44, 95, 138, 0.5), 0 0 60px rgba(74, 144, 226, 0.4); }
    }

    @keyframes luxuryFloat {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-5px); }
    }

    @keyframes luxuryShimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }

    @keyframes luxuryPulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }

    /* Luxury Card Styles */
    .luxury-card {
        position: relative;
        background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
        border: 2px solid transparent;
        background-clip: padding-box;
        border-radius: 20px;
        overflow: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        animation: luxuryFloat 6s ease-in-out infinite;
    }

    .luxury-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--luxury-gradient);
        border-radius: 20px;
        padding: 2px;
        mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
        mask-composite: exclude;
        -webkit-mask-composite: xor;
        z-index: -1;
    }

    .luxury-card:hover {
        transform: translateY(-10px) scale(1.02);
        animation: luxuryGlow 2s ease-in-out infinite;
        box-shadow: var(--luxury-shadow);
    }

    .luxury-card .card-glow {
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(44, 95, 138, 0.15) 0%, transparent 70%);
        opacity: 0;
        transition: opacity 0.3s ease;
        pointer-events: none;
    }

    .luxury-card:hover .card-glow {
        opacity: 1;
        animation: luxuryShimmer 2s linear infinite;
    }

    .luxury-btn {
        background: var(--luxury-gradient);
        border: none;
        color: white;
        font-weight: 600;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        box-shadow: 0 4px 15px rgba(63, 114, 175, 0.3);
        transition: all 0.3s ease;
    }

    .luxury-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(44, 95, 138, 0.6);
        animation: luxuryPulse 0.6s ease-in-out;
    }

    /* Minimalist Summary Cards */
    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .summary-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: all 0.4s ease;
        position: relative;
        overflow: hidden;
        backdrop-filter: blur(10px);
    }

    .summary-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--gradient-primary);
        opacity: 0;
        transition: opacity 0.4s ease;
        z-index: -1;
    }

    .summary-card:hover::before {
        opacity: 0.1;
    }

    .summary-card:hover {
        transform: translateY(-8px) scale(1.02);
        box-shadow: var(--luxury-shadow);
        border-color: transparent;
    }

    /* Individual card gradients */
    .summary-card:nth-child(1)::before {
        background: var(--gradient-math);
    }

    .summary-card:nth-child(2)::before {
        background: var(--luxury-gradient);
    }

    .summary-card:nth-child(3)::before {
        background: var(--gradient-data);
    }

    .summary-card:nth-child(4)::before {
        background: var(--luxury-gradient);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f0f0f0;
    }

    .card-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
        color: var(--primary-dark);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header i {
        color: var(--luxury-accent);
        font-size: 20px;
    }

    .card-content {
        text-align: center;
    }

    .card-value {
        font-size: 36px;
        font-weight: 800;
        color: var(--primary-dark);
        margin-bottom: 10px;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .card-subtitle {
        font-size: 14px;
        color: #6c757d;
        font-weight: 500;
    }

    .progress-bar {
        width: 100%;
        height: 8px;
        background: #e9ecef;
        border-radius: 10px;
        overflow: hidden;
        margin: 15px 0;
    }

    .progress-fill {
        height: 100%;
        background: var(--luxury-gradient);
        border-radius: 10px;
        transition: width 0.8s ease;
        position: relative;
    }

    .progress-fill::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
        animation: luxuryShimmer 2s linear infinite;
    }

    /* Current Month Display */
    .current-month-display {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
        padding: 20px 30px;
        border-radius: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        border: 2px solid transparent;
        background-clip: padding-box;
        position: relative;
        overflow: hidden;
    }

    .current-month-display::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--luxury-gradient);
        border-radius: 16px;
        padding: 2px;
        mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
        mask-composite: exclude;
        -webkit-mask-composite: xor;
        z-index: -1;
    }

    .month-info {
        display: flex;
        align-items: center;
        gap: 12px;
        color: var(--primary-dark);
        font-weight: 600;
    }

    .month-info i {
        color: var(--luxury-accent);
        font-size: 18px;
    }

    .month-label {
        font-size: 14px;
        color: #6c757d;
    }

    .month-value {
        font-size: 16px;
        font-weight: 700;
        color: var(--primary-dark);
        background: var(--luxury-gradient);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .filter-item {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .filter-item i {
        color: var(--luxury-accent);
        font-size: 16px;
    }

    .compact-select {
        padding: 8px 12px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        background: white;
        color: var(--primary-dark);
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .compact-select:focus {
        outline: none;
        border-color: var(--luxury-accent);
        box-shadow: 0 0 0 3px rgba(44, 95, 138, 0.2);
    }

    /* Department Search Section */
    .department-search-section {
        margin-bottom: 30px;
        background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        border: 2px solid transparent;
        background-clip: padding-box;
        position: relative;
        overflow: hidden;
        animation: slideDown 0.5s ease-out;
    }

    .department-search-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--luxury-gradient);
        border-radius: 20px;
        padding: 2px;
        mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
        mask-composite: exclude;
        -webkit-mask-composite: xor;
        z-index: -1;
    }

    .department-header-card {
        background: var(--luxury-gradient);
        color: white;
        padding: 25px 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-radius: 18px 18px 0 0;
    }

    .dept-header-info {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .dept-header-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        font-weight: 700;
        color: white;
        font-family: 'JetBrains Mono', monospace;
    }

    .dept-header-details h3 {
        font-size: 24px;
        font-weight: 700;
        margin: 0 0 5px 0;
        color: white;
    }

    .dept-header-details p {
        font-size: 14px;
        margin: 0;
        opacity: 0.9;
    }

    .search-controls {
        display: flex;
        gap: 15px;
        align-items: center;
        flex-wrap: wrap;
    }

    .search-input {
        position: relative;
        min-width: 300px;
    }

    .search-input input {
        width: 100%;
        padding: 12px 45px 12px 15px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 25px;
        background: rgba(255, 255, 255, 0.1);
        color: white;
        font-size: 14px;
        backdrop-filter: blur(10px);
        transition: all 0.3s ease;
    }

    .search-input input::placeholder {
        color: rgba(255, 255, 255, 0.7);
    }

    .search-input input:focus {
        outline: none;
        border-color: rgba(255, 255, 255, 0.6);
        background: rgba(255, 255, 255, 0.2);
    }

    .search-input i {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: rgba(255, 255, 255, 0.7);
        font-size: 16px;
    }

    .search-loading {
        display: none;
        align-items: center;
        gap: 10px;
        color: rgba(255, 255, 255, 0.8);
        font-size: 14px;
    }

    .search-loading i {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    /* Modern Dashboard Styles */
    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin: 30px 0;
    }

    .summary-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        border: 1px solid #e9ecef;
        transition: all 0.3s ease;
    }

    .summary-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }

    .card-header h3 {
        font-size: 14px;
        font-weight: 600;
        color: #6c757d;
        margin: 0;
        line-height: 1.3;
    }

    .card-dropdown {
        margin-left: 10px;
    }

    .mini-select {
        font-size: 12px;
        padding: 4px 8px;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        background: white;
        color: #495057;
    }

    .card-content {
        text-align: left;
    }

    .card-value {
        font-size: 28px;
        font-weight: 700;
        color: #112D4E;
        margin-bottom: 5px;
        line-height: 1.2;
    }

    .card-subtitle {
        font-size: 13px;
        color: #6c757d;
        margin-bottom: 10px;
    }

    .progress-bar {
        width: 100%;
        height: 8px;
        background: #e9ecef;
        border-radius: 4px;
        overflow: hidden;
        margin: 10px 0;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #3F72AF 0%, #112D4E 100%);
        border-radius: 4px;
        transition: width 0.3s ease;
    }

    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 6px;
        margin-top: 10px;
    }

    .action-buttons-section {
        display: flex;
        gap: 15px;
        margin: 30px 0;
        justify-content: flex-start;
    }

    .btn-outline {
        background: white;
        color: #3F72AF;
        border: 2px solid #3F72AF;
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .btn-outline:hover {
        background: #3F72AF;
        color: white;
        transform: translateY(-2px);
    }

    .department-overview {
        margin: 40px 0;
    }

    .section-header {
        margin-bottom: 25px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .section-header h2 {
        font-size: 24px;
        font-weight: 700;
        color: #112D4E;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .department-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }

    .department-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: all 0.4s ease;
        display: flex;
        align-items: center;
        gap: 16px;
        cursor: pointer;
        position: relative;
        overflow: hidden;
        backdrop-filter: blur(10px);
    }

    .department-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--luxury-gradient);
        opacity: 0;
        transition: opacity 0.4s ease;
        z-index: -1;
    }

    .department-card:hover::before {
        opacity: 0.1;
    }

    .department-card:hover {
        transform: translateY(-4px) scale(1.02);
        box-shadow: var(--luxury-shadow);
        border-color: transparent;
    }

    .department-card.active {
        border-color: transparent;
        background: rgba(44, 95, 138, 0.1);
        box-shadow: var(--luxury-shadow);
    }

    .department-card.active::before {
        opacity: 0.15;
    }

    .dept-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--luxury-gradient);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 16px;
        font-weight: 600;
        box-shadow: var(--luxury-shadow);
        transition: all 0.3s ease;
    }

    .dept-icon:hover {
        transform: scale(1.1);
        box-shadow: var(--luxury-glow);
    }

    .dept-info {
        flex: 1;
    }

    .dept-name {
        font-size: 16px;
        font-weight: 600;
        color: #112D4E;
        margin: 0 0 5px 0;
    }

    .dept-stats {
        font-size: 12px;
        color: #6c757d;
        margin: 0;
    }

    .employee-payslips {
        margin: 40px 0;
    }

    .filter-controls {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .filter-controls .btn {
        padding: 8px 16px;
        font-size: 12px;
    }

    #payslipsContainer {
        display: flex;
        flex-direction: column;
        gap: 30px;
    }

    .department-section {
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.2);
        overflow: hidden;
        transition: all 0.4s ease;
    }

    .department-section:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .department-header {
        background: linear-gradient(135deg, #3F72AF 0%, #2563af 50%, #112D4E 100%);
        color: white;
        padding: 20px 25px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .department-header:hover {
        background: linear-gradient(135deg, #4A90E2 0%, #3F72AF 50%, #2563af 100%);
    }

    .dept-header-content {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .dept-header-icon {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        font-weight: 700;
        color: white;
    }

    .dept-header-info h3 {
        margin: 0 0 5px 0;
        font-size: 20px;
        font-weight: 700;
        color: white;
    }

    .dept-header-info p {
        margin: 0;
        font-size: 14px;
        opacity: 0.9;
        font-weight: 500;
    }

    .dept-header-actions {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .department-employees {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        padding: 25px;
        background: #f8f9fa;
        transition: all 0.3s ease;
    }

    .department-employees.collapsed {
        display: none;
    }

    .department-employees.expanded {
        display: flex;
        animation: slideDown 0.3s ease-out;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            max-height: 0;
        }
        to {
            opacity: 1;
            max-height: 1000px;
        }
    }

    /* No Results Message */
    .no-results-message {
        display: none;
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        margin: 20px 0;
    }

    .no-results-content {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 15px;
    }

    .no-results-content i {
        font-size: 48px;
        color: #6c757d;
        opacity: 0.5;
    }

    .no-results-content h3 {
        margin: 0;
        font-size: 20px;
        color: #112D4E;
        font-weight: 600;
    }

    .no-results-content p {
        margin: 0;
        color: #6c757d;
        font-size: 14px;
    }

    /* Enhanced Search Loading */
    .search-loading {
        display: none;
        align-items: center;
        gap: 10px;
        color: rgba(255, 255, 255, 0.8);
        font-size: 14px;
        animation: pulse 1.5s ease-in-out infinite;
    }

    @keyframes pulse {
        0%, 100% { opacity: 0.6; }
        50% { opacity: 1; }
    }

    .employee-card {
        background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: 20px;
        padding: 20px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        border: 2px solid transparent;
        background-clip: padding-box;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
        cursor: pointer;
        animation: luxuryFloat 8s ease-in-out infinite;
        width: 300px;
        height: 150px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .employee-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--luxury-gradient);
        border-radius: 16px;
        padding: 2px;
        mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
        mask-composite: exclude;
        -webkit-mask-composite: xor;
        z-index: -1;
        opacity: 0;
        transition: opacity 0.4s ease;
    }

    .employee-card::after {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(44, 95, 138, 0.15) 0%, transparent 70%);
        opacity: 0;
        transition: opacity 0.4s ease;
        pointer-events: none;
    }

    .employee-card:hover {
        transform: translateY(-10px) scale(1.02);
        animation: luxuryGlow 2s ease-in-out infinite;
        box-shadow: var(--luxury-shadow);
    }

    .employee-card:hover::before {
        opacity: 1;
    }

    .employee-card:hover::after {
        opacity: 1;
    }

    .employee-card:hover .employee-avatar {
        transform: scale(1.1) rotate(5deg);
    }

    .employee-card:hover::before {
        animation: luxuryShimmer 2s linear infinite;
    }

    .employee-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 15px;
        position: relative;
        z-index: 1;
    }

    .employee-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: var(--luxury-gradient);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 20px;
        font-weight: 700;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .employee-avatar::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.3), transparent);
        transform: rotate(45deg);
        transition: all 0.6s ease;
    }

    .employee-card:hover .employee-avatar::before {
        left: 100%;
    }

    .employee-info {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-align: center;
    }

    .employee-info h3 {
        margin: 0 0 5px 0;
        font-size: 18px;
        font-weight: 600;
        color: #112D4E;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .employee-info p {
        margin: 0;
        font-size: 14px;
        color: #6c757d;
        font-weight: 500;
    }

    .employee-payroll-computation {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 20px;
    }

    .employee-computation-item {
        text-align: center;
        padding: 12px;
        background: rgba(44, 95, 138, 0.05);
        border-radius: 12px;
        border: 1px solid rgba(44, 95, 138, 0.1);
        transition: all 0.3s ease;
    }

    .employee-computation-item:hover {
        background: rgba(44, 95, 138, 0.1);
        transform: translateY(-2px);
    }

    .employee-computation-label {
        font-size: 12px;
        color: #6c757d;
        margin-bottom: 5px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .employee-computation-value {
        font-size: 16px;
        font-weight: 700;
        color: #112D4E;
    }

    .employee-net-pay-banner {
        background: var(--luxury-gradient);
        color: white;
        padding: 15px;
        border-radius: 12px;
        text-align: center;
        position: relative;
        overflow: hidden;
        box-shadow: 0 8px 25px rgba(44, 95, 138, 0.3);
    }

    .employee-net-pay-banner::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: all 0.6s ease;
    }

    .employee-card:hover .employee-net-pay-banner::before {
        left: 100%;
    }

    .employee-net-pay-label {
        font-size: 12px;
        margin-bottom: 5px;
        opacity: 0.9;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .employee-net-pay-value {
        font-size: 20px;
        font-weight: 700;
        color: white;
        font-family: 'JetBrains Mono', monospace;
    }

    .payslip-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 1px solid #e9ecef;
    }

    .employee-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .employee-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 18px;
        font-weight: 600;
    }

    .employee-details h4 {
        margin: 0 0 5px 0;
        font-size: 16px;
        font-weight: 600;
        color: #112D4E;
    }

    .employee-details p {
        margin: 0;
        font-size: 12px;
        color: #6c757d;
    }

    .payslip-actions {
        display: flex;
        gap: 10px;
    }

    .btn-view {
        background: #3F72AF;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .btn-view:hover {
        background: #112D4E;
        transform: translateY(-1px);
    }

    .btn-export {
        background: #28a745;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .btn-export:hover {
        background: #218838;
        transform: translateY(-1px);
    }

    .payslip-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 15px;
    }

    .summary-item {
        text-align: center;
        padding: 10px;
        background: #f8f9fa;
        border-radius: 8px;
    }

    .summary-label {
        font-size: 11px;
        color: #6c757d;
        margin-bottom: 5px;
        font-weight: 500;
    }

    .summary-value {
        font-size: 14px;
        font-weight: 600;
        color: #112D4E;
    }

    .summary-value.positive {
        color: #28a745;
    }

    .summary-value.negative {
        color: #dc3545;
    }

    /* Enhanced Responsive Design */
    @media (max-width: 992px) {
        .sidebar {
            width: 80px;
            overflow: hidden;
        }

        .sidebar .logo {
            font-size: 24px;
            padding: 15px 0;
        }

        .menu-item span, .logout-btn span {
            display: none;
        }

        .menu-item, .logout-btn {
            justify-content: center;
            padding: 15px;
        }

        .menu-item i, .logout-btn i {
            margin-right: 0;
        }

        .header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }

        .header-right {
            width: 100%;
            justify-content: space-between;
        }

        /* Hide role indicator text on mobile */
        .user-role-indicator .role-info {
            display: none;
        }

        .user-role-indicator .role-badge {
            justify-content: center;
            padding: 8px 12px;
            min-width: auto;
        }

        .user-role-indicator .role-icon {
            width: 30px;
            height: 30px;
            font-size: 14px;
        }

        .main-content {
            margin-left: 80px;
            padding: 20px;
        }
    }

    @media (max-width: 1024px) {
        .employee-card {
            flex: 0 0 calc(50% - 10px);
            min-width: 250px;
        }
    }

    @media (max-width: 768px) {
        .summary-cards {
            grid-template-columns: 1fr;
        }
        
        .department-grid {
            grid-template-columns: 1fr;
        }
        
        .search-controls {
            flex-direction: column;
            align-items: stretch;
        }
        
        .search-input {
            min-width: auto;
        }
        
        .payslip-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        
        .payslip-actions {
            width: 100%;
            justify-content: flex-start;
        }

        .payslip-grid {
            grid-template-columns: 1fr;
        }

        .payslip-section:first-child {
            border-right: none;
            border-bottom: 1px solid #e9ecef;
        }

        .employee-details {
            grid-template-columns: 1fr;
        }

        .summary-section {
            flex-direction: column;
            gap: 15px;
        }

        .summary-item {
            width: 100%;
        }

        #payslipsContainer {
            flex-direction: column;
        }

        .employee-card {
            flex: 1 1 100%;
            min-width: auto;
        }

        .department-employees {
            flex-direction: column;
        }

        .department-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }

        .dept-header-content {
            width: 100%;
        }

        .dept-header-actions {
            width: 100%;
            justify-content: flex-end;
        }
    }

    @media (max-width: 480px) {
        .main-content {
            padding: 10px;
        }

        .header {
            padding: 15px 20px;
        }

        .header h1 {
            font-size: 20px;
        }

        .current-month-display {
            padding: 15px 20px;
        }

        .payslip-modal-content {
            width: 95%;
            margin: 2vh auto;
        }

        .payslip-modal-body {
            padding: 20px;
        }

        .header-right {
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }

        .user-role-indicator {
            order: 2;
        }

        .header-actions {
            order: 1;
        }
    }

    /* Enhanced Payslip Modal Styles with Beautiful Design */
    .payslip-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(17, 45, 78, 0.95);
        backdrop-filter: blur(10px);
        z-index: 2000;
        overflow-y: auto;
        padding: 20px;
        box-sizing: border-box;
        animation: fadeIn 0.3s ease-in-out;
    }

    .payslip-modal[style*="display: block"] {
        display: flex !important;
        align-items: center;
        justify-content: center;
    }

    @keyframes fadeIn {
        from { 
            opacity: 0; 
        }
        to { 
            opacity: 1; 
        }
    }

    .payslip-content {
        position: relative;
        background: white;
        margin: auto;
        width: 100%;
        max-width: 1800px;
        max-height: 95vh;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        overflow: hidden;
        animation: modalSlideUp 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        display: flex;
        flex-direction: column;
    }

    @keyframes modalSlideUp {
        from { 
            opacity: 0; 
            transform: translateY(60px) scale(0.9);
        }
        to { 
            opacity: 1; 
            transform: translateY(0) scale(1);
        }
    }

    .payslip-header {
        background: linear-gradient(135deg, #3F72AF 0%, #2563af 50%, #112D4E 100%);
        color: white;
        padding: 20px 40px;
        position: relative;
        overflow: hidden;
        text-align: center;
        flex-shrink: 0;
    }

    .payslip-header::before {
        content: '';
        position: absolute;
        top: -60%;
        right: -15%;
        width: 500px;
        height: 500px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
        border-radius: 50%;
        animation: float 6s ease-in-out infinite;
    }

    .payslip-header::after {
        content: '';
        position: absolute;
        bottom: -40%;
        left: -10%;
        width: 400px;
        height: 400px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.08) 0%, transparent 70%);
        border-radius: 50%;
        animation: float 8s ease-in-out infinite reverse;
    }

    @keyframes float {
        0%, 100% {
            transform: translateY(0) translateX(0);
        }
        50% {
            transform: translateY(-20px) translateX(10px);
        }
    }

    .company-info {
        position: relative;
        z-index: 1;
    }

    .company-info h2 {
        font-size: 16px;
        margin: 0 0 3px;
        font-weight: 700;
        letter-spacing: 0.5px;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }

    .company-info p {
        margin: 0;
        font-size: 10px;
        opacity: 0.9;
        font-weight: 400;
    }

    .pay-period {
        display: none;
    }

    .pay-period h3 {
        font-size: 14px;
        margin: 0;
        font-weight: 700;
        letter-spacing: 0.5px;
    }

    .modal-close {
        position: absolute;
        top: 15px;
        right: 15px;
        font-size: 28px;
        color: white;
        cursor: pointer;
        z-index: 15;
        width: 45px;
        height: 45px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #ff4757 0%, #ff3742 100%);
        backdrop-filter: blur(15px);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        font-weight: 700;
        border: 3px solid rgba(255, 255, 255, 0.8);
        box-shadow: 0 4px 15px rgba(255, 71, 87, 0.4);
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    }

    .modal-close:hover {
        background: linear-gradient(135deg, #ff3742 0%, #ff2f3a 100%);
        transform: rotate(90deg) scale(1.2);
        border-color: white;
        box-shadow: 0 6px 20px rgba(255, 71, 87, 0.6);
    }

    .modal-close:active {
        transform: rotate(90deg) scale(1.1);
        box-shadow: 0 3px 10px rgba(255, 71, 87, 0.5);
    }

    /* Pulsing animation for close button */
    .modal-close {
        animation: closeButtonPulse 2s ease-in-out infinite;
    }

    @keyframes closeButtonPulse {
        0%, 100% {
            box-shadow: 0 4px 15px rgba(255, 71, 87, 0.4), 0 0 10px rgba(255, 71, 87, 0.3);
        }
        50% {
            box-shadow: 0 4px 15px rgba(255, 71, 87, 0.4), 0 0 25px rgba(255, 71, 87, 0.7);
        }
    }

    /* Enhanced visibility with stronger contrast */
    .modal-close::before {
        content: '';
        position: absolute;
        top: -2px;
        left: -2px;
        right: -2px;
        bottom: -2px;
        background: linear-gradient(135deg, #ff4757, #ff3742);
        border-radius: 50%;
        z-index: -1;
        opacity: 0.3;
        animation: closeButtonGlow 3s ease-in-out infinite;
    }

    @keyframes closeButtonGlow {
        0%, 100% {
            opacity: 0.3;
            transform: scale(1);
        }
        50% {
            opacity: 0.6;
            transform: scale(1.1);
        }
    }

    .payslip-body {
        padding: 0;
        background: white;
        flex: 1;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
    }

    .employee-section {
        background: white;
        padding: 20px 40px;
        margin: 0;
        border-bottom: 2px solid #e9ecef;
        animation: slideInLeft 0.5s ease-out;
        flex-shrink: 0;
    }

    @keyframes slideInLeft {
        from {
            opacity: 0;
            transform: translateX(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .section-title {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
        color: #112D4E;
        font-size: 14px;
        font-weight: 700;
        padding-bottom: 0;
        border-bottom: none;
    }

    .section-title i {
        margin-right: 0;
        color: #3F72AF;
        font-size: 0;
        background: transparent;
        padding: 0;
        border-radius: 0;
    }

    .employee-details {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 15px 30px;
    }

    .detail-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .detail-group label {
        font-size: 10px;
        color: #6c757d;
        font-weight: 600;
        text-transform: none;
        letter-spacing: 0;
    }

    .detail-group span {
        font-size: 13px;
        font-weight: 600;
        color: #000;
    }

    .payslip-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 25px;
        margin-bottom: 0;
        padding: 20px 40px;
        animation: slideInRight 0.6s ease-out;
        flex-shrink: 0;
    }

    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .payslip-section {
        background: white;
        padding: 0;
        border-radius: 0;
        box-shadow: none;
        border: none;
        margin-bottom: 0;
    }

    .payslip-section:last-child {
        margin-bottom: 0;
    }

    .breakdown-container {
        max-height: none;
        border: 2px solid #3F72AF;
        border-radius: 10px;
        padding: 15px 20px;
        background: white;
        transition: all 0.3s ease;
    }

    .breakdown-container:hover {
        box-shadow: 0 4px 15px rgba(63, 114, 175, 0.15);
        transform: translateY(-2px);
    }

    .breakdown-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: none;
        font-size: 12px;
        align-items: center;
        transition: all 0.2s;
    }

    .breakdown-row:hover {
        background: transparent;
        padding-left: 0;
    }

    .breakdown-row span:first-child {
        color: #000;
        font-weight: 400;
    }

    .breakdown-row span:last-child {
        color: #000;
        font-weight: 600;
        font-size: 12px;
    }

    .breakdown-row:last-child {
        border-bottom: none;
    }

    .breakdown-row.total-row {
        background: transparent;
        font-weight: 700;
        color: #000;
        margin-top: 0;
        padding: 8px 0;
        border-radius: 0;
        border: none;
        border-top: 2px solid #e9ecef;
    }

    .breakdown-row.total-row:hover {
        background: transparent;
    }

    .breakdown-row.total-row span {
        color: #000;
        font-size: 13px;
        font-weight: 700;
    }

    .summary-section {
        background: white;
        border-radius: 0;
        padding: 20px 40px;
        box-shadow: none;
        position: relative;
        overflow: hidden;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        border-top: 2px solid #e9ecef;
        animation: slideInUp 0.7s ease-out;
        flex-shrink: 0;
    }

    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .summary-section::before {
        display: none;
    }

    .summary-item {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 15px;
        font-size: 13px;
        position: relative;
        z-index: 1;
        background: linear-gradient(135deg, #3F72AF 0%, #2563af 50%, #112D4E 100%);
        border-radius: 10px;
        transition: all 0.3s ease;
    }

    .summary-item:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(63, 114, 175, 0.3);
    }

    .summary-item span:first-child {
        color: rgba(255, 255, 255, 0.95);
        font-weight: 600;
        font-size: 11px;
        margin-bottom: 6px;
    }

    .summary-item span:last-child {
        color: white;
        font-weight: 700;
        font-size: 16px;
    }

    .summary-item.total {
        border-top: none;
        font-size: 16px;
        font-weight: 800;
        color: white;
        margin-top: 0;
        padding-top: 15px;
        padding-bottom: 15px;
    }

    .summary-item.total span:first-child {
        font-size: 12px;
    }

    .summary-item.total span:last-child {
        color: white;
        font-size: 20px;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }

    .payslip-footer {
        padding: 10px 40px;
        border-top: none;
        background: white;
        text-align: center;
        flex-shrink: 0;
    }

    .footer-note {
        font-size: 9px;
        color: #999;
        font-style: italic;
    }

    .footer-note p {
        margin: 0;
    }

    /* Enhanced Export Button Design */
    .header-export-btn {
        position: absolute;
        top: 15px;
        right: 60px;
        padding: 16px 20px;
        border-radius: 30px;
        font-weight: 800;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        cursor: pointer;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        font-size: 14px;
        border: 3px solid rgba(63, 114, 175, 0.8);
        background: linear-gradient(135deg, #3F72AF 0%, #2563af 50%, #112D4E 100%);
        color: white;
        text-transform: uppercase;
        letter-spacing: 1px;
        z-index: 10;
        backdrop-filter: blur(20px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        position: relative;
        overflow: hidden;
        min-width: 140px;
        height: 50px;
    }

    .header-export-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        transition: left 0.6s ease;
    }

    .header-export-btn:hover::before {
        left: 100%;
    }

    .header-export-btn:hover {
        background: linear-gradient(135deg, #4A90E2 0%, #3F72AF 50%, #2563af 100%);
        border-color: rgba(74, 144, 226, 1);
        transform: translateY(-4px) scale(1.08);
        box-shadow: 0 10px 30px rgba(63, 114, 175, 0.6);
        letter-spacing: 1.2px;
    }

    .header-export-btn:active {
        transform: translateY(-1px) scale(1.02);
        box-shadow: 0 4px 15px rgba(63, 114, 175, 0.4);
        background: linear-gradient(135deg, #2563af 0%, #112D4E 100%);
    }

    .header-export-btn i {
        font-size: 16px;
        transition: transform 0.3s ease;
    }

    .header-export-btn:hover i {
        transform: translateX(3px) scale(1.1);
    }

    /* Pulsing effect for better visibility */
    .header-export-btn {
        animation: subtlePulse 3s ease-in-out infinite;
    }

    @keyframes subtlePulse {
        0%, 100% {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2), 0 0 15px rgba(63, 114, 175, 0.3);
        }
        50% {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2), 0 0 25px rgba(63, 114, 175, 0.6);
        }
    }

    /* Enhanced visibility with stronger contrast */
    .header-export-btn {
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        border: 3px solid rgba(63, 114, 175, 0.9);
    }

    .header-export-btn:hover {
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.4);
    }

    /* Loading state for export button */
    .header-export-btn.loading {
        pointer-events: none;
        opacity: 0.7;
    }

    .header-export-btn.loading i {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .company-info h2 {
        margin: 0 0 8px 0;
        font-size: 28px;
        font-weight: 700;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .company-info p {
        margin: 0;
        font-size: 14px;
        opacity: 0.9;
        font-weight: 400;
    }

    .pay-period h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        text-align: right;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .payslip-body {
        padding: 30px;
        max-height: 75vh;
        overflow-y: auto;
    }

    .employee-section {
        margin-bottom: 30px;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 12px;
        border-left: 4px solid #3F72AF;
    }

    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: #112D4E;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .section-title::before {
        content: '';
        width: 4px;
        height: 20px;
        background: #3F72AF;
        border-radius: 2px;
    }

    .employee-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
    }

    .detail-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .detail-group label {
        font-size: 12px;
        font-weight: 600;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .detail-group span {
        font-size: 14px;
        font-weight: 500;
        color: #2D3748;
    }

    .payslip-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }

    .payslip-section {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 20px;
        border-left: 4px solid #3F72AF;
    }

    .breakdown-container {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .breakdown-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid #e9ecef;
    }

    .breakdown-row:last-child {
        border-bottom: none;
    }

    .breakdown-row span:first-child {
        font-size: 14px;
        color: #6c757d;
    }

    .breakdown-row span:last-child {
        font-size: 14px;
        font-weight: 600;
        color: #2D3748;
    }

    .total-row {
        background: #e3f2fd;
        padding: 10px;
        border-radius: 8px;
        margin-top: 10px;
        border: 2px solid #3F72AF;
    }

    .total-row span {
        font-weight: 700;
        color: #112D4E;
    }

    .summary-section {
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        color: white;
        padding: 25px;
        border-radius: 12px;
        text-align: center;
    }

    .summary-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        font-size: 16px;
    }

    .summary-item.total {
        font-size: 20px;
        font-weight: 700;
        border-top: 2px solid rgba(255, 255, 255, 0.3);
        margin-top: 15px;
        padding-top: 15px;
    }

    /* New Payslip Design Styles */
    .employee-info-section {
        background: #f8f9fa;
        padding: 25px 30px;
        border-bottom: 1px solid #e9ecef;
    }

    .employee-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 30px;
    }

    .info-column {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid #e9ecef;
    }

    .info-row:last-child {
        border-bottom: none;
    }

    .info-row .label {
        font-size: 13px;
        font-weight: 600;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        min-width: 120px;
    }

    .info-row .value {
        font-size: 14px;
        font-weight: 500;
        color: #2D3748;
        text-align: right;
    }

    .payslip-details-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        padding: 30px;
        background: white;
    }

    .deductions-section, .earnings-section {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 20px;
        border: 1px solid #e9ecef;
    }

    .section-header h3 {
        margin: 0 0 20px 0;
        font-size: 16px;
        font-weight: 700;
        color: #112D4E;
        text-align: center;
        padding-bottom: 10px;
        border-bottom: 2px solid #3F72AF;
    }

    .deductions-list, .earnings-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .deduction-item, .earning-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        border-bottom: 1px solid #e9ecef;
        font-size: 14px;
    }

    .deduction-item:last-child, .earning-item:last-child {
        border-bottom: none;
    }

    .item-name {
        color: #6c757d;
        font-weight: 500;
    }

    .item-amount {
        color: #2D3748;
        font-weight: 600;
    }

    .deduction-total, .earning-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 0;
        margin-top: 10px;
        background: #e3f2fd;
        border-radius: 8px;
        border: 2px solid #3F72AF;
        font-weight: 700;
    }

    .total-label {
        color: #112D4E;
        font-size: 16px;
    }

    .total-amount {
        color: #112D4E;
        font-size: 16px;
    }

    .summary-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 25px 30px;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-top: 2px solid #3F72AF;
        gap: 20px;
    }

    .summary-box {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
        border: 2px solid transparent;
    }

    .summary-box:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(63, 114, 175, 0.2);
        border-color: #3F72AF;
    }

    .summary-box.net-pay {
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        color: white;
    }

    .summary-box.net-pay:hover {
        transform: translateY(-3px) scale(1.02);
        box-shadow: 0 8px 25px rgba(63, 114, 175, 0.4);
    }

    .summary-label {
        color: #6c757d;
        font-weight: 600;
        font-size: 12px;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .summary-box.net-pay .summary-label {
        color: rgba(255, 255, 255, 0.9);
    }

    .summary-value {
        color: #112D4E;
        font-weight: 700;
        font-size: 18px;
    }

    .summary-box.net-pay .summary-value {
        color: white;
        font-size: 24px;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    .payslip-footer {
        background: #f8f9fa;
        padding: 20px 30px;
        border-top: 1px solid #e9ecef;
        text-align: center;
    }

    .payslip-footer p {
        margin: 0;
        color: #6c757d;
        font-size: 12px;
        font-style: italic;
    }

    /* Responsive Design for New Layout */
    @media (max-width: 768px) {
        .employee-info-grid {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        .payslip-details-grid {
            grid-template-columns: 1fr;
            gap: 20px;
            padding: 20px;
        }
        
        .summary-footer {
            flex-direction: column;
            gap: 15px;
        }
        
        .summary-box {
            width: 100%;
        }
    }

    .payslip-modal-body {
        padding: 30px;
        max-height: 75vh;
        overflow-y: auto;
    }

    .payslip-modal-footer {
        padding: 20px 30px;
        background: #f8f9fa;
        border-top: 1px solid #e9ecef;
        display: flex;
        justify-content: flex-end;
        gap: 15px;
    }

    /* Enhanced Payslip Content Styles */
    .payslip-content {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        max-width: 1000px;
        margin: 0 auto;
    }

    .payslip-header {
        background: var(--luxury-gradient);
        color: white;
        padding: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: relative;
        overflow: hidden;
    }

    .payslip-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(45deg, rgba(255, 255, 255, 0.1) 0%, transparent 50%, rgba(255, 255, 255, 0.1) 100%);
        animation: shimmer 4s ease-in-out infinite;
    }

    .company-info h2 {
        margin: 0 0 5px 0;
        font-size: 28px;
        font-weight: 700;
    }

    .company-info p {
        margin: 0;
        opacity: 0.9;
        font-size: 14px;
    }

    .pay-period h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
    }

    .payslip-body {
        padding: 0;
    }

    .employee-section {
        padding: 25px 30px;
        background: #f8f9fa;
        border-bottom: 1px solid #e9ecef;
    }

    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: #112D4E;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #3F72AF;
    }

    .employee-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }

    .detail-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .detail-group label {
        font-size: 12px;
        font-weight: 600;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .detail-group span {
        font-size: 14px;
        font-weight: 500;
        color: #112D4E;
    }

    .payslip-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .payslip-section {
        padding: 25px 30px;
    }

    .payslip-section:first-child {
        border-right: none;
    }

    .breakdown-container {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .breakdown-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid #f1f3f5;
        font-size: 14px;
    }

    .breakdown-row:last-child {
        border-bottom: none;
    }

    .breakdown-row.total-row {
        background: #f8f9fa;
        font-weight: 700;
        color: #112D4E;
        margin-top: 10px;
        padding: 15px;
        border-radius: 8px;
        border: 2px solid #3F72AF;
    }

    .breakdown-row.total-row span {
        font-size: 16px;
        font-weight: 700;
    }

    .summary-section {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        padding: 25px 30px;
        border-top: 2px solid #3F72AF;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
    }

    .summary-item {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
        border: 2px solid transparent;
    }

    .summary-item:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(63, 114, 175, 0.2);
        border-color: #3F72AF;
    }

    .summary-item span:first-child {
        color: #6c757d;
        font-weight: 600;
        font-size: 12px;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .summary-item span:last-child {
        color: #112D4E;
        font-weight: 700;
        font-size: 18px;
    }

    .summary-item.total {
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        color: white;
    }

    .summary-item.total span:first-child {
        color: rgba(255, 255, 255, 0.9);
        font-size: 12px;
    }

    .summary-item.total span:last-child {
        color: white;
        font-size: 24px;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    .payslip-footer {
        background: #f8f9fa;
        padding: 20px 30px;
        border-top: 1px solid #e9ecef;
        text-align: center;
    }

    .footer-note p {
        margin: 0;
        color: #6c757d;
        font-size: 12px;
        font-style: italic;
    }

    /* Animations */
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(50px) scale(0.9);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    /* Custom scrollbar for modal */
    .payslip-modal-body::-webkit-scrollbar {
        width: 8px;
    }

    .payslip-modal-body::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .payslip-modal-body::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 10px;
    }

    .payslip-modal-body::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    /* Close modal when clicking outside */
    .payslip-modal {
        display: flex;
        align-items: center;
        justify-content: center;
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
        <img src="LOGO/newLogo_transparent.png" class="logo" style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding:-250px; padding-bottom: 20px;">
        <div class="menu">
            <?php if ($user_role === 'admin'): ?>
                <a href="AdminHome.php" class="menu-item">
                    <i class="fas fa-th-large"></i> Dashboard
                </a>
                <a href="AdminEmployees.php" class="menu-item">
                    <i class="fas fa-users"></i> Employees
                </a>
                <a href="AdminAttendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
                <a href="AdminPayroll.php" class="menu-item">
                    <i class="fas fa-money-bill-wave"></i> Payroll
                </a>
                <a href="AdminHistory.php" class="menu-item active">
                    <i class="fas fa-history"></i> History
                </a>
            <?php else: ?>
                <a href="DeptHeadDashboard.php" class="menu-item">
                    <i class="fas fa-th-large"></i> Dashboard
                </a>
                <a href="DeptHeadEmployees.php" class="menu-item">
                    <i class="fas fa-users"></i> Employees
                </a>
                <a href="DeptHeadAttendance.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
                <a href="Payroll.php" class="menu-item">
                    <i class="fas fa-money-bill-wave"></i> Payroll
                </a>
                <a href="DeptHeadHistory.php" class="menu-item active">
                    <i class="fas fa-history"></i> History
                </a>
            <?php endif; ?>
        </div>
        
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-money-bill-wave"></i> Payroll History</h1>
            </div>
            <div class="header-right">
                <!-- User Role Indicator -->
                <div class="user-role-indicator">
                    <div class="role-badge <?php echo $user_role; ?>" title="<?php echo ucfirst($user_role); ?> - <?php echo $user_name; ?>">
                        <div class="role-icon">
                            <?php if ($user_role === 'admin'): ?>
                                <i class="fas fa-crown"></i>
                            <?php else: ?>
                                <i class="fas fa-user-tie"></i>
                            <?php endif; ?>
                        </div>
                        <div class="role-info">
                            <div class="role-title"><?php echo ucfirst($user_role); ?></div>
                            <div class="role-subtitle"><?php echo $user_name; ?></div>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="<?php echo ($user_role === 'admin') ? 'AdminHistory.php' : 'DeptHeadHistory.php'; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Attendance History
                    </a>
                </div>
            </div>
        </div>

        <!-- Header -->
        <div class="current-month-display">
            <div class="month-info">
                <i class="fas fa-calendar-alt"></i>
                <div>
                    <div class="month-label">Payroll History</div>
                    <div class="month-value"><?php echo date('F Y', strtotime($current_month . '-01')); ?></div>
                </div>
            </div>
            <div class="filter-item">
                <i class="fas fa-filter"></i>
                <select class="compact-select" id="monthSelect" onchange="changeMonth()">
                    <?php foreach ($available_months as $month): ?>
                        <option value="<?php echo $month; ?>" <?php echo $month === $current_month ? 'selected' : ''; ?>>
                            <?php echo date('F Y', strtotime($month . '-01')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card luxury-card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Employee Count</h3>
                </div>
                <div class="card-content">
                    <div class="card-value" id="summary-employee-count"><?php echo number_format($total_employees); ?></div>
                    <div class="card-subtitle" id="summary-employee-subtitle">Active employees for <?php echo date('F Y', strtotime($current_month . '-01')); ?></div>
                </div>
            </div>

            <div class="summary-card luxury-card">
                <div class="card-header">
                    <h3><i class="fas fa-dollar-sign"></i> Total Payroll Amount</h3>
                </div>
                <div class="card-content">
                    <div class="card-value" id="summary-total-payroll">₱<?php echo number_format($total_net_pay, 2); ?></div>
                    <div class="card-subtitle" id="summary-payroll-subtitle">Total payroll for the month</div>
                </div>
            </div>

            <div class="summary-card luxury-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Average Salary</h3>
                </div>
                <div class="card-content">
                    <div class="card-value" id="summary-average-salary">₱<?php echo number_format($total_employees > 0 ? $total_net_pay / $total_employees : 0, 2); ?></div>
                    <div class="card-subtitle" id="summary-average-subtitle">Average salary per employee</div>
                </div>
            </div>

            <div class="summary-card luxury-card">
                <div class="card-header">
                    <h3><i class="fas fa-check-circle"></i> Payroll Status</h3>
                </div>
                <div class="card-content">
                    <div class="card-value" id="summary-payroll-status">Complete</div>
                    <div class="card-subtitle" id="summary-status-subtitle">All employees processed</div>
                </div>
            </div>
        </div>

        <!-- Department Filter -->
        <div class="department-search-section">
            <div class="department-header-card">
                <div class="dept-header-info">
                    <div class="dept-header-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="dept-header-details">
                        <h3>Department Overview</h3>
                        <p>Filter employees by department</p>
                    </div>
                </div>
                <div class="search-controls">
                    <div class="search-input">
                        <input type="text" id="employeeSearch" placeholder="Search employees..." onkeyup="searchEmployees()">
                        <i class="fas fa-search"></i>
                    </div>
                    <div class="search-loading" id="searchLoading">
                        <i class="fas fa-spinner"></i>
                        <span>Searching...</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Department Cards -->
        <div class="department-overview">
            <div class="section-header">
                <h2><i class="fas fa-sitemap"></i> Departments</h2>
            </div>
            <div class="department-grid" id="departmentGrid">
                <?php
                $department_stats = [];
                foreach ($employees as $employee) {
                    $dept = $employee['Department'];
                    if (!isset($department_stats[$dept])) {
                        $department_stats[$dept] = ['count' => 0, 'total_salary' => 0];
                    }
                    $department_stats[$dept]['count']++;
                    $payroll_data = calculatePayroll($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                    $department_stats[$dept]['total_salary'] += $payroll_data['net_pay'];
                }
                
                foreach ($department_stats as $dept => $stats):
                ?>
                <div class="department-card" onclick="filterByDepartment('<?php echo $dept; ?>')">
                    <div class="dept-icon">
                        <?php echo strtoupper(substr($dept, 0, 2)); ?>
                    </div>
                    <div class="dept-info">
                        <div class="dept-name"><?php echo $dept; ?></div>
                        <div class="dept-stats">
                            <?php echo $stats['count']; ?> employees • ₱<?php echo number_format($stats['total_salary'], 2); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Employee Payslips -->
        <div class="employee-payslips">
            <div class="section-header">
                <h2><i class="fas fa-file-invoice-dollar"></i> Employee Payslips</h2>
                <div class="filter-controls">
                    <button class="btn btn-outline" onclick="showAllDepartments()" id="showAllBtn">
                        <i class="fas fa-eye"></i> Show All Departments
                    </button>
                    <button class="btn btn-outline" onclick="clearFilters()" id="clearFiltersBtn" style="display: none;">
                        <i class="fas fa-times"></i> Clear Filters
                    </button>
                </div>
            </div>
            <div id="payslipsContainer">
                <?php 
                // Group employees by department
                $employees_by_dept = [];
                foreach ($employees as $employee) {
                    $dept = $employee['Department'];
                    if (!isset($employees_by_dept[$dept])) {
                        $employees_by_dept[$dept] = [];
                    }
                    $employees_by_dept[$dept][] = $employee;
                }
                
                // Display employees grouped by department
                foreach ($employees_by_dept as $department => $dept_employees): 
                    $dept_payroll_total = 0;
                    foreach ($dept_employees as $emp) {
                        $payroll_data = calculatePayroll($emp['EmployeeID'], $emp['base_salary'], $current_month, $conn);
                        $dept_payroll_total += $payroll_data['net_pay'];
                    }
                ?>
                <div class="department-section" data-department="<?php echo htmlspecialchars($department); ?>">
                    <div class="department-header">
                        <div class="dept-header-content">
                            <div class="dept-header-icon">
                                <i class="fas fa-building"></i>
                            </div>
                            <div class="dept-header-info">
                                <h3><?php echo htmlspecialchars($department); ?></h3>
                                <p><?php echo count($dept_employees); ?> employees • Total: ₱<?php echo number_format($dept_payroll_total, 2); ?></p>
                            </div>
                        </div>
                        <div class="dept-header-actions">
                            <button class="btn btn-sm" onclick="toggleDepartment('<?php echo htmlspecialchars($department); ?>')" id="toggle-<?php echo preg_replace('/[^a-zA-Z0-9]/', '', $department); ?>">
                                <i class="fas fa-chevron-down"></i> Expand
                            </button>
                        </div>
                    </div>
                    <div class="department-employees" id="employees-<?php echo preg_replace('/[^a-zA-Z0-9]/', '', $department); ?>">
                        <?php foreach ($dept_employees as $employee): 
                            // Calculate payroll using centralized function from payroll_computations.php
                            // Special Holiday Pay formula: (base_salary / 8) * 1.30 * 8 = base_salary * 1.30
                            // This is handled by calculateHolidayPay() function in payroll_computations.php
                            $payroll_data = calculatePayroll($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                            
                            // Calculate Basic Salary from attendance (hourly rate × total hours) - same as Payroll.php
                            $hourlyRate = round($employee['base_salary'] / 8.0, 2); // Round to 2 decimals for precision
                            $basicSalary = round($hourlyRate * $employee['total_hours'], 2); // Round to 2 decimals
                            
                            // Get overtime hours breakdown using 10pm cutoff logic from payroll_computations.php
                            $otHoursBreakdown = calculateOvertimeHoursBreakdown($employee['EmployeeID'], $current_month, $conn);
                            $regular_overtime_hours = $otHoursBreakdown['regular_ot_hours']; // OT hours before 10pm
                            $nsd_overtime_hours = $otHoursBreakdown['nsd_ot_hours']; // OT hours from 10pm onwards
                            $total_overtime_hours = $regular_overtime_hours + $nsd_overtime_hours; // Total for display
                            
                            // Calculate OT pay separately for regular and NSD hours
                            // Regular OT: (base_salary / 8) * 1.25 * regular_OT_hours
                            // NSD OT: (hourly_rate * NSD_OT_hours) + (hourly_rate * 0.10 * 1.25 * NSD_OT_hours)
                            $hourlyRate = round($employee['base_salary'] / 8, 2); // Round to 2 decimals for precision
                            $regular_overtime_pay = round($hourlyRate * 1.25 * $regular_overtime_hours, 2); // Round to 2 decimals
                            // NSD OT Pay = regular hourly rate + NSD premium
                            $nsdOTPremium = round($hourlyRate * 0.10 * 1.25 * $nsd_overtime_hours, 2); // Round to 2 decimals
                            $regularPayForNSDHours = round($hourlyRate * $nsd_overtime_hours, 2); // Round to 2 decimals
                            $nsd_overtime_pay = round($regularPayForNSDHours + $nsdOTPremium, 2); // Round final result to 2 decimals
                            
                            // Get late minutes using employee's shift start time
                            // Calculate late minutes for all records where time_in is after shift start, regardless of status
                            $lateQuery = "SELECT SUM(
                                CASE 
                                    WHEN TIME(a.time_in) > CONCAT(SUBSTRING_INDEX(e.Shift, '-', 1), ':00') 
                                    THEN TIMESTAMPDIFF(MINUTE, CONCAT(SUBSTRING_INDEX(e.Shift, '-', 1), ':00'), TIME(a.time_in))
                                    ELSE 0 
                                END
                            ) as late_minutes 
                            FROM attendance a
                            JOIN empuser e ON a.EmployeeID = e.EmployeeID
                            WHERE a.EmployeeID = ? 
                            AND a.time_in IS NOT NULL
                            AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?";
                            $stmt = $conn->prepare($lateQuery);
                            $late_minutes = 0;
                            if ($stmt) {
                                $stmt->bind_param("is", $employee['EmployeeID'], $current_month);
                                $stmt->execute();
                                $lateResult = $stmt->get_result();
                                $lateRow = $lateResult->fetch_assoc();
                                $late_minutes = $lateRow && $lateRow['late_minutes'] ? intval($lateRow['late_minutes']) : 0;
                                $stmt->close();
                            }
                            
                            // Get absences (excluding Sundays)
                            $absencesQuery = "SELECT COUNT(*) as absence_count 
                                              FROM (
                                                  SELECT DATE(attendance_date) as date 
                                                  FROM attendance 
                                                  WHERE EmployeeID = ? 
                                                  AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
                                                  AND status = 'absent'
                                                  AND DAYOFWEEK(attendance_date) != 1
                                              ) as absences";
                            $stmt = $conn->prepare($absencesQuery);
                            $absences = 0;
                            if ($stmt) {
                                $stmt->bind_param("is", $employee['EmployeeID'], $current_month);
                                $stmt->execute();
                                $absResult = $stmt->get_result();
                                $absRow = $absResult->fetch_assoc();
                                $absences = $absRow ? intval($absRow['absence_count']) : 0;
                                $stmt->close();
                            }
                            
                            // Calculate working days in month (excluding Sundays)
                            $month_start = date('Y-m-01', strtotime($current_month . '-01'));
                            $month_end = date('Y-m-t', strtotime($current_month . '-01'));
                            $working_days = 0;
                            $current_date = strtotime($month_start);
                            $end_date = strtotime($month_end);
                            while ($current_date <= $end_date) {
                                $day_of_week = date('w', $current_date);
                                if ($day_of_week != 0) { // Not Sunday
                                    $working_days++;
                                }
                                $current_date = strtotime('+1 day', $current_date);
                            }
                            $monthly_rate = $employee['base_salary'] * $working_days;
                            $daily_rate = $employee['base_salary'];
                        ?>
                        <div class="employee-card" onclick="viewPayslip('<?php echo $employee['EmployeeID']; ?>', '<?php echo $current_month; ?>')" 
                             data-employee-id="<?php echo $employee['EmployeeID']; ?>"
                             data-employee-name="<?php echo htmlspecialchars($employee['EmployeeName']); ?>"
                             data-department="<?php echo htmlspecialchars($employee['Department']); ?>"
                             data-days-worked="<?php echo intval($employee['days_worked']); ?>"
                             data-total-hours="<?php echo number_format($employee['total_hours'], 1); ?>"
                             data-base-salary="<?php echo number_format($basicSalary, 2); ?>"
                             data-overtime-pay="<?php echo number_format($regular_overtime_pay, 2); ?>"
                             data-special-holiday-pay="<?php echo number_format($payroll_data['special_holiday_pay'] ?? 0, 2); ?>"
                             data-legal-holiday-pay="<?php echo number_format($payroll_data['regular_holiday_pay'] ?? 0, 2); ?>"
                             data-night-shift-diff="<?php echo number_format($payroll_data['night_shift_diff'] ?? 0, 2); ?>"
                             data-leave-pay="<?php echo number_format($payroll_data['leave_pay'] ?? 0, 2); ?>"
                             data-13th-month="<?php echo number_format($payroll_data['thirteenth_month_pay'] ?? 0, 2); ?>"
                             data-lates="<?php echo number_format($payroll_data['lates_amount'], 2); ?>"
                             data-phic-employee="<?php echo number_format($payroll_data['phic_employee'] ?? 0, 2); ?>"
                             data-phic-employer="<?php echo number_format($payroll_data['phic_employer'] ?? 0, 2); ?>"
                             data-pagibig-employee="<?php echo number_format($payroll_data['pagibig_employee'] ?? 0, 2); ?>"
                             data-pagibig-employer="<?php echo number_format($payroll_data['pagibig_employer'] ?? 0, 2); ?>"
                             data-sss-employee="<?php echo number_format($payroll_data['sss_employee'] ?? 0, 2); ?>"
                             data-sss-employer="<?php echo number_format($payroll_data['sss_employer'] ?? 0, 2); ?>"
                             data-net-pay="<?php echo number_format($payroll_data['net_pay'], 2); ?>"
                             data-gross-pay="<?php echo number_format($payroll_data['gross_pay'], 2); ?>"
                             data-laundry-allowance="<?php echo number_format($payroll_data['laundry_allowance'] ?? 0, 2); ?>"
                             data-medical-allowance="<?php echo number_format($payroll_data['medical_allowance'] ?? 0, 2); ?>"
                             data-rice-allowance="<?php echo number_format($payroll_data['rice_allowance'] ?? 0, 2); ?>"
                             data-total-deductions="<?php echo number_format($payroll_data['total_deductions'], 2); ?>"
                             data-sss="<?php echo $employee['SSS'] ?? 'N/A'; ?>"
                             data-philhealth="<?php echo $employee['PHIC'] ?? 'N/A'; ?>"
                             data-pagibig="<?php echo $employee['HDMF'] ?? 'N/A'; ?>"
                             data-tin="<?php echo $employee['TIN'] ?? 'N/A'; ?>"
                             data-shift="<?php echo $employee['Shift'] ?? 'N/A'; ?>"
                             data-overtime-hours="<?php echo number_format($regular_overtime_hours, 2); ?>"
                             data-regular-ot-hours="<?php echo number_format($regular_overtime_hours, 2); ?>"
                             data-nsd-ot-hours="<?php echo number_format($nsd_overtime_hours, 2); ?>"
                             data-regular-ot-pay="<?php echo number_format($regular_overtime_pay, 2); ?>"
                             data-nsd-ot-pay="<?php echo number_format($nsd_overtime_pay, 2); ?>"
                             data-nsd-overtime-hours="<?php echo number_format($nsd_overtime_hours, 2); ?>"
                             data-nsd-overtime-pay="<?php echo number_format($nsd_overtime_pay, 2); ?>"
                             data-basic-salary-earned="<?php echo number_format($payroll_data['basic_salary'], 2); ?>"
                             data-base-salary-db="<?php echo number_format($employee['base_salary'], 2); ?>"
                             data-late-mins="<?php echo $late_minutes; ?>"
                             data-absences="<?php echo $absences; ?>"
                             data-monthly-rate="<?php echo number_format($monthly_rate, 2); ?>"
                             data-daily-rate="<?php echo number_format($daily_rate, 2); ?>">
                            <div class="employee-header">
                                <div class="employee-avatar">
                                    <?php echo strtoupper(substr($employee['EmployeeName'], 0, 1)); ?>
                                </div>
                                <div class="employee-info">
                                    <h3><?php echo htmlspecialchars($employee['EmployeeName']); ?></h3>
                                    <p>ID: <?php echo htmlspecialchars($employee['EmployeeID']); ?></p>
                                </div>
                            </div>
                            
                            <div class="employee-net-pay-banner">
                                <div class="employee-net-pay-label">Net Pay</div>
                                <div class="employee-net-pay-value">₱<?php echo number_format($payroll_data['net_pay'], 2); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Payslip Modal -->
    <div id="payslipModal" class="payslip-modal" style="display: none;">
        <div class="payslip-content">
            <span class="modal-close" onclick="closePayslipModal()">&times;</span>
            <?php if ($user_role !== 'admin'): ?>
            <button class="header-export-btn" onclick="exportPayslipPDF()" id="exportPayslipBtn">
                <i class="fas fa-file-export"></i> Export PDF
            </button>
            <?php endif; ?>
            
                <div class="payslip-header">
                    <div class="company-info">
                        <h2>WTEI Corporation</h2>
                        <p>Malvar st. Brgy.Mandaragat, Puerto Princesa City, Palawan</p>
                    </div>
                    <div class="pay-period">
                    <h3 id="modal-pay-period">Pay Period: <?php echo date('F Y', strtotime($current_month . '-01')); ?></h3>
                    </div>
                </div>
                
                <div class="payslip-body">
                    <div class="employee-section">
                    <div class="section-title">
                        Employee Information
                    </div>
                        <div class="employee-details">
                            <div class="detail-group">
                                <label>Name</label>
                            <span id="modal-employee-name"></span>
                            </div>
                            <div class="detail-group">
                                <label>Payroll Group</label>
                                <span>WTEICC</span>
                            </div>
                            <div class="detail-group">
                                <label>Employee ID</label>
                            <span id="modal-employee-id"></span>
                            </div>
                            <div class="detail-group">
                            <label>Department</label>
                            <span id="modal-employee-dept"></span>
                            </div>
                            <div class="detail-group">
                                <label>SSS</label>
                            <span id="modal-employee-sss"></span>
                            </div>
                            <div class="detail-group">
                                <label>Philhealth</label>
                            <span id="modal-employee-philhealth"></span>
                            </div>
                            <div class="detail-group">
                                <label>Pag-IBIG</label>
                            <span id="modal-employee-pagibig"></span>
                            </div>
                            <div class="detail-group">
                                <label>TIN</label>
                            <span id="modal-employee-tin"></span>
                            </div>
                            <div class="detail-group">
                                <label>Payment Date</label>
                            <span><?php echo date('F j, Y'); ?></span>
                            </div>
                            <div class="detail-group">
                                <label>Date Covered</label>
                            <span id="modal-date-covered"></span>
                            </div>
                            <div class="detail-group">
                                <label>Total Hours</label>
                            <span id="modal-total-hours-worked"></span>
                            </div>
                            <div class="detail-group">
                                <label>Base Salary (Database)</label>
                            <span id="modal-base-salary-db"></span>
                            </div>
                            <div class="detail-group">
                                <label>Basic Salary (Earned)</label>
                            <span id="modal-basic-salary-earned"></span>
                            </div>
                            <div class="detail-group">
                                <label>Total Days Worked</label>
                            <span id="modal-total-days-worked"></span>
                            </div>
                            <div class="detail-group">
                            <label>Late Minutes</label>
                            <span id="modal-late-mins"></span>
                            </div>
                            <div class="detail-group">
                                <label>Overtime Hours</label>
                                <span id="modal-overtime-hours"></span>
                            </div>
                            <div class="detail-group">
                                <label>NSD OT Hours</label>
                                <span id="modal-nsd-ot-hours"></span>
                            </div>
                            <div class="detail-group">
                                <label>NSD Overtime Hours</label>
                                <span id="modal-nsd-overtime-hours"></span>
                            </div>
                            <div class="detail-group">
                                <label>Late Deduction</label>
                            <span id="modal-late-deduction"></span>
                        </div>
                        <div class="detail-group">
                            <label>Total Gross Income</label>
                            <span id="modal-total-gross-income"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="payslip-grid">
                        <div class="payslip-section">
                    <div class="section-title">
                            Deductions:
                        </div>
                            <div class="breakdown-container">
                                <div class="breakdown-row">
                                <span>Late Deductions</span>
                                <span id="modal-late-deductions"></span>
                                </div>
                                <div class="breakdown-row">
                                <span>PHIC (Employee)</span>
                                <span id="modal-phic-employee"></span>
                                </div>
                                <div class="breakdown-row">
                                <span>Pag-IBIG (Employee)</span>
                                <span id="modal-pagibig-employee"></span>
                                </div>
                                <div class="breakdown-row">
                                <span>SSS (Employee)</span>
                                <span id="modal-sss-employee"></span>
                                </div>
                            <div id="modal-additional-deductions-list"></div>
                                <div class="breakdown-row total-row">
                                <span>Total Deductions</span>
                                <span id="modal-total-deductions"></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="payslip-section">
                        <div class="section-title">
                            Earnings:
                        </div>
                            <div class="breakdown-container">
                                <div class="breakdown-row">
                            <span>Basic Salary</span>
                            <span id="modal-basic-salary"></span>
                                </div>
                                <div class="breakdown-row">
                            <span>Overtime Pay</span>
                            <span id="modal-overtime-pay"></span>
                                </div>
                                <div class="breakdown-row" id="nsd-overtime-row">
                            <span>NSD Overtime Pay</span>
                            <span id="modal-nsd-overtime-pay">₱0.00</span>
                                </div>
                                <div class="breakdown-row">
                            <span>Special Holiday Pay</span>
                            <span id="modal-special-holiday-pay"></span>
                                </div>
                                <div class="breakdown-row">
                            <span>Legal Holiday Pay</span>
                            <span id="modal-legal-holiday-pay"></span>
                                </div>
                                <div class="breakdown-row" id="night-shift-row">
                            <span>Night Shift Differential</span>
                            <span id="modal-night-shift-diff"></span>
                                </div>
                                <div class="breakdown-row" id="modal-leave-pay-row" style="display: none;">
                            <span>Leave Pay</span>
                            <span id="modal-leave-pay"></span>
                                </div>
                        <div class="breakdown-row" id="modal-13th-month-row" style="display: none;">
                            <span>13th Month Pay</span>
                            <span id="modal-13th-month"></span>
                        </div>
                        <div class="breakdown-row" id="laundry-allowance-row" style="display: none;">
                            <span>Laundry Allowance</span>
                            <span id="modal-laundry-allowance"></span>
                        </div>
                        <div class="breakdown-row" id="medical-allowance-row" style="display: none;">
                            <span>Medical Allowance</span>
                            <span id="modal-medical-allowance"></span>
                        </div>
                        <div class="breakdown-row" id="rice-allowance-row" style="display: none;">
                            <span>Rice Allowance</span>
                            <span id="modal-rice-allowance"></span>
                        </div>
                                <div class="breakdown-row total-row">
                            <span>Total Earnings</span>
                            <span id="modal-total-earnings"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="summary-section">
                        <div class="summary-item">
                            <span>Total Earnings:</span>
                        <span id="modal-summary-earnings"></span>
                        </div>
                        <div class="summary-item">
                            <span>Total Deductions:</span>
                        <span id="modal-summary-deductions"></span>
                        </div>
                        <div class="summary-item total">
                            <span>Net Pay:</span>
                    <span id="modal-net-pay"></span>
                        </div>
                    </div>
                </div>
                
                <div class="payslip-footer">
                    <div class="footer-note">
                        <p>This is a computer-generated document. No signature is required.</p>
                    </div>
                </div>
            </div>
    </div>

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

    <script>
    function changeMonth() {
        const month = document.getElementById('monthSelect').value;
        const url = new URL(window.location);
        url.searchParams.set('month', month);
        window.location.href = url.toString();
    }

    function filterByDepartment(department) {
        // Hide all department sections
        const allSections = document.querySelectorAll('.department-section');
        allSections.forEach(section => {
            section.style.display = 'none';
        });
        
        // Show only the selected department
        const selectedSection = document.querySelector(`[data-department="${department}"]`);
        if (selectedSection) {
            selectedSection.style.display = 'block';
            // Expand the department
            const employeesDiv = selectedSection.querySelector('.department-employees');
            const toggleBtn = selectedSection.querySelector('button');
            if (employeesDiv && toggleBtn) {
                employeesDiv.classList.remove('collapsed');
                employeesDiv.classList.add('expanded');
                toggleBtn.innerHTML = '<i class="fas fa-chevron-up"></i> Collapse';
            }
        }
        
        // Show filter controls
        document.getElementById('clearFiltersBtn').style.display = 'inline-flex';
        document.getElementById('showAllBtn').style.display = 'none';
        
        // Update department cards to show active state
        const deptCards = document.querySelectorAll('.department-card');
        deptCards.forEach(card => {
            card.classList.remove('active');
            if (card.onclick.toString().includes(department)) {
                card.classList.add('active');
            }
        });
        
        // Update summary cards for selected department
        updateSummaryCardsForDepartment(department);
    }

    function showAllDepartments() {
        // Show all department sections
        const allSections = document.querySelectorAll('.department-section');
        allSections.forEach(section => {
            section.style.display = 'block';
        });
        
        // Hide filter controls
        document.getElementById('clearFiltersBtn').style.display = 'none';
        document.getElementById('showAllBtn').style.display = 'inline-flex';
        
        // Remove active state from department cards
        const deptCards = document.querySelectorAll('.department-card');
        deptCards.forEach(card => {
            card.classList.remove('active');
        });
        
        // Reset summary cards to show all departments
        resetSummaryCards();
    }

    function clearFilters() {
        showAllDepartments();
    }

    function toggleDepartment(department) {
        const cleanDept = department.replace(/[^a-zA-Z0-9]/g, '');
        const employeesDiv = document.getElementById(`employees-${cleanDept}`);
        const toggleBtn = document.getElementById(`toggle-${cleanDept}`);
        
        if (employeesDiv.classList.contains('collapsed')) {
            employeesDiv.classList.remove('collapsed');
            employeesDiv.classList.add('expanded');
            toggleBtn.innerHTML = '<i class="fas fa-chevron-up"></i> Collapse';
        } else {
            employeesDiv.classList.remove('expanded');
            employeesDiv.classList.add('collapsed');
            toggleBtn.innerHTML = '<i class="fas fa-chevron-down"></i> Expand';
        }
    }

    // AJAX search function
    function searchEmployees() {
        const searchTerm = document.getElementById('employeeSearch').value.toLowerCase();
        const loading = document.getElementById('searchLoading');
        
        // Show loading indicator
        loading.style.display = 'flex';
        
        // Debounce the search
        clearTimeout(window.searchTimeout);
        window.searchTimeout = setTimeout(() => {
            const employeeCards = document.querySelectorAll('.employee-card');
            let hasResults = false;
            
            employeeCards.forEach(card => {
                const employeeName = card.dataset.employeeName.toLowerCase();
                const employeeId = card.dataset.employeeId.toLowerCase();
                const department = card.dataset.department.toLowerCase();
                
                const matches = employeeName.includes(searchTerm) || 
                              employeeId.includes(searchTerm) || 
                              department.includes(searchTerm);
                
                if (matches) {
                    card.style.display = 'block';
                    hasResults = true;
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Hide loading indicator
            loading.style.display = 'none';
            
            // Show no results message if needed
            if (!hasResults && searchTerm.length > 0) {
                showNoResultsMessage();
            } else {
                hideNoResultsMessage();
            }
        }, 300);
    }

    function showNoResultsMessage() {
        let noResultsDiv = document.getElementById('noResultsMessage');
        if (!noResultsDiv) {
            noResultsDiv = document.createElement('div');
            noResultsDiv.id = 'noResultsMessage';
            noResultsDiv.className = 'no-results-message';
            noResultsDiv.innerHTML = `
                <div class="no-results-content">
                    <i class="fas fa-search"></i>
                    <h3>No employees found</h3>
                    <p>Try adjusting your search terms</p>
                </div>
            `;
            document.getElementById('payslipsContainer').appendChild(noResultsDiv);
        }
        noResultsDiv.style.display = 'block';
    }

    function hideNoResultsMessage() {
        const noResultsDiv = document.getElementById('noResultsMessage');
        if (noResultsDiv) {
            noResultsDiv.style.display = 'none';
        }
    }

    function viewPayslip(employeeId, month) {
        // Get data from the card using data attributes
        const card = document.querySelector(`.employee-card[data-employee-id="${employeeId}"]`);
        
        if (!card) {
            console.error('Employee card not found for ID:', employeeId);
            return;
        }
        
        // Set employee information
        document.getElementById('modal-employee-name').textContent = card.dataset.employeeName;
        document.getElementById('modal-employee-id').textContent = card.dataset.employeeId;
        document.getElementById('modal-employee-dept').textContent = card.dataset.department;
        
        // Set earnings with formatted currency
        const formatCurrency = (value) => {
            if (!value || value === 'undefined' || value === 'null') return '₱0.00';
            const cleanValue = value.toString().replace(/,/g, '');
            const numValue = parseFloat(cleanValue);
            if (isNaN(numValue)) return '₱0.00';
            return '₱' + numValue.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        };
        
        // Set additional employee information
        document.getElementById('modal-total-days-worked').textContent = card.dataset.daysWorked + ' day' + (card.dataset.daysWorked != 1 ? 's' : '');
        
        // Fix late minutes display - show 0 if undefined
        const lateMins = card.dataset.lateMins || '0';
        document.getElementById('modal-late-mins').textContent = lateMins + ' min' + (lateMins != 1 ? 's' : '');
        
        // Set overtime hours (only regular OT hours, before 10pm cutoff)
        const regularOvertimeHours = card.dataset.regularOtHours || card.dataset.overtimeHours || '0.00';
        document.getElementById('modal-overtime-hours').textContent = regularOvertimeHours + ' hour' + (parseFloat(regularOvertimeHours) != 1 ? 's' : '');
        
        // Set NSD overtime hours (10pm onwards)
        const nsdOvertimeHours = card.dataset.nsdOtHours || card.dataset.nsdOvertimeHours || '0.00';
        document.getElementById('modal-nsd-ot-hours').textContent = nsdOvertimeHours + ' hour' + (parseFloat(nsdOvertimeHours) != 1 ? 's' : '');
        document.getElementById('modal-nsd-overtime-hours').textContent = nsdOvertimeHours + ' hour' + (parseFloat(nsdOvertimeHours) != 1 ? 's' : '');
        
        // Set employee details from database fields
        document.getElementById('modal-total-hours-worked').textContent = card.dataset.totalHours + ' hours';
        document.getElementById('modal-base-salary-db').textContent = formatCurrency(card.dataset.baseSalaryDb);
        document.getElementById('modal-basic-salary-earned').textContent = formatCurrency(card.dataset.baseSalary);
        document.getElementById('modal-employee-sss').textContent = card.dataset.sss || 'N/A';
        document.getElementById('modal-employee-philhealth').textContent = card.dataset.philhealth || 'N/A';
        document.getElementById('modal-employee-pagibig').textContent = card.dataset.pagibig || 'N/A';
        document.getElementById('modal-employee-tin').textContent = card.dataset.tin || 'N/A';
        
        // Set date covered
        const currentMonth = '<?php echo $current_month; ?>';
        const monthStart = new Date(currentMonth + '-01');
        const monthEnd = new Date();
        document.getElementById('modal-date-covered').textContent = 
            monthStart.toLocaleDateString() + ' - ' + monthEnd.toLocaleDateString();
        
        // Set earnings - Basic Salary is now calculated from attendance
        // Round all values to 2 decimals for precision
        const calculatedBasicSalary = Math.round(parseFloat(card.dataset.baseSalary.replace(/,/g, '')) * 100) / 100;
        document.getElementById('modal-basic-salary').textContent = formatCurrency(calculatedBasicSalary.toString());
        // Set Regular Overtime Pay (OT hours before 10pm)
        const regularOvertimePay = Math.round(parseFloat((card.dataset.regularOtPay || card.dataset.overtimePay || '0').replace(/,/g, '')) * 100) / 100;
        document.getElementById('modal-overtime-pay').textContent = formatCurrency(regularOvertimePay.toString());
        
        // Set NSD Overtime Pay (OT hours from 10pm onwards) - calculate from NSD OT hours
        const nsdOvertimePayValue = card.dataset.nsdOvertimePay || '0';
        const nsdOvertimePay = Math.round(parseFloat(nsdOvertimePayValue.toString().replace(/,/g, '')) * 100) / 100;
        
        // Display NSD Overtime Pay - always show in earnings section (even if 0)
        document.getElementById('nsd-overtime-row').style.display = 'flex';
        document.getElementById('modal-nsd-overtime-pay').textContent = formatCurrency(nsdOvertimePay.toString());
        const specialHolidayPayRounded = Math.round(parseFloat((card.dataset.specialHolidayPay || '0').replace(/,/g, '')) * 100) / 100;
        const legalHolidayPayRounded = Math.round(parseFloat((card.dataset.legalHolidayPay || '0').replace(/,/g, '')) * 100) / 100;
        document.getElementById('modal-special-holiday-pay').textContent = formatCurrency(specialHolidayPayRounded.toString());
        document.getElementById('modal-legal-holiday-pay').textContent = formatCurrency(legalHolidayPayRounded.toString());
        // Conditionally show/hide night shift differential based on shift
        const shift = card.dataset.shift || '';
        const isNightShift = shift.includes('22:00') || shift.includes('22:00-06:00') || 
                            shift.toLowerCase().includes('night') || shift.toLowerCase().includes('nsd');
        
        if (isNightShift) {
            document.getElementById('night-shift-row').style.display = 'flex';
            const nightShiftDiffRounded = Math.round(parseFloat((card.dataset.nightShiftDiff || '0').replace(/,/g, '')) * 100) / 100;
            document.getElementById('modal-night-shift-diff').textContent = formatCurrency(nightShiftDiffRounded.toString());
        } else {
            document.getElementById('night-shift-row').style.display = 'none';
        }
        // Check if current month is December
        const isDecember = currentMonth.endsWith('-12');
        
        // Show/hide December-only payments
        const leavePayRow = document.getElementById('modal-leave-pay-row');
        const thirteenthMonthRow = document.getElementById('modal-13th-month-row');
        
        if (isDecember) {
            leavePayRow.style.display = 'flex';
            thirteenthMonthRow.style.display = 'flex';
            const leavePayRounded = Math.round(parseFloat((card.dataset.leavePay || '0').replace(/,/g, '')) * 100) / 100;
            const thirteenthMonthRounded = Math.round(parseFloat((card.dataset['13thMonth'] || '0').replace(/,/g, '')) * 100) / 100;
            document.getElementById('modal-leave-pay').textContent = formatCurrency(leavePayRounded.toString());
            document.getElementById('modal-13th-month').textContent = formatCurrency(thirteenthMonthRounded.toString());
        } else {
            leavePayRow.style.display = 'none';
            thirteenthMonthRow.style.display = 'none';
        }
        
        // Set allowances from database and show/hide rows based on values
        // Round to 2 decimals for precision
        const laundryAllowance = Math.round(parseFloat((card.dataset.laundryAllowance || '0').replace(/,/g, '')) * 100) / 100;
        const medicalAllowance = Math.round(parseFloat((card.dataset.medicalAllowance || '0').replace(/,/g, '')) * 100) / 100;
        const riceAllowance = Math.round(parseFloat((card.dataset.riceAllowance || '0').replace(/,/g, '')) * 100) / 100;
        
        // Show/hide laundry allowance row
        if (laundryAllowance > 0) {
            document.getElementById('laundry-allowance-row').style.display = 'flex';
            document.getElementById('modal-laundry-allowance').textContent = formatCurrency(laundryAllowance.toString());
        } else {
            document.getElementById('laundry-allowance-row').style.display = 'none';
        }
        
        // Show/hide medical allowance row
        if (medicalAllowance > 0) {
            document.getElementById('medical-allowance-row').style.display = 'flex';
            document.getElementById('modal-medical-allowance').textContent = formatCurrency(medicalAllowance.toString());
        } else {
            document.getElementById('medical-allowance-row').style.display = 'none';
        }
        
        // Show/hide rice allowance row
        if (riceAllowance > 0) {
            document.getElementById('rice-allowance-row').style.display = 'flex';
            document.getElementById('modal-rice-allowance').textContent = formatCurrency(riceAllowance.toString());
        } else {
            document.getElementById('rice-allowance-row').style.display = 'none';
        }
        
        // Calculate total earnings using calculated basic salary from attendance
        // Round all values to 2 decimals for precision
        const basicSalaryAmount = Math.round(parseFloat(card.dataset.baseSalary.replace(/,/g, '')) * 100) / 100;
        const regularOvertimePayAmount = Math.round(parseFloat((card.dataset.regularOtPay || card.dataset.overtimePay || '0').replace(/,/g, '')) * 100) / 100;
        const nsdOvertimePayAmount = Math.round(parseFloat((card.dataset.nsdOvertimePay || '0').replace(/,/g, '')) * 100) / 100;
        const specialHolidayPay = Math.round(parseFloat((card.dataset.specialHolidayPay || '0').replace(/,/g, '')) * 100) / 100;
        const legalHolidayPay = Math.round(parseFloat((card.dataset.legalHolidayPay || '0').replace(/,/g, '')) * 100) / 100;
        const nightShiftDiff = Math.round(parseFloat((card.dataset.nightShiftDiff || '0').replace(/,/g, '')) * 100) / 100;
        const leavePay = isDecember ? Math.round(parseFloat((card.dataset.leavePay || '0').replace(/,/g, '')) * 100) / 100 : 0;
        const thirteenthMonth = isDecember ? Math.round(parseFloat((card.dataset['13thMonth'] || '0').replace(/,/g, '')) * 100) / 100 : 0;
        // Use the already parsed allowance values from above (already rounded)
        
        // Only include night shift differential for night shift employees
        const nightShiftDiffAmount = isNightShift ? nightShiftDiff : 0;
        // Total earnings includes both regular OT pay and NSD OT pay separately
        // Round final total to 2 decimals
        const totalEarnings = Math.round((basicSalaryAmount + regularOvertimePayAmount + nsdOvertimePayAmount + specialHolidayPay + legalHolidayPay + nightShiftDiffAmount + leavePay + thirteenthMonth + laundryAllowance + medicalAllowance + riceAllowance) * 100) / 100;
        
        document.getElementById('modal-total-earnings').textContent = formatCurrency(totalEarnings.toString());
        document.getElementById('modal-summary-earnings').textContent = formatCurrency(totalEarnings.toString());
        document.getElementById('modal-total-gross-income').textContent = formatCurrency(totalEarnings.toString());
        
        // Set deductions - round all to 2 decimals for precision
        const lates = Math.round(parseFloat(card.dataset.lates.replace(/,/g, '')) * 100) / 100;
        const phicEmployee = Math.round(parseFloat(card.dataset.phicEmployee.replace(/,/g, '')) * 100) / 100;
        const pagibigEmployee = Math.round(parseFloat(card.dataset.pagibigEmployee.replace(/,/g, '')) * 100) / 100;
        const sssEmployee = Math.round(parseFloat((card.dataset.sssEmployee || '0').replace(/,/g, '')) * 100) / 100;
        
        document.getElementById('modal-late-deductions').textContent = formatCurrency(lates.toString());
        document.getElementById('modal-phic-employee').textContent = formatCurrency(phicEmployee.toString());
        document.getElementById('modal-pagibig-employee').textContent = formatCurrency(pagibigEmployee.toString());
        document.getElementById('modal-sss-employee').textContent = formatCurrency(sssEmployee.toString());
        
        // Set late deduction amount
        document.getElementById('modal-late-deduction').textContent = formatCurrency(lates.toString());
        
        // Round total deductions to 2 decimals
        const totalDeductions = Math.round((lates + phicEmployee + pagibigEmployee + sssEmployee) * 100) / 100;
        document.getElementById('modal-total-deductions').textContent = formatCurrency(totalDeductions.toString());
        document.getElementById('modal-summary-deductions').textContent = formatCurrency(totalDeductions.toString());
        
        // Calculate net pay - use gross pay from backend calculation to ensure accuracy
        // Net Pay = Gross Pay + Leave Pay + 13th Month - Total Deductions
        const grossPay = Math.round(parseFloat((card.dataset.grossPay || '0').replace(/,/g, '')) * 100) / 100;
        const calculatedNetPay = Math.round((grossPay + leavePay + thirteenthMonth - totalDeductions) * 100) / 100;
        document.getElementById('modal-net-pay').textContent = formatCurrency(calculatedNetPay.toString());
        
        // Show the modal with animation
        const modal = document.getElementById('payslipModal');
        modal.style.display = 'block';
        // Trigger reflow to enable animation
        modal.offsetHeight;
    }

    function exportPayslip(employeeId, month) {
        window.open(`generate_payslip_pdf.php?employee_id=${employeeId}&month=${month}`, '_blank');
    }

    function closePayslipModal() {
        document.getElementById('payslipModal').style.display = 'none';
    }

    function exportPayslipPDF() {
        // Check user role
        const userRole = '<?php echo $user_role; ?>';
        if (userRole === 'admin') {
            alert('Access Denied: Admin accounts cannot export payslips.');
            return;
        }
        
        const employeeId = document.getElementById('modal-employee-id').textContent;
        const currentMonth = '<?php echo $current_month; ?>';
        
        if (!employeeId) {
            alert('Employee ID not found');
            return;
        }
        
        // Add loading state
        const exportBtn = document.getElementById('exportPayslipBtn');
        const originalContent = exportBtn.innerHTML;
        exportBtn.classList.add('loading');
        exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
        exportBtn.disabled = true;
        
        // Create download link
        const downloadUrl = `generate_payslip_pdf.php?employee_id=${employeeId}&month=${currentMonth}`;
        
        // Create temporary link and trigger download
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = `Payslip_${employeeId}_${currentMonth}.pdf`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Reset button state after a delay
        setTimeout(() => {
            exportBtn.classList.remove('loading');
            exportBtn.innerHTML = originalContent;
            exportBtn.disabled = false;
        }, 2000);
    }

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

    // Print payslip function
    function printPayslip() {
        const payslipContent = document.querySelector('.payslip-content');
        if (payslipContent) {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Payslip</title>
                        <style>
                            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
                            .payslip-content { max-width: 800px; margin: 0 auto; }
                        </style>
                    </head>
                    <body>
                        ${payslipContent.outerHTML}
                    </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
    }

    // Close modal when clicking outside
    document.addEventListener('click', function(event) {
        const modal = document.getElementById('payslipModal');
        if (event.target === modal) {
            closePayslipModal();
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closePayslipModal();
        }
    });

    // Close logout modal when clicking outside of it
    window.onclick = function(event) {
        const modal = document.getElementById('logoutModal');
        if (event.target === modal) {
            closeLogoutModal();
        }
    }

    // Close logout modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeLogoutModal();
        }
    });

    // Initialize page
    document.addEventListener('DOMContentLoaded', function() {
        // Collapse all departments by default
        const allEmployeeDivs = document.querySelectorAll('.department-employees');
        allEmployeeDivs.forEach(div => {
            div.classList.add('collapsed');
        });
        
        // Update all toggle buttons to show "Expand"
        const allToggleBtns = document.querySelectorAll('[id^="toggle-"]');
        allToggleBtns.forEach(btn => {
            btn.innerHTML = '<i class="fas fa-chevron-down"></i> Expand';
        });
    });

    // Add loading animation for search
    document.getElementById('employeeSearch').addEventListener('input', function() {
        const loading = document.getElementById('searchLoading');
        loading.style.display = 'flex';
        
        setTimeout(() => {
            loading.style.display = 'none';
        }, 500);
    });

    // Function to update summary cards for selected department
    function updateSummaryCardsForDepartment(department) {
        // Get all employee cards for the selected department
        const departmentSection = document.querySelector(`[data-department="${department}"]`);
        if (!departmentSection) return;
        
        const employeeCards = departmentSection.querySelectorAll('.employee-card');
        let totalPayroll = 0;
        let employeeCount = employeeCards.length;
        
        // Calculate totals from employee cards - round to 2 decimals for precision
        employeeCards.forEach(card => {
            const netPay = Math.round(parseFloat(card.dataset.netPay.replace(/,/g, '')) * 100) / 100;
            totalPayroll += netPay;
        });
        
        // Round final totals to 2 decimals
        totalPayroll = Math.round(totalPayroll * 100) / 100;
        const averageSalary = employeeCount > 0 ? Math.round((totalPayroll / employeeCount) * 100) / 100 : 0;
        
        // Update summary cards
        document.getElementById('summary-employee-count').textContent = employeeCount.toLocaleString();
        document.getElementById('summary-employee-subtitle').textContent = `Employees in ${department}`;
        
        document.getElementById('summary-total-payroll').textContent = '₱' + totalPayroll.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('summary-payroll-subtitle').textContent = `Total payroll for ${department}`;
        
        document.getElementById('summary-average-salary').textContent = '₱' + averageSalary.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('summary-average-subtitle').textContent = `Average salary in ${department}`;
        
        document.getElementById('summary-payroll-status').textContent = 'Complete';
        document.getElementById('summary-status-subtitle').textContent = `${department} payroll processed`;
    }

    // Function to reset summary cards to show all departments
    function resetSummaryCards() {
        // Get original values from PHP
        const originalEmployeeCount = <?php echo $total_employees; ?>;
        const originalTotalPayroll = <?php echo $total_net_pay; ?>;
        const originalAverageSalary = <?php echo $total_employees > 0 ? $total_net_pay / $total_employees : 0; ?>;
        const currentMonth = '<?php echo date('F Y', strtotime($current_month . '-01')); ?>';
        
        // Reset summary cards to original values
        document.getElementById('summary-employee-count').textContent = originalEmployeeCount.toLocaleString();
        document.getElementById('summary-employee-subtitle').textContent = `Active employees for ${currentMonth}`;
        
        document.getElementById('summary-total-payroll').textContent = '₱' + originalTotalPayroll.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('summary-payroll-subtitle').textContent = 'Total payroll for the month';
        
        document.getElementById('summary-average-salary').textContent = '₱' + originalAverageSalary.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('summary-average-subtitle').textContent = 'Average salary per employee';
        
        document.getElementById('summary-payroll-status').textContent = 'Complete';
        document.getElementById('summary-status-subtitle').textContent = 'All employees processed';
    }
    </script>
</body>
</html>
