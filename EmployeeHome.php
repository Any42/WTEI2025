<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: Login.php");
    exit();
}

// Get employee information - FIXED SESSION VARIABLES
$employee_id = $_SESSION['employee_id']; // Changed from userid to employee_id

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Get employee details - Using employee_id from session
$emp_query = "SELECT * FROM empuser WHERE EmployeeID = ?";
$stmt = $conn->prepare($emp_query);
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$emp_result = $stmt->get_result();
$employee = $emp_result->fetch_assoc();

// Check if employee data was found
if (!$employee) {
    // Handle error - employee not found
    die("Employee data not found");
}

// Define default profile picture path
$default_profile_pic = 'img/default-avatar.png'; // Adjust path as needed
$profile_pic_path = $employee['profile_picture'] ?? $default_profile_pic;
if (empty($employee['profile_picture']) || !file_exists($employee['profile_picture'])) {
    $profile_pic_path = $default_profile_pic;
}

function getInitials($fullName) {
    $fullName = trim((string)$fullName);
    if ($fullName === '') return 'EM';
    $parts = preg_split('/\s+/', $fullName);
    $first = strtoupper(substr($parts[0] ?? '', 0, 1));
    $last = strtoupper(substr($parts[count($parts)-1] ?? '', 0, 1));
    $initials = $first . ($last !== '' ? $last : '');
    return $initials !== '' ? $initials : 'EM';
}
$emp_initials = getInitials($employee['EmployeeName'] ?? $employee['EmployeeUsername'] ?? 'Employee');

// Get attendance statistics - Fixed to use correct table structure
$attendance_query = "SELECT 
    COUNT(*) as total_days,
    COUNT(CASE WHEN status != 'late' OR status IS NULL THEN 1 END) as on_time,
    COUNT(CASE WHEN status = 'late' THEN 1 END) as late
    FROM attendance 
    WHERE EmployeeID = ? 
    AND attendance_type = 'present'
    AND MONTH(attendance_date) = MONTH(CURRENT_DATE())";
$stmt = $conn->prepare($attendance_query);
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$attendance_stats = $stmt->get_result()->fetch_assoc();

// Get absent records - days with no attendance since system started
$absent_query = "SELECT 
    attendance_date,
    'absent' as type,
    'No attendance recorded' as reason
    FROM attendance 
    WHERE EmployeeID = ? 
    AND attendance_type = 'absent'
    ORDER BY attendance_date DESC
    LIMIT 5";
$stmt = $conn->prepare($absent_query);
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$absent_records = $stmt->get_result();

// Get recent activities - Fixed to use correct attendance table structure
$activities_query = "SELECT 
    'attendance' as type,
    attendance_date,
    time_in,
    time_out,
    status,
    attendance_type,
    late_minutes,
    total_hours
    FROM attendance 
    WHERE EmployeeID = ?
    AND attendance_type = 'present'
    AND attendance_date IS NOT NULL
    ORDER BY attendance_date DESC, time_in DESC
    LIMIT 5";

$stmt = $conn->prepare($activities_query);
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$recent_activities = $stmt->get_result();

// Get absent count for this month
$absent_count_query = "SELECT COUNT(*) as absent_count FROM attendance WHERE EmployeeID = ? AND attendance_type = 'absent' AND MONTH(attendance_date) = MONTH(CURRENT_DATE())";
$stmt = $conn->prepare($absent_count_query);
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$absent_count_result = $stmt->get_result()->fetch_assoc();
$absent_count = $absent_count_result['absent_count'];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard - WTEI</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Base Styles */
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

    .page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    background-color: white;
    padding: 25px;
    border-radius: 16px;
    box-shadow: 0 4px 15px var(--shadow-color);
    border-bottom: 2px solid var(--border-color);
}

.page-title {
    font-size: 28px;
    color: var(--secondary-color);
    font-weight: 600;
}

/* Card Styles */
.card {
    background-color: white;
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 4px 15px var(--shadow-color);
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px var(--shadow-color);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--border-color);
}

.card-title {
    font-size: 18px;
    color: var(--secondary-color);
    font-weight: 600;
}

/* Button Styles */
.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}

.btn-primary {
    background-color: var(--secondary-color);
    color: white;
}

.btn-primary:hover {
    background-color: #1a252f;
    transform: translateY(-2px);
}

.btn-secondary {
    background-color: var(--primary-color);
    color: var(--secondary-color);
}

.btn-secondary:hover {
    background-color: #6BC4E4;
    transform: translateY(-2px);
}

/* Stats Container Styles */
.stats-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-box {
    background: white;
    border-radius: 16px;
    padding: 25px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 4px 15px var(--shadow-color);
    transition: all 0.3s ease;
}

.stat-box:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px var(--shadow-color);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.stat-icon.attendance {
    background-color: var(--primary-color);
    color: var(--secondary-color);
}

.stat-icon.hours {
    background-color: var(--accent-color);
    color: var(--secondary-color);
}

.stat-icon.percentage {
    background-color: #E7D5FF;
    color: var(--secondary-color);
}

.stat-icon.absent {
    background-color: #FFEBEE;
    color: #D32F2F;
}

.stat-info {
    flex: 1;
}

.stat-value {
    display: block;
    font-size: 28px;
    font-weight: 600;
    color: var(--secondary-color);
    line-height: 1.2;
}

.stat-label {
    color: #666;
    font-size: 14px;
    margin-top: 5px;
}

