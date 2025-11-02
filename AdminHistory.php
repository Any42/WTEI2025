<?php
// Include attendance calculations
require_once 'attendance_calculations.php';

// Connect to the database
$host = "localhost";
$user = "root";
$password = "";
$dbname = "wteimain1"; // change this to your actual DB name

$conn = new mysqli($host, $user, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// AJAX flag
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1';
// --- Filter Logic & Query Building --- 
$attendance_records = [];

// Get system date range first
$date_range_sql = "SELECT 
    MIN(DATE(a.attendance_date)) as earliest_date,
    MAX(DATE(a.attendance_date)) as latest_date
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

// Build WHERE conditions for employees
$employee_where = ["e.Status='active'"];
$employee_params = [];
$employee_types = "";

// Apply search filter
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = trim($_GET['search']);
    $employee_where[] = "(e.EmployeeName LIKE ? OR e.EmployeeID LIKE ? OR e.Department LIKE ?)";
    $search_param = "%$search_term%";
    $employee_params[] = $search_param;
    $employee_params[] = $search_param;
    $employee_params[] = $search_param;
    $employee_types .= "sss";
}

// Apply department filter
if (isset($_GET['department']) && !empty($_GET['department'])) {
    $filter_department = $conn->real_escape_string($_GET['department']);
    $employee_where[] = "e.Department = ?";
    $employee_params[] = $filter_department;
    $employee_types .= "s";
}

// Apply shift filter
if (isset($_GET['shift']) && !empty($_GET['shift'])) {
    $filter_shift = $conn->real_escape_string($_GET['shift']);
    $employee_where[] = "e.Shift = ?";
    $employee_params[] = $filter_shift;
    $employee_types .= "s";
}

// Apply attendance_type filter (present/absent)
$filter_attendance_type = '';
if (isset($_GET['attendance_type']) && $_GET['attendance_type'] !== '') {
    $filter_attendance_type = $conn->real_escape_string($_GET['attendance_type']);
    if ($filter_attendance_type === 'present') {
        $employee_where[] = "a.attendance_type = 'present'";
    } elseif ($filter_attendance_type === 'absent') {
        // Include non-existent records as absent too
        $employee_where[] = "(a.attendance_type = 'absent' OR a.id IS NULL)";
    }
}

// Apply status filter (late, early, halfday, on_leave)
$filter_status = '';
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $filter_status = $conn->real_escape_string($_GET['status']);
    if ($filter_status === 'late') {
        // Use late_minutes > 0 to robustly identify late entries regardless of recalculated status
        $employee_where[] = "a.late_minutes > 0";
    } elseif ($filter_status === 'early_in') {
        $employee_where[] = "a.status = 'early_in'";
    } elseif ($filter_status === 'early_out') {
        $employee_where[] = "a.early_out_minutes > 0";
    } elseif ($filter_status === 'halfday') {
        $employee_where[] = "a.status = 'halfday'";
    } elseif ($filter_status === 'on_leave') {
        $employee_where[] = "a.is_on_leave = 1";
    }
}

// Overtime filter
$show_overtime = false;
if (isset($_GET['show_overtime']) && $_GET['show_overtime'] === '1') {
    $show_overtime = true;
}

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

if (isset($_GET['date']) && !empty($_GET['date'])) {
    $query_start_date = $_GET['date'];
    $query_end_date = $_GET['date'];
    $filter_type = 'date';
    $filter_value = $_GET['date'];
} elseif (isset($_GET['month']) && !empty($_GET['month'])) {
    $month_start = $_GET['month'] . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $query_start_date = max($month_start, $system_start_date);
    $query_end_date = min($month_end, $system_end_date);
    $filter_type = 'month';
    $filter_value = $_GET['month'];
} elseif (isset($_GET['year']) && !empty($_GET['year'])) {
    $year_start = $_GET['year'] . '-01-01';
    $year_end = $_GET['year'] . '-12-31';
    $query_start_date = max($year_start, $system_start_date);
    $query_end_date = min($year_end, $system_end_date);
    $filter_type = 'year';
    $filter_value = $_GET['year'];
}

// Generate working days for the query range
$working_days = generateWorkingDays($query_start_date, $query_end_date, $filter_type, $filter_value);

// Pagination settings
$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$per_page = isset($_GET['per_page']) ? $_GET['per_page'] : '50'; // Default to 50 records per page
$records_per_page = $show_all ? 999999 : ($per_page == 'all' ? 999999 : (int)$per_page); // Show selected records per page, or all if show_all=1 or per_page=all
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = $show_all ? 0 : (($current_page - 1) * $records_per_page);

// If filtering by Late, disable pagination to avoid hiding matches across dates
if (isset($filter_status) && $filter_status === 'late' && !$show_all) {
    $show_all = true;
    $records_per_page = 999999;
    $offset = 0;
    $current_page = 1;
}

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
                COALESCE(a.time_in, NULL) as time_in,
                COALESCE(a.time_out, NULL) as time_out,
                COALESCE(a.time_in_morning, NULL) as time_in_morning,
                COALESCE(a.time_out_morning, NULL) as time_out_morning,
                COALESCE(a.time_in_afternoon, NULL) as time_in_afternoon,
                COALESCE(a.time_out_afternoon, NULL) as time_out_afternoon,
                a.notes,
                COALESCE(a.data_source, 'biometric') as data_source,
                COALESCE(a.overtime_hours, 0) as overtime_hours,
                a.overtime_time_in,
                a.overtime_time_out,
                COALESCE(a.is_overtime, 0) as is_overtime,
                COALESCE(a.late_minutes, 0) as late_minutes,
                COALESCE(a.early_out_minutes, 0) as early_out_minutes,
                COALESCE(a.total_hours, 0) as total_hours,
                COALESCE(a.is_on_leave, 0) as is_on_leave,
                COALESCE(a.nsd_ot_hours, 0) as nsd_ot_hours,
                COALESCE(a.is_on_nsdot, 0) as is_on_nsdot,
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
                  " . (!$show_overtime ? " AND (COALESCE(a.is_overtime, 0) = 0 AND COALESCE(a.overtime_hours, 0) = 0)" : "") . "
              ORDER BY wd.working_date DESC, COALESCE(a.time_in, '00:00:00') DESC" . 
              ($show_all ? "" : " LIMIT $records_per_page OFFSET $offset");
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
                a.notes,
                COALESCE(a.data_source, 'biometric') as data_source,
                COALESCE(a.overtime_hours, 0) as overtime_hours,
                a.overtime_time_in,
                a.overtime_time_out,
                COALESCE(a.is_overtime, 0) as is_overtime,
                COALESCE(a.late_minutes, 0) as late_minutes,
                COALESCE(a.early_out_minutes, 0) as early_out_minutes,
                COALESCE(a.total_hours, 0) as total_hours,
                COALESCE(a.is_on_leave, 0) as is_on_leave,
                COALESCE(a.nsd_ot_hours, 0) as nsd_ot_hours,
                COALESCE(a.is_on_nsdot, 0) as is_on_nsdot,
                e.EmployeeName,
                e.Department,
                e.Shift
              FROM attendance a 
              JOIN empuser e ON a.EmployeeID = e.EmployeeID
              WHERE " . implode(' AND ', $employee_where) . "
              AND DATE(a.attendance_date) BETWEEN ? AND ?
              " . (!$show_overtime ? " AND (COALESCE(a.is_overtime, 0) = 0 AND COALESCE(a.overtime_hours, 0) = 0)" : "") . "
              ORDER BY a.attendance_date DESC, a.time_in DESC" . 
              ($show_all ? "" : " LIMIT $records_per_page OFFSET $offset");
}

$stmt = $conn->prepare($query);

if (!$stmt) {
    die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
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
// No extra params for attendance_type (constants inlined)

if (!empty($all_params)) {
    if (!$stmt->bind_param($all_types, ...$all_params)) {
         die("Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error);
    }
}

if(!$stmt->execute()){
    die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
}

$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $attendance_records[] = $row;
    }
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
                        AND wd.working_date >= e.DateHired";
    if (!$show_overtime) { $count_query .= " AND (COALESCE(a.is_overtime, 0) = 0 AND COALESCE(a.overtime_hours, 0) = 0)"; }
} else {
    $count_query = "SELECT COUNT(*) as total_count
                    FROM attendance a 
                    JOIN empuser e ON a.EmployeeID = e.EmployeeID
                    WHERE " . implode(' AND ', $employee_where) . "
                    AND DATE(a.attendance_date) BETWEEN ? AND ?";
    if (!$show_overtime) { $count_query .= " AND (COALESCE(a.is_overtime, 0) = 0 AND COALESCE(a.overtime_hours, 0) = 0)"; }
}

$count_stmt = $conn->prepare($count_query);
if ($count_stmt) {
    if (!empty($working_days)) {
        // For working days query, bind employee parameters if any
        if (!empty($employee_params)) {
            $count_stmt->bind_param($employee_types, ...$employee_params);
        }
    } else {
        // For fallback query, bind all parameters (employee + date)
        $all_count_params = array_merge($employee_params, [$query_start_date, $query_end_date]);
        $all_count_types = $employee_types . "ss";
        if (!empty($all_count_params)) {
            $count_stmt->bind_param($all_count_types, ...$all_count_params);
        }
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result ? $count_result->fetch_assoc()['total_count'] : 0;
    $count_stmt->close();
} else {
    $total_records = 0;
}

// Auto-enable show_all if total records is below minimum pagination threshold
if ($total_records <= 20 && !$show_all) {
    $show_all = true;
    $records_per_page = 999999;
    $offset = 0;
    $current_page = 1;
}

$total_pages = ceil($total_records / $records_per_page);

// Apply accurate calculations to all attendance records
$attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);

// Apply overtime filter
if (!$show_overtime) {
    $attendance_records = array_values(array_filter($attendance_records, function($record) {
        return ($record['overtime_hours'] ?? 0) == 0;
    }));
}

// Apply status filter with robust handling for "late" via late_minutes
if (!empty($filter_status)) {
    $statusFilter = strtolower($filter_status);
    $attendance_records = array_values(array_filter($attendance_records, function($rec) use ($statusFilter) {
        $db_status = strtolower($rec['status'] ?? '');
        switch ($statusFilter) {
            case 'late':
                return (int)($rec['late_minutes'] ?? 0) > 0;
            case 'early_in':
                return $db_status === 'early_in';
            case 'early_out':
                return (int)($rec['early_out_minutes'] ?? 0) > 0;
            case 'halfday':
                return $db_status === 'halfday';
            case 'on_leave':
                return (int)($rec['is_on_leave'] ?? 0) === 1;
            case 'on_time':
                return $db_status === 'on_time';
            default:
                return true;
        }
    }));
}

