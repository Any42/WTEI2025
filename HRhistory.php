<?php
session_start();

// Check if user is logged in and is HR
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'hr') {
    header("Location: login.php");
    exit();
}

// Include attendance calculations
require_once 'attendance_calculations.php';

// Get HR details from session
$hr_id = $_SESSION['userid'];
$hr_name = $_SESSION['username'];

// Connect to the database
$host = "localhost";
$user = "root";
$password = "";
$dbname = "wteimain1"; // change if needed

$conn = new mysqli($host, $user, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get HR department from database instead of session
$hr_department_query = "SELECT department FROM hr_accounts WHERE hr_id = ?";
$stmt = $conn->prepare($hr_department_query);
$stmt->bind_param("i", $hr_id);
$stmt->execute();
$hr_department_result = $stmt->get_result();

if ($hr_department_result->num_rows > 0) {
    $hr_department_row = $hr_department_result->fetch_assoc();
    $hr_department = $hr_department_row['department'];
} else {
    die("HR account not found or department not set.");
}
$stmt->close();

// --- Filter Logic & Query Building --- 
$attendance_records = [];

// Initialize filter variables
$search_term = '';
$filter_date = '';
$filter_month = '';
$filter_year = '';
$filter_department = '';
$filter_shift = '';
$filter_status = '';
$filter_attendance_type = '';
$show_overtime = false;

// Determine active filter mode based on provided parameters (authoritative)
// Infer by presence of specific inputs to keep UI in sync
if (!empty($_GET['date'])) {
    $active_filter = 'date';
} elseif (!empty($_GET['month'])) {
    $active_filter = 'month';
} elseif (!empty($_GET['year'])) {
    $active_filter = 'year';
} else {
    // Fall back to explicit active_filter if provided, else default to year
    $active_filter = isset($_GET['active_filter']) ? $_GET['active_filter'] : 'year';
}

// Apply search filter
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = trim($_GET['search']);
}

// Apply date/month/year filter based on active filter mode
if ($active_filter === 'month' && isset($_GET['month']) && !empty($_GET['month'])) {
    $filter_month = $_GET['month'];
} elseif ($active_filter === 'year' && isset($_GET['year']) && !empty($_GET['year'])) {
    $filter_year = $_GET['year'];
} elseif ($active_filter === 'date' && isset($_GET['date']) && !empty($_GET['date'])) {
    $filter_date = $_GET['date'];
} elseif (!empty($search_term)) {
    // If searching without specific date filter, don't set a default date
    // This allows search to work across all dates
} else {
    // Default behavior: show records for current year when no specific filter is provided
    $filter_year = date('Y');
}

// Department filter
if (isset($_GET['department']) && $_GET['department'] !== '' && strtolower($_GET['department']) !== 'all') {
    $filter_department = $_GET['department'];
}

// Shift filter
if (isset($_GET['shift']) && $_GET['shift'] !== '') {
    $filter_shift = $_GET['shift'];
}

// Status filter (normalize to lowercase)
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $filter_status = strtolower(trim($_GET['status']));
}

// Attendance Type filter
if (isset($_GET['attendance_type']) && $_GET['attendance_type'] !== '') {
    $filter_attendance_type = $_GET['attendance_type'];
}

// Overtime filter
if (isset($_GET['show_overtime']) && $_GET['show_overtime'] === '1') {
    $show_overtime = true;
}

// Get actual recorded date range from the system first - optimize with LIMIT
// Only get date range for the last year to improve query speed
$date_range_sql = "SELECT 
    MIN(DATE(a.attendance_date)) as earliest_date,
    MAX(DATE(a.attendance_date)) as latest_date,
    COUNT(DISTINCT DATE(a.attendance_date)) as actual_recorded_days
    FROM attendance a 
    JOIN empuser e ON a.EmployeeID = e.EmployeeID 
    WHERE e.Status='active'
    AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
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

// Build attendance JOIN conditions
$attendance_conditions = [];
$attendance_params = [];
$attendance_types = "";

// Add date range filter
if (!empty($filter_month)) {
    $attendance_conditions[] = "DATE_FORMAT(a.attendance_date, '%Y-%m') = ?";
    $attendance_params[] = $filter_month;
    $attendance_types .= "s";
} elseif (!empty($filter_year)) {
    // For year filter, we need to show all employees and generate absent records
    // Don't add year condition to attendance_conditions here
    $year_filter_active = true;
} elseif (!empty($filter_date)) {
    $attendance_conditions[] = "DATE(a.attendance_date) = ?";
    $attendance_params[] = $filter_date;
    $attendance_types .= "s";
}

// Apply 'late' at database level so pagination/limits never hide matches
if (!empty($filter_status) && $filter_status === 'late') {
    $employee_where[] = "COALESCE(a.late_minutes, 0) > 0";
}

// Apply 'on_leave' filter - this will be added to WHERE clause after JOIN
// Note: We need attendance record to exist for is_on_leave to be checked

// Add attendance type filter
if (!empty($filter_attendance_type)) {
    if ($filter_attendance_type === 'present') {
        $attendance_conditions[] = "a.attendance_type = 'present'";
    } elseif ($filter_attendance_type === 'absent') {
        // For absent: show records where there's no attendance record OR attendance_type is 'absent'
        $attendance_conditions[] = "(a.id IS NULL OR a.attendance_type = 'absent')";
    }
}

// Add system date range constraints
$attendance_conditions[] = "DATE(a.attendance_date) BETWEEN ? AND ?";
$attendance_params[] = $system_start_date;
$attendance_params[] = $system_end_date;
$attendance_types .= "ss";

// Construct the JOIN condition
$join_condition = "e.EmployeeID = a.EmployeeID";
if (!empty($attendance_conditions)) {
    $join_condition .= " AND " . implode(' AND ', $attendance_conditions);
}

// Note: coalesce_date logic moved to working days generation

// Function to generate all working days (excluding Sundays) within a date range
function generateWorkingDays($start_date, $end_date, $filter_type = null, $filter_value = null) {
    $working_days = [];
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    
    while ($start <= $end) {
        // Skip Sundays (day of week 0 = Sunday)
        if ($start->format('w') != 0) {
            $date_str = $start->format('Y-m-d');
            
            // Apply specific filters
            if ($filter_type === 'month' && $filter_value) {
                if (date('Y-m', strtotime($date_str)) === $filter_value) {
                    $working_days[] = $date_str;
                }
            } elseif ($filter_type === 'year' && $filter_value) {
                if (date('Y', strtotime($date_str)) === $filter_value) {
                    $working_days[] = $date_str;
                }
            } elseif ($filter_type === 'date' && $filter_value) {
                if ($date_str === $filter_value) {
                    $working_days[] = $date_str;
                }
            } elseif (!$filter_type) {
                $working_days[] = $date_str;
            }
        }
        $start->add(new DateInterval('P1D'));
    }
    
    return $working_days;
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

// Limit working days to prevent query timeout - optimize for performance
// For year filter, limit to recent months only (last 3 months or max 60 days)
// For month filter, use full month (max ~22 working days)
// For date filter, just 1 day
$max_working_days = 60; // Reduced from 100 for better performance
if ($filter_type === 'date' && count($working_days) > 1) {
    $working_days = array_slice($working_days, 0, 1);
} elseif ($filter_type === 'month') {
    // Month filter: allow up to 22 working days (typical month)
    $max_working_days = 22;
    if (count($working_days) > $max_working_days) {
        $working_days = array_slice($working_days, 0, $max_working_days);
    }
} elseif ($filter_type === 'year' || empty($filter_type)) {
    // Year filter or default: limit to recent 60 days for performance
    $max_working_days = 60;
    // If year filter, take the most recent working days
    if (count($working_days) > $max_working_days) {
        $working_days = array_slice($working_days, -$max_working_days);
    }
}

// Client-side pagination - no server-side pagination needed

// Build the main query with proper absent record generation - optimized for performance
// Only use CROSS JOIN approach if we have reasonable number of working days (reduced from 100 to 60)
if (!empty($working_days) && count($working_days) <= 60) {
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
                COALESCE(a.is_on_leave, 0) as is_on_leave,
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
                  AND wd.working_date >= COALESCE(e.DateHired, '1900-01-01')" . 
                  (!empty($filter_status) && $filter_status === 'on_leave' ? " AND a.id IS NOT NULL AND COALESCE(a.is_on_leave, 0) = 1" : "") . "
              ORDER BY COALESCE(a.overtime_hours, 0) DESC, wd.working_date DESC, COALESCE(a.time_in, '00:00:00') DESC
              LIMIT 5000";
} else {
    // Fallback to original query if no working days or too many working days
    $query = "SELECT 
                a.id,
                a.EmployeeID,
                a.attendance_date,
                a.attendance_type,
                a.status,
                a.status as db_status,
                a.time_in,
                a.time_out,
                a.time_in_morning,
                a.time_out_morning,
                a.time_in_afternoon,
                a.time_out_afternoon,
                COALESCE(a.data_source, 'biometric') as data_source,
                COALESCE(a.overtime_hours, 0) as overtime_hours,
                COALESCE(a.total_hours, 0) as total_hours,
                COALESCE(a.late_minutes, 0) as late_minutes,
                COALESCE(a.early_out_minutes, 0) as early_out_minutes,
                COALESCE(a.is_on_leave, 0) as is_on_leave,
                e.EmployeeName,
                e.Department,
                e.Shift
              FROM attendance a 
              JOIN empuser e ON a.EmployeeID = e.EmployeeID
              WHERE " . implode(' AND ', $employee_where) . "
              AND DATE(a.attendance_date) BETWEEN ? AND ?" .
              (!empty($filter_status) && $filter_status === 'on_leave' ? " AND a.is_on_leave = 1" : "") . "
              ORDER BY a.overtime_hours DESC, a.attendance_date DESC, a.time_in DESC
              LIMIT 5000";
}

// Status filter will be applied after calculations, not at database level

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

// Debug information (uncomment if needed)
// echo "<div style='background: #f0f0f0; padding: 10px; margin: 10px 0; border: 1px solid #ccc;'>";
// echo "<strong>Debug Query:</strong><br>";
// echo "Search term: " . htmlspecialchars($search_term ?? '') . "<br>";
// echo "Filter date: " . htmlspecialchars($filter_date ?? '') . "<br>";
// echo "Filter month: " . htmlspecialchars($filter_month ?? '') . "<br>";
// echo "Filter year: " . htmlspecialchars($filter_year ?? '') . "<br>";
// echo "Employee WHERE: " . implode(' AND ', $employee_where) . "<br>";
// echo "Query: " . htmlspecialchars($query) . "<br>";
// echo "Bind params: " . print_r($all_params, true) . "<br>";
// echo "Expected params: " . substr_count($query, '?') . "<br>";
// echo "Actual params: " . count($all_params) . "<br>";
// echo "</div>";

// Prepare and execute the statement
$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
}

// Check parameter count
$expected_params = substr_count($query, '?');
$actual_params = count($all_params);

if ($expected_params !== $actual_params) {
    die("Parameter count mismatch: Expected $expected_params, got $actual_params");
}

// Bind parameters if any exist
if (!empty($all_params)) {
    if (!$stmt->bind_param($all_types, ...$all_params)) {
         die("Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error);
    }
}

// Execute the statement
if(!$stmt->execute()){
    die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
}

$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $attendance_records[] = $row;
    }
    // echo "<div style='background: #e8f5e8; padding: 5px; margin: 5px 0; border: 1px solid #4caf50;'>";
    // echo "Records found: " . count($attendance_records) . "<br>";
    // echo "</div>";
} else {
    die("Error getting result: " . $conn->error);
}
$stmt->close();

// Get total count for pagination
$count_query = "";
if (!empty($working_days)) {
    $count_query = "SELECT COUNT(*) as total_count
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
                        AND wd.working_date >= COALESCE(e.DateHired, '1900-01-01')" .
                        (!empty($filter_status) && $filter_status === 'on_leave' ? " AND a.id IS NOT NULL AND COALESCE(a.is_on_leave, 0) = 1" : "");
} else {
    $count_query = "SELECT COUNT(*) as total_count
                    FROM attendance a 
                    JOIN empuser e ON a.EmployeeID = e.EmployeeID
                    WHERE " . implode(' AND ', $employee_where) . "
                    AND DATE(a.attendance_date) BETWEEN ? AND ?" .
                    (!empty($filter_status) && $filter_status === 'on_leave' ? " AND a.is_on_leave = 1" : "");
}

$count_stmt = $conn->prepare($count_query);
if ($count_stmt) {
    if (!empty($working_days)) {
        // For working days query, we need to bind employee filter parameters
        if (!empty($employee_params)) {
            if (!$count_stmt->bind_param($employee_types, ...$employee_params)) {
                die("Count query parameter binding failed: (" . $count_stmt->errno . ") " . $count_stmt->error);
            }
        }
        if (!$count_stmt->execute()) {
            die("Count query execution failed: (" . $count_stmt->errno . ") " . $count_stmt->error);
        }
    } else {
        // For fallback query, bind employee filter parameters + date parameters
        $all_count_params = array_merge($employee_params, [$query_start_date, $query_end_date]);
        $all_count_types = $employee_types . 'ss';
        if (!empty($all_count_params)) {
            if (!$count_stmt->bind_param($all_count_types, ...$all_count_params)) {
                die("Count query parameter binding failed: (" . $count_stmt->errno . ") " . $count_stmt->error);
            }
        }
        if (!$count_stmt->execute()) {
            die("Count query execution failed: (" . $count_stmt->errno . ") " . $count_stmt->error);
        }
    }
    $count_result = $count_stmt->get_result();
    $total_records = $count_result ? $count_result->fetch_assoc()['total_count'] : 0;
    $count_stmt->close();
} else {
    die("Count query preparation failed: (" . $conn->errno . ") " . $conn->error);
}

// Total records count for display purposes

// Apply accurate calculations to all attendance records
// Optimize: Only calculate metrics for records that need it (skip absent/no_record records if not needed)
$attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);

// Apply overtime filter
if (!$show_overtime) {
    $attendance_records = array_values(array_filter($attendance_records, function($record) {
        return ($record['overtime_hours'] ?? 0) == 0;
    }));
}

