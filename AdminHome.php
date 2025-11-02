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

// Initialize dashboard statistics
$totalEmployees = 0;
$presentToday = 0;
$absentToday = 0;
$lateToday = 0;
$monthlyPayroll = 0;
$recentEmployees = [];
$notifications_admin = [];

// Get total employees
$query = "SELECT COUNT(*) as total FROM empuser WHERE Status = 'active'";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $totalEmployees = $row['total'];
}

// Check if attendance table exists and get today's attendance statistics
$tableCheck = $conn->query("SHOW TABLES LIKE 'attendance'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $today = date('Y-m-d');
    
    // Get attendance statistics for today using prepared statements
    // Count distinct employees to avoid duplicates
    $today_stats_query = "SELECT
        COUNT(DISTINCT CASE WHEN attendance_type = 'present' THEN EmployeeID END) as present_count,
        COUNT(DISTINCT CASE WHEN attendance_type = 'absent' THEN EmployeeID END) as absent_count,
        COUNT(DISTINCT CASE WHEN status = 'late' THEN EmployeeID END) as late_count
        FROM attendance
        WHERE DATE(attendance_date) = ?";
    
    $stmt_today_stats = $conn->prepare($today_stats_query);
    if ($stmt_today_stats) {
        $stmt_today_stats->bind_param("s", $today);
        $stmt_today_stats->execute();
        $stats_result = $stmt_today_stats->get_result();
        $stats = $stats_result->fetch_assoc();
        $stmt_today_stats->close();
        
        $presentToday = (int)($stats['present_count'] ?? 0);
        $absentToday = (int)($stats['absent_count'] ?? 0);
        $lateToday = (int)($stats['late_count'] ?? 0);
    }
}

// Get recent attendance records (last 5)
$recentAttendance = [];
$attendance_query = "SELECT a.*, e.EmployeeName, e.Department 
                    FROM attendance a 
                    JOIN empuser e ON a.EmployeeID = e.EmployeeID 
                    ORDER BY a.attendance_date DESC, a.id DESC 
                    LIMIT 5";
$attendance_result = $conn->query($attendance_query);
if ($attendance_result && $attendance_result->num_rows > 0) {
    while ($row = $attendance_result->fetch_assoc()) {
        $recentAttendance[] = $row;
    }
}

// Check if payroll table exists before querying
$tableCheck = $conn->query("SHOW TABLES LIKE 'payroll'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    // Calculate monthly payroll total
    $currentMonth = date('m');
    $currentYear = date('Y');
    $query = "SELECT SUM(net_pay) as total FROM payroll WHERE MONTH(payment_date) = ? AND YEAR(payment_date) = ?";
    $stmt_payroll = $conn->prepare($query);
    if ($stmt_payroll) {
        $stmt_payroll->bind_param("ii", $currentMonth, $currentYear);
        $stmt_payroll->execute();
        $result = $stmt_payroll->get_result();
        $row = $result->fetch_assoc();
        $monthlyPayroll = $row['total'] ? $row['total'] : 0;
        $stmt_payroll->close();
    }
}

// Get recent employees
$query = "SELECT EmployeeID, EmployeeName, Department, Position 
          FROM empuser 
          WHERE Status = 'active'
          ORDER BY EmployeeID DESC LIMIT 5";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $recentEmployees[] = $row;
    }
}

// Fetch Notifications Data (Limit to 10 recent)
$notifications_admin = [];

// Get recent attendance records
$attendance_notifications = [];
$attendance_query = "SELECT a.*, e.EmployeeName, e.Department 
                    FROM attendance a 
                    JOIN empuser e ON a.EmployeeID = e.EmployeeID 
                    ORDER BY a.attendance_date DESC, a.id DESC 
                    LIMIT 5";
$attendance_result = $conn->query($attendance_query);
if ($attendance_result && $attendance_result->num_rows > 0) {
    while ($row = $attendance_result->fetch_assoc()) {
        $attendance_notifications[] = [
            'type' => 'attendance',
            'id' => $row['id'],
            'EmployeeName' => $row['EmployeeName'],
            'status' => $row['status'],
            'timestamp' => $row['attendance_date'],
            'time_in' => $row['time_in'],
            'time_out' => $row['time_out'],
            'notes' => $row['notes'],
            'amount' => null
        ];
    }
}

// Get recent employees
$employee_notifications = [];
$employee_query = "SELECT EmployeeID, EmployeeName, Department, created_at 
                  FROM empuser 
                  WHERE Status = 'active'
                  ORDER BY created_at DESC 
                  LIMIT 3";
$employee_result = $conn->query($employee_query);
if ($employee_result && $employee_result->num_rows > 0) {
    while ($row = $employee_result->fetch_assoc()) {
        $employee_notifications[] = [
            'type' => 'employee',
            'id' => $row['EmployeeID'],
            'EmployeeName' => $row['EmployeeName'],
            'status' => 'active',
            'timestamp' => $row['created_at'],
            'time_in' => null,
            'time_out' => null,
            'notes' => null,
            'amount' => null
        ];
    }
}

// Get recent payroll records
$payroll_notifications = [];
$payroll_query = "SELECT p.*, e.EmployeeName, e.Department 
                 FROM payroll p 
                 JOIN empuser e ON p.EmployeeID = e.EmployeeID 
                 ORDER BY p.payment_date DESC, p.id DESC 
                 LIMIT 2";
$payroll_result = $conn->query($payroll_query);
if ($payroll_result && $payroll_result->num_rows > 0) {
    while ($row = $payroll_result->fetch_assoc()) {
        $payroll_notifications[] = [
            'type' => 'payroll',
            'id' => $row['id'],
            'EmployeeName' => $row['EmployeeName'],
            'status' => 'generated',
            'timestamp' => $row['payment_date'],
            'time_in' => null,
            'time_out' => null,
            'notes' => $row['description'],
            'amount' => $row['net_pay']
        ];
    }
}

// Combine all notifications
$notifications_admin = array_merge($attendance_notifications, $employee_notifications, $payroll_notifications);

// Sort by timestamp
usort($notifications_admin, function($a, $b) {
    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
});

