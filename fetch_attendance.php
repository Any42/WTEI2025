<?php
session_start();
header('Content-Type: application/json');

// Basic security check - ensure user is logged in (adjust roles as needed later)
if (!isset($_SESSION['loggedin'])) {
    echo json_encode(['error' => 'Authentication required.', 'records' => [], 'stats' => null]);
    exit;
}

// Include attendance calculations
require_once 'attendance_calculations.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error, 'records' => [], 'stats' => null]);
    exit;
}

// --- Filter Logic & Query Building --- 
$attendance_records = [];
$where_conditions_main = ["1=1"]; // Conditions for main query
$params_main = [];
$param_types_main = "";

$where_conditions_stats = ["1=1"]; // Conditions specifically for stats queries
$params_stats = [];
$param_types_stats = "";

// Determine role for potential future role-specific logic
$role = $_SESSION['role'] ?? 'guest'; 

// Apply search filter (if provided)
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
    $search_term = "%$search%";
    $where_conditions_main[] = "(e.EmployeeName LIKE ? OR e.EmployeeID LIKE ? OR e.Department LIKE ?)";
    $params_main[] = $search_term;
    $params_main[] = $search_term;
    $params_main[] = $search_term;
    $param_types_main .= "sss";
}

// Apply date/month filter
$is_today_filter = false; // Flag to know if we are defaulting to today
if (isset($_GET['month']) && !empty($_GET['month'])) {
    $filter_month = $conn->real_escape_string($_GET['month']);
    $date_condition_sql = "DATE_FORMAT(a.attendance_date, '%Y-%m') = ?";
    $where_conditions_main[] = $date_condition_sql;
    $where_conditions_stats[] = $date_condition_sql; // Use same condition for stats
    $params_main[] = $filter_month;
    $params_stats[] = $filter_month; // Add param for stats
    $param_types_main .= "s";
    $param_types_stats .= "s"; // Add type for stats
    $filter_period_sql = "DATE_FORMAT(attendance_date, '%Y-%m') = \'$filter_month\'"; // For stats query (less safe, consider param binding here too if complex)
} elseif (isset($_GET['date']) && !empty($_GET['date'])) {
    $filter_date = $conn->real_escape_string($_GET['date']);
    $date_condition_sql = "DATE(a.attendance_date) = ?";
    $where_conditions_main[] = $date_condition_sql;
    $where_conditions_stats[] = $date_condition_sql;
    $params_main[] = $filter_date;
    $params_stats[] = $filter_date;
    $param_types_main .= "s";
    $param_types_stats .= "s";
    $filter_period_sql = "DATE(attendance_date) = \'$filter_date\'"; 
} else {
    $is_today_filter = true;
    $today_date = date('Y-m-d');
    $date_condition_sql = "DATE(a.attendance_date) = ?";
    $where_conditions_main[] = $date_condition_sql;
    $where_conditions_stats[] = $date_condition_sql;
    $params_main[] = $today_date;
    $params_stats[] = $today_date;
    $param_types_main .= "s";
    $param_types_stats .= "s";
    $filter_period_sql = "DATE(attendance_date) = \'$today_date\'";
}

// Apply department filter (if provided)
$department_condition_sql = "";
if (isset($_GET['department']) && !empty($_GET['department'])) {
    $filter_department = $conn->real_escape_string($_GET['department']);
    $where_conditions_main[] = "e.Department = ?";
    $where_conditions_stats[] = "e.Department = ?"; // Add department condition for stats query too
    $params_main[] = $filter_department;
    $params_stats[] = $filter_department;
    $param_types_main .= "s";
    $param_types_stats .= "s";
    $department_condition_sql = "AND e.Department = \'$filter_department\'"; // For stats query
}

// Apply status filter (primarily for Admin, if provided)
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $filter_status = $conn->real_escape_string($_GET['status']);
    $where_conditions_main[] = "a.status = ?";
    $params_main[] = $filter_status;
    $param_types_main .= "s";
}

// Construct the final WHERE clauses
$where_clause_main = implode(' AND ', $where_conditions_main);
$where_clause_stats = implode(' AND ', $where_conditions_stats);

