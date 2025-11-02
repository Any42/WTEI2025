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

// Align PHP and MySQL session timezones to Asia/Manila
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");

// Get HR details
$hr_query = "SELECT * FROM hr_accounts WHERE hr_id = ?";
$stmt = $conn->prepare($hr_query);
$stmt->bind_param("i", $hr_id);
$stmt->execute();
$hr_result = $stmt->get_result();
$hr_details = $hr_result->fetch_assoc();

// Define default profile picture path
$default_profile_pic = 'img/default-avatar.png'; // Adjust path as needed
$profile_pic_path = $hr_details['profile_picture'] ?? $default_profile_pic;
if (empty($hr_details['profile_picture']) || !file_exists($hr_details['profile_picture'])) {
    $profile_pic_path = $default_profile_pic;
}

function getInitials($fullName) {
    $fullName = trim((string)$fullName);
    if ($fullName === '') return 'HR';
    $parts = preg_split('/\s+/', $fullName);
    $first = strtoupper(substr($parts[0] ?? '', 0, 1));
    $last = strtoupper(substr($parts[count($parts)-1] ?? '', 0, 1));
    $initials = $first . ($last !== '' ? $last : '');
    return $initials !== '' ? $initials : 'HR';
}
$hr_initials = getInitials($hr_details['full_name'] ?? ($hr_details['username'] ?? 'HR'));

// Get dashboard statistics
$stats = [
    'total_employees' => 0,
    'present_today' => 0,
    'on_leave' => 0,
    'pending_leaves' => 0
];

// Total employees
// Use active employees as denominator (align with attendance calculations)
$result = $conn->query("SELECT COUNT(DISTINCT EmployeeID) as count FROM empuser WHERE Status = 'active'");
$stats['total_employees'] = (int)($result->fetch_assoc()['count'] ?? 0);

// Present today (use DATE() and honor attendance_type='present')
$today = date('Y-m-d');
$present_query = "SELECT COUNT(DISTINCT a.EmployeeID) as count 
                 FROM attendance a 
                 WHERE DATE(a.attendance_date) = ?
                 AND a.attendance_type = 'present'";
$stmt = $conn->prepare($present_query);
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();
$stats['present_today'] = $result->fetch_assoc()['count'];


// Late today (distinct employees marked present with status late)
$late_query = "SELECT COUNT(DISTINCT a.EmployeeID) as count 
                 FROM attendance a 
                 WHERE DATE(a.attendance_date) = ?
                 AND a.attendance_type = 'present'
                 AND a.status = 'late'";
$stmt = $conn->prepare($late_query);
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();
$stats['late_today'] = (int)($result->fetch_assoc()['count'] ?? 0);

// Derived daily metrics
$actual_absent = max(0, (int)$stats['total_employees'] - (int)$stats['present_today']);
$attendance_rate = ((int)$stats['total_employees'] > 0)
    ? round(($stats['present_today'] / $stats['total_employees']) * 100)
    : 0;



// Get recent activities
$query = "SELECT a.*, e.EmployeeName 
          FROM (
              SELECT EmployeeID,
                     attendance_date AS activity_time,
                     'attendance' AS type,
                     time_in,
                     time_out,
                     status,
                     attendance_type
              FROM attendance 
              WHERE DATE(attendance_date) = ?
          ) a
          JOIN empuser e ON a.EmployeeID = e.EmployeeID
          ORDER BY COALESCE(time_in, '23:59:59') DESC, activity_time DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();

$recent_activities = [];
while ($row = $result->fetch_assoc()) {
    $recent_activities[] = $row;
}

// Build notifications from today's attendance events (limit 10)
$notifications = [];
$noti_sql = "SELECT 
    e.EmployeeName AS employee_name,
    a.EmployeeID AS employee_id,
    CASE 
        WHEN a.time_out IS NOT NULL THEN CONCAT('Marked time out at ', DATE_FORMAT(a.time_out, '%h:%i %p'))
        WHEN a.time_in IS NOT NULL AND a.status = 'late' THEN CONCAT('Clocked in late at ', DATE_FORMAT(a.time_in, '%h:%i %p'))
        WHEN a.time_in IS NOT NULL THEN CONCAT('Clocked in at ', DATE_FORMAT(a.time_in, '%h:%i %p'))
        ELSE 'Attendance updated'
    END AS message,
    CONCAT(DATE(a.attendance_date),' ', COALESCE(a.time_out, a.time_in, '00:00:00')) AS created_at
