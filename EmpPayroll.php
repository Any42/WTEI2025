<?php
session_start();
if (!isset($_SESSION['employee_id'])) {
    header("Location: Login.php");
    exit();
}

// Include centralized payroll computations
require_once 'payroll_computations.php';
// Include attendance calculations
require_once 'attendance_calculations.php';

$conn = new mysqli("localhost", "root", "", "wteimain1");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$employee_id = $_SESSION['employee_id'];
$current_month = date('Y-m');

// Get employee details
$stmt = $conn->prepare("SELECT * FROM empuser WHERE EmployeeID = ?");
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();

// Get payroll history
$stmt = $conn->prepare("SELECT * FROM payroll WHERE EmployeeID = ? ORDER BY payment_date DESC");
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$payroll_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate current month payroll using payroll_computations.php
$current_payroll = calculatePayroll($employee_id, $employee['base_salary'], $current_month, $conn);

// Get attendance summary for current month
$attendance_summary = getEmployeeAttendanceSummary($employee_id, $current_month, $conn);

// Additional deductions are now handled by payroll_computations.php
// No need to query deductions table as it has been removed
$additional_deductions = 0;

// Calculate working days in current month
$working_days = calculateWorkingDaysInMonth($current_month);
$monthly_rate = calculateMonthlyRate($employee['base_salary'], $working_days);

// Final net pay calculation (excluding admin fee)
$final_net_pay = $current_payroll['net_pay'] - $additional_deductions + $current_payroll['admin_fee'];

// Get detailed attendance records for current month with employee shift information
$attendance_query = "SELECT a.*, e.Shift FROM attendance a 
                    JOIN empuser e ON a.EmployeeID = e.EmployeeID
                    WHERE a.EmployeeID = ? 
                    AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ? 
                    ORDER BY a.attendance_date DESC";
$stmt = $conn->prepare($attendance_query);
$stmt->bind_param("ss", $employee_id, $current_month);
$stmt->execute();
$attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get accurate count of days worked (status = 'present' OR attendance_type = 'present') directly from database
$days_worked_query = "SELECT COUNT(*) as days_worked FROM attendance a
                      JOIN empuser e ON a.EmployeeID = e.EmployeeID
                      WHERE a.EmployeeID = ? 
                      AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ? 
                      AND (a.status = 'present' OR a.attendance_type = 'present')";
$stmt = $conn->prepare($days_worked_query);
$stmt->bind_param("ss", $employee_id, $current_month);
$stmt->execute();
$days_worked_result = $stmt->get_result()->fetch_assoc();
$days_worked_count = $days_worked_result['days_worked'];



// Apply attendance calculations to get accurate metrics using employee's shift
// The Shift field from empuser table is now included in attendance records
$attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);

// Validate and fix overtime data if needed
foreach ($attendance_records as &$record) {
    // Ensure overtime_hours is properly set
    if (!isset($record['overtime_hours']) || $record['overtime_hours'] === null) {
        $record['overtime_hours'] = 0.00;
    }
    
    // Ensure is_overtime flag is properly set
    if (!isset($record['is_overtime']) || $record['is_overtime'] === null) {
        $record['is_overtime'] = ($record['overtime_hours'] > 0) ? 1 : 0;
    }
    
    // Ensure total_hours is properly set
    if (!isset($record['total_hours']) || $record['total_hours'] === null) {
        $record['total_hours'] = 0.00;
    }
}

// Calculate working days from 1st to present day (excluding Sundays)
$year = date('Y', strtotime($current_month . '-01'));
$month = date('m', strtotime($current_month . '-01'));
$current_day = date('d'); // Current day of the month
$working_days_in_month = 0;

for ($day = 1; $day <= $current_day; $day++) {
    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
    $day_of_week = date('w', strtotime($date)); // 0 = Sunday, 1 = Monday, etc.
    if ($day_of_week != 0) { // Not Sunday
        $working_days_in_month++;
    }
}

// Calculate attendance statistics
$total_attendance_days = count($attendance_records);
$present_days = 0;
$absent_days = 0;
$late_days = 0;
$early_days = 0;
$half_days = 0;
$overtime_days = 0;
$total_overtime_hours = 0;
$total_work_hours = 0;
$total_late_minutes = 0;
$total_early_out_minutes = 0;

// Get overtime hours breakdown using 10pm cutoff logic
$otHoursBreakdown = calculateOvertimeHoursBreakdown($employee_id, $current_month, $conn);
$total_overtime_hours = $otHoursBreakdown['regular_ot_hours']; // Only regular OT hours (before 10pm)