.stat-link {
    text-decoration: none;
    color: inherit;
    display: block;
    transition: all 0.3s ease;
}

.stat-link:hover {
    transform: scale(1.05);
}

.stat-link .stat-value {
    color: var(--secondary-color);
    font-weight: 600;
}

.stat-link:hover .stat-value {
    color: var(--primary-color);
}

.stat-details {
    margin-top: 10px;
    display: flex;
    gap: 15px;
}

.detail-item {
    font-size: 12px;
    padding: 4px 8px;
    border-radius: 12px;
}

.detail-item.success {
    background-color: var(--accent-color);
    color: #2E7D32;
}

.detail-item.warning {
    background-color: #FFF3E0;
    color: #F57C00;
}

.detail-item.absent {
    background-color: #FFEBEE;
    color: #D32F2F;
}

/* Table Styles */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    width: 100%;
}

table {
    width: 100%;
    min-width: 600px; /* Prevent table content from becoming too cramped */
    border-collapse: separate;
    border-spacing: 0;
}

th, td {
    padding: 16px;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

th {
    background-color: var(--background-color);
    color: var(--secondary-color);
    font-weight: 600;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

tr:hover {
    background-color: var(--background-color);
}

/* Status Badge Styles */
.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
    word-break: keep-all;
    overflow-wrap: normal;
}

.status-success {
    background-color: var(--accent-color);
    color: #2E7D32;
}

.status-warning {
    background-color: #FFF3E0;
    color: #F57C00;
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

/* Quick Actions Styles */
.quick-actions {
    background-color: var(--background-color);
    border: 2px solid var(--accent-color);
    border-radius: 16px;
    padding: 25px;
}

.quick-actions .card-header {
    margin-bottom: 25px;
    border-bottom: 2px solid var(--accent-color);
    padding-bottom: 15px;
}

.quick-actions .card-header h2 {
    color: var(--primary-color);
    font-size: 20px;
    font-weight: 600;
}

.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    padding: 10px;
}

.quick-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 25px 20px;
    background-color: var(--background-color);
    border: 2px solid var(--accent-color);
    border-radius: 12px;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px var(--shadow-color);
}

.quick-action-btn:hover {
    transform: translateY(-3px);
    border-color: var(--secondary-color);
    background-color: var(--accent-color);
    box-shadow: 0 4px 12px var(--shadow-color);
}

.quick-action-icon {
    width: 60px;
    height: 60px;
    background-color: var(--background-color);
    border: 2px solid var(--accent-color);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 15px;
    transition: all 0.3s ease;
}

.quick-action-icon i {
    font-size: 24px;
    color: var(--secondary-color);
    transition: all 0.3s ease;
}

.quick-action-btn:hover .quick-action-icon {
    background-color: var(--secondary-color);
    border-color: var(--background-color);
}

.quick-action-btn:hover .quick-action-icon i {
    color: var(--background-color);
}

.quick-action-text {
    text-align: center;
    margin-top: 10px;
}

.quick-action-text span {
    color: var(--primary-color);
    font-size: 16px;
    font-weight: 500;
}

/* Responsive Design */
@media (max-width: 768px) {
    .sidebar {
        width: 70px;
    }
    
    .sidebar .logo span,
    .sidebar .menu-item span {
        display: none;
    }
    
    .sidebar .menu-item {
        padding: 15px;
        justify-content: center;
    }
    
    .sidebar .menu-item i {
        margin: 0;
    }
    
    .main-content {
        margin-left: 70px;
    }
    
    .stats-container {
        grid-template-columns: 1fr;
    }
    
    .page-header {
        flex-direction: column;
        gap: 15px;
    }
    
    .quick-actions-grid {
        grid-template-columns: 1fr;
    }
    
    .quick-action-btn {
        padding: 20px;
    }
}

/* Enhanced Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(8px);
    animation: fadeIn 0.3s ease-out;
}

.modal-content {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    margin: 5% auto;
    padding: 0;
    border: none;
    border-radius: 20px;
    width: 90%;
    max-width: 900px;
    max-height: 80vh;
    position: relative;
    box-shadow: 0 20px 60px rgba(17, 45, 78, 0.15);
    animation: slideIn 0.3s ease-out;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.modal-header {
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
    color: white;
    padding: 25px 30px;
    margin: 0;
    border-bottom: none;
    position: relative;
}

.modal-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #3F72AF, #DBE2EF, #3F72AF);
}

.modal-header h2 {
    color: white;
    font-size: 26px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.modal-header h2::before {
    content: '\f007';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    font-size: 20px;
    opacity: 0.9;
}

.close {
    color: white;
    font-size: 32px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
    position: absolute;
    right: 25px;
    top: 50%;
    transform: translateY(-50%);
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
}

.close:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-50%) scale(1.1);
}

.modal-body {
    padding: 30px;
    background: white;
    flex: 1;
    overflow-y: auto;
    display: flex;
    gap: 30px;
}

/* Section Styles */
.info-section, .editable-section {
    margin-bottom: 0;
    flex: 1;
    min-width: 0;
}

.section-title {
    color: var(--primary-color);
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--accent-color);
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-section .section-title::before {
    content: '\f2bd';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    color: var(--secondary-color);
}

.editable-section .section-title::before {
    content: '\f044';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    color: var(--secondary-color);
}

