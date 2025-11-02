    <?php
session_start();

// Check if user is logged in and is a Department Head
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'depthead' || !isset($_SESSION['user_department'])) {
    header("Location: login.php");
    exit;
}
date_default_timezone_set('Asia/Manila');

// Include attendance calculations
require_once 'attendance_calculations.php';

$dept_head_name = $_SESSION['username'] ?? 'Dept Head';
$managed_department = $_SESSION['user_department'];
$dept_head_id = $_SESSION['userid'] ?? null;

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Ensure MySQL session timezone matches PHP
$conn->query("SET time_zone = '+08:00'");

$has_payroll_access = ($managed_department == 'Accounting');

// Fetch ALL employees for filter dropdown (not just from managed department)
$all_employees = [];
$emp_stmt = $conn->prepare("SELECT EmployeeID, EmployeeName, Department FROM empuser ORDER BY EmployeeName");
if ($emp_stmt) {
    $emp_stmt->execute();
    $emp_result = $emp_stmt->get_result();
    while ($row = $emp_result->fetch_assoc()) {
        $all_employees[] = $row;
    }
    $emp_stmt->close();
}


// Initial filter values
$filter_employee_id = $_GET['employee_id'] ?? '';
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_status = $_GET['status'] ?? '';
$filter_shift = $_GET['shift'] ?? '';
$filter_mode = $_GET['filter_mode'] ?? 'today'; // 'today', 'date', 'month'

// Fetch attendance records for today only (like HRAttendance.php)
$today = date('Y-m-d');
$attendance_records = [];

// Get all employees with their attendance status for today (including absent employees)
$sql = "SELECT 
            COALESCE(a.id, 0) as id,
            e.EmployeeID,
            e.EmployeeName,
            e.Position,
            e.Department,
            e.Shift,
            a.attendance_date,
            a.time_in,
            a.time_out,
            a.time_in_morning,
            a.time_out_morning,
            a.time_in_afternoon,
            a.time_out_afternoon,
            a.status,
            a.notes,
            a.data_source,
            a.overtime_hours,
            a.late_minutes,
            a.early_out_minutes,
            a.is_overtime,
            a.attendance_type,
            CASE 
                WHEN a.id IS NULL THEN 'absent'
                ELSE COALESCE(a.attendance_type, 'present')
            END as final_attendance_type
        FROM empuser e
        LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND DATE(a.attendance_date) = ?
        WHERE e.Status = 'active'
        ORDER BY e.EmployeeName ASC";

$params = [$today];
$types = "s";

$stmt_att = $conn->prepare($sql);
if ($stmt_att) {
    if (!empty($params)) {
        $stmt_att->bind_param($types, ...$params);
    }
    $stmt_att->execute();
    $result_att = $stmt_att->get_result();
    while ($row = $result_att->fetch_assoc()) {
        $attendance_records[] = $row;
    }
    $stmt_att->close();
    
    // Apply accurate calculations to all attendance records
    $attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);
}

// Compute attendance stats for today (like HRAttendance.php)
$stats_query = "SELECT 
                COUNT(DISTINCT CASE WHEN a.attendance_type = 'present' THEN e.EmployeeID END) as total_present,
                COUNT(DISTINCT CASE WHEN a.attendance_type = 'present' AND a.time_out IS NULL THEN e.EmployeeID END) as still_present,
                COUNT(DISTINCT CASE WHEN a.status = 'late' THEN e.EmployeeID END) as late_arrivals,
                COUNT(DISTINCT CASE WHEN a.status = 'early_in' THEN e.EmployeeID END) as early_in,
                COUNT(DISTINCT CASE WHEN a.attendance_type = 'absent' OR a.id IS NULL THEN e.EmployeeID END) as total_absent
                FROM empuser e
                LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND DATE(a.attendance_date) = ?
                WHERE e.Status = 'active'";

$stmt_stats = $conn->prepare($stats_query);
if (!$stmt_stats) {
    error_log("Stats prepare failed: " . $conn->error);
    $stats = ['total_present' => 0, 'still_present' => 0, 'late_arrivals' => 0, 'early_in' => 0, 'total_absent' => 0];
} else {
    $stmt_stats->bind_param("s", $today);
    
    if (!$stmt_stats->execute()) {
        error_log("Stats execute failed: " . $stmt_stats->error);
        $stats = ['total_present' => 0, 'still_present' => 0, 'late_arrivals' => 0, 'early_in' => 0, 'total_absent' => 0];
    } else {
        $stats_result = $stmt_stats->get_result();
        $stats = $stats_result->fetch_assoc();
    }
    $stmt_stats->close();
}

// Get total employees company-wide (unfiltered) for attendance rate denominator
$total_emp_query = "SELECT COUNT(DISTINCT EmployeeID) as total_employees FROM empuser WHERE Status = 'active'";
$total_result = $conn->query($total_emp_query);
if ($total_result) {
    $total_data = $total_result->fetch_assoc();
    $total_employees = (int)($total_data['total_employees'] ?? 0);
} else {
    error_log("Total employees query failed: " . $conn->error);
    $total_employees = 0;
}

// Daily calculations
$total_present = (int)($stats['total_present'] ?? 0);
$total_absent = (int)($stats['total_absent'] ?? 0);
$total_late = (int)($stats['late_arrivals'] ?? 0);
$attendance_rate = $total_employees > 0 
    ? round(($total_present / $total_employees) * 100)
    : 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Attendance - All Departments - WTEI</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="depthead-styles.css/depthead-styles.css?v=<?php echo time(); ?>">
    <style>
        :root {
    --primary-color: #112D4E;      /* Dark blue */
    --secondary-color: #3F72AF;    /* Medium blue */
    --accent-color: #DBE2EF;       /* Light blue/gray */
    --background-color: #F9F7F7;   /* Off white */
    --text-color: #112D4E;         /* Using dark blue for text */
    --border-color: #DBE2EF;       /* Light blue for borders */
    --shadow-color: rgba(17, 45, 78, 0.1); /* Primary color shadow */
    --success-color: #3F72AF;      /* Using secondary color for success */
    --warning-color: #DBE2EF;      /* Using accent color for warnings */
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
    color: var(--text-color);
}

/* Sidebar Styles */
.sidebar {
    width: 250px;  /* Slightly reduced from 280px */
    background: linear-gradient(180deg, var(--primary-color) 0%, var(--secondary-color) 100%);
    padding: 20px 0;
    box-shadow: 4px 0 10px var(--shadow-color);
    display: flex;
    flex-direction: column;
    color: white;
    position: fixed;
    height: 100vh;
    transition: width 0.3s ease, margin-left 0.3s ease;
    border-right: 2px solid var(--accent-color);
    z-index: 100;
    left: 0;
    top: 0;
}

.logo {
    font-weight: 600;
    font-size: 24px;
    padding: 25px;
    text-align: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    margin-bottom: 20px;
    color: white;
    letter-spacing: 1px;
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
    background-color: rgba(219, 226, 239, 0.2); /* accent color with opacity */
    color: var(--background-color);
    transform: translateX(5px);
}

.menu-item i {
    margin-right: 15px;
    width: 20px;
    text-align: center;
    font-size: 18px;
}

