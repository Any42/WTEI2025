<?php
// Restore required bootstrap initialization to avoid undefined vars and parse errors
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

// Check if user is logged in and is a Department Head
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'depthead' || !isset($_SESSION['user_department'])) {
    header("Location: Login.php");
    exit;
}

$dept_head_name = $_SESSION['username'] ?? 'Dept Head'; // Default if not set
$managed_department = $_SESSION['user_department'];
$dept_head_id = $_SESSION['userid'] ?? null; // Assuming userid stores DeptHeadID

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1"; // Make sure this is your correct database name
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Set timezone for consistent date handling (exactly like HRAttendance.php)
$conn->query("SET time_zone = '+08:00'");

// Define who has payroll access
$has_payroll_access = ($managed_department == 'Accounting'); // Accounting department automatically has access

// --- Statistics for ALL Employees (Company-wide) ---
$stats = [
    'total_employees' => 0,
    'present_today' => 0,
    'absent_today' => 0,
    'attendance_rate' => 0,
    'deductions_total' => 0
];

// Define today's date (exactly like HRAttendance.php)
$today = date('Y-m-d');

// Get comprehensive statistics using LEFT JOIN (exactly like HRAttendance.php)
$date_condition = "DATE(a.attendance_date) = ?";
$stats_query = "SELECT 
                COUNT(DISTINCT CASE WHEN a.attendance_type = 'present' THEN e.EmployeeID END) as total_present,
                COUNT(DISTINCT CASE WHEN a.attendance_type = 'absent' OR a.id IS NULL THEN e.EmployeeID END) as total_absent
                FROM empuser e
                LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND $date_condition
                WHERE e.Status = 'active'";

$stmt_stats = $conn->prepare($stats_query);
if (!$stmt_stats) {
    error_log("Stats prepare failed: " . $conn->error);
    $stats = ['present_today' => 0, 'absent_today' => 0];
} else {
    $stmt_stats->bind_param("s", $today);
    
    if (!$stmt_stats->execute()) {
        error_log("Stats execute failed: " . $stmt_stats->error);
        $stats = ['present_today' => 0, 'absent_today' => 0];
    } else {
        $stats_result = $stmt_stats->get_result();
        $stats_data = $stats_result->fetch_assoc();
        
        $stats['present_today'] = (int)($stats_data['total_present'] ?? 0);
        $stats['absent_today'] = (int)($stats_data['total_absent'] ?? 0);
    }
    $stmt_stats->close();
}

// Total employees in the company (only active employees) - exactly like HRAttendance.php
$total_emp_query = "SELECT COUNT(DISTINCT EmployeeID) as total_employees FROM empuser WHERE Status = 'active'";
$total_result = $conn->query($total_emp_query);
if ($total_result) {
    $total_data = $total_result->fetch_assoc();
    $stats['total_employees'] = (int)($total_data['total_employees'] ?? 0);
} else {
    error_log("Total employees query failed: " . $conn->error);
    $stats['total_employees'] = 0;
}

// Calculate attendance rate (exactly like HRAttendance.php)
$stats['attendance_rate'] = $stats['total_employees'] > 0 
    ? round(($stats['present_today'] / $stats['total_employees']) * 100)
    : 0;

