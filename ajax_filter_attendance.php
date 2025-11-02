<?php
session_start();

// Check if user is logged in and is a Department Head
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'depthead') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

date_default_timezone_set('Asia/Manila');

// Include attendance calculations
require_once 'attendance_calculations.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Ensure MySQL session timezone matches PHP
$conn->query("SET time_zone = '+08:00'");

// Get filter parameters
$filter_employee_id = $_GET['employee_id'] ?? '';
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_status = $_GET['status'] ?? '';
$filter_attendance_type = $_GET['attendance_type'] ?? '';
$filter_shift = $_GET['shift'] ?? '';
$filter_mode = $_GET['filter_mode'] ?? 'today';
$search_term = $_GET['search'] ?? '';

// Fetch attendance records based on filter mode
$today = date('Y-m-d');
$attendance_records = [];

if ($filter_mode === 'today') {
    $date_filter = $today;
} elseif ($filter_mode === 'date') {
    $date_filter = $filter_date;
} elseif ($filter_mode === 'month') {
    $date_filter = $filter_month;
} else {
    $date_filter = $today;
}

// Build the main query
$sql = "SELECT 
            COALESCE(a.id, 0) as id,
            e.EmployeeID,
            e.EmployeeName,
            e.Position,
            e.Department,
            e.Shift,
            COALESCE(a.attendance_date, ?) as attendance_date,
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
            a.total_hours,
            COALESCE(a.is_on_leave, 0) as is_on_leave,
            CASE 
                WHEN a.id IS NULL THEN 'absent'
                ELSE COALESCE(a.attendance_type, 'present')
            END as final_attendance_type
        FROM empuser e
        LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND DATE(a.attendance_date) = ?
        WHERE e.Status = 'active'";

$params = [$date_filter, $date_filter];
$types = "ss";

// Add filters
if (!empty($filter_status)) {
    if ($filter_status === 'on_leave') {
        $sql .= " AND a.is_on_leave = 1";
    } else {
        $sql .= " AND (a.status = ? OR (a.status IS NULL AND ? = 'absent'))";
        $params[] = $filter_status;
        $params[] = $filter_status;
        $types .= "ss";
    }
}

if (!empty($filter_attendance_type)) {
    if ($filter_attendance_type === 'absent') {
        $sql .= " AND a.id IS NULL";
    } else {
        $sql .= " AND a.attendance_type = ?";
        $params[] = $filter_attendance_type;
        $types .= "s";
    }
}

if (!empty($filter_shift)) {
    $sql .= " AND e.Shift = ?";
    $params[] = $filter_shift;
    $types .= "s";
}

if (!empty($search_term)) {
    $sql .= " AND (e.EmployeeID LIKE ? OR e.EmployeeName LIKE ? OR e.Department LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$sql .= " ORDER BY e.EmployeeName ASC";

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

// Compute attendance stats
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
    $stats = ['total_present' => 0, 'still_present' => 0, 'late_arrivals' => 0, 'early_in' => 0, 'total_absent' => 0];
} else {
    $stmt_stats->bind_param("s", $date_filter);
    
    if (!$stmt_stats->execute()) {
        $stats = ['total_present' => 0, 'still_present' => 0, 'late_arrivals' => 0, 'early_in' => 0, 'total_absent' => 0];
    } else {
        $stats_result = $stmt_stats->get_result();
        $stats = $stats_result->fetch_assoc();
    }
    $stmt_stats->close();
}

// Get total employees company-wide for attendance rate denominator
$total_emp_query = "SELECT COUNT(DISTINCT EmployeeID) as total_employees FROM empuser WHERE Status = 'active'";
$total_result = $conn->query($total_emp_query);
if ($total_result) {
    $total_data = $total_result->fetch_assoc();
    $total_employees = (int)($total_data['total_employees'] ?? 0);
} else {
    $total_employees = 0;
}

// Calculate attendance rate
$total_present = (int)($stats['total_present'] ?? 0);
$total_absent = (int)($stats['total_absent'] ?? 0);
$total_late = (int)($stats['late_arrivals'] ?? 0);
$attendance_rate = $total_employees > 0 
    ? round(($total_present / $total_employees) * 100)
    : 0;

// Prepare response data
$response = [
    'success' => true,
    'data' => [
        'attendance_records' => $attendance_records,
        'stats' => [
            'total_present' => $total_present,
            'total_absent' => $total_absent,
            'total_late' => $total_late,
            'attendance_rate' => $attendance_rate
        ],
        'filters' => [
            'date_filter' => $date_filter,
            'filter_mode' => $filter_mode,
            'applied_filters' => [
                'status' => $filter_status,
                'attendance_type' => $filter_attendance_type,
                'shift' => $filter_shift,
                'search' => $search_term
            ]
        ]
    ]
];

$conn->close();

// Set JSON header
header('Content-Type: application/json');

// Add error handling for JSON encoding
$json_response = json_encode($response);
if ($json_response === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to encode response data']);
} else {
    echo $json_response;
}
?>