foreach ($attendance_records as $record) {
    // Count days worked based on status = 'present' OR attendance_type = 'present'
    if ($record['status'] === 'present' || $record['attendance_type'] === 'present') {
        $present_days++;
    }
    
    if ($record['status'] === 'late') {
        $late_days++;
    }
    
    if ($record['status'] === 'early') {
        $early_days++;
    }
    
    if ($record['status'] === 'halfday') {
        $half_days++;
    }
    
    if ($record['is_overtime'] == 1 && isset($record['overtime_hours']) && $record['overtime_hours'] > 0) {
        $overtime_days++;
        // Note: total_overtime_hours is now calculated using 10pm cutoff logic above
    }
    
    $total_work_hours += $record['total_hours'];
    $total_late_minutes += $record['late_minutes'];
    $total_early_out_minutes += $record['early_out_minutes'];
}

// Get accurate count of half days directly from database
$halfday_count_query = "SELECT COUNT(*) as halfday_count FROM attendance a
                       JOIN empuser e ON a.EmployeeID = e.EmployeeID
                       WHERE a.EmployeeID = ? 
                       AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ? 
                       AND a.status = 'halfday'";
$stmt = $conn->prepare($halfday_count_query);
$stmt->bind_param("ss", $employee_id, $current_month);
$stmt->execute();
$halfday_count_result = $stmt->get_result()->fetch_assoc();
$halfday_count = $halfday_count_result['halfday_count'];

// Use database count if available, otherwise use PHP calculated count
if ($halfday_count > 0) {
    $half_days = $halfday_count;
}

// Calculate absent days: working days in month - present days
$absent_days = max(0, $working_days_in_month - $present_days);

// Get recent attendance records (last 7 days)
$recent_attendance = array_slice($attendance_records, 0, 7);

// Fallback: If database count is 0, use PHP calculated count
if ($days_worked_count == 0 && $present_days > 0) {
    $days_worked_count = $present_days;
}