// Get departments for filter
$departments_query = "SELECT DISTINCT Department FROM empuser ORDER BY Department";
$departments_result_obj = $conn->query($departments_query);
$departments = [];
while ($dept = $departments_result_obj->fetch_assoc()) {
    $departments[] = $dept['Department'];
}


// Calculate summary analytics
$recorded_days = count($working_days);

// Count records including generated absent records
$analytics_where = ["e.Status='active'"];
$analytics_params = [];
$analytics_types = "";

// Add employee filters
if (isset($_GET['department']) && !empty($_GET['department'])) {
    $analytics_where[] = "e.Department = ?";
    $analytics_params[] = $_GET['department'];
    $analytics_types .= "s";
}
if (isset($_GET['shift']) && !empty($_GET['shift'])) {
    $analytics_where[] = "e.Shift = ?";
    $analytics_params[] = $_GET['shift'];
    $analytics_types .= "s";
}
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $analytics_where[] = "(e.EmployeeName LIKE ? OR e.EmployeeID LIKE ?)";
    $search_param = "%" . trim($_GET['search']) . "%";
    $analytics_params[] = $search_param;
    $analytics_params[] = $search_param;
    $analytics_types .= "ss";
}

// Use the same query logic as the main query for analytics
if (!empty($working_days)) {
    // Use the same working days logic for analytics
    $analytics_query = "SELECT 
        COALESCE(a.id, 0) as id,
        COALESCE(a.attendance_type, 'absent') as attendance_type,
        COALESCE(a.status, 'no_record') as status,
        COALESCE(a.is_overtime, 0) as is_overtime,
        COALESCE(a.overtime_hours, 0) as overtime_hours,
        COALESCE(a.early_out_minutes, 0) as early_out_minutes
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
            AND wd.working_date >= e.DateHired" .
        (!empty($filter_attendance_type) ? (
            $filter_attendance_type === 'present' ? " AND a.attendance_type = 'present'" : " AND (a.attendance_type = 'absent' OR a.id IS NULL)"
        ) : "") .
        (!empty($filter_status) ? (
            $filter_status === 'late' ? " AND a.late_minutes > 0" : 
            ($filter_status === 'early_in' ? " AND a.status = 'early_in'" : 
            ($filter_status === 'early_out' ? " AND a.early_out_minutes > 0" : 
            ($filter_status === 'halfday' ? " AND a.status = 'halfday'" : 
            ($filter_status === 'on_leave' ? " AND a.is_on_leave = 1" : ""))))
        ) : "");
} else {
    // For default - count only actual records
    $analytics_query = "SELECT 
        a.id,
        a.attendance_type,
        a.status,
        COALESCE(a.is_overtime, 0) as is_overtime,
        COALESCE(a.overtime_hours, 0) as overtime_hours,
        COALESCE(a.early_out_minutes, 0) as early_out_minutes
        FROM attendance a 
        JOIN empuser e ON a.EmployeeID = e.EmployeeID
        WHERE " . implode(' AND ', $analytics_where) .
        (!empty($filter_attendance_type) ? (
            $filter_attendance_type === 'present' ? " AND a.attendance_type = 'present'" : " AND (a.attendance_type = 'absent' OR a.id IS NULL)"
        ) : "") .
        (!empty($filter_status) ? (
            $filter_status === 'late' ? " AND a.late_minutes > 0" : 
            ($filter_status === 'early_in' ? " AND a.status = 'early_in'" : 
            ($filter_status === 'early_out' ? " AND a.early_out_minutes > 0" : 
            ($filter_status === 'halfday' ? " AND a.status = 'halfday'" : 
            ($filter_status === 'on_leave' ? " AND a.is_on_leave = 1" : ""))))
        ) : "") . "
        AND DATE(a.attendance_date) BETWEEN ? AND ?";
    $analytics_params[] = $query_start_date;
    $analytics_params[] = $query_end_date;
    $analytics_types .= "ss";
}

$analytics_stmt = $conn->prepare($analytics_query);
if ($analytics_stmt) {
    if (!empty($analytics_params)) {
        $analytics_stmt->bind_param($analytics_types, ...$analytics_params);
    }
    $analytics_stmt->execute();
    $analytics_result = $analytics_stmt->get_result();
    
    $total_records = 0;
    $present_count = 0;
    $absent_count = 0;
    $late_count = 0;
    $early_count = 0;
    $overtime_count = 0;
    
    while ($row = $analytics_result->fetch_assoc()) {
        $total_records++;
        if ($row['attendance_type'] === 'present' || $row['status'] === 'halfday') {
            $present_count++;
        } else {
            $absent_count++;
        }
        
        // For halfday filter, only show total and present records, others should be 0
        if ($filter_status === 'halfday') {
            // Only count records that match the halfday filter
            if ($row['id'] > 0 && $row['status'] === 'halfday') {
                $late_count = 0;
                $early_count = 0;
                $overtime_count = 0;
            }
        } else {
            // Only count late if there's an actual attendance record (not generated absent)
            if ($row['id'] > 0 && (int)($row['late_minutes'] ?? 0) > 0) {
                $late_count++;
            }
            // Only count early if there's an actual attendance record
            if ($row['id'] > 0 && $row['status'] === 'early_in') {
                $early_count++;
            }
            if ($row['is_overtime'] == 1 || $row['overtime_hours'] > 0) {
                $overtime_count++;
            }
        }
    }
    
    $summary_data = [
        'total_records' => $total_records,
        'present_count' => $present_count,
        'absent_count' => $absent_count,
        'late_count' => $late_count,
        'early_count' => $early_count,
        'overtime_count' => $overtime_count,
        'recorded_days' => $recorded_days
    ];
    
    $analytics_stmt->close();
} else {
    $summary_data = ['total_records' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'early_count' => 0, 'overtime_count' => 0, 'recorded_days' => 0];
}

// Today analytics: Get accurate counts for today
$today = date('Y-m-d');
$today_dept_condition = "";
$today_dept_params = [];
$today_dept_types = "";

if (isset($_GET['department']) && !empty($_GET['department'])) {
    $today_dept_condition = "AND e.Department = ?";
    $today_dept_params[] = $conn->real_escape_string($_GET['department']);
    $today_dept_types = "s";
}

if (isset($_GET['shift']) && !empty($_GET['shift'])) {
    $today_dept_condition .= " AND e.Shift = ?";
    $today_dept_params[] = $conn->real_escape_string($_GET['shift']);
    $today_dept_types .= "s";
}

// Get total active employees for today
$total_employees_query = "SELECT COUNT(*) as total FROM empuser e WHERE e.Status = 'active' $today_dept_condition";
$total_employees_stmt = $conn->prepare($total_employees_query);
if ($total_employees_stmt) {
    if (!empty($today_dept_params)) {
        $total_employees_stmt->bind_param($today_dept_types, ...$today_dept_params);
    }
    $total_employees_stmt->execute();
    $total_employees_result = $total_employees_stmt->get_result();
    $total_employees_today = $total_employees_result ? $total_employees_result->fetch_assoc()['total'] : 0;
    $total_employees_stmt->close();
} else {
    $total_employees_today = 0;
}

// Get present employees for today
$present_conditions = ["DATE(a.attendance_date) = ?", "(a.attendance_type = 'present' OR a.status = 'halfday')"];
$present_params = [$today];
$present_types = "s";

if (isset($_GET['department']) && !empty($_GET['department'])) {
    $present_conditions[] = "e.Department = ?";
    $present_params[] = $conn->real_escape_string($_GET['department']);
    $present_types .= "s";
}
if (isset($_GET['shift']) && !empty($_GET['shift'])) {
    $present_conditions[] = "e.Shift = ?";
    $present_params[] = $conn->real_escape_string($_GET['shift']);
    $present_types .= "s";
}
// Status filter removed

$present_where = implode(' AND ', $present_conditions);
$present_query = "SELECT COUNT(DISTINCT a.EmployeeID) as present_count FROM attendance a JOIN empuser e ON a.EmployeeID = e.EmployeeID WHERE $present_where";
$present_stmt = $conn->prepare($present_query);
if ($present_stmt) {
    $present_stmt->bind_param($present_types, ...$present_params);
    $present_stmt->execute();
    $present_result = $present_stmt->get_result();
    $present_today = $present_result ? $present_result->fetch_assoc()['present_count'] : 0;
    $present_stmt->close();
} else {
    $present_today = 0;
}

// Calculate absent (total employees - present employees)
$absent_today = max(0, $total_employees_today - $present_today);

// Get late and early counts for today
$late_early_conditions = ["DATE(a.attendance_date) = ?"];
$late_early_params = [$today];
$late_early_types = "s";

if (isset($_GET['department']) && !empty($_GET['department'])) {
    $late_early_conditions[] = "e.Department = ?";
    $late_early_params[] = $conn->real_escape_string($_GET['department']);
    $late_early_types .= "s";
}
if (isset($_GET['shift']) && !empty($_GET['shift'])) {
    $late_early_conditions[] = "e.Shift = ?";
    $late_early_params[] = $conn->real_escape_string($_GET['shift']);
    $late_early_types .= "s";
}

$late_early_where = implode(' AND ', $late_early_conditions);
$late_early_query = "SELECT 
    SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END) as late_today,
    SUM(CASE WHEN a.status = 'early' THEN 1 ELSE 0 END) as early_today
    FROM attendance a 
    JOIN empuser e ON a.EmployeeID = e.EmployeeID 
    WHERE $late_early_where";
$late_early_stmt = $conn->prepare($late_early_query);
if ($late_early_stmt) {
    $late_early_stmt->bind_param($late_early_types, ...$late_early_params);
    $late_early_stmt->execute();
    $late_early_result = $late_early_stmt->get_result();
    $late_early_data = $late_early_result ? $late_early_result->fetch_assoc() : ['late_today' => 0, 'early_today' => 0];
    $late_early_stmt->close();
} else {
    $late_early_data = ['late_today' => 0, 'early_today' => 0];
}

$today_data = [
    'present_today' => $present_today,
    'absent_today' => $absent_today,
    'late_today' => $late_early_data['late_today'],
    'early_today' => $late_early_data['early_today']
];


$conn->close();