.form-group {
    margin-bottom: 25px;
    position: relative;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: var(--primary-color);
    font-weight: 600;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Read-only Field Styles */
.readonly-field {
    width: 100%;
    padding: 15px 20px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 15px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    color: #64748b;
    cursor: not-allowed;
    position: relative;
    transition: all 0.3s ease;
}

.readonly-field::after {
    content: '\f023';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 14px;
}

/* Editable Field Styles */
.editable-field {
    width: 100%;
    padding: 15px 20px;
    border: 2px solid var(--accent-color);
    border-radius: 12px;
    font-size: 15px;
    background: white;
    color: var(--text-color);
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.editable-field:focus {
    outline: none;
    border-color: var(--secondary-color);
    box-shadow: 0 0 0 4px rgba(63, 114, 175, 0.1);
    transform: translateY(-1px);
}

.editable-field:hover {
    border-color: var(--secondary-color);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

/* Form Help Text */
.form-help {
    display: block;
    margin-top: 8px;
    color: #64748b;
    font-size: 13px;
    font-style: italic;
    display: flex;
    align-items: center;
    gap: 6px;
}

.form-help::before {
    content: '\f05a';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    color: var(--secondary-color);
    font-size: 12px;
}

/* Enhanced Form Actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    margin-top: 0;
    padding: 25px 30px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-top: 2px solid var(--accent-color);
    flex-shrink: 0;
}

/* Enhanced Button Styles */
.btn {
    padding: 14px 28px;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    position: relative;
    overflow: hidden;
}

.btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s;
}

.btn:hover::before {
    left: 100%;
}

.btn-primary {
    background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(63, 114, 175, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(63, 114, 175, 0.4);
}

.btn-secondary {
    background: linear-gradient(135deg, #64748b 0%, #475569 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(100, 116, 139, 0.3);
}

.btn-secondary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(100, 116, 139, 0.4);
}

/* Password Modal Specific Styles */
.password-modal {
    max-width: 600px;
}

.password-section {
    width: 100%;
}

/* Password Input Container */
.password-input-container {
    position: relative;
    display: flex;
    align-items: center;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
}

.password-input-container:focus-within {
    box-shadow: 0 4px 12px rgba(63, 114, 175, 0.15);
    transform: translateY(-1px);
}

.password-field {
    padding-right: 50px !important;
    width: 100%;
    padding: 15px 20px;
    border: 2px solid var(--accent-color);
    border-radius: 12px;
    font-size: 15px;
    background: white;
    color: var(--text-color);
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.password-field:focus {
    outline: none;
    border-color: var(--secondary-color);
    box-shadow: 0 0 0 4px rgba(63, 114, 175, 0.1);
    transform: translateY(-1px);
}

.password-field:hover {
    border-color: var(--secondary-color);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.password-toggle {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid var(--accent-color);
    color: #64748b;
    cursor: pointer;
    padding: 8px;
    border-radius: 8px;
    transition: all 0.3s ease;
    z-index: 10;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.password-toggle:hover {
    color: var(--secondary-color);
    background-color: var(--secondary-color);
    color: white;
    border-color: var(--secondary-color);
    transform: translateY(-50%) scale(1.05);
    box-shadow: 0 4px 8px rgba(63, 114, 175, 0.3);
}

.password-toggle:active {
    transform: translateY(-50%) scale(0.95);
}

.password-toggle i {
    font-size: 14px;
    transition: all 0.3s ease;
}

/* Password Strength Indicator */
.password-strength {
    margin-top: 10px;
}

.strength-bar {
    width: 100%;
    height: 4px;
    background-color: #e2e8f0;
    border-radius: 2px;
    overflow: hidden;
    margin-bottom: 8px;
}

.strength-fill {
    height: 100%;
    width: 0%;
    transition: all 0.3s ease;
    border-radius: 2px;
}

.strength-fill.weak {
    width: 25%;
    background-color: #ef4444;
}

.strength-fill.fair {
    width: 50%;
    background-color: #f59e0b;
}

.strength-fill.good {
    width: 75%;
    background-color: #3b82f6;
}

.strength-fill.strong {
    width: 100%;
    background-color: #10b981;
}

.strength-text {
    font-size: 12px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 5px;
}

.strength-text.weak {
    color: #ef4444;
}

.strength-text.fair {
    color: #f59e0b;
}

.strength-text.good {
    color: #3b82f6;
}

.strength-text.strong {
    color: #10b981;
}

/* Password Match Indicator */
.password-match {
    margin-top: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.match-icon {
    font-size: 14px;
    transition: all 0.3s ease;
}

.match-icon.match {
    color: #10b981;
}

.match-icon.no-match {
    color: #ef4444;
}

.match-icon.neutral {
    color: #64748b;
}

.match-text {
    font-size: 12px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.match-text.match {
    color: #10b981;
}

.match-text.no-match {
    color: #ef4444;
}

.match-text.neutral {
    color: #64748b;
}

/* Submit Button States */
#submit-password-btn:disabled {
    background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
    cursor: not-allowed;
    opacity: 0.6;
}

#submit-password-btn:disabled:hover {
    transform: none;
    box-shadow: 0 4px 15px rgba(100, 116, 139, 0.3);
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideIn {
    from { 
        opacity: 0;
        transform: translateY(-50px) scale(0.95);
    }
    to { 
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.match-icon.match {
    animation: pulse 0.6s ease-in-out;
}

.quick-action-container {
    margin: 20px 0;
    text-align: right;
}

.view-latest-payslip {
    padding: 15px 30px;
    font-size: 16px;
    background-color: var(--primary-color);
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.view-latest-payslip:hover {
    background-color: var(--secondary-color);
    transform: translateY(-2px);
}

.payslip-header {
    text-align: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid var(--accent-color);
}

.payslip-body {
    padding: 20px;
}

.employee-info {
    margin-bottom: 30px;
}

.info-group {
    display: flex;
    margin-bottom: 10px;
}

.info-group label {
    width: 120px;
    font-weight: 600;
    color: var(--primary-color);
}

.salary-breakdown {
    border: 1px solid var(--accent-color);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
}

.breakdown-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid var(--accent-color);
}

.deductions-breakdown {
    margin-top: 20px;
}

.total-row {
    display: flex;
    justify-content: space-between;
    padding: 15px 0;
    margin-top: 20px;
    border-top: 2px solid var(--accent-color);
    font-weight: 600;
    font-size: 18px;
}

.payslip-footer {
    text-align: center;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 2px solid var(--accent-color);
    color: var(--text-color);
    font-size: 14px;
}

/* --- Responsive Design Adjustments --- */

/* Large Desktops (Optional - Adjust if needed) */
@media (min-width: 1400px) {
    .stats-container {
        grid-template-columns: repeat(4, 1fr); /* More columns on very large screens */
    }
    .quick-actions-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

/* Medium Desktops / Laptops */
@media (max-width: 1200px) {
    .sidebar {
        width: 220px;
    }
    .main-content {
        margin-left: 220px;
        width: calc(100% - 220px);
    }
    .stats-container {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }
    .page-title {
        font-size: 24px;
    }
    .stat-value {
        font-size: 24px;
    }
     .quick-actions-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
}

/* Tablets */
@media (max-width: 992px) {
    .sidebar {
        width: 80px; /* Collapse sidebar to icons */
    }
    .sidebar .logo span,
    .sidebar .menu-item span,
    .sidebar .logout-btn span {
        display: none; /* Hide text */
    }
    .sidebar .logo {
        padding: 20px 0;
        font-size: 20px; /* Adjust logo size if needed */
    }
    .sidebar .menu-item {
        padding: 15px;
        justify-content: center;
    }
    .sidebar .menu-item i {
        margin-right: 0;
    }
    .sidebar .logout-btn {
        margin: 20px 10px; /* Adjust margin */
        padding: 12px;
    }
    .sidebar .logout-btn i {
        margin-right: 0;
    }

    .main-content {
        margin-left: 80px;
        width: calc(100% - 80px);
        padding: 15px; /* Reduce padding */
    }

    .page-header {
        padding: 20px;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    .page-title {
        font-size: 22px;
    }

    .stats-container {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }
    .card {
        padding: 15px;
    }
    .stat-value {
        font-size: 22px;
    }
    .stat-icon {
        width: 50px;
        height: 50px;
    }
    .stat-icon i {
        font-size: 20px;
    }

    .quick-actions-grid {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }

}

/* Mobile Phones */
@media (max-width: 576px) {
    .sidebar {
        width: 100%; /* Make sidebar take full width */
        height: auto; /* Adjust height */
        position: relative; /* Change positioning */
        box-shadow: none;
        border-right: none;
        border-bottom: 2px solid var(--accent-color);
    }
    .sidebar .logo {
        text-align: left;
        padding: 15px 20px;
        border-bottom: none; /* Remove border */
    }
    .sidebar .menu {
        flex-direction: row; /* Horizontal menu */
        overflow-x: auto; /* Scrollable menu */
        padding: 0 10px;
        flex-grow: 0; /* Don't let menu grow */
    }
    .menu-item {
        padding: 10px 15px;
        margin-bottom: 0;
        flex-shrink: 0; /* Prevent items from shrinking */
    }
    .menu-item i {
        margin-right: 8px; /* Add back margin for icons */
    }
    .menu-item:hover, .menu-item.active {
        transform: none; /* Remove transform */
    }
    .sidebar .logout-btn {
        display: none; /* Hide logout button (consider adding to a profile dropdown?) */
    }

    .main-content {
        margin-left: 0; /* Remove margin */
        width: 100%;
        padding: 10px;
    }

    .page-header {
        padding: 15px;
        border-radius: 12px;
    }
    .page-title {
        font-size: 20px;
    }
    .header-actions {
        width: 100%;
    }
    .welcome-text {
        font-size: 13px;
    }

    .stats-container {
        grid-template-columns: 1fr; /* Single column */
        gap: 10px;
    }
    .stat-value {
        font-size: 20px;
    }

    .quick-actions-grid {
        grid-template-columns: 1fr; /* Single column */
        gap: 10px;
    }

    .modal-content {
        width: 95%;
        margin: 10px auto;
        padding: 0;
        max-height: 90vh;
    }
    .modal-header h2 {
        font-size: 18px;
    }
    .modal-body {
        padding: 20px;
        flex-direction: column;
        gap: 20px;
    }
    .info-section, .editable-section {
        flex: none;
    }
    
    .password-field {
        padding: 12px 16px;
        font-size: 14px;
    }
    
    .password-toggle {
        width: 32px;
        height: 32px;
        padding: 6px;
    }
    
    .password-toggle i {
        font-size: 12px;
    }

    th, td {
        padding: 12px 10px; /* Reduce table padding */
        font-size: 13px;
    }
}

/* --- Profile Dropdown Styles --- */
.profile-dropdown {
    position: relative;
    display: inline-block;
    margin-left: 10px; /* Add some space */
}

.profile-btn {
    background-color: transparent;
    color: var(--primary-color, #769FCD); /* HR text color */
    padding: 5px 10px;
    font-size: 14px;
    border: none;
    border-radius: 25px;
    cursor: pointer;
    display: flex;
    align-items: center;
    transition: background-color 0.2s ease;
}

.profile-btn:hover {
    background-color: var(--accent-color, #D6E6F2);
}

.profile-img-icon { display: none; }
.profile-initials-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    margin-right: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: var(--secondary-color);
    color: #fff;
    font-weight: 700;
    font-size: 12px;
    border: 1px solid var(--border-color, #DBE2EF);
}

.profile-btn span {
    margin-right: 5px;
    font-weight: 500;
    display: inline-block;
    max-width: 150px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.profile-btn .fa-caret-down {
    margin-left: 5px;
    font-size: 12px;
    transition: transform 0.2s ease;
}

.profile-dropdown:hover .profile-btn .fa-caret-down {
    transform: rotate(180deg);
}

.dropdown-content {
    display: none;
    position: absolute;
    background-color: white;
    min-width: 220px;
    box-shadow: 0px 5px 15px var(--shadow-color, rgba(0,0,0,0.1));
    z-index: 100;
    border-radius: 8px;
    right: 0;
    top: calc(100% + 5px);
    margin-top: 0;
    overflow: hidden;
    border: 1px solid #e0e0e0;
}

.profile-dropdown.active .dropdown-content {
    display: block;
    animation: fadeIn 0.15s ease-in-out;
}

.dropdown-content a {
    color: var(--text-color, #333);
    padding: 10px 15px;
    text-decoration: none;
    display: flex;
    align-items: center;
    font-size: 14px;
    transition: background-color 0.15s ease, color 0.15s ease;
    white-space: nowrap;
}

.dropdown-content a i {
    margin-right: 10px;
    width: 18px;
    text-align: center;
    color: var(--secondary-color, #B9D7EA);
    font-size: 15px;
}

.dropdown-content a:hover {
    background-color: var(--accent-color, #D6E6F2);
    color: var(--primary-color, #769FCD);
}

.dropdown-content a:hover i {
    color: var(--primary-color, #769FCD);
}


/* Ensure header actions container alignment */
.page-header .header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: nowrap;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-5px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Activity List Styles */
.activity-list {
    max-height: 400px;
    overflow-y: auto;
}

.activity-item {
    display: flex;
    align-items: flex-start;
    padding: 15px 0;
    border-bottom: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-item:hover {
    background-color: var(--background-color);
    border-radius: 8px;
    padding-left: 10px;
    padding-right: 10px;
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    flex-shrink: 0;
}

.activity-icon.attendance {
    background-color: var(--accent-color);
    color: var(--secondary-color);
}

.activity-details {
    flex: 1;
    min-width: 0;
}

.activity-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px;
}

.activity-type {
    font-weight: 600;
    color: var(--primary-color);
    font-size: 14px;
}

.activity-status {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.activity-status.success {
    background-color: #E8F5E8;
    color: #2E7D32;
}

.activity-status.warning {
    background-color: #FFF3E0;
    color: #F57C00;
}

.activity-time {
    color: #666;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 3px;
}

.activity-time-out {
    color: #888;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 5px;
    font-style: italic;
}

.activity-time-details {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #f0f0f0;
}

.time-entry {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
    font-size: 12px;
    color: #666;
}

.time-entry:last-child {
    margin-bottom: 0;
}

.time-entry i {
    width: 12px;
    text-align: center;
    color: var(--secondary-color);
}

.time-entry strong {
    color: var(--primary-color);
    font-weight: 600;
    min-width: 40px;
}

.no-activities {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.no-activities i {
    font-size: 48px;
    color: var(--accent-color);
    margin-bottom: 15px;
    display: block;
}

.no-activities p {
    font-size: 14px;
    margin: 0;
}

/* Absent Records Styles */
.absent-list {
    max-height: 400px;
    overflow-y: auto;
}

.absent-item {
    display: flex;
    align-items: flex-start;
    padding: 15px 0;
    border-bottom: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.absent-item:last-child {
    border-bottom: none;
}

.absent-item:hover {
    background-color: #FFF5F5;
    border-radius: 8px;
    padding-left: 10px;
    padding-right: 10px;
}

.absent-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    flex-shrink: 0;
    background-color: #FFEBEE;
    color: #D32F2F;
}

.absent-details {
    flex: 1;
    min-width: 0;
}

.absent-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px;
}

.absent-type {
    font-weight: 600;
    color: #D32F2F;
    font-size: 14px;
}

.absent-status {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    background-color: #FFEBEE;
    color: #D32F2F;
}

.absent-time {
    color: #666;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 3px;
}

.absent-reason {
    color: #888;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 5px;
    font-style: italic;
}

.no-absences {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.no-absences i {
    font-size: 48px;
    color: #4CAF50;
    margin-bottom: 15px;
    display: block;
}

.no-absences p {
    font-size: 14px;
    margin: 0;
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
    <img src="LOGO/newLogo_transparent.png" class="logo"  style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding:-250px; padding-bottom: 20px;">
        <nav class="menu">
            <a href="EmployeeHome.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'EmployeeHome.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="EmpAttendance.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'EmpAttendance.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-check"></i>
                <span>Attendance</span>
            </a>
            
            <a href="EmpPayroll.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'EmpPayroll.php' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payroll</span>
            </a>
            <a href="EmpHistory.php" class="menu-item">
                <i class="fas fa-history"></i> History
            </a>
            
            
        </nav>
        <a href="Logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div class="header-content">
                <h1>Welcome back, <?php echo htmlspecialchars($employee['EmployeeName'] ?? 'Employee'); ?>!</h1>
                <p class="subtitle"><?php echo htmlspecialchars($employee['Position'] ?? ''); ?> - <?php echo htmlspecialchars($employee['Department'] ?? ''); ?></p>
            </div>
            <div class="header-actions">
                <div class="profile-dropdown">
                    <button class="profile-btn">
                        <div class="profile-initials-avatar"><?php echo htmlspecialchars($emp_initials); ?></div>
                        <span><?php echo htmlspecialchars($employee['EmployeeName'] ?? 'Employee'); ?></span>
                        <i class="fas fa-caret-down"></i>
                    </button>
                    <div class="dropdown-content">
                        <a href="#" onclick="showProfileModal()"><i class="fas fa-user-edit"></i> Edit Profile</a>
                        <a href="#" onclick="showChangePasswordModal()"><i class="fas fa-key"></i> Change Password</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="stats-container">
            <div class="stat-box">
                <div class="stat-icon attendance">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo $attendance_stats['total_days']; ?></span>
                    <span class="stat-label">Days Present (Including Lates)</span>
                    <div class="stat-details">
                        <span class="detail-item success"><?php echo $attendance_stats['on_time']; ?> On Time</span>
                        <span class="detail-item warning"><?php echo $attendance_stats['late']; ?> Late</span>
                    </div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-icon hours">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-info">
                    <a href="EmpAttendance.php" class="stat-link">
                        <span class="stat-value">View</span>
                        <span class="stat-label">Attendance</span>
                    </a>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-icon percentage">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="stat-info">
                    <a href="EmpPayroll.php" class="stat-link">
                        <span class="stat-value">View</span>
                        <span class="stat-label">Payslip</span>
                    </a>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-icon absent">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo $absent_count; ?></span>
                    <span class="stat-label">Days Absent</span>
                    <div class="stat-details">
                        <span class="detail-item absent">This Month</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="card recent-activities">
                <div class="card-header">
                    <h2>Recent Activities</h2>
                </div>
                <div class="activity-list">
                    <?php 
                    $activity_count = 0;
                    while ($activity = $recent_activities->fetch_assoc()): 
                        $activity_count++;
                        $date = date('M d, Y', strtotime($activity['attendance_date']));
                        $time_in_display = $activity['time_in'] ? date('h:i A', strtotime($activity['time_in'])) : 'Not recorded';
                        $time_out_display = $activity['time_out'] ? date('h:i A', strtotime($activity['time_out'])) : 'Not recorded';
                        
                        // Determine status and styling
                        $status_text = '';
                        $status_class = '';
                        
                        if ($activity['status'] == 'late') {
                            $status_text = 'Late';
                            if ($activity['late_minutes'] > 0) {
                                $status_text .= ' (' . $activity['late_minutes'] . ' min)';
                            }
                            $status_class = 'warning';
                        } elseif ($activity['status'] == 'early') {
                            $status_text = 'Early Out';
                            $status_class = 'warning';
                        } else {
                            $status_text = 'On Time';
                            $status_class = 'success';
                        }
                        
                        // Calculate total hours if available
                        $total_hours_display = '';
                        if ($activity['total_hours'] > 0) {
                            $total_hours_display = ' (' . number_format($activity['total_hours'], 1) . ' hrs)';
                        }
                    ?>
                        <div class="activity-item">
                            <div class="activity-icon attendance">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="activity-details">
                                <div class="activity-header">
                                    <span class="activity-type">Attendance</span>
                                    <span class="activity-status <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </div>
                                <div class="activity-time">
                                    <i class="fas fa-calendar-day"></i>
                                    <?php echo $date; ?>
                                </div>
                                <div class="activity-time-details">
                                    <div class="time-entry">
                                        <i class="fas fa-sign-in-alt"></i>
                                        <strong>In:</strong> <?php echo $time_in_display; ?>
                                    </div>
                                    <div class="time-entry">
                                        <i class="fas fa-sign-out-alt"></i>
                                        <strong>Out:</strong> <?php echo $time_out_display; ?>
                                    </div>
                                    <?php if ($total_hours_display): ?>
                                        <div class="time-entry">
                                            <i class="fas fa-hourglass-half"></i>
                                            <strong>Total:</strong> <?php echo $total_hours_display; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                    
                    <?php if ($activity_count == 0): ?>
                        <div class="no-activities">
                            <i class="fas fa-info-circle"></i>
                            <p>No recent attendance records found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card absent-records">
                <div class="card-header">
                    <h2>Recent Absences</h2>
                </div>
                <div class="absent-list">
                    <?php 
                    $absent_count = 0;
                    while ($absent = $absent_records->fetch_assoc()): 
                        $absent_count++;
                        $date = date('M d, Y', strtotime($absent['attendance_date']));
                        $day_name = date('l', strtotime($absent['attendance_date']));
                    ?>
                        <div class="absent-item">
                            <div class="absent-icon">
                                <i class="fas fa-user-times"></i>
                            </div>
                            <div class="absent-details">
                                <div class="absent-header">
                                    <span class="absent-type">Absent</span>
                                    <span class="absent-status">No Attendance</span>
                                </div>
                                <div class="absent-time">
                                    <i class="fas fa-calendar-day"></i>
                                    <?php echo $day_name . ', ' . $date; ?>
                                </div>
                                <div class="absent-reason">
                                    <i class="fas fa-info-circle"></i>
                                    <?php echo $absent['reason']; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                    
                    <?php if ($absent_count == 0): ?>
                        <div class="no-absences">
                            <i class="fas fa-check-circle"></i>
                            <p>No recent absences recorded.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

<!-- Profile Modals -->
<!-- Edit Profile Modal -->
<div id="editProfileModal" class="modal profile-modal">
    <div class="modal-content">
        <span class="close" onclick="closeEditProfileModal()">&times;</span>
        <div class="modal-header">
            <h2>Edit Profile</h2>
        </div>
        <form id="editProfileForm" method="POST" action="update_employee_profile.php">
            <div class="modal-body">
                <!-- Read-only Information Section -->
                <div class="info-section">
                    <h3 class="section-title">Employee Information</h3>
                    <div class="form-group">
                        <label for="emp_name">Full Name</label>
                        <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($employee['EmployeeName'] ?? ''); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="emp_contact">Contact</label>
                        <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($employee['Contact'] ?? ''); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($employee['Department'] ?? ''); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Position</label>
                        <input type="text" class="form-control readonly-field" value="<?php echo htmlspecialchars($employee['Position'] ?? ''); ?>" readonly>
                    </div>
                </div>

                <!-- Editable Information Section -->
                <div class="editable-section">
                    <h3 class="section-title">Editable Information</h3>
                    <div class="form-group">
                        <label for="emp_email">Email Address</label>
                        <input type="email" name="employee_email" id="emp_email" class="form-control editable-field" value="<?php echo htmlspecialchars($employee['EmployeeEmail'] ?? ''); ?>" required>
                        <small class="form-help">You can update your email address for notifications</small>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                 <button type="button" class="btn btn-secondary" onclick="closeEditProfileModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Change Password Modal -->
<div id="changePasswordModal" class="modal profile-modal">
    <div class="modal-content password-modal">
        <span class="close" onclick="closeChangePasswordModal()">&times;</span>
        <div class="modal-header">
            <h2>Change Password</h2>
        </div>
        <form id="changePasswordForm" method="POST" action="change_employee_password.php">
            <div class="modal-body">
                <div class="password-section">
                    <h3 class="section-title">Password Information</h3>
                    
                    <div class="form-group">
                        <label for="current_emp_password">Current Password</label>
                        <div class="password-input-container">
                            <input type="password" name="current_password" id="current_emp_password" class="form-control password-field" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('current_emp_password')">
                                <i class="fas fa-eye" id="current_emp_password_icon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_emp_password">New Password</label>
                        <div class="password-input-container">
                            <input type="password" name="new_password" id="new_emp_password" class="form-control password-field" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('new_emp_password')">
                                <i class="fas fa-eye" id="new_emp_password_icon"></i>
                            </button>
                        </div>
                        <div class="password-strength" id="password-strength">
                            <div class="strength-bar">
                                <div class="strength-fill" id="strength-fill"></div>
                            </div>
                            <span class="strength-text" id="strength-text">Enter a password</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_emp_password">Confirm New Password</label>
                        <div class="password-input-container">
                            <input type="password" name="confirm_password" id="confirm_emp_password" class="form-control password-field" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('confirm_emp_password')">
                                <i class="fas fa-eye" id="confirm_emp_password_icon"></i>
                            </button>
                        </div>
                        <div class="password-match" id="password-match">
                            <i class="fas fa-check-circle match-icon" id="match-icon"></i>
                            <span class="match-text" id="match-text">Passwords must match</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeChangePasswordModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submit-password-btn" disabled>
                    <i class="fas fa-key"></i> Update Password
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Removed Change Profile Picture Modal -->

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
    
    // ---- Modal Control Functions ----
    const editProfileModal_emp = document.getElementById('editProfileModal');
    const changePasswordModal_emp = document.getElementById('changePasswordModal');
    // removed change picture modal

    function showProfileModal() {
        if(editProfileModal_emp) editProfileModal_emp.style.display = 'block';
    }
    function closeEditProfileModal() {
        if(editProfileModal_emp) editProfileModal_emp.style.display = 'none';
    }

    function showChangePasswordModal() {
        if(changePasswordModal_emp) changePasswordModal_emp.style.display = 'block';
    }
    function closeChangePasswordModal() {
        if(changePasswordModal_emp) changePasswordModal_emp.style.display = 'none';
        const form = document.getElementById('changePasswordForm');
        if(form) form.reset();
    }

    // removed change picture handlers

    // --- Click-to-Toggle Dropdown (Employee) --- 
    const profileDropdownButton_emp = document.querySelector('.profile-dropdown .profile-btn');
    const profileDropdown_emp = document.querySelector('.profile-dropdown');

    if (profileDropdownButton_emp && profileDropdown_emp) {
        profileDropdownButton_emp.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            profileDropdown_emp.classList.toggle('active');
        });
    }

    // Close dropdown when clicking outside (combined with modal closing)
    document.addEventListener('click', function(event) {
        // Close Employee Dropdown
        if (profileDropdown_emp && profileDropdown_emp.classList.contains('active')) {
            if (!profileDropdown_emp.contains(event.target)) {
                profileDropdown_emp.classList.remove('active');
            }
        }
        // Close Modals (existing logic)
        if (event.target == editProfileModal_emp) {
            closeEditProfileModal();
        }
        if (event.target == changePasswordModal_emp) {
            closeChangePasswordModal();
        }
    });
    // --- End Click-to-Toggle --- 

     // Enhanced Password Form Functionality
     const cpFormEmp = document.getElementById('changePasswordForm');
     const newPasswordField = document.getElementById('new_emp_password');
     const confirmPasswordField = document.getElementById('confirm_emp_password');
     const submitBtn = document.getElementById('submit-password-btn');
     
     // Password toggle functionality
     function togglePassword(fieldId) {
         const field = document.getElementById(fieldId);
         const icon = document.getElementById(fieldId + '_icon');
         
         if (field.type === 'password') {
             field.type = 'text';
             icon.classList.remove('fa-eye');
             icon.classList.add('fa-eye-slash');
         } else {
             field.type = 'password';
             icon.classList.remove('fa-eye-slash');
             icon.classList.add('fa-eye');
         }
     }
     
     // Password strength checker
     function checkPasswordStrength(password) {
         let score = 0;
         let feedback = '';
         
         if (password.length >= 8) score++;
         if (password.match(/[a-z]/)) score++;
         if (password.match(/[A-Z]/)) score++;
         if (password.match(/[0-9]/)) score++;
         if (password.match(/[^a-zA-Z0-9]/)) score++;
         
         const strengthFill = document.getElementById('strength-fill');
         const strengthText = document.getElementById('strength-text');
         
         // Remove all classes first
         strengthFill.className = 'strength-fill';
         strengthText.className = 'strength-text';
         
         if (password.length === 0) {
             strengthText.textContent = 'Enter a password';
             strengthText.classList.add('neutral');
         } else if (score <= 2) {
             strengthFill.classList.add('weak');
             strengthText.textContent = 'Weak password';
             strengthText.classList.add('weak');
         } else if (score === 3) {
             strengthFill.classList.add('fair');
             strengthText.textContent = 'Fair password';
             strengthText.classList.add('fair');
         } else if (score === 4) {
             strengthFill.classList.add('good');
             strengthText.textContent = 'Good password';
             strengthText.classList.add('good');
         } else {
             strengthFill.classList.add('strong');
             strengthText.textContent = 'Strong password';
             strengthText.classList.add('strong');
         }
         
         return score >= 3; // Minimum requirement for good password
     }
     
     // Password match checker
     function checkPasswordMatch() {
         const newPassword = newPasswordField.value;
         const confirmPassword = confirmPasswordField.value;
         const matchIcon = document.getElementById('match-icon');
         const matchText = document.getElementById('match-text');
         
         // Remove all classes first
         matchIcon.className = 'fas fa-check-circle match-icon';
         matchText.className = 'match-text';
         
         if (confirmPassword.length === 0) {
             matchText.textContent = 'Passwords must match';
             matchIcon.classList.add('neutral');
             matchText.classList.add('neutral');
         } else if (newPassword === confirmPassword) {
             matchText.textContent = 'Passwords match';
             matchIcon.classList.add('match');
             matchText.classList.add('match');
         } else {
             matchText.textContent = 'Passwords do not match';
             matchIcon.classList.add('no-match');
             matchText.classList.add('no-match');
         }
         
         return newPassword === confirmPassword && newPassword.length > 0;
     }
     
     // Update submit button state
     function updateSubmitButton() {
         const currentPassword = document.getElementById('current_emp_password').value;
         const newPassword = newPasswordField.value;
         const isStrongPassword = checkPasswordStrength(newPassword);
         const passwordsMatch = checkPasswordMatch();
         
         if (currentPassword.length > 0 && newPassword.length > 0 && isStrongPassword && passwordsMatch) {
             submitBtn.disabled = false;
         } else {
             submitBtn.disabled = true;
         }
     }
     
     // Event listeners
     if (newPasswordField) {
         newPasswordField.addEventListener('input', function() {
             checkPasswordStrength(this.value);
             checkPasswordMatch();
             updateSubmitButton();
         });
     }
     
     if (confirmPasswordField) {
         confirmPasswordField.addEventListener('input', function() {
             checkPasswordMatch();
             updateSubmitButton();
         });
     }
     
     if (document.getElementById('current_emp_password')) {
         document.getElementById('current_emp_password').addEventListener('input', updateSubmitButton);
     }
     
     // Form submission validation
     if(cpFormEmp) {
         cpFormEmp.onsubmit = function(e) {
             const currentPassword = document.getElementById('current_emp_password').value;
             const newPassword = newPasswordField.value;
             const confirmPassword = confirmPasswordField.value;
             
             if (!currentPassword) {
                 e.preventDefault();
                 alert('Please enter your current password!');
                 return false;
             }
             
             if (newPassword !== confirmPassword) {
                 e.preventDefault();
                 alert('New passwords do not match!');
                 return false;
             }
             
             if (!checkPasswordStrength(newPassword)) {
                 e.preventDefault();
                 alert('Password is too weak. Please choose a stronger password!');
                 return false;
             }
             
             return true;
         }
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