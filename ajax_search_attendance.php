<?php
session_start();

// Check if user is logged in and is HR
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'hr') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Include attendance calculations
require_once 'attendance_calculations.php';

// Connect to the database
$host = "localhost";
$user = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($host, $user, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get search parameters
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$filter_month = isset($_GET['month']) ? $_GET['month'] : '';
$filter_year = isset($_GET['year']) ? $_GET['year'] : '';
$filter_department = isset($_GET['department']) ? $_GET['department'] : '';
$filter_shift = isset($_GET['shift']) ? $_GET['shift'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_attendance_type = isset($_GET['attendance_type']) ? $_GET['attendance_type'] : '';

// Get system date range first
$date_range_sql = "SELECT 
    MIN(DATE(a.attendance_date)) as earliest_date,
    MAX(DATE(a.attendance_date)) as latest_date,
    COUNT(DISTINCT DATE(a.attendance_date)) as actual_recorded_days
    FROM attendance a 
    JOIN empuser e ON a.EmployeeID = e.EmployeeID 
    WHERE e.Status='active'";
$date_range_stmt = $conn->prepare($date_range_sql);
$date_range_stmt->execute();
$date_range_result = $date_range_stmt->get_result();
$date_range_data = $date_range_result->fetch_assoc();
$date_range_stmt->close();

$system_start_date = $date_range_data['earliest_date'] ?? date('Y-m-d');
$system_end_date = $date_range_data['latest_date'] ?? date('Y-m-d');
$actual_recorded_days = (int)($date_range_data['actual_recorded_days'] ?? 0);

// Build WHERE conditions for employees
$employee_where = ["e.Status='active'"];
$employee_params = [];
$employee_types = "";

// Add search condition
if (!empty($search_term)) {
    $employee_where[] = "(e.EmployeeName LIKE ? OR e.EmployeeID LIKE ?)";
    $search_param = "%$search_term%";
    $employee_params[] = $search_param;
    $employee_params[] = $search_param;
    $employee_types .= "ss";
}

// Add department filter
if (!empty($filter_department)) {
    $employee_where[] = "e.Department = ?";
    $employee_params[] = $filter_department;
    $employee_types .= 's';
}

// Add shift filter
if (!empty($filter_shift)) {
    $employee_where[] = "e.Shift = ?";
    $employee_params[] = $filter_shift;
    $employee_types .= 's';
}

// Function to generate all working days (excluding Sundays) within a date range
function generateWorkingDays($startDate, $endDate, $filterType = null, $filterValue = null) {
    $workingDays = [];
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    
    while ($start <= $end) {
        // Skip Sundays (day of week 0 = Sunday)
        if ($start->format('w') != 0) {
            $dateStr = $start->format('Y-m-d');
            
            // Apply specific filters
            if ($filterType === 'month' && $filterValue) {
                if (date('Y-m', strtotime($dateStr)) === $filterValue) {
                    $workingDays[] = $dateStr;
                }
            } elseif ($filterType === 'year' && $filterValue) {
                if (date('Y', strtotime($dateStr)) === $filterValue) {
                    $workingDays[] = $dateStr;
                }
            } elseif ($filterType === 'date' && $filterValue) {
                if ($dateStr === $filterValue) {
                    $workingDays[] = $dateStr;
                }
            } elseif (!$filterType) {
                $workingDays[] = $dateStr;
            }
        }
        $start->add(new DateInterval('P1D'));
    }
    
    return $workingDays;
}

// Determine the date range for the query
$query_start_date = $system_start_date;
$query_end_date = $system_end_date;
$filter_type = null;
$filter_value = null;

if (!empty($filter_date)) {
    $query_start_date = $filter_date;
    $query_end_date = $filter_date;
    $filter_type = 'date';
    $filter_value = $filter_date;
} elseif (!empty($filter_month)) {
    $month_start = $filter_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $query_start_date = max($month_start, $system_start_date);
    $query_end_date = min($month_end, $system_end_date);
    $filter_type = 'month';
    $filter_value = $filter_month;
} elseif (!empty($filter_year)) {
    $year_start = $filter_year . '-01-01';
    $year_end = $filter_year . '-12-31';
    $query_start_date = max($year_start, $system_start_date);
    $query_end_date = min($year_end, $system_end_date);
    $filter_type = 'year';
    $filter_value = $filter_year;
}

// Generate working days for the query range
$working_days = generateWorkingDays($query_start_date, $query_end_date, $filter_type, $filter_value);

// Build the main query with proper absent record generation
if (!empty($working_days)) {
    // Create a temporary table with all working days and employees
    $working_days_str = "'" . implode("','", $working_days) . "'";
    
    $query = "SELECT 
                COALESCE(a.id, 0) as id,
                e.EmployeeID,
                COALESCE(a.attendance_date, wd.working_date) as attendance_date,
                COALESCE(a.attendance_type, 'absent') as attendance_type,
                COALESCE(a.status, 'no_record') as status,
                COALESCE(a.status, 'no_record') as db_status,
                COALESCE(a.time_in, NULL) as time_in,
                COALESCE(a.time_out, NULL) as time_out,
                COALESCE(a.time_in_morning, NULL) as time_in_morning,
                COALESCE(a.time_out_morning, NULL) as time_out_morning,
                COALESCE(a.time_in_afternoon, NULL) as time_in_afternoon,
                COALESCE(a.time_out_afternoon, NULL) as time_out_afternoon,
                COALESCE(a.data_source, 'biometric') as data_source,
                COALESCE(a.overtime_hours, 0) as overtime_hours,
                COALESCE(a.total_hours, 0) as total_hours,
                COALESCE(a.late_minutes, 0) as late_minutes,
                COALESCE(a.early_out_minutes, 0) as early_out_minutes,
                e.EmployeeName,
                e.Department,
                e.Shift
              FROM empuser e 
              CROSS JOIN (
                  SELECT working_date FROM (
                      SELECT '{$working_days[0]}' as working_date" . 
                      (count($working_days) > 1 ? " UNION ALL SELECT '" . implode("' UNION ALL SELECT '", array_slice($working_days, 1)) . "'" : "") . "
                  ) as wd
              ) wd
              LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID 
                  AND DATE(a.attendance_date) = wd.working_date
              WHERE " . implode(' AND ', $employee_where) . "
                  AND wd.working_date >= e.DateHired
              ORDER BY wd.working_date DESC, COALESCE(a.time_in, '00:00:00') DESC";
} else {
    // Fallback to original query if no working days
    $query = "SELECT 
                a.id,
                a.EmployeeID,
                a.attendance_date,
                a.attendance_type,
                a.status,
                a.time_in,
                a.time_out,
                a.time_in_morning,
                a.time_out_morning,
                a.time_in_afternoon,
                a.time_out_afternoon,
                COALESCE(a.data_source, 'biometric') as data_source,
                a.overtime_hours,
                a.total_hours,
                a.late_minutes,
                a.early_out_minutes,
                e.EmployeeName,
                e.Department,
                e.Shift
              FROM attendance a 
              JOIN empuser e ON a.EmployeeID = e.EmployeeID
              WHERE " . implode(' AND ', $employee_where) . "
              AND DATE(a.attendance_date) BETWEEN ? AND ?
              ORDER BY a.attendance_date DESC, a.time_in DESC";
}

// Add attendance type filter for all cases
if (!empty($filter_attendance_type)) {
    if ($filter_attendance_type === 'present') {
        $query = str_replace("WHERE " . implode(' AND ', $employee_where), 
                            "WHERE " . implode(' AND ', $employee_where) . " AND a.attendance_type = 'present'", $query);
    } elseif ($filter_attendance_type === 'absent') {
        $query = str_replace("WHERE " . implode(' AND ', $employee_where), 
                            "WHERE " . implode(' AND ', $employee_where) . " AND (a.id IS NULL OR a.attendance_type = 'absent')", $query);
    }
}

// Prepare parameters for binding
$all_params = [];
$all_types = "";

// Add employee WHERE parameters
$all_params = array_merge($all_params, $employee_params);
$all_types .= $employee_types;

// Add parameters for fallback query (when no working days)
if (empty($working_days)) {
    $all_params[] = $query_start_date;
    $all_params[] = $query_end_date;
    $all_types .= "ss";
}

// Status filter parameters are now handled directly in the query string, no parameters needed

// Execute query
$stmt = $conn->prepare($query);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query preparation failed: ' . $conn->error]);
    exit();
}

// Check parameter count
$expected_params = substr_count($query, '?');
$actual_params = count($all_params);

if ($expected_params !== $actual_params) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => "Parameter count mismatch: Expected $expected_params, got $actual_params"
    ]);
    exit();
}