// If AJAX request, return JSON payload and exit
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'records' => $attendance_records,
        'summary' => [
            'total_records' => (int)($summary_data['total_records'] ?? 0),
            'present_count' => (int)($summary_data['present_count'] ?? 0),
            'absent_count' => (int)($summary_data['absent_count'] ?? 0),
            'late_count' => (int)($summary_data['late_count'] ?? 0),
            'early_count' => (int)($summary_data['early_count'] ?? 0),
            'overtime_count' => (int)($summary_data['overtime_count'] ?? 0)
        ]
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History</title>
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
            z-index: 100;
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
            padding: 12px 20px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(63, 114, 175, 0.3);
        }

        .btn-secondary {
            background-color: #DBE2EF;
            color: #112D4E;
        }

        .btn-secondary:hover {
            background-color: #3F72AF;
            color: white;
            transform: translateY(-2px);
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
            gap: 10px;
            padding: 6px;
            border: 1px solid #DBE2EF;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(17,45,78,0.06);
        }

        .date-filter-btn {
            background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
            color: #26415f;
            border: 1px solid #DBE2EF;
            padding: 12px 18px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
            letter-spacing: .2px;
            border-radius: 10px;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(17,45,78,0.06);
        }

        /* keep for future overrides */

        .date-filter-btn.active {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: #fff;
            border-color: #3F72AF;
            box-shadow: 0 8px 18px rgba(17,45,78,0.22);
            transform: translateY(-1px);
        }

        .date-filter-btn.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, rgba(255,255,255,0.6), rgba(255,255,255,0));
        }

        .date-filter-btn.active i {
            color: white;
        }

        .date-filter-btn:not(.active):hover {
            background: linear-gradient(180deg, #F8FBFF 0%, #EEF5FF 100%);
            transform: translateY(-1px);
            border-color: #c8d8f0;
            box-shadow: 0 6px 14px rgba(63, 114, 175, 0.18);
        }

        .date-filter-btn:not(.active):hover i {
            color: #3F72AF;
        }

        .filter-input-area {
            padding: 16px 0;
            border-top: 1px solid #DBE2EF;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-start;
        }

        .filter-input-area > div {
            padding-top: 15px;
        }

        .date-input-container label,
        .month-input-container label,
        .year-input-container label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            font-weight: 700;
            font-size: 13px;
            color: #0f2a46;
            letter-spacing: .3px;
            text-transform: uppercase;
        }

        .date-input-container label i,
        .month-input-container label i,
        .year-input-container label i {
            color: #3F72AF;
            font-size: 16px;
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
        .filter-option input,
        .date-input-container input[type="date"],
        .month-input-container input[type="month"],
        .year-input-container select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #DBE2EF;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s ease;
            background: linear-gradient(180deg, #ffffff 0%, #f7faff 100%);
            box-shadow: 0 2px 6px rgba(17, 45, 78, 0.06);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='%233F72AF' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Cline x1='16' y1='2' x2='16' y2='6'%3E%3C/line%3E%3Cline x1='8' y1='2' x2='8' y2='6'%3E%3C/line%3E%3Cline x1='3' y1='10' x2='21' y2='10'%3E%3C/line%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 18px 18px;
            padding-right: 42px;
            height: 44px;
        }

        /* Compact widths for date/month/year pickers */
        .date-input-container,
        .month-input-container,
        .year-input-container {
            width: 240px;
            max-width: 100%;
        }

        .filter-option select:focus,
        .filter-option input:focus,
        .date-input-container input[type="date"]:focus,
        .month-input-container input[type="month"]:focus,
        .year-input-container select:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.15);
            transform: translateY(-1px);
        }

        /* Hide default picker icon for better custom look */
        /* Keep native picker indicators visible for usability */
        .date-input-container input[type="date"]::-webkit-calendar-picker-indicator,
        .month-input-container input[type="month"]::-webkit-calendar-picker-indicator {
            opacity: 1;
        }

        /* Aesthetic custom dropdown for selects */
        select.form-control,
        .filter-option select,
        .year-input-container select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%233F72AF' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px 16px;
            padding-right: 40px;
        }

        select.form-control:hover,
        .filter-option select:hover,
        .year-input-container select:hover {
            border-color: #c8d8f0;
            box-shadow: 0 4px 10px rgba(63,114,175,0.12);
        }

        /* Polished date/month/year pickers */
        .date-input-container,
        .month-input-container,
        .year-input-container {
            background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
            border: 1px solid rgba(219,226,239,0.6);
            border-radius: 14px;
            padding: 16px;
            width: 320px;
            max-width: 100%;
            display: inline-block;
            box-shadow: 0 6px 16px rgba(17,45,78,0.06);
        }

        .date-input-container:hover,
        .month-input-container:hover,
        .year-input-container:hover {
            box-shadow: 0 10px 22px rgba(17,45,78,0.10);
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

        .status-halfday {
            background-color: rgba(33, 150, 243, 0.15);
            color: #2196F3;
        }

        .status-on_time {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 235, 59, 0.2));
            color: #FF8F00;
            box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3);
        }

        .attendance-type {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 12px;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .attendance-type.regular {
            background-color: rgba(63, 114, 175, 0.15);
            color: #3F72AF;
        }

        .attendance-type.late-arrival {
            background-color: rgba(255, 152, 0, 0.15);
            color: #f57c00;
        }

        .attendance-type.absent {
            background-color: rgba(255, 107, 107, 0.15);
            color: #FF6B6B;
        }

        .attendance-type.absent-no-record {
            background-color: rgba(255, 107, 107, 0.25);
            color: #FF4757;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .attendance-type.overtime {
            background-color: rgba(22, 199, 154, 0.15);
            color: #16C79A;
        }

        .attendance-type.early-departure {
            background-color: rgba(255, 199, 95, 0.15);
            color: #FFC75F;
        }

        .attendance-type.full-day {
            background-color: rgba(63, 114, 175, 0.15);
            color: #3F72AF;
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
        .view-toggle .btn i { color: inherit; }
        .view-toggle .btn + .btn { border-left: 1px solid #DBE2EF; }
        .view-toggle .btn.active {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: #fff;
        }
        .view-toggle .btn:not(.active):hover { background: #F8FBFF; }
        .view-toggle .btn:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
        .view-toggle .btn:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }

        /* Employee Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(17, 45, 78, 0.8);
            backdrop-filter: blur(8px);
            z-index: 1000;
            animation: fadeIn 0.3s ease;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow: hidden;
            animation: slideInUp 0.4s ease;
            border: 1px solid rgba(63, 114, 175, 0.2);
        }

        .modal-header {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .modal-header h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }
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

        .modal-header .close {
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            padding: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .modal-header .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }

        .modal-body {
            padding: 30px;
            max-height: 60vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 20px 30px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        /* Custom scrollbar for modal */
        .modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }

        .modal-body::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Employee Details Styles */
        .employee-details {
            display: grid;
            gap: 25px;
        }

        .employee-profile {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 25px;
            background: linear-gradient(135deg, rgba(63, 114, 175, 0.1) 0%, rgba(17, 45, 78, 0.05) 100%);
            border-radius: 15px;
            border-left: 4px solid #3F72AF;
        }

        .employee-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            box-shadow: 0 8px 20px rgba(63, 114, 175, 0.3);
        }

        .employee-info h3 {
            margin: 0 0 8px 0;
            color: #112D4E;
            font-size: 24px;
            font-weight: 600;
        }

        .employee-info p {
            margin: 4px 0;
            color: #6c757d;
            font-size: 16px;
        }

        .attendance-details {
            display: grid;
            gap: 20px;
        }

        .detail-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            border-left: 3px solid #3F72AF;
        }

        .detail-section h4 {
            margin: 0 0 15px 0;
            color: #112D4E;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .detail-section h4 i {
            color: #3F72AF;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .detail-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #3F72AF;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .detail-item .label {
            font-size: 12px;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .detail-item .value {
            font-size: 16px;
            font-weight: 600;
            color: #2D3748;
        }

        .detail-item.present { border-left-color: #16C79A; }
        .detail-item.absent { border-left-color: #FF6B6B; }
        .detail-item.late { border-left-color: #FFC75F; }
        .detail-item.early { border-left-color: #FF6B6B; }
        .detail-item.overtime { border-left-color: #16C79A; }
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

        /* Modern Elegant Toggle Switch Styles */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 90px;
            height: 40px;
            margin-top: 8px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-label {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(145deg, #f0f0f0, #e0e0e0);
            transition: all 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            border-radius: 40px;
            box-shadow: 
                inset 0 2px 4px rgba(0,0,0,0.1),
                0 4px 8px rgba(0,0,0,0.1),
                0 1px 3px rgba(0,0,0,0.1);
            border: 3px solid #f8f9fa;
        }

        .toggle-label:before {
            position: absolute;
            content: "";
            height: 32px;
            width: 32px;
            left: 4px;
            bottom: 4px;
            background: linear-gradient(145deg, #ffffff, #f8f9fa);
            transition: all 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            border-radius: 50%;
            box-shadow: 
                0 4px 8px rgba(0,0,0,0.2),
                0 2px 4px rgba(0,0,0,0.1),
                inset 0 1px 0 rgba(255,255,255,0.8);
        }

        .toggle-label:after {
            content: "OFF";
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            font-size: 11px;
            font-weight: 700;
            color: #6c757d;
            transition: all 0.3s ease;
            text-shadow: 0 1px 2px rgba(255,255,255,0.8);
        }

        input:checked + .toggle-label {
            background: linear-gradient(145deg, #007bff, #0056b3);
            box-shadow: 
                inset 0 2px 4px rgba(0,0,0,0.2),
                0 6px 12px rgba(0,123,255,0.4),
                0 2px 4px rgba(0,123,255,0.2);
            border-color: #007bff;
        }

        input:checked + .toggle-label:before {
            transform: translateX(50px);
            background: linear-gradient(145deg, #ffffff, #f8f9fa);
            box-shadow: 
                0 4px 8px rgba(0,0,0,0.3),
                0 2px 4px rgba(0,0,0,0.2),
                inset 0 1px 0 rgba(255,255,255,0.9);
        }

        input:checked + .toggle-label:after {
            content: "ON";
            color: white;
            right: 14px;
            text-shadow: 0 1px 3px rgba(0,0,0,0.5);
            font-weight: 800;
        }

        .filter-option .toggle-switch {
            margin-top: 5px;
        }

        .filter-option .toggle-switch:hover .toggle-label {
            box-shadow: 
                inset 0 2px 4px rgba(0,0,0,0.1),
                0 6px 12px rgba(0,0,0,0.15),
                0 2px 4px rgba(0,0,0,0.1);
            transform: translateY(-1px);
        }

        .filter-option .toggle-switch:hover input:checked + .toggle-label {
            box-shadow: 
                inset 0 2px 4px rgba(0,0,0,0.2),
                0 8px 16px rgba(0,123,255,0.5),
                0 3px 6px rgba(0,123,255,0.3);
            transform: translateY(-1px);
        }

        .filter-option .toggle-switch:active .toggle-label {
            transform: translateY(0);
        }

        /* Loading state for AJAX */
        .toggle-switch.loading .toggle-label {
            opacity: 0.8;
            pointer-events: none;
        }

        .toggle-switch.loading .toggle-label:before {
            animation: pulse 1.2s ease-in-out infinite;
        }

        .toggle-switch.loading .toggle-label:after {
            content: "...";
            animation: dots 1.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { 
                transform: scale(1);
                box-shadow: 0 4px 8px rgba(0,0,0,0.2), 0 2px 4px rgba(0,0,0,0.1);
            }
            50% { 
                transform: scale(1.05);
                box-shadow: 0 6px 12px rgba(0,0,0,0.3), 0 3px 6px rgba(0,0,0,0.2);
            }
        }

        @keyframes dots {
            0%, 20% { content: "..."; }
            40% { content: ".."; }
            60% { content: "."; }
            80%, 100% { content: ""; }
        }

        /* Focus state for accessibility */
        .toggle-switch input:focus + .toggle-label {
            outline: 2px solid #007bff;
            outline-offset: 2px;
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
<div class="sidebar">
        <img src="LOGO/newLogo_transparent.png" class="logo" style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding:-250px; padding-bottom: 20px;">
        <div class="menu">
            <a href="AdminHome.php" class="menu-item">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="AdminEmployees.php" class="menu-item ">
                <i class="fas fa-users"></i> Employees
            </a>
            <a href="AdminAttendance.php" class="menu-item">
                <i class="fas fa-calendar-check"></i> Attendance
            </a>
            <a href="AdminPayroll.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i> Payroll
            </a>
            <a href="AdminHistory.php" class="menu-item active">
                <i class="fas fa-history"></i> History
            </a>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>


    <div class="main-content">
        <!-- Replace the header section with this -->
<div class="header">
    <h1>History</h1>
    <div class="header-actions">
        <div class="view-toggle btn-group">
            <button type="button" class="btn btn-primary active" id="attendanceBtn" onclick="showAttendance()">
                <i class="fas fa-calendar-check"></i> Attendance
            </button>
            <button type="button" class="btn btn-primary" id="payrollBtn" onclick="showPayroll()">
                <i class="fas fa-money-bill-wave"></i> Payroll
            </button>
        </div>
    </div>
 </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon present"><i class="fas fa-database"></i></div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo (int)($summary_data['total_records'] ?? 0); ?></div>
                    <div class="summary-label">
                        Total Records
                        <?php 
                        $filter_info = [];
                        if (isset($_GET['date']) && !empty($_GET['date'])) {
                            $filter_info[] = "Date: " . date('M d, Y', strtotime($_GET['date']));
                        } elseif (isset($_GET['month']) && !empty($_GET['month'])) {
                            $filter_info[] = "Month: " . date('F Y', strtotime($_GET['month'] . '-01'));
                        } elseif (isset($_GET['year']) && !empty($_GET['year'])) {
                            $filter_info[] = "Year: " . $_GET['year'];
                        }
                        if (isset($_GET['department']) && !empty($_GET['department'])) {
                            $filter_info[] = "Dept: " . $_GET['department'];
                        }
                        if (isset($_GET['attendance_type']) && !empty($_GET['attendance_type'])) {
                            $filter_info[] = "Type: " . ucfirst($_GET['attendance_type']);
                        }
                        if (isset($_GET['status']) && !empty($_GET['status'])) {
                            $status_display = $_GET['status'] === 'early_in' ? 'Early In' : 
                                            ($_GET['status'] === 'early_out' ? 'Early Out' : ucfirst($_GET['status']));
                            $filter_info[] = "Status: " . $status_display;
                        }
                        if (!empty($filter_info)) {
                            echo "<br><small>(" . implode(', ', $filter_info) . ")</small>";
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon present"><i class="fas fa-check-circle"></i></div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo (int)($summary_data['present_count'] ?? 0); ?></div>
                    <div class="summary-label">
                        Present Records
                        <?php 
                        // Show today's data if viewing today or no date filter
                        $show_today = (!isset($_GET['date']) || $_GET['date'] === date('Y-m-d')) && 
                                     !isset($_GET['month']) && !isset($_GET['year']);
                        if ($show_today): ?>
                            <br><small>(Today: <?php echo (int)($today_data['present_today'] ?? 0); ?>)</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon absent"><i class="fas fa-times-circle"></i></div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo (int)($summary_data['absent_count'] ?? 0); ?></div>
                    <div class="summary-label">
                        Absent Records
                        <?php if ($show_today): ?>
                            <br><small>(Today: <?php echo (int)($today_data['absent_today'] ?? 0); ?>)</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon late"><i class="fas fa-clock"></i></div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo (int)($summary_data['late_count'] ?? 0); ?></div>
                    <div class="summary-label">
                        Late Records
                        <?php if ($show_today): ?>
                            <br><small>(Today: <?php echo (int)($today_data['late_today'] ?? 0); ?>)</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon rate"><i class="fas fa-arrow-up"></i></div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo (int)($summary_data['early_count'] ?? 0); ?></div>
                    <div class="summary-label">
                        Early Records
                        <?php if ($show_today): ?>
                            <br><small>(Today: <?php echo (int)($today_data['early_today'] ?? 0); ?>)</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Filter Section -->
        <div class="filter-container card">
            <form method="GET" action="">
                <div class="filter-controls">
                    <div class="search-box" style="width: 100%; margin-bottom: 20px;">
                        <input type="text" name="search" placeholder="Search by employee name or ID..." 
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                        <button type="submit" class="btn btn-primary" style="position: absolute; right: 0; top: 0; height: 100%; border-radius: 0 12px 12px 0;">
                            <i class="fas fa-search"></i> Search  
                        </button>
                    </div>

                    <div class="filter-mode-buttons">
                        <button type="button" class="date-filter-btn active" data-filter="date">
                            <i class="fas fa-calendar-day"></i> Select Date
                        </button>
                        <button type="button" class="date-filter-btn" data-filter="month">
                            <i class="fas fa-calendar-alt"></i> Select Month
                        </button>
                        <button type="button" class="date-filter-btn" data-filter="year">
                            <i class="fas fa-calendar"></i> Select Year
                        </button>
                    </div>

                    <div class="filter-input-area">
                        <div class="date-input-container" id="dateFilter">
                            <label for="date"><i class="fas fa-calendar-day"></i> Select Date:</label>
                            <input type="date" name="date" id="date" class="form-control" 
                                   value="<?php echo isset($_GET['date']) ? htmlspecialchars($_GET['date']) : ''; ?>">
                        </div>

                        <div class="month-input-container" id="monthFilter" style="display: none;">
                            <label for="month"><i class="fas fa-calendar-alt"></i> Select Month:</label>
                            <input type="month" name="month" id="month" class="form-control" 
                                   value="<?php echo isset($_GET['month']) ? htmlspecialchars($_GET['month']) : ''; ?>">
                        </div>

                        <div class="year-input-container" id="yearFilter" style="display: none;">
                            <label for="year"><i class="fas fa-calendar"></i> Select Year:</label>
                            <select name="year" id="year" class="form-control">
                                <option value="">All Years</option>
                                <?php
                                $current_year = date('Y');
                                for ($year = $current_year; $year >= 2000; $year--) {
                                    $selected = (isset($_GET['year']) && $_GET['year'] == $year) ? 'selected' : '';
                                    echo "<option value='$year' $selected>$year</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="filter-options">
                        <div class="filter-option">
                            <label for="department">Department:</label>
                            <select name="department" id="department" class="form-control">
                                <option value="">All Departments</option>
                                <?php
                                foreach ($departments as $dept) {
                                    $selected = (isset($_GET['department']) && $_GET['department'] == $dept) ? 'selected' : '';
                                    echo "<option value='$dept' $selected>$dept</option>";
                                }
                                ?>
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
                            <label for="status">Status:</label>
                            <select name="status" id="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="late" <?php echo (isset($_GET['status']) && $_GET['status'] == 'late') ? 'selected' : ''; ?>>Late</option>
                                <option value="early_in" <?php echo (isset($_GET['status']) && $_GET['status'] == 'early_in') ? 'selected' : ''; ?>>Early In</option>
                                <option value="early_out" <?php echo (isset($_GET['status']) && $_GET['status'] == 'early_out') ? 'selected' : ''; ?>>Early Out</option>
                                <option value="halfday" <?php echo (isset($_GET['status']) && $_GET['status'] == 'halfday') ? 'selected' : ''; ?>>Half Day</option>
                                <option value="on_leave" <?php echo (isset($_GET['status']) && $_GET['status'] == 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                            </select>
                        </div>

                        <div class="filter-option">
                            <label for="shift">Shift:</label>
                            <select name="shift" id="shift" class="form-control">
                                <option value="">All Shifts</option>
                                <option value="08:00-17:00" <?php echo (isset($_GET['shift']) && $_GET['shift'] === '08:00-17:00') ? 'selected' : ''; ?>>8:00 AM - 5:00 PM</option>
                                <option value="08:30-17:30" <?php echo (isset($_GET['shift']) && $_GET['shift'] === '08:30-17:30') ? 'selected' : ''; ?>>8:30 AM - 5:30 PM</option>
                                <option value="09:00-18:00" <?php echo (isset($_GET['shift']) && $_GET['shift'] === '09:00-18:00') ? 'selected' : ''; ?>>9:00 AM - 6:00 PM</option>
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
                        <a href="AdminHistory.php" class="btn btn-secondary">
                            <i class="fas fa-sync-alt"></i> Reset All Filters
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Results Table -->
        <div class="results-container" id="attendanceResults">
            <div class="results-header">
                <h2>Attendance Records</h2>
                <div class="results-actions">
                </div>
            </div>

            <?php
            // Helper function to get lunch start time from shift
            function getLunchStartFromShift($shift) {
                if (empty($shift)) return '12:00:00';
                
                // Parse shift like "08:30-17:30" or "8:30-5:30pm"
                if (preg_match('/(\d{1,2}):?(\d{2})?\s*-\s*(\d{1,2}):?(\d{2})?/', $shift, $matches)) {
                    $startHour = (int)$matches[1];
                    // Default lunch times based on shift start
                    if ($startHour <= 8) {
                        return '12:00:00';
                    } elseif ($startHour <= 8.5) {
                        return '12:30:00';
                    } else {
                        return '13:00:00';
                    }
                }
                return '12:00:00';
            }
            
            // Helper function to convert time string to minutes for comparison
            function timeToMinutes($timeStr) {
                if (empty($timeStr)) return null;
                // Handle formats like "12:30", "12:30:00", "12:30 PM"
                if (preg_match('/(\d{1,2}):(\d{2})/', $timeStr, $matches)) {
                    $hours = (int)$matches[1];
                    $minutes = (int)$matches[2];
                    // Handle 12-hour format
                    if (stripos($timeStr, 'pm') !== false && $hours != 12) {
                        $hours += 12;
                    } elseif (stripos($timeStr, 'am') !== false && $hours == 12) {
                        $hours = 0;
                    }
                    return $hours * 60 + $minutes;
                }
                return null;
            }
            
            // Helper function to format data source
            function formatDataSource($dataSource) {
                $dataSource = $dataSource ?? 'biometric';
                switch($dataSource) {
                    case 'manual':
                        return 'Manual Entry by HR';
                    case 'bulk_edit':
                        return 'BulkEditAttendance';
                    case 'biometric':
                    default:
                        return 'Biometric';
                }
            }
            
            // Helper function to detect halfday type
            function detectHalfdayType($record) {
                $shift = $record['Shift'] ?? '';
                $isHalfDay = in_array(strtolower($record['status'] ?? ''), ['half_day', 'halfday']) || 
                             (isset($record['total_hours']) && $record['total_hours'] > 0 && $record['total_hours'] <= 4.0);
                
                $isAfternoonOnly = false;
                $isMorningOnly = false;
                
                if ($isHalfDay) {
                    $lunchStart = getLunchStartFromShift($shift);
                    $lunchStartMinutes = timeToMinutes($lunchStart);
                    
                    $hasMorningIn = !empty($record['time_in_morning']);
                    $hasMorningOut = !empty($record['time_out_morning']);
                    $hasAfternoonIn = !empty($record['time_in_afternoon']);
                    $hasAfternoonOut = !empty($record['time_out_afternoon']);
                    
                    // Get times for comparison
                    $timeInMorningMinutes = timeToMinutes($record['time_in_morning'] ?? '');
                    $timeOutMorningMinutes = timeToMinutes($record['time_out_morning'] ?? '');
                    $timeInAfternoonMinutes = timeToMinutes($record['time_in_afternoon'] ?? '');
                    $actualTimeIn = $record['time_in'] ?? $record['time_in_morning'] ?? $record['time_in_afternoon'] ?? '';
                    $actualTimeInMinutes = timeToMinutes($actualTimeIn);
                    
                    // Check if morning session is valid (time_in_morning must be before lunch start)
                    $isValidMorningSession = false;
                    if ($hasMorningIn && $lunchStartMinutes !== null && $timeInMorningMinutes !== null) {
                        // Morning time_in must be before lunch start to be valid
                        $isValidMorningSession = ($timeInMorningMinutes < $lunchStartMinutes);
                    }
                    
                    // Afternoon-only detection:
                    // 1. Most reliable: has afternoon session but no valid morning out
                    // 2. Actual time_in is at/after lunch start
                    // 3. Has afternoon_in/out but morning_in is invalid (at/after lunch start)
                    if ($hasAfternoonIn || $hasAfternoonOut) {
                        if (!$hasMorningOut || ($hasMorningIn && !$isValidMorningSession)) {
                            // Has afternoon session but no morning out, OR morning_in is invalid
                            $isAfternoonOnly = true;
                        }
                    } elseif ($actualTimeInMinutes !== null && $lunchStartMinutes !== null && $actualTimeInMinutes >= $lunchStartMinutes) {
                        // Time in is at/after lunch start
                        $isAfternoonOnly = true;
                    } elseif ($hasMorningIn && !$isValidMorningSession) {
                        // Morning_in exists but is invalid (at/after lunch start)
                        $isAfternoonOnly = true;
                    }
                    
                    // Morning-only: has valid morning session but no afternoon session
                    if ($isValidMorningSession && $hasMorningOut && !$hasAfternoonIn && !$hasAfternoonOut) {
                        $isMorningOnly = true;
                    }
                }
                
                return [
                    'isHalfDay' => $isHalfDay,
                    'isAfternoonOnly' => $isAfternoonOnly,
                    'isMorningOnly' => $isMorningOnly
                ];
            }
            ?>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Shift</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Total Hours</th>
                            <th>Status</th>
                            <th>Attendance Type</th>
                            <th>Source</th>
                            <th>Late Minutes</th>
                            <th>Early Out Minutes</th>
                            <th>Overtime Hours</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($attendance_records)): ?>
                            <?php foreach ($attendance_records as $record): ?>
                                <tr onclick="openEmployeeModal('<?php echo htmlspecialchars($record['EmployeeID']); ?>', '<?php echo htmlspecialchars($record['EmployeeName']); ?>', '<?php echo htmlspecialchars($record['attendance_date']); ?>')" style="cursor: pointer;">
                                    <td><?php echo htmlspecialchars($record['attendance_date']); ?></td>
                                    <td><?php echo htmlspecialchars($record['EmployeeName']); ?></td>
                                    <td><?php echo htmlspecialchars($record['Department']); ?></td>
                                    <td><?php 
                                        // Convert shift from military time to 12-hour format
                                        $shift = $record['Shift'];
                                        if (!empty($shift) && preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $shift, $matches)) {
                                            $start_hour = (int)$matches[1];
                                            $start_min = $matches[2];
                                            $end_hour = (int)$matches[3];
                                            $end_min = $matches[4];
                                            
                                            // Convert start time
                                            $start_period = $start_hour >= 12 ? 'PM' : 'AM';
                                            $start_display = ($start_hour % 12 == 0 ? 12 : $start_hour % 12);
                                            
                                            // Convert end time
                                            $end_period = $end_hour >= 12 ? 'PM' : 'AM';
                                            $end_display = ($end_hour % 12 == 0 ? 12 : $end_hour % 12);
                                            
                                            echo htmlspecialchars($start_display . ':' . $start_min . ' ' . $start_period . ' - ' . $end_display . ':' . $end_min . ' ' . $end_period);
                                        } else {
                                            echo htmlspecialchars($shift);
                                        }
                                    ?></td>
                                    <td><?php echo !empty($record['time_in']) ? date('g:i A', strtotime($record['time_in'])) : '-'; ?></td>
                                    <td><?php echo !empty($record['time_out']) ? date('g:i A', strtotime($record['time_out'])) : '-'; ?></td>
                                    <td>
                                        <?php 
                                        // Don't show total hours if employee is on leave
                                        if (($record['is_on_leave'] ?? 0) == 1) {
                                            echo '-';
                                        } else {
                                            $total_hours = isset($record['total_hours']) ? $record['total_hours'] : 0;
                                            if ($total_hours > 0) {
                                                echo number_format($total_hours, 2) . ' hrs';
                                            } else {
                                                echo '-';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        // Detect halfday first to avoid showing "Late" status for halfdays
                                        $halfdayInfoForStatus = detectHalfdayType($record);
                                        $isHalfDayForStatus = $halfdayInfoForStatus['isHalfDay'];
                                        $isAfternoonOnlyForStatus = $halfdayInfoForStatus['isAfternoonOnly'];
                                        $showLateStatus = !($isHalfDayForStatus && $isAfternoonOnlyForStatus);
                                        ?>
                                        <?php if (($record['is_on_leave'] ?? 0) == 1): ?>
                                            <span class="status-badge status-on-leave">ON-LEAVE</span>
                                        <?php elseif ($showLateStatus && ($record['late_minutes'] ?? 0) > 0): ?>
                                            <span class="status-badge status-late">Late</span>
                                        <?php elseif (!empty($record['status'])): ?>
                                            <?php
                                            $statusDisplay = $record['status'];
                                            if ($statusDisplay === 'half_day' || $statusDisplay === 'halfday') {
                                                $statusDisplay = 'Half Day';
                                            } else {
                                                $statusDisplay = ucfirst($statusDisplay);
                                            }
                                            ?>
                                            <span class="status-badge status-<?php echo htmlspecialchars($record['status']); ?>">
                                                <?php echo htmlspecialchars($statusDisplay); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-absent">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        // Use the attendance_type from database
                                        $attendance_type = $record['attendance_type'];
                                        $attendance_class = $attendance_type;
                                        
                                        // Add additional context based on status and times
                                        if ($attendance_type === 'present') {
                                            if (!empty($record['time_out']) && $record['overtime_hours'] > 0) {
                                                $attendance_type = 'Present (Overtime)';
                                                $attendance_class = 'overtime';
                                            } elseif (!empty($record['time_out']) && $record['early_out_minutes'] > 0) {
                                                $attendance_type = 'Present (Early Out)';
                                                $attendance_class = 'early-departure';
                                            } else {
                                                $attendance_type = 'Present (Full Day)';
                                                $attendance_class = 'full-day';
                                            }
                                        } elseif ($attendance_type === 'absent') {
                                            // Check if this is a missing record or actual absent record
                                            if ($record['id'] == 0) {
                                                $attendance_type = 'Absent (No Record)';
                                                $attendance_class = 'absent-no-record';
                                            } else {
                                                $attendance_type = 'Absent';
                                                $attendance_class = 'absent';
                                            }
                                        }
                                        ?>
                                        <span class="attendance-type <?php echo $attendance_class; ?>">
                                            <?php echo htmlspecialchars($attendance_type); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(formatDataSource($record['data_source'] ?? 'biometric')); ?></td>
                                    <?php
                                    // Detect halfday type based on shift and attendance times
                                    $halfdayInfo = detectHalfdayType($record);
                                    $showLate = !($halfdayInfo['isHalfDay'] && $halfdayInfo['isAfternoonOnly']);
                                    // For all halfdays, remove early out minutes (both morning and afternoon halfdays shouldn't show early out)
                                    $showEarlyOut = !($halfdayInfo['isHalfDay']);
                                    ?>
                                    <td><?php echo ($showLate && $record['late_minutes'] > 0) ? $record['late_minutes'] : '-'; ?></td>
                                    <td><?php echo ($showEarlyOut && $record['early_out_minutes'] > 0) ? $record['early_out_minutes'] : '-'; ?></td>
                                    <td><?php echo ($record['overtime_hours'] > 0 ? number_format($record['overtime_hours'], 2) : '-'); ?></td>
                                    <td>
                                        <button class="action-btn view-btn" onclick="event.stopPropagation(); openEmployeeModal('<?php echo htmlspecialchars($record['EmployeeID']); ?>', '<?php echo htmlspecialchars($record['EmployeeName']); ?>', '<?php echo htmlspecialchars($record['attendance_date']); ?>')" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="14" style="text-align: center;">No records found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination Controls -->
            <?php if ($total_pages > 1 && !$show_all && $total_records > 0): ?>
            <div class="pagination-container" style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                <div class="pagination-info">
                    <span style="color: #6c757d; font-size: 14px;">
                        <?php if ($show_all): ?>
                            Showing all <?php echo number_format($total_records); ?> records
                        <?php else: ?>
                            Showing <?php echo (($current_page - 1) * 50) + 1; ?> to 
                            <?php echo min($current_page * 50, $total_records); ?> of 
                            <?php echo number_format($total_records); ?> records
                        <?php endif; ?>
                    </span>
                </div>
                
                <div class="pagination">
                    <?php 
                    // Helper function to build pagination URLs with all filters preserved
                    function buildPaginationUrl($page) {
                        $params = $_GET;
                        $params['page'] = $page;
                        // Ensure we don't lose any important parameters
                        unset($params['ajax']); // Remove ajax flag for pagination
                        return '?' . http_build_query($params);
                    }
                    ?>
                    
                    <?php if ($current_page > 1): ?>
                        <a href="<?php echo buildPaginationUrl(1); ?>" class="btn btn-secondary pagination-link" style="margin-right: 5px;" onclick="return true;">
                            <i class="fas fa-angle-double-left"></i> First
                        </a>
                        <a href="<?php echo buildPaginationUrl($current_page - 1); ?>" class="btn btn-secondary pagination-link" style="margin-right: 5px;" onclick="return true;">
                            <i class="fas fa-angle-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="<?php echo buildPaginationUrl($i); ?>" 
                           class="btn <?php echo $i == $current_page ? 'btn-primary' : 'btn-secondary'; ?> pagination-link" 
                           style="margin-right: 5px;" onclick="return true;">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($current_page < $total_pages && $total_records > ($current_page * 50)): ?>
                        <a href="<?php echo buildPaginationUrl($current_page + 1); ?>" class="btn btn-secondary pagination-link" style="margin-right: 5px;" onclick="return true;">
                            Next <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="<?php echo buildPaginationUrl($total_pages); ?>" class="btn btn-secondary pagination-link" onclick="return true;">
                            Last <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="show-all-container" style="display: flex; gap: 10px; align-items: center;">
                    <div class="per-page-selector" style="display: flex; align-items: center; gap: 5px;">
                        <label for="per_page" style="font-size: 12px; color: #6c757d;">Show:</label>
                        <select id="per_page" name="per_page" onchange="changePerPage()" style="padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px;">
                            <option value="20" <?php echo $per_page == '20' ? 'selected' : ''; ?>>20</option>
                            <option value="50" <?php echo $per_page == '50' ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $per_page == '100' ? 'selected' : ''; ?>>100</option>
                            <option value="all" <?php echo $per_page == 'all' ? 'selected' : ''; ?>>All</option>
                        </select>
                    </div>
                    <?php if ($show_all): ?>
                        <a href="?<?php echo http_build_query(array_diff_key($_GET, ['show_all' => ''])); ?>" class="btn btn-secondary">
                            <i class="fas fa-th-list"></i> Show Paginated
                        </a>
                    <?php else: ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['show_all' => '1'])); ?>" class="btn btn-primary">
                            <i class="fas fa-list"></i> Show All Records
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($show_all): ?>
            <!-- Show All Records Footer -->
            <div class="pagination-container" style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                <div class="pagination-info">
                    <span style="color: #6c757d; font-size: 14px;">
                        <?php if ($total_records <= 20): ?>
                            Showing all <?php echo number_format($total_records); ?> records (auto-displayed due to low record count)
                        <?php else: ?>
                            Showing all <?php echo number_format($total_records); ?> records
                        <?php endif; ?>
                    </span>
                </div>
                <div class="show-all-container">
                    <?php if ($total_records > 20): ?>
                        <a href="?<?php echo http_build_query(array_diff_key($_GET, ['show_all' => ''])); ?>" class="btn btn-secondary">
                            <i class="fas fa-th-list"></i> Show Paginated
                        </a>
                    <?php else: ?>
                        <span style="color: #6c757d; font-size: 12px; font-style: italic;">
                            <i class="fas fa-info-circle"></i> Auto-displayed (≤20 records)
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Employee Attendance Details Modal -->
    <div id="employeeModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-clock"></i> Employee Attendance Details</h2>
                <button class="close" onclick="closeEmployeeModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body" id="employeeModalBody">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEmployeeModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Add this after the Attendance Results container -->
<!-- Payroll Results Container (initially hidden) -->
<div class="results-container" id="payrollResults" style="display: none;">
    <div class="results-header">
        <h2>Payroll Records</h2>
        <div class="results-actions"></div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Pay Period</th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Basic Salary</th>
                    <th>Deductions</th>
                    <th>Net Pay</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="7" style="text-align: center;">Payroll data would be displayed here</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>



    <!-- Update the JavaScript section with this -->
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
        
        // Filter toggle functionality + Auto-refresh similar to HRhistory
    document.addEventListener('DOMContentLoaded', function() {
        const filterButtons = document.querySelectorAll('.date-filter-btn');
        const dateFilter = document.getElementById('dateFilter');
        const monthFilter = document.getElementById('monthFilter');
        const yearFilter = document.getElementById('yearFilter');
        
        // Check URL parameters to determine which filter was used
        const urlParams = new URLSearchParams(window.location.search);
        const hasDate = urlParams.get('date');
        const hasMonth = urlParams.get('month');
        const hasYear = urlParams.get('year');
        
        let activeFilter = 'year';
        if (hasDate) activeFilter = 'date';
        else if (hasMonth) activeFilter = 'month';
        else activeFilter = 'year';

        filterButtons.forEach(button => {
            button.classList.remove('active');
            if (button.dataset.filter === activeFilter) button.classList.add('active');
            button.addEventListener('click', function() {
                filterButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
                const filterType = this.dataset.filter;
                dateFilter.style.display = 'none';
                monthFilter.style.display = 'none';
                yearFilter.style.display = 'none';
                if (filterType === 'date') dateFilter.style.display = 'block';
                else if (filterType === 'month') monthFilter.style.display = 'block';
                else if (filterType === 'year') yearFilter.style.display = 'block';
            });
        });

        dateFilter.style.display = activeFilter === 'date' ? 'block' : 'none';
        monthFilter.style.display = activeFilter === 'month' ? 'block' : 'none';
        yearFilter.style.display = activeFilter === 'year' ? 'block' : 'none';

        if (!hasDate && !hasMonth && !hasYear) {
            const yearSelect = document.getElementById('year');
            if (yearSelect) yearSelect.value = String(new Date().getFullYear());
        }

        // Prevent form submission and use AJAX instead
        const form = document.querySelector('form[method="GET"]');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                refreshHistoryData();
            });
        }
        // Auto-refresh handler
        function buildParams() {
            const params = new URLSearchParams(window.location.search);
            // Reflect current inline inputs if present
            const date = document.getElementById('date')?.value;
            const month = document.getElementById('month')?.value;
            const year = document.getElementById('year')?.value;
            const department = document.getElementById('department')?.value;
            const attendanceType = document.getElementById('attendance_type')?.value;
            const status = document.getElementById('status')?.value;
            const shift = document.getElementById('shift')?.value;
            const search = document.querySelector('input[name="search"]')?.value;
            // Clean and set values
            if (date) { params.set('date', date); params.delete('month'); params.delete('year'); }
            if (month) { params.set('month', month); params.delete('date'); params.delete('year'); }
            if (year) { params.set('year', year); params.delete('date'); params.delete('month'); }
            if (department !== undefined) { if (department) params.set('department', department); else params.delete('department'); }
            if (attendanceType !== undefined) { if (attendanceType) params.set('attendance_type', attendanceType); else params.delete('attendance_type'); }
            if (status !== undefined) { if (status) params.set('status', status); else params.delete('status'); }
            if (shift !== undefined) { if (shift) params.set('shift', shift); else params.delete('shift'); }
            if (search !== undefined) { if (search) params.set('search', search); else params.delete('search'); }
            return params;
        }

        async function refreshHistoryData() {
            try {
                // Show loading state
                const tbody = document.querySelector('#attendanceResults tbody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="14" style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #3F72AF;"></i><br><br>Loading attendance records...</td></tr>';
                }
                
                const params = buildParams();
                params.set('ajax', '1');
                const res = await fetch('AdminHistory.php?' + params.toString());
                const data = await res.json();
                if (!data.success) return;
                updateTable(data.records || []);
                updateSummaries(data.summary || {});
            } catch (e) { 
                console.error(e);
                const tbody = document.querySelector('#attendanceResults tbody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="14" style="text-align: center; padding: 20px; color: #ff4757;">Error loading data. Please try again.</td></tr>';
                }
            }
        }

        // Make filter mode buttons (Date/Month/Year) trigger refresh immediately
        (function initFilterModeButtons(){
            const buttons = document.querySelectorAll('.date-filter-btn');
            const dateFilter = document.getElementById('dateFilter');
            const monthFilter = document.getElementById('monthFilter');
            const yearFilter = document.getElementById('yearFilter');
            buttons.forEach(btn => {
                btn.addEventListener('click', () => {
                    const mode = btn.getAttribute('data-filter');
                    // Toggle visibility
                    if (dateFilter) dateFilter.style.display = mode==='date'?'block':'none';
                    if (monthFilter) monthFilter.style.display = mode==='month'?'block':'none';
                    if (yearFilter) yearFilter.style.display = mode==='year'?'block':'none';
                    // Clear non-active inputs to avoid stale state
                    const dateEl = document.getElementById('date');
                    const monthEl = document.getElementById('month');
                    const yearEl = document.getElementById('year');
                    if (mode !== 'date' && dateEl) dateEl.value = '';
                    if (mode !== 'month' && monthEl) monthEl.value = '';
                    if (mode !== 'year' && yearEl) yearEl.value = '';
                    // Refresh immediately
                    setTimeout(() => refreshHistoryData(), 100);
                });
            });
        })();

        function updateTable(records) {
            const tbody = document.querySelector('#attendanceResults tbody');
            if (!tbody) return;
            if (!records.length) {
                tbody.innerHTML = '<tr><td colspan="14" style="text-align:center;">No records found</td></tr>';
                return;
            }
            
            // Helper function to format data source
            function formatDataSource(dataSource) {
                dataSource = dataSource || 'biometric';
                switch(dataSource) {
                    case 'manual':
                        return 'Manual Entry by HR';
                    case 'bulk_edit':
                        return 'BulkEditAttendance';
                    case 'biometric':
                    default:
                        return 'Biometric';
                }
            }
            
            const rows = records.map(r => {
                const date = r.attendance_date ? r.attendance_date : '-';
                const timeIn = r.time_in ? formatTimeTo12Hour(r.time_in) : '-';
                const timeOut = r.time_out ? formatTimeTo12Hour(r.time_out) : '-';
                // Don't show total hours if employee is on leave
                const totalHours = (r.is_on_leave == 1) ? '-' : (r.total_hours && r.total_hours > 0 ? Number(r.total_hours).toFixed(2) + ' hrs' : '-');
                const status = r.status || '';
                const attType = r.attendance_type || '-';
                const source = formatDataSource(r.data_source || 'biometric');
                
                // Helper function to get lunch start time from shift
                function getLunchStartFromShift(shift) {
                    if (!shift) return '12:00:00';
                    const match = shift.match(/(\d{1,2}):?(\d{2})?\s*-\s*(\d{1,2}):?(\d{2})?/);
                    if (match) {
                        const startHour = parseInt(match[1]);
                        if (startHour <= 8) return '12:00:00';
                        if (startHour <= 8.5) return '12:30:00';
                        return '13:00:00';
                    }
                    return '12:00:00';
                }
                
                // Helper function to convert time string to minutes
                function timeToMinutes(timeStr) {
                    if (!timeStr) return null;
                    const match = timeStr.toString().match(/(\d{1,2}):(\d{2})/);
                    if (match) {
                        let hours = parseInt(match[1]);
                        const minutes = parseInt(match[2]);
                        if (timeStr.toString().toLowerCase().includes('pm') && hours !== 12) {
                            hours += 12;
                        } else if (timeStr.toString().toLowerCase().includes('am') && hours === 12) {
                            hours = 0;
                        }
                        return hours * 60 + minutes;
                    }
                    return null;
                }
                
                // Detect halfday type
                const isHalfDay = (r.status === 'half_day' || r.status === 'halfday') || 
                                 (r.total_hours > 0 && r.total_hours <= 4.0);
                
                let isAfternoonOnly = false;
                let isMorningOnly = false;
                
                if (isHalfDay) {
                    const shift = r.Shift || '';
                    const lunchStart = getLunchStartFromShift(shift);
                    const lunchStartMinutes = timeToMinutes(lunchStart);
                    
                    const hasMorningIn = !!(r.time_in_morning);
                    const hasMorningOut = !!(r.time_out_morning);
                    const hasAfternoonIn = !!(r.time_in_afternoon);
                    const hasAfternoonOut = !!(r.time_out_afternoon);
                    
                    const timeInMorningMinutes = timeToMinutes(r.time_in_morning || '');
                    const actualTimeIn = r.time_in || r.time_in_morning || r.time_in_afternoon || '';
                    const actualTimeInMinutes = timeToMinutes(actualTimeIn);
                    
                    // Check if morning session is valid (time_in_morning must be before lunch start)
                    let isValidMorningSession = false;
                    if (hasMorningIn && lunchStartMinutes !== null && timeInMorningMinutes !== null) {
                        isValidMorningSession = (timeInMorningMinutes < lunchStartMinutes);
                    }
                    
                    // Afternoon-only detection:
                    if (hasAfternoonIn || hasAfternoonOut) {
                        if (!hasMorningOut || (hasMorningIn && !isValidMorningSession)) {
                            isAfternoonOnly = true;
                        }
                    } else if (actualTimeInMinutes !== null && lunchStartMinutes !== null && actualTimeInMinutes >= lunchStartMinutes) {
                        isAfternoonOnly = true;
                    } else if (hasMorningIn && !isValidMorningSession) {
                        isAfternoonOnly = true;
                    }
                    
                    // Morning-only: has valid morning session but no afternoon session
                    if (isValidMorningSession && hasMorningOut && !hasAfternoonIn && !hasAfternoonOut) {
                        isMorningOnly = true;
                    }
                }
                
                // Check if should show late status (don't show for afternoon-only halfdays)
                const showLateStatus = !(isHalfDay && isAfternoonOnly);
                const showLate = !(isHalfDay && isAfternoonOnly);
                // For all halfdays, remove early out minutes
                const showEarlyOut = !(isHalfDay);
                
                const statusBadge = (r.is_on_leave == 1)
                    ? '<span class="status-badge status-on-leave">ON-LEAVE</span>'
                    : (showLateStatus && (r.late_minutes && Number(r.late_minutes) > 0)
                        ? '<span class="status-badge status-late">Late</span>'
                        : (status
                            ? `<span class="status-badge status-${status}">${(status==='on_time'?'On-time':(status==='half_day'||status==='halfday'?'Half Day':status.charAt(0).toUpperCase()+status.slice(1)))}</span>`
                            : '<span class="status-badge status-absent">-</span>'));
                
                const late = (showLate && r.late_minutes && r.late_minutes > 0) ? r.late_minutes : '-';
                const early = (showEarlyOut && r.early_out_minutes && r.early_out_minutes > 0) ? r.early_out_minutes : '-';
                const ot = r.overtime_hours && r.overtime_hours > 0 ? Number(r.overtime_hours).toFixed(2) : '-';
                return `<tr onclick="openEmployeeModal('${r.EmployeeID}', '${r.EmployeeName}', '${date}')" style="cursor: pointer;">
                    <td>${date}</td>
                    <td>${r.EmployeeName || ''}</td>
                    <td>${r.Department || ''}</td>
                    <td>${r.Shift || ''}</td>
                    <td>${timeIn}</td>
                    <td>${timeOut}</td>
                    <td>${totalHours}</td>
                    <td>${statusBadge}</td>
                    <td>${attType.charAt(0).toUpperCase()+attType.slice(1)}</td>
                    <td>${source}</td>
                    <td>${late}</td>
                    <td>${early}</td>
                    <td>${ot}</td>
                    <td>
                        <button class="action-btn view-btn" onclick="event.stopPropagation(); openEmployeeModal('${r.EmployeeID}', '${r.EmployeeName}', '${date}')" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');
            tbody.innerHTML = rows;
        }

        function updateSummaries(summary) {
            const values = document.querySelectorAll('.summary-value');
            if (values && values.length >= 5) {
                values[0].textContent = summary.total_records ?? 0;
                values[1].textContent = summary.present_count ?? 0;
                values[2].textContent = summary.absent_count ?? 0;
                values[3].textContent = summary.late_count ?? 0;
                values[4].textContent = summary.early_count ?? 0;
            }
        }

        // Attach change listeners for instant refresh
        ['date','month','year','department','attendance_type','status','shift'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', () => {
                    // For date/month/year, clear other date inputs
                    if (['date','month','year'].includes(id)) {
                        const dateEl = document.getElementById('date');
                        const monthEl = document.getElementById('month');
                        const yearEl = document.getElementById('year');
                        if (id !== 'date' && dateEl) dateEl.value = '';
                        if (id !== 'month' && monthEl) monthEl.value = '';
                        if (id !== 'year' && yearEl) yearEl.value = '';
                    }
                    refreshHistoryData();
                });
            }
        });
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            let t; searchInput.addEventListener('input', () => { clearTimeout(t); t = setTimeout(refreshHistoryData, 300); });
        }

        // Initial fetch
        refreshHistoryData();
    });

    // Global time formatting function
    function formatTimeTo12Hour(time24) {
        if (!time24) return 'Not Set';
        const [hours, minutes] = time24.split(':');
        const hour12 = hours % 12 || 12;
        const period = hours >= 12 ? 'PM' : 'AM';
        return `${hour12}:${minutes} ${period}`;
    }

    function showAttendance() {
        document.getElementById('attendanceResults').style.display = 'block';
        document.getElementById('payrollResults').style.display = 'none';
        document.getElementById('attendanceBtn').classList.add('active');
        document.getElementById('payrollBtn').classList.remove('active');
    }

    function showPayroll() {
        // Redirect to PayrollHistory.php with current month filter
        const currentDate = new Date();
        const currentMonth = currentDate.getFullYear() + '-' + String(currentDate.getMonth() + 1).padStart(2, '0');
        window.location.href = `PayrollHistory.php?month=${currentMonth}`;
    }

    // Employee Modal Functions
    async function openEmployeeModal(employeeId, employeeName, attendanceDate) {
        try {
            const modal = document.getElementById('employeeModal');
            const modalBody = document.getElementById('employeeModalBody');
            
            // Show loading state
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #3F72AF;"></i>
                    <br><br>
                    <p>Loading employee details...</p>
                </div>
            `;
            
            modal.classList.add('show');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            // Fetch employee details
            const response = await fetch(`get_employee_details.php?employee_id=${employeeId}&date=${attendanceDate}`);
            const data = await response.json();
            
            if (data.success) {
                displayEmployeeDetails(data.employee, data.attendance);
            } else {
                modalBody.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #ff4757;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 20px;"></i>
                        <h3>Error Loading Details</h3>
                        <p>Could not load employee attendance details.</p>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error opening employee modal:', error);
            const modalBody = document.getElementById('employeeModalBody');
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #ff4757;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 20px;"></i>
                    <h3>Connection Error</h3>
                    <p>Unable to connect to server. Please try again.</p>
                </div>
            `;
        }
    }

    function displayEmployeeDetails(employee, attendance) {
        const modalBody = document.getElementById('employeeModalBody');
        
        // Format shift display
        let shiftDisplay = employee.Shift || 'Not Set';
        if (shiftDisplay && shiftDisplay.includes('-')) {
            const [start, end] = shiftDisplay.split('-');
            const startTime = formatTimeTo12Hour(start);
            const endTime = formatTimeTo12Hour(end);
            shiftDisplay = `${startTime} - ${endTime}`;
        }
        
        // Format attendance date
        const attendanceDate = new Date(attendance.attendance_date).toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        
        // Get status color and icon
        const statusInfo = getStatusInfo(attendance.status, attendance.attendance_type);
        
        // Helper function to get lunch start time from shift
        function getLunchStartFromShift(shift) {
            if (!shift || shift === 'Not Set') return '12:00:00';
            const match = shift.match(/(\d{1,2}):?(\d{2})?\s*-\s*(\d{1,2}):?(\d{2})?/);
            if (match) {
                const startHour = parseInt(match[1]);
                if (startHour <= 8) return '12:00:00';
                if (startHour <= 8.5) return '12:30:00';
                return '13:00:00';
            }
            return '12:00:00';
        }
        
        // Helper function to convert time string to minutes
        function timeToMinutes(timeStr) {
            if (!timeStr) return null;
            const match = timeStr.toString().match(/(\d{1,2}):(\d{2})/);
            if (match) {
                let hours = parseInt(match[1]);
                const minutes = parseInt(match[2]);
                if (timeStr.toString().toLowerCase().includes('pm') && hours !== 12) {
                    hours += 12;
                } else if (timeStr.toString().toLowerCase().includes('am') && hours === 12) {
                    hours = 0;
                }
                return hours * 60 + minutes;
            }
            return null;
        }
        
        // Determine halfday status and session type based on shift
        const isHalfDay = (attendance.status === 'half_day' || attendance.status === 'halfday') ||
                         (attendance.total_hours > 0 && attendance.total_hours <= 4.0);
        
        let isAfternoonOnly = false;
        let isMorningOnly = false;
        
        if (isHalfDay) {
            const shift = employee.Shift || '';
            const lunchStart = getLunchStartFromShift(shift);
            const lunchStartMinutes = timeToMinutes(lunchStart);
            
            const hasMorningIn = !!(attendance.time_in_morning);
            const hasMorningOut = !!(attendance.time_out_morning);
            const hasAfternoonIn = !!(attendance.time_in_afternoon);
            const hasAfternoonOut = !!(attendance.time_out_afternoon);
            
            const timeInMorningMinutes = timeToMinutes(attendance.time_in_morning || '');
            const actualTimeIn = attendance.time_in || attendance.time_in_morning || attendance.time_in_afternoon || '';
            const actualTimeInMinutes = timeToMinutes(actualTimeIn);
            
            // Check if morning session is valid (time_in_morning must be before lunch start)
            let isValidMorningSession = false;
            if (hasMorningIn && lunchStartMinutes !== null && timeInMorningMinutes !== null) {
                isValidMorningSession = (timeInMorningMinutes < lunchStartMinutes);
            }
            
            // Afternoon-only detection:
            if (hasAfternoonIn || hasAfternoonOut) {
                if (!hasMorningOut || (hasMorningIn && !isValidMorningSession)) {
                    isAfternoonOnly = true;
                }
            } else if (actualTimeInMinutes !== null && lunchStartMinutes !== null && actualTimeInMinutes >= lunchStartMinutes) {
                isAfternoonOnly = true;
            } else if (hasMorningIn && !isValidMorningSession) {
                isAfternoonOnly = true;
            }
            
            // Morning-only: has valid morning session but no afternoon session
            if (isValidMorningSession && hasMorningOut && !hasAfternoonIn && !hasAfternoonOut) {
                isMorningOnly = true;
            }
        }
        
        modalBody.innerHTML = `
            <div class="employee-details">
                <div class="employee-profile">
                    <div class="employee-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="employee-info">
                        <h3>${employee.EmployeeName}</h3>
                        <p><strong>Employee ID:</strong> ${employee.EmployeeID}</p>
                        <p><strong>Department:</strong> ${employee.Department}</p>
                        <p><strong>Position:</strong> ${employee.Position || 'Not Specified'}</p>
                    </div>
                </div>
                
                <div class="attendance-details">
                    <div class="detail-section">
                        <h4><i class="fas fa-calendar-alt"></i> Attendance Information</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="label"><i class="fas fa-calendar"></i> Date</div>
                                <div class="value">${attendanceDate}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label"><i class="fas fa-clock"></i> Shift</div>
                                <div class="value">${shiftDisplay}</div>
                            </div>
                            <div class="detail-item ${statusInfo.class}">
                                <div class="label"><i class="fas fa-${statusInfo.icon}"></i> Status</div>
                                <div class="value">${statusInfo.text}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label"><i class="fas fa-tag"></i> Type</div>
                                <div class="value">${attendance.attendance_type ? attendance.attendance_type.charAt(0).toUpperCase() + attendance.attendance_type.slice(1) : 'Not Set'}</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4><i class="fas fa-clock"></i> Time Records</h4>
                        <div class="detail-grid">
                            <!-- Always show Overall Time In/Out from database columns directly -->
                            <div class="detail-item">
                                <div class="label"><i class="fas fa-sign-in-alt"></i> Overall Time In</div>
                                <div class="value">${attendance.time_in ? formatTimeTo12Hour(attendance.time_in) : '-'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label"><i class="fas fa-sign-out-alt"></i> Overall Time Out</div>
                                <div class="value">${attendance.time_out ? formatTimeTo12Hour(attendance.time_out) : '-'}</div>
                            </div>
                            <!-- Show Morning Session from database -->
                            <div class="detail-item">
                                <div class="label"><i class="fas fa-sun"></i> Morning In</div>
                                <div class="value">${attendance.time_in_morning ? formatTimeTo12Hour(attendance.time_in_morning) : '-'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label"><i class="fas fa-utensils"></i> Morning Out (Lunch Start)</div>
                                <div class="value">${attendance.time_out_morning ? formatTimeTo12Hour(attendance.time_out_morning) : '-'}</div>
                            </div>
                            <!-- Show Afternoon Session from database -->
                            <div class="detail-item">
                                <div class="label"><i class="fas fa-coffee"></i> Afternoon In (Lunch End)</div>
                                <div class="value">${attendance.time_in_afternoon ? formatTimeTo12Hour(attendance.time_in_afternoon) : '-'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label"><i class="fas fa-moon"></i> Afternoon Out (Evening Out)</div>
                                <div class="value">${attendance.time_out_afternoon ? formatTimeTo12Hour(attendance.time_out_afternoon) : '-'}</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4><i class="fas fa-chart-line"></i> Work Summary</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="label"><i class="fas fa-hourglass-half"></i> Total Hours</div>
                                <div class="value">${(attendance.is_on_leave == 1) ? '-' : (attendance.total_hours ? Number(attendance.total_hours).toFixed(2) + ' hrs' : '-')}</div>
                            </div>
                            <div class="detail-item ${attendance.late_minutes > 0 ? 'late' : ''}">
                                <div class="label"><i class="fas fa-exclamation-triangle"></i> Late Minutes</div>
                                <div class="value">${(isHalfDay && isAfternoonOnly) ? '-' : (attendance.late_minutes > 0 ? attendance.late_minutes + ' min' : 'On Time')}</div>
                            </div>
                            <div class="detail-item ${attendance.early_out_minutes > 0 ? 'early' : ''}">
                                <div class="label"><i class="fas fa-clock"></i> Early Out</div>
                                <div class="value">${(isHalfDay && (isMorningOnly || isAfternoonOnly)) ? '-' : (attendance.early_out_minutes > 0 ? attendance.early_out_minutes + ' min' : 'Full Day')}</div>
                            </div>
                            <div class="detail-item ${attendance.overtime_hours > 0 ? 'overtime' : ''}">
                                <div class="label"><i class="fas fa-plus-circle"></i> Overtime</div>
                                <div class="value">${attendance.overtime_hours > 0 ? Number(attendance.overtime_hours).toFixed(2) + ' hrs' : 'None'}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function closeEmployeeModal() {
        const modal = document.getElementById('employeeModal');
        modal.classList.remove('show');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    function getStatusInfo(status, attendanceType) {
        if (attendanceType === 'absent') {
            return { class: 'absent', icon: 'times-circle', text: 'Absent' };
        }
        
        switch (status) {
            case 'late':
                return { class: 'late', icon: 'exclamation-triangle', text: 'Late' };
            case 'early':
                return { class: 'early', icon: 'clock', text: 'Early Departure' };
            case 'on_time':
                return { class: 'present', icon: 'check-circle', text: 'On Time' };
            case 'half_day':
                return { class: 'present', icon: 'clock', text: 'Half Day' };
            default:
                return { class: 'present', icon: 'check-circle', text: 'Present' };
        }
    }

    // Close modal when clicking outside
    document.addEventListener('click', function(event) {
        const modal = document.getElementById('employeeModal');
        if (event.target === modal) {
            closeEmployeeModal();
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeEmployeeModal();
        }
    });

    // Function to handle per-page change
    function changePerPage() {
        const perPage = document.getElementById('per_page').value;
        const currentUrl = new URL(window.location);
        currentUrl.searchParams.set('per_page', perPage);
        currentUrl.searchParams.delete('page'); // Reset to page 1
        currentUrl.searchParams.delete('ajax'); // Remove ajax flag
        window.location.href = currentUrl.toString();
    }

        // AJAX Toggle switch functionality
        document.addEventListener('DOMContentLoaded', function() {
            const toggleSwitch = document.getElementById('show_overtime');
            const toggleContainer = document.querySelector('.switch-btn');
            
            if (toggleSwitch && toggleContainer) {
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
                
                // AJAX function to update attendance records
                function updateAttendanceRecords() {
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
                            const currentResults = document.querySelector('#attendanceResults');
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
                
                // Add event listener for toggle changes
                toggleSwitch.addEventListener('change', function() {
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
                                    const currentResults = document.querySelector('#attendanceResults');
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
                        
                        console.log('Row clicked:', { employeeId, employeeName, attendanceDate });
                        
                        if (employeeId && employeeName && attendanceDate) {
                            openEmployeeModal(employeeId, employeeName, attendanceDate);
                        } else {
                            console.error('Missing required data:', { employeeId, employeeName, attendanceDate });
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
                        
                        console.log('Button clicked:', { employeeId, employeeName, attendanceDate });
                        
                        if (employeeId && employeeName && attendanceDate) {
                            openEmployeeModal(employeeId, employeeName, attendanceDate);
                        } else {
                            console.error('Missing required data:', { employeeId, employeeName, attendanceDate });
                        }
                    });
                });
            }
            
            // Initial pagination setup
            attachPaginationListeners();
            
            // Initial modal setup
            attachModalEventListeners();
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

</body>
</html>






