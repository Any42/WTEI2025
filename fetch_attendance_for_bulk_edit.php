<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

// Check if user is logged in and is HR
if (!isset($_SESSION['loggedin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Connection Failed: ' . $conn->connect_error]);
    exit;
}

// Ensure MySQL session uses the same timezone for date comparisons
$conn->query("SET time_zone = '+08:00'");

// Get filter parameters
$department_filter = isset($_GET['department']) ? $conn->real_escape_string($_GET['department']) : '';
$shift_filter = isset($_GET['shift']) ? $conn->real_escape_string($_GET['shift']) : '';
$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
$late_only_filter = isset($_GET['late_only']) && $_GET['late_only'] == '1';
$type_filter = isset($_GET['attendance_type']) ? $conn->real_escape_string($_GET['attendance_type']) : '';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$month_filter = isset($_GET['month']) ? $_GET['month'] : '';
$year_filter = isset($_GET['year']) ? $_GET['year'] : '';

// Build date condition based on filter type (align with attendance view defaults)
$date_condition = "";
$date_param = null;

if ($date_filter) {
    $date_condition = "DATE(a.attendance_date) = ?";
    $date_param = $date_filter;
} elseif ($month_filter) {
    $date_condition = "DATE_FORMAT(a.attendance_date, '%Y-%m') = ?";
    $date_param = $month_filter;
} elseif ($year_filter) {
    $date_condition = "YEAR(a.attendance_date) = ?";
    $date_param = $year_filter;
} else {
    // Default to today - use same timezone as main attendance page
    $date_condition = "DATE(a.attendance_date) = ?";
    $date_param = date('Y-m-d');
}

// Build the query to get ALL active employees with their attendance records for the specified date range
// This will show ALL active employees, even if they don't have attendance records for the filtered date
$query = "
    SELECT 
        e.EmployeeID,
        e.EmployeeName,
        e.Department,
        e.Shift,
        COALESCE(a.attendance_date, '') as attendance_date,
        COALESCE(a.attendance_type, 'absent') as attendance_type,
        COALESCE(a.time_in, '') as time_in,
        COALESCE(a.time_out, '') as time_out,
        COALESCE(a.time_in_morning, '') as time_in_morning,
        COALESCE(a.time_out_morning, '') as time_out_morning,
        COALESCE(a.time_in_afternoon, '') as time_in_afternoon,
        COALESCE(a.time_out_afternoon, '') as time_out_afternoon,
        COALESCE(a.late_minutes, 0) as late_minutes,
        COALESCE(a.early_out_minutes, 0) as early_out_minutes,
        COALESCE(a.overtime_hours, 0) as overtime_hours,
        COALESCE(a.status, 'absent') as status
    FROM empuser e
    LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND " . $date_condition . "
    WHERE e.Status = 'active'
";

$params = [$date_param];
$param_types = "s";

// Add department filter
if ($department_filter) {
    $query .= " AND e.Department = ?";
    $params[] = $department_filter;
    $param_types .= "s";
}

// Add shift filter
if ($shift_filter) {
    $query .= " AND e.Shift = ?";
    $params[] = $shift_filter;
    $param_types .= "s";
}

// Add search filter
if ($search_term) {
    $query .= " AND (e.EmployeeName LIKE ? OR e.EmployeeID LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ss";
}

// Add status filter - but don't filter out absent employees
if ($status_filter) {
    if ($status_filter === 'present') {
        $query .= " AND a.attendance_type = 'present'";
    } elseif ($status_filter === 'absent') {
        $query .= " AND (a.attendance_type = 'absent' OR a.attendance_type IS NULL)";
    }
}

// Add attendance type filter - but don't filter out absent employees
if ($type_filter) {
    if ($type_filter === 'present') {
        $query .= " AND a.attendance_type = 'present'";
    } elseif ($type_filter === 'absent') {
        $query .= " AND (a.attendance_type = 'absent' OR a.attendance_type IS NULL)";
    }
}

// Add late only filter
if ($late_only_filter) {
    $query .= " AND a.late_minutes > 0";
}

$query .= " ORDER BY e.EmployeeName";

$stmt = $conn->prepare($query);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query preparation failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$employees = [];
while ($row = $result->fetch_assoc()) {
    $employees[] = [
        'EmployeeID' => $row['EmployeeID'],
        'EmployeeName' => $row['EmployeeName'],
        'Department' => $row['Department'],
        'Shift' => $row['Shift'],
        'attendance_date' => $row['attendance_date'],
        'attendance_type' => $row['attendance_type'],
        'time_in' => $row['time_in'],
        'time_out' => $row['time_out'],
        'time_in_morning' => $row['time_in_morning'],
        'time_out_morning' => $row['time_out_morning'],
        'time_in_afternoon' => $row['time_in_afternoon'],
        'time_out_afternoon' => $row['time_out_afternoon'],
        'late_minutes' => $row['late_minutes'],
        'early_out_minutes' => $row['early_out_minutes'],
        'overtime_hours' => $row['overtime_hours'],
        'status' => $row['status']
    ];
}

$stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'employees' => $employees,
    'total' => count($employees)
]);
?>