if (!empty($all_params)) {
    if (!$stmt->bind_param($all_types, ...$all_params)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Parameter binding failed: ' . $stmt->error]);
        exit();
    }
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query execution failed: ' . $stmt->error]);
    exit();
}

$result = $stmt->get_result();
$attendance_records = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $attendance_records[] = $row;
    }
}

$stmt->close();

// Apply accurate calculations to all attendance records
$attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);

// Apply status filter based on database status (before calculations override it)
if (!empty($filter_status)) {
    $attendance_records = array_values(array_filter($attendance_records, function($rec) use ($filter_status) {
        // Use original database status, not calculated status
        $db_status = $rec['db_status'] ?? $rec['status'] ?? null;
        switch ($filter_status) {
            case 'late':
                return $db_status === 'late';
            case 'early_in':
                return $db_status === 'early_in';
            case 'on_time':
                return $db_status === 'on_time';
            case 'halfday':
                return $db_status === 'halfday';
            default:
                return true;
        }
    }));
}

// Apply attendance type filter after calculations
if (!empty($filter_attendance_type)) {
    $attendance_records = array_values(array_filter($attendance_records, function($rec) use ($filter_attendance_type) {
        $attType = $rec['attendance_type'] ?? null;
        $isGeneratedAbsent = (isset($rec['id']) && (int)$rec['id'] === 0);
        switch ($filter_attendance_type) {
            case 'present':
                return $attType === 'present';
            case 'absent':
                return $isGeneratedAbsent || $attType === 'absent';
            default:
                return true;
        }
    }));
}

$conn->close();

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'records' => $attendance_records,
    'count' => count($attendance_records)
]);
?>
