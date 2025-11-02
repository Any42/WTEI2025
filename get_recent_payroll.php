<?php
session_start();

// Check if user is logged in and is a Department Head with payroll access
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'depthead' || !isset($_SESSION['user_department'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

$managed_department = $_SESSION['user_department'];
$has_payroll_access = ($managed_department == 'Accounting');

if (!$has_payroll_access) {
    echo json_encode(['success' => false, 'error' => 'No payroll access']);
    exit;
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Set timezone
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");

try {
    // Get recent payroll activities for the department (last 10 records)
    $query = "SELECT p.*, e.EmployeeName, e.Department
              FROM payroll p 
              JOIN empuser e ON p.EmployeeID = e.EmployeeID
              WHERE e.Department = ? 
              ORDER BY p.created_at DESC 
              LIMIT 10";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $managed_department);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    
    while ($row = $result->fetch_assoc()) {
        $time_ago = getTimeAgo($row['created_at']);
        
        // Create notification message based on payroll data
        $message = "Payroll processed - Net Pay: ₱" . number_format($row['net_pay'], 2);
        if ($row['payment_type']) {
            $message .= " (" . $row['payment_type'] . ")";
        }
        
        $notifications[] = [
            'employee_name' => $row['EmployeeName'],
            'message' => $message,
            'time_ago' => $time_ago,
            'payment_date' => $row['payment_date'],
            'net_pay' => $row['net_pay'],
            'payment_type' => $row['payment_type']
        ];
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications
    ]);
    
} catch (Exception $e) {
    $conn->close();
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch payroll data'
    ]);
}

function getTimeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) {
        return 'Just now';
    } elseif ($time < 3600) {
        $minutes = floor($time / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($time < 86400) {
        $hours = floor($time / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($time < 2592000) {
        $days = floor($time / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', strtotime($datetime));
    }
}
?>
