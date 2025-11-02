<?php
session_start();

// Prevent caching to ensure current month is always displayed
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'depthead') {
    header("Location: Login.php");
    exit;
}

// Include centralized payroll computations
require_once 'payroll_computations.php';

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

// Get HR details
$dept_query = "SELECT * FROM deptheaduser WHERE DeptHeadID = ?";
$stmt = $conn->prepare($dept_query);
if ($stmt) {
    $stmt->bind_param("i", $hr_id);
    $stmt->execute();
    $dept_result = $stmt->get_result();
    $dept_details = $dept_result->fetch_assoc();
    $stmt->close();
}

// Payroll.php always shows current month's payroll - ignore any month parameter in URL
// Set timezone to ensure correct date calculation (always set, don't check)
date_default_timezone_set('Asia/Manila'); // Philippines timezone

// Force current month - ALWAYS use the server's actual current month
// This ensures Payroll.php always shows the current month, never past or future months
$current_month = date('Y-m'); // Gets current month from server (e.g., 2025-11 for November 2025)

// If a month parameter exists in URL, handle it (but always use current_month for display)
if (isset($_GET['month']) && !empty($_GET['month'])) {
    $requested_month = $_GET['month'];
    // Validate month format
    if (preg_match('/^\d{4}-\d{2}$/', $requested_month)) {
        // If requested month is in the past, redirect to PayrollHistory.php
        if ($requested_month < $current_month) {
            $redirect_url = 'PayrollHistory.php?month=' . urlencode($requested_month);
            if (isset($_GET['department']) && !empty($_GET['department'])) {
                $redirect_url .= '&department=' . urlencode($_GET['department']);
            }
            header("Location: " . $redirect_url);
            exit;
        }
        // If requested month is current month or future, use current_month (already set above)
        // We ignore the URL parameter and always use current month for Payroll.php
    }
}

// Ensure $current_month is definitely the current month (in case of any issues)
$current_month = date('Y-m'); // Re-confirm current month

$department_filter = isset($_GET['department']) ? $conn->real_escape_string($_GET['department']) : '';