// Build and execute the main query
$query_main = "SELECT a.id, a.EmployeeID, a.attendance_date, a.status, a.time_in, a.time_out, a.notes, 
                 e.EmployeeName, e.Department 
          FROM attendance a 
          JOIN empuser e ON a.EmployeeID = e.EmployeeID 
          WHERE $where_clause_main
          ORDER BY a.attendance_date DESC, a.time_in DESC"; 

$stmt_main = $conn->prepare($query_main);

if (!$stmt_main) {
    echo json_encode(['error' => 'SQL prepare failed (main query): ' . $conn->error, 'records' => [], 'stats' => null]);
    $conn->close();
    exit;
}

if (!empty($params_main)) {
    if (!$stmt_main->bind_param($param_types_main, ...$params_main)) {
        echo json_encode(['error' => 'Param binding failed (main query): ' . $stmt_main->error, 'records' => [], 'stats' => null]);
        $stmt_main->close();
        $conn->close();
        exit;
    }
}

if(!$stmt_main->execute()){
    echo json_encode(['error' => 'SQL execute failed (main query): ' . $stmt_main->error, 'records' => [], 'stats' => null]);
    $stmt_main->close();
    $conn->close();
    exit;
}

$result = $stmt_main->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Format times for better display later
        $row['time_in_formatted'] = $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : '-';
        $row['time_out_formatted'] = $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : '-';
        $row['date_formatted'] = date('M d, Y', strtotime($row['attendance_date']));
        
        // Calculate duration for HR view if needed (can be done here or in JS)
        if ($role === 'hr') {
             if ($row['time_out'] && $row['time_in']) { // Ensure both times exist
                $duration = strtotime($row['time_out']) - strtotime($row['time_in']);
                if ($duration >= 0) {
                    $row['duration_formatted'] = floor($duration / 3600) . 'h ' . floor(($duration % 3600) / 60) . 'm';
                } else {
                     $row['duration_formatted'] = 'Invalid'; // Or handle negative duration
                }
            } elseif ($row['time_in']) {
                $duration = time() - strtotime($row['time_in']);
                 $row['duration_formatted'] = floor($duration / 3600) . 'h ' . floor(($duration % 3600) / 60) . 'm (Ongoing)';
            } else {
                $row['duration_formatted'] = '-';
            }
             $row['on_time_status'] = ($row['time_in'] && strtotime($row['time_in']) > strtotime('09:00:00')) ? 'Late' : 'On Time';
        }

        $attendance_records[] = $row;
    }
    
    // Apply accurate calculations to all attendance records
    $attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);
} else {
     echo json_encode(['error' => 'Failed to get results: ' . $stmt_main->error, 'records' => [], 'stats' => null]);
     $stmt_main->close();
     $conn->close();
     exit;
}

$stmt_main->close();

// --- Calculate Statistics based on filters --- 
$stats = [];