$conn->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll - WTEI</title>
    <link rel="stylesheet" href="css/employee-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Enhanced Payroll Page Styling */
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
            --white: #ffffff;
            --text-dark: #2c3e50;
            --text-light: #6c757d;
            --shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            --shadow-hover: 0 8px 25px rgba(0, 0, 0, 0.15);
            --gradient-primary: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            --gradient-success: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            --gradient-warning: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            --gradient-danger: linear-gradient(135deg, #dc3545 0%, #e74c3c 100%);
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
            width: 280px;
            background: linear-gradient(180deg, var(--primary-color) 0%, #6BC4E4 100%);
            padding: 20px 0;
            box-shadow: 4px 0 10px var(--shadow-color);
            display: flex;
            flex-direction: column;
            color: var(--secondary-color);
            position: fixed;
            height: 100vh;
            transition: all 0.3s ease;
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
            background-color: var(--secondary-color);
            color: white;
            transform: translateX(5px);
        }

        .menu-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        .logout-btn {
            background-color: var(--secondary-color);
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
            background-color: #1a252f;
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

        /* Enhanced Summary Cards */
        .payroll-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
            perspective: 1000px;
        }

        .summary-card {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--shadow);
            border: 2px solid transparent;
            background-clip: padding-box;
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: cardFloat 6s ease-in-out infinite;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--gradient-primary);
            border-radius: 18px;
            padding: 2px;
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask-composite: exclude;
            -webkit-mask-composite: xor;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .summary-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: var(--shadow-hover);
        }

        .summary-card:hover::before {
            opacity: 1;
        }

        .summary-card.earnings {
            background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
        }

        .summary-card.deductions {
            background: linear-gradient(145deg, #ffffff 0%, #fff5f5 100%);
        }

        .summary-card.net-pay {
            background: linear-gradient(145deg, #ffffff 0%, #f0fff4 100%);
        }

        .summary-card.attendance {
            background: linear-gradient(145deg, #ffffff 0%, #f0f8ff 100%);
        }

        @keyframes cardFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-5px); }
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }

        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: var(--white);
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .card-icon.earnings {
            background: var(--gradient-success);
        }

        .card-icon.deductions {
            background: var(--gradient-danger);
        }

        .card-icon.net-pay {
            background: var(--gradient-primary);
        }

        .card-icon.attendance {
            background: var(--gradient-warning);
        }

        .summary-card:hover .card-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .card-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-dark);
            margin: 15px 0 10px 0;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .card-subtitle {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
            margin-bottom: 15px;
        }

        .card-details {
            background: rgba(63, 114, 175, 0.05);
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(63, 114, 175, 0.1);
            font-size: 13px;
        }

        .detail-row:last-child {
            border-bottom: none;
            font-weight: 700;
            color: var(--text-dark);
        }

        .detail-label {
            color: var(--text-light);
            font-weight: 500;
        }

        .detail-value {
            color: var(--text-dark);
            font-weight: 600;
        }

        /* Current Month Display */
        .current-month-banner {
            background: var(--gradient-primary);
            color: var(--white);
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .current-month-banner::before {
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

        @keyframes float {
            0%, 100% { transform: translateY(0) translateX(0); }
            50% { transform: translateY(-20px) translateX(10px); }
        }

        .month-info {
            position: relative;
            z-index: 1;
        }

        .month-info h2 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 10px 0;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .month-info p {
            font-size: 16px;
            margin: 0;
            opacity: 0.9;
        }

        /* Enhanced Payslip Modal */
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
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .payslip-content {
            position: relative;
            background: var(--white);
            margin: auto;
            width: 100%;
            max-width: 1200px;
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
            color: var(--white);
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
            border-color: var(--white);
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

        .payslip-header {
            background: var(--gradient-primary);
            color: var(--white);
            padding: 30px 40px;
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

        .company-info {
            position: relative;
            z-index: 1;
        }

        .company-info h2 {
            font-size: 20px;
            margin: 0 0 5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .company-info p {
            margin: 0;
            font-size: 12px;
            opacity: 0.9;
            font-weight: 400;
        }

        .payslip-body {
            padding: 0;
            background: var(--white);
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .employee-section {
            background: var(--white);
            padding: 25px 40px;
            margin: 0;
            border-bottom: 2px solid var(--border-color);
            animation: slideInLeft 0.5s ease-out;
            flex-shrink: 0;
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .section-title {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            color: var(--text-dark);
            font-size: 16px;
            font-weight: 700;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-color);
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
            color: var(--text-light);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-group span {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .payslip-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-bottom: 0;
            padding: 30px 40px;
            animation: slideInRight 0.6s ease-out;
            flex-shrink: 0;
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .breakdown-container {
            border: 2px solid var(--primary-color);
            border-radius: 15px;
            padding: 20px;
            background: var(--white);
            transition: all 0.3s ease;
        }

        .breakdown-container:hover {
            box-shadow: 0 4px 15px rgba(63, 114, 175, 0.15);
            transform: translateY(-2px);
        }

        .breakdown-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            align-items: center;
            transition: all 0.2s;
        }

        .breakdown-row:hover {
            background: rgba(63, 114, 175, 0.05);
            padding-left: 10px;
        }

        .breakdown-row span:first-child {
            color: var(--text-dark);
            font-weight: 500;
        }

        .breakdown-row span:last-child {
            color: var(--text-dark);
            font-weight: 600;
        }

        .breakdown-row.total-row {
            background: var(--gradient-primary);
            color: var(--white);
            font-weight: 700;
            margin-top: 15px;
            padding: 15px;
            border-radius: 10px;
            border: none;
        }

        .breakdown-row.total-row span {
            color: var(--white);
            font-size: 16px;
            font-weight: 700;
        }

        .summary-section {
            background: var(--white);
            border-radius: 0;
            padding: 30px 40px;
            box-shadow: none;
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            border-top: 2px solid var(--border-color);
            animation: slideInUp 0.7s ease-out;
            flex-shrink: 0;
        }

        @keyframes slideInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .summary-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-size: 14px;
            position: relative;
            z-index: 1;
            background: var(--gradient-primary);
            border-radius: 15px;
            transition: all 0.3s ease;
        }

        .summary-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(63, 114, 175, 0.3);
        }

        .summary-item span:first-child {
            color: rgba(255, 255, 255, 0.95);
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 8px;
        }

        .summary-item span:last-child {
            color: var(--white);
            font-weight: 700;
            font-size: 18px;
        }

        .summary-item.total {
            border-top: none;
            font-size: 18px;
            font-weight: 800;
            color: var(--white);
            margin-top: 0;
            padding-top: 20px;
            padding-bottom: 20px;
        }

        .summary-item.total span:first-child {
            font-size: 14px;
        }

        .summary-item.total span:last-child {
            color: var(--white);
            font-size: 24px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin: 30px 0;
            justify-content: center;
        }

        /* Attendance Summary Section */
        .attendance-summary-section {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            margin: 30px 0;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }

        .section-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .section-header h2 {
            font-size: 24px;
            color: var(--text-dark);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .section-header h2 i {
            color: var(--secondary-color);
        }

        .section-header p {
            color: var(--text-light);
            font-size: 16px;
        }

        /* Attendance Statistics Grid */
        .attendance-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--white);
            border-radius: 15px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border-left: 4px solid;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-card.present {
            border-left-color: #28a745;
        }

        .stat-card.absent {
            border-left-color: #dc3545;
        }

        .stat-card.late {
            border-left-color: #ffc107;
        }

        .stat-card.overtime {
            border-left-color: #17a2b8;
        }

        .stat-card.hours {
            border-left-color: #6f42c1;
        }

        .stat-card.halfday {
            border-left-color: #fd7e14;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: var(--white);
        }

        .stat-card.present .stat-icon {
            background: linear-gradient(135deg, #28a745, #20c997);
        }

        .stat-card.absent .stat-icon {
            background: linear-gradient(135deg, #dc3545, #e74c3c);
        }

        .stat-card.late .stat-icon {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
        }

        .stat-card.overtime .stat-icon {
            background: linear-gradient(135deg, #17a2b8, #20c997);
        }

        .stat-card.hours .stat-icon {
            background: linear-gradient(135deg, #6f42c1, #e83e8c);
        }

        .stat-card.halfday .stat-icon {
            background: linear-gradient(135deg, #fd7e14, #ffc107);
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
        }

        /* Recent Attendance */
        .recent-attendance {
            margin-top: 30px;
        }

        .recent-attendance h3 {
            font-size: 20px;
            color: var(--text-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .recent-attendance h3 i {
            color: var(--secondary-color);
        }

        .attendance-table-container {
            overflow-x: auto;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .attendance-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--white);
        }

        .attendance-table th {
            background: var(--gradient-primary);
            color: var(--white);
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 15px 12px;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .attendance-table th:first-child {
            border-top-left-radius: 12px;
        }

        .attendance-table th:last-child {
            border-top-right-radius: 12px;
        }

        .attendance-table td {
            padding: 15px 12px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-dark);
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .attendance-table tr {
            background-color: var(--white);
            transition: all 0.2s ease;
        }

        .attendance-table tr:hover {
            background-color: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(63, 114, 175, 0.1);
        }

        .attendance-table tr:hover td {
            border-color: var(--border-color);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-on_time {
            background-color: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }

        .status-late {
            background-color: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }

        .status-early {
            background-color: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }

        .status-halfday {
            background-color: rgba(253, 126, 20, 0.15);
            color: #fd7e14;
        }

        .status-early_in {
            background-color: rgba(23, 162, 184, 0.15);
            color: #17a2b8;
        }

        .overtime-badge {
            background-color: rgba(23, 162, 184, 0.15);
            color: #17a2b8;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .late-badge {
            background-color: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .no-overtime, .no-late {
            color: var(--text-light);
            font-style: italic;
        }

        .no-records {
            text-align: center;
            color: var(--text-light);
            font-style: italic;
            padding: 40px;
        }


        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(63, 114, 175, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(63, 114, 175, 0.4);
        }

        .btn-outline {
            background: var(--white);
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: var(--white);
            transform: translateY(-2px);
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

        /* Responsive Design */
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
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .payroll-summary-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .payslip-grid {
                grid-template-columns: 1fr;
            }
            
            .summary-section {
                flex-direction: column;
            }
            
            .employee-details {
                grid-template-columns: 1fr;
            }

            .attendance-stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

            .stat-card {
                padding: 15px;
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }

            .stat-value {
                font-size: 24px;
            }

            .attendance-table-container {
                overflow-x: auto;
            }

            .attendance-table th,
            .attendance-table td {
                padding: 10px 8px;
                font-size: 12px;
            }
        }

        @media (max-width: 480px) {
            .attendance-stats-grid {
                grid-template-columns: 1fr;
            }

            .section-header h2 {
                font-size: 20px;
                flex-direction: column;
                gap: 5px;
            }

            .attendance-table th,
            .attendance-table td {
                padding: 8px 6px;
                font-size: 11px;
            }

        }

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
            <a href="EmployeeHome.php" class="menu-item">
                <i class="fas fa-home"></i> Dashboard
            </a>
            
            <a href="EmpAttendance.php" class="menu-item">
                <i class="fas fa-calendar-check"></i> Attendance
            </a>
            <a href="EmpPayroll.php" class="menu-item active">
                <i class="fas fa-money-bill-wave"></i> Payroll
            </a>
            <a href="EmpHistory.php" class="menu-item">
                <i class="fas fa-history"></i> History
            </a>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">My Payroll</h1>
                <p>Welcome back, <?php echo htmlspecialchars($employee['EmployeeName']); ?></p>
            </div>
        </div>

        <!-- Attendance Summary Section -->
        <div class="attendance-summary-section">
            <div class="section-header">
                <h2><i class="fas fa-calendar-check"></i> Attendance Summary - <?php echo getMonthDisplayName($current_month); ?></h2>
                <p>Your detailed attendance information for this month</p>
            </div>

            <!-- Attendance Statistics Cards -->
            <div class="attendance-stats-grid">
                <div class="stat-card present">
                <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $days_worked_count; ?></div>
                        <div class="stat-label">Days Worked</div>
                </div>
            </div>

                <div class="stat-card absent">
                    <div class="stat-icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $absent_days; ?></div>
                        <div class="stat-label">Absent Days</div>
                    </div>
                </div>

                <div class="stat-card late">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $late_days; ?></div>
                        <div class="stat-label">Late Days</div>
                </div>
                </div>

                <div class="stat-card overtime">
                    <div class="stat-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($total_overtime_hours, 1); ?>h</div>
                        <div class="stat-label">Overtime Hours</div>
            </div>
        </div>

                <div class="stat-card hours">
                    <div class="stat-icon">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($total_work_hours, 1); ?>h</div>
                        <div class="stat-label">Total Hours</div>
                    </div>
        </div>

                <div class="stat-card halfday">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-minus"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $half_days; ?></div>
                        <div class="stat-label">Half Days</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Payroll Summary Cards -->
        <div class="payroll-summary-grid">
            <!-- Earnings Card -->
            <div class="summary-card earnings">
                <div class="card-header">
                    <div class="card-icon earnings">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                </div>
                <div class="card-title">
                    <i class="fas fa-chart-line"></i> Total Earnings
                </div>
                <div class="card-value">₱<?php echo number_format($current_payroll['gross_pay'] + $current_payroll['leave_pay'] + $current_payroll['thirteenth_month_pay'] + $current_payroll['laundry_allowance'] + $current_payroll['medical_allowance'] + $current_payroll['rice_allowance'], 2); ?></div>
                <div class="card-subtitle">This Month's Earnings</div>
                <div class="card-details">
                    <div class="detail-row">
                        <span class="detail-label">Basic Salary</span>
                        <span class="detail-value">₱<?php echo number_format($current_payroll['gross_pay'], 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Leave Pay</span>
                        <span class="detail-value">₱<?php echo number_format($current_payroll['leave_pay'], 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">13th Month Pay</span>
                        <span class="detail-value">₱<?php echo number_format($current_payroll['thirteenth_month_pay'], 2); ?></span>
                    </div>
                    <?php if ($current_payroll['laundry_allowance'] > 0): ?>
                    <div class="detail-row">
                        <span class="detail-label">Laundry Allowance</span>
                        <span class="detail-value">₱<?php echo number_format($current_payroll['laundry_allowance'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($current_payroll['medical_allowance'] > 0): ?>
                    <div class="detail-row">
                        <span class="detail-label">Medical Allowance</span>
                        <span class="detail-value">₱<?php echo number_format($current_payroll['medical_allowance'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($current_payroll['rice_allowance'] > 0): ?>
                    <div class="detail-row">
                        <span class="detail-label">Rice Allowance</span>
                        <span class="detail-value">₱<?php echo number_format($current_payroll['rice_allowance'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Deductions Card -->
            <div class="summary-card deductions">
                <div class="card-header">
                    <div class="card-icon deductions">
                        <i class="fas fa-minus-circle"></i>
                </div>
                </div>
                <div class="card-title">
                    <i class="fas fa-calculator"></i> Total Deductions
                </div>
                <div class="card-value">₱<?php echo number_format($current_payroll['total_deductions'] + $additional_deductions, 2); ?></div>
                <div class="card-subtitle">This Month's Deductions</div>
                <div class="card-details">
                    <div class="detail-row">
                        <span class="detail-label">Late Deductions</span>
                        <span class="detail-value">₱<?php echo number_format($current_payroll['lates_amount'], 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">PHIC (Employee)</span>
                        <span class="detail-value">₱<?php echo number_format($current_payroll['phic_employee'], 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Pag-IBIG (Employee)</span>
                        <span class="detail-value">₱<?php echo number_format($current_payroll['pagibig_employee'], 2); ?></span>
                    </div>
                    <?php if ($additional_deductions > 0): ?>
                    <div class="detail-row">
                        <span class="detail-label">Other Deductions</span>
                        <span class="detail-value">₱<?php echo number_format($additional_deductions, 2); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Net Pay Card -->
            <div class="summary-card net-pay">
                <div class="card-header">
                    <div class="card-icon net-pay">
                        <i class="fas fa-wallet"></i>
                </div>
                </div>
                <div class="card-title">
                    <i class="fas fa-coins"></i> Net Pay
                </div>
                <div class="card-value">₱<?php echo number_format($final_net_pay, 2); ?></div>
                <div class="card-subtitle">Take Home Pay</div>
                <div class="card-details">
                    <div class="detail-row">
                        <span class="detail-label">Gross Pay</span>
                        <span class="detail-value">₱<?php echo number_format($current_payroll['gross_pay'] + $current_payroll['leave_pay'] + $current_payroll['thirteenth_month_pay'] + $current_payroll['laundry_allowance'] + $current_payroll['medical_allowance'] + $current_payroll['rice_allowance'], 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Total Deductions</span>
                        <span class="detail-value">-₱<?php echo number_format($current_payroll['total_deductions'] + $additional_deductions, 2); ?></span>
                    </div>
            </div>
        </div>

            <!-- Attendance Card -->
            <div class="summary-card attendance">
                <div class="card-header">
                    <div class="card-icon attendance">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                </div>
                <div class="card-title">
                    <i class="fas fa-clock"></i> Attendance Summary
                </div>
                <div class="card-value"><?php echo $days_worked_count; ?> Days</div>
                <div class="card-subtitle">This Month's Work</div>
                <div class="card-details">
                    <div class="detail-row">
                        <span class="detail-label">Days Worked</span>
                        <span class="detail-value"><?php echo $days_worked_count; ?> days</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Total Hours</span>
                        <span class="detail-value"><?php echo number_format($total_work_hours, 1); ?> hrs</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Absences</span>
                        <span class="detail-value"><?php echo $absent_days; ?> days</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Late Minutes</span>
                        <span class="detail-value"><?php echo $total_late_minutes; ?> mins</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Half Days</span>
                        <span class="detail-value"><?php echo $half_days; ?> days</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn btn-primary" onclick="viewCurrentMonthPayslip()">
                <i class="fas fa-file-invoice-dollar"></i> View Current Month Payslip
            </button>
            <button class="btn btn-outline" onclick="viewPayrollHistory()">
                <i class="fas fa-history"></i> View Payroll History
            </button>
        </div>


        <!-- Payroll History Section -->
        <div class="card" id="payrollHistorySection" style="display: none;">
            <div class="card-header">
                <h2 class="card-title">Payroll History</h2>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Payment Date</th>
                            <th>Gross Pay</th>
                            <th>Deductions</th>
                            <th>Net Pay</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payroll_history)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center;">No payroll records found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payroll_history as $payroll): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($payroll['payment_date'])); ?></td>
                                    <td>₱<?php echo number_format($payroll['gross_pay'], 2); ?></td>
                                    <td>₱<?php echo number_format($payroll['total_deductions'], 2); ?></td>
                                    <td>₱<?php echo number_format($payroll['net_pay'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($payroll['description']); ?></td>
                                    <td>
                                        <button class="btn btn-primary" onclick="viewPayslip(<?php echo $payroll['id']; ?>)">
                                            <i class="fas fa-eye"></i> View Payslip
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Enhanced Payslip Modal -->
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
            </div>
            
                <div class="payslip-body">
                <div class="employee-section">
                    <div class="section-title">
                        Employee Information
                    </div>
                    <div class="employee-details">
                        <div class="detail-group">
                            <label>Name</label>
                            <span id="modal-employee-name"><?php echo htmlspecialchars($employee['EmployeeName']); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Employee ID</label>
                            <span id="modal-employee-id"><?php echo htmlspecialchars($employee['EmployeeID']); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>ID No.</label>
                            <span id="modal-employee-id-no"><?php echo htmlspecialchars($employee['EmployeeID']); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Department</label>
                            <span id="modal-employee-dept"><?php echo htmlspecialchars($employee['Department']); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>SSS</label>
                            <span id="modal-employee-sss"><?php echo $employee['SSS'] ?? 'N/A'; ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Philhealth</label>
                            <span id="modal-employee-philhealth"><?php echo $employee['PHIC'] ?? 'N/A'; ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Pag-IBIG</label>
                            <span id="modal-employee-pagibig"><?php echo $employee['HDMF'] ?? 'N/A'; ?></span>
                        </div>
                        <div class="detail-group">
                            <label>TIN</label>
                            <span id="modal-employee-tin"><?php echo $employee['TIN'] ?? 'N/A'; ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Payroll Group</label>
                            <span>WTEICC</span>
                        </div>
                        <div class="detail-group">
                            <label>Date Covered</label>
                            <span id="modal-date-covered"><?php echo date('m/d/Y', strtotime($current_month . '-01')) . ' - ' . date('m/d/Y'); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Payment Date</label>
                            <span><?php echo date('F j, Y'); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Total Days Worked</label>
                            <span id="modal-total-days-worked"><?php echo $attendance_summary['days_worked']; ?> day<?php echo $attendance_summary['days_worked'] != 1 ? 's' : ''; ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Late Minutes</label>
                            <span id="modal-late-mins"><?php echo $attendance_summary['late_minutes']; ?> min<?php echo $attendance_summary['late_minutes'] != 1 ? 's' : ''; ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Overtime Hours</label>
                            <span id="modal-overtime-hours"><?php echo number_format($total_overtime_hours, 2); ?> hour<?php echo $total_overtime_hours != 1 ? 's' : ''; ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Late Deduction</label>
                            <span id="modal-late-deduction">₱<?php echo number_format($current_payroll['lates_amount'], 2); ?></span>
                        </div>
                        <div class="detail-group">
                            <label>Total Gross Income</label>
                            <span id="modal-total-gross-income">₱<?php echo number_format($current_payroll['gross_pay'] + $current_payroll['leave_pay'] + $current_payroll['thirteenth_month_pay'], 2); ?></span>
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
                                <span id="modal-late-deductions">₱<?php echo number_format($current_payroll['lates_amount'], 2); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span>PHIC (Employee)</span>
                                <span id="modal-phic-employee">₱<?php echo number_format($current_payroll['phic_employee'], 2); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span>Pag-IBIG (Employee)</span>
                                <span id="modal-pagibig-employee">₱<?php echo number_format($current_payroll['pagibig_employee'], 2); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span>SSS (Employee)</span>
                                <span id="modal-sss-employee">₱<?php echo number_format($current_payroll['sss_employee'], 2); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span>Withholding Tax</span>
                                <span id="modal-withholding-tax">₱0.00</span>
                            </div>
                            <?php if ($additional_deductions > 0): ?>
                            <div class="breakdown-row">
                                <span>Additional Deductions</span>
                                <span id="modal-additional-deductions">₱<?php echo number_format($additional_deductions, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="breakdown-row total-row">
                                <span>Total Deductions</span>
                                <span id="modal-total-deductions">₱<?php echo number_format($current_payroll['total_deductions'] + $additional_deductions, 2); ?></span>
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
                                <span id="modal-basic-salary">₱<?php echo number_format($current_payroll['gross_pay'], 2); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span>Overtime Pay</span>
                                <span id="modal-overtime-pay">₱<?php echo number_format($current_payroll['overtime_pay'], 2); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span>Special Holiday Pay</span>
                                <span id="modal-special-holiday-pay">₱<?php echo number_format($current_payroll['special_holiday_pay'], 2); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span>Legal Holiday Pay</span>
                                <span id="modal-legal-holiday-pay">₱<?php echo number_format($current_payroll['legal_holiday_pay'], 2); ?></span>
                            </div>
                            <div class="breakdown-row" id="night-shift-row">
                                <span>Night Shift Differential</span>
                                <span id="modal-night-shift-diff">₱<?php echo number_format($current_payroll['night_shift_diff'], 2); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span>Leave Pay</span>
                                <span id="modal-leave-pay">₱<?php echo number_format($current_payroll['leave_pay'], 2); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span>13th Month Pay</span>
                                <span id="modal-13th-month">₱<?php echo number_format($current_payroll['thirteenth_month_pay'], 2); ?></span>
                            </div>
                            <?php if ($current_payroll['laundry_allowance'] > 0): ?>
                            <div class="breakdown-row">
                                <span>Laundry Allowance</span>
                                <span id="modal-laundry-allowance">₱<?php echo number_format($current_payroll['laundry_allowance'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($current_payroll['medical_allowance'] > 0): ?>
                            <div class="breakdown-row">
                                <span>Medical Allowance</span>
                                <span id="modal-medical-allowance">₱<?php echo number_format($current_payroll['medical_allowance'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($current_payroll['rice_allowance'] > 0): ?>
                            <div class="breakdown-row">
                                <span>Rice Allowance</span>
                                <span id="modal-rice-allowance">₱<?php echo number_format($current_payroll['rice_allowance'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="breakdown-row total-row">
                                <span>Total Earnings</span>
                                <span id="modal-total-earnings">₱<?php 
                                $shift = $employee['Shift'] ?? '';
                                $isNightShift = strpos($shift, '22:00') !== false || strpos($shift, '22:00-06:00') !== false || 
                                              stripos($shift, 'night') !== false || stripos($shift, 'nsd') !== false;
                                $night_shift_amount = $isNightShift ? $current_payroll['night_shift_diff'] : 0;
                                echo number_format($current_payroll['gross_pay'] + $current_payroll['overtime_pay'] + $current_payroll['special_holiday_pay'] + $current_payroll['legal_holiday_pay'] + $night_shift_amount + $current_payroll['leave_pay'] + $current_payroll['thirteenth_month_pay'] + $current_payroll['laundry_allowance'] + $current_payroll['medical_allowance'] + $current_payroll['rice_allowance'], 2); 
                                ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="summary-section">
                    <div class="summary-item">
                        <span>Total Earnings:</span>
                        <span id="modal-summary-earnings">₱<?php 
                        $shift = $employee['Shift'] ?? '';
                        $isNightShift = strpos($shift, '22:00') !== false || strpos($shift, '22:00-06:00') !== false || 
                                      stripos($shift, 'night') !== false || stripos($shift, 'nsd') !== false;
                        $night_shift_amount = $isNightShift ? $current_payroll['night_shift_diff'] : 0;
                        echo number_format($current_payroll['gross_pay'] + $current_payroll['overtime_pay'] + $current_payroll['special_holiday_pay'] + $current_payroll['legal_holiday_pay'] + $night_shift_amount + $current_payroll['leave_pay'] + $current_payroll['thirteenth_month_pay'] + $current_payroll['laundry_allowance'] + $current_payroll['medical_allowance'] + $current_payroll['rice_allowance'], 2); 
                        ?></span>
                    </div>
                    <div class="summary-item">
                        <span>Total Deductions:</span>
                        <span id="modal-summary-deductions">₱<?php echo number_format($current_payroll['total_deductions'] + $additional_deductions, 2); ?></span>
                    </div>
                    <div class="summary-item total">
                        <span>Net Pay:</span>
                        <span id="modal-net-pay">₱<?php echo number_format($final_net_pay, 2); ?></span>
                    </div>
                </div>
                </div>
            
                <div class="payslip-footer">
                <div class="footer-note">
                    <p>This is a computer-generated document. No signature is required.</p>
                    <p>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></p>
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
            window.location.href = 'Logout.php';
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const logoutModal = document.getElementById('logoutModal');
            const payslipModal = document.getElementById('payslipModal');
            
            if (event.target === logoutModal) {
                closeLogoutModal();
            }
            if (event.target === payslipModal) {
                closePayslipModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeLogoutModal();
                closePayslipModal();
            }
        });

        // View current month payslip
        function viewCurrentMonthPayslip() {
            document.getElementById('payslipModal').style.display = 'block';
        }

        // View payroll history
        function viewPayrollHistory() {
            const historySection = document.getElementById('payrollHistorySection');
            if (historySection.style.display === 'none') {
                historySection.style.display = 'block';
                historySection.scrollIntoView({ behavior: 'smooth' });
            } else {
                historySection.style.display = 'none';
            }
        }

        // Close payslip modal
        function closePayslipModal() {
            document.getElementById('payslipModal').style.display = 'none';
        }

        // Export payslip PDF
        function exportPayslipPDF() {
            const employeeId = '<?php echo $employee_id; ?>';
            const currentMonth = '<?php echo $current_month; ?>';
            
            if (!employeeId) {
                alert('Employee ID not found');
                return;
            }
            
            // Create download link
            const downloadUrl = `generate_payslip_pdf.php?employee_id=${employeeId}&month=${currentMonth}`;
            
            // Create temporary link and trigger download
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = `Payslip_${employeeId}_${currentMonth}.pdf`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // View historical payslip
        function viewPayslip(payrollId) {
            // Create a form to submit the payslip ID
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = 'view_payslip.php';

            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'payroll_id';
            input.value = payrollId;

            form.appendChild(input);
            
            // Submit form and handle response
            fetch('view_payslip.php', {
                method: 'POST',
                body: new FormData(form)
            })
            .then(response => response.text())
            .then(html => {
                // Create a temporary container to parse the HTML
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                
                // Extract data from the response and populate the modal
                const employeeName = tempDiv.querySelector('#payslipEmployeeName')?.textContent || '<?php echo htmlspecialchars($employee['EmployeeName']); ?>';
                const employeeId = tempDiv.querySelector('#payslipEmployeeId')?.textContent || '<?php echo htmlspecialchars($employee['EmployeeID']); ?>';
                const department = tempDiv.querySelector('#payslipDepartment')?.textContent || '<?php echo htmlspecialchars($employee['Department']); ?>';
                const position = tempDiv.querySelector('#payslipPosition')?.textContent || '<?php echo htmlspecialchars($employee['Position']); ?>';
                
                // Update modal with historical data
                document.getElementById('modal-employee-name').textContent = employeeName;
                document.getElementById('modal-employee-id').textContent = employeeId;
                document.getElementById('modal-employee-dept').textContent = department;
                document.getElementById('modal-employee-position').textContent = position;
                
                document.getElementById('payslipModal').style.display = 'block';
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while fetching the payslip.');
            });
        }

        // Print payslip
        function printPayslip() {
            const printContent = document.querySelector('.payslip-content').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = `
                <div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px;">
                    ${printContent}
                </div>
            `;
            window.print();
            document.body.innerHTML = originalContent;
            
            // Reattach event listeners after restoring content
            attachEventListeners();
        }

        function attachEventListeners() {
            // Reattach modal close button event
            const closeBtn = document.querySelector('.modal-close');
            if (closeBtn) {
                closeBtn.onclick = closePayslipModal;
            }

            // Reattach window click event
            window.onclick = function(event) {
                const modal = document.getElementById('payslipModal');
                if (event.target === modal) {
                    closePayslipModal();
                }
            }
        }

        // Initial attachment of event listeners
        attachEventListeners();
        
        // Conditionally show/hide night shift differential based on shift
        const shift = '<?php echo $employee['Shift'] ?? ''; ?>';
        const isNightShift = shift.includes('22:00') || shift.includes('22:00-06:00') || 
                            shift.toLowerCase().includes('night') || shift.toLowerCase().includes('nsd');
        
        if (!isNightShift) {
            document.getElementById('night-shift-row').style.display = 'none';
        }

        // Add print functionality to the modal
        document.addEventListener('DOMContentLoaded', function() {
            // Add print button to modal if it doesn't exist
            const modalClose = document.querySelector('.modal-close');
            if (modalClose && !document.querySelector('.print-btn')) {
                const printBtn = document.createElement('button');
                printBtn.className = 'btn btn-primary print-btn';
                printBtn.style.cssText = 'position: absolute; top: 20px; right: 70px; z-index: 10;';
                printBtn.innerHTML = '<i class="fas fa-print"></i> Print';
                printBtn.onclick = printPayslip;
                modalClose.parentNode.insertBefore(printBtn, modalClose);
            }
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