// Limit to 10 most recent
$notifications_admin = array_slice($notifications_admin, 0, 10);

// Debug: Log notification count
error_log("Admin notifications count: " . count($notifications_admin));

// Notifications data is now populated above

// Get user data
$user_id = $_SESSION['userid'];
$user_data = [];
$query = "SELECT * FROM adminuser WHERE AdminID = ?";
$stmt_user = $conn->prepare($query);
if ($stmt_user) {
    $stmt_user->bind_param("s", $user_id);
    $stmt_user->execute();
    $result = $stmt_user->get_result();
    if ($result && $result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
    }
    $stmt_user->close();
}

// Define default profile picture path
$default_profile_pic = 'img/default-avatar.png';
// Profile display: use initials-based avatar rather than image
$profile_pic_path = $user_data['profile_picture'] ?? $default_profile_pic; // retained for backward-compat
if (empty($user_data['profile_picture']) || !file_exists($user_data['profile_picture'])) {
    $profile_pic_path = $default_profile_pic;
}

function getInitials($fullName) {
    $fullName = trim((string)$fullName);
    if ($fullName === '') return 'U';
    $parts = preg_split('/\s+/', $fullName);
    $first = strtoupper(substr($parts[0] ?? '', 0, 1));
    $last = strtoupper(substr($parts[count($parts)-1] ?? '', 0, 1));
    $initials = $first . ($last !== '' ? $last : '');
    return $initials !== '' ? $initials : 'U';
}
$admin_initials = getInitials($user_data['AdminName'] ?? ($user_data['AdminUsername'] ?? 'User'));

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $admin_id = $_SESSION['userid'];
    $admin_name = $conn->real_escape_string($_POST['admin_name']);
    $admin_email = $conn->real_escape_string($_POST['admin_email']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Verify current password
    $check_query = "SELECT AdminPassword FROM adminuser WHERE AdminID = ?";
    $stmt_check = $conn->prepare($check_query);
    if ($stmt_check) {
        $stmt_check->bind_param("s", $admin_id);
        $stmt_check->execute();
        $result = $stmt_check->get_result();
        if ($result && $result->num_rows > 0) {
            $admin = $result->fetch_assoc();
            if (password_verify($current_password, $admin['AdminPassword'])) {
                // Update basic info
                $update_query = "UPDATE adminuser SET AdminName = ?, AdminEmail = ? WHERE AdminID = ?";
                $stmt_update = $conn->prepare($update_query);
                if ($stmt_update) {
                    $stmt_update->bind_param("sss", $admin_name, $admin_email, $admin_id);
                    if ($stmt_update->execute()) {
                        $_SESSION['success'] = "Profile updated successfully!";
                    } else {
                        $_SESSION['error'] = "Error updating profile: " . $conn->error;
                    }
                    $stmt_update->close();
                }

                // Update password if provided
                if (!empty($new_password) && $new_password === $confirm_password) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $password_query = "UPDATE adminuser SET AdminPassword = ? WHERE AdminID = ?";
                    $stmt_password = $conn->prepare($password_query);
                    if ($stmt_password) {
                        $stmt_password->bind_param("ss", $hashed_password, $admin_id);
                        if ($stmt_password->execute()) {
                            $_SESSION['success'] = "Profile and password updated successfully!";
                        } else {
                            $_SESSION['error'] = "Error updating password: " . $conn->error;
                        }
                        $stmt_password->close();
                    }
                }
            } else {
                $_SESSION['error'] = "Current password is incorrect!";
            }
        }
        $stmt_check->close();
    }
    
    // Redirect to refresh the page
    header("Location: AdminHome.php");
    exit;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - WTEI</title>
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
            transition: all 0.3s ease;
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
            border-bottom: 2px solid #DBE2EF;
        }
        
        .header h1 {
            font-size: 28px;
            color: #112D4E;
            font-weight: 600;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: nowrap;
        }
        
       
        
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
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

        .notification-badge {
            background: linear-gradient(135deg, #E91E63, #C2185B);
            color: white;
            border-radius: 50%;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 8px;
            min-width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(233, 30, 99, 0.3);
            animation: pulse-badge 2s infinite;
            transition: all 0.3s ease;
            position: relative;
            z-index: 10;
        }

        .notification-badge.hidden {
            opacity: 0;
            transform: scale(0);
            pointer-events: none;
        }

        .notification-badge:not(.hidden) {
            opacity: 1;
            transform: scale(1);
            animation: badge-appear 0.5s ease-out, pulse-badge 2s infinite 0.5s;
        }

        @keyframes badge-appear {
            0% { 
                opacity: 0;
                transform: scale(0) rotate(-180deg);
            }
            50% { 
                opacity: 1;
                transform: scale(1.2) rotate(-90deg);
            }
            100% { 
                opacity: 1;
                transform: scale(1) rotate(0deg);
            }
        }

        @keyframes pulse-badge {
            0% { 
                transform: scale(1);
                box-shadow: 0 2px 8px rgba(233, 30, 99, 0.3);
            }
            50% { 
                transform: scale(1.1);
                box-shadow: 0 4px 12px rgba(233, 30, 99, 0.5);
            }
            100% { 
                transform: scale(1);
                box-shadow: 0 2px 8px rgba(233, 30, 99, 0.3);
            }
        }

        /* Dashboard Cards Styles */
        .dashboard-cards {
            display: grid;
            align-items: center;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .card {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8FBFF 100%);
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 8px 32px rgba(17, 45, 78, 0.08);
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(219, 226, 239, 0.3);
            cursor: default;
        }

        .clickable-card {
            cursor: pointer;
        }

        .clickable-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(17, 45, 78, 0.15);
            border-color: #3F72AF;
        }

        .clickable-card:active {
            transform: translateY(-4px) scale(1.01);
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, #3F72AF, #112D4E);
            border-radius: 20px 20px 0 0;
            background-size: 200% 100%;
            animation: shimmer 3s infinite;
        }

        .clickable-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(63, 114, 175, 0.05) 0%, rgba(17, 45, 78, 0.02) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 20px;
            z-index: 1;
        }

        .clickable-card:hover::after {
            opacity: 1;
        }

        .card-content {
            position: relative;
            z-index: 1;
        }

        .card-title {
            font-size: 13px;
            color: #345;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .card-value {
            font-size: 30px;
            font-weight: 700;
            color: #112D4E;
            margin-bottom: 8px;
        }

        .card-subtitle {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .card-icon {
            position: absolute;
            right: 20px;
            bottom: 20px;
            font-size: 48px;
            color: rgba(63, 114, 175, 0.12);
            transition: all 0.3s ease;
        }

        .clickable-card:hover .card-icon {
            color: rgba(63, 114, 175, 0.25);
            transform: scale(1.1);
        }

        .card-action {
            position: absolute;
            right: 20px;
            top: 20px;
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
            opacity: 0;
            transform: translateX(10px);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.3);
        }

        .clickable-card:hover .card-action {
            opacity: 1;
            transform: translateX(0);
        }

        .clickable-card:hover .card-action i {
            transform: translateX(2px);
        }

        /* Card color variations */
        .card:nth-child(1)::before {
            background: linear-gradient(90deg, #3F72AF, #112D4E);
        }

        .card:nth-child(2)::before {
            background: linear-gradient(90deg, #16C79A, #0F9D58);
        }

        .card:nth-child(3)::before {
            background: linear-gradient(90deg, #FFC75F, #FF9800);
        }

        .card:nth-child(4)::before {
            background: linear-gradient(90deg, #E91E63, #C2185B);
        }

        /* Card value color variations */
        .card:nth-child(1) .card-value {
            color: #3F72AF;
        }

        .card:nth-child(2) .card-value {
            color: #16C79A;
        }

        .card:nth-child(3) .card-value {
            color: #FF9800;
        }

        .card:nth-child(4) .card-value {
            color: #E91E63;
        }

        /* Card icon color variations */
        .card:nth-child(1) .card-icon {
            color: rgba(63, 114, 175, 0.12);
        }

        .card:nth-child(2) .card-icon {
            color: rgba(22, 199, 154, 0.12);
        }

        .card:nth-child(3) .card-icon {
            color: rgba(255, 152, 0, 0.12);
        }

        .card:nth-child(4) .card-icon {
            color: rgba(233, 30, 99, 0.12);
        }

        .clickable-card:hover .card:nth-child(1) .card-icon {
            color: rgba(63, 114, 175, 0.25);
        }

        .clickable-card:hover .card:nth-child(2) .card-icon {
            color: rgba(22, 199, 154, 0.25);
        }

        .clickable-card:hover .card:nth-child(3) .card-icon {
            color: rgba(255, 152, 0, 0.25);
        }

        .clickable-card:hover .card:nth-child(4) .card-icon {
            color: rgba(233, 30, 99, 0.25);
        }

        /* Add subtle pulse animation to card values */
        .card-value {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        /* Add ripple effect on click */
        .clickable-card {
            position: relative;
            overflow: hidden;
        }

        .clickable-card .ripple {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
            z-index: 0;
        }

        .clickable-card:active .ripple {
            width: 300px;
            height: 300px;
        }

        /* Tables Styles */
        .dashboard-tables {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
        }

        .table-container {
            background-color: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #F8F1F1;
        }

        .table-header h2 {
            font-size: 18px;
            color: #112D4E;
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        table th, table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        table th {
            color: #112D4E;
            font-weight: 600;
            font-size: 14px;
            background-color: #DBE2EF;
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

        .clickable {
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .clickable:hover {
            background-color: #F9F7F7;
            transform: translateY(-2px);
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .status-pending {
            background-color: rgba(17, 45, 78, 0.1);
            color: #112D4E;
        }

        .status-approved {
            background-color: rgba(63, 114, 175, 0.1);
            color: #3F72AF;
        }

        .status-rejected {
            background-color: rgba(255, 71, 87, 0.1);
            color: #ff4757;
        }

        /* Profile Modal Styles */
        .profile-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .profile-modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            position: relative;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .profile-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #F8F1F1;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: #112D4E;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 32px;
            color: white;
        }

        .profile-info h2 {
            margin: 0;
            font-size: 1.5rem;
            color: #112D4E;
        }

        .profile-info p {
            margin: 5px 0 0;
            color: #112D4E;
        }

        .profile-details {
            margin-bottom: 30px;
        }

        .profile-detail-item {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #F8F1F1;
        }

        .profile-detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .detail-label {
            width: 120px;
            color: #112D4E;
            font-weight: 500;
        }

        .detail-value {
            flex: 1;
            color: #112D4E;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #eee;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
        }

        .profile-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #F8F1F1;
        }

        .btn-edit {
            background-color: #3F72AF;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(63, 114, 175, 0.2);
        }

        .modal-close {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 24px;
            font-weight: bold;
            color: #112D4E;
            cursor: pointer;
            transition: all 0.3s;
        }

        .modal-close:hover {
            color: #3F72AF;
            transform: rotate(90deg);
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
            background-color: rgba(63, 114, 175, 0.1);
            color: #3F72AF;
            border: 1px solid rgba(63, 114, 175, 0.2);
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

        /* --- Profile Dropdown Styles (Embedded) --- */
        .profile-dropdown {
            position: relative;
            display: inline-block;
            margin-left: 10px; /* Space from previous elements */
        }

        .profile-btn {
            background-color: transparent; /* Clear background */
            color: #112D4E; /* Admin text color */
            padding: 5px 10px;
            font-size: 14px;
            border: none; /* No border */
            border-radius: 25px;
            cursor: pointer;
            display: flex;
            align-items: center;
            transition: background-color 0.2s ease;
        }

        .profile-btn:hover {
            background-color: #f0f4f8; /* Light background on hover */
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
            background-color: #3F72AF;
            color: #fff;
            font-weight: 700;
            font-size: 12px;
            border: 1px solid #ccc;
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

        /* Rotate caret when dropdown is active */
        .profile-dropdown.active .profile-btn .fa-caret-down {
            transform: rotate(180deg);
        }

        .dropdown-content {
            visibility: hidden; /* Use visibility/opacity for transitions */
            opacity: 0;
            position: absolute;
            background-color: white;
            min-width: 220px;
            box-shadow: 0px 5px 15px rgba(0, 0, 0, 0.1);
            z-index: 1100; /* Ensure it's above other content */
            border-radius: 8px;
            right: 0;
            top: calc(100% + 5px);
            margin-top: 0;
            overflow: hidden;
            border: 1px solid #e0e0e0;
            transition: opacity 0.2s ease, visibility 0.2s ease, transform 0.2s ease;
            transform: translateY(-5px); /* Start slightly up */
        }

        /* Show dropdown when parent has .active class */
        .profile-dropdown.active > .dropdown-content {
            visibility: visible;
            opacity: 1;
            transform: translateY(0);
        }

        /* Remove :hover rule for showing content */
        /*
        .profile-dropdown:hover .dropdown-content {
            display: block;
            animation: fadeInDropdown 0.15s ease-in-out;
        }
        */

        .dropdown-content a {
            color: #333;
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
            color: #555;
            font-size: 15px;
        }

        .dropdown-content a:hover {
            background-color: #f5f5f5;
            color: #112D4E;
        }

        .dropdown-content a:hover i {
            color: #3F72AF;
        }

        .dropdown-content a:last-child {
            border-top: 1px solid #eee;
            color: #d9534f;
        }

        .dropdown-content a:last-child:hover {
            background-color: #d9534f;
            color: white;
        }

        .dropdown-content a:last-child:hover i {
            color: white;
        }

        /* Keyframes for Dropdown */
        @keyframes fadeInDropdown {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* --- Notifications Button Badge (Optional) --- */
        .header-actions .btn .badge {
            background-color: #d9534f; /* Red badge */
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 10px;
            position: relative;
            top: -8px;
            right: -5px;
            display: none; /* Hidden by default */
        }

         /* --- Notifications Container (Embedded) --- */
        .notifications-container {
            position: fixed;
            top: 80px;
            right: 20px;
            width: 400px;
            max-height: 600px;
            background: linear-gradient(180deg, #FFFFFF 0%, #F8FBFF 100%);
            border: 1px solid #E6ECF3;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(17, 45, 78, 0.2);
            z-index: 1200;
            overflow: hidden;
            display: none;
            opacity: 0;
            transform: translateY(-20px) scale(0.95);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .notifications-container.active { 
            display: block !important; 
            opacity: 1 !important; 
            transform: translateY(0) scale(1) !important;
            animation: notificationSlideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes notificationSlideIn {
            0% {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            50% {
                opacity: 0.8;
                transform: translateY(-5px) scale(0.98);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .notifications-header {
            padding: 14px 16px;
            font-weight: 700;
            color: #112D4E;
            border-bottom: 1px solid #E6ECF3;
            background: #F4F8FF;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .close-notifications {
            font-size: 20px;
            cursor: pointer;
            color: #666;
            line-height: 1;
        }

        .close-notifications:hover {
            color: #333;
        }

        .notifications-list {
            max-height: 480px;
            overflow-y: auto;
            padding: 0;
        }

        .notifications-list::-webkit-scrollbar {
            width: 6px;
        }

        .notifications-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .notifications-list::-webkit-scrollbar-thumb {
            background: #3F72AF;
            border-radius: 3px;
        }

        .notifications-list::-webkit-scrollbar-thumb:hover {
            background: #112D4E;
        }

        .notification-item {
            display: flex;
            align-items: flex-start;
            padding: 16px 18px;
            border-bottom: 1px solid #EDEFF5;
            transition: all 0.3s ease;
            background: #fff;
            position: relative;
            cursor: pointer;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item:hover {
            background: linear-gradient(135deg, #F8FBFF 0%, #F0F4F8 100%);
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.1);
        }

        .notification-item.empty {
            text-align: center;
            color: #888;
            padding: 40px 20px;
            font-style: italic;
            cursor: default;
        }

        .notification-item.empty:hover {
            background: #fff;
            transform: none;
            box-shadow: none;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(63,114,175,0.15), rgba(17,45,78,0.08));
            color: #3F72AF;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 14px;
            flex-shrink: 0;
            border: 1px solid #E6ECF3;
            transition: all 0.3s ease;
        }

        .notification-item:hover .notification-icon {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.2);
        }

        .notification-icon i {
            font-size: 18px;
        }

        .notification-content {
            flex-grow: 1;
            font-size: 14px;
            color: #2D3748;
            line-height: 1.5;
        }

        .notification-content .employee-name {
            font-weight: 700;
            color: #112D4E;
            margin-right: 6px;
            font-size: 15px;
        }

        .notification-time {
            display: block;
            font-size: 12px;
            color: #6B7280;
            margin-top: 6px;
            font-weight: 500;
        }
        .notification-notes {
            font-size: 12px;
            color: #666;
            margin-top: 6px;
            padding: 6px 8px;
            background: rgba(63, 114, 175, 0.05);
            border-radius: 6px;
            border-left: 3px solid #3F72AF;
            font-style: italic;
        }

.notifications-footer {
    padding: 16px 20px;
    border-top: 1px solid #E6ECF3;
    text-align: center;
    background: linear-gradient(135deg, #F8FBFF 0%, #F0F4F8 100%);
}

.notifications-footer .view-all {
    color: #3F72AF;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    padding: 8px 16px;
    border-radius: 20px;
    background: rgba(63, 114, 175, 0.1);
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.notifications-footer .view-all:hover {
    background: #3F72AF;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(63, 114, 175, 0.3);
}

.notification-item {
    position: relative;
}

        .notification-item.unread::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background-color: #3F72AF;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 20px;
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
    <img src="LOGO/newLogo_transparent.png" class="logo" style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding:-250px; padding-bottom: 20px;">
        <div class="menu">
            <a href="AdminHome.php" class="menu-item active">
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
            <h1>Dashboard Overview</h1>
            <div class="header-actions">
                
                <a href="#" class="btn btn-secondary" id="notificationsButton">
                    <i class="fas fa-bell"></i> Notifications
                    <span class="notification-badge" id="notificationBadge"><?php echo count($notifications_admin); ?></span>
                </a>
                 <!-- Profile Dropdown -->
                <div class="profile-dropdown">
                    <button class="profile-btn">
                        <div class="profile-initials-avatar"><?php echo htmlspecialchars($admin_initials); ?></div>
                        <span><?php echo htmlspecialchars($user_data['AdminName'] ?? 'Admin'); ?></span>
                        <i class="fas fa-caret-down"></i>
                    </button>
                    <div class="dropdown-content">
                        <a href="#" onclick="showProfileModal()"><i class="fas fa-user-edit"></i> Edit Profile</a>
                        <a href="#" onclick="showChangePasswordModal()"><i class="fas fa-key"></i> Change Password</a>
                    </div>
                </div>
                 <!-- End Profile Dropdown -->
            </div>
        </div>
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo $_SESSION['success']; unset($_SESSION['success']); unset($_SESSION['password_changed']); ?>
        </div>
        <?php endif; ?>
        
        <div class="dashboard-cards">
            <div class="card clickable-card" onclick="window.location='AdminEmployees.php'">
                <div class="ripple"></div>
                <div class="card-content">
                    <div class="card-title">Total Employees</div>
                    <div class="card-value"><?php echo $totalEmployees; ?></div>
                    <div class="card-subtitle">Active Employees</div>
                    <div class="card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="card-action">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                </div>
            </div>
            
            <div class="card clickable-card" onclick="window.location='AdminAttendance.php'">
                <div class="ripple"></div>
                <div class="card-content">
                    <div class="card-title">Present Today</div>
                    <div class="card-value"><?php echo $presentToday; ?></div>
                    <div class="card-subtitle">Present + Late</div>
                    <div class="card-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="card-action">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                </div>
            </div>
            
            <div class="card clickable-card" onclick="window.location='AdminAttendance.php'">
                <div class="ripple"></div>
                <div class="card-content">
                    <div class="card-title">Late Today</div>
                    <div class="card-value"><?php echo $lateToday; ?></div>
                    <div class="card-subtitle">Late Only</div>
                    <div class="card-icon">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="card-action">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                </div>
            </div>
            
            <div class="card clickable-card" onclick="window.location='AdminPayroll.php'">
                <div class="ripple"></div>
                <div class="card-content">
                    <div class="card-title">Monthly Payroll</div>
                    <div class="card-value">₱<?php echo number_format($monthlyPayroll, 2); ?></div>
                    <div class="card-subtitle">Current Month</div>
                    <div class="card-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="card-action">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="dashboard-tables">
            <div class="table-container">
                <div class="table-header">
                    <h2>Recent Employees</h2>
                    <a href="AdminEmployees.php" class="btn btn-secondary">View All</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Position</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentEmployees)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center;">No employees found</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($recentEmployees as $employee): ?>
                            <tr class="clickable" onclick="window.location='AdminEmployees.php?view_employee=<?php echo $employee['EmployeeID']; ?>'">
                                <td><?php echo $employee['EmployeeID']; ?></td>
                                <td><?php echo $employee['EmployeeName']; ?></td>
                                <td><?php echo $employee['Department']; ?></td>
                                <td><?php echo $employee['Position']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="table-container">
    <div class="table-header">
        <h2>Recent Attendance</h2>
        <a href="AdminAttendance.php" class="btn btn-secondary">View All</a>
    </div>
    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Date</th>
                <th>Time In</th>
                <th>Time Out</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentAttendance)): ?>
            <tr>
                <td colspan="5" style="text-align: center;">No attendance records found</td>
            </tr>
            <?php else: ?>
                <?php foreach ($recentAttendance as $attendance): ?>
                <tr class="clickable" onclick="window.location='AdminAttendance.php'">
                    <td><?php echo htmlspecialchars($attendance['EmployeeName']); ?></td>
                    <td><?php echo date('M d, Y', strtotime($attendance['attendance_date'])); ?></td>
                    <td><?php echo !empty($attendance['time_in']) ? date('h:i A', strtotime($attendance['time_in'])) : '-'; ?></td>
                    <td><?php echo !empty($attendance['time_out']) ? date('h:i A', strtotime($attendance['time_out'])) : '-'; ?></td>
                    <td>
                        <?php 
                        // Determine display status based on attendance_type and status
                        $display_status = 'Present';
                        $status_class = 'approved';
                        
                        if ($attendance['attendance_type'] === 'absent') {
                            $display_status = 'Absent';
                            $status_class = 'rejected';
                        } elseif ($attendance['status'] === 'late') {
                            $display_status = 'Late';
                            $status_class = 'pending';
                        }
                        ?>
                        <span class="status-badge status-<?php echo $status_class; ?>">
                            <?php echo $display_status; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
        </div>
    </div>

    <!-- Profile Modal -->
    <div id="profileModal" class="profile-modal">
        <div class="profile-modal-content">
            <span class="modal-close" onclick="closeProfileModal()">&times;</span>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                </div>
            <?php endif; ?>
            <div class="profile-header">
                <div class="profile-avatar">
                    <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" alt="Profile" id="modalProfilePic" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                </div>
                <div class="profile-info">
                    <h2 id="modalProfileName"><?php echo htmlspecialchars($user_data['AdminName'] ?? 'User'); ?></h2>
                    <p>Administrator</p>
                </div>
            </div>
            <form id="profileForm" method="POST" action="update_admin_profile.php">
                <input type="hidden" name="update_profile" value="1">
                <class="profile-details">
                    <div class="profile-detail-item">
                        <div class="detail-label">Admin ID</div>
                        <div class="detail-value"><?php echo htmlspecialchars($user_data['AdminID'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="profile-detail-item">
                        <div class="detail-label">Username</div>
                        <div class="detail-value"><?php echo htmlspecialchars($user_data['AdminUsername'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="profile-detail-item">
                        <div class="detail-label">Name</div>
                        <div class="detail-value"><?php echo htmlspecialchars($user_data['AdminName'] ?? 'N/A'); ?></div>
                    </div>
                    
                    <div class="profile-detail-item">
                        <div class="detail-label">Email</div>
                        <div class="detail-value">
                            <input type="email" name="admin_email" value="<?php echo htmlspecialchars($user_data['AdminEmail'] ?? ''); ?>" class="form-control" required>
                        </div>
                    </div>
                    
                <div class="profile-actions">
                    <button type="submit" class="btn-edit">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div id="changePasswordModal" class="profile-modal">
        <div class="profile-modal-content">
            <span class="modal-close" onclick="closeChangePasswordModal()">&times;</span>
            <div class="profile-header">
                 <div class="profile-info">
                    <h2>Change Password</h2>
                </div>
            </div>
            <form id="changePasswordForm" method="POST" action="change_admin_password.php">
                 <div class="profile-details">
                    <div class="profile-detail-item">
                        <div class="detail-label">Current Password</div>
                        <div class="detail-value">
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                    </div>
                    <div class="profile-detail-item">
                        <div class="detail-label">New Password</div>
                        <div class="detail-value" style="position:relative;">
                            <input type="password" name="new_password" id="admin_new_password" class="form-control" required minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}" style="padding-right:90px;">
                            <button type="button" onclick="toggleAdminPassword('admin_new_password', this)"style="position:absolute; right:8px; top:0; bottom:0; transform:none; background:none; border:none; cursor:pointer; color:#3F72AF; display:flex; align-items:center;,margin-bottom:10px; padding:0 10px;">Show</button>
                        </div>
                    </div>
                    <small style="display:block;color:#6c757d; margin-top: 20px; margin-bottom: 20px;">At least 8 characters, include numbers, lowercase and uppercase letters.</small>
                    <div class="profile-detail-item">
                        <div class="detail-label">Confirm Password</div>
                        <div class="detail-value" style="position:relative;">
                            <input type="password" name="confirm_password" id="admin_confirm_password" class="form-control" required minlength="8" style="padding-right:90px;">
                            <button type="button" onclick="toggleAdminPassword('admin_confirm_password', this)" style="position:absolute; right:8px; top:0; bottom:0; transform:none; background:none; border:none; cursor:pointer; color:#3F72AF; display:flex; align-items:center; padding:0 10px;">Show</button>
                        </div>
                    </div>
                </div>
                <div class="profile-actions">
                    <button type="submit" class="btn-edit">
                        <i class="fas fa-key"></i> Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    

    <!-- Notifications Pop-up Container -->
<div id="notificationsContainer_admin" class="notifications-container">
    <div class="notifications-header">
        <i class="fas fa-bell"></i> Recent Activities
        <span class="close-notifications" onclick="toggleNotifications_admin()">&times;</span>
    </div>
    <div class="notifications-list">
        <?php if (empty($notifications_admin)): ?>
            <div class="notification-item empty">
                <p>No new notifications</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications_admin as $notification): ?>
                <div class="notification-item unread">
                    <div class="notification-icon">
                        <?php if ($notification['type'] === 'attendance'): ?>
                            <i class="fas fa-clock"></i>
                        <?php elseif ($notification['type'] === 'employee'): ?>
                            <i class="fas fa-user"></i>
                        <?php elseif ($notification['type'] === 'payroll'): ?>
                            <i class="fas fa-money-bill-wave"></i>
                        <?php endif; ?>
                    </div>
                    <div class="notification-content">
                        <?php if ($notification['type'] === 'attendance'): ?>
                            <strong class="employee-name"><?php echo htmlspecialchars($notification['EmployeeName']); ?></strong>
                            <?php if ($notification['status'] === 'late'): ?>
                                arrived late
                            <?php elseif ($notification['status'] === 'absent'): ?>
                                is absent today
                            <?php else: ?>
                                clocked in at <?php echo date('h:i A', strtotime($notification['time_in'])); ?>
                            <?php endif; ?>
                            <?php if (!empty($notification['time_out'])): ?>
                                and clocked out at <?php echo date('h:i A', strtotime($notification['time_out'])); ?>
                            <?php endif; ?>
                        <?php elseif ($notification['type'] === 'employee'): ?>
                            New employee <strong class="employee-name"><?php echo htmlspecialchars($notification['EmployeeName']); ?></strong> added
                        <?php elseif ($notification['type'] === 'payroll'): ?>
                            Payroll processed for <strong class="employee-name"><?php echo htmlspecialchars($notification['EmployeeName']); ?></strong>
                            <div class="notification-notes">
                                Amount: ₱<?php echo number_format($notification['amount'], 2); ?>
                            </div>
                        <?php endif; ?>
                        <div class="notification-time">
                            <?php 
                            $timestamp = strtotime($notification['timestamp']);
                            $now = time();
                            $diff = $now - $timestamp;
                            
                            if ($diff < 60) {
                                echo "Just now";
                            } elseif ($diff < 3600) {
                                $mins = floor($diff / 60);
                                echo $mins . " minute" . ($mins > 1 ? "s" : "") . " ago";
                            } elseif ($diff < 86400) {
                                $hours = floor($diff / 3600);
                                echo $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
                            } else {
                                echo date('M j, Y g:i A', $timestamp);
                            }
                            ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (empty($notifications_admin)): ?>
            <div class="notification-item empty">
                <div class="notification-icon">
                    <i class="fas fa-info-circle" style="color: #6c757d;"></i>
                </div>
                <div class="notification-content">
                    No recent activities found.
                </div>
            </div>
            <!-- Test notification to verify container works -->
            <div class="notification-item unread">
                <div class="notification-icon">
                    <i class="fas fa-bell" style="color: #3F72AF;"></i>
                </div>
                <div class="notification-content">
                    <span class="employee-name">Test Notification</span>
                    This is a test notification to verify the container is working.
                    <span class="notification-time">
                        <?php echo date('M d, Y h:i A'); ?>
                    </span>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($notifications_admin as $noti): ?>
                <div class="notification-item unread">
                    <div class="notification-icon">
                        <?php 
                            $icon = 'fa-bell';
                            $icon_color = '#3F72AF';
                            if ($noti['type'] === 'attendance') {
                                $icon = 'fa-calendar-check';
                                if ($noti['status'] === 'late') $icon_color = '#FFA500';
                                elseif ($noti['status'] === 'absent') $icon_color = '#FF4757';
                            }
                            elseif ($noti['type'] === 'employee') {
                                $icon = 'fa-user-plus';
                                if ($noti['status'] === 'inactive') $icon_color = '#FF4757';
                                elseif ($noti['status'] === 'archived') $icon_color = '#888';
                            }
                            elseif ($noti['type'] === 'payroll') $icon = 'fa-money-bill-wave';
                        ?>
                        <i class="fas <?php echo $icon; ?>" style="color: <?php echo $icon_color; ?>"></i>
                    </div>
                    <div class="notification-content">
                        <?php if ($noti['type'] === 'attendance'): ?>
                            <span class="employee-name"><?php echo htmlspecialchars($noti['EmployeeName']); ?></span>
                            <?php 
                                $action = '';
                                if ($noti['status'] === 'present') {
                                    $action = 'clocked in';
                                    if ($noti['time_in']) $action .= ' at ' . date('h:i A', strtotime($noti['time_in']));
                                }
                                elseif ($noti['status'] === 'late') {
                                    $action = 'was late';
                                    if ($noti['time_in']) $action .= ' (arrived at ' . date('h:i A', strtotime($noti['time_in'])) . ')';
                                }
                                else $action = 'was absent';
                            ?>
                            <?php echo $action; ?>
                            <?php if ($noti['notes']): ?>
                                <div class="notification-notes">Note: <?php echo htmlspecialchars($noti['notes']); ?></div>
                            <?php endif; ?>
                        <?php elseif ($noti['type'] === 'employee'): ?>
                            <?php if ($noti['status'] === 'active'): ?>
                                New employee added: 
                                <span class="employee-name"><?php echo htmlspecialchars($noti['EmployeeName']); ?></span>
                            <?php else: ?>
                                Employee status changed: 
                                <span class="employee-name"><?php echo htmlspecialchars($noti['EmployeeName']); ?></span>
                                is now <?php echo htmlspecialchars($noti['status']); ?>
                            <?php endif; ?>
                        <?php elseif ($noti['type'] === 'payroll'): ?>
                            Payroll processed for 
                            <span class="employee-name"><?php echo htmlspecialchars($noti['EmployeeName']); ?></span>
                            (₱<?php echo number_format($noti['amount'], 2); ?>)
                            <?php if ($noti['notes']): ?>
                                <div class="notification-notes"><?php echo htmlspecialchars($noti['notes']); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <span class="notification-time">
                            <?php 
                                $timestamp = strtotime($noti['timestamp']);
                                echo date('M d, Y h:i A', $timestamp);
                                
                                // Show "X hours/days ago" if recent
                                $now = time();
                                $diff = $now - $timestamp;
                                if ($diff < 86400) { // Less than 1 day
                                    if ($diff < 3600) { // Less than 1 hour
                                        $mins = floor($diff / 60);
                                        echo " ($mins min" . ($mins != 1 ? 's' : '') . " ago)";
                                    } else {
                                        $hours = floor($diff / 3600);
                                        echo " ($hours hour" . ($hours != 1 ? 's' : '') . " ago)";
                                    }
                                } elseif ($diff < 604800) { // Less than 1 week
                                    $days = floor($diff / 86400);
                                    echo " ($days day" . ($days != 1 ? 's' : '') . " ago)";
                                }
                            ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="notifications-footer">
        <a href="AdminHistory.php" class="view-all">
            <i class="fas fa-external-link-alt"></i> View All Activities
        </a>
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
        
        // ---- Modal Control Functions ----
        const profileModal = document.getElementById('profileModal');
        const changePasswordModal = document.getElementById('changePasswordModal');
        const changePictureModal = document.getElementById('changePictureModal');

        function showProfileModal() {
            if(profileModal) profileModal.style.display = 'block';
        }
        function closeProfileModal() {
            if(profileModal) profileModal.style.display = 'none';
        }

        function showChangePasswordModal() {
            if(changePasswordModal) changePasswordModal.style.display = 'block';
        }
        function closeChangePasswordModal() {
            if(changePasswordModal) changePasswordModal.style.display = 'none';
             // Clear form fields on close for security
            const form = document.getElementById('changePasswordForm');
            if(form) form.reset();
        }

        function showChangePictureModal() {
            if(changePictureModal) changePictureModal.style.display = 'block';
        }
        function closeChangePictureModal() {
            if(changePictureModal) changePictureModal.style.display = 'none';
            // Reset file input and preview on close
            const form = document.getElementById('changePictureForm');
            const preview = document.getElementById('picturePreview');
            const fileName = document.getElementById('fileName');
            const defaultPic = "<?php echo htmlspecialchars($default_profile_pic); ?>"; // Get default path
            const currentPic = "<?php echo htmlspecialchars($profile_pic_path); ?>"; // Get current path
            if(form) form.reset();
            if(preview) preview.src = currentPic; // Reset to current pic on close
            if(fileName) fileName.textContent = "No file chosen";

        }

         // Image Preview for Change Picture Modal
        function previewImage(event) {
            const reader = new FileReader();
            const preview = document.getElementById('picturePreview');
            const fileName = document.getElementById('fileName');
            reader.onload = function(){
                if (reader.readyState == 2) {
                    preview.src = reader.result;
                }
            }
            if(event.target.files[0]){
                reader.readAsDataURL(event.target.files[0]);
                fileName.textContent = event.target.files[0].name;
            } else {
                 const currentPic = "<?php echo htmlspecialchars($profile_pic_path); ?>";
                 preview.src = currentPic; // Revert to current if no file selected
                 fileName.textContent = "No file chosen";
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target == profileModal) {
                closeProfileModal();
            }
            if (event.target == changePasswordModal) {
                closeChangePasswordModal();
            }
            if (event.target == changePictureModal) {
                closeChangePictureModal();
            }
        }
        // Toggle notifications dropdown
function toggleNotifications_admin() {
    const container = document.getElementById('notificationsContainer_admin');
    const badge = document.getElementById('notificationBadge');
    
    console.log('Toggle notifications called');
    console.log('Container found:', !!container);
    console.log('Badge found:', !!badge);
    
    if (container) {
        // Toggle the active class
        container.classList.toggle('active');
        
        console.log('Container active class:', container.classList.contains('active'));
        
        // Force visibility for debugging
        if (container.classList.contains('active')) {
            container.style.display = 'block';
            container.style.opacity = '1';
            container.style.transform = 'translateY(0) scale(1)';
            container.style.zIndex = '1200';
        } else {
            container.style.display = 'none';
        }
        
        // If container is now active (opened)
        if (container.classList.contains('active')) {
            // Mark all notification items as read
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
            
            // Hide the notification badge
            if (badge) {
                badge.classList.add('hidden');
            }
            
            // Update the counter
            updateNotificationCounter();
        }
    } else {
        console.error('Notifications container not found!');
    }
}

// Update notification counter
function updateNotificationCounter() {
    const badge = document.getElementById('notificationBadge');
    if (badge) {
        const unreadCount = document.querySelectorAll('.notification-item.unread').length;
        if (unreadCount > 0) {
            badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }
}

// Show notification badge (for when new notifications arrive)
function showNotificationBadge() {
    const badge = document.getElementById('notificationBadge');
    if (badge) {
        badge.classList.remove('hidden');
        // Add a bounce animation
        badge.style.animation = 'pulse-badge 0.6s ease-in-out';
        setTimeout(() => {
            badge.style.animation = 'pulse-badge 2s infinite';
        }, 600);
    }
}

// Close notifications when clicking outside
document.addEventListener('click', function(event) {
    const container = document.getElementById('notificationsContainer_admin');
    const btn = document.querySelector('.header-actions .btn-secondary');
    
    if (container && container.classList.contains('active') && 
        !container.contains(event.target) && 
        event.target !== btn && !btn.contains(event.target)) {
        container.classList.remove('active');
    }
});

// Initialize notification items as unread (you might want to implement server-side tracking)
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.notification-item').forEach(item => {
        item.classList.add('unread');
    });
    updateNotificationCounter();
    
    // Add click event to close button
    const closeBtn = document.querySelector('.close-notifications');
    if (closeBtn) {
        closeBtn.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            const container = document.getElementById('notificationsContainer_admin');
            if (container) {
                container.classList.remove('active');
            }
        });
    }
});

        // Form validation for password match (example)
        const cpForm = document.getElementById('changePasswordForm');
        if(cpForm) {
            cpForm.onsubmit = function(e) {
                const newPassword = cpForm.querySelector('input[name="new_password"]').value;
                const confirmPassword = cpForm.querySelector('input[name="confirm_password"]').value;

                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    alert('New passwords do not match!');
                    return false;
                }
                // Add more validation if needed (e.g., password strength)
                return true;
            }
        }

        // --- Click-to-Toggle Dropdown (Admin) --- 
        const profileDropdownButton_admin = document.querySelector('.profile-dropdown .profile-btn');
        const profileDropdown_admin = document.querySelector('.profile-dropdown');

        if (profileDropdownButton_admin && profileDropdown_admin) {
            profileDropdownButton_admin.addEventListener('click', function(event) {
                profileDropdown_admin.classList.toggle('active');
                event.stopPropagation(); 
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
             // Close Admin Dropdown
            if (profileDropdown_admin && profileDropdown_admin.classList.contains('active')) {
                if (!profileDropdown_admin.contains(event.target)) {
                    profileDropdown_admin.classList.remove('active');
                }
            }
            // Close other modals (existing code)
            if (event.target == profileModal) {
                closeProfileModal();
            }
            if (event.target == changePasswordModal) {
                closeChangePasswordModal();
            }
            if (event.target == changePictureModal) {
                closeChangePictureModal();
            }
        });
        // --- End Click-to-Toggle --- 

        // --- Notification Toggle (Admin) --- 
        document.addEventListener('DOMContentLoaded', function() {
            const notificationsButton_admin = document.getElementById('notificationsButton');
            const notificationsContainer_admin = document.getElementById('notificationsContainer_admin');

            console.log('DOM loaded, looking for notification elements');
            console.log('Button found:', !!notificationsButton_admin);
            console.log('Container found:', !!notificationsContainer_admin);

            if (notificationsButton_admin) {
                notificationsButton_admin.addEventListener('click', function(event) {
                    event.preventDefault(); // Prevent default link behavior
                    event.stopPropagation();
                    console.log('Notification button clicked');
                    toggleNotifications_admin();
                });
            } else {
                console.error('Notification button not found!');
            }
        });

        // Simulate new notification (for testing - remove in production)
        function simulateNewNotification() {
            const container = document.getElementById('notificationsContainer_admin');
            const badge = document.getElementById('notificationBadge');
            
            if (container && badge) {
                // Only show badge if notifications panel is closed
                if (!container.classList.contains('active')) {
                    showNotificationBadge();
                }
            }
        }

        // Auto-simulate notifications every 30 seconds (for testing)
        // setInterval(simulateNewNotification, 30000);

        // Update main click listener to close admin notifications
         document.addEventListener('click', function(event) {
                 // Close Admin Dropdown (Existing)
                if (profileDropdown_admin && profileDropdown_admin.classList.contains('active')) {
                    if (!profileDropdown_admin.contains(event.target)) {
                        profileDropdown_admin.classList.remove('active');
                    }
                }
                // Close Admin Notifications
                if (notificationsContainer_admin && notificationsContainer_admin.classList.contains('active')) {
                    if (!notificationsContainer_admin.contains(event.target) && 
                        event.target !== notificationsButton_admin && 
                        !notificationsButton_admin.contains(event.target)) {
                        notificationsContainer_admin.classList.remove('active'); // Close it
                    }
                }
                // Close Modals (Existing)
                if (event.target == profileModal) {
                    closeProfileModal();
                }
                if (event.target == changePasswordModal) {
                    closeChangePasswordModal();
                }
                if (event.target == changePictureModal) {
                    closeChangePictureModal();
                }
            });

        function toggleAdminPassword(inputId, btn){
            const input = document.getElementById(inputId);
            if(!input) return;
            if(input.type === 'password') { input.type = 'text'; btn.textContent = 'Hide'; }
            else { input.type = 'password'; btn.textContent = 'Show'; }
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