<?php
session_start();
if (!isset($_SESSION['employee_id'])) {
    header("Location: Login.php");
    exit();
}

// Include attendance calculations
require_once 'attendance_calculations.php';

$conn = new mysqli("localhost", "root", "", "wteimain1");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$employee_id = $_SESSION['employee_id'];

// Get employee details
$stmt = $conn->prepare("SELECT EmployeeName, Department FROM empuser WHERE EmployeeID = ?");
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();

// Show only today's attendance
$query = "SELECT a.*, e.Shift, COALESCE(a.is_on_leave, 0) as is_on_leave FROM attendance a JOIN empuser e ON a.EmployeeID = e.EmployeeID WHERE a.EmployeeID = ? AND attendance_date = CURDATE() ORDER BY attendance_date DESC";
$params = [$employee_id];
$param_types = "s";

// Prepare and execute the query
$stmt = $conn->prepare($query);
$stmt->bind_param($param_types, $employee_id);
$stmt->execute();
$attendance_result = $stmt->get_result();
$attendance_records = $attendance_result->fetch_all(MYSQLI_ASSOC);

// Apply accurate calculations to all attendance records
$attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);

// Calculate statistics
$total_days = count($attendance_records);
$on_time = 0;
$late = 0;
$total_hours = 0;
$total_overtime = 0;
$total_late_minutes = 0;
$total_early_out_minutes = 0;
$present_dates = [];

foreach ($attendance_records as $record) {
    // Track punctual vs late
    if (($record['status'] ?? '') === 'present' || ($record['status'] ?? '') === 'on_time') {
        $on_time++;
    } elseif (($record['status'] ?? '') === 'late') {
        $late++;
    }

    // Track present days regardless of late/early via attendance_type
    if (($record['attendance_type'] ?? '') === 'present' && !empty($record['attendance_date'])) {
        $present_dates[$record['attendance_date']] = true;
    }
    
    // Use server-computed total_hours which already excludes lunch gaps via segments
    if (isset($record['total_hours'])) {
        $total_hours += floatval($record['total_hours']);
    } else {
        $total_hours += AttendanceCalculator::calculateTotalHours($record['time_in'] ?? null, $record['time_out'] ?? null);
    }
    
    // Use accurate calculated values
    $total_overtime += floatval($record['overtime_hours'] ?? 0);
    $total_late_minutes += intval($record['late_minutes'] ?? 0);
    $total_early_out_minutes += intval($record['early_out_minutes'] ?? 0);
}

// Compute attendance rate for today only
$present_days = count($present_dates);
$today = new DateTime('today');
$working_days_elapsed = ((int)$today->format('w') !== 0) ? 1 : 0; // 1 if not Sunday, 0 if Sunday