// Deductions are now handled by payroll_computations.php
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
                   AND (DAYOFWEEK(a.attendance_date) != 1 OR (
                        e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%'
                   ))
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Management - WTEI</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="depthead-styles.css/depthead-styles.css?v=<?php echo time(); ?>">
    
    <style>
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

    /* Enhanced Summary Cards */
    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 25px;
        margin-bottom: 40px;
        perspective: 1000px;
    }

    .summary-card {
        background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    .summary-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        transition: left 0.5s;
    }

    .summary-card:hover::before {
        left: 100%;
    }

    .summary-card:hover {
        transform: translateY(-8px) rotateX(5deg);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
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
        color: rgba(255, 255, 255, 0.9);
    }

    .close-dept-btn {
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: white;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 18px;
    }

    .close-dept-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: scale(1.1);
    }

    .dept-search-container {
        padding: 25px 30px;
        background: white;
    }

    .dept-search-box {
        position: relative;
        max-width: 500px;
    }

    .dept-search-box i {
        position: absolute;
        left: 18px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--luxury-accent);
        font-size: 16px;
        z-index: 2;
    }

    .dept-search-box input {
        width: 100%;
        padding: 15px 50px 15px 50px;
        border: 2px solid #e9ecef;
        border-radius: 25px;
        font-size: 15px;
        font-weight: 500;
        transition: all 0.3s ease;
        background: #f8f9fa;
        outline: none;
    }

    .dept-search-box input:focus {
        border-color: var(--luxury-accent);
        background: white;
        box-shadow: 0 4px 12px rgba(44, 95, 138, 0.2);
    }

    .dept-search-box .clear-dept-search {
        position: absolute;
        right: 18px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        font-size: 16px;
        cursor: pointer;
        transition: color 0.3s ease;
    }

    .dept-search-box .clear-dept-search:hover {
        color: var(--luxury-accent);
    }

    .dept-employees-container {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
        padding: 30px;
        background: #F9F7F7;
    }

    .dept-employee-card {
        background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: 20px;
        padding: 25px;
        margin-bottom: 20px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        border: 2px solid transparent;
        background-clip: padding-box;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        position: relative;
        overflow: hidden;
        animation: luxuryFloat 8s ease-in-out infinite;
    }

    .dept-employee-card::after {
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

    .dept-employee-card::before {
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

    .dept-employee-card:hover {
        transform: translateY(-10px) scale(1.02);
        animation: luxuryGlow 2s ease-in-out infinite;
        box-shadow: var(--luxury-shadow);
    }

    .dept-employee-card:hover::before {
        opacity: 1;
    }

    .dept-employee-card:hover::after {
        opacity: 1;
    }

    .dept-employee-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f8f9fa;
        position: relative;
        z-index: 1;
    }

    .dept-employee-avatar {
        width: 55px;
        height: 55px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 20px;
        font-weight: 700;
        font-family: 'JetBrains Mono', monospace;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(63, 114, 175, 0.3);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .dept-employee-info h4 {
        margin: 0 0 5px 0;
        font-size: 16px;
        font-weight: 700;
        color: var(--primary-dark);
        line-height: 1.3;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: all 0.3s ease;
        position: relative;
    }

    .dept-employee-info p {
        margin: 0;
        font-size: 13px;
        color: #6c757d;
        font-family: 'JetBrains Mono', monospace;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: all 0.3s ease;
    }

    .dept-employee-info p::before {
        content: '●';
        color: var(--luxury-accent);
        font-size: 6px;
        margin-right: 4px;
    }

    .dept-payroll-computation {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 15px;
    }

    .dept-computation-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .dept-computation-label {
        font-size: 11px;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-family: 'JetBrains Mono', monospace;
    }

    .dept-computation-value {
        font-size: 15px;
        font-weight: 700;
        color: var(--primary-dark);
        font-family: 'JetBrains Mono', monospace;
    }

    .dept-net-pay-banner {
        background: var(--luxury-gradient);
        padding: 20px;
        border-radius: 16px;
        text-align: center;
        margin-top: 20px;
        box-shadow: 0 8px 25px rgba(44, 95, 138, 0.3);
        position: relative;
        overflow: hidden;
    }

    .dept-net-pay-banner::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
        transition: left 0.5s;
    }

    .dept-net-pay-banner:hover::before {
        left: 100%;
    }

    .dept-net-pay-label {
        font-size: 11px;
        color: rgba(255, 255, 255, 0.9);
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 5px;
        font-family: 'JetBrains Mono', monospace;
    }

    .dept-net-pay-value {
        font-size: 26px;
        font-weight: 700;
        color: white;
        font-family: 'JetBrains Mono', monospace;
    }

    .dept-employee-card:hover .dept-employee-avatar {
        transform: scale(1.1) rotate(5deg);
    }

    .dept-employee-card:hover::before {
        animation: luxuryShimmer 2s linear infinite;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Loading and Empty States */
    .loading-spinner {
        text-align: center;
        padding: 40px 20px;
        color: var(--luxury-accent);
        font-size: 14px;
    }

    .loading-spinner i {
        margin-right: 8px;
        animation: spin 1s linear infinite;
    }

    .error-message {
        text-align: center;
        padding: 40px 20px;
        color: #dc3545;
        font-size: 14px;
        background: rgba(220, 53, 69, 0.05);
        border-radius: 12px;
        margin: 20px 0;
    }

    .no-employees {
        text-align: center;
        padding: 40px 20px;
        color: #6c757d;
        font-size: 14px;
        background: rgba(108, 117, 125, 0.05);
        border-radius: 12px;
        margin: 20px 0;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    /* Header with Search */
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        background: white;
        padding: 20px 30px;
        border-radius: 16px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        gap: 20px;
        flex-wrap: wrap;
    }

    .header h1 {
        margin: 0;
        color: #112D4E;
        font-size: 24px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .search-container {
        flex: 1;
        max-width: 400px;
        min-width: 250px;
    }

    .search-box {
        position: relative;
        width: 100%;
    }

    .search-box i {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        font-size: 16px;
        z-index: 2;
        pointer-events: none;
    }

    .search-box input {
        width: 100%;
        padding: 12px 16px 12px 45px;
        border: 2px solid #e9ecef;
        border-radius: 25px;
        font-size: 14px;
        transition: all 0.3s ease;
        background: #f8f9fa;
        outline: none;
    }

    .search-box input:focus {
        border-color: #3F72AF;
        background: white;
        box-shadow: 0 4px 12px rgba(63, 114, 175, 0.15);
    }

    .search-results {
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        right: 0;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        max-height: 400px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
        border: 2px solid #e9ecef;
    }

    .search-results.active {
        display: block;
        animation: slideDown 0.3s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .search-result-item {
        padding: 14px 18px;
        border-bottom: 1px solid #f0f0f0;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .search-result-item:last-child {
        border-bottom: none;
    }

    .search-result-item:hover {
        background: linear-gradient(135deg, #f8fbff 0%, #f0f7ff 100%);
        padding-left: 22px;
    }

    .search-result-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 700;
        flex-shrink: 0;
    }

    .search-result-info {
        flex: 1;
        min-width: 0;
    }

    .search-result-info h4 {
        margin: 0 0 4px 0;
        font-size: 14px;
        font-weight: 600;
        color: #112D4E;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .search-result-info p {
        margin: 0;
        font-size: 12px;
        color: #6c757d;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .search-result-arrow {
        color: #3F72AF;
        font-size: 14px;
        flex-shrink: 0;
    }

    .search-no-results {
        padding: 20px;
        text-align: center;
        color: #6c757d;
        font-size: 14px;
    }

    .search-loading {
        padding: 20px;
        text-align: center;
        color: #3F72AF;
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
        border-radius: 12px;
        padding: 18px 20px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        border: 2px solid transparent;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 14px;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }

    .department-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        transform: scaleY(0);
        transition: transform 0.3s ease;
    }

    .department-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(63, 114, 175, 0.25);
        border-color: #3F72AF;
    }

    .department-card:hover::before {
        transform: scaleY(1);
    }

    .department-card.active {
        border-color: #3F72AF;
        background: linear-gradient(135deg, #E3F2FD 0%, #F3E5F5 100%);
    }

    .department-card.active::before {
        transform: scaleY(1);
    }

    .dept-icon {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        font-weight: 700;
        flex-shrink: 0;
    }

    .dept-info h4 {
        font-size: 16px;
        font-weight: 600;
        color: #112D4E;
        margin: 0 0 5px 0;
    }

    .dept-info p {
        font-size: 14px;
        color: #6c757d;
        margin: 0;
    }

    .input-group {
        position: relative;
        display: flex;
        align-items: center;
    }

    .input-group i {
        position: absolute;
        left: 12px;
        color: #6c757d;
        z-index: 2;
    }

    .input-group input {
        padding-left: 35px;
    }

    /* Compact Filter Bar */
    .compact-filter-bar {
        display: flex;
        gap: 15px;
        margin: 20px 0 30px;
        flex-wrap: wrap;
    }

    .filter-item {
        display: flex;
        align-items: center;
        gap: 10px;
        background: white;
        padding: 10px 18px;
        border-radius: 25px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        border: 2px solid transparent;
        transition: all 0.3s ease;
    }

    .filter-item:hover {
        border-color: #3F72AF;
        box-shadow: 0 4px 12px rgba(63, 114, 175, 0.2);
    }

    .filter-item i {
        color: #3F72AF;
        font-size: 16px;
    }

    .compact-select {
        border: none;
        background: transparent;
        color: #112D4E;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        outline: none;
        padding: 0;
        min-width: 150px;
    }

    .compact-select:focus {
        outline: none;
    }

    /* Enhanced Payroll Grid and Cards */
    .payroll-container {
        margin-top: 40px;
    }

    .department-section {
        margin-bottom: 40px;
    }

    .department-header {
        background: linear-gradient(135deg, #3F72AF 0%, #2563af 50%, #112D4E 100%);
        color: white;
        padding: 20px 30px;
        border-radius: 16px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 8px 25px rgba(63, 114, 175, 0.3);
        position: relative;
        overflow: hidden;
    }

    .department-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 200px;
        height: 200px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
        border-radius: 50%;
        animation: float 6s ease-in-out infinite;
    }

    .department-header h3 {
        margin: 0;
        color: white;
        font-size: 20px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 12px;
        position: relative;
        z-index: 1;
    }

    .department-header .dept-badge {
        background: rgba(255, 255, 255, 0.2);
        color: white;
        padding: 8px 16px;
        border-radius: 25px;
        font-size: 14px;
        font-weight: 600;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.3);
        position: relative;
        z-index: 1;
    }

    .payroll-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
        padding: 30px;
        background: #F9F7F7;
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
        width: 55px;
        height: 55px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        font-weight: 700;
        font-family: 'JetBrains Mono', monospace;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(63, 114, 175, 0.3);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .employee-avatar::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
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
        font-size: 16px;
        font-weight: 700;
        color: var(--primary-dark);
        line-height: 1.3;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: all 0.3s ease;
        position: relative;
    }

    .employee-info p {
        margin: 0;
        font-size: 13px;
        color: #6c757d;
        font-family: 'JetBrains Mono', monospace;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: all 0.3s ease;
    }

    .employee-info p::before {
        content: '●';
        color: var(--luxury-accent);
        font-size: 6px;
        margin-right: 4px;
    }

    .employee-payroll-computation {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 15px;
    }

    .employee-computation-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .employee-computation-label {
        font-size: 11px;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-family: 'JetBrains Mono', monospace;
    }

    .employee-computation-value {
        font-size: 15px;
        font-weight: 700;
        color: var(--primary-dark);
        font-family: 'JetBrains Mono', monospace;
    }

    .employee-net-pay-banner {
        background: var(--luxury-gradient);
        padding: 15px;
        border-radius: 12px;
        text-align: center;
        box-shadow: 0 8px 25px rgba(44, 95, 138, 0.3);
        position: relative;
        overflow: hidden;
    }

    .employee-net-pay-banner::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
        transition: left 0.5s;
    }

    .employee-net-pay-banner:hover::before {
        left: 100%;
    }

    .employee-net-pay-label {
        font-size: 11px;
        color: rgba(255, 255, 255, 0.9);
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 5px;
        font-family: 'JetBrains Mono', monospace;
    }

    .employee-net-pay-value {
        font-size: 20px;
        font-weight: 700;
        color: white;
        font-family: 'JetBrains Mono', monospace;
    }


    /* Department Filtering Styles */
    .department-section.hidden {
        display: none !important;
    }

    .department-section.visible {
        display: block !important;
    }

    /* Responsive Design */
    @media (max-width: 1024px) {
        .payroll-grid {
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        }
        
        .dept-employees-container {
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        }
    }

    @media (max-width: 768px) {
        .header {
            flex-direction: column;
            align-items: stretch;
            padding: 15px 20px;
        }

        .header h1 {
            font-size: 20px;
        }

        .search-container {
            max-width: 100%;
            width: 100%;
        }

        .summary-cards {
            grid-template-columns: 1fr;
            gap: 15px;
        }
        
        .department-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .payroll-grid {
            grid-template-columns: 1fr;
        }
        
        .dept-employees-container {
            grid-template-columns: 1fr;
        }
        
        .action-buttons-section {
            flex-direction: column;
        }
        
        .card-value {
            font-size: 24px;
        }
    }

    @media (max-width: 480px) {
        .search-box input {
            font-size: 13px;
            padding: 10px 12px 10px 40px;
        }

        .search-box i {
            left: 12px;
            font-size: 14px;
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
        max-width: 1400px;
        max-height: 90vh;
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

    .modal-close {
        position: absolute;
        top: 20px;
        right: 20px;
        font-size: 24px;
        color: white;
        cursor: pointer;
        z-index: 10;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        font-weight: 300;
        border: 2px solid rgba(255, 255, 255, 0.3);
    }

    .modal-close:hover {
        background: rgba(255, 255, 255, 0.35);
        transform: rotate(90deg) scale(1.15);
        border-color: white;
    }

    .header-export-btn {
        position: absolute;
        top: 20px;
        right: 65px;
        padding: 8px 20px;
        border-radius: 20px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        font-size: 11px;
        border: 2px solid rgba(255, 255, 255, 0.4);
        background: rgba(255, 255, 255, 0.15);
        color: white;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        z-index: 10;
        backdrop-filter: blur(10px);
    }

    .header-export-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        border-color: white;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .header-export-btn i {
        font-size: 12px;
    }

    .header-export-btn.loading {
        opacity: 0.7;
        cursor: not-allowed;
    }

    .header-export-btn.loading i {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
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
        grid-template-columns: repeat(4, 1fr);
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
        text-align: left;
    }

    .breakdown-row span:last-child {
        color: #000;
        font-weight: 600;
        font-size: 12px;
        text-align: right;            /* Align amounts to the right */
        min-width: 140px;             /* Keep columns aligned when exported */
        display: inline-block;         /* Ensure fixed width applies */
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

    .contributions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }

    .contribution-item {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid #f1f3f5;
        font-size: 14px;
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

    .signature-section {
        display: none;
    }

    .footer-note {
        font-size: 9px;
        color: #999;
        font-style: italic;
    }

    .footer-note p {
        margin: 0;
    }

    .action-buttons {
        display: none;
    }

    .action-btn {
        display: none;
    }

    .print-btn {
        display: none;
    }

    .download-btn {
        display: none;
    }

    /* Print Styles */
    @media print {
        .payslip-modal {
            position: static;
            background: none;
            padding: 0;
            display: block !important;
            width: 100%;
            height: auto;
        }
        
        .payslip-content {
            width: 100%;
            max-width: 100%;
            margin: 0;
            box-shadow: none;
            border-radius: 0;
        }
        
        .modal-close, .action-buttons {
            display: none !important;
        }
        
        .payslip-header {
            background: #f0f0f0 !important;
            color: #000 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .breakdown-row, .contribution-item, .summary-item {
            page-break-inside: avoid;
        }
        
        .total-row {
            background: #e0e0e0 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .payslip-header {
            flex-direction: column;
            text-align: center;
        }
        
        .pay-period {
            text-align: center;
        }
        
        .payslip-grid {
            grid-template-columns: 1fr;
        }
        
        .payslip-footer {
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        
        .footer-note {
            text-align: center;
        }
        
        .action-buttons {
            flex-direction: column;
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
    <div class="sidebar">
    <div class="logo">
    <img src="LOGO/newLogo_transparent.png" class="logo" style="width: 230px; height: 230px;padding-top: 70px;margin-bottom: 20px; margin-top: -70px; object-fit:contain; padding-bottom: -50px; padding-left: 0px; margin-right: 25px;padding: -190px; margin: 190;">
    <i class="fas fa-user-shield"></i> <!-- Changed icon -->
            <span>Accounting Head Portal</span>
        </div>
        <div class="menu">
            <a href="DeptHeadDashboard.php" class="menu-item"><i class="fas fa-th-large"></i> <span>Dashboard</span></a>
            <a href="DeptHeadEmployees.php" class="menu-item"><i class="fas fa-users"></i> <span>Employees</span></a>
            <a href="DeptHeadAttendance.php" class="menu-item"><i class="fas fa-calendar-check"></i> <span>Attendance</span></a>
            <a href="Payroll.php" class="menu-item active"><i class="fas fa-money-bill-wave"></i> <span>Payroll</span></a>
            <a href="DeptHeadHistory.php" class="menu-item"><i class="fas fa-history"></i> <span>History</span></a>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="header">
            <h1><i class="fas fa-money-bill-wave"></i> Payroll Management</h1>
            <div class="search-container">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="employeeSearch" placeholder="Search employee by name..." autocomplete="off">
                    <div id="searchResults" class="search-results"></div>
                </div>
            </div>
        </div>

        <!-- Current Month Display -->
        <div class="current-month-display">
            <div class="month-info">
                <i class="fas fa-calendar-alt"></i>
                <span class="month-label">Current Payroll Period:</span>
                <span class="month-value"><?php echo date('F Y', strtotime($current_month . '-01')); ?></span>
            </div>
            <div class="filter-item">
                <i class="fas fa-building"></i>
                <select class="compact-select" id="departmentFilter" onchange="updateFilters()">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>"
                                <?php echo $department_filter === $dept ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-top: 10px; font-size: 13px; color: #666; padding: 0 15px;">
                <i class="fas fa-info-circle"></i> Showing current month only. 
                <a href="PayrollHistory.php" style="color: #3F72AF; text-decoration: none; font-weight: 500;">
                    View past payrolls →
                </a>
            </div>
        </div>

        <!-- Summary Cards Section -->
        <div class="summary-cards">
            <div class="summary-card luxury-card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Employee Count</h3>
                </div>
                <div class="card-content">
                    <div class="card-value" id="summary-employee-count"><?php echo count($employees); ?></div>
                    <div class="card-subtitle" id="summary-employee-subtitle">Active employees for <?php echo date('F Y', strtotime($current_month . '-01')); ?></div>
                </div>
                <div class="card-glow"></div>
            </div>

            <div class="summary-card luxury-card">
                <div class="card-header">
                    <h3><i class="fas fa-dollar-sign"></i> Total Payroll Amount</h3>
                </div>
                <div class="card-content">
                    <div class="card-value" id="summary-total-payroll">
                        <?php 
                        $total_amount = 0;
                        foreach ($employees as $employee) {
                            $payroll = calculatePayroll($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                            // Additional deductions are now handled by payroll_computations.php
                            $additional_deductions = 0;
                            $final_net_pay = $payroll['net_pay'] - $additional_deductions;
                            $total_amount += $final_net_pay;
                        }
                        echo '₱' . number_format($total_amount, 3);
                        ?>
                    </div>
                    <div class="card-subtitle" id="summary-payroll-subtitle">Total payroll for the month</div>
                </div>
                <div class="card-glow"></div>
            </div>

            <div class="summary-card luxury-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Average Salary</h3>
                </div>
                <div class="card-content">
                    <div class="card-value" id="summary-average-salary">
                        <?php 
                        $total_salary = 0;
                        $employee_count = count($employees);
                        if ($employee_count > 0) {
                            foreach ($employees as $employee) {
                                $total_salary += $employee['base_salary'];
                            }
                            $average_salary = $total_salary / $employee_count;
                            echo '₱' . number_format($average_salary, 3);
                        } else {
                            echo '₱0.00';
                        }
                        ?>
                    </div>
                    <div class="card-subtitle" id="summary-average-subtitle">Average salary per employee</div>
                </div>
                <div class="card-glow"></div>
            </div>

            <div class="summary-card luxury-card">
                <div class="card-header">
                    <h3><i class="fas fa-check-circle"></i> Payroll Status</h3>
                </div>
                <div class="card-content">
                    <div class="card-value" id="summary-payroll-status">Complete</div>
                    <div class="card-subtitle" id="summary-status-subtitle">All employees processed</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 100%;"></div>
                    </div>
                    <button class="btn btn-primary btn-sm luxury-btn" onclick="runNewPayrollCycle()">
                        <i class="fas fa-play"></i> Run New Payroll Cycle
                    </button>
                </div>
                <div class="card-glow"></div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons-section">
            <button class="btn btn-outline" onclick="viewAllPayrollHistory()">
                <i class="fas fa-history"></i> View All Payroll History
            </button>
            <button class="btn btn-primary" onclick="exportPayroll()">
                <i class="fas fa-download"></i> Export Payroll
            </button>
        </div>

        <!-- Department Overview -->
        <div class="department-overview">
            <div class="section-header">
                <h2><i class="fas fa-building"></i> Department Overview</h2>
                <button class="btn btn-outline" onclick="showAllDepartments()" id="showAllBtn" style="display: none;">
                    <i class="fas fa-eye"></i> Show All Departments
                </button>
            </div>
            <div class="department-grid">
                <?php 
                $dept_counts = [];
                foreach ($employees as $employee) {
                    $dept = $employee['Department'];
                    if (!isset($dept_counts[$dept])) {
                        $dept_counts[$dept] = 0;
                    }
                    $dept_counts[$dept]++;
                }
                
                foreach ($dept_counts as $dept_name => $count): 
                    $dept_icon = strtoupper(substr($dept_name, 0, 1));
                ?>
                    <div class="department-card" onclick="filterByDepartment('<?php echo htmlspecialchars($dept_name, ENT_QUOTES); ?>')" data-dept="<?php echo htmlspecialchars($dept_name); ?>">
                        <div class="dept-icon"><?php echo $dept_icon; ?></div>
                        <div class="dept-info">
                            <h4><?php echo htmlspecialchars($dept_name); ?></h4>
                            <p><?php echo $count; ?> Employee<?php echo $count > 1 ? 's' : ''; ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Department Search Section -->
        <div id="departmentSearchSection" class="department-search-section" style="display: none;">
            <div class="department-header-card">
                <div class="dept-header-info">
                    <div class="dept-header-icon" id="selectedDeptIcon"></div>
                    <div class="dept-header-details">
                        <h3 id="selectedDeptName"></h3>
                        <p id="selectedDeptCount"></p>
                    </div>
                </div>
                <button class="close-dept-btn" onclick="closeDepartmentSearch()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="dept-search-container">
                <div class="dept-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="deptEmployeeSearch" placeholder="Search employees in this department..." autocomplete="off">
                    <i class="fas fa-times clear-dept-search" id="clearDeptSearch" style="display: none;"></i>
                </div>
            </div>
            
            <div id="deptEmployeesContainer" class="dept-employees-container">
                <!-- AJAX loaded employees will appear here -->
            </div>
        </div>

        <!-- Payroll Cards -->
        <div class="payroll-container">
            <?php if (empty($employees)): ?>
                <div class="no-data">
                    <p>No employees found for the selected filters.</p>
                </div>
            <?php else: ?>
                <?php 
                // Group employees by department
                $employeesByDept = [];
                foreach ($employees as $employee) {
                    $dept = $employee['Department'] ?: 'Unassigned';
                    if (!isset($employeesByDept[$dept])) {
                        $employeesByDept[$dept] = [];
                    }
                    $employeesByDept[$dept][] = $employee;
                }
                ksort($employeesByDept); // Sort departments alphabetically
                
                // Display each department section
                foreach ($employeesByDept as $deptName => $deptEmployees): 
                ?>
                    <div class="department-section" data-department="<?php echo htmlspecialchars($deptName); ?>">
                        <div class="department-header">
                            <h3>
                                <i class="fas fa-building"></i>
                                <?php echo htmlspecialchars($deptName); ?>
                            </h3>
                            <span class="dept-badge"><?php echo count($deptEmployees); ?> Employee<?php echo count($deptEmployees) > 1 ? 's' : ''; ?></span>
                        </div>
                        
                        <div class="payroll-grid">
                <?php 
                $displayCount = 0;
                $maxDisplay = 5;
                foreach ($deptEmployees as $employee): 
                    if ($displayCount >= $maxDisplay) break;
                    $displayCount++;
                    
                    // Calculate payroll components
                    $payroll = calculatePayroll($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                    
                    // Use centralized computation for Basic Salary
                    $basicSalary = $payroll['basic_salary'];
                    
                    // Additional deductions are now handled by payroll_computations.php
                    $additional_deductions = 0;
                    
                    // Final net pay calculation
                    $final_net_pay = $payroll['net_pay'] - $additional_deductions;
                    
                    // Calculate additional info for payslip
                    // Get working days in month (excluding Sundays)
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
                    
                    // Get absences (excluding Sundays)
                    $absencesQuery = "SELECT COUNT(*) as absence_count 
                                      FROM (
                                          SELECT DATE(a.attendance_date) as date 
                                          FROM attendance a
                                          JOIN empuser e ON a.EmployeeID = e.EmployeeID
                                          WHERE a.EmployeeID = ? 
                                          AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                                          AND a.status = 'absent'
                                          AND (DAYOFWEEK(a.attendance_date) != 1 OR (
                                              e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%'
                                          ))
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
                    
                    // Get late minutes using employee's shift start time
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
                    AND a.status = 'late' 
                    AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                    AND (DAYOFWEEK(a.attendance_date) != 1 OR (
                        e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%'
                    ))";
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
                    
                    // Get overtime hours breakdown using 10pm cutoff logic from payroll_computations.php
                    $otHoursBreakdown = calculateOvertimeHoursBreakdown($employee['EmployeeID'], $current_month, $conn);
                    $regular_overtime_hours = $otHoursBreakdown['regular_ot_hours'];
                    $total_nsd_overtime_hours = $otHoursBreakdown['nsd_ot_hours'];
                    
                    // Calculate OT pay separately for regular and NSD hours
                    // Regular OT: (base_salary / 8) * 1.25 * regular_OT_hours
                    // NSD OT: (hourly_rate * NSD_OT_hours) + (hourly_rate * 0.10 * 1.25 * NSD_OT_hours)
                    $hourlyRate = $employee['base_salary'] / 8;
                    $regular_overtime_pay = $hourlyRate * 1.25 * $regular_overtime_hours;
                    // NSD OT Pay = regular hourly rate + NSD premium
                    $nsdOTPremium = $hourlyRate * 0.10 * 1.25 * $total_nsd_overtime_hours;
                    $regularPayForNSDHours = $hourlyRate * $total_nsd_overtime_hours;
                    $nsd_overtime_pay = $regularPayForNSDHours + $nsdOTPremium;
                ?>
                    <div class="employee-card" onclick="viewPayslip('<?php echo $employee['EmployeeID']; ?>')" 
                         data-employee-id="<?php echo $employee['EmployeeID']; ?>"
                         data-employee-name="<?php echo htmlspecialchars($employee['EmployeeName']); ?>"
                         data-department="<?php echo htmlspecialchars($employee['Department']); ?>"
                         data-days-worked="<?php echo intval($employee['days_worked']); ?>"
                         data-total-hours="<?php 
                             $hourlyRateCalc = $employee['base_salary'] / 8.0; 
                             $hoursFromPay = ($hourlyRateCalc > 0) ? ($payroll['basic_salary'] / $hourlyRateCalc) : 0; 
                             echo number_format($hoursFromPay, 2); 
                         ?>"
                         data-base-salary="<?php echo number_format($basicSalary, 3); ?>"
                         data-overtime-pay="<?php echo number_format($regular_overtime_pay, 3); ?>"
                         data-regular-overtime-hours="<?php echo number_format($regular_overtime_hours, 3); ?>"
                         data-regular-overtime-pay="<?php echo number_format($regular_overtime_pay, 3); ?>"
                         data-special-holiday-pay="<?php echo number_format($payroll['special_holiday_pay'], 3); ?>"
                         data-legal-holiday-pay="<?php echo number_format($payroll['legal_holiday_pay'], 3); ?>"
                         data-night-shift-diff="<?php echo number_format($payroll['night_shift_diff'], 3); ?>"
                         data-nsd-overtime-pay="<?php echo number_format($nsd_overtime_pay, 3); ?>"
                         data-leave-pay="<?php echo number_format($payroll['leave_pay'], 3); ?>"
                         data-13th-month="<?php echo number_format($payroll['thirteenth_month_pay'], 3); ?>"
                         data-lates="<?php echo number_format($payroll['lates_amount'], 3); ?>"
                         data-phic-employee="<?php echo number_format($payroll['phic_employee'], 3); ?>"
                         data-phic-employer="<?php echo number_format($payroll['phic_employer'], 3); ?>"
                         data-pagibig-employee="<?php echo number_format($payroll['pagibig_employee'], 3); ?>"
                         data-pagibig-employer="<?php echo number_format($payroll['pagibig_employer'], 3); ?>"
                         data-sss-employee="<?php echo number_format($payroll['sss_employee'], 3); ?>"
                         data-sss-employer="<?php echo number_format($payroll['sss_employer'], 3); ?>"
                         data-net-pay="<?php echo number_format($final_net_pay, 3); ?>"
                         data-gross-pay="<?php echo number_format($payroll['gross_pay'], 3); ?>"
                         data-admin-fee="<?php echo number_format($payroll['admin_fee'], 3); ?>"
                         data-total-deductions="<?php echo number_format($payroll['total_deductions'] + $additional_deductions, 3); ?>"
                         data-laundry-allowance="<?php echo number_format($payroll['laundry_allowance'], 3); ?>"
                         data-medical-allowance="<?php echo number_format($payroll['medical_allowance'], 3); ?>"
                         data-rice-allowance="<?php echo number_format($payroll['rice_allowance'], 3); ?>"
                         data-monthly-rate="<?php echo number_format($monthly_rate, 3); ?>"
                         data-daily-rate="<?php echo number_format($daily_rate, 3); ?>"
                         data-absences="<?php echo $absences; ?>"
                         data-late-mins="<?php echo $late_minutes; ?>"
                         data-overtime-hours="<?php echo number_format($regular_overtime_hours, 3); ?>"
                         data-nsd-overtime-hours="<?php echo number_format($total_nsd_overtime_hours, 3); ?>"
                         data-sss="<?php echo $employee['SSS'] ?? 'N/A'; ?>"
                         data-philhealth="<?php echo $employee['PHIC'] ?? 'N/A'; ?>"
                         data-pagibig="<?php echo $employee['HDMF'] ?? 'N/A'; ?>"
                         data-tin="<?php echo $employee['TIN'] ?? 'N/A'; ?>"
                         data-shift="<?php echo $employee['Shift'] ?? 'N/A'; ?>"
                         data-base-salary-db="<?php echo number_format($employee['base_salary'], 2); ?>">
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
                            <div class="employee-net-pay-value">₱<?php echo number_format($final_net_pay, 3); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payslip Modal -->
    <div id="payslipModal" class="payslip-modal">
        <div class="payslip-content">
            <span class="modal-close" onclick="closePayslipModal()">&times;</span>
            <button class="header-export-btn" onclick="exportPayslipPDF()">
                <i class="fas fa-file-export"></i> Export PDF
            </button>
            
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
                            <label>Employee ID</label>
                            <span id="modal-employee-id"></span>
                        </div>
                        <div class="detail-group">
                            <label>Payroll Group</label>
                            <span>WTEICC</span>
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
                            <label>Total Hours Worked</label>
                            <span id="modal-total-hours-worked"></span>
                        </div>
                        <div class="detail-group">
                            <label>Date Covered</label>
                            <span id="modal-date-covered"></span>
                        </div>
                        <div class="detail-group">
                            <label>Payment Date</label>
                            <span><?php echo date('F j, Y'); ?></span>
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
                        <div class="breakdown-row" id="nsd-overtime-row">
                            <span>NSD Overtime Pay</span>
                            <span id="modal-nsd-overtime-pay"></span>
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
        
        // Employee Search with AJAX
        let searchTimeout;
        const searchInput = document.getElementById('employeeSearch');
        const searchResults = document.getElementById('searchResults');

        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();

            if (query.length < 2) {
                searchResults.classList.remove('active');
                return;
            }

            // Show loading
            searchResults.innerHTML = '<div class="search-loading"><i class="fas fa-spinner"></i> Searching...</div>';
            searchResults.classList.add('active');

            // Debounce search
            searchTimeout = setTimeout(() => {
                searchEmployees(query);
            }, 300);
        });

        function searchEmployees(query) {
            const currentMonth = '<?php echo $current_month; ?>';
            
            fetch(`search_employee_payroll.php?query=${encodeURIComponent(query)}&month=${encodeURIComponent(currentMonth)}`)
                .then(response => response.json())
                .then(data => {
                    displaySearchResults(data);
                })
                .catch(error => {
                    console.error('Search error:', error);
                    searchResults.innerHTML = '<div class="search-no-results">Error searching employees</div>';
                });
        }

        function displaySearchResults(employees) {
            if (employees.length === 0) {
                searchResults.innerHTML = '<div class="search-no-results">No employees found</div>';
                return;
            }

            let html = '';
            employees.forEach(employee => {
                const initial = employee.EmployeeName.charAt(0).toUpperCase();
                html += `
                    <div class="search-result-item" onclick="viewPayslipFromSearch('${employee.EmployeeID}')">
                        <div class="search-result-avatar">${initial}</div>
                        <div class="search-result-info">
                            <h4>${employee.EmployeeName}</h4>
                            <p>${employee.Department || 'No Department'}</p>
                        </div>
                    </div>
                `;
            });

            searchResults.innerHTML = html;
        }

        function viewPayslipFromSearch(employeeId) {
            searchResults.classList.remove('active');
            searchInput.value = '';
            viewPayslip(employeeId);
        }

        // Close search results when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.search-box')) {
                searchResults.classList.remove('active');
            }
        });

        function updateFilters() {
            const department = document.getElementById('departmentFilter').value;
            
            let url = 'Payroll.php';
            if (department) {
                url += '?department=' + encodeURIComponent(department);
            }
            
            window.location.href = url;
        }

        function viewPayslip(employeeId) {
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
            const formatCurrency = (value) => '₱' + parseFloat(value.replace(/,/g, '')).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
            
            // Set additional employee information
            document.getElementById('modal-base-salary-db').textContent = formatCurrency(card.dataset.baseSalaryDb);
            document.getElementById('modal-basic-salary-earned').textContent = formatCurrency(card.dataset.baseSalary);
            document.getElementById('modal-total-days-worked').textContent = card.dataset.daysWorked + ' day' + (card.dataset.daysWorked != 1 ? 's' : '');
            
            // Fix late minutes display - show 0 if undefined
            const lateMins = card.dataset.lateMins || '0';
            document.getElementById('modal-late-mins').textContent = lateMins + ' min' + (lateMins != 1 ? 's' : '');
            
            // Set overtime hours (only regular OT hours, before 10pm cutoff)
            const regularOvertimeHours = card.dataset.regularOvertimeHours || card.dataset.overtimeHours || '0.00';
            document.getElementById('modal-overtime-hours').textContent = regularOvertimeHours + ' hour' + (parseFloat(regularOvertimeHours) != 1 ? 's' : '');
            
            // Set NSD overtime hours (10pm onwards)
            const nsdOvertimeHours = card.dataset.nsdOvertimeHours || '0.00';
            document.getElementById('modal-nsd-overtime-hours').textContent = nsdOvertimeHours + ' hour' + (parseFloat(nsdOvertimeHours) != 1 ? 's' : '');
            
            // Set employee details from database fields
            document.getElementById('modal-employee-sss').textContent = card.dataset.sss || 'N/A';
            document.getElementById('modal-employee-philhealth').textContent = card.dataset.philhealth || 'N/A';
            document.getElementById('modal-employee-pagibig').textContent = card.dataset.pagibig || 'N/A';
            document.getElementById('modal-employee-tin').textContent = card.dataset.tin || 'N/A';
            
            // Set total hours worked from attendance
            document.getElementById('modal-total-hours-worked').textContent = card.dataset.totalHours + ' hours';
            
            // Set date covered
            const currentMonth = '<?php echo $current_month; ?>';
            const monthStart = new Date(currentMonth + '-01');
            const monthEnd = new Date();
            document.getElementById('modal-date-covered').textContent = 
                monthStart.toLocaleDateString() + ' - ' + monthEnd.toLocaleDateString();
            
            // Set earnings - Basic Salary is now calculated from attendance
            const calculatedBasicSalary = parseFloat(card.dataset.baseSalary.replace(/,/g, ''));
            document.getElementById('modal-basic-salary').textContent = formatCurrency(calculatedBasicSalary.toString());
            
            // Set Regular Overtime Pay (OT hours before 10pm)
            const regularOvertimePay = parseFloat((card.dataset.regularOvertimePay || card.dataset.overtimePay || '0').replace(/,/g, ''));
            document.getElementById('modal-overtime-pay').textContent = formatCurrency(regularOvertimePay.toString());
            
            document.getElementById('modal-special-holiday-pay').textContent = formatCurrency(card.dataset.specialHolidayPay || '0');
            document.getElementById('modal-legal-holiday-pay').textContent = formatCurrency(card.dataset.legalHolidayPay || '0');
            
            // Conditionally show/hide night shift differential based on shift
            const shift = card.dataset.shift || '';
            const isNightShift = shift.includes('22:00') || shift.includes('22:00-06:00') || 
                                shift.toLowerCase().includes('night') || shift.toLowerCase().includes('nsd');
            
            if (isNightShift) {
                document.getElementById('night-shift-row').style.display = 'flex';
                document.getElementById('modal-night-shift-diff').textContent = formatCurrency(card.dataset.nightShiftDiff || '0');
            } else {
                document.getElementById('night-shift-row').style.display = 'none';
            }
            
            // Set NSD Overtime Pay (OT hours from 10pm onwards) - always show in earnings
            const nsdOvertimePay = parseFloat((card.dataset.nsdOvertimePay || '0').replace(/,/g, ''));
            if (nsdOvertimePay > 0) {
                document.getElementById('nsd-overtime-row').style.display = 'flex';
                document.getElementById('modal-nsd-overtime-pay').textContent = formatCurrency(nsdOvertimePay.toString());
            } else {
                document.getElementById('nsd-overtime-row').style.display = 'flex'; // Show even if 0 to match payslip format
                document.getElementById('modal-nsd-overtime-pay').textContent = formatCurrency('0');
            }
            
            // Check if current month is December
            const isDecember = currentMonth.endsWith('-12');
            
            // Show/hide December-only payments
            const leavePayRow = document.getElementById('modal-leave-pay-row');
            const thirteenthMonthRow = document.getElementById('modal-13th-month-row');
            
            if (isDecember) {
                leavePayRow.style.display = 'flex';
                thirteenthMonthRow.style.display = 'flex';
                document.getElementById('modal-leave-pay').textContent = formatCurrency(card.dataset.leavePay);
                document.getElementById('modal-13th-month').textContent = formatCurrency(card.dataset['13thMonth']);
            } else {
                leavePayRow.style.display = 'none';
                thirteenthMonthRow.style.display = 'none';
            }
            
            // Set allowances from database and show/hide rows based on values
            const laundryAllowance = parseFloat((card.dataset.laundryAllowance || '0').replace(/,/g, ''));
            const medicalAllowance = parseFloat((card.dataset.medicalAllowance || '0').replace(/,/g, ''));
            const riceAllowance = parseFloat((card.dataset.riceAllowance || '0').replace(/,/g, ''));
            
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
            const basicSalaryAmount = parseFloat(card.dataset.baseSalary.replace(/,/g, ''));
            const regularOvertimePayAmount = parseFloat((card.dataset.regularOvertimePay || card.dataset.overtimePay || '0').replace(/,/g, ''));
            const specialHolidayPay = parseFloat((card.dataset.specialHolidayPay || '0').replace(/,/g, ''));
            const legalHolidayPay = parseFloat((card.dataset.legalHolidayPay || '0').replace(/,/g, ''));
            const nightShiftDiff = parseFloat((card.dataset.nightShiftDiff || '0').replace(/,/g, ''));
            const nsdOvertimePayAmount = parseFloat((card.dataset.nsdOvertimePay || '0').replace(/,/g, ''));
            const leavePay = isDecember ? parseFloat(card.dataset.leavePay.replace(/,/g, '')) : 0;
            const thirteenthMonth = isDecember ? parseFloat(card.dataset['13thMonth'].replace(/,/g, '')) : 0;
            // Use the already parsed allowance values from above
            
            // Only include night shift differential for night shift employees
            const nightShiftDiffAmount = isNightShift ? nightShiftDiff : 0;
            // Total earnings (gross pay) includes all earnings except leave pay and 13th month
            // Gross pay = basic salary + OT + holidays + night shift diff + allowances
            const totalEarnings = basicSalaryAmount + regularOvertimePayAmount + nsdOvertimePayAmount + specialHolidayPay + legalHolidayPay + nightShiftDiffAmount + laundryAllowance + medicalAllowance + riceAllowance;
            
            document.getElementById('modal-total-earnings').textContent = formatCurrency(totalEarnings.toString());
            document.getElementById('modal-summary-earnings').textContent = formatCurrency(totalEarnings.toString());
            document.getElementById('modal-total-gross-income').textContent = formatCurrency(totalEarnings.toString());
            
            // Set deductions
            const lates = parseFloat(card.dataset.lates.replace(/,/g, ''));
            const phicEmployee = parseFloat(card.dataset.phicEmployee.replace(/,/g, ''));
            const pagibigEmployee = parseFloat(card.dataset.pagibigEmployee.replace(/,/g, ''));
            const sssEmployee = parseFloat((card.dataset.sssEmployee || '0').replace(/,/g, ''));
            
            document.getElementById('modal-late-deductions').textContent = formatCurrency(lates.toString());
            document.getElementById('modal-phic-employee').textContent = formatCurrency(phicEmployee.toString());
            document.getElementById('modal-pagibig-employee').textContent = formatCurrency(pagibigEmployee.toString());
            document.getElementById('modal-sss-employee').textContent = formatCurrency(sssEmployee.toString());
            
            // Set late deduction amount
            document.getElementById('modal-late-deduction').textContent = formatCurrency(lates.toString());
            
            const totalDeductions = lates + phicEmployee + pagibigEmployee + sssEmployee;
            document.getElementById('modal-total-deductions').textContent = formatCurrency(totalDeductions.toString());
            document.getElementById('modal-summary-deductions').textContent = formatCurrency(totalDeductions.toString());
            
            // Calculate net pay accurately using backend formula: Gross Pay + Leave Pay + 13th Month Pay - Total Deductions
            // Use gross pay from backend to ensure accuracy (includes all earnings components)
            const grossPay = parseFloat((card.dataset.grossPay || '0').replace(/,/g, ''));
            
            // Net Pay = Gross Pay + Leave Pay + 13th Month Pay - Total Deductions
            // This matches the backend calculation in payroll_computations.php
            const calculatedNetPay = grossPay + leavePay + thirteenthMonth - totalDeductions;
            document.getElementById('modal-net-pay').textContent = formatCurrency(calculatedNetPay.toString());
            
            // Show the modal with animation
            const modal = document.getElementById('payslipModal');
            modal.style.display = 'block';
            // Trigger reflow to enable animation
            modal.offsetHeight;
        }

        function closePayslipModal() {
            document.getElementById('payslipModal').style.display = 'none';
        }
        

        function exportPayslipPDF() {
            const employeeId = document.getElementById('modal-employee-id').textContent;
            const currentMonth = '<?php echo $current_month; ?>';
            
            if (!employeeId) {
                alert('Employee ID not found');
                return;
            }
            
            // Add loading state
            const exportBtn = document.querySelector('.header-export-btn');
            const originalContent = exportBtn.innerHTML;
            exportBtn.classList.add('loading');
            exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
            exportBtn.disabled = true;
            
            // Collect values from modal to ensure exported values match the payslip view
            const getNum = (id) => {
                const el = document.getElementById(id);
                if (!el) return '';
                const t = el.textContent.replace(/[^0-9.\-]/g, '');
                return t;
            };

            const params = new URLSearchParams({
                employee_id: employeeId,
                month: currentMonth,
                total_hours: getNum('modal-total-hours-worked'),
                basic_salary_db: getNum('modal-base-salary-db'),
                basic_salary_earned: getNum('modal-basic-salary-earned'),
                days_worked: (document.getElementById('modal-total-days-worked')?.textContent || '').replace(/[^0-9]/g, ''),
                late_minutes: (document.getElementById('modal-late-mins')?.textContent || '').replace(/[^0-9]/g, ''),
                overtime_hours: getNum('modal-overtime-hours'),
                nsd_overtime_hours: getNum('modal-nsd-overtime-hours'),
                overtime_pay: getNum('modal-overtime-pay'),
                nsd_overtime_pay: getNum('modal-nsd-overtime-pay'),
                special_holiday_pay: getNum('modal-special-holiday-pay'),
                legal_holiday_pay: getNum('modal-legal-holiday-pay'),
                night_shift_diff: getNum('modal-night-shift-diff'),
                leave_pay: getNum('modal-leave-pay'),
                thirteenth_month: getNum('modal-13th-month'),
                earnings_total: getNum('modal-total-earnings'),
                late_deductions: getNum('modal-late-deductions'),
                phic_employee: getNum('modal-phic-employee'),
                pagibig_employee: getNum('modal-pagibig-employee'),
                sss_employee: getNum('modal-sss-employee'),
                deductions_total: getNum('modal-total-deductions'),
                net_pay: getNum('modal-net-pay')
            });

            // Create download link with serialized payslip values (export will default if params missing)
            const downloadUrl = `generate_payslip_pdf.php?${params.toString()}`;
            
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

        function runNewPayrollCycle() {
            if (confirm('Are you sure you want to run a new payroll cycle? This will process all employees for the current month.')) {
                // In a real implementation, this would trigger payroll processing
                alert('New payroll cycle started for <?php echo date('F Y', strtotime($current_month . '-01')); ?>');
            }
        }

        function viewAllPayrollHistory() {
            // Redirect to payroll history page
            window.location.href = 'PayrollHistory.php';
        }

        function exportPayroll() {
            const department = document.getElementById('departmentFilter').value;
            const month = '<?php echo date('F Y', strtotime($current_month . '-01')); ?>';
            
            // In a real implementation, this would export payroll data
            alert('Exporting payroll data for ' + month + (department ? ' - ' + department : ''));
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('payslipModal');
            if (event.target === modal) {
                closePayslipModal();
            }
        }

        // Department filtering with search functionality
        function filterByDepartment(deptName) {
            console.log('Filtering by department:', deptName);
            
            // Hide ALL department sections (both regular and search sections)
            const allDeptSections = document.querySelectorAll('.department-section');
            console.log('Found department sections:', allDeptSections.length);
            
            allDeptSections.forEach((section, index) => {
                console.log(`Hiding section ${index}:`, section.dataset.department);
                section.style.display = 'none';
                section.classList.add('hidden');
                section.classList.remove('visible');
            });
            
            // Hide the entire payroll container to prevent showing any department sections
            const payrollContainer = document.querySelector('.payroll-container');
            if (payrollContainer) {
                payrollContainer.style.display = 'none';
            }
            
            // Show the "Show All" button
            document.getElementById('showAllBtn').style.display = 'block';
            
            // Show the department search section
            const searchSection = document.getElementById('departmentSearchSection');
            const deptIcon = document.getElementById('selectedDeptIcon');
            const deptNameEl = document.getElementById('selectedDeptName');
            const deptCount = document.getElementById('selectedDeptCount');
            
            // Set department info
            deptIcon.textContent = deptName.charAt(0).toUpperCase();
            deptNameEl.textContent = deptName;
            
            // Show the search section
            searchSection.style.display = 'block';
            searchSection.scrollIntoView({ behavior: 'smooth' });
            
            // Update summary cards for selected department
            updateSummaryCardsForDepartment(deptName);
            
            // Load employees for this department
            loadDepartmentEmployees(deptName);
        }

        function closeDepartmentSearch() {
            // Hide the department search section
            document.getElementById('departmentSearchSection').style.display = 'none';
            document.getElementById('deptEmployeeSearch').value = '';
            document.getElementById('clearDeptSearch').style.display = 'none';
            
            // Show the payroll container again
            const payrollContainer = document.querySelector('.payroll-container');
            if (payrollContainer) {
                payrollContainer.style.display = 'block';
            }
            
            // Show all department sections again
            showAllDepartments();
        }

        function showAllDepartments() {
            // Show the payroll container
            const payrollContainer = document.querySelector('.payroll-container');
            if (payrollContainer) {
                payrollContainer.style.display = 'block';
            }
            
            // Show all department sections
            const allDeptSections = document.querySelectorAll('.department-section');
            allDeptSections.forEach(section => {
                section.style.display = 'block';
                section.classList.remove('hidden');
                section.classList.add('visible');
            });
            
            // Hide the "Show All" button
            document.getElementById('showAllBtn').style.display = 'none';
            
            // Reset summary cards to show all departments
            resetSummaryCards();
        }

        function loadDepartmentEmployees(department) {
            const container = document.getElementById('deptEmployeesContainer');
            container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading employees...</div>';
            
            fetch('search_employee_payroll.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `department=${encodeURIComponent(department)}&month=<?php echo $current_month; ?>&ajax=1`
            })
            .then(response => response.text())
            .then(data => {
                container.innerHTML = data;
                // Update employee count
                const employeeCards = container.querySelectorAll('.dept-employee-card');
                document.getElementById('selectedDeptCount').textContent = `${employeeCards.length} Employee${employeeCards.length !== 1 ? 's' : ''}`;
            })
            .catch(error => {
                console.error('Error loading department employees:', error);
                container.innerHTML = '<div class="error-message">Error loading employees. Please try again.</div>';
            });
        }

        // Department search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const deptSearchInput = document.getElementById('deptEmployeeSearch');
            const clearBtn = document.getElementById('clearDeptSearch');
            
            if (deptSearchInput) {
                deptSearchInput.addEventListener('input', function() {
                    const query = this.value.toLowerCase().trim();
                    
                    if (query.length > 0) {
                        clearBtn.style.display = 'block';
                    } else {
                        clearBtn.style.display = 'none';
                    }
                    
                    // Filter employees in real-time
                    const employeeCards = document.querySelectorAll('.dept-employee-card');
                    employeeCards.forEach(card => {
                        const name = card.querySelector('h4').textContent.toLowerCase();
                        const id = card.querySelector('p').textContent.toLowerCase();
                        
                        if (query.length === 0 || name.includes(query) || id.includes(query)) {
                            card.style.display = 'block';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            }
            
            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    document.getElementById('deptEmployeeSearch').value = '';
                    this.style.display = 'none';
                    
                    // Show all employees
                    const employeeCards = document.querySelectorAll('.dept-employee-card');
                    employeeCards.forEach(card => {
                        card.style.display = 'block';
                    });
                });
            }
        });

        // Function to update summary cards for selected department
        function updateSummaryCardsForDepartment(department) {
            // Get all employee cards for the selected department
            const departmentSection = document.querySelector(`[data-department="${department}"]`);
            if (!departmentSection) return;
            
            const employeeCards = departmentSection.querySelectorAll('.employee-card');
            let totalPayroll = 0;
            let employeeCount = employeeCards.length;
            let totalSalary = 0;
            
            // Calculate totals from employee cards
            employeeCards.forEach(card => {
                const netPay = parseFloat(card.dataset.netPay ? card.dataset.netPay.replace(/,/g, '') : '0');
                const baseSalary = parseFloat(card.dataset.baseSalary ? card.dataset.baseSalary.replace(/,/g, '') : '0');
                totalPayroll += netPay;
                totalSalary += baseSalary;
            });
            
            const averageSalary = employeeCount > 0 ? totalSalary / employeeCount : 0;
            
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
            const originalEmployeeCount = <?php echo count($employees); ?>;
            const originalTotalPayroll = <?php 
                $total_amount = 0;
                foreach ($employees as $employee) {
                    $payroll = calculatePayroll($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                    // Additional deductions are now handled by payroll_computations.php
                    $additional_deductions = 0;
                    $final_net_pay = $payroll['net_pay'] - $additional_deductions;
                    $total_amount += $final_net_pay;
                }
                echo $total_amount;
            ?>;
            const originalAverageSalary = <?php 
                $total_salary = 0;
                $employee_count = count($employees);
                if ($employee_count > 0) {
                    foreach ($employees as $employee) {
                        $total_salary += $employee['base_salary'];
                    }
                    echo $total_salary / $employee_count;
                } else {
                    echo 0;
                }
            ?>;
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