FROM attendance a 
JOIN empuser e ON a.EmployeeID = e.EmployeeID
WHERE DATE(a.attendance_date) = ?
ORDER BY COALESCE(a.time_out, a.time_in, '00:00:00') DESC
LIMIT 10";
$noti_stmt = $conn->prepare($noti_sql);
if ($noti_stmt) {
    $noti_stmt->bind_param('s', $today);
    $noti_stmt->execute();
    $noti_res = $noti_stmt->get_result();
    while ($row = $noti_res->fetch_assoc()) { $notifications[] = $row; }
    $noti_stmt->close();
}


$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Dashboard - WTEI</title>
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
    width: 320px;
    background: linear-gradient(180deg, #112D4E 0%, #3F72AF 100%);
    box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
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
    color: white;
    margin-left: 20px;
    margin: 0 auto 15px;
    display: block;
    transition: all 0.3s ease;
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
    color: #FFFFFF;
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
    transition: opacity 0.3s ease;
    color: #FFFFFF;
}

.logout-container {
    padding: 20px 15px;
    margin-top: auto;
}

.logout-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    padding: 14px;
    background-color: rgba(255, 255, 255, 0.1);
    color: #FFFFFF;
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
    color: #FFFFFF;
}

/* Toggle Button */
.sidebar-toggle {
    position: fixed;
    bottom: 20px;
    left: 20px;
    width: 40px;
    height: 40px;
    background-color: #3F72AF;
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 1001;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
    transition: all 0.3s ease;
}

.sidebar-toggle:hover {
    background-color: #112D4E;
    transform: scale(1.1);
}

/* Collapsed State */
.sidebar.collapsed {
    width: 90px;
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
    margin-left: 90px;
}