.logout-btn {
    background-color: var(--accent-color);
    color: var(--primary-color);
    border: 1px solid var(--secondary-color);
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
    background-color: var(--secondary-color);
    color: var(--background-color);
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
            transition: all 0.3s ease;
        }

        /* Enhanced Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border-bottom: 2px solid #DBE2EF;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(to right, #3F72AF, #112D4E);
        }

        .header h1 {
            font-size: 28px;
            color: #112D4E;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 i {
            color: #3F72AF;
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
            border: 2px solid #DBE2EF;
            border-radius: 12px;
            font-size: 14px;
            background-color: #fff;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .search-box input:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
        }

        .search-box i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #112D4E;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(63, 114, 175, 0.3);
        }

        .btn-secondary {
            background-color: #DBE2EF;
            color: #112D4E;
        }

        .btn-secondary:hover {
            background-color: #3F72AF;
            color: white;
            transform: translateY(-2px);
        }

        /* Enhanced Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            transition: all 0.3s;
            border-left: 4px solid #3F72AF;
            position: relative;
            overflow: hidden;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        }

        .summary-card:nth-child(2) {
            border-left-color: #16C79A;
        }

        .summary-card:nth-child(3) {
            border-left-color: #FF6B6B;
        }

        .summary-card:nth-child(4) {
            border-left-color: #FFC75F;
        }

        .summary-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 24px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .summary-icon.present {
            background-color: rgba(63, 114, 175, 0.15);
            color: #3F72AF;
        }

        .summary-icon.rate {
            background-color: rgba(22, 199, 154, 0.15);
            color: #16C79A;
        }

        .summary-icon.absent {
            background-color: rgba(255, 107, 107, 0.15);
            color: #FF6B6B;
        }

        .summary-icon.late {
            background-color: rgba(255, 199, 95, 0.15);
            color: #FFC75F;
        }

        .summary-info {
            flex-grow: 1;
        }

        .summary-value {
            font-size: 32px;
            font-weight: 700;
            color: #112D4E;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }

        .summary-label {
            font-size: 14px;
            color: #6c757d;
            font-weight: 500;
        }

        /* Enhanced Filter Container */
        .filter-container {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            border: 1px solid rgba(219, 226, 239, 0.5);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #DBE2EF;
        }

        .filter-header h2 {
            font-size: 20px;
            color: #112D4E;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-header h2 i {
            color: #3F72AF;
        }

        .filter-controls {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .filter-mode-buttons {
            display: inline-flex;
            border: 1px solid #DBE2EF;
            border-radius: 12px;
            overflow: hidden;
            padding: 0;
            width: fit-content;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .date-filter-btn {
            background-color: white;
            color: #4A5568;
            border: none;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border-right: 1px solid #DBE2EF;
        }

        .filter-mode-buttons .date-filter-btn:last-child {
            border-right: none;
        }

        .date-filter-btn.active {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .date-filter-btn:not(.active):hover {
            background-color: #F8FBFF;
        }

        .filter-input-area {
            padding: 15px 0;
            border-top: 1px solid #DBE2EF;
        }

        .filter-input-area > div {
            padding-top: 15px;
        }

        .date-input-container label,
        .month-input-container label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #112D4E;
        }

        .filter-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            border-top: 1px solid #DBE2EF;
            padding-top: 25px;
        }

        .filter-option label {
            display: block;
            margin-bottom: 8px;
            color: #112D4E;
            font-weight: 600;
            font-size: 14px;
        }

        .filter-option select,
        .filter-option input {
            width: 100%;
            padding: 12px;
            border: 2px solid #DBE2EF;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
            background-color: white;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .filter-option select:focus,
        .filter-option input:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
        }

        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #DBE2EF;
        }

        /* Enhanced Results Container */
        .results-container {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            border: 1px solid rgba(219, 226, 239, 0.5);
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #DBE2EF;
        }

        .results-header h2 {
            font-size: 20px;
            color: #112D4E;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .results-header h2 i {
            color: #3F72AF;
        }

        .results-actions {
            display: flex;
            gap: 12px;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 1000px;
        }

        table th {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 16px 12px;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table th:first-child {
            border-top-left-radius: 12px;
        }

        table th:last-child {
            border-top-right-radius: 12px;
        }

        table td {
            padding: 16px 12px;
            border-bottom: 1px solid #F0F4F8;
            color: #2D3748;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        table tr {
            background-color: white;
            transition: all 0.2s ease;
        }

        table tr:hover {
            background-color: #F8FBFF;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(63, 114, 175, 0.1);
        }

        table tr:hover td {
            border-color: #DBE2EF;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            word-break: keep-all;
            overflow-wrap: normal;
        }

        .status-present {
            background-color: rgba(63, 114, 175, 0.15);
            color: #3F72AF;
        }

        .status-absent {
            background-color: rgba(255, 107, 107, 0.15);
            color: #FF6B6B;
        }

        .status-pending {
            background-color: rgba(255, 199, 95, 0.15);
            color: #FFC75F;
        }

        .status-late {
            background-color: rgba(255, 235, 59, 0.15);
            color: #F57F17;
        }

        .status-early_in {
            background-color: rgba(76, 175, 80, 0.15);
            color: #4CAF50;
        }

        .status-halfday {
            background-color: rgba(33, 150, 243, 0.15);
            color: #2196F3;
        }

        .status-on_time {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 235, 59, 0.2));
            color: #FF8F00;
            box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .view-btn {
            background-color: rgba(63, 114, 175, 0.15);
            color: #3F72AF;
        }

        .view-btn:hover {
            background-color: #3F72AF;
            color: white;
            transform: scale(1.1);
        }

        .edit-btn {
            background-color: rgba(255, 199, 95, 0.15);
            color: #FFC75F;
        }

        .edit-btn:hover {
            background-color: #FFC75F;
            color: white;
            transform: scale(1.1);
        }

        .delete-btn {
            background-color: rgba(255, 107, 107, 0.15);
            color: #FF6B6B;
        }

        .delete-btn:hover {
            background-color: #FF6B6B;
            color: white;
            transform: scale(1.1);
        }

        .approve-btn {
            background-color: rgba(22, 199, 154, 0.15);
            color: #16C79A;
        }

        .approve-btn:hover {
            background-color: #16C79A;
            color: white;
            transform: scale(1.1);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 25px;
        }

        .pagination button {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            border: 1px solid #DBE2EF;
            background-color: white;
            color: #112D4E;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .pagination button.active {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            border-color: #3F72AF;
        }

        .pagination button:hover:not(.active) {
            background-color: #DBE2EF;
            transform: translateY(-2px);
        }

        /* Enhanced Alert Styles */
        .alert {
            padding: 16px 20px;
            margin-bottom: 25px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            animation: slideIn 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            background-color: rgba(22, 199, 154, 0.15);
            color: #16C79A;
            border: 1px solid rgba(22, 199, 154, 0.2);
        }

        .alert-error {
            background-color: rgba(255, 71, 87, 0.15);
            color: #ff4757;
            border: 1px solid rgba(255, 71, 87, 0.2);
        }

        .alert i {
            margin-right: 12px;
            font-size: 20px;
        }

        /* Responsive styles */
        @media (max-width: 1200px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

            .header-actions {
                width: 100%;
            }

            .search-box {
                width: 100%;
            }

            .filter-options {
                grid-template-columns: 1fr;
            }
        }

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

            .main-content {
                margin-left: 80px;
                padding: 20px;
            }

            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .summary-cards {
                grid-template-columns: 1fr;
            }

            .results-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .results-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .filter-mode-buttons {
                flex-direction: column;
                width: 100%;
            }

            .date-filter-btn {
                border-right: none;
                border-bottom: 1px solid #DBE2EF;
            }

            .filter-mode-buttons .date-filter-btn:last-child {
                border-bottom: none;
            }

            .filter-actions {
                flex-direction: column;
            }
        }

        /* Animation for table rows */
        @keyframes fadeInRow {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        table tr {
            animation: fadeInRow 0.3s ease;
        }

        table tr:nth-child(even) {
            animation-delay: 0.05s;
        }

        table tr:nth-child(odd) {
            animation-delay: 0.1s;
        }

        /* Custom scrollbar */
        .table-container::-webkit-scrollbar {
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Search Container Styles */
        .search-container {
            position: relative;
            width: 100%;
        }

        .search-container input {
            width: 100%;
            padding: 8px 35px 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: white;
        }

        .search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            pointer-events: none;
        }

        /* Pagination Styles */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 15px 0;
            border-top: 1px solid #eee;
        }

        .pagination-info {
            color: #666;
            font-size: 14px;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pagination-btn {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background-color: white;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #333;
        }

        .pagination-btn:hover:not(:disabled) {
            background-color: #f8f9fa;
            border-color: #3F72AF;
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .page-numbers {
            display: flex;
            gap: 5px;
        }

        .page-number {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background-color: white;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #333;
            min-width: 40px;
            text-align: center;
        }

        .page-number:hover {
            background-color: #f8f9fa;
            border-color: #3F72AF;
        }

        .page-number.active {
            background-color: #3F72AF;
            color: white;
            border-color: #3F72AF;
        }

        .items-per-page {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
            font-size: 14px;
        }

        .items-per-page select {
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: white;
        }

        /* Enhanced Modal Styles */
        .enhanced-modal {
            animation: modalSlideIn 0.4s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px) scale(0.95);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        .enhanced-header {
            position: relative;
        }

        .enhanced-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(255,255,255,0.1) 0%, transparent 50%, rgba(255,255,255,0.05) 100%);
            pointer-events: none;
        }

        .close-btn:hover {
            background: rgba(255,255,255,0.3) !important;
            transform: scale(1.1);
        }

        .attendance-details {
            display: block;
        }

        /* Responsive modal */
        @media (max-width: 1024px) {
            #viewAttendanceModal .modal-content {
                max-width: 95% !important;
                width: 95% !important;
            }
            
            /* Make 3-column grids become 2 columns on tablets */
            #viewAttendanceBody [style*="grid-template-columns: repeat(3, 1fr)"] {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }
        
        @media (max-width: 768px) {
            #viewAttendanceModal .modal-content {
                max-width: 98% !important;
                width: 98% !important;
                margin: 2% auto !important;
                max-height: 95vh !important;
            }
            
            #viewAttendanceBody {
                padding: 15px !important;
            }
            
            /* Make all grid sections stack on mobile */
            #viewAttendanceBody [style*="grid-template-columns: repeat(3, 1fr)"],
            #viewAttendanceBody [style*="grid-template-columns: repeat(2, 1fr)"],
            #viewAttendanceBody [style*="grid-template-columns: 1fr 1fr"] {
                display: grid !important;
                grid-template-columns: 1fr !important;
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
            <a href="DeptHeadAttendance.php" class="menu-item active"><i class="fas fa-calendar-check"></i> <span>Attendance</span></a>
            
            <?php if($managed_department == 'Accounting'): ?>
                <a href="Payroll.php" class="menu-item">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Payroll</span>
                </a>
                <a href="DeptHeadHistory.php" class="menu-item">
                    <i class="fas fa-history"></i> History
                </a>
            <?php endif; ?>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Attendance Records: All Departments</h1>
            <div class="header-actions">
                <div class="profile-dropdown">
                    <div class="dropdown-content">
                        <a href="logout.php" onclick="return confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon present">
                    <i class="fas fa-users"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="present-today-value"><?php echo $total_present; ?></div>
                    <div class="summary-label">
                        <?php echo ($filter_mode === 'month' ? 'Total Present' : 'Present Today'); ?>
                        <?php if ($filter_mode === 'month' && !empty($within_days_text)): ?>
                            <div style="color:#6c757d; font-size:12px; font-weight:500;">(<?php echo htmlspecialchars($within_days_text); ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon rate">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="attendance-rate-value"><?php echo $attendance_rate; ?>%</div>
                    <div class="summary-label">
                        Attendance Rate
                        <?php if ($filter_mode === 'month' && !empty($within_days_text)): ?>
                            <div style="color:#6c757d; font-size:12px; font-weight:500;">(<?php echo htmlspecialchars($within_days_text); ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon absent">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="absent-today-value"><?php echo $total_absent; ?></div>
                    <div class="summary-label">
                        <?php echo ($filter_mode === 'month' ? 'Total Absent' : 'Absent Today'); ?>
                        <?php if ($filter_mode === 'month' && !empty($within_days_text)): ?>
                            <div style="color:#6c757d; font-size:12px; font-weight:500;">(<?php echo htmlspecialchars($within_days_text); ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon late">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="late-today-value"><?php echo $total_late; ?></div>
                    <div class="summary-label">Late</div>
                </div>
            </div>
        </div>

        <!-- Filter Container -->
        <div class="filter-container">
            <div class="filter-header">
                <h2><i class="fas fa-calendar-day"></i> Today's Attendance - <?php echo date('M d, Y'); ?></h2>
            </div>
            <div class="filter-controls">
                <div class="filter-options">
                    <div class="filter-option">
                        <label for="employee_search">Search Employee:</label>
                        <div class="search-container">
                            <input type="text" id="employee_search" class="form-control" placeholder="Search by name, ID, or department...">
                            <i class="fas fa-search search-icon"></i>
                        </div>
                    </div>
                    <div class="filter-option">
                        <label for="filter_status">Status:</label>
                        <select name="status" id="filter_status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="halfday">Half Day</option>
                            <option value="early_in">Early In</option>
                            <option value="late">Late</option>
                            <option value="on_time">On Time</option>
                            <option value="on_leave">On Leave</option>
                        </select>
                    </div>
                    <div class="filter-option">
                        <label for="filter_attendance_type">Attendance Type:</label>
                        <select name="attendance_type" id="filter_attendance_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>
                    <div class="filter-option">
                        <label for="filter_shift">Shift:</label>
                        <select name="shift" id="filter_shift" class="form-control">
                            <option value="">All Shifts</option>
                            <option value="08:00-17:00">8:00 AM - 5:00 PM</option>
                            <option value="08:30-17:30">8:30 AM - 5:30 PM</option>
                            <option value="09:00-18:00">9:00 AM - 6:00 PM</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results Container -->
        <div class="results-container">
            <div class="results-header">
                <h2><i class="fas fa-list"></i> Attendance Records</h2>
                
            </div>
            <!-- Enhanced View Details Modal -->
            <div id="viewAttendanceModal" class="modal" style="display:none; align-items:center; justify-content:center;">
                <div class="modal-content enhanced-modal" style="max-width:900px; width:92%; max-height:90vh; border-radius:16px; overflow:hidden; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2); background: white; display:flex; flex-direction:column;">
                    <div class="modal-header enhanced-header" style="background: linear-gradient(135deg, #5DADE2 0%, #3498DB 100%); color:#fff; padding:20px 24px; position:relative; flex-shrink:0;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width:40px; height:40px; background:rgba(255,255,255,0.25); border-radius:8px; display:flex; align-items:center; justify-content:center;">
                                <i class="fas fa-id-card" style="font-size:18px;"></i>
                            </div>
                            <div>
                                <h2 style="margin:0; font-size:20px; font-weight:700;">Attendance Details</h2>
                                <p style="margin:2px 0 0 0; font-size:13px; opacity:0.95;">Employee attendance information</p>
                            </div>
                        </div>
                        <button class="close-btn" onclick="closeViewAttendanceModal()" style="position:absolute; top:18px; right:18px; background:rgba(255,255,255,0.2); border:none; color:#fff; font-size:22px; cursor:pointer; padding:4px; border-radius:50%; transition:all 0.3s; width:36px; height:36px; display:flex; align-items:center; justify-content:center; z-index:3;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body enhanced-body" style="padding:0; background:#f5f5f5; flex:1; overflow-y:auto;">
                        <div id="viewAttendanceBody" class="attendance-details" style="padding:20px; line-height:1.5; color:#333;">
                            <!-- Content will be populated by JavaScript -->
                        </div>
                    </div>
                    <div class="modal-footer enhanced-footer" style="padding:16px 24px; border-top:1px solid #e0e0e0; background:white; display:flex; justify-content:flex-end; gap:10px; flex-shrink:0;">
                        <button type="button" class="btn btn-secondary" onclick="closeViewAttendanceModal()" style="padding:10px 20px; border-radius:6px; font-weight:600; font-size:14px; background:#6c757d; color:white; border:none; cursor:pointer; transition:all 0.3s;">
                            Close
                        </button>
                    </div>
                </div>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Shift</th>
                            <th>Date</th>
                            <th>Time In - Time Out</th>
                            <th>Attendance Type</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($attendance_records)): ?>
                            <tr>
                                <td colspan="11" style="text-align:center;">
                                    No attendance records found for the selected criteria.<br>
                                    <small style="color: #666;">
                                        Check if: 
                                        (1) Employees exist, 
                                        (2) Attendance was marked for selected date, 
                                        (3) Filters are correctly set
                                    </small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($attendance_records as $record): 
                                // Duration estimate excluding lunch based on shift
                                $duration = 'In Progress';
                                if (!empty($record['time_in']) && !empty($record['time_out'])) {
                                    $ls = null; $le = null;
                                    switch ($record['Shift'] ?? '') {
                                        case '08:00-17:00': $ls='12:00:00'; $le='13:00:00'; break;
                                        case '08:30-17:30': $ls='12:30:00'; $le='13:30:00'; break;
                                        case '09:00-18:00': $ls='13:00:00'; $le='14:00:00'; break;
                                    }
                                    $dateY = date('Y-m-d', strtotime($record['attendance_date']));
                                    if ($ls && $le) {
                                        $start = strtotime($dateY.' '.$record['time_in']);
                                        $end = strtotime($dateY.' '.$record['time_out']);
                                        $lstart = strtotime($dateY.' '.$ls);
                                        $lend = strtotime($dateY.' '.$le);
                                        $worked = max(0, $end - $start);
                                        $overlap = max(0, min($end, $lend) - max($start, $lstart));
                                        $worked -= $overlap;
                                    } else {
                                        $worked = max(0, strtotime($record['time_out']) - strtotime($record['time_in']));
                                    }
                                    $h = floor($worked/3600); $m = floor(($worked%3600)/60);
                                    $duration = sprintf('%dh %02dm', $h, $m);
                                }
                                
                                // Format time in 12-hour format
                                $time_in_formatted = !empty($record['time_in']) ? date('g:i A', strtotime($record['time_in'])) : '-';
                                $time_out_formatted = !empty($record['time_out']) ? date('g:i A', strtotime($record['time_out'])) : '-';
                                
                                $source = (!empty($record['notes']) && strpos($record['notes'], 'Manual') !== false)
                                    ? '<i class="fas fa-edit" title="Manual Entry"></i>'
                                    : '<i class="fas fa-fingerprint" title="Biometric Device"></i>';
                                    
                                // Use final_attendance_type for display
                                $attendance_type = $record['final_attendance_type'] ?? 'present';
                            ?>
                                <tr class="clickable-row"
                                    data-employee_id="<?php echo htmlspecialchars($record['EmployeeID']); ?>"
                                    data-employee_name="<?php echo htmlspecialchars($record['EmployeeName']); ?>"
                                    data-department="<?php echo htmlspecialchars($record['Department']); ?>"
                                    data-shift="<?php echo htmlspecialchars($record['Shift'] ?? ''); ?>"
                                    data-date="<?php echo htmlspecialchars($record['attendance_date'] ?? $today); ?>"
                                    data-time_in="<?php echo htmlspecialchars($record['time_in'] ?? ''); ?>"
                                    data-time_out="<?php echo htmlspecialchars($record['time_out'] ?? ''); ?>"
                                    data-am_in="<?php echo htmlspecialchars($record['time_in_morning'] ?? ''); ?>"
                                    data-am_out="<?php echo htmlspecialchars($record['time_out_morning'] ?? ''); ?>"
                                    data-pm_in="<?php echo htmlspecialchars($record['time_in_afternoon'] ?? ''); ?>"
                                    data-pm_out="<?php echo htmlspecialchars($record['time_out_afternoon'] ?? ''); ?>"
                                    data-late="<?php echo htmlspecialchars($record['late_minutes'] ?? 0); ?>"
                                    data-early="<?php echo htmlspecialchars($record['early_out_minutes'] ?? 0); ?>"
                                    data-ot="<?php echo htmlspecialchars($record['overtime_hours'] ?? 0); ?>"
                                    data-attendance_type="<?php echo htmlspecialchars($record['attendance_type'] ?? ''); ?>"
                                    data-status="<?php echo htmlspecialchars($record['status'] ?? ''); ?>"
                                    data-source="<?php echo htmlspecialchars($record['data_source'] ?? 'biometric'); ?>"
                                    data-notes="<?php echo htmlspecialchars($record['notes'] ?? ''); ?>">
                                    <td><?php echo htmlspecialchars($record['EmployeeID']); ?></td>
                                    <td><?php echo htmlspecialchars($record['EmployeeName']); ?></td>
                                    <td><?php echo htmlspecialchars($record['Department']); ?></td>
                                    <td><?php 
                                        $shift = $record['Shift'] ?? '';
                                        if ($shift && $shift !== '-') {
                                            // Convert 24-hour format to 12-hour format
                                            $times = explode('-', $shift);
                                            if (count($times) === 2) {
                                                $start_time = trim($times[0]);
                                                $end_time = trim($times[1]);
                                                
                                                // Convert start time
                                                $start_12h = date('g:i A', strtotime($start_time));
                                                
                                                // Convert end time
                                                $end_12h = date('g:i A', strtotime($end_time));
                                                
                                                echo htmlspecialchars($start_12h . ' - ' . $end_12h);
                                            } else {
                                                echo htmlspecialchars($shift);
                                            }
                                        } else {
                                            echo '-';
                                        }
                                    ?></td>
                                    <td><?php echo htmlspecialchars(date('M d, Y', strtotime($record['attendance_date']))); ?></td>
                                    <td><?php echo $time_in_formatted; ?> - <?php echo $time_out_formatted; ?></td>
                                    <td><?php echo ucfirst(htmlspecialchars($attendance_type)); ?></td>
                                    <td>
                                        <?php if (($record['is_on_leave'] ?? 0) == 1): ?>
                                            <span class="status-badge status-on-leave">ON-LEAVE</span>
                                        <?php else: ?>
                                            <span class="status-badge status-<?php echo strtolower(htmlspecialchars($record['status'] ?? 'present')); ?>">
                                                <?php echo $record['status'] ? ucfirst(str_replace('_', ' ', htmlspecialchars($record['status']))) : ucfirst($attendance_type); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;"><?php echo $source; ?></td>
                                    <td><button class="btn btn-secondary" onclick="event.stopPropagation(); openViewAttendanceModal(this.closest('tr'))"><i class="fas fa-eye"></i> View</button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination Controls -->
            <div class="pagination-container">
                <div class="pagination-info">
                    <span id="paginationInfo">Showing 1-25 of <?php echo count($attendance_records); ?> employees</span>
                </div>
                <div class="pagination-controls">
                    <button id="prevBtn" class="pagination-btn" onclick="changePage(-1)">
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>
                    <div id="pageNumbers" class="page-numbers"></div>
                    <button id="nextBtn" class="pagination-btn" onclick="changePage(1)">
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                <div class="items-per-page">
                    <label for="itemsPerPage">Show:</label>
                    <select id="itemsPerPage" onchange="changeItemsPerPage()">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span>per page</span>
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
        
        // Profile Dropdown
        const profileDropdownButton = document.querySelector('.profile-dropdown .profile-btn');
        const profileDropdown = document.querySelector('.profile-dropdown');
        if (profileDropdownButton && profileDropdown) {
            profileDropdownButton.addEventListener('click', function(event) {
                profileDropdown.classList.toggle('active');
                event.stopPropagation();
            });
        }
        window.addEventListener('click', function(event) {
            if (profileDropdown && profileDropdown.classList.contains('active')) {
                if (!profileDropdown.contains(event.target)) {
                    profileDropdown.classList.remove('active');
                }
            }
        });

        // Pagination variables
        let currentPage = 1;
        let itemsPerPage = 25;
        let allRows = [];
        let filteredRows = [];

        // Initialize pagination
        function initializePagination() {
            allRows = Array.from(document.querySelectorAll('tbody tr')).filter(row => row.querySelector('td'));
            filteredRows = [...allRows];
            updateTable();
            updatePagination();
        }

        // Update table display with pagination
        function updateTable() {
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const pageRows = filteredRows.slice(startIndex, endIndex);

            // Hide all rows first
            allRows.forEach(row => row.style.display = 'none');
            
            // Show only current page rows
            pageRows.forEach(row => row.style.display = '');
            
            updatePaginationInfo();
        }

        // Update pagination controls
        function updatePagination() {
            const totalPages = Math.ceil(filteredRows.length / itemsPerPage);
            const pageNumbers = document.getElementById('pageNumbers');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');

            // Update prev/next buttons
            prevBtn.disabled = currentPage === 1;
            nextBtn.disabled = currentPage === totalPages || totalPages === 0;

            // Clear and rebuild page numbers
            pageNumbers.innerHTML = '';

            if (totalPages <= 7) {
                // Show all pages if 7 or fewer
                for (let i = 1; i <= totalPages; i++) {
                    const pageBtn = document.createElement('button');
                    pageBtn.className = `page-number ${i === currentPage ? 'active' : ''}`;
                    pageBtn.textContent = i;
                    pageBtn.onclick = () => goToPage(i);
                    pageNumbers.appendChild(pageBtn);
                }
            } else {
                // Show first, last, current, and surrounding pages
                const pages = [1];
                
                if (currentPage > 3) pages.push('...');
                
                const start = Math.max(2, currentPage - 1);
                const end = Math.min(totalPages - 1, currentPage + 1);
                
                for (let i = start; i <= end; i++) {
                    if (!pages.includes(i)) pages.push(i);
                }
                
                if (currentPage < totalPages - 2) pages.push('...');
                if (totalPages > 1) pages.push(totalPages);

                pages.forEach(page => {
                    if (page === '...') {
                        const ellipsis = document.createElement('span');
                        ellipsis.textContent = '...';
                        ellipsis.style.padding = '8px 5px';
                        ellipsis.style.color = '#666';
                        pageNumbers.appendChild(ellipsis);
                    } else {
                        const pageBtn = document.createElement('button');
                        pageBtn.className = `page-number ${page === currentPage ? 'active' : ''}`;
                        pageBtn.textContent = page;
                        pageBtn.onclick = () => goToPage(page);
                        pageNumbers.appendChild(pageBtn);
                    }
                });
            }
        }

        // Update pagination info
        function updatePaginationInfo() {
            const startIndex = (currentPage - 1) * itemsPerPage + 1;
            const endIndex = Math.min(currentPage * itemsPerPage, filteredRows.length);
            const total = filteredRows.length;
            
            document.getElementById('paginationInfo').textContent = 
                `Showing ${total > 0 ? startIndex : 0}-${endIndex} of ${total} employees`;
        }

        // Go to specific page
        function goToPage(page) {
            currentPage = page;
            updateTable();
            updatePagination();
        }

        // Change page (prev/next)
        function changePage(direction) {
            const totalPages = Math.ceil(filteredRows.length / itemsPerPage);
            const newPage = currentPage + direction;
            
            if (newPage >= 1 && newPage <= totalPages) {
                currentPage = newPage;
                updateTable();
                updatePagination();
            }
        }

        // Change items per page
        function changeItemsPerPage() {
            const select = document.getElementById('itemsPerPage');
            itemsPerPage = parseInt(select.value);
            currentPage = 1;
            updateTable();
            updatePagination();
        }

        // AJAX filter functionality
        let filterTimeout;
        let isLoading = false;

        function showLoadingState() {
            if (isLoading) return;
            isLoading = true;
            
            const tableBody = document.querySelector('tbody');
            const loadingRow = document.createElement('tr');
            loadingRow.innerHTML = `
                <td colspan="11" style="text-align: center; padding: 40px;">
                    <div style="display: flex; align-items: center; justify-content: center; gap: 10px;">
                        <i class="fas fa-spinner fa-spin" style="color: #3F72AF; font-size: 18px;"></i>
                        <span style="color: #3F72AF; font-weight: 500;">Loading attendance data...</span>
                    </div>
                </td>
            `;
            tableBody.innerHTML = '';
            tableBody.appendChild(loadingRow);
        }

        function hideLoadingState() {
            isLoading = false;
        }

        function updateSummaryCards(stats) {
            const presentElement = document.getElementById('present-today-value');
            const rateElement = document.getElementById('attendance-rate-value');
            const absentElement = document.getElementById('absent-today-value');
            const lateElement = document.getElementById('late-today-value');

            if (presentElement) presentElement.textContent = stats.total_present;
            if (rateElement) rateElement.textContent = stats.attendance_rate + '%';
            if (absentElement) absentElement.textContent = stats.total_absent;
            if (lateElement) lateElement.textContent = stats.total_late;
        }

        function filterTableWithAJAX() {
            // Clear previous timeout
            if (filterTimeout) {
                clearTimeout(filterTimeout);
            }

            // Show loading state
            showLoadingState();

            // Debounce the AJAX call
            filterTimeout = setTimeout(() => {
                const searchTerm = document.getElementById('employee_search').value;
                const statusFilter = document.getElementById('filter_status').value;
                const attendanceTypeFilter = document.getElementById('filter_attendance_type').value;
                const shiftFilter = document.getElementById('filter_shift').value;

                // Build query parameters
                const params = new URLSearchParams({
                    search: searchTerm,
                    status: statusFilter,
                    attendance_type: attendanceTypeFilter,
                    shift: shiftFilter,
                    filter_mode: 'today'
                });

                // Make AJAX request
                fetch(`ajax_filter_attendance.php?${params.toString()}`, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    hideLoadingState();
                    
                    if (data.success) {
                        // Update table with new data
                        updateTableWithData(data.data.attendance_records);
                        
                        // Update summary cards
                        updateSummaryCards(data.data.stats);
                        
                        // Update pagination
            currentPage = 1;
            updatePagination();
                        
                        console.log('Attendance data updated successfully');
                    } else {
                        console.error('Error filtering data:', data.error);
                        showErrorMessage('Error loading attendance data');
                    }
                })
                .catch(error => {
                    hideLoadingState();
                    console.error('AJAX error:', error);
                    showErrorMessage('Network error while loading data. Please check your connection and try again.');
                });
            }, 300); // 300ms debounce
        }

        function updateTableWithData(records) {
            const tableBody = document.querySelector('tbody');
            
            if (records.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="11" style="text-align:center;">
                            No attendance records found for the selected criteria.<br>
                            <small style="color: #666;">
                                Check if: 
                                (1) Employees exist, 
                                (2) Attendance was marked for selected date, 
                                (3) Filters are correctly set
                            </small>
                        </td>
                    </tr>
                `;
                return;
            }

            let tableHTML = '';
            records.forEach((record, index) => {
                // Duration estimate excluding lunch based on shift
                let duration = 'In Progress';
                if (record.time_in && record.time_out) {
                    let ls = null, le = null;
                    switch (record.Shift || '') {
                        case '08:00-17:00': ls='12:00:00'; le='13:00:00'; break;
                        case '08:30-17:30': ls='12:30:00'; le='13:30:00'; break;
                        case '09:00-18:00': ls='13:00:00'; le='14:00:00'; break;
                    }
                    const dateY = record.attendance_date;
                    if (ls && le) {
                        const start = new Date(dateY + ' ' + record.time_in).getTime();
                        const end = new Date(dateY + ' ' + record.time_out).getTime();
                        const lstart = new Date(dateY + ' ' + ls).getTime();
                        const lend = new Date(dateY + ' ' + le).getTime();
                        let worked = Math.max(0, end - start);
                        const overlap = Math.max(0, Math.min(end, lend) - Math.max(start, lstart));
                        worked -= overlap;
                        const h = Math.floor(worked/3600000);
                        const m = Math.floor((worked%3600000)/60000);
                        duration = `${h}h ${m.toString().padStart(2, '0')}m`;
                    } else {
                        const worked = Math.max(0, new Date(record.time_out).getTime() - new Date(record.time_in).getTime());
                        const h = Math.floor(worked/3600000);
                        const m = Math.floor((worked%3600000)/60000);
                        duration = `${h}h ${m.toString().padStart(2, '0')}m`;
                    }
                }
                
                // Format time in 12-hour format
                const timeInFormatted = record.time_in ? new Date('1970-01-01T' + record.time_in).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true}) : '-';
                const timeOutFormatted = record.time_out ? new Date('1970-01-01T' + record.time_out).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true}) : '-';
                
                const source = (record.notes && record.notes.includes('Manual'))
                    ? '<i class="fas fa-edit" title="Manual Entry"></i>'
                    : '<i class="fas fa-fingerprint" title="Biometric Device"></i>';
                    
                const attendanceType = record.final_attendance_type || 'present';
                
                // Format shift to AM/PM
                let shiftFormatted = '-';
                if (record.Shift && record.Shift !== '-') {
                    const times = record.Shift.split('-');
                    if (times.length === 2) {
                        const startTime = times[0].trim();
                        const endTime = times[1].trim();
                        const start12h = new Date('1970-01-01T' + startTime).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true});
                        const end12h = new Date('1970-01-01T' + endTime).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true});
                        shiftFormatted = start12h + ' - ' + end12h;
                    } else {
                        shiftFormatted = record.Shift;
                    }
                }

                const statusClass = record.is_on_leave == 1 ? 'on-leave' : (record.status ? record.status.toLowerCase() : attendanceType.toLowerCase());
                const statusText = record.is_on_leave == 1 ? 'ON-LEAVE' : (record.status ? record.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) : attendanceType.charAt(0).toUpperCase() + attendanceType.slice(1));

                tableHTML += `
                    <tr class="clickable-row"
                        data-employee_id="${record.EmployeeID}"
                        data-employee_name="${record.EmployeeName}"
                        data-department="${record.Department}"
                        data-shift="${record.Shift || ''}"
                        data-date="${record.attendance_date || new Date().toISOString().split('T')[0]}"
                        data-time_in="${record.time_in || ''}"
                        data-time_out="${record.time_out || ''}"
                        data-am_in="${record.time_in_morning || ''}"
                        data-am_out="${record.time_out_morning || ''}"
                        data-pm_in="${record.time_in_afternoon || ''}"
                        data-pm_out="${record.time_out_afternoon || ''}"
                        data-late="${record.late_minutes || 0}"
                        data-early="${record.early_out_minutes || 0}"
                        data-ot="${record.overtime_hours || 0}"
                        data-attendance_type="${record.attendance_type || ''}"
                        data-status="${record.status || ''}"
                        data-source="${record.data_source || 'biometric'}"
                        data-notes="${record.notes || ''}">
                        <td>${record.EmployeeID}</td>
                        <td>${record.EmployeeName}</td>
                        <td>${record.Department}</td>
                        <td>${shiftFormatted}</td>
                        <td>${new Date(record.attendance_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'})}</td>
                        <td>${timeInFormatted} - ${timeOutFormatted}</td>
                        <td>${attendanceType.charAt(0).toUpperCase() + attendanceType.slice(1)}</td>
                        <td>
                            <span class="status-badge status-${statusClass}">
                                ${statusText}
                            </span>
                        </td>
                        <td style="text-align:center;">${source}</td>
                        <td><button class="btn btn-secondary view-details-btn" data-record-index="${index}"><i class="fas fa-eye"></i> View</button></td>
                    </tr>
                `;
            });
            
            tableBody.innerHTML = tableHTML;
            
            // Add event listeners for view buttons
            document.querySelectorAll('.view-details-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const recordIndex = parseInt(this.getAttribute('data-record-index'));
                    const record = records[recordIndex];
                    if (record) {
                        const row = this.closest('tr');
                        openViewAttendanceModal(row);
                    }
                });
            });
            
            // Re-initialize pagination with new data
            allRows = Array.from(document.querySelectorAll('tbody tr')).filter(row => row.querySelector('td'));
            filteredRows = [...allRows];
            updateTable();
        }

        function showErrorMessage(message) {
            const tableBody = document.querySelector('tbody');
            tableBody.innerHTML = `
                <tr>
                    <td colspan="11" style="text-align:center; color: #e74c3c; padding: 40px;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i><br>
                        ${message}
                    </td>
                </tr>
            `;
        }

        // Add event listeners for AJAX filtering
        document.getElementById('employee_search').addEventListener('input', filterTableWithAJAX);
        document.getElementById('filter_status').addEventListener('change', filterTableWithAJAX);
        document.getElementById('filter_attendance_type').addEventListener('change', filterTableWithAJAX);
        document.getElementById('filter_shift').addEventListener('change', filterTableWithAJAX);

        // Enhanced View modal helpers
        function openViewAttendanceModal(rowEl) {
            const body = document.getElementById('viewAttendanceBody');
            const fmt = (t)=> t ? new Date('1970-01-01T' + t).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true}) : '-';
            const name = rowEl.getAttribute('data-employee_name') || '-';
            const id = rowEl.getAttribute('data-employee_id') || '-';
            const dept = rowEl.getAttribute('data-department') || '-';
            const shift = rowEl.getAttribute('data-shift') || '-';
            const date = rowEl.getAttribute('data-date') || '-';
            const rawAmIn = rowEl.getAttribute('data-am_in');
            const rawAmOut = rowEl.getAttribute('data-am_out');
            const rawPmIn = rowEl.getAttribute('data-pm_in');
            const rawPmOut = rowEl.getAttribute('data-pm_out');
            const amIn = fmt(rawAmIn);
            const amOut = fmt(rawAmOut);
            const pmIn = fmt(rawPmIn);
            const pmOut = fmt(rawPmOut);
            const status = rowEl.getAttribute('data-status') || '-';
            const type = rowEl.getAttribute('data-attendance_type') || '-';
            const late = rowEl.getAttribute('data-late') || '0';
            const early = rowEl.getAttribute('data-early') || '0';
            const ot = rowEl.getAttribute('data-ot') || '0';
            const notes = rowEl.getAttribute('data-notes') || '-';
            const dataSource = rowEl.getAttribute('data-source') || 'biometric';
            const isHalfDay = (rowEl.getAttribute('data-status') || '') === 'half_day';
            const hasAmPairOnly = rawAmIn && rawAmOut && !rawPmIn && !rawPmOut;
            const hasPmPairOnly = rawPmIn && rawPmOut && !rawAmIn && !rawAmOut;
            
            // Helper functions for source display
            const getSourceIcon = (source) => {
                switch(source) {
                    case 'manual':
                        return '<i class="fas fa-edit" style="color:#3F72AF;"></i>';
                    case 'bulk_edit':
                        return '<i class="fas fa-users-cog" style="color:#FFC75F;"></i>';
                    case 'biometric':
                    default:
                        return '<i class="fas fa-fingerprint" style="color:#16C79A;"></i>';
                }
            };
            
            const getSourceText = (source) => {
                switch(source) {
                    case 'manual':
                        return 'Manual Entry (Web App)';
                    case 'bulk_edit':
                        return 'Bulk Edit (Web App)';
                    case 'biometric':
                    default:
                        return 'Biometric Scanner (ZKteco Device)';
                }
            };
            
            // Format shift to AM/PM
            const formatShift = (shiftStr) => {
                if (!shiftStr || shiftStr === '-') return '-';
                try {
                    const parts = shiftStr.split('-');
                    if (parts.length === 2) {
                        const start = new Date('1970-01-01T' + parts[0].trim()).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true});
                        const end = new Date('1970-01-01T' + parts[1].trim()).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true});
                        return `${start} - ${end}`;
                    }
                    return shiftStr;
                } catch (e) {
                    return shiftStr;
                }
            };
            
            let sessionHtml = '';
            if (isHalfDay && hasPmPairOnly) {
                sessionHtml = `
                    <div style="background: #E8F5E8; padding: 20px; border-radius: 12px; border-left: 4px solid #16C79A; margin-bottom: 20px;">
                        <h4 style="color: #16C79A; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-moon"></i> Afternoon Session (Half Day)
                        </h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">PM In (Lunch End)</div>
                                <div style="font-weight: 600; color: #333; font-size: 16px;">${pmIn}</div>
                            </div>
                            <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">PM Out</div>
                                <div style="font-weight: 600; color: #333; font-size: 16px;">${pmOut}</div>
                            </div>
                        </div>
                    </div>`;
            } else if (isHalfDay && hasAmPairOnly) {
                sessionHtml = `
                    <div style="background: #FFF8E1; padding: 20px; border-radius: 12px; border-left: 4px solid #FFC75F; margin-bottom: 20px;">
                        <h4 style="color: #F57C00; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-sun"></i> Morning Session (Half Day)
                        </h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">AM In</div>
                                <div style="font-weight: 600; color: #333; font-size: 16px;">${amIn}</div>
                            </div>
                            <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">AM Out (Lunch Start)</div>
                                <div style="font-weight: 600; color: #333; font-size: 16px;">${amOut}</div>
                            </div>
                        </div>
                    </div>`;
            } else {
                sessionHtml = `
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div style="background: #FFF8E1; padding: 20px; border-radius: 12px; border-left: 4px solid #FFC75F;">
                            <h4 style="color: #F57C00; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-sun"></i> Morning Session
                            </h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                    <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Morning Time In</div>
                                    <div style="font-weight: 600; color: #333; font-size: 16px;">${amIn}</div>
                                </div>
                                <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                    <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Morning Time Out</div>
                                    <div style="font-weight: 600; color: #333; font-size: 16px;">${amOut}</div>
                                </div>
                            </div>
                        </div>
                        <div style="background: #E8F5E8; padding: 20px; border-radius: 12px; border-left: 4px solid #16C79A;">
                            <h4 style="color: #16C79A; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-moon"></i> Afternoon Session
                            </h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                    <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Afternoon Time In</div>
                                    <div style="font-weight: 600; color: #333; font-size: 16px;">${pmIn}</div>
                                </div>
                                <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                    <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Afternoon Time Out</div>
                                    <div style="font-weight: 600; color: #333; font-size: 16px;">${pmOut}</div>
                                </div>
                            </div>
                        </div>
                    </div>`;
            }
            
            body.innerHTML = `
                <!-- Employee Information Section -->
                <div style="background: #EBF5FB; border: 3px solid #5DADE2; border-radius: 12px; padding: 20px; margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; border-bottom: 2px solid #5DADE2; padding-bottom: 10px;">
                        <i class="fas fa-user" style="color: #3498DB; font-size: 18px;"></i>
                        <h3 style="margin: 0; color: #2874A6; font-size: 16px; font-weight: 700;">Employee Information</h3>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Employee Name</div>
                            <div style="font-weight: 600; color: #2C3E50; font-size: 15px;">${name}</div>
                        </div>
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Employee ID</div>
                            <div style="font-weight: 600; color: #2C3E50; font-size: 15px;">${id}</div>
                        </div>
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Department</div>
                            <div style="font-weight: 600; color: #2C3E50; font-size: 15px;">${dept}</div>
                        </div>
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Shift</div>
                            <div style="font-weight: 600; color: #2C3E50; font-size: 15px;">${formatShift(shift)}</div>
                        </div>
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Date</div>
                            <div style="font-weight: 600; color: #2C3E50; font-size: 15px;">${new Date(date).toLocaleDateString('en-US', {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'})}</div>
                        </div>
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Data Source</div>
                            <div style="font-weight: 600; color: #2C3E50; font-size: 13px; display: flex; align-items: center; gap: 6px;">${getSourceIcon(dataSource)} <span style="font-size: 12px;">${getSourceText(dataSource)}</span></div>
                        </div>
                    </div>
                </div>
                
                <!-- Morning and Afternoon Sessions -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <!-- Morning Session -->
                    <div style="background: #FEF9E7; border: 3px solid #F7DC6F; border-radius: 12px; padding: 20px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px; border-bottom: 2px solid #F7DC6F; padding-bottom: 8px;">
                            <i class="fas fa-sun" style="color: #F39C12; font-size: 16px;"></i>
                            <h3 style="margin: 0; color: #D68910; font-size: 15px; font-weight: 700;">Morning Session</h3>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div style="background: white; padding: 12px; border-radius: 8px; border: 1px solid #FCF3CF;">
                                <div style="font-size: 10px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">AM In</div>
                                <div style="font-weight: 700; color: #D68910; font-size: 20px; font-family: 'Segoe UI', Tahoma, sans-serif;">${amIn}</div>
                            </div>
                            <div style="background: white; padding: 12px; border-radius: 8px; border: 1px solid #FCF3CF;">
                                <div style="font-size: 10px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">AM Out (Lunch Start)</div>
                                <div style="font-weight: 700; color: #D68910; font-size: 20px; font-family: 'Segoe UI', Tahoma, sans-serif;">${amOut}</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Afternoon Session -->
                    <div style="background: #E8F8F5; border: 3px solid #73C6B6; border-radius: 12px; padding: 20px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px; border-bottom: 2px solid #73C6B6; padding-bottom: 8px;">
                            <i class="fas fa-moon" style="color: #16A085; font-size: 16px;"></i>
                            <h3 style="margin: 0; color: #148F77; font-size: 15px; font-weight: 700;">Afternoon Session</h3>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div style="background: white; padding: 12px; border-radius: 8px; border: 1px solid #D1F2EB;">
                                <div style="font-size: 10px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">PM In (Lunch End)</div>
                                <div style="font-weight: 700; color: #148F77; font-size: 20px; font-family: 'Segoe UI', Tahoma, sans-serif;">${pmIn}</div>
                            </div>
                            <div style="background: white; padding: 12px; border-radius: 8px; border: 1px solid #D1F2EB;">
                                <div style="font-size: 10px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">PM Out</div>
                                <div style="font-weight: 700; color: #148F77; font-size: 20px; font-family: 'Segoe UI', Tahoma, sans-serif;">${pmOut}</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Status & Metrics -->
                <div style="background: #EBF5FB; border: 3px solid #5DADE2; border-radius: 12px; padding: 20px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; border-bottom: 2px solid #5DADE2; padding-bottom: 10px;">
                        <i class="fas fa-chart-line" style="color: #3498DB; font-size: 18px;"></i>
                        <h3 style="margin: 0; color: #2874A6; font-size: 16px; font-weight: 700;">Status & Metrics</h3>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Attendance Type</div>
                            <div style="font-weight: 700; color: #2C3E50; font-size: 15px;">${type.charAt(0).toUpperCase() + type.slice(1)}</div>
                        </div>
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Status</div>
                            <div style="font-weight: 700; color: #2C3E50; font-size: 15px;">${status === 'half_day' ? 'Half Day' : (status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' '))}</div>
                        </div>
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Late (minutes)</div>
                            <div style="font-weight: 700; color: ${late == 0 ? '#16A085' : '#E74C3C'}; font-size: 15px;">${(isHalfDay && hasPmPairOnly) ? '-' : (isHalfDay ? '-' : late)}</div>
                        </div>
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Early Out (minutes)</div>
                            <div style="font-weight: 700; color: ${early == 0 ? '#16A085' : '#F39C12'}; font-size: 15px;">${(isHalfDay && hasAmPairOnly) ? '-' : (isHalfDay ? '-' : early)}</div>
                        </div>
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Overtime (hours)</div>
                            <div style="font-weight: 700; color: ${ot > 0 ? '#16A085' : '#7B7D7D'}; font-size: 15px;">${parseFloat(ot).toFixed(2)}</div>
                        </div>
                        <div style="background: white; padding: 14px; border-radius: 8px; border: 1px solid #D6EAF8;">
                            <div style="font-size: 11px; color: #7B7D7D; margin-bottom: 4px; font-weight: 500;">Notes</div>
                            <div style="font-weight: 600; color: #2C3E50; font-size: 13px; max-height: 40px; overflow-y: auto;" title="${notes}">${notes}</div>
                        </div>
                    </div>
                </div>
            `;
            const modal = document.getElementById('viewAttendanceModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeViewAttendanceModal() {
            const modal = document.getElementById('viewAttendanceModal');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        
        // Close on overlay click
        document.getElementById('viewAttendanceModal').addEventListener('click', function(e){
            if (e.target === this) closeViewAttendanceModal();
        });

        // Row click
        document.addEventListener('click', function(e) {
            const tr = e.target.closest('tr.clickable-row');
            if (tr && !e.target.closest('button')) {
                openViewAttendanceModal(tr);
            }
        });

        // Initialize pagination when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize with current data (server-side rendered)
            initializePagination();
            startAutoRefresh();
            
            // Set up AJAX filtering as the primary method
            // The initial data is already loaded from server-side PHP
        });

        // Auto-refresh functionality to keep data up-to-date
        let autoRefreshInterval = null;
        let isRefreshing = false;

        function startAutoRefresh() {
            // Refresh every 30 seconds
            autoRefreshInterval = setInterval(function() {
                refreshAttendanceData();
            }, 30000); // 30 seconds
        }

        function stopAutoRefresh() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
        }

        function refreshAttendanceData() {
            if (isRefreshing) return; // Prevent multiple simultaneous refreshes
            
            isRefreshing = true;
            
            // Get current filter values
            const searchTerm = document.getElementById('employee_search').value;
            const statusFilter = document.getElementById('filter_status').value;
            const attendanceTypeFilter = document.getElementById('filter_attendance_type').value;
            const shiftFilter = document.getElementById('filter_shift').value;

            // Build query parameters for refresh
            const params = new URLSearchParams({
                search: searchTerm,
                status: statusFilter,
                attendance_type: attendanceTypeFilter,
                shift: shiftFilter,
                filter_mode: 'today'
            });

            // Use AJAX to refresh data
            fetch(`ajax_filter_attendance.php?${params.toString()}`, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update table with fresh data
                    updateTableWithData(data.data.attendance_records);
                    
                    // Update summary cards
                    updateSummaryCards(data.data.stats);
                    
                    // Re-initialize pagination with new data
                    initializePagination();
                    
                    console.log('Attendance data refreshed at ' + new Date().toLocaleTimeString());
                } else {
                    console.error('Error refreshing data:', data.error);
                }
                
                isRefreshing = false;
            })
            .catch(error => {
                console.error('Error refreshing attendance data:', error);
                isRefreshing = false;
            });
        }


        // Manual refresh button (optional - you can add this to the UI if desired)
        function manualRefresh() {
            stopAutoRefresh();
            refreshAttendanceData();
            startAutoRefresh();
        }

        // Stop auto-refresh when page is hidden (to save resources)
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
            }
        });

        // Add visual indicator for last refresh time
        function addRefreshIndicator() {
            const header = document.querySelector('.filter-header h2');
            if (header && !document.getElementById('last-refresh-indicator')) {
                const indicator = document.createElement('span');
                indicator.id = 'last-refresh-indicator';
                indicator.style.cssText = 'font-size: 12px; color: #6c757d; font-weight: 400; margin-left: 10px;';
                indicator.innerHTML = '<i class="fas fa-sync-alt"></i> Auto-updating';
                header.appendChild(indicator);
            }
        }

        // Initialize refresh indicator
        document.addEventListener('DOMContentLoaded', function() {
            addRefreshIndicator();
        });
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