// Apply status filter with robust handling for all status types
if (!empty($filter_status)) {
    $attendance_records = array_values(array_filter($attendance_records, function($rec) use ($filter_status) {
        $db_status = $rec['db_status'] ?? $rec['status'] ?? null;
        $status_lower = strtolower($db_status ?? '');
        switch ($filter_status) {
            case 'late':
                // Consider a record late if recorded late minutes exceed zero
                return (int)($rec['late_minutes'] ?? 0) > 0;
            case 'early':
            case 'early_out':
                // Early out: has early_out_minutes > 0 OR status is 'early'
                return (int)($rec['early_out_minutes'] ?? 0) > 0 || $status_lower === 'early';
            case 'early_in':
                return $status_lower === 'early_in';
            case 'on_time':
                // On time: status is on_time, no late minutes, and not on leave
                return $status_lower === 'on_time' && 
                       (int)($rec['late_minutes'] ?? 0) === 0 && 
                       (int)($rec['is_on_leave'] ?? 0) !== 1;
            case 'halfday':
            case 'half_day':
                return $status_lower === 'halfday' || $status_lower === 'half_day' ||
                       ((float)($rec['total_hours'] ?? 0) > 0 && (float)($rec['total_hours'] ?? 0) <= 4.0);
            case 'on_leave':
                return (int)($rec['is_on_leave'] ?? 0) === 1;
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

// Get departments for filter dropdown
$departments = [];
$departments_query = "SELECT DISTINCT Department FROM empuser WHERE Status='active' ORDER BY Department";
$departments_result_obj = $conn->query($departments_query);
if ($departments_result_obj) {
    while ($dept = $departments_result_obj->fetch_assoc()) {
        $departments[] = $dept['Department'];
    }
}

// Calculate summary analytics
$recorded_days = count($working_days);

// Count records including generated absent records
$analytics_where = ["e.Status='active'"];
$analytics_params = [];
$analytics_types = "";

// Add employee filters
if (!empty($filter_department)) {
    $analytics_where[] = "e.Department = ?";
    $analytics_params[] = $filter_department;
    $analytics_types .= 's';
}
// Include shift filter in analytics to match main results
if (!empty($filter_shift)) {
    $analytics_where[] = "e.Shift = ?";
    $analytics_params[] = $filter_shift;
    $analytics_types .= 's';
}
if (!empty($search_term)) {
    $analytics_where[] = "(e.EmployeeName LIKE ? OR e.EmployeeID LIKE ?)";
    $search_param = "%$search_term%";
    $analytics_params[] = $search_param;
    $analytics_params[] = $search_param;
    $analytics_types .= 'ss';
}

// Status filter will be applied after calculations, not at database level

// Apply attendance type filter consistently to analytics
if (!empty($filter_attendance_type)) {
    if ($filter_attendance_type === 'present') {
        // Count Half Day as Present in summaries
        $analytics_where[] = "(a.attendance_type = 'present' OR a.status = 'halfday')";
    } elseif ($filter_attendance_type === 'absent') {
        $analytics_where[] = "(a.id IS NULL OR a.attendance_type = 'absent')";
    }
}

// Build analytics query strictly within the active filter's date range
if (!empty($working_days)) {
    $analytics_query = "SELECT 
        COALESCE(a.id, 0) as id,
        COALESCE(a.attendance_type, 'absent') as attendance_type,
        COALESCE(a.status, 'no_record') as status,
        COALESCE(a.is_overtime, 0) as is_overtime,
        COALESCE(a.overtime_hours, 0) as overtime_hours,
        COALESCE(a.is_on_leave, 0) as is_on_leave
        FROM empuser e 
        CROSS JOIN (
            SELECT working_date FROM (
                SELECT '{$working_days[0]}' as working_date" . 
                (count($working_days) > 1 ? " UNION ALL SELECT '" . implode("' UNION ALL SELECT '", array_slice($working_days, 1)) . "'" : "") . "
            ) as wd
        ) wd
        LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID 
            AND DATE(a.attendance_date) = wd.working_date
        WHERE " . implode(' AND ', $analytics_where) . "
            AND wd.working_date >= COALESCE(e.DateHired, '1900-01-01')" .
            (!empty($filter_status) && $filter_status === 'on_leave' ? " AND a.id IS NOT NULL AND COALESCE(a.is_on_leave, 0) = 1" : "");
} else {
    $analytics_query = "SELECT 
        a.id,
        a.attendance_type,
        a.status,
        a.is_overtime,
        a.overtime_hours,
        COALESCE(a.is_on_leave, 0) as is_on_leave
        FROM attendance a 
        JOIN empuser e ON a.EmployeeID = e.EmployeeID
        WHERE " . implode(' AND ', $analytics_where) . "
        AND DATE(a.attendance_date) BETWEEN ? AND ?" .
        (!empty($filter_status) && $filter_status === 'on_leave' ? " AND a.is_on_leave = 1" : "");
    $analytics_params[] = $query_start_date;
    $analytics_params[] = $query_end_date;
    $analytics_types .= 'ss';
}
// Execute analytics query
$analytics_stmt = $conn->prepare($analytics_query);
if ($analytics_stmt) {
    if (!empty($analytics_params)) {
        $analytics_stmt->bind_param($analytics_types, ...$analytics_params);
    }
    $analytics_stmt->execute();
    $analytics_result = $analytics_stmt->get_result();
    
    // Count records
    $total_records = 0;
    $present_occ = 0;
    $absent_occ = 0;
    $late_occ = 0;
    $early_occ = 0;
    $ot_occ = 0;
    
    $analytics_records = [];
    while ($row = $analytics_result->fetch_assoc()) {
        $analytics_records[] = $row;
    }
    
    // Apply calculations to analytics records
    $analytics_records = AttendanceCalculator::calculateAttendanceMetrics($analytics_records);
    
    // Apply status filter to analytics records if needed
    if (!empty($filter_status)) {
        $analytics_records = array_values(array_filter($analytics_records, function($record) use ($filter_status) {
            $status = $record['status'] ?? null;
            $status_lower = strtolower($status ?? '');
            switch ($filter_status) {
                case 'late':
                    return (int)($record['late_minutes'] ?? 0) > 0;
                case 'early':
                case 'early_out':
                    return (int)($record['early_out_minutes'] ?? 0) > 0 || $status_lower === 'early';
                case 'early_in':
                    return $status_lower === 'early_in';
                case 'on_time':
                    return $status_lower === 'on_time' && 
                           (int)($record['late_minutes'] ?? 0) === 0 && 
                           (int)($record['is_on_leave'] ?? 0) !== 1;
                case 'halfday':
                case 'half_day':
                    return $status_lower === 'halfday' || $status_lower === 'half_day' ||
                           ((float)($record['total_hours'] ?? 0) > 0 && (float)($record['total_hours'] ?? 0) <= 4.0);
                case 'on_leave':
                    return (int)($record['is_on_leave'] ?? 0) === 1;
                default:
                    return true;
            }
        }));
    }
    
    // Apply attendance type filter to analytics records if needed
    if (!empty($filter_attendance_type)) {
        $analytics_records = array_values(array_filter($analytics_records, function($record) use ($filter_attendance_type) {
            $attType = $record['attendance_type'] ?? null;
            $isGeneratedAbsent = (isset($record['id']) && (int)$record['id'] === 0);
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
    
    // Count filtered records
    foreach ($analytics_records as $row) {
        $total_records++;
        
        $attType = strtolower($row['attendance_type'] ?? '');
        $stat = strtolower($row['status'] ?? '');
        if ($attType === 'present' || $stat === 'halfday' || $stat === 'half_day') {
            $present_occ++;
        } else {
            $absent_occ++;
        }
        
        if ((int)($row['late_minutes'] ?? 0) > 0) {
            $late_occ++;
        } elseif ($row['status'] === 'early') {
            $early_occ++;
        }
        
        if (($row['is_overtime'] == 1 || $row['overtime_hours'] > 0) && $row['attendance_type'] === 'present') {
            $ot_occ++;
        }
    }
    
    $analytics_stmt->close();
} else {
    $total_records = 0;
    $present_occ = 0;
    $absent_occ = 0;
    $late_occ = 0;
    $early_occ = 0;
    $ot_occ = 0;
}

$summary_data = [
    'total_records' => $total_records,
    'present_count' => $present_occ,
    'absent_count' => $absent_occ,
    'late_count' => $late_occ,
    'early_count' => $early_occ,
    'overtime_count' => $ot_occ,
    'recorded_days' => $recorded_days
];

// Don't close connection yet - we need it for the HTML section
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR History - <?php echo htmlspecialchars($hr_department); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
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
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
            transition: all var(--transition-speed) ease;
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
            margin: 0 auto 15px;
            display: block;
            transition: all var(--transition-speed) ease;
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
            color: var(--text-color); /* Changed to white */
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
            transition: opacity var(--transition-speed) ease;
            color: var(--text-color); /* Added to ensure text is white */
        }

        .logout-container {
            padding: 20px 15px;
            margin-top: auto; /* This pushes the logout to the bottom */
        }

        .logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 14px;
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--text-color); /* Changed to white */
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
            color: var(--text-color); /* Added to ensure icon is white */
        }

        /* Toggle Button */
        .sidebar-toggle {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 40px;
            height: 40px;
            background-color: var(--secondary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1001;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            transition: all var(--transition-speed) ease;
        }

        .sidebar-toggle:hover {
            background-color: var(--primary-color);
            transform: scale(1.1);
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

        .main-content {
            flex-grow: 1;
            padding: 30px;
            margin-left: 320px;
            overflow-y: auto;
            transition: all 0.3s ease;
        }
        
        /* Enhanced Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border-bottom: 2px solid #DBE2EF;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(to right, #3F72AF, #112D4E);
        }

        .header h1 {
            font-size: 28px;
            color: #112D4E;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 i {
            color: #3F72AF;
        }

        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-box {
            position: relative;
            width: 300px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 40px 12px 20px;
            border: 2px solid #DBE2EF;
            border-radius: 12px;
            font-size: 14px;
            background-color: #fff;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .search-box input:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
        }

        .search-box i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #112D4E;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
            user-select: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(63, 114, 175, 0.4);
            background: linear-gradient(135deg, #4A7BB7 0%, #1A3A5C 100%);
        }

        .btn-primary:active {
            transform: translateY(0);
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.3);
        }

        .btn-primary i {
            margin-right: 8px;
            font-size: 16px;
        }

        /* Special styling for search button */
        #searchBtn {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 14px 28px;
            border-radius: 25px;
            box-shadow: 0 6px 20px rgba(63, 114, 175, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        #searchBtn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(63, 114, 175, 0.4);
            background: linear-gradient(135deg, #4A7BB7 0%, #1A3A5C 100%);
        }

        #searchBtn:active {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(63, 114, 175, 0.3);
        }

        /* Add a subtle pulse animation for the search button */
        @keyframes searchPulse {
            0% { box-shadow: 0 6px 20px rgba(63, 114, 175, 0.3); }
            50% { box-shadow: 0 6px 20px rgba(63, 114, 175, 0.5); }
            100% { box-shadow: 0 6px 20px rgba(63, 114, 175, 0.3); }
        }

        #searchBtn:focus {
            animation: searchPulse 2s infinite;
            outline: none;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #DBE2EF 0%, #C8D5E8 100%);
            color: #112D4E;
            font-weight: 500;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(63, 114, 175, 0.3);
        }

        .btn-secondary:active {
            transform: translateY(0);
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.2);
        }

        /* Enhanced Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            transition: all 0.3s;
            border-left: 4px solid #3F72AF;
            position: relative;
            overflow: hidden;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        }

        .summary-card:nth-child(2) {
            border-left-color: #16C79A;
        }

        .summary-card:nth-child(3) {
            border-left-color: #FF6B6B;
        }

        .summary-card:nth-child(4) {
            border-left-color: #FFC75F;
        }

        .summary-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 24px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .summary-icon.present {
            background-color: rgba(63, 114, 175, 0.15);
            color: #3F72AF;
        }

        .summary-icon.rate {
            background-color: rgba(22, 199, 154, 0.15);
            color: #16C79A;
        }

        .summary-icon.absent {
            background-color: rgba(255, 107, 107, 0.15);
            color: #FF6B6B;
        }

        .summary-icon.late {
            background-color: rgba(255, 199, 95, 0.15);
            color: #FFC75F;
        }

        .summary-info {
            flex-grow: 1;
        }

        .summary-value {
            font-size: 32px;
            font-weight: 700;
            color: #112D4E;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }

        .summary-label {
            font-size: 14px;
            color: #6c757d;
            font-weight: 500;
        }

        /* Enhanced Filter Container */
        .filter-container {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            border: 1px solid rgba(219, 226, 239, 0.5);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #DBE2EF;
        }
        .filter-header h2 {
            font-size: 20px;
            color: #112D4E;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-header h2 i {
            color: #3F72AF;
        }

        .filter-controls {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .filter-mode-buttons {
            display: inline-flex;
            gap: 0;
            border: 2px solid #DBE2EF;
            border-radius: 16px;
            padding: 4px;
            width: auto;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            background: #fff;
            overflow: hidden;
        }

        .date-filter-btn {
            background: transparent;
            color: #6B7280;
            border: none;
            padding: 12px 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            position: relative;
            letter-spacing: 0.025em;
            border-radius: 12px;
            white-space: nowrap;
            min-width: 140px;
            justify-content: center;
        }

        .date-filter-btn:first-child {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }

        .date-filter-btn:last-child {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        .date-filter-btn:not(:first-child):not(:last-child) {
            border-radius: 0;
        }

        .date-filter-btn.active {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.3);
            transform: translateY(-1px);
        }

        .date-filter-btn.active::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);
            border-radius: inherit;
        }

        .date-filter-btn:not(.active):hover { 
            background: linear-gradient(135deg, #F8FBFF 0%, #E3F2FD 100%); 
            color: #3F72AF;
            transform: translateY(-1px); 
            box-shadow: 0 2px 8px rgba(63, 114, 175, 0.15); 
        }

        .date-filter-btn:not(.active):hover i { 
            color: #3F72AF; 
            transform: scale(1.1);
        }

        .date-filter-btn i {
            transition: all 0.3s ease;
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            max-width: 640px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9) translateY(-20px);
            transition: all 0.3s ease;
        }

        .modal.show .modal-content {
            transform: scale(1) translateY(0);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #DBE2EF;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
        }

        /* Make Date/Month/Year inputs smaller than container */
        #date,
        #month,
        #year {
            width: 220px;
            max-width: 100%;
            display: inline-block;
        }

        .filter-input-area {
            padding: 15px 0;
            border-top: 1px solid #DBE2EF;
        }

        .filter-input-area > div {
            padding-top: 15px;
        }

        .date-input-container label,
        .month-input-container label,
        .year-input-container label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #112D4E;
        }

        /* Ensure form controls in filter options don't stretch fully for date/month/year containers */
        .date-input-container .form-control,
        .month-input-container .form-control,
        .year-input-container .form-control {
            width: auto;
            max-width: 260px;
        }

        .filter-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            border-top: 1px solid #DBE2EF;
            padding-top: 25px;
        }

        .filter-option label {
            display: block;
            margin-bottom: 8px;
            color: #112D4E;
            font-weight: 600;
            font-size: 14px;
        }

        .filter-option select,
        .filter-option input {
            width: 100%;
            padding: 12px;
            border: 2px solid #DBE2EF;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
            background-color: white;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .filter-option select:focus,
        .filter-option input:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
        }

        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #DBE2EF;
        }

        /* Enhanced Results Container */
        .results-container {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            border: 1px solid rgba(219, 226, 239, 0.5);
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #DBE2EF;
        }

        .results-header h2 {
            font-size: 20px;
            color: #112D4E;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .results-header h2 i {
            color: #3F72AF;
        }

        .results-actions {
            display: flex;
            gap: 12px;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        table th {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 16px 12px;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table th:first-child {
            border-top-left-radius: 12px;
        }

        table th:last-child {
            border-top-right-radius: 12px;
        }

        table td {
            padding: 16px 12px;
            border-bottom: 1px solid #F0F4F8;
            color: #2D3748;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        table tr {
            background-color: white;
            transition: all 0.2s ease;
        }

        table tr:hover {
            background-color: #F8FBFF;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(63, 114, 175, 0.1);
        }

        table tr:hover td {
            border-color: #DBE2EF;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            word-break: keep-all;
            overflow-wrap: normal;
        }

        .status-present {
            background-color: rgba(63, 114, 175, 0.15);
            color: #3F72AF;
        }

        .status-absent {
            background-color: rgba(255, 107, 107, 0.15);
            color: #FF6B6B;
        }

        .status-absent-no-record {
            background-color: rgba(255, 107, 107, 0.25);
            color: #FF4757;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .status-pending {
            background-color: rgba(255, 199, 95, 0.15);
            color: #FFC75F;
        }

        .status-late {
            background-color: rgba(255, 235, 59, 0.15);
            color: #F57F17;
        }

        .status-early_in {
            background-color: rgba(76, 175, 80, 0.15);
            color: #4CAF50;
        }

        .status-half-day {
            background-color: rgba(33, 150, 243, 0.15);
            color: #2196F3;
        }

        .status-on_time {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 235, 59, 0.2));
            color: #FF8F00;
            box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3);
        }

        .status-normal {
            background-color: rgba(76, 175, 80, 0.15);
            color: #4caf50;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .view-btn {
            background-color: rgba(63, 114, 175, 0.15);
            color: #3F72AF;
        }

        .view-btn:hover {
            background-color: #3F72AF;
            color: white;
            transform: scale(1.1);
        }

        .edit-btn {
            background-color: rgba(255, 199, 95, 0.15);
            color: #FFC75F;
        }

        .edit-btn:hover {
            background-color: #FFC75F;
            color: white;
            transform: scale(1.1);
        }

        .delete-btn {
            background-color: rgba(255, 107, 107, 0.15);
            color: #FF6B6B;
        }

        .delete-btn:hover {
            background-color: #FF6B6B;
            color: white;
            transform: scale(1.1);
        }

        .approve-btn {
            background-color: rgba(22, 199, 154, 0.15);
            color: #16C79A;
        }

        .approve-btn:hover {
            background-color: #16C79A;
            color: white;
            transform: scale(1.1);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 25px;
        }

        .pagination button {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            border: 1px solid #DBE2EF;
            background-color: white;
            color: #112D4E;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .pagination button.active {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            border-color: #3F72AF;
        }

        .pagination button:hover:not(.active) {
            background-color: #DBE2EF;
            transform: translateY(-2px);
        }

        /* Enhanced Alert Styles */
        .alert {
            padding: 16px 20px;
            margin-bottom: 25px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            animation: slideIn 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            background-color: rgba(22, 199, 154, 0.15);
            color: #16C79A;
            border: 1px solid rgba(22, 199, 154, 0.2);
        }

        .alert-error {
            background-color: rgba(255, 71, 87, 0.15);
            color: #ff4757;
            border: 1px solid rgba(255, 71, 87, 0.2);
        }

        .alert i {
            margin-right: 12px;
            font-size: 20px;
        }

        /* Responsive styles */
        @media (max-width: 1200px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

            .header-actions {
                width: 100%;
            }

            .search-box {
                width: 100%;
            }

            .filter-options {
                grid-template-columns: 1fr;
            }
            
            .bulk-filter-section {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 80px;
                overflow: hidden;
            }

            .sidebar .logo {
                font-size: 24px;
                padding: 15px 0;
            }

            .menu-item span, .logout-btn span {
                display: none;
            }

            .menu-item, .logout-btn {
                justify-content: center;
                padding: 15px;
            }

            .menu-item i, .logout-btn i {
                margin-right: 0;
            }

            .main-content {
                margin-left: 80px;
                padding: 20px;
            }

            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .summary-cards {
                grid-template-columns: 1fr;
            }

            .results-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .results-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .filter-mode-buttons {
                flex-direction: column;
                width: 100%;
            }

            .date-filter-btn {
                border-right: none;
                border-bottom: 1px solid #DBE2EF;
            }

            .filter-mode-buttons .date-filter-btn:last-child {
                border-bottom: none;
            }

            .filter-actions {
                flex-direction: column;
            }
        }

        /* Animation for table rows */
        @keyframes fadeInRow {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        table tr {
            animation: fadeInRow 0.3s ease;
        }

        table tr:nth-child(even) {
            animation-delay: 0.05s;
        }

        table tr:nth-child(odd) {
            animation-delay: 0.1s;
        }
        /* Custom scrollbar */
        .table-container::-webkit-scrollbar {
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* View Toggle Buttons */
        /* View toggle improvements */
        .view-toggle.btn-group {
            display: inline-flex;
            border: 1px solid #DBE2EF;
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .view-toggle .btn {
            margin: 0;
            border-radius: 0;
            border: none;
            padding: 10px 18px;
            min-width: 140px;
            justify-content: center;
            gap: 8px;
            color: #3F72AF;
            background: transparent;
            box-shadow: none;
            font-weight: 600;
        }
        .filter-mode-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-mode-btn {
            padding: 12px 20px;
            border: 2px solid #DBE2EF;
            border-radius: 12px;
            background: white;
            color: #3F72AF;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .filter-mode-btn:hover {
            background: #F8FBFF;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.15);
        }
        
        .filter-mode-btn.active {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            border-color: #3F72AF;
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.25);
        }
        
        .filter-mode-btn i {
            font-size: 16px;
        }
        
        .filter-input-area {
            margin-bottom: 20px;
        }
        
        .filter-input-container {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .filter-input-container.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .filter-summary {
            background: linear-gradient(135deg, #F8FBFF 0%, #E3F2FD 100%);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            border-left: 4px solid #3F72AF;
        }
        
        .filter-summary p {
            margin: 0;
            color: #112D4E;
            font-weight: 500;
        }
        
        .filter-summary strong {
            color: #3F72AF;
        }
        .view-toggle .btn i { color: inherit; }
        .view-toggle .btn + .btn { border-left: 1px solid #DBE2EF; }
        .view-toggle .btn.active {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: #fff;
        }
        .view-toggle .btn:not(.active):hover { background: #F8FBFF; }
        .view-toggle .btn:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
        .view-toggle .btn:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }

        /* Bulk Edit Modal Styles */
        .bulk-step {
            margin-bottom: 30px;
        }

        .bulk-step h3 {
            color: #112d4e;
            margin-bottom: 25px;
            padding-bottom: 12px;
            border-bottom: 3px solid #e8f4f8;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bulk-filter-section {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
            padding: 28px;
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #E8F4F8;
        }

        .bulk-filter-section .form-group:nth-child(5) {
            grid-column: 1 / -1;
        }

        .date-filter-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            align-items: start;
        }

        /* Bulk modal date/month/year inputs smaller */
        #bulk-date-filter,
        #bulk-month-filter,
        #bulk-year-filter {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            display: block;
        }

        /* Fix clipped text in Pick Year select */
        #bulk-year-filter {
            height: auto !important;
            line-height: 1.2;
        }
        
        /* Remove dropdown indicators from time inputs */
        input[type="time"] {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: textfield;
        }
        
        input[type="time"]::-webkit-calendar-picker-indicator {
            display: none;
        }
        
        /* Enhanced styling for half-day dropdown */
        #bulk_halfday_session {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border: 2px solid #e3ecf9;
            border-radius: 8px;
            padding: 10px 12px;
            font-weight: 600;
            color: #112d4e;
            transition: all 0.3s ease;
        }
        
        #bulk_halfday_session:hover {
            border-color: #3f72af;
            box-shadow: 0 2px 8px rgba(63, 114, 175, 0.15);
        }
        
        #bulk_halfday_session:focus {
            outline: none;
            border-color: #3f72af;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
        }

        .search-input-wrapper {
            position: relative;
        }

        .bulk-filter-section .form-group label {
            font-size: 14px;
            font-weight: 600;
            color: #112D4E;
            margin-bottom: 8px;
            display: block;
        }

        .bulk-filter-section .form-control {
            border: 2px solid #DBE2EF;
            border-radius: 12px;
            padding: 14px 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .bulk-filter-section .form-control:focus {
            border-color: #3B82F6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }

        .bulk-filter-section select.form-control {
            background: #fff;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 16px 16px;
            padding-right: 45px;
        }

        .employee-selection-container {
            border: 2px solid #E8F4F8;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .selection-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 24px;
            background: linear-gradient(135deg, #E8F4F8 0%, #F0F8FF 100%);
            border-bottom: 2px solid #DBE2EF;
            font-weight: 600;
            color: #112D4E;
        }

        .selection-header label {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            cursor: pointer;
            font-size: 15px;
        }

        .selection-header input[type="checkbox"] {
            transform: scale(1.3);
            margin-right: 8px;
        }

        .employee-list {
            max-height: 500px;
            overflow-y: auto;
            background: #fff;
        }

        .employee-item {
            display: flex;
            align-items: flex-start;
            padding: 16px 24px;
            border-bottom: 1px solid #F0F4F8;
            transition: all 0.3s ease;
            position: relative;
        }

        .employee-item:hover {
            background: linear-gradient(135deg, #F8FAFC 0%, #F1F5F9 100%);
            transform: translateX(2px);
        }

        .employee-item.selected {
            background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
            border-left: 4px solid #3B82F6;
        }

        .employee-item:last-child {
            border-bottom: none;
        }

        .employee-checkbox {
            margin-right: 16px;
            margin-top: 4px;
        }

        .employee-checkbox input[type="checkbox"] {
            transform: scale(1.3);
            cursor: pointer;
        }

        .employee-info {
            flex: 1;
        }

        .employee-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .employee-name {
            font-weight: 600;
            color: #112d4e;
            font-size: 16px;
        }

        .attendance-status {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .attn-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .attn-badge.present {
            background: #D1FAE5;
            color: #065F46;
            border: 1px solid #A7F3D0;
        }

        .attn-badge.absent {
            background: #FEE2E2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        .status-indicator {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .status-indicator.late {
            background: #FEF3C7;
            color: #92400E;
        }

        .status-indicator.early {
            background: #FEE2E2;
            color: #991B1B;
        }

        .status-indicator.overtime {
            background: #E0E7FF;
            color: #3730A3;
        }

        .employee-details {
            font-size: 13px;
            color: #64748B;
            line-height: 1.5;
        }

        .detail-row {
            margin-bottom: 4px;
        }

        .detail-label {
            font-weight: 600;
            color: #475569;
        }

        .loading-state, .error-state, .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748B;
            font-size: 14px;
        }

        .loading-state i, .error-state i, .empty-state i {
            font-size: 24px;
            margin-bottom: 12px;
            display: block;
        }

        .error-state {
            color: #DC2626;
        }

        .empty-state {
            color: #6B7280;
        }

        .employee-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s;
        }

        .employee-item:hover {
            background-color: #f8f9fa;
        }

        .employee-item:last-child {
            border-bottom: none;
        }

        .employee-item input[type="checkbox"] {
            margin-right: 12px;
            transform: scale(1.2);
        }

        .employee-info {
            flex: 1;
        }

        .employee-name {
            font-weight: 600;
            color: #112D4E;
            margin-bottom: 2px;
        }

        .employee-details {
            font-size: 0.9rem;
            color: #666;
        }

        /* Attendance status badge for bulk list */
        .attn-badge {
            display: inline-block;
            margin-left: 8px;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
        }
        .attn-badge.present { background: #d4edda; color: #155724; }
        .attn-badge.absent { background: #f8d7da; color: #721c24; }

        .shift-selection {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .shift-option {
            display: flex;
            align-items: center;
            padding: 20px;
            border: 2px solid #DBE2EF;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }

        .shift-option:hover {
            border-color: #3F72AF;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.15);
        }

        .shift-option.selected {
            border-color: #112D4E;
            background: #DBE2EF;
            box-shadow: 0 4px 12px rgba(17, 45, 78, 0.15);
        }

        .shift-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            background: linear-gradient(135deg, #3F72AF, #112D4E);
            color: white;
            font-size: 24px;
        }

        .shift-option.selected .shift-icon {
            background: linear-gradient(135deg, #112D4E, #0f1b2e);
        }

        .shift-info h4 {
            margin: 0 0 5px 0;
            color: #112D4E;
            font-size: 1.1rem;
        }

        .shift-info p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }

        .shift-info-display {
            padding: 15px;
            background: #DBE2EF;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #112D4E;
        }

        .shift-info-display h4 {
            margin: 0 0 5px 0;
            color: #112D4E;
        }

        .shift-info-display p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }

        /* Custom scrollbar styling for modals */
        #hrViewBody::-webkit-scrollbar {
            width: 8px;
        }
        
        #hrViewBody::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        #hrViewBody::-webkit-scrollbar-thumb {
            background: #3F72AF;
            border-radius: 4px;
        }
        
        #hrViewBody::-webkit-scrollbar-thumb:hover {
            background: #112D4E;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-section {
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #DBE2EF;
        }

        .form-section h3 {
            color: #112D4E;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #112D4E;
        }

        .large-input, .large-textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #DBE2EF;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .large-input:focus, .large-textarea:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
        }

        .large-textarea {
            resize: vertical;
            min-height: 80px;
        }

        /* Pagination Styles */
        .pagination-btn {
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .pagination-btn:hover:not(:disabled) {
            background: #3F72AF !important;
            color: white !important;
            border-color: #3F72AF !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(63, 114, 175, 0.2);
        }
        
        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed !important;
        }
        
        .pagination-btn.page-number:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(63, 114, 175, 0.25);
        }
        #records-per-page:hover {
            border-color: #3F72AF;
            box-shadow: 0 2px 6px rgba(63, 114, 175, 0.15);
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


        /* Focus state for accessibility */
        .toggle-switch input:focus + .toggle-label {
            outline: 2px solid #007bff;
            outline-offset: 2px;
        }

        /* New Beautiful Toggle Switch Design */
        .switch-btn {
            position: relative;
            display: inline-block;
            width: 100px;
            height: 40px;
        }

        .switch-btn input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(145deg, #e0e0e0, #c0c0c0);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            box-shadow: 
                inset 0 2px 4px rgba(0,0,0,0.1),
                0 2px 4px rgba(0,0,0,0.1);
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 40px;
            width: 50px;
            left: 0;
            bottom: 0;
            background: linear-gradient(145deg, #ffffff, #f0f0f0);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            box-shadow: 
                0 2px 4px rgba(0,0,0,0.2),
                0 1px 2px rgba(0,0,0,0.1);
        }

        input:checked + .slider {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            box-shadow: 
                inset 0 2px 4px rgba(0,0,0,0.2),
                0 4px 8px rgba(63, 114, 175, 0.3);
        }

        input:checked + .slider:before {
            transform: translateX(50px);
            background: linear-gradient(145deg, #ffffff, #f8f9fa);
            box-shadow: 
                0 3px 6px rgba(0,0,0,0.3),
                0 1px 3px rgba(0,0,0,0.2);
        }

        .slider.round {
            border-radius: 8px;
        }

        .slider.round:before {
            border-radius: 8px;
        }

        /* Hover effects */
        .switch-btn:hover .slider {
            box-shadow: 
                inset 0 2px 4px rgba(0,0,0,0.1),
                0 4px 8px rgba(0,0,0,0.15);
        }

        .switch-btn:hover input:checked + .slider {
            box-shadow: 
                inset 0 2px 4px rgba(0,0,0,0.2),
                0 6px 12px rgba(63, 114, 175, 0.4);
        }

        /* Loading state for AJAX */
        .switch-btn.loading .slider:before {
            animation: pulse 1.2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { 
                transform: scale(1);
                box-shadow: 0 2px 4px rgba(0,0,0,0.2), 0 1px 2px rgba(0,0,0,0.1);
            }
            50% { 
                transform: scale(1.05);
                box-shadow: 0 3px 6px rgba(0,0,0,0.3), 0 2px 4px rgba(0,0,0,0.2);
            }
        }


        /* Overtime badge styles */
        .overtime-badge {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.3);
        }

        .no-overtime {
            color: #6c757d;
            font-style: italic;
        }

        /* Pagination styles */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 15px 0;
            border-top: 1px solid #e0e0e0;
        }

        .pagination-info {
            color: #666;
            font-size: 14px;
        }

        .pagination {
            display: flex;
            gap: 5px;
        }

        .pagination-btn {
            padding: 8px 12px;
            background: #f8f9fa;
            color: #333;
            text-decoration: none;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .pagination-btn:hover {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }

        .pagination-btn.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
    </style>
</head>
<body>
    <?php 
    // Provide full active roster for bulk edit in history context as well
    try {
        $__conn2 = new mysqli($host, $user, $password, $dbname);
        if ($__conn2 && !$__conn2->connect_error) {
            $__conn2->query("SET time_zone = '+08:00'");
            $__emps2 = [];
            $__res2 = $__conn2->query("SELECT EmployeeID, EmployeeName, Department, Shift FROM empuser WHERE Status='active' ORDER BY EmployeeName");
            if ($__res2) { while ($__r2 = $__res2->fetch_assoc()) { $__emps2[] = $__r2; } }
            $__conn2->close();
            echo '<script>window.allActiveEmployees = ' . json_encode($__emps2) . ';</script>';
        }
    } catch (Exception $__e2) { /* silent */ }
    ?>
    <div class="sidebar">
    <div class="logo">
    <img src="LOGO/newLogo_transparent.png" class="logo" style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding:-250px; padding-bottom: 20px;">       
               
    </div>
    <div class="portal-name">
    <i class="fas fa-users-cog"></i>
    <span>HR Portal</span>
    </div>
        <div class="menu">
            <a href="HRHome.php" class="menu-item">
                <i class="fas fa-th-large"></i> <span>Dashboard</span>
            </a>
            <a href="HREmployees.php" class="menu-item">
                <i class="fas fa-users"></i> <span>Employees</span>
            </a>
            <a href="HRAttendance.php" class="menu-item">
                <i class="fas fa-calendar-check"></i> <span>Attendance</span>
            </a>
            <a href="HRhistory.php" class="menu-item active">
                <i class="fas fa-history"></i> <span>History</span>
            </a>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </a>
    </div>

    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div>
                <h1><i class="fas fa-history"></i> Department History</h1>
                <div class="department-badge"><?php echo (isset($_GET['department']) && $_GET['department'] !== '') ? htmlspecialchars($_GET['department']) : 'All Departments'; ?></div>
                <div style="margin-top: 10px; padding: 8px 12px; background: rgba(63, 114, 175, 0.1); border-radius: 8px; font-size: 14px; color: #3F72AF;">
                    <i class="fas fa-info-circle"></i> 
                    System Data Range: <?php echo date('M d, Y', strtotime($system_start_date)); ?> to <?php echo date('M d, Y', strtotime($system_end_date)); ?> 
                    (<?php echo $actual_recorded_days; ?> recorded days)
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon total">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="summary-total" data-default="<?php echo $summary_data['total_records']; ?>"><?php echo $summary_data['total_records']; ?></div>
                    <div class="summary-label">
                        Total Records
                        <?php 
                        $context_text = '';
                        if (isset($filter_month) && !empty($filter_month)) {
                            $context_text = 'in ' . date('F Y', strtotime($filter_month . '-01')) . ' (within ' . $recorded_days . ' recorded days)';
                        } elseif (isset($filter_year) && !empty($filter_year)) {
                            $context_text = 'in ' . $filter_year . ' (within ' . $recorded_days . ' recorded days)';
                        } elseif (isset($filter_date) && !empty($filter_date)) {
                            $context_text = 'on ' . date('M d, Y', strtotime($filter_date));
                        } else {
                            $context_text = 'within ' . $recorded_days . ' recorded days';
                        }
                        echo '<br><small style="color: #6c757d; font-size: 12px;">' . $context_text . '</small>';
                        ?>
                    </div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon present">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="summary-present" data-default="<?php echo $summary_data['present_count']; ?>"><?php echo $summary_data['present_count']; ?></div>
                    <div class="summary-label">
                        Present Records
                        <?php 
                        if (isset($filter_month) && !empty($filter_month)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">in ' . date('F Y', strtotime($filter_month . '-01')) . ' (within ' . $recorded_days . ' recorded days)</small>';
                        } elseif (isset($filter_year) && !empty($filter_year)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">in ' . $filter_year . ' (within ' . $recorded_days . ' recorded days)</small>';
                        } elseif (isset($filter_date) && !empty($filter_date)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">on ' . date('M d, Y', strtotime($filter_date)) . '</small>';
                        } else {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">within ' . $recorded_days . ' recorded days</small>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon absent">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="summary-absent" data-default="<?php echo $summary_data['absent_count']; ?>"><?php echo $summary_data['absent_count']; ?></div>
                    <div class="summary-label">
                        Absent Records
                        <?php 
                        if (isset($filter_month) && !empty($filter_month)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">in ' . date('F Y', strtotime($filter_month . '-01')) . ' (within ' . $recorded_days . ' recorded days)</small>';
                        } elseif (isset($filter_year) && !empty($filter_year)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">in ' . $filter_year . ' (within ' . $recorded_days . ' recorded days)</small>';
                        } elseif (isset($filter_date) && !empty($filter_date)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">on ' . date('M d, Y', strtotime($filter_date)) . '</small>';
                        } else {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">within ' . $recorded_days . ' recorded days</small>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon late">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="summary-late" data-default="<?php echo $summary_data['late_count']; ?>"><?php echo $summary_data['late_count']; ?></div>
                    <div class="summary-label">
                        Late Records
                        <?php 
                        if (isset($filter_month) && !empty($filter_month)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">in ' . date('F Y', strtotime($filter_month . '-01')) . ' (within ' . $recorded_days . ' recorded days)</small>';
                        } elseif (isset($filter_year) && !empty($filter_year)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">in ' . $filter_year . ' (within ' . $recorded_days . ' recorded days)</small>';
                        } elseif (isset($filter_date) && !empty($filter_date)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">on ' . date('M d, Y', strtotime($filter_date)) . '</small>';
                        } else {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">within ' . $recorded_days . ' recorded days</small>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon rate">
                    <i class="fas fa-business-time"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="summary-overtime" data-default="<?php echo $summary_data['overtime_count']; ?>"><?php echo $summary_data['overtime_count']; ?></div>
                    <div class="summary-label">
                        Overtime Records
                        <?php 
                        if (isset($filter_month) && !empty($filter_month)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">in ' . date('F Y', strtotime($filter_month . '-01')) . ' (within ' . $recorded_days . ' recorded days)</small>';
                        } elseif (isset($filter_year) && !empty($filter_year)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">in ' . $filter_year . ' (within ' . $recorded_days . ' recorded days)</small>';
                        } elseif (isset($filter_date) && !empty($filter_date)) {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">on ' . date('M d, Y', strtotime($filter_date)) . '</small>';
                        } else {
                            echo '<br><small style="color: #6c757d; font-size: 12px;">within ' . $recorded_days . ' recorded days</small>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Filter Section -->
        <div class="filter-container">
            <div class="filter-header">
                <h2><i class="fas fa-filter"></i> Filter Records</h2>
            </div>
            <form method="GET" action="">
                <div class="filter-controls">
                    <div class="search-box" style="width: 100%; margin-bottom: 20px; display: flex; gap: 10px;">
                        <input type="text" id="searchInput" name="search" placeholder="Search by employee name or ID..." 
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                               style="flex: 1; padding: 12px; border: 2px solid #DBE2EF; border-radius: 12px; font-size: 14px;"
                               onkeyup="performAjaxSearch()">
                        <button type="button" id="searchBtn" class="btn btn-primary" style="padding: 12px 20px; white-space: nowrap;" onclick="performAjaxSearch()">
                            Search
                        </button>
                    </div>

                    <div class="filter-mode-buttons">
                        <button type="button" class="date-filter-btn active" data-filter="date" title="Filter by specific date"><i class="fas fa-calendar-day"></i> Select Date</button>
                        <button type="button" class="date-filter-btn" data-filter="month" title="Filter by specific month"><i class="fas fa-calendar-alt"></i> Select Month</button>
                        <button type="button" class="date-filter-btn" data-filter="year" title="Filter by specific year"><i class="fas fa-calendar"></i> Select Year</button>
                    </div>

                    <div class="filter-input-area">
                        <div class="date-input-container" id="dateFilter">
                            <label for="date">Select Date:</label>
                            <input type="date" name="date" id="date" class="form-control" 
                                   value="<?php echo isset($_GET['date']) ? htmlspecialchars($_GET['date']) : ''; ?>">
                        </div>

                        <div class="month-input-container" id="monthFilter" style="display: none;">
                            <label for="month">Select Month:</label>
                            <select name="month" id="month" class="form-control">
                                <option value="">All Months</option>
                                <?php
                                // Get months that actually have data
                                $months_sql = "SELECT DISTINCT DATE_FORMAT(a.attendance_date, '%Y-%m') as month FROM attendance a ORDER BY month DESC";
                                $months_result = $conn->query($months_sql);
                                if ($months_result && $months_result->num_rows > 0) {
                                    while ($month_row = $months_result->fetch_assoc()) {
                                        $month = $month_row['month'];
                                        $selected = (isset($_GET['month']) && $_GET['month'] == $month) ? 'selected' : '';
                                        $display_month = date('F Y', strtotime($month . '-01'));
                                        echo "<option value='$month' $selected>$display_month</option>";
                                    }
                                } else {
                                    // Fallback to current month if no data
                                    $current_month = date('Y-m');
                                    $display_month = date('F Y');
                                    echo "<option value='$current_month'>$display_month</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <div class="year-input-container" id="yearFilter" style="display: none;">
                            <label for="year">Select Year:</label>
                            <select name="year" id="year" class="form-control">
                                <option value="">All Years</option>
                                <?php
                                // Get years that actually have data
                                $years_sql = "SELECT DISTINCT YEAR(a.attendance_date) as year FROM attendance a ORDER BY year DESC";
                                $years_result = $conn->query($years_sql);
                                if ($years_result && $years_result->num_rows > 0) {
                                    while ($year_row = $years_result->fetch_assoc()) {
                                        $year = $year_row['year'];
                                    $selected = (isset($_GET['year']) && $_GET['year'] == $year) ? 'selected' : '';
                                    echo "<option value='$year' $selected>$year</option>";
                                    }
                                } else {
                                    // Fallback to current year if no data
                                    $current_year = date('Y');
                                    echo "<option value='$current_year'>$current_year</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="filter-options">
                        <div class="filter-option">
                            <label for="department">Department:</label>
                            <select name="department" id="department" class="form-control" onchange="handleDepartmentChange()">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo (isset($_GET['department']) && $_GET['department'] === $dept) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-option">
                            <label for="status">Status:</label>
                            <select name="status" id="status" class="form-control">
                                <option value="">All Statuses</option>
                                <option value="early_in" <?php echo (isset($_GET['status']) && $_GET['status'] == 'early_in') ? 'selected' : ''; ?>>Early In</option>
                                <option value="early" <?php echo (isset($_GET['status']) && $_GET['status'] == 'early' || isset($_GET['status']) && $_GET['status'] == 'early_out') ? 'selected' : ''; ?>>Early Out</option>
                                <option value="on_time" <?php echo (isset($_GET['status']) && $_GET['status'] == 'on_time') ? 'selected' : ''; ?>>On Time</option>
                                <option value="late" <?php echo (isset($_GET['status']) && $_GET['status'] == 'late') ? 'selected' : ''; ?>>Late</option>
                                <option value="halfday" <?php echo (isset($_GET['status']) && $_GET['status'] == 'halfday' || isset($_GET['status']) && $_GET['status'] == 'half_day') ? 'selected' : ''; ?>>Half Day</option>
                                <option value="on_leave" <?php echo (isset($_GET['status']) && $_GET['status'] == 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                            </select>
                        </div>

                        <div class="filter-option">
                            <label for="attendance_type">Attendance Type:</label>
                            <select name="attendance_type" id="attendance_type" class="form-control">
                                <option value="">All Types</option>
                                <option value="present" <?php echo (isset($_GET['attendance_type']) && $_GET['attendance_type'] == 'present') ? 'selected' : ''; ?>>Present</option>
                                <option value="absent" <?php echo (isset($_GET['attendance_type']) && $_GET['attendance_type'] == 'absent') ? 'selected' : ''; ?>>Absent</option>
                            </select>
                        </div>

                        <div class="filter-option">
                            <label for="shift">Shift:</label>
                            <select name="shift" id="shift" class="form-control">
                                <option value="">All Shifts</option>
                                <option value="08:00-17:00" <?php echo (isset($_GET['shift']) && $_GET['shift'] == '08:00-17:00') ? 'selected' : ''; ?>>8:00 AM - 5:00 PM</option>
                                <option value="08:30-17:30" <?php echo (isset($_GET['shift']) && $_GET['shift'] == '08:30-17:30') ? 'selected' : ''; ?>>8:30 AM - 5:30 PM</option>
                                <option value="09:00-18:00" <?php echo (isset($_GET['shift']) && $_GET['shift'] == '09:00-18:00') ? 'selected' : ''; ?>>9:00 AM - 6:00 PM</option>
                            </select>
                        </div>

                                <div class="filter-option">
                            <label for="show_overtime" style="font-weight: 600; color: #112D4E; display: block; margin-bottom: 8px;">Show Overtime:</label>
                            <label class="switch-btn" style="margin-bottom: 20px;">
                                <input type="checkbox" name="show_overtime" id="show_overtime" value="1" 
                                       <?php echo (isset($_GET['show_overtime']) && $_GET['show_overtime'] === '1') ? 'checked' : ''; ?>>
                                <span class="slider round"></span>
                            </label>
                        </div>
                    </div>

                    <div class="filter-actions">
                        <a href="HRhistory.php" class="btn btn-secondary">
                            <i class="fas fa-sync-alt"></i> Reset
                        </a>
                        <button type="button" class="btn btn-warning" onclick="openBulkEditModal()">
                            <i class="fas fa-users-edit"></i> Bulk Edit
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Results Table -->
        <div class="results-container">
            <div class="results-header">
                <h2><i class="fas fa-table"></i> Attendance Records - <?php echo htmlspecialchars($hr_department); ?></h2>
                <div class="results-actions">
                    <button type="button" class="btn btn-secondary" onclick="recalculateOvertime()" title="Recalculate overtime for better accuracy">
                        <i class="fas fa-calculator"></i> Recalculate Overtime
                    </button>
                    <button type="button" class="btn btn-primary" onclick="handleHistoryExport()" title="Export current results">
                        <i class="fas fa-file-excel"></i> Export
                    </button>
                </div>
            </div>

            <div class="table-container">
                <div id="loadingIndicator" style="display: none; text-align: center; padding: 20px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #3F72AF;"></i>
                    <p>Searching...</p>
                </div>
                <table id="attendanceTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Employee</th>
                            <th>Employee ID</th>
                            <th>Attendance Type</th>
                            <th>Status</th>
                            <th>Late Minutes</th>
                            <th>Early Out Minutes</th>
                            <th>Overtime Hours</th>
                            <th>Total Hours</th>
                            <th>Source</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="attendanceTableBody">
                        <?php if (!empty($attendance_records)): ?>
                            <?php foreach ($attendance_records as $record): ?>
                                <?php
                                    $row_id = (int)$record['id'];
                                    $time_in = $record['time_in'] ?? null;
                                    $time_out = $record['time_out'] ?? null;
                                    $display_status = $record['status'] !== null ? $record['status'] : 'absent';
                                ?>
                                <tr data-id="<?php echo $row_id; ?>"
                                    data-employee_id="<?php echo htmlspecialchars($record['EmployeeID']); ?>"
                                    data-employee_name="<?php echo htmlspecialchars($record['EmployeeName']); ?>"
                                    data-department="<?php echo htmlspecialchars($record['Department'] ?? ''); ?>"
                                    data-shift="<?php echo htmlspecialchars($record['Shift'] ?? ''); ?>"
                                    data-attendance_type="<?php echo htmlspecialchars($record['attendance_type'] ?? ''); ?>"
                                    data-date="<?php echo htmlspecialchars($record['attendance_date']); ?>"
                                    data-time_in="<?php echo htmlspecialchars($time_in ?? ''); ?>"
                                    data-time_out="<?php echo htmlspecialchars($time_out ?? ''); ?>"
                                    data-source="<?php echo htmlspecialchars($record['data_source'] ?? 'biometric'); ?>">
                                    <td><?php 
                                        // Show the attendance date (either actual or generated)
                                        echo date('M d, Y', strtotime($record['attendance_date']));
                                    ?></td>
                                    <td><?php echo htmlspecialchars($record['EmployeeName']); ?></td>
                                    <td><?php echo htmlspecialchars($record['EmployeeID']); ?></td>
                                    <td>
                                        <?php 
                                        $attendance_type = $record['attendance_type'];
                                        if ($record['id'] == 0) {
                                            echo '<span class="status-badge status-absent-no-record">Absent</span>';
                                        } elseif ($attendance_type === 'present') {
                                            echo '<span class="status-badge status-present">Present</span>';
                                        } else {
                                            echo '<span class="status-badge status-absent">Absent</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $status = $record['status'] ?? null;
                                        if (($record['is_on_leave'] ?? 0) == 1) {
                                            echo '<span class="status-badge status-on-leave">ON-LEAVE</span>';
                                        } elseif ($record['id'] == 0 || $status === 'no_record') {
                                            echo '<span class="status-badge status-normal">No Record</span>';
                                        } elseif (!empty($record['late_minutes']) && (int)$record['late_minutes'] > 0) {
                                            echo '<span class="status-badge status-late">Late</span>';
                                        } elseif ($status === 'early') {
                                            echo '<span class="status-badge status-early">Early</span>';
                                        } elseif ($status === 'on_time') {
                                            echo '<span class="status-badge status-normal">On-time</span>';
                                        } elseif ($status === 'early_in') {
                                            echo '<span class="status-badge status-normal">Early In</span>';
                                        } elseif ($status === 'halfday' || $status === 'half_day') {
                                            echo '<span class="status-badge status-half-day">Half Day</span>';
                                        } else {
                                            echo '<span class="status-badge status-normal">On-time</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo (in_array($record['status'], ['half_day', 'halfday'])) ? '-' : ((!empty($record['late_minutes']) && $record['late_minutes'] > 0) ? (int)$record['late_minutes'] : '-'); ?></td>
                                    <td><?php echo (in_array($record['status'], ['half_day', 'halfday'])) ? '-' : ((!empty($record['early_out_minutes']) && $record['early_out_minutes'] > 0) ? (int)$record['early_out_minutes'] : '-'); ?></td>
                                    <td><?php 
                                        $ot_hours = isset($record['overtime_hours']) ? (float)$record['overtime_hours'] : 0;
                                        echo ($ot_hours > 0) ? number_format($ot_hours, 2) : '-';
                                    ?></td>
                                    <td><?php 
                                        // Don't show total hours if employee is on leave
                                        if (($record['is_on_leave'] ?? 0) == 1) {
                                            echo '-';
                                        } else {
                                            $th = isset($record['total_hours']) ? (float)$record['total_hours'] : 0.0;
                                            if ($th <= 0) {
                                                $ti = $record['time_in'] ?? '';
                                                $to = $record['time_out'] ?? '';
                                                if (!empty($ti) && !empty($to)) {
                                                    $th = (float)AttendanceCalculator::calculateTotalHours($ti, $to);
                                                }
                                            }
                                            echo ($th > 0) ? number_format($th, 2) : '-';
                                        }
                                    ?></td>
                                    <td style="text-align: center;">
                                        <?php 
                                        $dataSource = $record['data_source'] ?? 'biometric';
                                        switch($dataSource) {
                                            case 'manual':
                                                echo '<i class="fas fa-edit text-primary" title="Manual Entry (Web App)"></i>';
                                                break;
                                            case 'bulk_edit':
                                                echo '<i class="fas fa-users-cog text-warning" title="Bulk Edit (Web App)"></i>';
                                                break;
                                            case 'biometric':
                                            default:
                                                echo '<i class="fas fa-fingerprint text-success" title="Biometric Scanner (ZKteco Device)"></i>';
                                                break;
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="action-btn view-btn" onclick="openHrViewModal('<?php echo htmlspecialchars($record['EmployeeName']); ?>','<?php echo htmlspecialchars($record['EmployeeID']); ?>','<?php echo htmlspecialchars($record['attendance_date']); ?>','<?php echo htmlspecialchars($time_in ?? ''); ?>','<?php echo htmlspecialchars($time_out ?? ''); ?>','<?php echo htmlspecialchars($record['time_in_morning'] ?? ''); ?>','<?php echo htmlspecialchars($record['time_out_morning'] ?? ''); ?>','<?php echo htmlspecialchars($record['time_in_afternoon'] ?? ''); ?>','<?php echo htmlspecialchars($record['time_out_afternoon'] ?? ''); ?>','<?php echo htmlspecialchars($display_status); ?>','<?php echo (int)($record['late_minutes'] ?? 0); ?>','<?php echo (int)($record['early_out_minutes'] ?? 0); ?>','<?php echo (float)($record['overtime_hours'] ?? 0); ?>','<?php echo (int)($record['is_on_leave'] ?? 0); ?>')" title="View"><i class="fas fa-eye"></i></button>
                                            <?php if ($record['id'] == 0): ?>
                                                <button type="button" class="action-btn edit-btn" onclick="openEditAttendance(this, '<?php echo $row_id; ?>')" title="Create Record"><i class="fas fa-plus"></i></button>
                                            <?php elseif (empty($time_out)): ?>
                                                <button type="button" class="action-btn approve-btn" onclick="markTimeOut('<?php echo $row_id; ?>', this)" title="Mark Time Out"><i class="fas fa-sign-out-alt"></i></button>
                                            <?php else: ?>
                                                <button type="button" class="action-btn edit-btn" onclick="openEditAttendance(this, '<?php echo $row_id; ?>')" title="Edit"><i class="fas fa-edit"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-search" style="font-size: 48px; color: #97BC62; margin-bottom: 15px;"></i>
                                    <br>
                                    <strong>No records found</strong>
                                    <br>
                                    <small style="color: #6c757d;">Try adjusting your search criteria or filters</small>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination Controls -->
            <div class="pagination-container" style="display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; background: #fff; border-top: 1px solid #e5e7eb; border-radius: 0 0 12px 12px;">
                <div class="pagination-info" style="color: #6b7280; font-size: 14px;">
                    Showing <span id="pagination-start">1</span> to <span id="pagination-end">10</span> of <span id="pagination-total">0</span> records
                </div>
                <div class="pagination-controls" style="display: flex; gap: 8px; align-items: center;">
                    <button class="pagination-btn" onclick="changePage('first')" id="btn-first" style="padding: 8px 12px; border: 1px solid #d1d5db; background: white; border-radius: 6px; cursor: pointer; color: #374151; transition: all 0.2s;">
                        <i class="fas fa-angle-double-left"></i>
                    </button>
                    <button class="pagination-btn" onclick="changePage('prev')" id="btn-prev" style="padding: 8px 12px; border: 1px solid #d1d5db; background: white; border-radius: 6px; cursor: pointer; color: #374151; transition: all 0.2s;">
                        <i class="fas fa-angle-left"></i> Previous
                    </button>
                    <div class="pagination-pages" id="pagination-pages" style="display: flex; gap: 4px;"></div>
                    <button class="pagination-btn" onclick="changePage('next')" id="btn-next" style="padding: 8px 12px; border: 1px solid #d1d5db; background: white; border-radius: 6px; cursor: pointer; color: #374151; transition: all 0.2s;">
                        Next <i class="fas fa-angle-right"></i>
                    </button>
                    <button class="pagination-btn" onclick="changePage('last')" id="btn-last" style="padding: 8px 12px; border: 1px solid #d1d5db; background: white; border-radius: 6px; cursor: pointer; color: #374151; transition: all 0.2s;">
                        <i class="fas fa-angle-double-right"></i>
                    </button>
                </div>
                <div class="pagination-settings" style="display: flex; align-items: center; gap: 8px;">
                    <label style="color: #6b7280; font-size: 14px;">Show:</label>
                    <select id="records-per-page" onchange="changeRecordsPerPage()" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: white; color: #374151; cursor: pointer;">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Year Export Month Range Modal -->
        <div id="yearExportModal" class="modal">
            <div class="modal-content" style="max-width: 520px;">
                <div class="modal-header" style="background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%); color:#fff; padding:16px 20px; display:flex; align-items:center; justify-content:space-between;">
                    <h2 style="margin:0; font-size:18px;"><i class="fas fa-calendar-alt"></i> Select Month Range</h2>
                    <button class="close" onclick="closeYearExportModal()" aria-label="Close" style="background:none; border:none; color:#fff; font-size:24px; cursor:pointer;">&times;</button>
                </div>
                <div class="modal-body" style="line-height:1.8; padding:20px;">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label for="startMonth" style="display:block; margin-bottom:6px; font-weight:600; color:#112D4E;">From Month</label>
                            <select id="startMonth" class="form-control">
                                <option value="1">January</option>
                                <option value="2">February</option>
                                <option value="3">March</option>
                                <option value="4">April</option>
                                <option value="5">May</option>
                                <option value="6">June</option>
                                <option value="7">July</option>
                                <option value="8">August</option>
                                <option value="9">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                        </div>
                        <div>
                            <label for="endMonth" style="display:block; margin-bottom:6px; font-weight:600; color:#112D4E;">To Month</label>
                            <select id="endMonth" class="form-control">
                                <option value="1">January</option>
                                <option value="2">February</option>
                                <option value="3">March</option>
                                <option value="4">April</option>
                                <option value="5">May</option>
                                <option value="6">June</option>
                                <option value="7">July</option>
                                <option value="8">August</option>
                                <option value="9">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                        </div>
                    </div>
                    <small style="color:#6c757d;">Tip: Make sure "To Month" is not earlier than "From Month".</small>
                </div>
                <div class="modal-footer" style="padding:16px 20px; border-top:1px solid #eee; display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" class="btn btn-secondary" onclick="closeYearExportModal()">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="confirmYearExport()"><i class="fas fa-file-export"></i> Export</button>
                </div>
            </div>
        </div>

        <!-- Date Export Range Modal -->
        <div id="dateExportModal" class="modal">
            <div class="modal-content" style="max-width: 520px;">
                <div class="modal-header" style="background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%); color:#fff; padding:16px 20px; display:flex; align-items:center; justify-content:space-between;">
                    <h2 style="margin:0; font-size:18px;"><i class="fas fa-calendar-day"></i> Select Date Range</h2>
                    <button class="close" onclick="closeDateExportModal()" aria-label="Close" style="background:none; border:none; color:#fff; font-size:24px; cursor:pointer;">&times;</button>
                </div>
                <div class="modal-body" style="line-height:1.8; padding:20px;">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label for="startDateExport" style="display:block; margin-bottom:6px; font-weight:600; color:#112D4E;">From Date</label>
                            <input type="date" id="startDateExport" class="form-control">
                        </div>
                        <div>
                            <label for="endDateExport" style="display:block; margin-bottom:6px; font-weight:600; color:#112D4E;">To Date</label>
                            <input type="date" id="endDateExport" class="form-control">
                        </div>
                    </div>
                    <small style="color:#6c757d;">Tip: The export will include working days only (excludes Sundays).</small>
                </div>
                <div class="modal-footer" style="padding:16px 20px; border-top:1px solid #eee; display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" class="btn btn-secondary" onclick="closeDateExportModal()">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="confirmDateExport()"><i class="fas fa-file-export"></i> Export</button>
                </div>
            </div>
        </div>

        <!-- View Details Modal -->
        <div id="hrViewModal" class="modal">
            <div class="modal-content" style="max-width:720px; border-radius:20px; overflow:hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                <div class="modal-header" style="background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%); color:#fff; padding:24px 28px; display:flex; align-items:center; justify-content:space-between; position:relative;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <div style="width:48px; height:48px; background:rgba(255,255,255,0.2); border-radius:12px; display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-id-card" style="font-size:20px;"></i>
                        </div>
                        <div>
                            <h2 style="margin:0; font-size:20px; font-weight:600;">Attendance Details</h2>
                            <p style="margin:4px 0 0 0; font-size:14px; opacity:0.9;">Employee attendance information</p>
                        </div>
                    </div>
                    <button class="close" onclick="closeHrViewModal()" aria-label="Close" style="background:rgba(255,255,255,0.2); border:none; color:#fff; font-size:20px; cursor:pointer; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; transition:all 0.3s ease;">&times;</button>
                </div>
                <div class="modal-body" id="hrViewBody" style="padding:28px; background:#fafbfc; max-height:70vh; overflow-y:auto; scrollbar-width: thin; scrollbar-color: #3F72AF #f1f1f1;"></div>
                <div class="modal-footer" style="padding:20px 28px; border-top:1px solid #e1e8ed; display:flex; justify-content:flex-end; background:#fff;">
                    <button type="button" class="btn btn-secondary" onclick="closeHrViewModal()" style="background:#6c757d; color:#fff; border:none; padding:10px 24px; border-radius:8px; font-weight:500; transition:all 0.3s ease;">Close</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Edit Attendance Modal -->
    <div id="editAttendanceModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%); color:#fff; padding:16px 20px; display:flex; align-items:center; justify-content:space-between;">
                <h2 id="editModalTitle" style="margin:0; font-size:18px;"><i class="fas fa-edit"></i> Edit Attendance</h2>
                <button class="close" onclick="closeEditAttendanceModal()" aria-label="Close" style="background:none; border:none; color:#fff; font-size:24px; cursor:pointer;">&times;</button>
            </div>
            <div class="modal-body" style="line-height:1.8; padding:20px;">
                <form id="editAttendanceForm" onsubmit="return submitEditAttendance(event)">
                    <input type="hidden" id="edit_id" name="id">
                    <input type="hidden" id="edit_employee_id" name="employee_id">
                    <input type="hidden" id="edit_attendance_date" name="attendance_date">
                    
                    <!-- Employee Information -->
                    <div id="employeeShiftInfo" style="background: linear-gradient(135deg, #E3F2FD 0%, #F3E5F5 100%); padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #3F72AF;">
                        <h3 style="color: #3F72AF; margin: 0 0 10px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-user-clock"></i> Employee Information
                        </h3>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                            <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #e0e0e0;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 4px;"><i class="fas fa-user"></i> Employee Name</div>
                                <div id="shiftEmployeeName" style="font-weight: 600; color: #333; font-size: 15px;">-</div>
                            </div>
                            <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #e0e0e0;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 4px;"><i class="fas fa-building"></i> Department</div>
                                <div id="shiftDepartment" style="font-weight: 600; color: #333; font-size: 15px;">-</div>
                            </div>
                            <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #e0e0e0;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 4px;"><i class="fas fa-clock"></i> Shift Schedule</div>
                                <div id="shiftSchedule" style="font-weight: 600; color: #333; font-size: 15px;">-</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Time Tracking Section -->
                    <div style="margin-bottom: 25px;">
                        <h3 style="color: #112D4E; margin-bottom: 15px; font-size: 16px; border-bottom: 2px solid #DBE2EF; padding-bottom: 8px;">
                            <i class="fas fa-clock"></i> Time Tracking
                        </h3>
                        
                        <!-- Morning Session -->
                        <div style="background: #F8FBFF; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #3F72AF;">
                            <h4 style="color: #3F72AF; margin-bottom: 10px; font-size: 14px;">
                                <i class="fas fa-sun"></i> Morning Session
                            </h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label for="edit_am_in" style="display: block; margin-bottom: 8px; font-weight: 600; color: #112D4E; font-size: 13px;">Morning Time In</label>
                                    <input type="time" id="edit_am_in" name="am_in" class="form-control">
                    </div>
                                <div class="form-group">
                                    <label for="edit_am_out" style="display: block; margin-bottom: 8px; font-weight: 600; color: #112D4E; font-size: 13px;">Morning Time Out</label>
                                    <input type="time" id="edit_am_out" name="am_out" class="form-control">
                    </div>
                            </div>
                        </div>
                        
                        <!-- Afternoon Session -->
                        <div style="background: #F8FBFF; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #16C79A;">
                            <h4 style="color: #16C79A; margin-bottom: 10px; font-size: 14px;">
                                <i class="fas fa-moon"></i> Afternoon Session
                            </h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label for="edit_pm_in" style="display: block; margin-bottom: 8px; font-weight: 600; color: #112D4E; font-size: 13px;">Afternoon Time In</label>
                                    <input type="time" id="edit_pm_in" name="pm_in" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="edit_pm_out" style="display: block; margin-bottom: 8px; font-weight: 600; color: #112D4E; font-size: 13px;">Afternoon Time Out</label>
                                    <input type="time" id="edit_pm_out" name="pm_out" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Overall Time (Auto-calculated) -->
                        <div style="background: #FFF8E1; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #FFC75F;">
                            <h4 style="color: #F57C00; margin-bottom: 10px; font-size: 14px;">
                                <i class="fas fa-calculator"></i> Overall Time (Auto-calculated)
                            </h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label for="edit_time_in" style="display: block; margin-bottom: 8px; font-weight: 600; color: #112D4E; font-size: 13px;">Total Time In</label>
                                    <input type="time" id="edit_time_in" name="time_in" class="form-control" readonly style="background-color: #f5f5f5;">
                                </div>
                                <div class="form-group">
                                    <label for="edit_time_out" style="display: block; margin-bottom: 8px; font-weight: 600; color: #112D4E; font-size: 13px;">Total Time Out</label>
                                    <input type="time" id="edit_time_out" name="time_out" class="form-control" readonly style="background-color: #f5f5f5;">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Status and Overtime Section -->
                    <div style="margin-bottom: 25px;">
                        <h3 style="color: #112D4E; margin-bottom: 15px; font-size: 16px; border-bottom: 2px solid #DBE2EF; padding-bottom: 8px;">
                            <i class="fas fa-cog"></i> Status & Overtime
                        </h3>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                            <div class="form-group">
                                <label for="edit_attendance_type" style="display: block; margin-bottom: 8px; font-weight: 600; color: #112D4E; font-size: 13px;">Attendance Type</label>
                                <select id="edit_attendance_type" name="attendance_type" class="form-control">
                                    <option value="present">Present</option>
                                    <option value="absent">Absent</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="edit_status" style="display: block; margin-bottom: 8px; font-weight: 600; color: #112D4E; font-size: 13px;">Status</label>
                                <select id="edit_status" name="status" class="form-control">
                                    <option value="">Normal</option>
                                    <option value="late">Late</option>
                                    <option value="early">Early Out</option>
                                    <option value="on_leave">On Leave</option>
                                </select>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                            <div class="form-group">
                                <label for="edit_overtime_hours" style="display: block; margin-bottom: 8px; font-weight: 600; color: #112D4E; font-size: 13px;">Overtime Hours</label>
                                <input type="number" id="edit_overtime_hours" name="overtime_hours" class="form-control" step="0.01" min="0" placeholder="0.00">
                            </div>
                            <div class="form-group">
                                <label for="edit_is_overtime" style="display: block; margin-bottom: 8px; font-weight: 600; color: #112D4E; font-size: 13px;">Overtime Status</label>
                                <select id="edit_is_overtime" name="is_overtime" class="form-control">
                                    <option value="0">No Overtime</option>
                                    <option value="1">Has Overtime</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #112D4E; font-size: 13px; cursor: pointer;">
                                <input type="checkbox" id="edit_half_day" name="half_day" value="1" style="transform: scale(1.2);" onchange="toggleHalfDay()">
                                <i class="fas fa-clock-half"></i> Mark as Half Day
                            </label>
                            <small style="color: #6c757d; font-size: 12px; margin-left: 25px;">
                                When checked, afternoon times will be cleared and employee will be marked as half day
                            </small>
                        </div>
                    </div>
                    
                    <!-- Notes Section -->
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="edit_notes" style="display: block; margin-bottom: 8px; font-weight: 600; color: #112D4E;">Notes</label>
                        <textarea id="edit_notes" name="notes" class="form-control" rows="3" style="resize: vertical;" placeholder="Add any additional notes..."></textarea>
                    </div>
                    
                    <!-- Leave Status Section -->
                    <div class="form-group" style="background: #FFF3E0; padding: 15px; border-radius: 8px; border-left: 4px solid #FF9800; margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #E65100; font-size: 14px; cursor: pointer;">
                            <input type="checkbox" id="edit_is_on_leave" name="is_on_leave" value="1" style="transform: scale(1.2);" onchange="toggleLeaveStatus()">
                            <i class="fas fa-calendar-times"></i> Set Employee on Leave
                        </label>
                        <small style="color: #6c757d; font-size: 12px; margin-left: 25px;">
                            When checked, employee will be marked as on leave and one leave day will be deducted from their leave balance
                        </small>
                        <div id="leaveBalanceInfo" style="margin-top: 8px; padding: 8px; background: #fff; border-radius: 4px; font-size: 12px; color: #666; display: none;">
                            <i class="fas fa-info-circle"></i> <span id="leaveBalanceText">Loading leave balance...</span>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="padding:16px 20px; border-top:1px solid #eee; display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditAttendanceModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('editAttendanceForm').dispatchEvent(new Event('submit'))"><i class="fas fa-save"></i> Save Changes</button>
            </div>
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
        
    // Filter toggle functionality
    document.addEventListener('DOMContentLoaded', function() {
        const filterButtons = document.querySelectorAll('.date-filter-btn');
        const dateFilter = document.getElementById('dateFilter');
        const monthFilter = document.getElementById('monthFilter');
        const yearFilter = document.getElementById('yearFilter');
        
        // Use the PHP-determined active filter
        const activeFilter = '<?php echo $active_filter; ?>';
        
        // Activate the correct filter button based on URL
        filterButtons.forEach(button => {
            button.classList.remove('active');
            if (button.dataset.filter === activeFilter) {
                button.classList.add('active');
            }
            button.addEventListener('click', function() {
                filterButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
                const filterType = this.dataset.filter;
                dateFilter.style.display = 'none';
                monthFilter.style.display = 'none';
                yearFilter.style.display = 'none';
                if (filterType === 'date') { dateFilter.style.display = 'block'; }
                else if (filterType === 'month') { monthFilter.style.display = 'block'; }
                else if (filterType === 'year') { yearFilter.style.display = 'block'; }
            });
        });
        
        // Show the correct filter input based on active filter (default to year)
        dateFilter.style.display = activeFilter === 'date' ? 'block' : 'none';
        monthFilter.style.display = activeFilter === 'month' ? 'block' : 'none';
        yearFilter.style.display = activeFilter === 'year' ? 'block' : 'none';
        // If defaulting to year view and no specific year selected, leave year empty to show system range across years

        // If no specific filter provided and active filter is date, set the date input to today
        if (activeFilter === 'date' && !document.getElementById('date').value) {
            const dateInput = document.getElementById('date');
            if (dateInput) {
                dateInput.value = new Date().toISOString().split('T')[0];
            }
        }

        // On Apply Filters, clear non-active inputs so hidden stale values don't override
        const form = document.querySelector('form[method="GET"]');
        if (form) {
            form.addEventListener('submit', function(e) {
                const dateInput = document.getElementById('date');
                const monthInput = document.getElementById('month');
                const yearInput = document.getElementById('year');
                
                // Determine mode by populated input first (most reliable)
                let mode = 'year';
                if (yearInput && yearInput.value) {
                    mode = 'year';
                } else if (monthInput && monthInput.value) {
                    mode = 'month';
                } else if (dateInput && dateInput.value) {
                    mode = 'date';
                } else {
                    // Fallback to currently highlighted button
                    const activeBtn = document.querySelector('.date-filter-btn.active');
                    mode = activeBtn ? activeBtn.getAttribute('data-filter') : 'year';
                }
                
                // Clear non-active inputs so only selected mode drives server filter
                if (mode !== 'date' && dateInput) dateInput.value = '';
                if (mode !== 'month' && monthInput) monthInput.value = '';
                if (mode !== 'year' && yearInput) yearInput.value = '';
                
                // Ensure hidden active_filter matches resolved mode
                const existingInput = form.querySelector('input[name="active_filter"]');
                if (existingInput) { existingInput.remove(); }
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'active_filter';
                hiddenInput.value = mode;
                form.appendChild(hiddenInput);
            });
        }
    });

    
    // Add some interactive features
    document.addEventListener('DOMContentLoaded', function() {
        // Highlight rows on hover
        const tableRows = document.querySelectorAll('tbody tr');
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#F0F8F0';
            });
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
        });

        // Add click handler for status badges
        const statusBadges = document.querySelectorAll('.status-badge');
        statusBadges.forEach(badge => {
            badge.addEventListener('click', function() {
                const status = this.textContent.toLowerCase().trim();
                const statusSelect = document.getElementById('status');
                statusSelect.value = status;
                // You could auto-submit the form here if desired
            });
        });
    });

    // HR View modal helpers
    function openHrViewModal(name, id, date, timeIn, timeOut, amIn, amOut, pmIn, pmOut, status, late, early, ot, isOnLeave) {
        const body = document.getElementById('hrViewBody');
        
        const fmt = (t) => {
            if (!t || t === '' || t === 'HH:MM') return '-';
            try {
                // Handle different time formats
                let timeStr = t.toString();
                // If it's already in HH:MM format, return as is
                if (/^\d{1,2}:\d{2}$/.test(timeStr)) {
                    return timeStr;
                }
                // If it's in HH:MM:SS format, convert to HH:MM
                if (/^\d{1,2}:\d{2}:\d{2}$/.test(timeStr)) {
                    return timeStr.substring(0, 5);
                }
                // Try to parse as time and format
                const time = new Date('1970-01-01T' + timeStr);
                if (!isNaN(time.getTime())) {
                    return time.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: false});
                }
                return timeStr;
            } catch (e) {
                return t || '-';
            }
        };
        const dstr = date ? new Date(date).toLocaleDateString('en-US', {weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'}) : '-';
        const isHalfDay = status === 'half_day' || status === 'halfday';
        const hasAmPair = amIn && amOut && !pmIn && !pmOut;
        const hasPmPair = pmIn && pmOut && !amIn && !amOut;
        
        // For halfdays, use the actual time out based on which session was attended
        let displayTimeOut = timeOut;
        if (isHalfDay) {
            if (hasAmPair) {
                // Morning-only halfday: use morning out as overall time out
                displayTimeOut = amOut || timeOut;
            } else if (hasPmPair) {
                // Afternoon-only halfday: use afternoon out as overall time out
                displayTimeOut = pmOut || timeOut;
            }
        }

        // Get employee shift information from the row data
        const row = document.querySelector(`tr[data-employee_id='${id}']`);
        const shift = row ? row.getAttribute('data-shift') || '-' : '-';
        
        // Format shift to AM/PM
        const formatShift = (shiftStr) => {
            if (!shiftStr || shiftStr === '-') return '-';
            try {
                const parts = shiftStr.split('-');
                if (parts.length === 2) {
                    const start = new Date('1970-01-01T' + parts[0].trim()).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true});
                    const end = new Date('1970-01-01T' + parts[1].trim()).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true});
                    return `${start} - ${end}`;
                }
                return shiftStr;
            } catch (e) {
                return shiftStr;
            }
        };

        // Build session HTML based on half day
        let sessionsHtml = '';
        if (isHalfDay && hasPmPair) {
            sessionsHtml = `
                <div style="background: #E8F5E8; padding: 20px; border-radius: 12px; border-left: 4px solid #16C79A; margin-bottom: 20px;">
                    <h4 style="color: #16C79A; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-moon"></i> Afternoon Session (Half Day)
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-fingerprint text-success"></i> Time In
                            </div>
                            <input type="text" value="${fmt(pmIn)}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-weight: 600; color: #333;" placeholder="HH:MM" readonly>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-fingerprint text-success"></i> Time Out
                            </div>
                            <input type="text" value="${fmt(pmOut)}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-weight: 600; color: #333;" placeholder="HH:MM" readonly>
                        </div>
                    </div>
                </div>`;
        } else if (isHalfDay && hasAmPair) {
            sessionsHtml = `
                <div style="background: #FFF8E1; padding: 20px; border-radius: 12px; border-left: 4px solid #FFC75F; margin-bottom: 20px;">
                    <h4 style="color: #F57C00; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-sun"></i> Morning Session (Half Day)
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-fingerprint text-success"></i> Time In
                            </div>
                            <input type="text" value="${fmt(amIn)}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-weight: 600; color: #333;" placeholder="HH:MM" readonly>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-fingerprint text-success"></i> Time Out
                            </div>
                            <input type="text" value="${fmt(amOut)}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-weight: 600; color: #333;" placeholder="HH:MM" readonly>
                        </div>
                    </div>
                </div>`;
        } else {
            sessionsHtml = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div style="background: #FFF8E1; padding: 20px; border-radius: 12px; border-left: 4px solid #FFC75F;">
                    <h4 style="color: #F57C00; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-sun"></i> Morning Session
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-fingerprint text-success"></i> Time In
                            </div>
                            <input type="text" value="${fmt(amIn)}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-weight: 600; color: #333;" placeholder="HH:MM" readonly>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-fingerprint text-success"></i> Time Out
                            </div>
                            <input type="text" value="${fmt(amOut)}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-weight: 600; color: #333;" placeholder="HH:MM" readonly>
                        </div>
                    </div>
                </div>
                <div style="background: #E8F5E8; padding: 20px; border-radius: 12px; border-left: 4px solid #16C79A;">
                    <h4 style="color: #16C79A; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-moon"></i> Afternoon Session
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-fingerprint text-success"></i> Time In
                            </div>
                            <input type="text" value="${fmt(pmIn)}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-weight: 600; color: #333;" placeholder="HH:MM" readonly>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-fingerprint text-success"></i> Time Out
                            </div>
                            <input type="text" value="${fmt(pmOut)}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-weight: 600; color: #333;" placeholder="HH:MM" readonly>
                        </div>
                    </div>
                </div>
            </div>`;
        }
        body.innerHTML = `
            <div style="background: #F8FBFF; padding: 20px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #3F72AF;">
                <h4 style="color: #3F72AF; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-user"></i> Employee Information
                </h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Employee Name</div>
                        <div style="font-weight: 600; color: #333; font-size: 16px;">${name}</div>
                    </div>
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Employee ID</div>
                        <div style="font-weight: 600; color: #333; font-size: 16px;">${id}</div>
                    </div>
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Shift</div>
                        <div style="font-weight: 600; color: #333; font-size: 16px;">${formatShift(shift)}</div>
                    </div>
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Date</div>
                        <div style="font-weight: 600; color: #333; font-size: 16px;">${dstr}</div>
                    </div>
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Data Source</div>
                        <div style="font-weight: 600; color: #333; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                            ${getSourceIcon(row ? row.getAttribute('data-source') || 'biometric' : 'biometric')} ${getSourceText(row ? row.getAttribute('data-source') || 'biometric' : 'biometric')}
                        </div>
                    </div>
                </div>
            </div>
            
            ${sessionsHtml}
            
            <div style="background: #F0F8FF; padding: 20px; border-radius: 12px; border-left: 4px solid #3F72AF;">
                <h4 style="color: #3F72AF; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-chart-line"></i> Status & Metrics
                </h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Overall Time In</div>
                        <div style="font-weight: 600; color: #333; font-size: 16px;">${fmt(timeIn)}</div>
                    </div>
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Overall Time Out</div>
                        <div style="font-weight: 600; color: #333; font-size: 16px;">${fmt(displayTimeOut)}</div>
                    </div>
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Status</div>
                        <div style="font-weight: 600; color: #333; font-size: 16px;">${(isOnLeave == 1 || isOnLeave === '1') ? 'On Leave' : (status ? (status === 'on_time' ? 'On-time' : (status === 'on_leave' ? 'On Leave' : (status === 'half_day' || status === 'halfday' ? 'Half Day' : status.charAt(0).toUpperCase()+status.slice(1)))) : 'On-time')}</div>
                    </div>
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Late (minutes)</div>
                        <div style="font-weight: 600; color: #333; font-size: 16px;">${(isHalfDay && hasPmPair) ? '-' : (isHalfDay ? '-' : late)}</div>
                    </div>
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Early Out (minutes)</div>
                        <div style="font-weight: 600; color: #333; font-size: 16px;">${(isHalfDay && hasAmPair) ? '-' : (isHalfDay ? '-' : early)}</div>
                    </div>
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Overtime (hours)</div>
                        <div style="font-weight: 600; color: #333; font-size: 16px;">${parseFloat(ot).toFixed(2)}</div>
                    </div>
                    ${(isOnLeave == 1 || isOnLeave === '1') ? `
                    <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0; grid-column: 1 / -1;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-calendar-times" style="color: #E74C3C;"></i> Leave Status
                        </div>
                        <div style="font-weight: 600; color: #E74C3C; font-size: 16px;">Is on Leave</div>
                    </div>
                    ` : ''}
                </div>
            </div>
        `;
        const modal = document.getElementById('hrViewModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    function closeHrViewModal() {
        const modal = document.getElementById('hrViewModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }

    // Edit + Mark Out functionality
    function openEditAttendance(btn, id) {
        // Derive the correct row from the clicked button to avoid matching the first row with data-id=0
        const row = btn?.closest('tr') || document.querySelector(`tr[data-id='${id}']`);
        if (!row) {
            console.error('Row not found for id:', id);
            return;
        }
        
        // Get all data attributes from the row
        const employeeId = row.getAttribute('data-employee_id') || '';
        const employeeName = row.getAttribute('data-employee_name') || '';
        const department = row.getAttribute('data-department') || '';
        const shift = row.getAttribute('data-shift') || '';
        const date = row.getAttribute('data-date') || '';
        const timeIn = row.getAttribute('data-time_in') || '';
        const timeOut = row.getAttribute('data-time_out') || '';
        
        // Set modal title based on whether creating or editing
        const modalTitle = document.getElementById('editModalTitle');
        if (String(id) === '0') {
            modalTitle.innerHTML = '<i class="fas fa-plus"></i> Create Attendance Record';
        } else {
            modalTitle.innerHTML = '<i class="fas fa-edit"></i> Edit Attendance';
        }
        
        // Set basic form data
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_employee_id').value = employeeId;
        document.getElementById('edit_attendance_date').value = date;
        document.getElementById('edit_time_in').value = timeIn ? timeIn.substring(0,5) : '';
        document.getElementById('edit_time_out').value = timeOut ? timeOut.substring(0,5) : '';
        document.getElementById('edit_notes').value = '';
        
        // Display employee information
        console.log('Opening edit modal for:', {
            id: id,
            employeeId: employeeId,
            employeeName: employeeName,
            department: department,
            shift: shift,
            date: date
        });
        
        document.getElementById('shiftEmployeeName').textContent = employeeName || '-';
        document.getElementById('shiftDepartment').textContent = department || '-';
        document.getElementById('shiftSchedule').textContent = formatShiftForDisplay(shift);
        
        // Set default time values based on shift (only for new records or when no existing times)
        if (id == 0 || (!timeIn && !timeOut)) {
            setDefaultTimesBasedOnShift(shift);
        } else {
            // Clear all time inputs initially for existing records
            document.getElementById('edit_am_in').value = '';
            document.getElementById('edit_am_out').value = '';
            document.getElementById('edit_pm_in').value = '';
            document.getElementById('edit_pm_out').value = '';
        }
        
        // Reset status fields
        document.getElementById('edit_attendance_type').value = 'present';
        document.getElementById('edit_status').value = '';
        document.getElementById('edit_overtime_hours').value = '';
        document.getElementById('edit_is_overtime').value = '0';
        document.getElementById('edit_half_day').checked = false;
        
        // Reset leave status
        document.getElementById('edit_is_on_leave').checked = false;
        document.getElementById('leaveBalanceInfo').style.display = 'none';
        
        // Reset afternoon input states
        document.getElementById('edit_pm_in').disabled = false;
        document.getElementById('edit_pm_out').disabled = false;
        document.getElementById('edit_pm_in').style.backgroundColor = '';
        document.getElementById('edit_pm_out').style.backgroundColor = '';
        
        // If this is a new record (id = 0), we need to create the record first
        if (String(id) === '0') {
            // For new records, we'll need to create the record first
            // This will be handled in the submit function
            document.getElementById('edit_id').value = '0';
        } else {
            // For existing records, fetch the full record data
            fetchRecordData(id);
        }
        
        const modal = document.getElementById('editAttendanceModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    // Fetch full record data for editing
    function fetchRecordData(id) {
        fetch('fetch_attendance_record.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${encodeURIComponent(id)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.record) {
                const record = data.record;
                
                // Set time inputs
                document.getElementById('edit_am_in').value = record.time_in_morning ? record.time_in_morning.substring(0,5) : '';
                document.getElementById('edit_am_out').value = record.time_out_morning ? record.time_out_morning.substring(0,5) : '';
                document.getElementById('edit_pm_in').value = record.time_in_afternoon ? record.time_in_afternoon.substring(0,5) : '';
                document.getElementById('edit_pm_out').value = record.time_out_afternoon ? record.time_out_afternoon.substring(0,5) : '';
                document.getElementById('edit_time_in').value = record.time_in ? record.time_in.substring(0,5) : '';
                document.getElementById('edit_time_out').value = record.time_out ? record.time_out.substring(0,5) : '';
                
                // Set status fields
                document.getElementById('edit_attendance_type').value = record.attendance_type || 'present';
                document.getElementById('edit_status').value = record.status || '';
                document.getElementById('edit_overtime_hours').value = record.overtime_hours || '';
                document.getElementById('edit_is_overtime').value = record.is_overtime || '0';
                document.getElementById('edit_notes').value = record.notes || '';
                
                // Set leave status
                const isOnLeave = record.is_on_leave == 1;
                document.getElementById('edit_is_on_leave').checked = isOnLeave;
                if (isOnLeave) {
                    document.getElementById('leaveBalanceInfo').style.display = 'block';
                    fetchLeaveBalance();
                    
                    // Clear all time inputs when employee is on leave
                    document.getElementById('edit_am_in').value = '';
                    document.getElementById('edit_am_out').value = '';
                    document.getElementById('edit_pm_in').value = '';
                    document.getElementById('edit_pm_out').value = '';
                    document.getElementById('edit_time_in').value = '';
                    document.getElementById('edit_time_out').value = '';
                    
                    // Set status to on_leave
                    document.getElementById('edit_status').value = 'on_leave';
                    
                    // Set attendance type to absent
                    document.getElementById('edit_attendance_type').value = 'absent';
                } else {
                    document.getElementById('leaveBalanceInfo').style.display = 'none';
                }
                
                // Check for half day based on sessions
                const hasMorningPair = (record.time_in_morning && record.time_out_morning) && (!record.time_in_afternoon && !record.time_out_afternoon);
                const hasAfternoonPair = (record.time_in_afternoon && record.time_out_afternoon) && (!record.time_in_morning && !record.time_out_morning);
        const halfDayChecked = (record.status === 'half_day') || hasMorningPair || hasAfternoonPair;
        document.getElementById('edit_half_day').checked = halfDayChecked;
        
        // Apply half day state: disable the non-existent session only
        const pmInEl = document.getElementById('edit_pm_in');
        const pmOutEl = document.getElementById('edit_pm_out');
        const amInEl = document.getElementById('edit_am_in');
        const amOutEl = document.getElementById('edit_am_out');

        // Reset
        [pmInEl, pmOutEl, amInEl, amOutEl].forEach(el => { el.disabled = false; el.style.backgroundColor = ''; });

        if (halfDayChecked) {
            if (hasAfternoonPair) {
                // Afternoon-only half day → disable morning fields
                amInEl.value = '';
                amOutEl.value = '';
                amInEl.disabled = true;
                amOutEl.disabled = true;
                amInEl.style.backgroundColor = '#f5f5f5';
                amOutEl.style.backgroundColor = '#f5f5f5';
            } else if (hasMorningPair) {
                // Morning-only half day → disable afternoon fields
                pmInEl.value = '';
                pmOutEl.value = '';
                pmInEl.disabled = true;
                pmOutEl.disabled = true;
                pmInEl.style.backgroundColor = '#f5f5f5';
                pmOutEl.style.backgroundColor = '#f5f5f5';
            }
        }
            }
        })
        .catch(() => {
            console.error('Failed to fetch record data');
        });
    }
    function closeEditAttendanceModal() {
        const modal = document.getElementById('editAttendanceModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    // Auto-calculate overall time based on morning/afternoon sessions
    function updateOverallTime() {
        const amIn = document.getElementById('edit_am_in').value;
        const amOut = document.getElementById('edit_am_out').value;
        const pmIn = document.getElementById('edit_pm_in').value;
        const pmOut = document.getElementById('edit_pm_out').value;
        
        // Calculate overall time in (earliest time)
        let overallIn = '';
        if (amIn && pmIn) {
            overallIn = amIn < pmIn ? amIn : pmIn;
        } else if (amIn) {
            overallIn = amIn;
        } else if (pmIn) {
            overallIn = pmIn;
        }
        
        // Calculate overall time out (latest time)
        let overallOut = '';
        if (amOut && pmOut) {
            overallOut = amOut > pmOut ? amOut : pmOut;
        } else if (amOut) {
            overallOut = amOut;
        } else if (pmOut) {
            overallOut = pmOut;
        }
        
        document.getElementById('edit_time_in').value = overallIn;
        document.getElementById('edit_time_out').value = overallOut;
        
        // Auto-check half day if no afternoon session
        const halfDayCheckbox = document.getElementById('edit_half_day');
        const hasAfternoon = pmIn || pmOut;
        if (!hasAfternoon && (amIn || amOut)) {
            halfDayCheckbox.checked = true;
        }
    }
    
    // Add event listeners for auto-calculation
    document.addEventListener('DOMContentLoaded', function() {
        const timeInputs = ['edit_am_in', 'edit_am_out', 'edit_pm_in', 'edit_pm_out'];
        timeInputs.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('change', updateOverallTime);
                element.addEventListener('input', updateOverallTime);
            }
        });
    });

    // Half day toggle functionality
    function toggleHalfDay() {
        const halfDayCheckbox = document.getElementById('edit_half_day');
        const pmInInput = document.getElementById('edit_pm_in');
        const pmOutInput = document.getElementById('edit_pm_out');
        const amInInput = document.getElementById('edit_am_in');
        const amOutInput = document.getElementById('edit_am_out');
        
        // If user checks half day manually, choose which session to keep based on existing inputs
        if (halfDayCheckbox.checked) {
            const hasAfternoonPair = pmInInput.value && pmOutInput.value;
            const hasMorningPair = amInInput.value && amOutInput.value;

            // Default preference: if only PM pair exists, keep PM; if only AM, keep AM
            if (hasAfternoonPair && !hasMorningPair) {
                amInInput.value = '';
                amOutInput.value = '';
                amInInput.disabled = true;
                amOutInput.disabled = true;
                amInInput.style.backgroundColor = '#f5f5f5';
                amOutInput.style.backgroundColor = '#f5f5f5';
                pmInInput.disabled = false;
                pmOutInput.disabled = false;
                pmInInput.style.backgroundColor = '';
                pmOutInput.style.backgroundColor = '';
            } else {
                // Default to morning half day
                pmInInput.value = '';
                pmOutInput.value = '';
                pmInInput.disabled = true;
                pmOutInput.disabled = true;
                pmInInput.style.backgroundColor = '#f5f5f5';
                pmOutInput.style.backgroundColor = '#f5f5f5';
                amInInput.disabled = false;
                amOutInput.disabled = false;
                amInInput.style.backgroundColor = '';
                amOutInput.style.backgroundColor = '';
            }
        } else {
            // Re-enable all when unchecked
            [pmInInput, pmOutInput, amInInput, amOutInput].forEach(el => { el.disabled = false; el.style.backgroundColor = ''; });
        }
        
        // Update overall time calculation
        updateOverallTime();
    }

    function submitEditAttendance(event) {
        event.preventDefault();
        const form = document.getElementById('editAttendanceForm');
        const formData = new FormData(form);
        
        // Handle leave status - set total_hours to 0 and status to on_leave when on leave
        const isOnLeave = document.getElementById('edit_is_on_leave').checked;
        if (isOnLeave) {
            formData.set('total_hours', '0');
            formData.set('status', 'on_leave');
            // Ensure time inputs are null
            formData.set('time_in', '');
            formData.set('time_out', '');
            formData.set('am_in', '');
            formData.set('am_out', '');
            formData.set('pm_in', '');
            formData.set('pm_out', '');
        }
        
        // Handle half day logic
        const halfDay = document.getElementById('edit_half_day').checked;
        if (halfDay) {
            formData.set('half_day', '1');
            // If half day, clear afternoon times
            formData.set('pm_in', '');
            formData.set('pm_out', '');
        } else {
            formData.set('half_day', '0');
        }
        
        // Ensure employee_id and attendance_date are included for new records
        const employeeId = document.getElementById('edit_employee_id').value;
        const attendanceDate = document.getElementById('edit_attendance_date').value;
        if (employeeId) formData.set('employee_id', employeeId);
        if (attendanceDate) formData.set('attendance_date', attendanceDate);
        
        // Show loading state
        const submitBtn = document.querySelector('#editAttendanceModal .btn-primary');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        
        // Determine which endpoint to use based on record ID
        const recordId = document.getElementById('edit_id').value;
        const endpoint = (recordId === '0') ? 'create_attendance.php' : 'update_attendance.php';
        
        fetch(endpoint, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const action = (recordId === '0') ? 'created' : 'updated';
                    alert(`Attendance ${action} successfully.`);
                    closeEditAttendanceModal();
                    // Preserve current URL parameters (filters) when reloading
                    window.location.href = window.location.href.split('?')[0] + (window.location.search || '');
                } else {
                    alert(data.message || `Failed to ${recordId === '0' ? 'create' : 'update'} attendance.`);
                }
            })
            .catch(() => alert('Request failed.'))
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        return false;
    }
    function markTimeOut(id, btnEl) {
        if (btnEl) { 
            btnEl.disabled = true; 
            btnEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; 
            btnEl.title = 'Processing...';
        }
        
        fetch('mark_timeout.php', { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: `id=${encodeURIComponent(id)}` 
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const message = data.message || `Time out marked successfully at ${data.time_out}`;
                    const segment = data.segment ? ` (${data.segment} session)` : '';
                    alert(message + segment);
                    // Preserve current URL parameters (filters) when reloading
                    window.location.href = window.location.href.split('?')[0] + (window.location.search || '');
                } else {
                    alert(data.message || 'Failed to mark time out.');
                }
            })
            .catch(() => alert('Request failed.'))
            .finally(() => { 
                if (btnEl) { 
                    btnEl.disabled = false; 
                    btnEl.innerHTML = '<i class="fas fa-sign-out-alt"></i>'; 
                    btnEl.title = 'Mark Time Out';
                } 
            });
    }

    // Overtime recalculation function
    function recalculateOvertime() {
        if (!confirm('This will recalculate overtime for all present records in the current filter. Continue?')) {
            return;
        }
        
        // Get current filter dates
        const urlParams = new URLSearchParams(window.location.search);
        const activeFilter = '<?php echo $active_filter; ?>';
        let startDate, endDate;
        
        if (activeFilter === 'month' && urlParams.get('month')) {
            const month = urlParams.get('month');
            startDate = month + '-01';
            endDate = month + '-' + new Date(month + '-01').toLocaleDateString('en-CA', {day: '2-digit'});
        } else if (activeFilter === 'year' && urlParams.get('year')) {
            const year = urlParams.get('year');
            startDate = year + '-01-01';
            endDate = year + '-12-31';
        } else if (activeFilter === 'date' && urlParams.get('date')) {
            startDate = endDate = urlParams.get('date');
        } else {
            // Default to current month
            const now = new Date();
            startDate = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-01';
            endDate = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                     new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
        }
        
        const formData = new FormData();
        formData.append('start_date', startDate);
        formData.append('end_date', endDate);
        
        // Show loading state
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recalculating...';
        
        fetch('recalculate_overtime.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const summary = data.summary;
                alert(`Overtime recalculation completed!\n\n` +
                      `Total records processed: ${summary.total_records}\n` +
                      `Records updated: ${summary.updated_records}\n` +
                      `Overtime corrections: ${summary.overtime_corrected}\n` +
                      `Overtime added: ${summary.overtime_added}\n` +
                      `Overtime removed: ${summary.overtime_removed}`);
                // Preserve current URL parameters (filters) when reloading
                window.location.href = window.location.href.split('?')[0] + (window.location.search || '');
            } else {
                alert('Error: ' + (data.message || 'Failed to recalculate overtime'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Request failed. Please try again.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    }

    // ----- Export Handling -----
    function handleHistoryExport() {
        const params = new URLSearchParams(window.location.search);
        const activeFilter = getActiveFilter();
        if (activeFilter === 'year' && (params.get('year') || document.getElementById('year')?.value)) {
            // Open month range modal
            document.getElementById('yearExportModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            // Pre-fill year months
            const y = params.get('year') || document.getElementById('year').value;
            // Optionally preselect 1..12
            document.getElementById('startMonth').value = '1';
            document.getElementById('endMonth').value = '12';
        } else if (activeFilter === 'date') {
            // If user is on date mode, allow picking a date range
            const modal = document.getElementById('dateExportModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
            // Prefill with current selection if any
            const selectedDate = params.get('date') || document.getElementById('date')?.value || '';
            if (selectedDate) {
                document.getElementById('startDateExport').value = selectedDate;
                document.getElementById('endDateExport').value = selectedDate;
            }
        } else {
            // Build direct export based on current filter (date or month or none)
            triggerHistoryExport();
        }
    }

    function closeYearExportModal() {
        const modal = document.getElementById('yearExportModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }

    function confirmYearExport() {
        const params = new URLSearchParams(window.location.search);
        const year = params.get('year') || document.getElementById('year')?.value;
        const sm = parseInt(document.getElementById('startMonth').value, 10);
        const em = parseInt(document.getElementById('endMonth').value, 10);
        if (!year) { alert('Please select a year first.'); return; }
        if (isNaN(sm) || isNaN(em) || sm < 1 || em > 12 || sm > em) {
            alert('Invalid month range.');
            return;
        }
        // Build year-month range params
        const exportParams = new URLSearchParams();
        exportParams.set('mode', 'year');
        exportParams.set('year', year);
        exportParams.set('start_month', String(sm).padStart(2,'0'));
        exportParams.set('end_month', String(em).padStart(2,'0'));
        appendCommonHistoryFilters(exportParams);
        window.open('export_history.php?' + exportParams.toString(), '_blank');
        closeYearExportModal();
    }

    function triggerHistoryExport() {
        const params = new URLSearchParams(window.location.search);
        const activeFilter = getActiveFilter();
        const exportParams = new URLSearchParams();
        if (activeFilter === 'date' && (params.get('date') || document.getElementById('date')?.value)) {
            // If coming here without modal, export that single date
            exportParams.set('mode', 'daterange');
            const d = params.get('date') || document.getElementById('date').value;
            exportParams.set('start_date', d);
            exportParams.set('end_date', d);
        } else if (activeFilter === 'month' && (params.get('month') || document.getElementById('month')?.value)) {
            exportParams.set('mode', 'month');
            exportParams.set('month', params.get('month') || document.getElementById('month').value);
        } else if (activeFilter === 'year' && (params.get('year') || document.getElementById('year')?.value)) {
            // If year but modal is not used, export full year
            exportParams.set('mode', 'year');
            exportParams.set('year', params.get('year') || document.getElementById('year').value);
            exportParams.set('start_month', '01');
            exportParams.set('end_month', '12');
        } else {
            // Default: export current table context without date constraint
            exportParams.set('mode', 'all');
        }
        appendCommonHistoryFilters(exportParams);
        window.open('export_history.php?' + exportParams.toString(), '_blank');
    }

    function appendCommonHistoryFilters(searchParams) {
        const params = new URLSearchParams(window.location.search);
        const department = params.get('department') || document.getElementById('department')?.value || '';
        const shift = params.get('shift') || document.getElementById('shift')?.value || '';
        const status = params.get('status') || document.getElementById('status')?.value || '';
        const search = params.get('search') || document.getElementById('searchInput')?.value || '';
        if (department) searchParams.set('department', department);
        if (shift) searchParams.set('shift', shift);
        if (status) searchParams.set('status', status);
        if (search) searchParams.set('search', search);
    }

    function closeDateExportModal() {
        const modal = document.getElementById('dateExportModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }

    function confirmDateExport() {
        const s = document.getElementById('startDateExport').value;
        const e = document.getElementById('endDateExport').value;
        if (!s || !e) { alert('Please select both From and To dates.'); return; }
        if (new Date(s) > new Date(e)) { alert('From Date cannot be after To Date.'); return; }
        const exportParams = new URLSearchParams();
        exportParams.set('mode', 'daterange');
        exportParams.set('start_date', s);
        exportParams.set('end_date', e);
        appendCommonHistoryFilters(exportParams);
        window.open('export_history.php?' + exportParams.toString(), '_blank');
        closeDateExportModal();
    }
    // AJAX Search Functions
    let searchTimeout;
    
    function performAjaxSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            const searchTerm = document.getElementById('searchInput').value;
            const activeFilter = getActiveFilter();
            const department = document.getElementById('department').value;
            const shift = document.getElementById('shift').value;
            const status = document.getElementById('status').value;
            const attendanceType = document.getElementById('attendance_type') ? document.getElementById('attendance_type').value : '';
            const showOvertime = document.getElementById('show_overtime') && document.getElementById('show_overtime').checked ? '1' : '';
            
            // Show loading indicator
            document.getElementById('loadingIndicator').style.display = 'block';
            document.getElementById('attendanceTable').style.display = 'none';
            
            // Build URL parameters
            const params = new URLSearchParams();
            if (searchTerm) params.append('search', searchTerm);
            if (activeFilter === 'date' && document.getElementById('date').value) {
                params.append('date', document.getElementById('date').value);
            } else if (activeFilter === 'month' && document.getElementById('month').value) {
                params.append('month', document.getElementById('month').value);
            } else if (activeFilter === 'year' && document.getElementById('year').value) {
                params.append('year', document.getElementById('year').value);
            }
            if (department) params.append('department', department);
            if (shift) params.append('shift', shift);
            if (status) params.append('status', status);
            if (attendanceType) params.append('attendance_type', attendanceType);
            if (showOvertime) params.append('show_overtime', showOvertime);
            
            // Make AJAX request
            fetch('ajax_search_attendance.php?' + params.toString())
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateAttendanceTable(data.records, true); // Update summary cards when filtering
                    } else {
                        showError('Search failed: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    showError('Search failed. Please try again.');
                })
                .finally(() => {
                    document.getElementById('loadingIndicator').style.display = 'none';
                    document.getElementById('attendanceTable').style.display = 'table';
                });
        }, 300); // 300ms delay for debouncing
    }
    
    function getActiveFilter() {
        const activeBtn = document.querySelector('.date-filter-btn.active');
        return activeBtn ? activeBtn.getAttribute('data-filter') : 'date';
    }
    
    function getSourceIcon(source) {
        switch(source) {
            case 'manual':
                return '<i class="fas fa-edit text-primary" title="Manual Entry (Web App)"></i>';
            case 'bulk_edit':
                return '<i class="fas fa-users-cog text-warning" title="Bulk Edit (Web App)"></i>';
            case 'biometric':
            default:
                return '<i class="fas fa-fingerprint text-success" title="Biometric Scanner (ZKteco Device)"></i>';
        }
    }
    
    function getSourceText(source) {
        switch(source) {
            case 'manual':
                return 'Manual Entry (Web App)';
            case 'bulk_edit':
                return 'Bulk Edit (Web App)';
            case 'biometric':
            default:
                return 'Biometric Scanner (ZKteco Device)';
        }
    }
    
    function formatShiftForDisplay(shift) {
        if (!shift || shift === '-') return 'No shift assigned';
        try {
            const parts = shift.split('-');
            if (parts.length === 2) {
                const start = new Date('1970-01-01T' + parts[0].trim()).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true});
                const end = new Date('1970-01-01T' + parts[1].trim()).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true});
                return `${start} - ${end}`;
            }
            return shift;
        } catch (e) {
            return shift;
        }
    }
    
    function setDefaultTimesBasedOnShift(shift) {
        if (!shift || shift === '-') {
            // Default 8-5 shift if no shift assigned
            shift = '08:00-17:00';
        }
        
        try {
            const parts = shift.split('-');
            if (parts.length === 2) {
                const startTime = parts[0].trim();
                const endTime = parts[1].trim();
                
                // Parse start and end times
                const startHour = parseInt(startTime.split(':')[0]);
                const endHour = parseInt(endTime.split(':')[0]);
                
                // Calculate lunch break based on shift pattern
                let lunchStart, lunchEnd;
                
                if (startHour <= 8) {
                    // Early shift (6-7 AM start): lunch at 11:30-12:30
                    lunchStart = new Date('1970-01-01T11:30:00');
                    lunchEnd = new Date('1970-01-01T12:30:00');
                } else if (startHour >= 9) {
                    // Late shift (9+ AM start): lunch at 1:00-2:00
                    lunchStart = new Date('1970-01-01T13:00:00');
                    lunchEnd = new Date('1970-01-01T14:00:00');
                } else {
                    // Standard shift (8 AM start): lunch at 12:00-1:00
                    lunchStart = new Date('1970-01-01T12:00:00');
                    lunchEnd = new Date('1970-01-01T13:00:00');
                }
                
                // Set morning session times
                const morningIn = new Date('1970-01-01T' + startTime + ':00');
                const morningOut = new Date(lunchStart);
                
                // Set afternoon session times  
                const afternoonIn = new Date(lunchEnd);
                const afternoonOut = new Date('1970-01-01T' + endTime + ':00');
                
                // Format times for input fields (HH:MM)
                const formatTime = (date) => {
                    return date.toTimeString().substring(0, 5);
                };
                
                // Set default values
                document.getElementById('edit_am_in').value = formatTime(morningIn);
                document.getElementById('edit_am_out').value = formatTime(morningOut);
                document.getElementById('edit_pm_in').value = formatTime(afternoonIn);
                document.getElementById('edit_pm_out').value = formatTime(afternoonOut);
                
                // Update overall time calculation
                updateOverallTime();
            }
        } catch (e) {
            console.error('Error setting default times:', e);
            // Fallback to 8-5 defaults
            document.getElementById('edit_am_in').value = '08:00';
            document.getElementById('edit_am_out').value = '12:00';
            document.getElementById('edit_pm_in').value = '13:00';
            document.getElementById('edit_pm_out').value = '17:00';
            updateOverallTime();
        }
    }
    
    function updateAttendanceTable(records, updateSummary = true) {
        const tbody = document.getElementById('attendanceTableBody');
        tbody.innerHTML = '';
        
        if (records.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="10" style="text-align: center; padding: 40px;">
                        <i class="fas fa-search" style="font-size: 48px; color: #97BC62; margin-bottom: 15px;"></i>
                        <br>
                        <strong>No records found</strong>
                        <br>
                        <small style="color: #6c757d;">Try adjusting your search criteria or filters</small>
                    </td>
                </tr>
            `;
            // Re-initialize pagination after table update
            initializePagination();
            if (updateSummary) {
                updateSummaryCards(records);
            }
            return;
        }
        
        records.forEach(record => {
            const row = createAttendanceRow(record);
            tbody.appendChild(row);
        });
        
        // Re-initialize pagination after table update
        initializePagination();
        
        // Update summary cards only if requested (e.g., from filter operations)
        if (updateSummary) {
            updateSummaryCards(records);
        }
    }
    function createAttendanceRow(record) {
        const calcOverallHours = (inStr, outStr) => {
            if (!inStr || !outStr) return 0;
            // Normalize to HH:MM:SS
            const norm = t => (t.length === 5 ? t + ':00' : t);
            const start = new Date('1970-01-01T' + norm(inStr));
            const end = new Date('1970-01-01T' + norm(outStr));
            const ms = end - start;
            if (isNaN(ms) || ms <= 0) return 0;
            return Math.round((ms / 3600000) * 100) / 100; // two decimals
        };
        const row = document.createElement('tr');
        row.setAttribute('data-id', record.id);
        row.setAttribute('data-employee_id', record.EmployeeID);
        row.setAttribute('data-employee_name', record.EmployeeName);
        row.setAttribute('data-department', record.Department || '');
        row.setAttribute('data-shift', record.Shift || '');
        row.setAttribute('data-date', record.attendance_date);
        row.setAttribute('data-time_in', record.time_in || '');
        row.setAttribute('data-time_out', record.time_out || '');
        row.setAttribute('data-is_on_leave', record.is_on_leave || '0');
        row.setAttribute('data-source', record.data_source || 'biometric');
        
        const attendanceType = record.attendance_type === 'present' ? 
            '<span class="status-badge status-present">Present</span>' : 
            (record.id == 0 ? '<span class="status-badge status-absent-no-record">Absent</span>' : '<span class="status-badge status-absent">Absent</span>');
        
        let statusBadge = '';
        if (record.is_on_leave == 1) {
            statusBadge = '<span class="status-badge status-on-leave">ON-LEAVE</span>';
        } else if (record.id == 0 || record.status === 'no_record') {
            statusBadge = '<span class="status-badge status-normal">No Record</span>';
        } else if (record.late_minutes && Number(record.late_minutes) > 0) {
            statusBadge = '<span class="status-badge status-late">Late</span>';
        } else if (record.status === 'early') {
            statusBadge = '<span class="status-badge status-early">Early</span>';
        } else if (record.status === 'on_time') {
            statusBadge = '<span class="status-badge status-normal">On-time</span>';
        } else if (record.status === 'early_in') {
            statusBadge = '<span class="status-badge status-normal">Early In</span>';
        } else if (record.status === 'halfday' || record.status === 'half_day') {
            statusBadge = '<span class="status-badge status-half-day">Half Day</span>';
        } else {
            statusBadge = '<span class="status-badge status-normal">On-time</span>';
        }
        
        const actionButtons = record.id == 0 ? 
            `<button type="button" class="action-btn edit-btn" onclick="openEditAttendance(this, '${record.id}')" title="Create Record"><i class="fas fa-plus"></i></button>` :
            (record.time_out ? 
                `<button type="button" class="action-btn edit-btn" onclick="openEditAttendance(this, '${record.id}')" title="Edit"><i class="fas fa-edit"></i></button>` :
                `<button type="button" class="action-btn approve-btn" onclick="markTimeOut('${record.id}', this)" title="Mark Time Out"><i class="fas fa-sign-out-alt"></i></button>`
            );
        
        // Don't show total hours if employee is on leave
        const totalHours = (record.is_on_leave == 1)
            ? '-'
            : ((record.total_hours && record.total_hours > 0)
                ? parseFloat(record.total_hours).toFixed(2)
                : (() => {
                    const h = calcOverallHours(record.time_in, record.time_out);
                    return h > 0 ? h.toFixed(2) : '-';
                  })());

        row.innerHTML = `
            <td>${formatDate(record.attendance_date)}</td>
            <td>${record.EmployeeName}</td>
            <td>${record.EmployeeID}</td>
            <td>${attendanceType}</td>
            <td>${statusBadge}</td>
            <td>${(record.status === 'half_day' || record.status === 'halfday') ? '-' : (record.late_minutes > 0 ? record.late_minutes : '-')}</td>
            <td>${(record.status === 'half_day' || record.status === 'halfday') ? '-' : (record.early_out_minutes > 0 ? record.early_out_minutes : '-')}</td>
            <td>${(record.overtime_hours && parseFloat(record.overtime_hours) > 0) ? parseFloat(record.overtime_hours).toFixed(2) : '-'}</td>
            <td>${totalHours}</td>
            <td style="text-align: center;">
                ${getSourceIcon(record.data_source || 'biometric')}
            </td>
            <td>
                <div class="action-buttons">
                    <button type="button" class="action-btn view-btn" onclick="openHrViewModal('${record.EmployeeName}','${record.EmployeeID}','${record.attendance_date}','${record.time_in || ''}','${record.time_out || ''}','${record.time_in_morning || ''}','${record.time_out_morning || ''}','${record.time_in_afternoon || ''}','${record.time_out_afternoon || ''}','${record.status || ''}','${record.late_minutes || 0}','${record.early_out_minutes || 0}','${record.overtime_hours || 0}','${record.is_on_leave || 0}')" title="View"><i class="fas fa-eye"></i></button>
                    ${actionButtons}
                </div>
            </td>
        `;
        
        return row;
    }
    
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: '2-digit', 
            year: 'numeric' 
        });
    }
    
    function updateSummaryCards(records) {
        // Calculate summary statistics based on filtered data
        const totalRecords = records.length;
        
        // Present Records: attendance_type 'present' OR status in present-like set
        const presentCount = records.filter(r => {
            const att = (r.attendance_type || '').toLowerCase();
            const norm = (s) => (s || '').toLowerCase().replace(/\s+/g, '_');
            const st = norm(r.status);
            return att === 'present' || ['late','halfday','half_day','on_time','on-time','early_in','early-in'].includes(st);
        }).length;
        
        const absentCount = records.filter(r => r.attendance_type === 'absent').length;
        const lateCount = records.filter(r => Number(r.late_minutes || 0) > 0).length;
        const overtimeCount = records.filter(r => r.overtime_hours && r.overtime_hours > 0).length;
        
        // Update summary cards with proper IDs
        const totalElement = document.getElementById('summary-total');
        if (totalElement) {
            totalElement.textContent = totalRecords;
        }
        
        const presentElement = document.getElementById('summary-present');
        if (presentElement) {
            presentElement.textContent = presentCount;
        }
        
        const absentElement = document.getElementById('summary-absent');
        if (absentElement) {
            absentElement.textContent = absentCount;
        }
        
        const lateElement = document.getElementById('summary-late');
        if (lateElement) {
            lateElement.textContent = lateCount;
        }
        
        const overtimeElement = document.getElementById('summary-overtime');
        if (overtimeElement) {
            overtimeElement.textContent = overtimeCount;
        }
    }

    function updateSummaryCardsFromTable() {
        const tbody = document.querySelector('#attendanceTableBody');
        if (!tbody) return;
        
        // Get all table rows (excluding "no records" placeholder)
        const rows = Array.from(tbody.querySelectorAll('tr[data-employee_id]'));
        
        // Extract data from table rows
        const records = rows.map(row => {
            const statusCell = row.querySelector('td:nth-child(9)'); // Status column
            const attendanceTypeCell = row.querySelector('td:nth-child(8)'); // Attendance Type column
            const overtimeCell = row.querySelector('td:nth-child(10)'); // Overtime column
            
            return {
                status: statusCell ? statusCell.textContent.trim().toLowerCase() : '',
                attendance_type: attendanceTypeCell ? attendanceTypeCell.textContent.trim().toLowerCase() : '',
                overtime_hours: overtimeCell ? parseFloat(overtimeCell.textContent.trim()) || 0 : 0
            };
        });
        
        // Update summary cards with extracted data
        updateSummaryCards(records);
    }
    
    
    function showError(message) {
        // Create error notification
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-error';
        errorDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${message}`;
        errorDiv.style.position = 'fixed';
        errorDiv.style.top = '20px';
        errorDiv.style.right = '20px';
        errorDiv.style.zIndex = '9999';
        
        document.body.appendChild(errorDiv);
        
        // Remove after 5 seconds
        setTimeout(() => {
            if (errorDiv.parentNode) {
                errorDiv.parentNode.removeChild(errorDiv);
            }
        }, 5000);
    }

    // Toast notification utility
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        const colors = {
            info: {bg: '#E8F4FF', fg: '#0B5ED7', border: '#B6DAFF'},
            success: {bg: '#E6FFF3', fg: '#0F9D58', border: '#A6F3D1'},
            warning: {bg: '#FFF8E1', fg: '#B7791F', border: '#FAD783'},
            error: {bg: '#FEE2E2', fg: '#B91C1C', border: '#FCA5A5'}
        }[type] || {bg: '#E8F4FF', fg: '#0B5ED7', border: '#B6DAFF'};
        toast.textContent = message;
        toast.style.position = 'fixed';
        toast.style.top = '20px';
        toast.style.right = '20px';
        toast.style.padding = '12px 16px';
        toast.style.borderRadius = '10px';
        toast.style.background = colors.bg;
        toast.style.color = colors.fg;
        toast.style.border = '1px solid ' + colors.border;
        toast.style.boxShadow = '0 6px 16px rgba(0,0,0,0.12)';
        toast.style.zIndex = '9999';
        document.body.appendChild(toast);
        setTimeout(() => { toast.remove(); }, 3500);
    }
    
        // Add event listeners for filter changes
        document.addEventListener('DOMContentLoaded', function() {
        // Helper to submit the GET form while preserving active_filter
        function submitFilterFormPreserveMode() {
            const form = document.querySelector('form[method="GET"]');
            if (!form) return;
            // ensure active_filter hidden is set based on active button
            const activeBtn = document.querySelector('.date-filter-btn.active');
            const mode = activeBtn ? activeBtn.getAttribute('data-filter') : 'date';
            const existingInput = form.querySelector('input[name="active_filter"]');
            if (existingInput) { existingInput.remove(); }
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'active_filter';
            hiddenInput.value = mode;
            form.appendChild(hiddenInput);
            // Clear non-active date inputs to avoid stale values
            const dateInput = document.getElementById('date');
            const monthInput = document.getElementById('month');
            const yearInput = document.getElementById('year');
            if (mode !== 'date' && dateInput) dateInput.value = '';
            if (mode !== 'month' && monthInput) monthInput.value = '';
            if (mode !== 'year' && yearInput) yearInput.value = '';
            
            // Client-side pagination will be reset automatically when filters change
            
            form.submit();
        }

        // Add change listeners to filter inputs → set active mode and reload for consistent server-side calc
        const setActiveMode = (mode) => {
            const buttons = document.querySelectorAll('.date-filter-btn');
            buttons.forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-filter') === mode));
        };

        // Handle department filter change
        function handleDepartmentChange() {
            const departmentSelect = document.getElementById('department');
            if (departmentSelect) {
                // Clear other filters that might conflict
                const statusSelect = document.getElementById('status');
                const shiftSelect = document.getElementById('shift');
                const searchInput = document.getElementById('searchInput');
                
                if (statusSelect) statusSelect.value = '';
                if (shiftSelect) shiftSelect.value = '';
                if (searchInput) searchInput.value = '';
                
                // Submit the form to apply the department filter
                submitFilterFormPreserveMode();
            }
        }

        const filterInputs = ['date', 'month', 'year', 'department', 'shift', 'status', 'attendance_type', 'show_overtime'];
        filterInputs.forEach(inputId => {
            const input = document.getElementById(inputId);
            if (!input) return;
            input.addEventListener('change', () => {
                if (inputId === 'date') setActiveMode('date');
                if (inputId === 'month') setActiveMode('month');
                if (inputId === 'year') setActiveMode('year');
                performAjaxSearch();
            });
        });

        // Also update hidden active_filter when user clicks filter mode buttons before submitting
        document.querySelectorAll('.date-filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                setActiveMode(btn.getAttribute('data-filter'));
                performAjaxSearch();
            });
        });

        // Reinitialize pagination after page load (for filter changes)
        window.addEventListener('load', function() {
            setTimeout(initializePagination, 100);
        });
    });

    // ===== BULK EDIT FUNCTIONALITY =====
    let bulkEditData = {
        currentStep: 1,
        selectedEmployees: [],
        selectedShift: null,
        allEmployees: []
    };

    function openBulkEditModal() {
        const modal = document.getElementById('bulkEditModal');
        modal.style.display = 'flex';
        modal.classList.add('show');
        
        // Reset bulk edit data
        bulkEditData = {
            currentStep: 1,
            selectedEmployees: [],
            selectedShift: null,
            allEmployees: []
        };
        
        // Load employees
        loadBulkEmployees();
        showBulkStep(1);
    }

    function closeBulkEditModal() {
        const modal = document.getElementById('bulkEditModal');
        modal.style.display = 'none';
        modal.classList.remove('show');
        
        // Reset form
        document.getElementById('bulkEditForm').reset();
        
        // Reset half-day inputs
        const markHalfDayEl = document.getElementById('bulk_mark_half_day');
        if (markHalfDayEl) {
            markHalfDayEl.checked = false;
        }
        
        // Reset all time inputs to enabled state
        const allTimeInputs = ['bulk_am_in', 'bulk_am_out', 'bulk_pm_in', 'bulk_pm_out'];
        allTimeInputs.forEach(inputId => {
            const input = document.getElementById(inputId);
            if (input) {
                input.disabled = false;
                input.style.opacity = '1';
                input.style.cursor = 'text';
                input.style.background = 'white';
            }
        });
        
        // Reset section opacity
        const morningSection = document.getElementById('bulk-morning-section');
        const afternoonSection = document.getElementById('bulk-afternoon-section');
        if (morningSection) {
            morningSection.style.opacity = '1';
            morningSection.style.pointerEvents = 'auto';
        }
        if (afternoonSection) {
            afternoonSection.style.opacity = '1';
            afternoonSection.style.pointerEvents = 'auto';
        }
        
        // Hide warning popup if visible
        const warningPopup = document.getElementById('shift-warning-popup');
        if (warningPopup) {
            warningPopup.style.display = 'none';
        }
        
        bulkEditData = {
            currentStep: 1,
            selectedEmployees: [],
            selectedShift: null,
            allEmployees: []
        };
    }

    function loadBulkEmployees() {
        // Get current page filters
        const currentParams = new URLSearchParams(window.location.search);
        const department = currentParams.get('department') || '';
        const shift = currentParams.get('shift') || '';
        const status = currentParams.get('status') || '';
        const attendance_type = currentParams.get('attendance_type') || '';
        const search = currentParams.get('search') || '';
        const late_only = currentParams.get('late_only') || '';
        const date = currentParams.get('date') || '';
        const month = currentParams.get('month') || '';
        const year = currentParams.get('year') || '';
        
        // Build fetch URL with current filters
        let fetchUrl = 'fetch_attendance_for_bulk_edit.php?';
        if (department) fetchUrl += `&department=${encodeURIComponent(department)}`;
        if (shift) fetchUrl += `&shift=${encodeURIComponent(shift)}`;
        if (status) fetchUrl += `&status=${encodeURIComponent(status)}`;
        if (attendance_type) fetchUrl += `&attendance_type=${encodeURIComponent(attendance_type)}`;
        if (search) fetchUrl += `&search=${encodeURIComponent(search)}`;
        if (late_only) fetchUrl += `&late_only=${encodeURIComponent(late_only)}`;
        
        // Check modal date filters first, then fall back to page filters
        const modalDateFilter = document.getElementById('bulk-date-filter')?.value || '';
        const modalMonthFilter = document.getElementById('bulk-month-filter')?.value || '';
        const modalYearFilter = document.getElementById('bulk-year-filter')?.value || '';
        
        if (modalDateFilter) {
            fetchUrl += `&date=${encodeURIComponent(modalDateFilter)}`;
        } else if (modalMonthFilter) {
            fetchUrl += `&month=${encodeURIComponent(modalMonthFilter)}`;
        } else if (modalYearFilter) {
            fetchUrl += `&year=${encodeURIComponent(modalYearFilter)}`;
        } else if (date) {
            fetchUrl += `&date=${encodeURIComponent(date)}`;
        } else if (month) {
            fetchUrl += `&month=${encodeURIComponent(month)}`;
        } else if (year) {
            fetchUrl += `&year=${encodeURIComponent(year)}`;
        } else {
            // Default to current month if no date filter is set
            const today = new Date();
            const currentMonth = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
            fetchUrl += `&month=${currentMonth}`;
        }

        // Show loading state
        const container = document.getElementById('bulk-employee-list');
        container.innerHTML = '<div class="loading-state"><i class="fas fa-spinner fa-spin"></i> Loading employees...</div>';

        // Fetch all employees with attendance records based on current filters
        fetch(fetchUrl)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    bulkEditData.allEmployees = data.employees.map(emp => ({
                        id: String(emp.EmployeeID),
                        name: emp.EmployeeName || '',
                        department: emp.Department || '',
                        shift: emp.Shift || '',
                        date: emp.attendance_date || '',
                        attendanceType: emp.attendance_type || 'absent',
                        timeIn: emp.time_in || '',
                        timeOut: emp.time_out || '',
                        timeInMorning: emp.time_in_morning || '',
                        timeOutMorning: emp.time_out_morning || '',
                        timeInAfternoon: emp.time_in_afternoon || '',
                        timeOutAfternoon: emp.time_out_afternoon || '',
                        lateMinutes: emp.late_minutes || 0,
                        earlyOutMinutes: emp.early_out_minutes || 0,
                        overtimeHours: emp.overtime_hours || 0,
                        status: emp.status || 'absent'
                    }));
                    
                    // Set the date filter inputs to match current page context if modal filters are empty
                    if (!modalDateFilter && !modalMonthFilter && !modalYearFilter) {
                        if (date) {
                            document.getElementById('bulk-date-filter').value = date;
                        } else if (month) {
                            document.getElementById('bulk-month-filter').value = month;
                        } else if (year) {
                            document.getElementById('bulk-year-filter').value = year;
                        }
                    }
                    
                    filterBulkEmployees();
                } else {
                    container.innerHTML = '<div class="error-state"><i class="fas fa-exclamation-triangle"></i> Error: ' + data.message + '</div>';
                }
            })
            .catch(error => {
                console.error('Error fetching employees:', error);
                container.innerHTML = '<div class="error-state"><i class="fas fa-exclamation-triangle"></i> Failed to load employees for bulk edit.</div>';
            });
    }

    function reloadBulkEmployees() {
        // Clear other date filters when one is selected
        const dateFilter = document.getElementById('bulk-date-filter');
        const monthFilter = document.getElementById('bulk-month-filter');
        const yearFilter = document.getElementById('bulk-year-filter');
        
        // Clear other date inputs when one is changed
        if (dateFilter.value) {
            monthFilter.value = '';
            yearFilter.value = '';
        } else if (monthFilter.value) {
            dateFilter.value = '';
            yearFilter.value = '';
        } else if (yearFilter.value) {
            dateFilter.value = '';
            monthFilter.value = '';
        }
        
        // Reload employees with new date filter
        loadBulkEmployees();
    }

    function filterBulkEmployees() {
        const departmentFilter = (document.getElementById('bulk-department-filter')?.value || '').toLowerCase();
        const shiftFilter = (document.getElementById('bulk-shift-filter')?.value || '').toLowerCase();
        const searchTerm = (document.getElementById('bulk-employee-search')?.value || '').toLowerCase();
        const typeFilter = (document.getElementById('bulk-type-filter')?.value || '').toLowerCase();

        const filtered = bulkEditData.allEmployees.filter(emp => {
            const matchesDepartment = !departmentFilter || (emp.department || '').toLowerCase().includes(departmentFilter);
            const matchesShift = !shiftFilter || (emp.shift || '').toLowerCase().includes(shiftFilter);
            const matchesSearch = !searchTerm || (emp.name || '').toLowerCase().includes(searchTerm) || (emp.id || '').toLowerCase().includes(searchTerm);
            const matchesType = !typeFilter || (emp.attendanceType || 'absent') === typeFilter;

            return matchesDepartment && matchesShift && matchesSearch && matchesType;
        });
        displayBulkEmployeeList(filtered);
    }

    function displayBulkEmployeeList(employees) {
        const container = document.getElementById('bulk-employee-list');
        container.innerHTML = '';
        
        if (employees.length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="fas fa-users-slash"></i> No employees found matching the current filters.</div>';
            updateSelectedCount();
            return;
        }
        
        employees.forEach(emp => {
            const isSelected = bulkEditData.selectedEmployees.some(selected => selected.id === emp.id);
            const present = (emp.attendanceType || '').toLowerCase() === 'present';
            
            // Get the current date filter to show the correct date
            const modalDateFilter = document.getElementById('bulk-date-filter')?.value || '';
            const modalMonthFilter = document.getElementById('bulk-month-filter')?.value || '';
            const modalYearFilter = document.getElementById('bulk-year-filter')?.value || '';
            
            let displayDate = 'N/A';
            if (modalDateFilter) {
                displayDate = new Date(modalDateFilter).toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: '2-digit', 
                    year: 'numeric' 
                });
            } else if (modalMonthFilter) {
                displayDate = new Date(modalMonthFilter + '-01').toLocaleDateString('en-US', { 
                    month: 'short', 
                    year: 'numeric' 
                });
            } else if (modalYearFilter) {
                displayDate = modalYearFilter;
            } else if (emp.date) {
                displayDate = new Date(emp.date).toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: '2-digit', 
                    year: 'numeric' 
                });
            }
            
            // Format time display
            const timeDisplay = present ? 
                `${emp.timeIn || 'N/A'} - ${emp.timeOut || 'N/A'}` : 
                'No attendance';
            
            // Status indicators
            let statusIndicators = '';
            if (present) {
                if (emp.lateMinutes > 0) statusIndicators += '<span class="status-indicator late"><i class="fas fa-clock"></i> Late</span>';
                if (emp.earlyOutMinutes > 0) statusIndicators += '<span class="status-indicator early"><i class="fas fa-arrow-down"></i> Early</span>';
                if (emp.overtimeHours > 0) statusIndicators += '<span class="status-indicator overtime"><i class="fas fa-plus"></i> OT</span>';
            }
            
            const item = document.createElement('div');
            item.className = `employee-item ${isSelected ? 'selected' : ''}`;
            item.innerHTML = `
                <div class="employee-checkbox">
                    <input type="checkbox" ${isSelected ? 'checked' : ''} 
                           onchange="toggleEmployeeSelection('${emp.id}', '${emp.name.replace(/'/g, "\\'")}', '${emp.department.replace(/'/g, "\\'")}', '${emp.shift.replace(/'/g, "\\'")}', '${emp.date.replace(/'/g, "\\'")}', '${emp.attendanceType}')">
                </div>
                <div class="employee-info">
                    <div class="employee-header">
                        <div class="employee-name">${emp.name}</div>
                        <div class="attendance-status">
                            <span class="attn-badge ${present ? 'present' : 'absent'}">${present ? 'Present' : 'Absent'}</span>
                            ${statusIndicators}
                        </div>
                    </div>
                    <div class="employee-details">
                        <div class="detail-row">
                            <span class="detail-label">ID:</span> ${emp.id} | 
                            <span class="detail-label">Dept:</span> ${emp.department} | 
                            <span class="detail-label">Shift:</span> ${emp.shift || '-'}
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Date:</span> ${displayDate} | 
                            <span class="detail-label">Time:</span> ${timeDisplay}
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(item);
        });
        
        updateSelectedCount();
    }

    function toggleEmployeeSelection(employeeId, employeeName, department, shift, date, attendanceType) {
        const existingIndex = bulkEditData.selectedEmployees.findIndex(emp => emp.id === employeeId);
        
        if (existingIndex >= 0) {
            bulkEditData.selectedEmployees.splice(existingIndex, 1);
        } else {
            bulkEditData.selectedEmployees.push({
                id: employeeId,
                name: employeeName,
                department: department,
                shift: shift,
                date: date,
                attendanceType: attendanceType
            });
        }
        
        updateSelectedCount();
    }

    function toggleAllEmployees() {
        const selectAll = document.getElementById('select-all-employees');
        const checkboxes = document.querySelectorAll('#bulk-employee-list input[type="checkbox"]');
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
            const employeeItem = checkbox.closest('.employee-item');
            const employeeName = employeeItem.querySelector('.employee-name').textContent;
            const employeeDetails = employeeItem.querySelector('.employee-details').textContent;
            const employeeId = employeeDetails.match(/ID: ([^|]+)/)?.[1]?.trim();
            const department = employeeDetails.match(/Dept: ([^|]+)/)?.[1]?.trim();
            const shift = employeeDetails.match(/Shift: ([^|]+)/)?.[1]?.trim();
            
            if (employeeId) {
                if (selectAll.checked) {
                    // Add if not already selected
                    if (!bulkEditData.selectedEmployees.some(emp => emp.id === employeeId)) {
                        bulkEditData.selectedEmployees.push({
                            id: employeeId,
                            name: employeeName,
                            department: department || '',
                            shift: shift || ''
                        });
                    }
                } else {
                    // Remove if selected
                    bulkEditData.selectedEmployees = bulkEditData.selectedEmployees.filter(emp => emp.id !== employeeId);
                }
            }
        });
        
        updateSelectedCount();
    }
    function updateSelectedCount() {
        const count = bulkEditData.selectedEmployees.length;
        document.getElementById('selected-count').textContent = `${count} employee${count !== 1 ? 's' : ''} selected`;
        
        // Update next button state
        const nextBtn = document.getElementById('bulk-next-btn');
        if (bulkEditData.currentStep === 1) {
            nextBtn.disabled = count === 0;
        }
    }

    function nextBulkStep() {
        if (bulkEditData.currentStep === 1) {
            if (bulkEditData.selectedEmployees.length === 0) {
                alert('Please select at least one employee.');
                return;
            }
            // Enforce single shift across selected employees
            const distinctShifts = Array.from(new Set(bulkEditData.selectedEmployees.map(e => (e.shift || '').toLowerCase()))).filter(Boolean);
            if (distinctShifts.length > 1) {
                showToast('Different shifts selected. Please select employees with the same shift.', 'error');
                return;
            }
            // Persist common shift and set default edit mode to full day
            bulkEditData.commonShift = distinctShifts[0] || (bulkEditData.selectedEmployees[0]?.shift || '');
            bulkEditData.selectedShift = 'full';
            showBulkStep(2);
        } else if (bulkEditData.currentStep === 2) {
            if (!bulkEditData.selectedShift) {
                alert('Please select a shift type.');
                return;
            }
            
            // No further validation: defaults are derived from commonShift captured in Step 1
            
            showBulkStep(3);
        }
    }

    function previousBulkStep() {
        if (bulkEditData.currentStep > 1) {
            showBulkStep(bulkEditData.currentStep - 1);
        }
    }

    function showBulkStep(step) {
        // Hide all steps
        document.querySelectorAll('.bulk-step').forEach(stepEl => {
            stepEl.style.display = 'none';
        });
        
        // Show current step
        document.getElementById(`bulk-step-${step}`).style.display = 'block';
        
        // Update buttons
        const prevBtn = document.getElementById('bulk-prev-btn');
        const nextBtn = document.getElementById('bulk-next-btn');
        const saveBtn = document.getElementById('bulk-save-btn');
        
        prevBtn.style.display = step > 1 ? 'inline-block' : 'none';
        nextBtn.style.display = step < 3 ? 'inline-block' : 'none';
        saveBtn.style.display = step === 3 ? 'inline-block' : 'none';
        
        bulkEditData.currentStep = step;
        
        // Update stepper visual state
        const stepper1 = document.getElementById('stepper-1');
        const stepper2 = document.getElementById('stepper-2');
        const stepper3 = document.getElementById('stepper-3');
        [stepper1, stepper2, stepper3].forEach((el, idx) => {
            if (!el) return;
            if (idx + 1 === step) {
                el.style.opacity = '1';
                el.style.borderColor = '#3F72AF';
                el.style.boxShadow = '0 6px 14px rgba(63,114,175,0.18)';
            } else {
                el.style.opacity = '0.8';
                el.style.borderColor = '#E3ECF9';
                el.style.boxShadow = '0 2px 8px rgba(0,0,0,0.04)';
            }
        });

        if (step === 3) {
            updateShiftInfoDisplay();
            // Auto-fill default times when entering step 3
            if (bulkEditData.selectedShift) {
                autofillShiftTimes(bulkEditData.selectedShift);
            }

            // Wire Status & OT controls
            const markOtEl = document.getElementById('bulk_mark_ot');
            const otHoursEl = document.getElementById('bulk_overtime_hours');
            const noTimeoutEl = document.getElementById('bulk_no_timeout');
            if (markOtEl && otHoursEl) {
                const syncOt = () => {
                    const noOtEl = document.getElementById('bulk_no_overtime');
                    const noOt = !!(noOtEl && noOtEl.checked);
                    const enable = markOtEl.checked && !noOt;
                    otHoursEl.disabled = !enable;
                    if (!enable) { otHoursEl.value = ''; }
                };
                markOtEl.addEventListener('change', syncOt);
                // Auto-toggle mark OT when hours typed
                const onHoursInput = () => {
                    const val = parseFloat(otHoursEl.value || '0');
                    if (val > 0) { markOtEl.checked = true; }
                    syncOt();
                };
                otHoursEl.addEventListener('input', onHoursInput);
                syncOt();
            }
            // No overtime toggle should disable OT hours and uncheck Mark Overtime
            const noOtEl = document.getElementById('bulk_no_overtime');
            if (noOtEl) {
                const otHoursEl = document.getElementById('bulk_overtime_hours');
                const markOtEl = document.getElementById('bulk_mark_ot');
                const syncNoOt = () => {
                    if (noOtEl.checked) {
                        if (markOtEl) { markOtEl.checked = false; }
                        if (otHoursEl) { otHoursEl.value = ''; otHoursEl.disabled = true; }
                    }
                };
                noOtEl.addEventListener('change', syncNoOt);
                syncNoOt();
            }
            if (noTimeoutEl) {
                const pmOutField = document.getElementById('bulk_pm_out');
                const syncNt = () => {
                    if (pmOutField) {
                        if (noTimeoutEl.checked) {
                            pmOutField.value = '';
                            pmOutField.disabled = true;
                            pmOutField.style.backgroundColor = '#f5f5f5';
                        } else {
                            pmOutField.disabled = false;
                            pmOutField.style.backgroundColor = '';
                        }
                    }
                };
                noTimeoutEl.addEventListener('change', syncNt);
                syncNt();
            }
        }

        // Enforce button enabled states per step
        if (step === 1) {
            nextBtn.disabled = bulkEditData.selectedEmployees.length === 0;
        } else if (step === 2) {
            // Preselect full day option and enable next
            nextBtn.disabled = false;
            const opts = document.querySelectorAll('.shift-option');
            opts.forEach(o => o.classList.remove('selected'));
            const fullOpt = Array.from(opts).pop();
            if (fullOpt) { fullOpt.classList.add('selected'); }
        } else {
            nextBtn.disabled = false;
        }
    }

    function selectBulkShift(shiftType, el) {
        // Check for multiple shift types in selected employees
        const distinctShifts = Array.from(new Set(bulkEditData.selectedEmployees.map(e => (e.shift || '').toLowerCase()))).filter(Boolean);
        
        if (distinctShifts.length > 1) {
            // Show warning popup
            document.getElementById('shift-warning-popup').style.display = 'block';
            return;
        }
        
        // Remove previous selection
        document.querySelectorAll('.shift-option').forEach(option => {
            option.classList.remove('selected');
        });
        
        // Add selection to clicked option
        if (el) { el.classList.add('selected'); }
        
        bulkEditData.selectedShift = shiftType;
        
        // Enable next button
        document.getElementById('bulk-next-btn').disabled = false;
        
        // Auto-fill default times when moving to step 3
        if (bulkEditData.currentStep === 2) {
            setTimeout(() => {
                autofillShiftTimes(shiftType);
            }, 100);
        }
    }
    
    function closeShiftWarning() {
        document.getElementById('shift-warning-popup').style.display = 'none';
    }
    
    function toggleHalfDayInputs() {
        const markHalfDay = document.getElementById('bulk_mark_half_day');
        const halfDaySession = document.getElementById('bulk_halfday_session');
        const morningSection = document.getElementById('bulk-morning-section');
        const afternoonSection = document.getElementById('bulk-afternoon-section');
        
        // Get all time inputs
        const amInField = document.getElementById('bulk_am_in');
        const amOutField = document.getElementById('bulk_am_out');
        const pmInField = document.getElementById('bulk_pm_in');
        const pmOutField = document.getElementById('bulk_pm_out');
        
        if (markHalfDay && markHalfDay.checked) {
            const session = halfDaySession.value;
            
            if (session === 'morning') {
                // Keep morning enabled, disable afternoon
                if (pmInField) {
                    pmInField.disabled = true;
                    pmInField.style.opacity = '0.5';
                    pmInField.style.cursor = 'not-allowed';
                    pmInField.style.background = '#f3f4f6';
                }
                if (pmOutField) {
                    pmOutField.disabled = true;
                    pmOutField.style.opacity = '0.5';
                    pmOutField.style.cursor = 'not-allowed';
                    pmOutField.style.background = '#f3f4f6';
                }
                if (afternoonSection) {
                    afternoonSection.style.opacity = '0.6';
                    afternoonSection.style.pointerEvents = 'none';
                }
                
                // Enable morning inputs
                if (amInField) {
                    amInField.disabled = false;
                    amInField.style.opacity = '1';
                    amInField.style.cursor = 'text';
                    amInField.style.background = 'white';
                }
                if (amOutField) {
                    amOutField.disabled = false;
                    amOutField.style.opacity = '1';
                    amOutField.style.cursor = 'text';
                    amOutField.style.background = 'white';
                }
                if (morningSection) {
                    morningSection.style.opacity = '1';
                    morningSection.style.pointerEvents = 'auto';
                }
            } else {
                // Keep afternoon enabled, disable morning
                if (amInField) {
                    amInField.disabled = true;
                    amInField.style.opacity = '0.5';
                    amInField.style.cursor = 'not-allowed';
                    amInField.style.background = '#f3f4f6';
                }
                if (amOutField) {
                    amOutField.disabled = true;
                    amOutField.style.opacity = '0.5';
                    amOutField.style.cursor = 'not-allowed';
                    amOutField.style.background = '#f3f4f6';
                }
                if (morningSection) {
                    morningSection.style.opacity = '0.6';
                    morningSection.style.pointerEvents = 'none';
                }
                
                // Enable afternoon inputs
                if (pmInField) {
                    pmInField.disabled = false;
                    pmInField.style.opacity = '1';
                    pmInField.style.cursor = 'text';
                    pmInField.style.background = 'white';
                }
                if (pmOutField) {
                    pmOutField.disabled = false;
                    pmOutField.style.opacity = '1';
                    pmOutField.style.cursor = 'text';
                    pmOutField.style.background = 'white';
                }
                if (afternoonSection) {
                    afternoonSection.style.opacity = '1';
                    afternoonSection.style.pointerEvents = 'auto';
                }
            }
        } else {
            // Half-day not checked, enable all inputs
            const allInputs = [amInField, amOutField, pmInField, pmOutField];
            allInputs.forEach(input => {
                if (input) {
                    input.disabled = false;
                    input.style.opacity = '1';
                    input.style.cursor = 'text';
                    input.style.background = 'white';
                }
            });
            
            if (morningSection) {
                morningSection.style.opacity = '1';
                morningSection.style.pointerEvents = 'auto';
            }
            if (afternoonSection) {
                afternoonSection.style.opacity = '1';
                afternoonSection.style.pointerEvents = 'auto';
            }
        }
    }
    
    function autofillShiftTimes(shift) {
        // Determine defaults from actual employee common shift captured in Step 1
        let schedule = bulkEditData.commonShift || '';
        if (!schedule && selectedShiftKey) schedule = selectedShiftKey;
        
        // Resolve schedule to defaults e.g., 09:00-18:00 => 09:00,12:00,13:00,18:00
        function resolveDefaultsFromShiftString(shiftStr) {
            const s = (shiftStr || '').toLowerCase();
            if (s.includes('09:00-18:00') || s.includes('9:00-18:00') || s.includes('9am-6pm')) {
                // 9am-6pm with 1-hour lunch from 13:00 to 14:00 → total 8hrs
                return { am_in: '09:00', am_out: '13:00', pm_in: '14:00', pm_out: '18:00' };
            }
            if (s.includes('08:30-17:30') || s.includes('8:30-5:30')) {
                return { am_in: '08:30', am_out: '12:30', pm_in: '13:30', pm_out: '17:30' };
            }
            // default 08:00-17:00
            return { am_in: '08:00', am_out: '12:00', pm_in: '13:00', pm_out: '17:00' };
        }
        const defaults = resolveDefaultsFromShiftString(schedule);
        
        if (defaults) {
            if (defaults.am_in) {
                const amInField = document.getElementById('bulk_am_in');
                if (amInField) amInField.value = defaults.am_in;
            }
            if (defaults.am_out) {
                const amOutField = document.getElementById('bulk_am_out');
                if (amOutField) amOutField.value = defaults.am_out;
            }
            if (defaults.pm_in) {
                const pmInField = document.getElementById('bulk_pm_in');
                if (pmInField) pmInField.value = defaults.pm_in;
            }
            if (defaults.pm_out) {
                const pmOutField = document.getElementById('bulk_pm_out');
                if (pmOutField) pmOutField.value = defaults.pm_out;
            }
        }
    }

    function updateShiftInfoDisplay() {
        const shiftInfo = document.getElementById('bulk-shift-info');
        const morningSection = document.getElementById('bulk-morning-section');
        const afternoonSection = document.getElementById('bulk-afternoon-section');
        
        let shiftText = '';
        let showMorning = false;
        let showAfternoon = false;
        
        switch (bulkEditData.selectedShift) {
            case 'morning':
                shiftText = 'Morning Shift - Edit AM In/Out times only';
                showMorning = true;
                break;
            case 'afternoon':
                shiftText = 'Afternoon Shift - Edit PM In/Out times only';
                showAfternoon = true;
                break;
            case 'full':
                shiftText = 'Full Day - Edit all times (AM & PM)';
                showMorning = true;
                showAfternoon = true;
                break;
        }
        
        shiftInfo.innerHTML = `
            <h4>Selected: ${shiftText}</h4>
            <p>Editing attendance for ${bulkEditData.selectedEmployees.length} employee${bulkEditData.selectedEmployees.length !== 1 ? 's' : ''}</p>
        `;
        
        morningSection.style.display = showMorning ? 'block' : 'none';
        afternoonSection.style.display = showAfternoon ? 'block' : 'none';
    }

    function submitBulkEdit() {
        if (bulkEditData.selectedEmployees.length === 0) {
            alert('No employees selected.');
            return;
        }
        
        if (!bulkEditData.selectedShift) {
            alert('No shift type selected.');
            return;
        }
        
        // Get the attendance date from modal filters
        const modalDateFilter = document.getElementById('bulk-date-filter')?.value || '';
        const modalMonthFilter = document.getElementById('bulk-month-filter')?.value || '';
        const modalYearFilter = document.getElementById('bulk-year-filter')?.value || '';
        
        let attendanceDate = '';
        if (modalDateFilter) {
            attendanceDate = modalDateFilter;
        } else if (modalMonthFilter) {
            attendanceDate = modalMonthFilter + '-01'; // Use first day of month
        } else if (modalYearFilter) {
            attendanceDate = modalYearFilter + '-01-01'; // Use first day of year
        } else {
            // Fallback to today if no date filter is set
            attendanceDate = new Date().toISOString().split('T')[0];
        }
        
        // Collect form data
        const formData = new FormData();
        formData.append('action', 'bulk_edit');
        formData.append('employees', JSON.stringify(bulkEditData.selectedEmployees));
        formData.append('shift_type', bulkEditData.selectedShift);
        formData.append('attendance_date', attendanceDate);
        
        // Add time fields based on selected shift
        if (bulkEditData.selectedShift === 'morning' || bulkEditData.selectedShift === 'full') {
            const amIn = document.getElementById('bulk_am_in').value;
            const amOut = document.getElementById('bulk_am_out').value;
            if (amIn) formData.append('am_in', amIn);
            if (amOut) formData.append('am_out', amOut);
        }
        
        if (bulkEditData.selectedShift === 'afternoon' || bulkEditData.selectedShift === 'full') {
            const pmIn = document.getElementById('bulk_pm_in').value;
            const pmOut = document.getElementById('bulk_pm_out').value;
            if (pmIn) formData.append('pm_in', pmIn);
            if (pmOut) formData.append('pm_out', pmOut);
        }
        
        const notes = document.getElementById('bulk_notes').value;
        if (notes) formData.append('notes', notes);
        
        // Overtime and No Time Out controls
        const markOtEl = document.getElementById('bulk_mark_ot');
        const otHoursEl = document.getElementById('bulk_overtime_hours');
        const noOtEl = document.getElementById('bulk_no_overtime');
        const noTimeoutEl = document.getElementById('bulk_no_timeout');
        const otHoursVal = parseFloat((otHoursEl && otHoursEl.value) ? otHoursEl.value : '0') || 0;
        const markOt = !!(markOtEl && markOtEl.checked);
        const markNoOt = !!(noOtEl && noOtEl.checked);
        if (!markNoOt && markOt && otHoursVal > 0) {
            formData.append('is_overtime', '1');
            formData.append('overtime_hours', otHoursVal.toFixed(2));
        } else {
            // Explicitly clear OT to prevent lingering values
            formData.append('is_overtime', '0');
            formData.append('overtime_hours', '0');
        }
        const noTimeout = !!(noTimeoutEl && noTimeoutEl.checked);
        if (noTimeout) {
            formData.append('no_time_out', '1');
            // Ensure segment and overall outs are cleared
            formData.append('pm_out', '');
            formData.append('clear_time_out', '1');
        }
        
        // Handle half-day functionality
        const markHalfDayEl = document.getElementById('bulk_mark_half_day');
        const halfDaySessionEl = document.getElementById('bulk_halfday_session');
        if (markHalfDayEl && markHalfDayEl.checked) {
            formData.append('mark_half_day', '1');
            formData.append('halfday_session', halfDaySessionEl.value);
        }
        
        // Debug: Log the data being sent
        console.log('Bulk edit data being sent:');
        console.log('Employees:', bulkEditData.selectedEmployees);
        console.log('Shift type:', bulkEditData.selectedShift);
        console.log('Attendance date:', attendanceDate);
        console.log('Time fields:', {
            am_in: document.getElementById('bulk_am_in')?.value,
            am_out: document.getElementById('bulk_am_out')?.value,
            pm_in: document.getElementById('bulk_pm_in')?.value,
            pm_out: document.getElementById('bulk_pm_out')?.value,
            notes: notes
        });
        
        // Show loading state
        const saveBtn = document.getElementById('bulk-save-btn');
        const originalText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        saveBtn.disabled = true;
        
        // Submit to backend
        fetch('bulk_edit_attendance.php', {
            method: 'POST',
            body: formData
        })
        .then(async (response) => {
            // Always read raw text first to guard against invalid JSON produced by PHP notices
            const raw = await response.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                // Not valid JSON; package raw as message
                data = { success: false, message: raw && raw.trim() ? raw.trim().slice(0, 800) : `HTTP ${response.status}` };
            }
            if (!response.ok) {
                throw data;
            }
            return data;
        })
        .then(data => {
            if (data.success) {
                showToast(`Successfully updated attendance for ${data.updated_count} employee${data.updated_count !== 1 ? 's' : ''}.`, 'success');
                closeBulkEditModal();
                // Recalculate overtime for the affected date to persist OT to DB, then reload current page with existing filters
                const targetDate = attendanceDate || new Date().toISOString().split('T')[0];
                const recalcForm = new FormData();
                recalcForm.append('start_date', targetDate);
                recalcForm.append('end_date', targetDate);
                fetch('recalculate_overtime.php', { method: 'POST', body: recalcForm })
                    .catch(() => {})
                    .finally(() => {
                        // Preserve current URL parameters (filters) when reloading
                        window.location.href = window.location.href.split('?')[0] + (window.location.search || '');
                    });
            } else {
                console.error('Backend error:', data);
                let errorMessage = 'Error: ' + (data.message || 'Failed to update attendance.');
                if (data.debug_info) {
                    errorMessage += '\n\nDebug info: ' + JSON.stringify(data.debug_info);
                }
                showToast(errorMessage, 'error');
            }
        })
        .catch(error => {
            // Normalize error object
            const msg = (error && (error.message || error.error || error.msg)) ? (error.message || error.error || error.msg) : String(error);
            console.error('Bulk edit request failed:', error);
            showToast('Error updating attendance: ' + msg, 'error');
        })
        .finally(() => {
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
        });
    }

    // ===== PAGINATION FUNCTIONALITY =====
    let paginationState = {
        currentPage: 1,
        recordsPerPage: 10,
        totalRecords: 0,
        allRows: []
    };

    function initializePagination() {
        const tbody = document.querySelector('#attendanceTableBody');
        if (!tbody) return;
        
        // Get all table rows (excluding "no records" placeholder)
        paginationState.allRows = Array.from(tbody.querySelectorAll('tr[data-employee_id]'));
        paginationState.totalRecords = paginationState.allRows.length;
        
        // Debug: Log pagination state
        console.log(`Pagination initialized: ${paginationState.totalRecords} total records found in table`);
        
        // Reset to page 1 when reinitializing (important for filter changes)
        paginationState.currentPage = 1;
        
        // Apply pagination
        updatePagination();
    }
    
    function updatePagination() {
        const { currentPage, recordsPerPage, totalRecords, allRows } = paginationState;
        
        if (totalRecords === 0) {
            document.getElementById('pagination-start').textContent = '0';
            document.getElementById('pagination-end').textContent = '0';
            document.getElementById('pagination-total').textContent = '0';
            return;
        }
        
        const totalPages = Math.ceil(totalRecords / recordsPerPage);
        const startIndex = (currentPage - 1) * recordsPerPage;
        const endIndex = Math.min(startIndex + recordsPerPage, totalRecords);
        
        // Update pagination info
        document.getElementById('pagination-start').textContent = startIndex + 1;
        document.getElementById('pagination-end').textContent = endIndex;
        document.getElementById('pagination-total').textContent = totalRecords;
        
        // Show/hide rows based on current page
        allRows.forEach((row, index) => {
            if (index >= startIndex && index < endIndex) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        
        // Update pagination buttons
        updatePaginationButtons(totalPages);
        renderPageButtons(totalPages);
    }
    
    function updatePaginationButtons(totalPages) {
        const { currentPage } = paginationState;
        
        // Update page buttons
        document.getElementById('btn-first').disabled = currentPage === 1;
        document.getElementById('btn-prev').disabled = currentPage === 1;
        document.getElementById('btn-next').disabled = currentPage === totalPages;
        document.getElementById('btn-last').disabled = currentPage === totalPages;
    }
    
    function renderPageButtons(totalPages) {
        const pagesContainer = document.getElementById('pagination-pages');
        pagesContainer.innerHTML = '';
        
        const { currentPage } = paginationState;
        
        // Calculate which pages to show (show max 5 page buttons)
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, startPage + 4);
        
        // Adjust start page if we're near the end
        if (endPage - startPage < 4) {
            startPage = Math.max(1, endPage - 4);
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const btn = document.createElement('button');
            btn.textContent = i;
            btn.className = 'pagination-btn page-number';
            btn.style.cssText = `padding: 8px 12px; border: 1px solid ${i === currentPage ? '#3F72AF' : '#d1d5db'}; background: ${i === currentPage ? '#3F72AF' : 'white'}; color: ${i === currentPage ? 'white' : '#374151'}; border-radius: 6px; cursor: pointer; transition: all 0.2s; min-width: 40px;`;
            btn.onclick = () => goToPage(i);
            pagesContainer.appendChild(btn);
        }
    }
    function changePage(direction) {
        const { currentPage, totalRecords, recordsPerPage } = paginationState;
        const totalPages = Math.ceil(totalRecords / recordsPerPage);
        
        switch(direction) {
            case 'first':
                paginationState.currentPage = 1;
                break;
            case 'prev':
                paginationState.currentPage = Math.max(1, currentPage - 1);
                break;
            case 'next':
                paginationState.currentPage = Math.min(totalPages, currentPage + 1);
                break;
            case 'last':
                paginationState.currentPage = totalPages;
                break;
        }
        
        updatePagination();
    }
    
    function goToPage(pageNumber) {
        paginationState.currentPage = pageNumber;
        updatePagination();
    }
    
    function changeRecordsPerPage() {
        const select = document.getElementById('records-per-page');
        paginationState.recordsPerPage = parseInt(select.value);
        paginationState.currentPage = 1; // Reset to first page
        updatePagination();
    }

    // Initialize pagination on page load
    document.addEventListener('DOMContentLoaded', function() {
        initializePagination();
        
        // Check if there are active filters and update summary cards accordingly
        checkAndUpdateSummaryCards();
    });

    function checkAndUpdateSummaryCards() {
        // Check if there are any active filters in the URL
        const urlParams = new URLSearchParams(window.location.search);
        const hasFilters = urlParams.has('date') || urlParams.has('month') || urlParams.has('year') || 
                          urlParams.has('department') || urlParams.has('shift') || urlParams.has('status') || 
                          urlParams.has('attendance_type') || urlParams.has('search');
        
        // If there are active filters, update summary cards from table data
        // Otherwise, keep the PHP default values
        if (hasFilters) {
            setTimeout(updateSummaryCardsFromTable, 200);
        } else {
            // Reset to default values if no filters are active
            resetSummaryCardsToDefault();
        }
    }

    function resetSummaryCardsToDefault() {
        // Restore the original PHP values from data attributes
        const totalElement = document.getElementById('summary-total');
        if (totalElement) {
            totalElement.textContent = totalElement.getAttribute('data-default');
        }
        
        const presentElement = document.getElementById('summary-present');
        if (presentElement) {
            presentElement.textContent = presentElement.getAttribute('data-default');
        }
        
        const absentElement = document.getElementById('summary-absent');
        if (absentElement) {
            absentElement.textContent = absentElement.getAttribute('data-default');
        }
        
        const lateElement = document.getElementById('summary-late');
        if (lateElement) {
            lateElement.textContent = lateElement.getAttribute('data-default');
        }
        
        const overtimeElement = document.getElementById('summary-overtime');
        if (overtimeElement) {
            overtimeElement.textContent = overtimeElement.getAttribute('data-default');
        }
        
        console.log('Restored default PHP summary card values');
    }

    // AJAX Toggle switch functionality
    document.addEventListener('DOMContentLoaded', function() {
        const toggleSwitch = document.getElementById('show_overtime');
        const toggleContainer = document.querySelector('.switch-btn');
        
        if (toggleSwitch && toggleContainer) {
            console.log('Toggle switch and container found!');
            // Add loading state
            function setLoadingState(loading) {
                if (loading) {
                    toggleContainer.classList.add('loading');
                    toggleSwitch.disabled = true;
                } else {
                    toggleContainer.classList.remove('loading');
                    toggleSwitch.disabled = false;
                }
            }
        } else {
            console.log('Toggle elements not found!');
            console.log('Toggle switch:', toggleSwitch);
            console.log('Toggle container:', toggleContainer);
        }
        
        // AJAX function to update attendance records
        function updateAttendanceRecords() {
            if (toggleSwitch && toggleContainer) {
                setLoadingState(true);
                
                // Get current URL parameters
                const currentUrl = new URL(window.location);
                const params = new URLSearchParams(currentUrl.search);
                
                // Update show_overtime parameter
                if (toggleSwitch.checked) {
                    params.set('show_overtime', '1');
                } else {
                    params.delete('show_overtime');
                }
                
                console.log('Making AJAX request with params:', params.toString());
                
                // Make AJAX request to dedicated AJAX file
                fetch('ajax_overtime_filter.php?' + params.toString(), {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('AJAX response received:', data);
                    
                    if (data.success && data.html) {
                        // Replace the current results
                        const currentResults = document.querySelector('.results-container');
                        if (currentResults) {
                            currentResults.outerHTML = data.html;
                            console.log('Results updated successfully');
                            
                            // Re-attach pagination event listeners
                            attachPaginationListeners();
                            
                            // Re-attach modal event listeners
                            attachModalEventListeners();
                        }
                    } else {
                        console.warn('No HTML content in response');
                    }
                    
                    setLoadingState(false);
                })
                .catch(error => {
                    console.error('Error updating attendance records:', error);
                    setLoadingState(false);
                    
                    // Fallback: reload the page
                    console.log('Falling back to page reload');
                    window.location.href = window.location.pathname + '?' + params.toString();
                });
            }
        }
        
        // Add event listener for toggle changes
        if (toggleSwitch) {
            toggleSwitch.addEventListener('change', function() {
                console.log('Toggle changed! Checked:', this.checked);
                updateAttendanceRecords();
            });
        }
        
        // Function to attach pagination event listeners
        function attachPaginationListeners() {
            const paginationBtns = document.querySelectorAll('.pagination-btn');
            paginationBtns.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const page = this.getAttribute('data-page');
                    if (page) {
                        // Update URL with new page
                        const currentUrl = new URL(window.location);
                        currentUrl.searchParams.set('page', page);
                        
                        // Make AJAX request with new page
                        fetch('ajax_overtime_filter.php?' + currentUrl.searchParams.toString(), {
                            method: 'GET',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Content-Type': 'application/json'
                            }
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.html) {
                                const currentResults = document.querySelector('.results-container');
                                if (currentResults) {
                                    currentResults.outerHTML = data.html;
                                    attachPaginationListeners(); // Re-attach listeners
                                    attachModalEventListeners(); // Re-attach modal listeners
                                }
                            }
                        })
                        .catch(error => {
                            console.error('Error loading page:', error);
                        });
                    }
                });
            });
        }
        
        // Function to attach modal event listeners
        function attachModalEventListeners() {
            // Re-attach click handlers for table rows
            const tableRows = document.querySelectorAll('.results-container tbody tr');
            tableRows.forEach(row => {
                // Remove existing click handlers to avoid duplicates
                row.onclick = null;
                
                // Add new click handler
                row.addEventListener('click', function(e) {
                    const employeeId = this.getAttribute('data-employee-id');
                    const employeeName = this.getAttribute('data-employee-name');
                    const attendanceDate = this.getAttribute('data-attendance-date');
                    
                    // Get data from data attributes (preferred method)
                    const timeIn = this.getAttribute('data-time-in') || '';
                    const timeOut = this.getAttribute('data-time-out') || '';
                    const amIn = this.getAttribute('data-time-in-morning') || '';
                    const amOut = this.getAttribute('data-time-out-morning') || '';
                    const pmIn = this.getAttribute('data-time-in-afternoon') || '';
                    const pmOut = this.getAttribute('data-time-out-afternoon') || '';
                    const status = this.getAttribute('data-status') || '';
                    const lateMinutes = this.getAttribute('data-late-minutes') || '0';
                    const earlyMinutes = this.getAttribute('data-early-out-minutes') || '0';
                    const overtimeHours = this.getAttribute('data-overtime-hours') || '0';
                    const shift = this.getAttribute('data-shift') || '';
                    const department = this.getAttribute('data-department') || '';
                    const source = this.getAttribute('data-source') || 'biometric';
                    const isOnLeave = this.getAttribute('data-is-on-leave') || this.closest('tr')?.getAttribute('data-is_on_leave') || '0';
                    
                    // Add data attributes to the row for the modal function to find
                    this.setAttribute('data-employee_id', employeeId);
                    this.setAttribute('data-shift', shift);
                    this.setAttribute('data-department', department);
                    this.setAttribute('data-source', source);
                    
                    if (employeeId && employeeName && attendanceDate) {
                        openHrViewModal(
                            employeeName,
                            employeeId,
                            attendanceDate,
                            timeIn,
                            timeOut,
                            amIn,
                            amOut,
                            pmIn,
                            pmOut,
                            status,
                            lateMinutes,
                            earlyMinutes,
                            overtimeHours,
                            isOnLeave
                        );
                    }
                });
            });
            
            // Re-attach action button handlers
            const actionButtons = document.querySelectorAll('.results-container .btn[data-employee-id]');
            actionButtons.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const row = this.closest('tr');
                    const employeeId = row.getAttribute('data-employee-id');
                    const employeeName = row.getAttribute('data-employee-name');
                    const attendanceDate = row.getAttribute('data-attendance-date');
                    
                    // Extract data from table cells (0-indexed)
                    const timeInText = row.cells[4]?.textContent?.trim() || '';
                    const timeOutText = row.cells[5]?.textContent?.trim() || '';
                    const status = row.querySelector('.status-badge')?.textContent?.trim()?.toLowerCase() || '';
                    const lateMinutes = row.cells[9]?.textContent?.trim() || '0';
                    const earlyMinutes = row.cells[10]?.textContent?.trim() || '0';
                    const overtimeHours = row.cells[11]?.textContent?.replace('h', '').trim() || '0';
                    
                    // Convert 12-hour format to 24-hour format for the modal
                    const convertTo24Hour = (time12) => {
                        if (!time12 || time12 === '-') return '';
                        try {
                            const [time, period] = time12.split(' ');
                            const [hours, minutes] = time.split(':');
                            let hour24 = parseInt(hours);
                            if (period === 'PM' && hour24 !== 12) hour24 += 12;
                            if (period === 'AM' && hour24 === 12) hour24 = 0;
                            return `${hour24.toString().padStart(2, '0')}:${minutes}:00`;
                        } catch (e) {
                            return time12;
                        }
                    };
                    
                    const timeIn = convertTo24Hour(timeInText);
                    const timeOut = convertTo24Hour(timeOutText);
                    
                    // Add data attributes to the row for the modal function to find
                    row.setAttribute('data-employee_id', employeeId);
                    row.setAttribute('data-shift', row.cells[3]?.textContent?.trim() || '');
                    const isOnLeave = row.getAttribute('data-is_on_leave') || '0';
                    
                    if (employeeId && employeeName && attendanceDate) {
                        openHrViewModal(
                            employeeName,
                            employeeId,
                            attendanceDate,
                            timeIn,
                            timeOut,
                            '', // amIn
                            '', // amOut
                            '', // pmIn
                            '', // pmOut
                            status,
                            lateMinutes,
                            earlyMinutes,
                            overtimeHours,
                            isOnLeave
                        );
                    }
                });
            });
        }
        
        // Initial pagination setup
        attachPaginationListeners();
        
        // Initial modal setup
        attachModalEventListeners();
        
        // Leave status functionality
        function toggleLeaveStatus() {
            const leaveCheckbox = document.getElementById('edit_is_on_leave');
            const leaveBalanceInfo = document.getElementById('leaveBalanceInfo');
            const leaveBalanceText = document.getElementById('leaveBalanceText');
            
            if (leaveCheckbox.checked) {
                // Show leave balance info
                leaveBalanceInfo.style.display = 'block';
                fetchLeaveBalance();
                
                // Clear all time inputs when on leave is checked
                document.getElementById('edit_am_in').value = '';
                document.getElementById('edit_am_out').value = '';
                document.getElementById('edit_pm_in').value = '';
                document.getElementById('edit_pm_out').value = '';
                document.getElementById('edit_time_in').value = '';
                document.getElementById('edit_time_out').value = '';
                
                // Set attendance type to absent
                document.getElementById('edit_attendance_type').value = 'absent';
                
                // Set status to on_leave
                document.getElementById('edit_status').value = 'on_leave';
                
                // Clear overtime fields
                document.getElementById('edit_overtime_hours').value = '';
                document.getElementById('edit_is_overtime').value = '0';
                
                // Uncheck half day
                document.getElementById('edit_half_day').checked = false;
            } else {
                // Hide leave balance info
                leaveBalanceInfo.style.display = 'none';
                
                // Clear status if it was on_leave
                if (document.getElementById('edit_status').value === 'on_leave') {
                    document.getElementById('edit_status').value = '';
                }
            }
        }

        function fetchLeaveBalance() {
            const employeeId = document.getElementById('edit_employee_id').value;
            if (!employeeId) {
                document.getElementById('leaveBalanceText').textContent = 'Please select an employee first';
                return;
            }

            // Fetch leave balance via AJAX
            fetch('get_employee_leave_balance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `employee_id=${encodeURIComponent(employeeId)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const balance = data.leave_balance;
                    const status = balance > 0 ? 'Available' : 'No leave days remaining';
                    const color = balance > 0 ? '#4CAF50' : '#F44336';
                    document.getElementById('leaveBalanceText').innerHTML = 
                        `<span style="color: ${color}; font-weight: bold;">${balance} leave days ${status}</span>`;
                    
                    // Disable checkbox if no leave days remaining
                    const checkbox = document.getElementById('edit_is_on_leave');
                    if (balance <= 0) {
                        checkbox.checked = false;
                        checkbox.disabled = true;
                        document.getElementById('leaveBalanceInfo').style.display = 'none';
                        alert('Employee has no remaining leave days. Cannot set on leave.');
                    } else {
                        checkbox.disabled = false;
                    }
                } else {
                    document.getElementById('leaveBalanceText').textContent = 'Error loading leave balance';
                }
            })
            .catch(error => {
                console.error('Error fetching leave balance:', error);
                document.getElementById('leaveBalanceText').textContent = 'Error loading leave balance';
            });
        }

        // Update openEditAttendance function to handle leave status
        const originalOpenEditAttendance = window.openEditAttendance;
        window.openEditAttendance = function(btn, id) {
            originalOpenEditAttendance(btn, id);
            
            // Reset leave status
            const leaveCheckbox = document.getElementById('edit_is_on_leave');
            const leaveBalanceInfo = document.getElementById('leaveBalanceInfo');
            leaveCheckbox.checked = false;
            leaveCheckbox.disabled = false;
            leaveBalanceInfo.style.display = 'none';
            
            // If this is an existing record, check if it's already on leave
            if (id && id !== '0') {
                const row = btn?.closest('tr') || document.querySelector(`tr[data-id='${id}']`);
                if (row) {
                    const isOnLeave = row.getAttribute('data-is_on_leave');
                    if (isOnLeave === '1') {
                        leaveCheckbox.checked = true;
                        leaveBalanceInfo.style.display = 'block';
                        fetchLeaveBalance();
                    }
                }
            }
        };
    });
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

    <!-- Bulk Edit Attendance Modal -->
        <div id="bulkEditModal" class="modal">
        <div class="modal-content" style="max-width: 95%; width: 1280px; border-radius: 18px; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%); color:#fff; padding: 18px 22px; display:flex; align-items:center; justify-content:space-between;">
                <h2><i class="fas fa-users-edit"></i> Bulk Edit Attendance</h2>
                <span class="close" onclick="closeBulkEditModal()" style="cursor:pointer; font-size: 26px;">&times;</span>
            </div>
            <div class="modal-body" style="background: linear-gradient(180deg, #F8FBFF 0%, #F1F5F9 100%); padding: 22px; padding-bottom: 90px;">
                <!-- Progress Stepper -->
                <div id="bulk-stepper" style="display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:20px;">
                    <div class="step-item" id="stepper-1" style="flex:1; display:flex; align-items:center; gap:10px; background:#fff; border:1px solid #E3ECF9; padding:12px 14px; border-radius:12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                        <div class="step-badge" style="width:28px; height:28px; border-radius:50%; background:#DBE2EF; color:#112D4E; display:flex; align-items:center; justify-content:center; font-weight:700;">1</div>
                        <div style="font-weight:600; color:#112D4E;">Select Employees</div>
                    </div>
                    <div class="step-item" id="stepper-2" style="flex:1; display:flex; align-items:center; gap:10px; background:#fff; border:1px solid #E3ECF9; padding:12px 14px; border-radius:12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); opacity:0.8;">
                        <div class="step-badge" style="width:28px; height:28px; border-radius:50%; background:#DBE2EF; color:#112D4E; display:flex; align-items:center; justify-content:center; font-weight:700;">2</div>
                        <div style="font-weight:600; color:#112D4E;">Select Shift</div>
                    </div>
                    <div class="step-item" id="stepper-3" style="flex:1; display:flex; align-items:center; gap:10px; background:#fff; border:1px solid #E3ECF9; padding:12px 14px; border-radius:12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); opacity:0.8;">
                        <div class="step-badge" style="width:28px; height:28px; border-radius:50%; background:#DBE2EF; color:#112D4E; display:flex; align-items:center; justify-content:center; font-weight:700;">3</div>
                        <div style="font-weight:600; color:#112D4E;">Edit Times</div>
                    </div>
                </div>
                <!-- Step 1: Select Employees -->
                <div id="bulk-step-1" class="bulk-step">
                    <h3 style="display:flex; align-items:center; gap:10px;"><i class="fas fa-users"></i> Step 1: Select Employees</h3>
                    <div style="display:grid; grid-template-columns: 460px 1fr; gap: 20px; align-items:start;">
                    <div class="bulk-filter-section" style="border: 1px solid #E3ECF9; min-height: 560px;">
                        <div class="form-group">
                            <label for="bulk-department-filter">Filter by Department:</label>
                            <select id="bulk-department-filter" class="form-control" onchange="filterBulkEmployees()">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>">
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="bulk-shift-filter">Filter by Shift:</label>
                            <select id="bulk-shift-filter" class="form-control" onchange="filterBulkEmployees()">
                                <option value="">All Shifts</option>
                                <option value="08:00-17:00">8:00 AM - 5:00 PM</option>
                                <option value="08:30-17:30">8:30 AM - 5:30 PM</option>
                                <option value="09:00-18:00">9:00 AM - 6:00 PM</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="bulk-type-filter">Attendance Type:</label>
                            <select id="bulk-type-filter" class="form-control" onchange="filterBulkEmployees()">
                                <option value="">All Types</option>
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="bulk-employee-search">Search Employee:</label>
                            <div class="search-input-wrapper">
                                <input type="text" id="bulk-employee-search" class="form-control large-input" placeholder="Type employee name or ID..." onkeyup="filterBulkEmployees()" style="padding-right:44px;">
                                <button type="button" class="clear-search-btn" onclick="document.getElementById('bulk-employee-search').value=''; filterBulkEmployees();" title="Clear search" style="position:absolute; right:10px; top:50%; transform: translateY(-50%); border:none; background:transparent; cursor:pointer;">
                                    <i class="fas fa-times" style="color:#6c757d"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Date Filter:</label>
                            <div class="date-filter-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; align-items: flex-start;">
                                <div style="min-width: 0;">
                                    <label style="font-weight:600; color:#112D4E; font-size:12px; display:block; margin-bottom:4px;">📅 Pick Date</label>
                                    <input type="date" id="bulk-date-filter" class="form-control" onchange="reloadBulkEmployees()" style="height: 38px; width: 100%; font-size: 13px;">
                                </div>
                                <div style="min-width: 0;">
                                    <label style="font-weight:600; color:#112D4E; font-size:12px; display:block; margin-bottom:4px;">📆 Pick Month</label>
                                    <input type="month" id="bulk-month-filter" class="form-control" onchange="reloadBulkEmployees()" style="height: 38px; width: 100%; font-size: 13px;">
                                </div>
                                <div style="min-width: 0;">
                                    <label style="font-weight:600; color:#112D4E; font-size:12px; display:block; margin-bottom:4px;">🗓️ Pick Year</label>
                                    <select id="bulk-year-filter" class="form-control" onchange="reloadBulkEmployees()" style="height: 38px; width: 100%; font-size: 13px;">
                                    <option value="">All Years</option>
                                    <?php for ($y = date('Y'); $y >= date('Y')-10; $y--) { echo '<option value="'.$y.'">'.$y.'</option>'; } ?>
                                    </select>
                                </div>
                            </div>
                            <small style="color:#6c757d; font-size:11px; margin-top:8px; display:block;">💡 You can use any combination of date filters. Leave empty to show all records.</small>
                        </div>
                    </div>
                    <div class="employee-selection-container" style="border: 1px solid #E3ECF9; border-radius: 12px; overflow:hidden; min-height: 560px;">
                        <div class="selection-header">
                            <label>
                                <input type="checkbox" id="select-all-employees" onchange="toggleAllEmployees()">
                                Select All Visible
                            </label>
                            <span id="selected-count" style="font-weight:600; color:#112D4E;">0 employees selected</span>
                        </div>
                        <div id="bulk-employee-list" class="employee-list">
                            <!-- Employee list will be populated here -->
                        </div>
                    </div>
                    </div>
                </div>

                <!-- Step 2: Select Shift Type -->
                <div id="bulk-step-2" class="bulk-step" style="display: none;">
                    <h3 style="display:flex; align-items:center; gap:10px;"><i class="fas fa-clock"></i> Step 2: Select Shift Type</h3>
                    <div class="shift-selection" style="grid-template-columns: repeat(3, minmax(280px, 1fr));">
                        <div class="shift-option" onclick="selectBulkShift('morning', this)">
                            <div class="shift-icon">
                                <i class="fas fa-sun"></i>
                            </div>
                            <div class="shift-info">
                                <h4>Morning Shift</h4>
                                <p>Edit AM In/Out times only</p>
                            </div>
                        </div>
                        <div class="shift-option" onclick="selectBulkShift('afternoon', this)">
                            <div class="shift-icon">
                                <i class="fas fa-cloud-sun"></i>
                            </div>
                            <div class="shift-info">
                                <h4>Afternoon Shift</h4>
                                <p>Edit PM In/Out times only</p>
                            </div>
                        </div>
                        <div class="shift-option" onclick="selectBulkShift('full', this)">
                            <div class="shift-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="shift-info">
                                <h4>Full Day</h4>
                                <p>Edit all times (AM & PM)</p>
                            </div>
                        </div>
                    </div>
                    <!-- Warning popup for multiple shift selection -->
                    <div id="shift-warning-popup" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; border: 2px solid #ff6b6b; border-radius: 12px; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 10000; max-width: 400px;">
                        <div style="text-align: center;">
                            <div style="font-size: 48px; color: #ff6b6b; margin-bottom: 15px;">⚠️</div>
                            <h4 style="color: #ff6b6b; margin-bottom: 10px;">Multiple Shift Types Detected</h4>
                            <p style="color: #666; margin-bottom: 20px; line-height: 1.5;">You have selected employees with different shift types. Please choose one shift type to edit at a time for consistency.</p>
                            <button onclick="closeShiftWarning()" style="background: #ff6b6b; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600;">Got it</button>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Edit Times -->
                <div id="bulk-step-3" class="bulk-step" style="display: none; max-height: 60vh; overflow-y: auto; padding-right: 8px;">
                    <h3 style="display:flex; align-items:center; gap:10px;"><i class="fas fa-edit"></i> Step 3: Edit Attendance Times</h3>
                    <div id="bulk-shift-info" class="shift-info-display"></div>
                    <form id="bulkEditForm">
                        <!-- Status & Overtime Controls (moved to top for visibility) -->
                        <div class="form-section" style="margin-bottom: 16px;">
                            <h3 style="display:flex; align-items:center; gap:8px;"><i class="fas fa-business-time"></i> Status & Overtime</h3>
                            <div class="form-grid" style="grid-template-columns: repeat(4, minmax(220px, 1fr)); gap: 16px;">
                                <div class="form-group" style="display:flex; align-items:center; gap:10px;">
                                    <input type="checkbox" id="bulk_mark_ot" style="transform: scale(1.2);">
                                    <label for="bulk_mark_ot" style="margin:0;">Mark Overtime</label>
                                </div>
                                <div class="form-group">
                                    <label for="bulk_overtime_hours">Overtime Hours</label>
                                    <input type="number" id="bulk_overtime_hours" class="form-control large-input" step="0.01" min="0" placeholder="0.00" disabled>
                                </div>
                                <div class="form-group" style="display:flex; align-items:center; gap:10px;">
                                    <input type="checkbox" id="bulk_no_overtime" style="transform: scale(1.2);">
                                    <label for="bulk_no_overtime" style="margin:0;">Mark as No Overtime</label>
                                </div>
                                <div class="form-group" style="display:flex; align-items:center; gap:10px;">
                                    <input type="checkbox" id="bulk_no_timeout" style="transform: scale(1.2);">
                                    <label for="bulk_no_timeout" style="margin:0;">Mark as No Time Out</label>
                                </div>
                            </div>
                            <div class="form-group" style="display:flex; align-items:center; gap:10px; margin-top:10px;">
                                <input type="checkbox" id="bulk_mark_half_day" style="transform: scale(1.2);" onchange="toggleHalfDayInputs()">
                                <label for="bulk_mark_half_day" style="margin:0;">Mark as Half Day</label>
                                <select id="bulk_halfday_session" class="form-control large-input" style="max-width: 280px;" onchange="toggleHalfDayInputs()">
                                    <option value="morning">🌅 Record Morning Only (4 hours)</option>
                                    <option value="afternoon">🌆 Record Afternoon Only (4 hours)</option>
                                </select>
                            </div>
                            <small style="color:#6c757d;">If "Mark Overtime" is unchecked or "Mark as No Overtime" is checked, overtime will be cleared to 0. If "Mark as No Time Out" is checked, time out will be cleared to avoid lingering OT. For half day, choose which session to keep.</small>
                        </div>
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; align-items:start; gap: 24px;">
                            <div id="bulk-morning-section" class="form-section" style="display: none;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <h3><i class="fas fa-sun"></i> Morning</h3>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="autofillShiftTimes(bulkEditData.selectedShift)" style="font-size: 12px; padding: 6px 12px;">
                                        <i class="fas fa-magic"></i> Auto-fill Default
                                    </button>
                                </div>
                                <div class="form-group">
                                    <label for="bulk_am_in">Time In (AM)</label>
                                    <input type="time" id="bulk_am_in" name="am_in" class="form-control large-input">
                                </div>
                                <div class="form-group">
                                    <label for="bulk_am_out">Time Out (Lunch Start)</label>
                                    <input type="time" id="bulk_am_out" name="am_out" class="form-control large-input">
                                </div>
                            </div>
                            <div id="bulk-afternoon-section" class="form-section" style="display: none;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <h3><i class="fas fa-cloud-sun"></i> Afternoon</h3>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="autofillShiftTimes(bulkEditData.selectedShift)" style="font-size: 12px; padding: 6px 12px;">
                                        <i class="fas fa-magic"></i> Auto-fill Default
                                    </button>
                                </div>
                                <div class="form-group">
                                    <label for="bulk_pm_in">Time In (Lunch End)</label>
                                    <input type="time" id="bulk_pm_in" name="pm_in" class="form-control large-input">
                                </div>
                                <div class="form-group">
                                    <label for="bulk_pm_out">Time Out (PM)</label>
                                    <input type="time" id="bulk_pm_out" name="pm_out" class="form-control large-input">
                                </div>
                                <!-- Notes inside right column for full visibility -->
                                <div class="form-group" style="margin-top: 16px;">
                                    <label for="bulk_notes">Notes (Optional)</label>
                                    <textarea id="bulk_notes" name="notes" class="form-control large-textarea" rows="6" style="min-height: 140px; resize: vertical;"></textarea>
                                </div>
                            </div>
                        </div>
                        <div style="text-align: center; margin: 20px 0;">
                            <button type="button" class="btn btn-info" onclick="autofillShiftTimes(bulkEditData.selectedShift)" style="box-shadow: 0 2px 8px rgba(52, 144, 220, 0.2);">
                                <i class="fas fa-magic"></i> Auto-fill All Default Times
                            </button>
                        </div>
                        
                    </form>
                </div>
            </div>
            <div class="modal-footer" style="background: #fff; border-top: 1px solid #E5EAF1; padding: 16px 22px; position: sticky; bottom: 0; z-index: 2; box-shadow: 0 -6px 14px rgba(17,45,78,0.06);">
                <div style="display:flex; gap:12px; align-items:center; justify-content:space-between; width:100%;">
                    <div>
                        <button type="button" class="btn btn-secondary" id="bulk-prev-btn" onclick="previousBulkStep()" style="display: none; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #DBE2EF; min-width:140px;">
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                    </div>
                    <div style="display:flex; gap:12px; align-items:center;">
                        <button type="button" class="btn btn-primary" id="bulk-next-btn" onclick="nextBulkStep()" style="box-shadow: 0 8px 18px rgba(63, 114, 175, 0.35); font-weight:700; padding: 12px 22px; min-width:140px;">
                            Next <i class="fas fa-arrow-right"></i>
                        </button>
                        <button type="button" class="btn btn-success" id="bulk-save-btn" onclick="submitBulkEdit()" style="display: none; box-shadow: 0 8px 18px rgba(22, 199, 154, 0.35); font-weight:700; padding: 12px 22px; min-width:160px;">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>

<?php
// Close database connection at the end
$conn->close();
?>