// Calculate deductions total for current month (only for Accounting department)
if ($has_payroll_access) {
    $current_month = date('Y-m');
    $stmt_deductions = $conn->prepare("SELECT SUM(COALESCE(total_deductions, 0) + COALESCE(deduction, 0)) as total 
                             FROM payroll p 
                             JOIN empuser e ON p.EmployeeID = e.EmployeeID
                             WHERE DATE_FORMAT(p.payment_date, '%Y-%m') = ? 
                             AND e.Department = ?");
    if ($stmt_deductions) {
        $stmt_deductions->bind_param("ss", $current_month, $managed_department);
        $stmt_deductions->execute();
        $result_deductions = $stmt_deductions->get_result();
        $stats['deductions_total'] = $result_deductions->fetch_assoc()['total'] ?? 0;
        $stmt_deductions->close();
    }
}

$conn->close();

function getInitials($fullName) {
    $fullName = trim((string)$fullName);
    if ($fullName === '') return 'DH';
    $parts = preg_split('/\s+/', $fullName);
    $first = strtoupper(substr($parts[0] ?? '', 0, 1));
    $last = strtoupper(substr($parts[count($parts)-1] ?? '', 0, 1));
    $initials = $first . ($last !== '' ? $last : '');
    return $initials !== '' ? $initials : 'DH';
}
$dept_head_initials = getInitials($dept_head_name);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Head Dashboard - <?php echo htmlspecialchars($managed_department); ?> - WTEI</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="depthead-styles.css/depthead-styles.css?v=<?php echo time(); ?>">
    <style>
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
            border: 1px solid #DBE2EF;
        }

        /* Enhanced Card Styles */
        .card-subtitle {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
            font-weight: 400;
        }

        .welcome-content h3 {
            color: #112D4E;
            margin-bottom: 15px;
            font-size: 20px;
        }

        .welcome-content p {
            margin-bottom: 10px;
            line-height: 1.6;
            color: #555;
        }

        .access-note {
            background: linear-gradient(135deg, #E3F2FD 0%, #BBDEFB 100%);
            border: 1px solid #3F72AF;
            border-radius: 8px;
            padding: 12px 15px;
            margin-top: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #112D4E;
        }

        .access-note i {
            color: #3F72AF;
            font-size: 16px;
        }

        /* Notification Button Styles */
        .notification-badge {
            background-color: #ff4757;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 10px;
            position: absolute;
            top: -8px;
            right: -8px;
            min-width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .btn {
            position: relative;
        }

        /* Payroll Notifications Container */
        .notifications-container {
            position: absolute;
            top: 70px;
            right: 20px;
            width: 400px;
            max-height: 500px;
            background-color: white;
            border: 1px solid #DBE2EF;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(17, 45, 78, 0.15);
            z-index: 1000;
            overflow: hidden;
            display: none;
            opacity: 0;
            transform: translateY(-10px);
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .notifications-container.active {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .notifications-header {
            background: linear-gradient(135deg, #112D4E 0%, #3F72AF 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notifications-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        .close-notifications {
            font-size: 20px;
            cursor: pointer;
            color: white;
            line-height: 1;
            transition: color 0.2s ease;
        }

        .close-notifications:hover {
            color: #DBE2EF;
        }

        .notifications-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #DBE2EF;
            transition: background-color 0.2s ease;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item:hover {
            background-color: #F9F7F7;
        }

        .notification-item.empty {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 30px 20px;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .notification-icon i {
            font-size: 16px;
        }

        .notification-content {
            flex-grow: 1;
            font-size: 14px;
            color: #333;
            line-height: 1.4;
        }

        .notification-content .employee-name {
            font-weight: 600;
            color: #112D4E;
            margin-right: 5px;
        }

        .notification-time {
            display: block;
            font-size: 12px;
            color: #666;
            margin-top: 3px;
        }

        /* Enhanced Dashboard Cards */
        .dashboard-cards .card {
            transition: all 0.3s ease;
            border: 1px solid #DBE2EF;
            position: relative;
            overflow: hidden;
        }

        .dashboard-cards .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #3F72AF 0%, #112D4E 100%);
        }

        .dashboard-cards .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(17, 45, 78, 0.15);
            border-color: #3F72AF;
        }

        .card-icon {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            border: none;
        }

        .card-value {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
        }
        
        @media (max-width: 480px) {
            .dashboard-cards {
                grid-template-columns: 1fr;
                gap: 10px;
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
            <a href="DeptHeadDashboard.php" class="menu-item active">
                <i class="fas fa-th-large"></i>
                <span>Dashboard</span>
            </a>
            <a href="DeptHeadEmployees.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span>Employees</span>
            </a>
            <a href="DeptHeadAttendance.php" class="menu-item">
                <i class="fas fa-calendar-check"></i>
                <span>Attendance</span>
            </a>
            
            <?php if($managed_department == 'Accounting'): ?>
            <a href="Payroll.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payroll</span>
            </a>
            <a href="DeptHeadHistory.php" class="menu-item">
                <i class="fas fa-history"></i> History
            </a>
            <?php endif; ?>
            <!-- Add other relevant links if needed -->
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">Company Dashboard - <?php echo htmlspecialchars($managed_department); ?> Department Head</h1>
            <div class="header-actions">
                <?php if($has_payroll_access): ?>
                <button class="btn btn-primary" onclick="togglePayrollNotifications()">
                    <i class="fas fa-bell"></i> Payroll Notifications
                    <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
                </button>
                <?php endif; ?>
                <div class="profile-dropdown">
                    <button class="profile-btn">
                        <div class="profile-initials-avatar"><?php echo htmlspecialchars($dept_head_initials); ?></div>
                        <span><?php echo htmlspecialchars($dept_head_name); ?></span>
                        <i class="fas fa-caret-down"></i>
                    </button>
                    <div class="dropdown-content">
                        <a href="#" onclick="document.getElementById('changePasswordModalDh').style.display='block'; document.querySelector('.profile-dropdown').classList.remove('active');">
                            <i class="fas fa-key"></i> Change Password
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Payroll Notifications Container -->
        <div id="payrollNotifications" class="notifications-container" style="display: none;">
            <div class="notifications-header">
                <h3>Recent Payroll Activities</h3>
                <span class="close-notifications" onclick="togglePayrollNotifications()">&times;</span>
            </div>
            <div class="notifications-list" id="payrollNotificationsList">
                <div class="notification-item empty">
                    <i class="fas fa-spinner fa-spin"></i> Loading recent payroll activities...
                </div>
            </div>
        </div>

        <div class="dashboard-cards">
            <div class="card">
                <div class="card-content">
                    <div class="card-icon"><i class="fas fa-users"></i></div>
                    <div class="card-title">Total Employees</div>
                    <div class="card-value"><?php echo $stats['total_employees']; ?></div>
                    <div class="card-subtitle">Active Company-wide</div>
                </div>
            </div>
            <div class="card">
                <div class="card-content">
                    <div class="card-icon"><i class="fas fa-calendar-check"></i></div>
                    <div class="card-title">Present Today</div>
                    <div class="card-value"><?php echo $stats['present_today']; ?></div>
                    <div class="card-subtitle"><?php echo date('M d, Y'); ?></div>
                </div>
            </div>
            <div class="card">
                <div class="card-content">
                    <div class="card-icon"><i class="fas fa-percentage"></i></div>
                    <div class="card-title">Attendance Rate</div>
                    <div class="card-value"><?php echo $stats['attendance_rate']; ?>%</div>
                    <div class="card-subtitle">Company-wide Rate</div>
                </div>
            </div>
            <div class="card">
                <div class="card-content">
                    <div class="card-icon"><i class="fas fa-user-times"></i></div>
                    <div class="card-title">Absent Today</div>
                    <div class="card-value"><?php echo $stats['absent_today']; ?></div>
                    <div class="card-subtitle">Not Present</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Department Overview</h2>
            </div>
            <div class="card-body" style="padding: 20px;">
                <div class="welcome-content">
                    <h3>Welcome, <strong><?php echo htmlspecialchars($dept_head_name); ?></strong>!</h3>
                    <p>You are managing the <strong><?php echo htmlspecialchars($managed_department); ?></strong> department.</p>
                    <p>This dashboard shows company-wide statistics and allows you to view employee details and track attendance across all departments.</p>
                    <?php if($managed_department == 'Accounting'): ?>
                    <div class="access-note">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong> As an Accounting department head, you have access to the payroll management system and company-wide statistics.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Change Password Modal -->
        <div id="changePasswordModalDh" class="modal" style="display:none;">
            <div class="modal-content" style="max-width:500px;margin:5% auto; padding:0; border-radius:12px; overflow:hidden;">
                <div class="modal-header" style="background:#112D4E;color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
                    <h2 style="margin:0;font-size:18px;">Change Password</h2>
                    <span style="cursor:pointer;" onclick="document.getElementById('changePasswordModalDh').style.display='none'">&times;</span>
                </div>
                <form method="POST" action="change_depthead_password.php">
                    <div class="modal-body" style="padding:20px;">
                        <div class="form-group" style="margin-bottom:14px;">
                            <label for="current_dh_password">Current Password</label>
                            <input type="password" name="current_password" id="current_dh_password" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin-bottom:14px;">
                            <label for="new_dh_password">New Password</label>
                            <div style="position: relative;">
                                <input type="password" name="new_password" id="new_dh_password" class="form-control" required minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}">
                                <button type="button" onclick="toggleDhPassword('new_dh_password', this)" style="position:absolute; right:8px; top:50%; transform: translateY(-50%); background:none; border:none; cursor:pointer; color:#3F72AF;">Show</button>
                            </div>
                            <small style="color:#6c757d;">At least 8 characters, include numbers, lowercase and uppercase letters.</small>
                        </div>
                        <div class="form-group" style="margin-bottom:14px;">
                            <label for="confirm_dh_password">Confirm New Password</label>
                            <div style="position: relative;">
                                <input type="password" name="confirm_password" id="confirm_dh_password" class="form-control" required minlength="8">
                                <button type="button" onclick="toggleDhPassword('confirm_dh_password', this)" style="position:absolute; right:8px; top:50%; transform: translateY(-50%); background:none; border:none; cursor:pointer; color:#3F72AF;">Show</button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="padding:16px 20px; display:flex; gap:10px; justify-content:flex-end; border-top:1px solid #eee;">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('changePasswordModalDh').style.display='none'">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Update Password</button>
                    </div>
                </form>
            </div>
        </div>
         <!-- Additional sections for recent activities, quick links etc. can be added here -->

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
        
        // Basic JS for profile dropdown toggle
        const profileDropdownButton = document.querySelector('.profile-dropdown .profile-btn');
        const profileDropdown = document.querySelector('.profile-dropdown');

        if (profileDropdownButton && profileDropdown) {
            profileDropdownButton.addEventListener('click', function(event) {
                profileDropdown.classList.toggle('active'); // Add 'active' class to toggle visibility
                event.stopPropagation();
            });
        }
        // Close dropdown when clicking outside
        window.addEventListener('click', function(event) {
            if (profileDropdown && profileDropdown.classList.contains('active')) {
                if (!profileDropdown.contains(event.target)) {
                    profileDropdown.classList.remove('active');
                }
            }
        });

        // Toggle show/hide for Dept Head change password modal inputs
        function toggleDhPassword(inputId, btn){
            const input = document.getElementById(inputId);
            if(!input) return;
            if(input.type === 'password') { input.type = 'text'; btn.textContent = 'Hide'; }
            else { input.type = 'password'; btn.textContent = 'Show'; }
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('changePasswordModalDh');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });

        // Form validation for password change
        function validatePasswordForm() {
            const newPassword = document.getElementById('new_dh_password').value;
            const confirmPassword = document.getElementById('confirm_dh_password').value;
            
            if (newPassword !== confirmPassword) {
                alert('New password and confirmation do not match.');
                return false;
            }
            
            if (newPassword.length < 8) {
                alert('Password must be at least 8 characters long.');
                return false;
            }
            
            const hasNumber = /\d/.test(newPassword);
            const hasLower = /[a-z]/.test(newPassword);
            const hasUpper = /[A-Z]/.test(newPassword);
            
            if (!hasNumber || !hasLower || !hasUpper) {
                alert('Password must include numbers, lowercase and uppercase letters.');
                return false;
            }
            
            return true;
        }

        // Add form validation to the change password form
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form[action="change_depthead_password.php"]');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!validatePasswordForm()) {
                        e.preventDefault();
                    }
                });
            }
            
            // Load payroll notifications on page load
            loadPayrollNotifications();
        });

        // Toggle payroll notifications
        function togglePayrollNotifications() {
            const container = document.getElementById('payrollNotifications');
            if (container.style.display === 'none' || container.style.display === '') {
                container.style.display = 'block';
                container.classList.add('active');
                loadPayrollNotifications();
            } else {
                container.classList.remove('active');
                setTimeout(() => {
                    container.style.display = 'none';
                }, 300);
            }
        }

        // Load payroll notifications
        function loadPayrollNotifications() {
            const notificationsList = document.getElementById('payrollNotificationsList');
            const badge = document.getElementById('notificationBadge');
            
            // Show loading state
            notificationsList.innerHTML = '<div class="notification-item empty"><i class="fas fa-spinner fa-spin"></i> Loading recent payroll activities...</div>';
            
            // Fetch recent payroll data
            fetch('get_recent_payroll.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.notifications.length > 0) {
                        displayNotifications(data.notifications);
                        updateBadge(data.notifications.length);
                    } else {
                        displayNoNotifications();
                        hideBadge();
                    }
                })
                .catch(error => {
                    console.error('Error loading payroll notifications:', error);
                    displayError();
                    hideBadge();
                });
        }

        // Display notifications
        function displayNotifications(notifications) {
            const notificationsList = document.getElementById('payrollNotificationsList');
            notificationsList.innerHTML = '';
            
            notifications.forEach(notification => {
                const notificationItem = document.createElement('div');
                notificationItem.className = 'notification-item';
                notificationItem.innerHTML = `
                    <div class="notification-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="notification-content">
                        <span class="employee-name">${notification.employee_name}</span>
                        ${notification.message}
                        <span class="notification-time">${notification.time_ago}</span>
                    </div>
                `;
                notificationsList.appendChild(notificationItem);
            });
        }

        // Display no notifications message
        function displayNoNotifications() {
            const notificationsList = document.getElementById('payrollNotificationsList');
            notificationsList.innerHTML = '<div class="notification-item empty"><i class="fas fa-info-circle"></i> No recent payroll activities found.</div>';
        }

        // Display error message
        function displayError() {
            const notificationsList = document.getElementById('payrollNotificationsList');
            notificationsList.innerHTML = '<div class="notification-item empty"><i class="fas fa-exclamation-triangle"></i> Error loading notifications. Please try again.</div>';
        }

        // Update notification badge
        function updateBadge(count) {
            const badge = document.getElementById('notificationBadge');
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'flex';
            } else {
                hideBadge();
            }
        }

        // Hide notification badge
        function hideBadge() {
            const badge = document.getElementById('notificationBadge');
            badge.style.display = 'none';
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