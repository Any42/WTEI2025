<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

// Check if user is logged in and has HR role
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'hr') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'attendance_calculations.php';

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

// Align MySQL session timezone
$conn->query("SET time_zone = '+08:00'");

// Get date range from POST or default to current month
$start_date = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-01');
$end_date = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-t');

// Validate date range
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

if (strtotime($start_date) > strtotime($end_date)) {
    echo json_encode(['success' => false, 'message' => 'Start date cannot be after end date']);
    exit;
}

try {
    // Recalculate overtime with enhanced accuracy
    $summary = AttendanceCalculator::recalculateOvertimeAccurately($conn, $start_date, $end_date);
    
    // Log the recalculation activity
    $log_message = sprintf(
        'HR recalculated overtime for %d records (%s to %s). Updated: %d, Corrected: %d, Added: %d, Removed: %d',
        $summary['total_records'],
        $start_date,
        $end_date,
        $summary['updated_records'],
        $summary['overtime_corrected'],
        $summary['overtime_added'],
        $summary['overtime_removed']
    );
    
    // Insert notification
    $conn->query("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(50), message TEXT, created_at DATETIME, unread TINYINT(1) DEFAULT 1)");
    $stmt = $conn->prepare("INSERT INTO notifications(type, message, created_at, unread) VALUES('overtime_recalc', ?, NOW(), 1)");
    $stmt->bind_param("s", $log_message);
    $stmt->execute();
    $stmt->close();
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Overtime records recalculated successfully',
        'summary' => $summary
    ]);
    
} catch (Exception $e) {
    $conn->close();
    echo json_encode([
        'success' => false,
        'message' => 'Error recalculating overtime: ' . $e->getMessage()
    ]);
}
?>