/* Responsive Design */
@media (max-width: 992px) {
    .sidebar {
        transform: translateX(-100%);
        width: 320px;
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
    margin-left: 320px;
    flex: 1;
    padding: 30px;
    transition: margin-left 0.3s ease;
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
        
        /* Main Content Styles */
        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 20px;
            transition: margin-left var(--transition-speed) ease;
        }

        /* Rest of your existing styles */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: var(--primary-color);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-secondary {
            background-color: var(--accent-color);
            color: var(--primary-color);
        }

        .btn-secondary:hover {
            background-color: var(--secondary-color);
            color: white;
        }

        .badge {
            background-color: var(--error-color);
            color: white;
            border-radius: 50%;
            padding: 4px 8px;
            font-size: 12px;
            margin-left: 5px;
        }

        .profile-dropdown {
            position: relative;
        }

        .profile-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: none;
            border: 1px solid var(--border-color);
            border-radius: 30px;
            padding: 8px 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .profile-btn:hover {
            background-color: var(--accent-color);
        }

        .profile-img-icon { display: none; }
        .profile-initials-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: var(--secondary-color);
            color: #fff;
            font-weight: 700;
            font-size: 13px;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background-color: white;
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            z-index: 100;
            overflow: hidden;
            margin-top: 10px;
        }

        .profile-dropdown.active .dropdown-content {
            display: block;
        }

        .dropdown-content a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
        }

        .dropdown-content a:hover {
            background-color: var(--accent-color);
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-color);
        }

        .card-content {
            padding: 20px;
            position: relative;
        }

        .card-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--secondary-color);
            margin-bottom: 10px;
        }

        .card-icon {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 40px;
            color: var(--accent-color);
            opacity: 0.7;
        }

        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--accent-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--secondary-color);
        }

        .activity-details {
            flex: 1;
        }

        .activity-time {
            color: #777;
            font-size: 14px;
        }

        .no-activities {
            padding: 30px;
            text-align: center;
            color: #777;
        }

        /* Summary Cards - Enhanced Beautiful Design */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 35px;
        }

        .summary-card {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8FBFF 100%);
            border-radius: 20px;
            padding: 28px 24px;
            border: 1px solid rgba(63, 114, 175, 0.08);
            box-shadow: 0 8px 24px rgba(17, 45, 78, 0.06), 0 2px 8px rgba(17, 45, 78, 0.04);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 48px rgba(17, 45, 78, 0.12), 0 8px 16px rgba(17, 45, 78, 0.08);
            border-color: rgba(63, 114, 175, 0.15);
        }

        .summary-card:hover::before {
            opacity: 1;
        }

        .summary-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            margin-bottom: 16px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
            font-size: 28px;
        }

        .summary-card:hover .summary-icon {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.2);
        }

        .summary-icon.present { 
            background: linear-gradient(135deg, #16C79A 0%, #11A67F 100%);
        }
        .summary-icon.late { 
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
        }
        .summary-icon.absent { 
            background: linear-gradient(135deg, #ff4757 0%, #e84118 100%);
        }
        .summary-icon.rate { 
            background: linear-gradient(135deg, #3F72AF 0%, #2C5282 100%);
        }
        .summary-icon.total { 
            background: linear-gradient(135deg, #112D4E 0%, #1a4971 100%);
        }

        .summary-value {
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
            margin-bottom: 6px;
        }
        
        .summary-label {
            color: #5A6C7D;
            font-weight: 500;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        @media (max-width: 1400px) {
            .summary-cards { 
                grid-template-columns: repeat(3, 1fr); 
            }
        }

        @media (max-width: 992px) {
            .summary-cards { 
                grid-template-columns: repeat(2, 1fr); 
            }
        }

        @media (max-width: 576px) {
            .summary-cards { 
                grid-template-columns: 1fr; 
            }
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: white;
            border-radius: 12px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--primary-color);
            color: white;
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .form-actions {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            color: white;
            cursor: pointer;
        }

        /* Notifications Styles */
        .notifications-container {
            position: fixed;
            top: 80px;
            right: 20px;
            width: 350px;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            z-index: 999;
            display: none;
            overflow: hidden;
        }

        .notifications-container.active {
            display: block;
        }

        .notifications-header {
            padding: 15px 20px;
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .close-notifications {
            font-size: 24px;
            cursor: pointer;
        }

        .notifications-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item.empty {
            justify-content: center;
            color: #777;
            padding: 30px;
        }

        .notification-icon {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background-color: var(--accent-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--secondary-color);
            flex-shrink: 0;
        }

        .notification-content {
            flex: 1;
        }

        .employee-name {
            font-weight: 600;
            color: var(--primary-color);
        }

        .notification-time {
            display: block;
            font-size: 12px;
            color: #777;
            margin-top: 5px;
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
    <link rel="stylesheet" href="css/hr-styles.css?v=<?php echo time(); ?>">
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
        <a href="HRHome.php" class="menu-item active">
            <i class="fas fa-th-large"></i>
            <span>Dashboard</span>
        </a>
        <a href="HREmployees.php" class="menu-item">
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


    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">HR Dashboard</h1>
            <div class="header-actions">
                <button class="btn btn-secondary" id="notificationsButton_hr">
                     <i class="fas fa-bell"></i> Notifications <span class="badge" id="notificationCount_hr">0</span>
                 </button>
                <div class="profile-dropdown">
                    <button class="profile-btn">
                        <div class="profile-initials-avatar"><?php echo htmlspecialchars($hr_initials); ?></div>
                        <span><?php echo htmlspecialchars($hr_details['full_name']); ?></span>
                        <i class="fas fa-caret-down"></i>
                    </button>
                    <div class="dropdown-content">
                        <a href="#" onclick="showProfileModal()"><i class="fas fa-user-edit"></i> Edit Profile</a>
                        <a href="#" onclick="showChangePasswordModal()"><i class="fas fa-key"></i> Change Password</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon total">
                    <i class="fas fa-users"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo (int)($stats['total_employees'] ?? 0); ?></div>
                    <div class="summary-label">Total Employees</div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon present">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo (int)($stats['present_today'] ?? 0); ?></div>
                    <div class="summary-label">Present Today</div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon late">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo (int)($stats['late_today'] ?? 0); ?></div>
                    <div class="summary-label">Late Today</div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon rate">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo $attendance_rate; ?>%</div>
                    <div class="summary-label">Attendance Rate</div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon absent">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo $actual_absent; ?></div>
                    <div class="summary-label">Absent Today</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Recent Activities</h2>
            </div>
            <div class="activity-list" style="background: linear-gradient(180deg,#fff 0%, #f8fbff 100%); border:1px solid #dbe2ef; border-radius:12px; box-shadow:0 6px 18px rgba(17,45,78,0.08);">
                <?php if (empty($recent_activities)): ?>
                    <div class="no-activities">No activities recorded for today</div>
                <?php else: ?>
                    <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item" style="background:#fff; border-radius:10px; margin:10px; padding:14px 16px; box-shadow:0 2px 8px rgba(17,45,78,0.06); display:flex; align-items:center; gap:14px;">
                            <div class="activity-icon" style="width:44px; height:44px; border-radius:50%; background:#e8eef8; display:flex; align-items:center; justify-content:center; color:#3F72AF;">
                                <i class="fas fa-<?php echo $activity['type'] === 'attendance' ? 'calendar-check' : 'calendar-times'; ?>"></i>
                            </div>
                            <div class="activity-details" style="flex:1; color:#112D4E;">
                                <strong style="font-weight:600; font-size:14px;">&nbsp;<?php echo htmlspecialchars($activity['EmployeeName']); ?></strong><br>
                                <?php if ($activity['type'] === 'attendance'): ?>
                                    <?php
                                        $attType = $activity['attendance_type'] ?? '';
                                        $status  = $activity['status'] ?? '';
                                        if ($attType === 'present') {
                                            if ($status === 'late') {
                                                $desc = 'arrived late';
                                            } else {
                                                $desc = 'arrived';
                                            }
                                        } else {
                                            $desc = 'is absent';
                                        }
                                    ?>
                                    <span style="font-size:13px; color:#26415f;"><?php echo $desc; ?><?php if (!empty($activity['time_in'])): ?> at <strong><?php echo date('h:i A', strtotime($activity['time_in'])); ?></strong><?php endif; ?><?php if (!empty($activity['time_out'])): ?> and left at <strong><?php echo date('h:i A', strtotime($activity['time_out'])); ?></strong><?php endif; ?></span>
                                <?php else: ?>
                                    activity recorded
                                <?php endif; ?>
                            </div>
                            <div class="activity-time" style="color:#6c7a92; font-size:12px; white-space:nowrap;">
                                <?php echo date('M d, Y h:i A', strtotime($activity['activity_time'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Profile Modals -->
<!-- Edit Profile Modal -->
<div id="editProfileModal" class="modal profile-modal"> <!-- Added profile-modal class for potential shared styling -->
    <div class="modal-content">
        <span class="close-modal" onclick="closeEditProfileModal()">&times;</span>
        <div class="modal-header">
            <h2>Edit Profile</h2>
        </div>
        <form id="editProfileForm" method="POST" action="update_hr_profile.php"> <!-- Specific backend script needed -->
            <div class="modal-body">
                <div class="form-group">
                    <label for="hr_name">Full Name</label>
                    <input type="text" name="hr_name" id="hr_name" class="form-control" value="<?php echo htmlspecialchars($hr_details['full_name'] ?? ''); ?>" disabled>
                </div>
                <div class="form-group">
                    <label for="hr_email">Email</label>
                    <input type="email" name="hr_email" id="hr_email" class="form-control" value="<?php echo htmlspecialchars($hr_details['email'] ?? ''); ?>" required>
                </div>
                
                 
                 <div class="form-group">
                    <label for="hr_id_display">HR ID</label>
                    <input type="text" id="hr_id_display" class="form-control" value="<?php echo htmlspecialchars($hr_details['hr_id'] ?? ''); ?>" disabled>
                </div>
                <div class="form-group">
                
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
    <div class="modal-content">
        <span class="close-modal" onclick="closeChangePasswordModal()">&times;</span>
         <div class="modal-header">
            <h2>Change Password</h2>
        </div>
        <form id="changePasswordForm" method="POST" action="change_hr_password.php"> <!-- Specific backend script needed -->
            <div class="modal-body">
                <div class="form-group">
                    <label for="current_hr_password">Current Password</label>
                    <input type="password" name="current_password" id="current_hr_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="new_hr_password">New Password</label>
                    <div style="position: relative;">
                        <input type="password" name="new_password" id="new_hr_password" class="form-control" required minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}">
                        <button type="button" onclick="toggleHrPassword('new_hr_password', this)" style="position:absolute; right:8px; top:50%; transform: translateY(-50%); background:none; border:none; cursor:pointer; color:#3F72AF;">Show</button>
                    </div>
                    <small style="color:#6c757d;">At least 8 characters, include numbers, lowercase and uppercase letters.</small>
                </div>
                <div class="form-group">
                    <label for="confirm_hr_password">Confirm New Password</label>
                    <div style="position: relative;">
                        <input type="password" name="confirm_password" id="confirm_hr_password" class="form-control" required minlength="8">
                        <button type="button" onclick="toggleHrPassword('confirm_hr_password', this)" style="position:absolute; right:8px; top:50%; transform: translateY(-50%); background:none; border:none; cursor:pointer; color:#3F72AF;">Show</button>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                 <button type="button" class="btn btn-secondary" onclick="closeChangePasswordModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Update Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Removed Change Profile Picture Modal -->

<!-- Notifications Pop-up Container -->
<div id="notificationsContainer_hr" class="notifications-container">
    <div class="notifications-header">
        Notifications
        <span class="close-notifications" onclick="toggleNotifications_hr()">&times;</span>
    </div>
    <div class="notifications-list">
        <?php if (empty($notifications)): ?>
            <div class="notification-item empty">No new notifications.</div>
        <?php else: ?>
            <?php foreach ($notifications as $noti): ?>
                <div class="notification-item">
                    <div class="notification-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="notification-content">
                        <?php $empName = $noti['employee_name'] ?? null; ?>
                        <span class="employee-name"><?php echo htmlspecialchars($empName ?: 'System'); ?></span>
                        <?php echo htmlspecialchars($noti['message'] ?? ''); ?>
                        <?php $ts = $noti['created_at'] ?? null; ?>
                        <span class="notification-time"><?php echo $ts ? date('M d, Y h:i A', strtotime($ts)) : ''; ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
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
    const editProfileModal = document.getElementById('editProfileModal');
    const changePasswordModal_hr = document.getElementById('changePasswordModal');
    const changePictureModal_hr = document.getElementById('changePictureModal');

    function showProfileModal() { // Renamed for consistency if called from dropdown
        if(editProfileModal) editProfileModal.style.display = 'flex';
    }
    function closeEditProfileModal() {
        if(editProfileModal) editProfileModal.style.display = 'none';
    }

    function showChangePasswordModal() {
        if(changePasswordModal_hr) changePasswordModal_hr.style.display = 'flex';
    }
    function closeChangePasswordModal() {
        if(changePasswordModal_hr) changePasswordModal_hr.style.display = 'none';
        const form = document.getElementById('changePasswordForm');
        if(form) form.reset();
    }

    // Removed change picture JS hooks

    // --- Click-to-Toggle Dropdown (HR) ---
    const profileDropdownButton_hr = document.querySelector('.profile-dropdown .profile-btn');
    const profileDropdown_hr = document.querySelector('.profile-dropdown');

    if (profileDropdownButton_hr && profileDropdown_hr) {
        profileDropdownButton_hr.addEventListener('click', function(event) {
            profileDropdown_hr.classList.toggle('active');
            event.stopPropagation();
        });
    }

    function toggleHrPassword(inputId, btn){
        const input = document.getElementById(inputId);
        if(!input) return;
        if(input.type === 'password') {
            input.type = 'text';
            btn.textContent = 'Hide';
        } else {
            input.type = 'password';
            btn.textContent = 'Show';
        }
    }

    // Close dropdown when clicking outside (combined with modal closing)
    window.addEventListener('click', function(event) {
         // Close HR Dropdown
        if (profileDropdown_hr && profileDropdown_hr.classList.contains('active')) {
            if (!profileDropdown_hr.contains(event.target)) {
                profileDropdown_hr.classList.remove('active');
            }
        }
        // Close Modals (existing logic)
        if (event.target == editProfileModal) {
            closeEditProfileModal();
        }
        if (event.target == changePasswordModal_hr) {
            closeChangePasswordModal();
        }
        if (event.target == changePictureModal_hr) {
            closeChangePictureModal();
        }
    });
    // --- End Click-to-Toggle ---

     // Form validation for password match
    const cpFormHr = document.getElementById('changePasswordForm');
    if(cpFormHr) {
        cpFormHr.onsubmit = function(e) {
            const newPassword = cpFormHr.querySelector('input[name="new_password"]').value;
            const confirmPassword = cpFormHr.querySelector('input[name="confirm_password"]').value;
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('New password and confirmation do not match.');
            }
        };
    }

    // --- Notifications Toggle (HR) ---
    const notificationsButton_hr = document.getElementById('notificationsButton_hr');
    const notificationsContainer_hr = document.getElementById('notificationsContainer_hr');
    const notificationCount_hr = document.getElementById('notificationCount_hr');

    async function toggleNotifications_hr() {
        notificationsContainer_hr.classList.toggle('active');
        if (!notificationsContainer_hr.classList.contains('active')) return;
        try {
            await fetch('mark_notifications_read.php', { method: 'POST' });
            if (notificationCount_hr) { notificationCount_hr.textContent = '0'; }
        } catch (_) {}
    }

    if (notificationsButton_hr && notificationsContainer_hr) {
        notificationsButton_hr.addEventListener('click', function(event) {
            toggleNotifications_hr();
            event.stopPropagation();
        });
    }

    // Close notifications when clicking outside
    window.addEventListener('click', function(event) {
        if (notificationsContainer_hr.classList.contains('active') && !notificationsContainer_hr.contains(event.target) && event.target !== notificationsButton_hr) {
            notificationsContainer_hr.classList.remove('active');
        }
    });

    // Set notification count (PHP variable needed)
    const notificationCount = <?php echo count($notifications); ?>;
    if (notificationCount_hr) {
        notificationCount_hr.textContent = notificationCount;
    }
    // --- End Notifications ---

    // Close modals with escape key
    window.addEventListener('keydown', function(event) {
        if (event.key === "Escape") {
            closeEditProfileModal();
            closeChangePasswordModal();
            closeChangePictureModal();
            if (notificationsContainer_hr.classList.contains('active')) {
                notificationsContainer_hr.classList.remove('active');
            }
        }
    });
    // Mobile responsiveness
    function checkScreenSize() {
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

        // Mobile menu toggle
        sidebarToggle.addEventListener('click', function() {
            if (window.innerWidth <= 992) {
                sidebar.classList.toggle('mobile-open');
            }
        });
        // Toggle sidebar collapse/expand
const sidebar = document.querySelector('.sidebar');
const sidebarToggle = document.querySelector('.sidebar-toggle');

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', function() {
        sidebar.classList.toggle('collapsed');
        
        // Change icon based on state
        const icon = this.querySelector('i');
        if (sidebar.classList.contains('collapsed')) {
            icon.classList.remove('fa-bars');
            icon.classList.add('fa-chevron-right');
        } else {
            icon.classList.remove('fa-chevron-right');
            icon.classList.add('fa-bars');
        }
    });
}

// Mobile responsiveness
function checkScreenSize() {
    if (window.innerWidth <= 992) {
        sidebar.classList.remove('collapsed');
        if (sidebarToggle) sidebarToggle.style.display = 'flex';
    } else {
        if (sidebarToggle) sidebarToggle.style.display = 'none';
    }
}

// Initialize on load and resize
window.addEventListener('load', checkScreenSize);
window.addEventListener('resize', checkScreenSize);

// Mobile menu toggle
if (sidebarToggle) {
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