$average_hours = $total_days > 0 ? round($total_hours / $total_days, 1) : 0;
$attendance_percentage = $working_days_elapsed > 0 ? max(0, min(100, round(($present_days / $working_days_elapsed) * 100))) : 0;
$average_overtime = $total_days > 0 ? round($total_overtime / $total_days, 2) : 0;
$average_late_minutes = $total_days > 0 ? round($total_late_minutes / $total_days, 1) : 0;
$average_early_out_minutes = $total_days > 0 ? round($total_early_out_minutes / $total_days, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Overview - WTEI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    
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

        .summary-card:nth-child(5) {
            border-left-color: #9C27B0;
        }

        .summary-card:nth-child(6) {
            border-left-color: #FF9800;
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

        .summary-icon.overtime {
            background-color: rgba(156, 39, 176, 0.15);
            color: #9C27B0;
        }

        .summary-icon.hours {
            background-color: rgba(255, 152, 0, 0.15);
            color: #FF9800;
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

        .summary-details {
            margin-top: 8px;
            font-size: 12px;
            color: #6c757d;
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
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 12px 40px rgba(17, 45, 78, 0.08);
            margin-bottom: 30px;
            border: 1px solid rgba(219, 226, 239, 0.3);
            position: relative;
            overflow: hidden;
        }

        .results-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #3F72AF, #112D4E, #3F72AF);
            background-size: 200% 100%;
            animation: shimmer 3s ease-in-out infinite;
        }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        /* Enhanced View Attendance Modal */
        #viewAttendanceModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease-in-out;
        }

        #viewAttendanceModal.show {
            display: flex !important;
            align-items: center;
            justify-content: center;
        }

        #viewAttendanceModal .modal-content {
            background: #fff;
            width: 92%;
            max-width: 600px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            position: relative;
            animation: modalSlideUp 0.3s ease-out;
            max-height: 90vh;
            overflow-y: auto;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes modalSlideUp {
            from { 
                opacity: 0; 
                transform: translateY(30px) scale(0.95);
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1);
            }
        }

        #viewAttendanceModal .modal-header {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: #fff;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-radius: 16px 16px 0 0;
        }

        #viewAttendanceModal .modal-header h2 {
            color: #fff;
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        #viewAttendanceModal .modal-header h2 i {
            color: #fff;
            font-size: 18px;
        }

        #viewAttendanceModal .close {
            color: #fff;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            background: none;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        #viewAttendanceModal .close:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        #viewAttendanceModal .modal-body {
            padding: 20px;
            line-height: 1.8;
        }

        #viewAttendanceModal .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            background: #f8f9fa;
            border-radius: 0 0 16px 16px;
            gap: 10px;
        }

        /* Enhanced Modal Content Styles - Compact Layout */
        .attendance-modal-content {
            padding: 0;
        }

        .main-info-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #3F72AF;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .info-row:last-child {
            margin-bottom: 0;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-label {
            font-weight: 600;
            color: #112D4E;
            font-size: 14px;
        }

        .info-value {
            color: #2D3748;
            font-size: 15px;
            font-weight: 500;
        }

        .info-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .time-section,
        .metrics-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #3F72AF;
        }

        .metrics-section:last-child {
            margin-bottom: 0;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #112D4E;
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }

        .section-title i {
            color: #3F72AF;
            font-size: 14px;
        }

        .time-container {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .time-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .time-label {
            font-weight: 600;
            color: #112D4E;
            font-size: 13px;
        }

        .time-value {
            color: #2D3748;
            font-size: 14px;
            font-weight: 500;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }

        .metric-item {
            background: white;
            padding: 12px;
            border-radius: 6px;
            text-align: center;
            border: 1px solid #e9ecef;
        }

        .metric-label {
            display: block;
            font-weight: 600;
            color: #112D4E;
            font-size: 12px;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .metric-value {
            color: #2D3748;
            font-size: 16px;
            font-weight: 600;
        }

        .notes-section {
            background: white;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .notes-label {
            font-weight: 600;
            color: #112D4E;
            font-size: 13px;
            margin-right: 8px;
        }

        .notes-value {
            color: #2D3748;
            font-size: 14px;
        }

        /* Status-specific styling for badges */
        .info-badge.type-present {
            background: linear-gradient(135deg, rgba(63, 114, 175, 0.2), rgba(63, 114, 175, 0.1));
            color: #3F72AF;
            border: 1px solid rgba(63, 114, 175, 0.3);
        }

        .info-badge.status-present,
        .info-badge.status-on_time {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.2), rgba(76, 175, 80, 0.1));
            color: #4CAF50;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .info-badge.status-late {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 193, 7, 0.1));
            color: #F57F17;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }

        .info-badge.status-absent {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.2), rgba(255, 107, 107, 0.1));
            color: #FF6B6B;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .info-badge.status-half_day {
            background: linear-gradient(135deg, rgba(33, 150, 243, 0.2), rgba(33, 150, 243, 0.1));
            color: #2196F3;
            border: 1px solid rgba(33, 150, 243, 0.3);
        }

        .info-badge.status-early_in {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.2), rgba(76, 175, 80, 0.1));
            color: #4CAF50;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .info-badge.status-on-leave {
            background: linear-gradient(135deg, rgba(156, 39, 176, 0.2), rgba(233, 30, 99, 0.2));
            color: #9C27B0;
            box-shadow: 0 2px 4px rgba(156, 39, 176, 0.3);
            font-weight: bold;
        }

        /* Enhanced Modal Footer */
        #viewAttendanceModal .modal-footer .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        #viewAttendanceModal .modal-footer .btn-primary {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            border: none;
        }

        #viewAttendanceModal .modal-footer .btn-primary:hover {
            background: linear-gradient(135deg, #112D4E 0%, #3F72AF 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(63, 114, 175, 0.3);
        }

        #viewAttendanceModal .modal-footer .btn i {
            font-size: 12px;
        }

        /* Responsive Design for Modal */
        @media (max-width: 768px) {
            .info-row {
                flex-direction: column;
                gap: 10px;
            }
            
            .metrics-grid {
                grid-template-columns: 1fr;
            }
            
            .main-info-section,
            .time-section,
            .metrics-section {
                padding: 15px;
            }

            #viewAttendanceModal .modal-footer {
                padding: 15px;
                flex-direction: column;
            }

            #viewAttendanceModal .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .info-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .time-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
        }

        /* Enhanced Modal Footer */
        .modal-footer {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-top: 1px solid #dee2e6;
            padding: 20px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .modal-footer .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .modal-footer .btn-primary {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            border: none;
        }

        .modal-footer .btn-primary:hover {
            background: linear-gradient(135deg, #112D4E 0%, #3F72AF 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(63, 114, 175, 0.3);
        }

        .modal-footer .btn i {
            font-size: 12px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .info-row {
                flex-direction: column;
                gap: 10px;
            }
            
            .metrics-grid {
                grid-template-columns: 1fr;
            }
            
            .main-info-section,
            .time-section,
            .metrics-section {
                padding: 15px;
            }

            .modal-footer {
                padding: 15px;
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .info-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .time-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px 25px;
            background: linear-gradient(135deg, rgba(63, 114, 175, 0.05) 0%, rgba(17, 45, 78, 0.05) 100%);
            border-radius: 16px;
            border: 1px solid rgba(63, 114, 175, 0.1);
            position: relative;
            overflow: hidden;
        }

        .results-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, #3F72AF, #112D4E);
        }

        .results-header h2 {
            font-size: 22px;
            color: #112D4E;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .results-header h2 i {
            color: #3F72AF;
            font-size: 24px;
            filter: drop-shadow(0 2px 4px rgba(63, 114, 175, 0.3));
        }

        .results-actions {
            display: flex;
            gap: 12px;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(17, 45, 78, 0.08);
            border: 1px solid rgba(219, 226, 239, 0.3);
            background: white;
            position: relative;
        }

        .table-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(63, 114, 175, 0.3), transparent);
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
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 20px 16px;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
            border: none;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        table th:first-child {
            border-top-left-radius: 16px;
        }

        table th:last-child {
            border-top-right-radius: 16px;
        }

        table td {
            padding: 18px 16px;
            border-bottom: 1px solid rgba(240, 244, 248, 0.8);
            color: #2D3748;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }

        table tr {
            background-color: white;
            transition: all 0.3s ease;
            position: relative;
        }

        table tr:nth-child(even) {
            background-color: rgba(248, 251, 255, 0.3);
        }

        table tr:hover {
            background: linear-gradient(135deg, rgba(63, 114, 175, 0.05) 0%, rgba(17, 45, 78, 0.05) 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(63, 114, 175, 0.1);
            border-radius: 8px;
        }

        table tr:hover td {
            border-color: rgba(63, 114, 175, 0.2);
        }

        table tr:hover::after {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, #3F72AF, #112D4E);
            border-radius: 0 4px 4px 0;
            z-index: 1;
        }

        .status-badge {
            padding: 10px 18px;
            border-radius: 25px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            white-space: nowrap;
            word-break: keep-all;
            overflow-wrap: normal;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .status-badge::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .status-badge:hover::before {
            left: 100%;
        }

        .status-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .status-present {
            background: linear-gradient(135deg, rgba(63, 114, 175, 0.2), rgba(63, 114, 175, 0.1));
            color: #3F72AF;
            border: 1px solid rgba(63, 114, 175, 0.3);
        }

        .status-absent {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.2), rgba(255, 107, 107, 0.1));
            color: #FF6B6B;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .status-late {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 193, 7, 0.1));
            color: #F57F17;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }

        .status-early_in {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.2), rgba(76, 175, 80, 0.1));
            color: #4CAF50;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .status-half-day {
            background: linear-gradient(135deg, rgba(33, 150, 243, 0.2), rgba(33, 150, 243, 0.1));
            color: #2196F3;
            border: 1px solid rgba(33, 150, 243, 0.3);
        }

        .status-on_time {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.25), rgba(76, 175, 80, 0.15));
            color: #4CAF50;
            border: 1px solid rgba(76, 175, 80, 0.4);
            box-shadow: 0 2px 8px rgba(76, 175, 80, 0.2);
        }

        .status-on-leave {
            background: linear-gradient(135deg, rgba(156, 39, 176, 0.2), rgba(233, 30, 99, 0.2));
            color: #9C27B0;
            box-shadow: 0 2px 4px rgba(156, 39, 176, 0.3);
            font-weight: bold;
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
                gap: 15px;
            }

            .results-container {
                padding: 20px;
                border-radius: 16px;
            }

            .results-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
                padding: 15px 20px;
                margin-bottom: 20px;
            }

            .results-header h2 {
                font-size: 18px;
            }

            .results-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .table-container {
                border-radius: 12px;
                box-shadow: 0 4px 15px rgba(17, 45, 78, 0.08);
            }

            table th {
                padding: 12px 8px;
                font-size: 11px;
                letter-spacing: 0.5px;
            }

            table td {
                padding: 12px 8px;
                font-size: 12px;
            }

            .status-badge {
                padding: 6px 12px;
                font-size: 10px;
                letter-spacing: 0.5px;
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

        /* Enhanced Animation for table rows */
        @keyframes fadeInRow {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes slideInFromLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        table tr {
            animation: fadeInRow 0.4s ease;
        }

        table tr:nth-child(even) {
            animation-delay: 0.1s;
        }

        table tr:nth-child(odd) {
            animation-delay: 0.05s;
        }

        .status-badge {
            animation: slideInFromLeft 0.3s ease;
        }

        /* Enhanced Custom scrollbar */
        .table-container::-webkit-scrollbar {
            height: 12px;
        }

        .table-container::-webkit-scrollbar-track {
            background: rgba(219, 226, 239, 0.3);
            border-radius: 12px;
            margin: 0 10px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #3F72AF, #112D4E);
            border-radius: 12px;
            border: 2px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #112D4E, #3F72AF);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .table-container::-webkit-scrollbar-corner {
            background: transparent;
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

        /* Enhanced Responsive design */
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

            .results-container {
                padding: 15px;
                margin-bottom: 20px;
            }

            .results-header {
                padding: 12px 15px;
                margin-bottom: 15px;
            }

            .results-header h2 {
                font-size: 16px;
            }

            table th {
                padding: 10px 6px;
                font-size: 10px;
            }

            table td {
                padding: 10px 6px;
                font-size: 11px;
            }

            .status-badge {
                padding: 4px 8px;
                font-size: 9px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
    <img src="LOGO/newLogo_transparent.png" class="logo" style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding:-250px; padding-bottom: 20px;">       

        
        <nav class="menu">
            <a href="EmployeeHome.php" class="menu-item">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="EmpAttendance.php" class="menu-item active">
                <i class="fas fa-calendar-check"></i>
                <span>Attendance</span>
            </a>
            
            <a href="EmpPayroll.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payroll</span>
            </a>
            <a href="EmpHistory.php" class="menu-item">
                <i class="fas fa-history"></i> <span>History</span>
            </a>
            
        </nav>
        <a href="Logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h1><i class="fas fa-calendar-check"></i> Today's Attendance</h1>
            <div class="header-actions">
                
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon present">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo $total_days; ?></div>
                    <div class="summary-label">Total Days Present</div>
                    <div class="summary-details">
                        <?php echo $on_time; ?> On Time • <?php echo $late; ?> Late
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon hours">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo $average_hours; ?></div>
                    <div class="summary-label">Average Hours Worked</div>
                    <div class="summary-details">
                        Total: <?php echo round($total_hours, 1); ?> hours
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon rate">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo $attendance_percentage; ?>%</div>
                    <div class="summary-label">Attendance Rate</div>
                    <div class="summary-details">
                        Current Filter
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon overtime">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo $average_overtime; ?></div>
                    <div class="summary-label">Average Overtime</div>
                    <div class="summary-details">
                        Total: <?php echo $total_overtime; ?> hours
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon late">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo $average_late_minutes; ?></div>
                    <div class="summary-label">Average Late (min)</div>
                    <div class="summary-details">
                        Total: <?php echo $total_late_minutes; ?> minutes
                    </div>
                </div>
            </div>

        </div>


        <!-- Attendance History -->
        <div class="results-container">
            <div class="results-header">
                <h2><i class="fas fa-history"></i> Today's Attendance History</h2>
                <div class="results-actions">
                    
                </div>
            </div>
            <!-- Details Modal -->
            <div id="viewAttendanceModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2><i class="fas fa-id-card"></i> Attendance Details</h2>
                        <button class="close" onclick="closeViewAttendanceModal()" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body" id="viewAttendanceBody" style="line-height:1.8;"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" onclick="closeViewAttendanceModal()">
                            <i class="fas fa-check"></i>
                            Close
                        </button>
                    </div>
                </div>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Hours Worked</th>
                            <th>Total Hours</th>
                            <th>Attendance Type</th>
                            <th>Status</th>
                            <th>Late (min)</th>
                            <th>Early Out (min)</th>
                            <th>Overtime (hrs)</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($attendance_records) > 0): ?>
                            <?php foreach ($attendance_records as $record): 
                                // Compute lunch-excluded hours for display
                                $shiftStr = isset($record['Shift']) ? $record['Shift'] : '';
                                $ls = null; $le = null;
                                switch ($shiftStr) {
                                    case '08:00-17:00':
                                    case '08:00-17:00pb':
                                    case '8-5pb':
                                        $ls = '12:00:00'; $le = '13:00:00';
                                        break;
                                    case '08:30-17:30':
                                    case '8:30-5:30pm':
                                        $ls = '12:30:00'; $le = '13:30:00';
                                        break;
                                    case '09:00-18:00':
                                    case '9am-6pm':
                                        $ls = '13:00:00'; $le = '14:00:00';
                                        break;
                                }
                                // Display total hours from server calculation
                                $durationText = '-';
                                if (isset($record['total_hours'])) {
                                    $hours = floatval($record['total_hours']);
                                    $h = floor($hours);
                                    $m = floor(($hours - $h) * 60);
                                    $durationText = sprintf('%dh %02dm', $h, $m);
                                } elseif (!empty($record['time_in']) && !empty($record['time_out'])) {
                                    $hours = AttendanceCalculator::calculateTotalHours($record['time_in'], $record['time_out']);
                                    $h = floor($hours);
                                    $m = floor(($hours - $h) * 60);
                                    $durationText = sprintf('%dh %02dm', $h, $m);
                                }
                            ?>
                            <tr class="clickable-row"
                                data-date="<?php echo htmlspecialchars($record['attendance_date']); ?>"
                                data-am_in="<?php echo htmlspecialchars($record['time_in_morning'] ?? ''); ?>"
                                data-am_out="<?php echo htmlspecialchars($record['time_out_morning'] ?? ''); ?>"
                                data-pm_in="<?php echo htmlspecialchars($record['time_in_afternoon'] ?? ''); ?>"
                                data-pm_out="<?php echo htmlspecialchars($record['time_out_afternoon'] ?? ''); ?>"
                                data-status="<?php echo htmlspecialchars($record['status'] ?? ''); ?>"
                                data-attendance_type="<?php echo htmlspecialchars($record['attendance_type'] ?? ''); ?>"
                                data-late="<?php echo htmlspecialchars($record['late_minutes'] ?? 0); ?>"
                                data-early="<?php echo htmlspecialchars($record['early_out_minutes'] ?? 0); ?>"
                                data-ot="<?php echo htmlspecialchars($record['overtime_hours'] ?? 0); ?>"
                                data-notes="<?php echo htmlspecialchars($record['notes'] ?? '-'); ?>"
                                data-shift="<?php echo htmlspecialchars($record['Shift'] ?? ''); ?>"
                                data-is_on_leave="<?php echo htmlspecialchars($record['is_on_leave'] ?? 0); ?>">
                                <td><?php echo date('M d, Y', strtotime($record['attendance_date'])); ?></td>
                                <td><?php echo $durationText; ?></td>
                                <td><?php echo isset($record['total_hours']) && $record['total_hours'] > 0 ? number_format((float)$record['total_hours'], 2) : '-'; ?></td>
                                <td><?php echo ucfirst($record['attendance_type']); ?></td>
                                <td>
                                    <span class="status-badge <?php 
                                        if (($record['is_on_leave'] ?? 0) == 1) echo 'status-on-leave';
                                        elseif ($record['status'] === 'half_day') echo 'status-half-day';
                                        elseif ($record['status'] === 'early') echo 'status-warning';
                                        elseif ($record['status'] === 'late') echo 'status-warning';
                                        elseif ($record['status'] === 'present' || $record['status'] === 'on_time') echo 'status-success';
                                        else echo 'status-absent';
                                    ?>">
                                        <?php 
                                            if (($record['is_on_leave'] ?? 0) == 1) {
                                                echo 'ON-LEAVE';
                                            } elseif ($record['status'] === 'present' || $record['status'] === 'on_time') {
                                                echo 'On-Time';
                                            } else {
                                                echo $record['status'] ? ($record['status']==='half_day' ? 'Half Day' : ucfirst($record['status'])) : '-';
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td><?php echo ($record['status'] === 'half_day') ? '-' : (($record['late_minutes'] > 0) ? $record['late_minutes'] : '-'); ?></td>
                                <td><?php echo ($record['status'] === 'half_day') ? '-' : (($record['early_out_minutes'] > 0) ? $record['early_out_minutes'] : '-'); ?></td>
                                <td><?php echo ($record['overtime_hours'] > 0 ? number_format($record['overtime_hours'], 2) : '-'); ?></td>
                                <td><?php echo htmlspecialchars($record['notes'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 20px;">No attendance records found for today.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
        
        // Details modal logic
        function openViewAttendanceModal(rowEl) {
            console.log('openViewAttendanceModal called with:', rowEl);
            const body = document.getElementById('viewAttendanceBody');
            if (!body) {
                console.error('viewAttendanceBody element not found');
                return;
            }
            
            const fmt = (t)=> t ? new Date('1970-01-01T' + t).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '-';
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
            const shift = rowEl.getAttribute('data-shift') || '-';
            const isOnLeave = parseInt(rowEl.getAttribute('data-is_on_leave')) === 1;
            const isHalfDay = status === 'half_day';
            const hasAmPairOnly = rawAmIn && rawAmOut && !rawPmIn && !rawPmOut;
            const hasPmPairOnly = rawPmIn && rawPmOut && !rawAmIn && !rawAmOut;
            let sessionHtml = '';
            if (isHalfDay && hasPmPairOnly) {
                sessionHtml = `
                    <div class="time-row">
                        <span class="time-label">PM In (Lunch End):</span>
                        <span class="time-value">${pmIn}</span>
                    </div>
                    <div class="time-row">
                        <span class="time-label">PM Out:</span>
                        <span class="time-value">${pmOut}</span>
                    </div>
                `;
            } else if (isHalfDay && hasAmPairOnly) {
                sessionHtml = `
                    <div class="time-row">
                        <span class="time-label">AM In:</span>
                        <span class="time-value">${amIn}</span>
                    </div>
                    <div class="time-row">
                        <span class="time-label">AM Out (Lunch Start):</span>
                        <span class="time-value">${amOut}</span>
                    </div>
                `;
            } else {
                sessionHtml = `
                    <div class="time-row">
                        <span class="time-label">AM In:</span>
                        <span class="time-value">${amIn}</span>
                    </div>
                    <div class="time-row">
                        <span class="time-label">AM Out (Lunch Start):</span>
                        <span class="time-value">${amOut}</span>
                    </div>
                    <div class="time-row">
                        <span class="time-label">PM In (Lunch End):</span>
                        <span class="time-value">${pmIn}</span>
                    </div>
                    <div class="time-row">
                        <span class="time-label">PM Out:</span>
                        <span class="time-value">${pmOut}</span>
                    </div>
                `;
            }
            body.innerHTML = `
                <div class="attendance-modal-content">
                    <!-- Main Info Section -->
                    <div class="main-info-section">
                        <div class="info-row">
                            <div class="info-item">
                                <span class="info-label">Date:</span>
                                <span class="info-value">${new Date(date).toLocaleDateString()}</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Shift:</span>
                                <span class="info-value">${shift}</span>
                            </div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-item">
                                <span class="info-label">Type:</span>
                                <span class="info-badge type-${type.toLowerCase()}">${type.charAt(0).toUpperCase() + type.slice(1)}</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Status:</span>
                                <span class="info-badge ${isOnLeave ? 'status-on-leave' : 'status-' + status.toLowerCase()}">${isOnLeave ? 'ON-LEAVE' : (status === 'half_day' ? 'Half Day' : (status.charAt(0).toUpperCase() + status.slice(1)))}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Time Tracking Section -->
                    <div class="time-section">
                        <h4 class="section-title">
                            <i class="fas fa-clock"></i>
                            Time Tracking
                        </h4>
                        <div class="time-container">
                            ${sessionHtml}
                        </div>
                    </div>

                    <!-- Metrics Section -->
                    <div class="metrics-section">
                        <h4 class="section-title">
                            <i class="fas fa-chart-line"></i>
                            Performance Metrics
                        </h4>
                        <div class="metrics-grid">
                            <div class="metric-item">
                                <span class="metric-label">Late (min)</span>
                                <span class="metric-value">${status === 'half_day' ? '-' : late}</span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-label">Early Out (min)</span>
                                <span class="metric-value">${status === 'half_day' ? '-' : early}</span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-label">Overtime (hrs)</span>
                                <span class="metric-value">${parseFloat(ot).toFixed(2)}</span>
                            </div>
                        </div>
                        ${notes !== '-' ? `
                        <div class="notes-section">
                            <span class="notes-label">Notes:</span>
                            <span class="notes-value">${notes}</span>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
            const modal = document.getElementById('viewAttendanceModal');
            if (!modal) {
                console.error('viewAttendanceModal element not found');
                return;
            }
            console.log('Showing modal');
            modal.classList.add('show');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        function closeViewAttendanceModal() {
            const modal = document.getElementById('viewAttendanceModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }
        document.addEventListener('click', function(e){
            const tr = e.target.closest('tr.clickable-row');
            if (tr && !e.target.closest('button') && !e.target.closest('.status-badge')) {
                console.log('Opening modal for row:', tr);
                openViewAttendanceModal(tr);
            }
        });
        // Close on overlay click
        document.getElementById('viewAttendanceModal').addEventListener('click', function(e){
            if (e.target === this) closeViewAttendanceModal();
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