if ($role === 'admin') {
    // Admin Stats: Present, Absent, Late for the filtered period
    $query_stats_admin = "SELECT 
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count
        FROM attendance a
        JOIN empuser e ON a.EmployeeID = e.EmployeeID 
        WHERE $where_clause_stats"; // Use the stats-specific WHERE clause

    $stmt_stats_admin = $conn->prepare($query_stats_admin);
    if (!$stmt_stats_admin) {
        // Log error or handle gracefully, don't echo JSON here ideally
        error_log("Admin Stats Prepare Failed: " . $conn->error);
    } else {
        if (!empty($params_stats)) {
            if (!$stmt_stats_admin->bind_param($param_types_stats, ...$params_stats)) {
                 error_log("Admin Stats Bind Failed: " . $stmt_stats_admin->error);
            } 
        }
        if ($stmt_stats_admin->execute()) {
            $stats_result_admin = $stmt_stats_admin->get_result();
            if ($stats_result_admin) {
                $stats_row = $stats_result_admin->fetch_assoc();
                $stats['present'] = (int)$stats_row['present_count'];
                $stats['absent'] = (int)$stats_row['absent_count'];
                $stats['late'] = (int)$stats_row['late_count'];
                
                // Calculate Attendance Rate
                // Get total unique employees matching the department filter (if any)
                $total_emp_params = [];
                $total_emp_types = "";
                $total_emp_condition = "1=1";
                 if (isset($_GET['department']) && !empty($_GET['department'])) {
                    $total_emp_condition = "e.Department = ?";
                    $total_emp_params[] = $filter_department; // Use the already escaped value
                    $total_emp_types = "s";
                 }
                $total_employees_q = "SELECT COUNT(DISTINCT EmployeeID) as count FROM empuser e WHERE $total_emp_condition";
                $stmt_total_emp = $conn->prepare($total_employees_q);
                if($stmt_total_emp) {
                    if (!empty($total_emp_params)) {
                        $stmt_total_emp->bind_param($total_emp_types, ...$total_emp_params);
                    }
                    if($stmt_total_emp->execute()){
                         $total_emp_result = $stmt_total_emp->get_result();
                         $total_employees = $total_emp_result ? (int)$total_emp_result->fetch_assoc()['count'] : 0;
                    } else { $total_employees = 0; }
                    $stmt_total_emp->close();
                } else { $total_employees = 0; }
                
                $attended_count = $stats['present'] + $stats['late'];
                $stats['rate'] = $total_employees > 0 ? round(($attended_count / $total_employees) * 100) : 0;
            }
        } else {
             error_log("Admin Stats Execute Failed: " . $stmt_stats_admin->error);
        }
        $stmt_stats_admin->close();
    }
     // Set default stats if calculation failed
     if (!isset($stats['present'])) $stats['present'] = 0;
     if (!isset($stats['absent'])) $stats['absent'] = 0;
     if (!isset($stats['late'])) $stats['late'] = 0;
     if (!isset($stats['rate'])) $stats['rate'] = 0;
    
} elseif ($role === 'hr') {
    // HR Stats: Total Present, Still Present, Late Arrivals for the filtered period
     $query_stats_hr = "SELECT 
        COUNT(DISTINCT a.EmployeeID) as total_present,
        COUNT(DISTINCT CASE WHEN a.time_out IS NULL THEN a.EmployeeID END) as still_present,
        COUNT(DISTINCT CASE WHEN TIME(a.time_in) > '09:00:00' THEN a.EmployeeID END) as late_arrivals
        FROM attendance a 
        JOIN empuser e ON a.EmployeeID = e.EmployeeID 
        WHERE $where_clause_stats";
        
     $stmt_stats_hr = $conn->prepare($query_stats_hr);
      if (!$stmt_stats_hr) {
         error_log("HR Stats Prepare Failed: " . $conn->error);
     } else {
         if (!empty($params_stats)) {
             if (!$stmt_stats_hr->bind_param($param_types_stats, ...$params_stats)) {
                  error_log("HR Stats Bind Failed: " . $stmt_stats_hr->error);
             } 
         }
         if($stmt_stats_hr->execute()){
            $stats_result_hr = $stmt_stats_hr->get_result();
             if ($stats_result_hr) {
                 $stats = $stats_result_hr->fetch_assoc();
                 $stats['total_present'] = (int)$stats['total_present'];
                 $stats['still_present'] = (int)$stats['still_present'];
                 $stats['late_arrivals'] = (int)$stats['late_arrivals'];
             }
         } else {
             error_log("HR Stats Execute Failed: " . $stmt_stats_hr->error);
         }
        $stmt_stats_hr->close();
     }
      // Set default stats if calculation failed
      if (!isset($stats['total_present'])) $stats['total_present'] = 0;
      if (!isset($stats['still_present'])) $stats['still_present'] = 0;
      if (!isset($stats['late_arrivals'])) $stats['late_arrivals'] = 0;
}

$conn->close();

// Combine records and stats into a single response object
$response = [
    'records' => $attendance_records,
    'stats' => $stats,
    'error' => null // Explicitly set error to null if successful
];

echo json_encode($response);

?> 