<?php
session_start();

// Check if user is logged in and is a Department Head
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'depthead') {
    header("Location: login.php");
    exit;
}

// Include attendance calculations
require_once 'attendance_calculations.php';

// Function to convert 24-hour time to 12-hour AM/PM format
function formatTime12Hour($time) {
    if (empty($time) || $time === '00:00:00' || $time === '00:00' || $time === null) {
        return '-';
    }
    
    try {
        // Handle different time formats
        $timeStr = trim($time);
        
        // If it's already in 12-hour format, return as is
        if (strpos($timeStr, 'AM') !== false || strpos($timeStr, 'PM') !== false) {
            return $timeStr;
        }
        
        // Convert 24-hour to 12-hour format
        $timestamp = strtotime($timeStr);
        if ($timestamp === false) {
            return $timeStr; // Return original if conversion fails
        }
        
        return date('g:i A', $timestamp);
    } catch (Exception $e) {
        return $time; // Return original if any error occurs
    }
}

// Function to format shift time to 12-hour format
function formatShiftTime($shift) {
    if (empty($shift) || $shift === null) {
        return '-';
    }
    
    try {
        $shiftStr = trim($shift);
        
        // Handle different shift formats
        if (strpos($shiftStr, '-') !== false) {
            // Format like "08:00-17:00" or "8:00 AM-5:00 PM"
            $times = explode('-', $shiftStr);
            if (count($times) === 2) {
                $start = trim($times[0]);
                $end = trim($times[1]);
                
                // Convert to 12-hour format if needed
                $startFormatted = formatTime12Hour($start);
                $endFormatted = formatTime12Hour($end);
                
                return $startFormatted . ' - ' . $endFormatted;
            }
        }
        
        // If it's a single time, format it
        return formatTime12Hour($shiftStr);
    } catch (Exception $e) {
        return $shift; // Return original if any error occurs
    }
}

$dept_head_name = $_SESSION['username'] ?? 'Dept Head';
$managed_department = $_SESSION['user_department'];
$dept_head_id = $_SESSION['userid'] ?? null;

// Database connection
$host = "localhost";
$user = "root";
$password = "";
$dbname = "wteimain1";

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

// Optional UI department filter (show all if not specified)
if (isset($_GET['department']) && $_GET['department'] !== '' && strtolower($_GET['department']) !== 'all') {
    $filter_department = $conn->real_escape_string($_GET['department']);
    $employee_where[] = "e.Department = ?";
    $employee_params[] = $filter_department;
    $employee_types .= "s";
}

// Apply attendance_type filter (present/absent)
$filter_attendance_type = '';
if (isset($_GET['attendance_type']) && $_GET['attendance_type'] !== '') {
    $filter_attendance_type = $conn->real_escape_string($_GET['attendance_type']);
    if ($filter_attendance_type === 'present') {
        $employee_where[] = "a.attendance_type = 'present'";
    } elseif ($filter_attendance_type === 'absent') {
        $employee_where[] = "(a.attendance_type = 'absent' OR a.id IS NULL)";
    }
}

// Apply shift filter
if (isset($_GET['shift']) && $_GET['shift'] !== '') {
    $filter_shift = $conn->real_escape_string($_GET['shift']);
    $employee_where[] = "e.Shift = ?";
    $employee_params[] = $filter_shift;
    $employee_types .= "s";
}

// Apply status filter (On-time, Early-in, Half-day, Late)
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $filter_status = strtolower(trim($conn->real_escape_string($_GET['status'])));
    if ($filter_status === 'late') {
        // Use late_minutes > 0 to robustly capture late
        $employee_where[] = "a.late_minutes > 0";
    } elseif (in_array($filter_status, ['on_time', 'early_in', 'halfday'])) {
        $employee_where[] = "a.status = '$filter_status'";
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
$queryStartDate = $system_start_date;
$queryEndDate = $system_end_date;
$filterType = null;
$filterValue = null;

if (isset($_GET['date']) && !empty($_GET['date'])) {
    $queryStartDate = $_GET['date'];
    $queryEndDate = $_GET['date'];
    $filterType = 'date';
    $filterValue = $_GET['date'];
} elseif (isset($_GET['month']) && !empty($_GET['month'])) {
    $monthStart = $_GET['month'] . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $queryStartDate = max($monthStart, $system_start_date);
    $queryEndDate = min($monthEnd, $system_end_date);
    $filterType = 'month';
    $filterValue = $_GET['month'];
} elseif (isset($_GET['year']) && !empty($_GET['year'])) {
    $yearStart = $_GET['year'] . '-01-01';
    $yearEnd = $_GET['year'] . '-12-31';
    $queryStartDate = max($yearStart, $system_start_date);
    $queryEndDate = min($yearEnd, $system_end_date);
    $filterType = 'year';
    $filterValue = $_GET['year'];
}

// Generate working days for the query range
$workingDays = generateWorkingDays($queryStartDate, $queryEndDate, $filterType, $filterValue);

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
if (!empty($workingDays)) {
    // Create a temporary table with all working days and employees
    $workingDaysStr = "'" . implode("','", $workingDays) . "'";
    
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
            COALESCE(a.overtime_hours, 0) as overtime_hours,
            COALESCE(a.late_minutes, 0) as late_minutes,
                COALESCE(a.early_out_minutes, 0) as early_out_minutes,
                e.EmployeeName,
                e.Department,
                e.Shift
          FROM empuser e 
              CROSS JOIN (
                  SELECT working_date FROM (
                      SELECT '{$workingDays[0]}' as working_date" . 
                      (count($workingDays) > 1 ? " UNION ALL SELECT '" . implode("' UNION ALL SELECT '", array_slice($workingDays, 1)) . "'" : "") . "
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
                a.overtime_hours,
                a.late_minutes,
                a.early_out_minutes,
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
if (empty($workingDays)) {
    $all_params[] = $queryStartDate;
    $all_params[] = $queryEndDate;
    $all_types .= "ss";
}

// No extra params for attendance_type filter (constants inlined)

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
if (!empty($workingDays)) {
    $count_query = "SELECT COUNT(*) as total_count
                    FROM empuser e 
                    CROSS JOIN (
                        SELECT working_date FROM (
                            SELECT '{$workingDays[0]}' as working_date" . 
                            (count($workingDays) > 1 ? " UNION ALL SELECT '" . implode("' UNION ALL SELECT '", array_slice($workingDays, 1)) . "'" : "") . "
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
    if (!empty($workingDays)) {
        // Bind employee parameters for working days query
        if (!empty($employee_params)) {
            $count_stmt->bind_param($employee_types, ...$employee_params);
        }
    } else {
        // Add date parameters for fallback query
        $all_count_params = array_merge($employee_params, [$queryStartDate, $queryEndDate]);
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

$total_pages = ceil($total_records / $records_per_page); // Use actual records_per_page for pagination calculation

// Apply accurate calculations to all attendance records
$attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);

// Apply overtime filter
if (!$show_overtime) {
    $attendance_records = array_values(array_filter($attendance_records, function($record) {
        return ($record['overtime_hours'] ?? 0) == 0;
    }));
}

// Get departments for filter (list all for selection)
$departments = [];
$departments_query = "SELECT DISTINCT Department FROM empuser ORDER BY Department";
$departments_result_obj = $conn->query($departments_query);
if ($departments_result_obj) {
    while ($dept = $departments_result_obj->fetch_assoc()) {
        $departments[] = $dept['Department'];
    }
}

// Get shifts for filter (list all for selection)
$shifts = [];
$shifts_query = "SELECT DISTINCT Shift FROM empuser WHERE Shift IS NOT NULL AND Shift != '' ORDER BY Shift";
$shifts_result_obj = $conn->query($shifts_query);
if ($shifts_result_obj) {
    while ($shift = $shifts_result_obj->fetch_assoc()) {
        $shifts[] = $shift['Shift'];
    }
}


// Calculate summary analytics
$recordedDays = count($workingDays);

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
if (!empty($workingDays)) {
    // Use the same working days logic for analytics
    $analytics_query = "SELECT 
        COALESCE(a.id, 0) as id,
        COALESCE(a.attendance_type, 'absent') as attendance_type,
        COALESCE(a.status, 'no_record') as status,
        COALESCE(a.is_overtime, 0) as is_overtime,
        COALESCE(a.overtime_hours, 0) as overtime_hours
        FROM empuser e 
        CROSS JOIN (
            SELECT working_date FROM (
                SELECT '{$workingDays[0]}' as working_date" . 
                (count($workingDays) > 1 ? " UNION ALL SELECT '" . implode("' UNION ALL SELECT '", array_slice($workingDays, 1)) . "'" : "") . "
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
            $filter_status === 'late' ? " AND a.late_minutes > 0" : (
            in_array($filter_status, ['on_time', 'early_in', 'halfday']) ? " AND a.status = '$filter_status'" :
            ($filter_status === 'on_leave' ? " AND a.is_on_leave = 1" : ""))
        ) : "");
    } else {
    // For default - count only actual records
    $analytics_query = "SELECT 
        a.id,
        a.attendance_type,
        a.status,
        COALESCE(a.is_overtime, 0) as is_overtime,
        COALESCE(a.overtime_hours, 0) as overtime_hours
        FROM attendance a 
        JOIN empuser e ON a.EmployeeID = e.EmployeeID
        WHERE " . implode(' AND ', $analytics_where) .
        (!empty($filter_attendance_type) ? (
            $filter_attendance_type === 'present' ? " AND a.attendance_type = 'present'" : " AND (a.attendance_type = 'absent' OR a.id IS NULL)"
        ) : "") .
        (!empty($filter_status) ? (
            $filter_status === 'late' ? " AND a.late_minutes > 0" : (
            in_array($filter_status, ['on_time', 'early_in', 'halfday']) ? " AND a.status = '$filter_status'" : 
            ($filter_status === 'on_leave' ? " AND a.is_on_leave = 1" : ""))
        ) : "") .
        " AND DATE(a.attendance_date) BETWEEN ? AND ?";
    $analytics_params[] = $queryStartDate;
    $analytics_params[] = $queryEndDate;
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
        if (($row['attendance_type'] ?? '') === 'present' || ($row['status'] ?? '') === 'halfday') {
            $present_count++;
        } else {
            $absent_count++;
        }
        
        // For halfday filter, only show total and present records, others should be 0
        if (isset($filter_status) && $filter_status === 'halfday') {
            // Only count records that match the halfday filter
            if ($row['id'] > 0 && $row['status'] === 'halfday') {
                $late_count = 0;
                $early_count = 0;
                $overtime_count = 0;
            }
        } else {
            if ((int)($row['late_minutes'] ?? 0) > 0) {
                $late_count++;
            }
            if ($row['status'] === 'early_in') {
                $early_count++;
            }
            if ($row['status'] === 'halfday') {
                $early_count++; // Count halfday as early for now, or create separate counter
            }
            if ($row['status'] === 'on_time') {
                $early_count++; // Count on_time as early for now, or create separate counter
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
        'recorded_days' => $recordedDays
    ];
    
    $analytics_stmt->close();
} else {
    $summary_data = ['total_records' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'early_count' => 0, 'overtime_count' => 0, 'recorded_days' => 0];
}

// Today's analytics (respect selected department if provided)
$today_conditions = ["DATE(a.attendance_date) = ?"]; $today_params = [date('Y-m-d')]; $today_types = "s";
if (isset($_GET['department']) && $_GET['department'] !== '' && strtolower($_GET['department']) !== 'all') {
    $today_conditions[] = "e.Department = ?";
    $today_params[] = $conn->real_escape_string($_GET['department']);
    $today_types .= "s";
}
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $statusParam = strtolower(trim($conn->real_escape_string($_GET['status'])));
    if ($statusParam === 'late') {
        $today_conditions[] = "a.late_minutes > 0";
        // no param added for this numeric condition
    } else {
        $today_conditions[] = "a.status = ?";
        $today_params[] = $statusParam;
        $today_types .= "s";
    }
}
$today_where = implode(' AND ', $today_conditions);
$today_query = "SELECT 
    SUM(CASE WHEN (a.status = 'present' OR a.status = 'halfday') THEN 1 ELSE 0 END) as present_today,
    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_today,
    SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END) as late_today,
    SUM(CASE WHEN a.status = 'early_in' THEN 1 ELSE 0 END) as early_in_today,
    SUM(CASE WHEN a.status = 'halfday' THEN 1 ELSE 0 END) as halfday_today,
    SUM(CASE WHEN a.status = 'on_time' THEN 1 ELSE 0 END) as on_time_today
    FROM attendance a 
    JOIN empuser e ON a.EmployeeID = e.EmployeeID 
    WHERE $today_where";
$today_stmt = $conn->prepare($today_query);
if ($today_stmt) {
    $today_stmt->bind_param($today_types, ...$today_params);
    $today_stmt->execute();
    $today_result = $today_stmt->get_result();
    $today_data = $today_result ? $today_result->fetch_assoc() : ['present_today' => 0, 'absent_today' => 0, 'late_today' => 0, 'early_in_today' => 0, 'halfday_today' => 0, 'on_time_today' => 0];
    $today_stmt->close();
} else {
    $today_data = ['present_today' => 0, 'absent_today' => 0, 'late_today' => 0, 'early_in_today' => 0, 'halfday_today' => 0, 'on_time_today' => 0];
}

$conn->close();

if ($is_ajax) {
    header('Content-Type: application/json');
    
    // Handle payroll history AJAX request
    if (isset($_GET['action']) && $_GET['action'] === 'payroll_history') {
        $payroll_month = $_GET['payroll_month'] ?? '';
        $payroll_year = $_GET['payroll_year'] ?? '';
        $payroll_department = $_GET['payroll_department'] ?? '';
        
        try {
            // Include payroll computations
            require_once 'payroll_computations.php';
            
            // Use the exact same logic as Payroll.php
            $current_month = $payroll_month ?: date('Y-m');
            $department_filter = $payroll_department;
            
            // Additional deductions are now handled by payroll_computations.php
            // No need to query deductions table as it has been removed
            
            // Get employees for payroll generation (exact same query as Payroll.php)
            $employees_query = "SELECT e.*, 
                               COUNT(DISTINCT DATE(a.attendance_date)) as days_worked,
                               COALESCE(SUM(TIMESTAMPDIFF(MINUTE, 
                                   CONCAT(DATE(a.attendance_date), ' ', TIME(a.time_in)), 
                                   CASE 
                                       WHEN a.time_out IS NOT NULL THEN CONCAT(DATE(a.attendance_date), ' ', TIME(a.time_out))
                                       ELSE CONCAT(DATE(a.attendance_date), ' 17:00:00')
                                   END
                               )) / 60, 0) as total_hours,
                               COALESCE(e.base_salary, 0) as base_salary
                               FROM empuser e
                               LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID 
                               AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                               WHERE 1=1";

            $params = [$current_month];
            $types = "s";

            if ($department_filter) {
                $employees_query .= " AND e.Department = ?";
                $params[] = $department_filter;
                $types .= "s";
            }

            $employees_query .= " GROUP BY e.EmployeeID ORDER BY e.Department, e.EmployeeName";

            $stmt = $conn->prepare($employees_query);
            $employees = [];
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $employees[] = $row;
                }
                $stmt->close();
            }
            
            // Calculate payroll for each employee (same as Payroll.php)
            $payroll_data = [];
            $total_gross = 0;
            $total_net = 0;
            $total_deductions = 0;
            $department_counts = [];
            
            foreach ($employees as $employee) {
                // Use the proper payroll calculation function
                $payroll = calculatePayroll($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                
                // Additional deductions are now handled by payroll_computations.php
                $additional_deductions = 0;
                
                // Final net pay calculation
                $final_net_pay = $payroll['net_pay'] - $additional_deductions;
                
                // Add to totals
                $total_gross += $payroll['gross_pay'];
                $total_net += $final_net_pay;
                $total_deductions += $payroll['total_deductions'] + $additional_deductions;
                
                // Count by department
                $dept = $employee['Department'] ?: 'Unassigned';
                if (!isset($department_counts[$dept])) {
                    $department_counts[$dept] = 0;
                }
                $department_counts[$dept]++;
                
                // Store employee data in the format expected by JavaScript
                $payroll_data[] = [
                    'EmployeeID' => $employee['EmployeeID'],
                    'EmployeeName' => $employee['EmployeeName'],
                    'Department' => $employee['Department'],
                    'PayPeriod' => $current_month,
                    'DaysWorked' => intval($employee['days_worked']),
                    'TotalHours' => floatval($employee['total_hours']),
                    'BaseSalary' => floatval($employee['base_salary']),
                    'GrossPay' => floatval($payroll['gross_pay']),
                    'Deductions' => floatval($payroll['total_deductions'] + $additional_deductions),
                    'NetPay' => floatval($final_net_pay),
                    'OvertimePay' => floatval($payroll['overtime_pay']),
                    'LateAmount' => floatval($payroll['lates_amount']),
                    'PHICEmployee' => floatval($payroll['phic_employee']),
                    'PHICEmployer' => floatval($payroll['phic_employer']),
                    'PagIBIGEmployee' => floatval($payroll['pagibig_employee']),
                    'PagIBIGEmployer' => floatval($payroll['pagibig_employer']),
                    'SSSEmployee' => floatval($payroll['sss_employee']),
                    'SSSEmployer' => floatval($payroll['sss_employer']),
                    'SpecialHolidayPay' => floatval($payroll['special_holiday_pay']),
                    'LegalHolidayPay' => floatval($payroll['legal_holiday_pay']),
                    'NightShiftDiff' => floatval($payroll['night_shift_diff']),
                    'LeavePay' => floatval($payroll['leave_pay']),
                    'ThirteenthMonthPay' => floatval($payroll['thirteenth_month_pay']),
                    'AdminFee' => floatval($payroll['admin_fee']),
                    'VAT' => floatval($payroll['vat'])
                ];
            }
            
            // Prepare summary data
            $summary_data = [
                'total_employees' => count($employees),
                'total_gross' => $total_gross,
                'total_net' => $total_net,
                'total_deductions' => $total_deductions,
                'average_salary' => count($employees) > 0 ? $total_net / count($employees) : 0,
                'department_counts' => $department_counts
            ];
            
            echo json_encode([
                'success' => true,
                'records' => $payroll_data,
                'summary' => $summary_data
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => 'Failed to load payroll history: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    // Default attendance AJAX response
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
    <title>History - Department Head</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
       /* Base Styles */
    :root {
        --primary-color: #112D4E;      /* Dark blue */
        --secondary-color: #3F72AF;    /* Medium blue */
        --accent-color: #DBE2EF;       /* Light blue/gray */
        --background-color: #F9F7F7;   /* Off white */
        --text-color: #112D4E;         /* Using dark blue for text */
        --border-color: #DBE2EF;       /* Light blue for borders */
        --shadow-color: rgba(17, 45, 78, 0.1); /* Primary color shadow */
        --success-color: #3F72AF;      /* Using secondary color for success */
        --warning-color: #DBE2EF;      /* Using accent color for warnings */
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
        color: var(--text-color);
    }

    /* Sidebar Styles */
    .sidebar {
        width: 250px;  /* Slightly reduced from 280px */
        background: linear-gradient(180deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        padding: 20px 0;
        box-shadow: 4px 0 10px var(--shadow-color);
        display: flex;
        flex-direction: column;
        color: white;
        position: fixed;
        height: 100vh;
        transition: width 0.3s ease, margin-left 0.3s ease;
        border-right: 2px solid var(--accent-color);
        z-index: 100;
        left: 0;
        top: 0;
    }

    .logo {
        font-weight: 600;
        font-size: 24px;
        padding: 25px;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 20px;
        color: white;
        letter-spacing: 1px;
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
        background-color: rgba(219, 226, 239, 0.2); /* accent color with opacity */
        color: var(--background-color);
        transform: translateX(5px);
    }

    .menu-item i {
        margin-right: 15px;
        width: 20px;
        text-align: center;
        font-size: 18px;
    }

    .logout-btn {
        background-color: var(--accent-color);
        color: var(--primary-color);
        border: 1px solid var(--secondary-color);
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
        background-color: var(--secondary-color);
        color: var(--background-color);
        transform: translateY(-2px);
    }

    .logout-btn i {
        margin-right: 10px;
    }

        
        .main-content {
            flex-grow: 1;
            padding: 30px;
            margin-left: 250px;
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
            border-right: none;
        }

        .filter-mode-buttons .date-filter-btn:last-child { border-right: none; }

        .date-filter-btn.active {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: #fff;
            border-color: #3F72AF;
            box-shadow: 0 8px 18px rgba(17,45,78,0.22);
            transform: translateY(-1px);
        }

        .date-filter-btn:not(.active):hover {
            background: linear-gradient(180deg, #F8FBFF 0%, #EEF5FF 100%);
            transform: translateY(-1px);
            border-color: #c8d8f0;
            box-shadow: 0 6px 14px rgba(63, 114, 175, 0.18);
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

        .filter-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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

        .filter-option select:focus,
        .filter-option input:focus,
        .date-input-container input[type="date"]:focus,
        .month-input-container input[type="month"]:focus,
        .year-input-container select:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.15);
        }

        /* Keep native picker icons visible to ensure month input works */
        .date-input-container input[type="date"]::-webkit-calendar-picker-indicator,
        .month-input-container input[type="month"]::-webkit-calendar-picker-indicator {
            opacity: 1;
        }

        /* Compact control widths */
        .date-input-container,
        .month-input-container,
        .year-input-container {
            width: 240px;
            max-width: 100%;
        }

        /* Custom select chevron */
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
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border-radius: 20px;
            padding: 0;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.3s ease;
            position: relative;
        }

        .modal-header {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-header h2 i {
            color: #DBE2EF;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .close:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }

        .modal-body {
            padding: 30px;
        }

        .employee-info {
            background: linear-gradient(135deg, #F8FBFF 0%, #EEF5FF 100%);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid #DBE2EF;
        }

        .employee-info h3 {
            color: #112D4E;
            margin-bottom: 20px;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .employee-info h3 i {
            color: #3F72AF;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 16px;
            color: #112D4E;
            font-weight: 500;
        }

        .attendance-details {
            background: white;
            border-radius: 16px;
            padding: 25px;
            border: 1px solid #DBE2EF;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .attendance-details h3 {
            color: #112D4E;
            margin-bottom: 20px;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .attendance-details h3 i {
            color: #3F72AF;
        }

        .time-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .time-item {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #F8FBFF 0%, #EEF5FF 100%);
            border-radius: 12px;
            border: 1px solid #DBE2EF;
        }

        .time-item .time-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .time-item .time-value {
            font-size: 18px;
            color: #112D4E;
            font-weight: 600;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .metric-item {
            text-align: center;
            padding: 15px;
            background: linear-gradient(135deg, #F8FBFF 0%, #EEF5FF 100%);
            border-radius: 12px;
            border: 1px solid #DBE2EF;
        }

        .metric-item .metric-label {
            font-size: 11px;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .metric-item .metric-value {
            font-size: 16px;
            color: #112D4E;
            font-weight: 600;
        }

        .status-section {
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #DBE2EF;
        }

        .status-badges {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .status-badge-large {
            padding: 12px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            word-break: keep-all;
            overflow-wrap: normal;
        }

        .status-badge-large.present {
            background-color: rgba(63, 114, 175, 0.15);
            color: #3F72AF;
            border: 1px solid rgba(63, 114, 175, 0.2);
        }

        .status-badge-large.absent {
            background-color: rgba(255, 107, 107, 0.15);
            color: #FF6B6B;
            border: 1px solid rgba(255, 107, 107, 0.2);
        }

        .status-badge-large.late {
            background-color: rgba(255, 235, 59, 0.15);
            color: #F57F17;
            border: 1px solid rgba(255, 235, 59, 0.2);
        }

        .status-badge-large.early_in {
            background-color: rgba(76, 175, 80, 0.15);
            color: #4CAF50;
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .status-badge-large.halfday {
            background-color: rgba(33, 150, 243, 0.15);
            color: #2196F3;
            border: 1px solid rgba(33, 150, 243, 0.2);
        }

        .status-badge-large.on_time {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 235, 59, 0.2));
            color: #FF8F00;
            border: 1px solid rgba(255, 193, 7, 0.3);
            box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3);
        }

        .status-badge-large.overtime {
            background-color: rgba(22, 199, 154, 0.15);
            color: #16C79A;
            border: 1px solid rgba(22, 199, 154, 0.2);
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Responsive modal */
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 20px;
            }

            .modal-header {
                padding: 20px;
            }

            .modal-header h2 {
                font-size: 20px;
            }

            .modal-body {
                padding: 20px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .time-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .metrics-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .status-badges {
                flex-direction: column;
            }
        }

        /* Make table rows clickable */
        table tbody tr {
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        table tbody tr:hover {
            background-color: #F8FBFF !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.15);
        }

        table tbody tr:hover::after {
            content: "Click to view details";
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(63, 114, 175, 0.9);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            pointer-events: none;
            z-index: 5;
        }
        /* Payroll History Styles - Matching Payroll.php */
        .luxury-card {
            position: relative;
            background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
            border: 2px solid transparent;
            background-clip: padding-box;
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: luxuryFloat 6s ease-in-out infinite;
        }

        .luxury-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #2C5F8A 0%, #4A90E2 50%, #87CEEB 100%);
            border-radius: 20px;
            padding: 2px;
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask-composite: exclude;
            -webkit-mask-composite: xor;
            z-index: -1;
        }

        .luxury-card:hover {
            transform: translateY(-10px) scale(1.02);
            animation: luxuryGlow 2s ease-in-out infinite;
            box-shadow: 0 20px 40px rgba(44, 95, 138, 0.25);
        }

        .luxury-card .card-glow {
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(44, 95, 138, 0.15) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .luxury-card:hover .card-glow {
            opacity: 1;
            animation: luxuryShimmer 2s linear infinite;
        }

        @keyframes luxuryFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-5px); }
        }

        @keyframes luxuryGlow {
            0%, 100% { box-shadow: 0 0 20px rgba(44, 95, 138, 0.3); }
            50% { box-shadow: 0 0 40px rgba(44, 95, 138, 0.5), 0 0 60px rgba(74, 144, 226, 0.4); }
        }

        @keyframes luxuryShimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        .department-overview {
            margin-bottom: 30px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .section-header h2 {
            font-size: 24px;
            color: #112D4E;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-header h2 i {
            color: #3F72AF;
        }

        .department-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }

        .department-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid #DBE2EF;
        }

        .department-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(63, 114, 175, 0.2);
        }

        .dept-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            margin-right: 15px;
        }

        .dept-info h4 {
            margin: 0 0 5px 0;
            color: #112D4E;
            font-size: 16px;
            font-weight: 600;
        }

        .dept-info p {
            margin: 0;
            color: #6c757d;
            font-size: 14px;
        }

        .payroll-container {
            margin-top: 30px;
        }

        .department-section {
            margin-bottom: 40px;
        }

        .department-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #DBE2EF;
        }

        .department-header h3 {
            font-size: 20px;
            color: #112D4E;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .department-header h3 i {
            color: #3F72AF;
        }

        .dept-badge {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .payroll-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .employee-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid #DBE2EF;
        }

        .employee-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(63, 114, 175, 0.2);
        }

        .employee-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .employee-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            margin-right: 15px;
        }

        .employee-info h3 {
            margin: 0 0 5px 0;
            color: #112D4E;
            font-size: 16px;
            font-weight: 600;
        }

        .employee-info p {
            margin: 0;
            color: #6c757d;
            font-size: 14px;
        }

        .employee-payroll-computation {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .employee-computation-item {
            text-align: center;
            padding: 10px;
            background: linear-gradient(135deg, #F8FBFF 0%, #EEF5FF 100%);
            border-radius: 8px;
            border: 1px solid #DBE2EF;
        }

        .employee-computation-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .employee-computation-value {
            font-size: 14px;
            color: #112D4E;
            font-weight: 600;
        }

        .employee-net-pay-banner {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
        }

        .employee-net-pay-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            opacity: 0.9;
        }

        .employee-net-pay-value {
            font-size: 20px;
            font-weight: 700;
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

        /* Ensure toggle switch is visible and properly styled - Override external CSS */
        .filter-container .filter-option {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
        }

        /* Stack layout specifically for the Show Overtime toggle */
        .filter-container .filter-option.toggle-overtime {
            display: block !important;
            align-items: initial !important;
            gap: 0 !important;
        }
        .filter-container .filter-option.toggle-overtime label[for="show_overtime"] {
            display: block !important;
            margin-bottom: 8px !important;
        }

        .filter-container .filter-option label[for="show_overtime"] {
            font-weight: 600 !important;
            color: #333 !important;
            margin: 0 !important;
            white-space: nowrap !important;
            display: inline-block !important;
        }

        /* Force toggle switch to display properly */
        #overtime-toggle {
            display: inline-block !important;
            position: relative !important;
        }

        #overtime-toggle input[type="checkbox"] {
            position: absolute !important;
            opacity: 0 !important;
            width: 0 !important;
            height: 0 !important;
        }

        #overtime-toggle .toggle-label {
            display: block !important;
            position: relative !important;
        }

        /* Additional toggle button debugging and override styles */
        .filter-container .filter-option:has(#overtime-toggle) {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            flex-direction: row !important;
        }

        .filter-container .filter-option:has(#overtime-toggle) label {
            display: inline-block !important;
            margin-bottom: 0 !important;
            margin-right: 10px !important;
        }

        /* Ensure the toggle switch container is properly sized */
        .filter-container #overtime-toggle {
            flex-shrink: 0 !important;
            width: 90px !important;
            height: 40px !important;
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
<div class="logo">
<img src="LOGO/newLogo_transparent.png" class="logo" style="width: 230px; height: 230px;padding-top: 70px;margin-bottom: 20px; margin-top: -70px; object-fit:contain; padding-bottom: -50px; padding-left: 0px; margin-right: 25px;padding: -190px; margin: 190;">
<i class="fas fa-user-shield"></i> <!-- Changed icon -->
            <span>Accounting Head Portal</span>
        </div>        
        <div class="menu">
            <a href="DeptHeadDashboard.php" class="menu-item">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="DeptHeadEmployees.php" class="menu-item ">
                <i class="fas fa-users"></i> Employees
            </a>
            <a href="DeptHeadAttendance.php" class="menu-item">
                <i class="fas fa-calendar-check"></i> Attendance
            </a>
            
            <?php if($managed_department == 'Accounting'): ?>
                <a href="Payroll.php" class="menu-item">
                    <i class="fas fa-money-bill-wave"></i> Payroll
                </a>
            <?php endif; ?>
            
            <a href="DeptHeadHistory.php" class="menu-item active">
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
            <h1 id="historyTitle">History - <?php echo (isset($_GET['department']) && $_GET['department'] !== '') ? htmlspecialchars($_GET['department']) : 'All Departments'; ?></h1>
            <div class="header-actions">
                <div class="view-toggle btn-group">
                    <button type="button" class="btn btn-primary active" id="attendanceBtn" onclick="showAttendance()">
                        <i class="fas fa-calendar-check"></i> Attendance
                    </button>
                    <?php if($managed_department == 'Accounting'): ?>
                    <button type="button" class="btn btn-primary" id="payrollBtn" onclick="showPayroll()">
                        <i class="fas fa-money-bill-wave"></i> Payroll
                    </button>
                    <?php endif; ?>
                    
                </div>
            </div>
            
        </div>

        <!-- Enhanced Filter Section -->
        <div class="filter-container card">
            <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon present"><i class="fas fa-database"></i></div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo isset($summary_data['total_records']) ? (int)$summary_data['total_records'] : 0; ?></div>
                    <div class="summary-label">Total Attendance (filtered)</div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon present"><i class="fas fa-check-circle"></i></div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo isset($summary_data['present_count']) ? (int)$summary_data['present_count'] : 0; ?></div>
                    <div class="summary-label">Present (Today: <?php echo isset($today_data['present_today']) ? (int)$today_data['present_today'] : 0; ?>)</div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon absent"><i class="fas fa-times-circle"></i></div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo isset($summary_data['absent_count']) ? (int)$summary_data['absent_count'] : 0; ?></div>
                    <div class="summary-label">Absent (Today: <?php echo isset($today_data['absent_today']) ? (int)$today_data['absent_today'] : 0; ?>)</div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon late"><i class="fas fa-clock"></i></div>
                <div class="summary-info">
                    <div class="summary-value"><?php echo isset($summary_data['late_count']) ? (int)$summary_data['late_count'] : 0; ?></div>
                    <div class="summary-label">Late (Today: <?php echo isset($today_data['late_today']) ? (int)$today_data['late_today'] : 0; ?>)</div>
                </div>
            </div>
        </div>
            <div class="filter-controls">
                <div class="search-box" style="width: 100%; margin-bottom: 20px;">
                    <input type="text" name="search" id="searchInput" placeholder="Search by employee name or ID..." 
                           value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    <i class="fas fa-search" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #112D4E;"></i>
                </div>

                    <div class="filter-mode-buttons">
                        <button type="button" class="date-filter-btn active" data-filter="date">By Date</button>
                        <button type="button" class="date-filter-btn" data-filter="month">By Month</button>
                        <button type="button" class="date-filter-btn" data-filter="year">By Year</button>
                    </div>

                    <div class="filter-input-area">
                        <div class="date-input-container" id="dateFilter">
                            <label for="date">Select Date:</label>
                            <input type="date" name="date" id="date" class="form-control" 
                                   value="<?php echo isset($_GET['date']) ? htmlspecialchars($_GET['date']) : ''; ?>">
                        </div>

                        <div class="month-input-container" id="monthFilter" style="display: none;">
                            <label for="month">Select Month:</label>
                            <input type="month" name="month" id="month" class="form-control" 
                                   value="<?php echo isset($_GET['month']) ? htmlspecialchars($_GET['month']) : ''; ?>">
                        </div>

                        <div class="year-input-container" id="yearFilter" style="display: none;">
                            <label for="year">Select Year:</label>
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
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo (isset($_GET['department']) && $_GET['department'] === $dept) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
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
                                <?php foreach ($shifts as $shift): ?>
                                    <option value="<?php echo htmlspecialchars($shift); ?>" <?php echo (isset($_GET['shift']) && $_GET['shift'] === $shift) ? 'selected' : ''; ?>>
                                        <?php echo formatShiftTime($shift); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-option">
                            <label for="status">Status:</label>
                            <select name="status" id="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="on_time" <?php echo (isset($_GET['status']) && $_GET['status'] == 'on_time') ? 'selected' : ''; ?>>On-time</option>
                                <option value="early_in" <?php echo (isset($_GET['status']) && $_GET['status'] == 'early_in') ? 'selected' : ''; ?>>Early-in</option>
                                <option value="halfday" <?php echo (isset($_GET['status']) && $_GET['status'] == 'halfday') ? 'selected' : ''; ?>>Half-day</option>
                                <option value="late" <?php echo (isset($_GET['status']) && $_GET['status'] == 'late') ? 'selected' : ''; ?>>Late</option>
                                <option value="on_leave" <?php echo (isset($_GET['status']) && $_GET['status'] == 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                            </select>
                        </div>

                        <div class="filter-option toggle-overtime">
                            <label for="show_overtime" style="font-weight: 600; color: #112D4E; display: block; margin-bottom: 8px;">Show Overtime:</label>
                            <label class="switch-btn" style="margin-bottom: 20px;">
                                <input type="checkbox" name="show_overtime" id="show_overtime" value="1" 
                                       <?php echo (isset($_GET['show_overtime']) && $_GET['show_overtime'] === '1') ? 'checked' : ''; ?>>
                                <span class="slider round"></span>
                            </label>
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                            <i class="fas fa-sync-alt"></i> Reset Filters
                        </button>
                    </div>
                </div>
        </div>

        <!-- Results Section -->
        <div class="results-container card">
            <div class="results-header">
                <h2><i class="fas fa-list"></i> Attendance Records</h2>
                <div class="results-actions"></div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Shift</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Status</th>
                            <th>Attendance Type</th>
                            <th>Late Minutes</th>
                            <th>Early Out Minutes</th>
                            <th>Overtime Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($attendance_records) > 0): ?>
                            <?php foreach ($attendance_records as $record): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($record['attendance_date']); ?></td>
                                    <td><?php echo htmlspecialchars($record['EmployeeID']); ?></td>
                                    <td><?php echo htmlspecialchars($record['EmployeeName']); ?></td>
                                    <td><?php echo htmlspecialchars($record['Department']); ?></td>
                                    <td><?php echo formatShiftTime($record['Shift']); ?></td>
                                    <td><?php echo formatTime12Hour($record['time_in']); ?></td>
                                    <td><?php echo formatTime12Hour($record['time_out']); ?></td>
                                    <td>
                                        <?php if (($record['is_on_leave'] ?? 0) == 1): ?>
                                            <span class="status-badge status-on-leave">ON-LEAVE</span>
                                        <?php elseif (!empty($record['status'])): ?>
                                            <span class="status-badge status-<?php echo htmlspecialchars($record['status']); ?>">
                                                <?php echo ucfirst(htmlspecialchars($record['status'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-absent">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $attendance_type = $record['attendance_type'];
                                        $attendance_class = $attendance_type;
                                        
                                        // Simplify to just Present or Absent
                                        if ($attendance_type === 'present') {
                                            $attendance_type = 'Present';
                                            $attendance_class = 'present';
                                        } elseif ($attendance_type === 'absent') {
                                            $attendance_type = 'Absent';
                                            $attendance_class = 'absent';
                                        }
                                        ?>
                                        <span class="attendance-type <?php echo $attendance_class; ?>">
                                            <?php echo htmlspecialchars($attendance_type); ?>
                                        </span>
                                    </td>
                                    <td><?php echo ($record['late_minutes'] > 0 ? $record['late_minutes'] : '-'); ?></td>
                                    <td><?php echo ($record['early_out_minutes'] > 0 ? $record['early_out_minutes'] : '-'); ?></td>
                                    <td><?php echo ($record['overtime_hours'] > 0 ? number_format($record['overtime_hours'], 2) : '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" style="text-align: center; padding: 30px; color: #6c757d;">
                                    <i class="fas fa-info-circle" style="font-size: 48px; margin-bottom: 15px; display: block; color: #DBE2EF;"></i>
                                    No attendance records found for the selected filters.
                                </td>
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
                        Showing <?php echo (($current_page - 1) * 50) + 1; ?> to 
                        <?php echo min($current_page * 50, $total_records); ?> of 
                        <?php echo number_format($total_records); ?> records
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
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['show_all' => '1'])); ?>" class="btn btn-primary">
                        <i class="fas fa-list"></i> Show All Records
                    </a>
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

        <!-- Payroll History Section (Hidden by default) -->
        <div id="payrollHistorySection" style="display: none;">
            <!-- Payroll History Filters -->
            <div class="filter-container card">
                <div class="filter-header">
                    <h2><i class="fas fa-calendar-alt"></i> Payroll Period Selection</h2>
                </div>
                <div class="filter-controls">
                    <div class="filter-options">
                        <div class="filter-option">
                            <label for="payrollMonth">Month:</label>
                            <input type="month" name="payrollMonth" id="payrollMonth" class="form-control" 
                                   value="<?php echo date('Y-m'); ?>">
                        </div>
                        <div class="filter-option">
                            <label for="payrollYear">Year:</label>
                            <select name="payrollYear" id="payrollYear" class="form-control">
                                <option value="">All Years</option>
                                <?php
                                $current_year = date('Y');
                                for ($year = $current_year; $year >= 2020; $year--) {
                                    echo "<option value='$year'>$year</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="filter-option">
                            <label for="payrollDepartment">Department:</label>
                            <select name="payrollDepartment" id="payrollDepartment" class="form-control">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>">
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="button" class="btn btn-primary" onclick="loadPayrollHistory()">
                            <i class="fas fa-search"></i> Load Payroll
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetPayrollFilters()">
                            <i class="fas fa-sync-alt"></i> Reset
                        </button>
                        <button type="button" class="btn btn-primary" onclick="exportPayrollHistory()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
            </div>

            <!-- Payroll Summary Cards -->
            <div id="payrollSummaryCards" class="summary-cards" style="display: none;">
                <!-- Summary cards will be populated here -->
            </div>

            <!-- Department Overview -->
            <div id="payrollDepartmentOverview" class="department-overview" style="display: none;">
                <div class="section-header">
                    <h2><i class="fas fa-building"></i> Department Overview</h2>
                </div>
                <div id="payrollDepartmentGrid" class="department-grid">
                    <!-- Department cards will be populated here -->
                </div>
            </div>

            <!-- Payroll Cards Container -->
            <div id="payrollCardsContainer" class="payroll-container" style="display: none;">
                <!-- Payroll cards will be populated here -->
            </div>

            <!-- Loading State -->
            <div id="payrollLoadingState" class="results-container card" style="display: none;">
                <div style="text-align: center; padding: 50px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #3F72AF; margin-bottom: 20px;"></i>
                    <h3>Loading Payroll Data...</h3>
                    <p>Please wait while we fetch the payroll information.</p>
                </div>
            </div>

            <!-- No Data State -->
            <div id="payrollNoDataState" class="results-container card" style="display: none;">
                <div style="text-align: center; padding: 50px; color: #6c757d;">
                    <i class="fas fa-info-circle" style="font-size: 48px; margin-bottom: 20px; color: #DBE2EF;"></i>
                    <h3>No Payroll Data Found</h3>
                    <p>Please select a different month or year to view payroll history.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Details Modal -->
    <div id="attendanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-calendar-check"></i> Attendance Details</h2>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <div class="employee-info">
                    <h3><i class="fas fa-user"></i> Employee Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Employee ID</div>
                            <div class="info-value" id="modalEmployeeId">-</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Employee Name</div>
                            <div class="info-value" id="modalEmployeeName">-</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Department</div>
                            <div class="info-value" id="modalDepartment">-</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Shift</div>
                            <div class="info-value" id="modalShift">-</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Date</div>
                            <div class="info-value" id="modalDate">-</div>
                        </div>
                    </div>
                </div>

                <div class="attendance-details">
                    <h3><i class="fas fa-clock"></i> Time Records</h3>
                    <div class="time-grid">
                        <div class="time-item">
                            <div class="time-label">Time In</div>
                            <div class="time-value" id="modalTimeIn">-</div>
                        </div>
                        <div class="time-item">
                            <div class="time-label">Time Out</div>
                            <div class="time-value" id="modalTimeOut">-</div>
                        </div>
                        <div class="time-item">
                            <div class="time-label">Morning In</div>
                            <div class="time-value" id="modalMorningIn">-</div>
                        </div>
                        <div class="time-item">
                            <div class="time-label">Morning Out</div>
                            <div class="time-value" id="modalMorningOut">-</div>
                        </div>
                        <div class="time-item">
                            <div class="time-label">Afternoon In</div>
                            <div class="time-value" id="modalAfternoonIn">-</div>
                        </div>
                        <div class="time-item">
                            <div class="time-label">Afternoon Out</div>
                            <div class="time-value" id="modalAfternoonOut">-</div>
                        </div>
                    </div>

                    <div class="metrics-grid">
                        <div class="metric-item">
                            <div class="metric-label">Late Minutes</div>
                            <div class="metric-value" id="modalLateMinutes">-</div>
                        </div>
                        <div class="metric-item">
                            <div class="metric-label">Early Out Minutes</div>
                            <div class="metric-value" id="modalEarlyOutMinutes">-</div>
                        </div>
                        <div class="metric-item">
                            <div class="metric-label">Overtime Hours</div>
                            <div class="metric-value" id="modalOvertimeHours">-</div>
                        </div>
                    </div>

                    <div class="status-section">
                        <h3><i class="fas fa-info-circle"></i> Status</h3>
                        <div class="status-badges" id="modalStatusBadges">
                            <!-- Status badges will be populated here -->
                        </div>
                    </div>
                </div>
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
        
        // JavaScript functions to format time in 12-hour format
        function formatTime12HourJS(time) {
            if (!time || time === '00:00:00' || time === '00:00' || time === null) {
                return '-';
            }
            
            try {
                // Handle different time formats
                let timeStr = time.toString();
                
                // If it's already in 12-hour format, return as is
                if (timeStr.includes('AM') || timeStr.includes('PM')) {
                    return timeStr;
                }
                
                // Convert 24-hour to 12-hour format
                const [hours, minutes] = timeStr.split(':');
                const hour24 = parseInt(hours);
                const mins = minutes || '00';
                
                if (hour24 === 0) {
                    return `12:${mins} AM`;
                } else if (hour24 < 12) {
                    return `${hour24}:${mins} AM`;
                } else if (hour24 === 12) {
                    return `12:${mins} PM`;
                } else {
                    return `${hour24 - 12}:${mins} PM`;
                }
            } catch (e) {
                console.error('Error formatting time:', time, e);
                return time || '-';
            }
        }

        function formatShiftTimeJS(shift) {
            if (!shift || shift === null) {
                return '-';
            }
            
            try {
                const shiftStr = shift.toString();
                
                // Handle different shift formats
                if (shiftStr.includes('-')) {
                    const times = shiftStr.split('-');
                    if (times.length === 2) {
                        const start = times[0].trim();
                        const end = times[1].trim();
                        return formatTime12HourJS(start) + ' - ' + formatTime12HourJS(end);
                    }
                }
                
                return formatTime12HourJS(shiftStr);
            } catch (e) {
                console.error('Error formatting shift:', shift, e);
                return shift || '-';
            }
        }

        // Enhanced AJAX filtering with real-time updates
        document.addEventListener('DOMContentLoaded', function() {
            const filterButtons = document.querySelectorAll('.date-filter-btn');
            const dateFilter = document.getElementById('dateFilter');
            const monthFilter = document.getElementById('monthFilter');
            const yearFilter = document.getElementById('yearFilter');
            
            // Modal functionality
            const modal = document.getElementById('attendanceModal');
            const closeBtn = document.querySelector('.close');

            // Close modal when clicking the X button
            if (closeBtn) {
                closeBtn.onclick = function() {
                    modal.classList.remove('show');
                }
            }

            // Close modal when clicking outside of it
            window.onclick = function(event) {
                if (event.target === modal) {
                    modal.classList.remove('show');
                }
            }

            // Close modal with Escape key
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && modal.classList.contains('show')) {
                    modal.classList.remove('show');
                }
            });
            
            // Add click handlers to initial table
            addRowClickHandlers();
            
            // Filter mode toggle functionality
            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const filterType = this.getAttribute('data-filter');
                    // Update active button
                    filterButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    // Show the appropriate filter input
                    dateFilter.style.display = filterType === 'date' ? 'block' : 'none';
                    monthFilter.style.display = filterType === 'month' ? 'block' : 'none';
                    yearFilter.style.display = filterType === 'year' ? 'block' : 'none';
                    // Clear non-active inputs to avoid stale state
                    const dateEl = document.getElementById('date');
                    const monthEl = document.getElementById('month');
                    const yearEl = document.getElementById('year');
                    if (filterType !== 'date' && dateEl) dateEl.value = '';
                    if (filterType !== 'month' && monthEl) monthEl.value = '';
                    if (filterType !== 'year' && yearEl) yearEl.value = '';
                    // Trigger refresh
                    refreshDeptHistory();
                });
            });
            
            // Initialize filter mode based on URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            const hasDate = urlParams.get('date');
            const hasMonth = urlParams.get('month');
            const hasYear = urlParams.get('year');
            if (hasDate) {
                document.querySelector('[data-filter="date"]').click();
            } else if (hasMonth) {
                document.querySelector('[data-filter="month"]').click();
            } else {
                document.querySelector('[data-filter="year"]').click();
                const yearSelect = document.getElementById('year');
                if (yearSelect && !hasYear) {
                    const serverSelectedYear = '<?php echo isset($filter_year) ? htmlspecialchars($filter_year) : date('Y'); ?>';
                    yearSelect.value = serverSelectedYear;
                }
            }

            // Build parameters for AJAX request
            function buildParams() {
                const params = new URLSearchParams();
                // Get current filter values
                const date = document.getElementById('date') ? document.getElementById('date').value : '';
                const month = document.getElementById('month') ? document.getElementById('month').value : '';
                const year = document.getElementById('year') ? document.getElementById('year').value : '';
                const department = document.getElementById('department') ? document.getElementById('department').value : '';
                const attendanceType = document.getElementById('attendance_type') ? document.getElementById('attendance_type').value : '';
                const shift = document.getElementById('shift') ? document.getElementById('shift').value : '';
                const status = document.getElementById('status') ? document.getElementById('status').value : '';
                const search = document.getElementById('searchInput') ? document.getElementById('searchInput').value : '';
                
                // Set date filter (mutually exclusive)
                if (date) {
                    params.set('date', date);
                } else if (month) {
                    params.set('month', month);
                } else if (year) {
                    params.set('year', year);
                }
                
                // Set other filters
                if (department && department !== '') params.set('department', department);
                if (attendanceType && attendanceType !== '') params.set('attendance_type', attendanceType);
                if (shift && shift !== '') params.set('shift', shift);
                if (status && status !== '') params.set('status', status);
                if (search && search.trim() !== '') params.set('search', search.trim());
                
                return params;
            }

            // AJAX function to refresh data
            async function refreshDeptHistory() {
                try {
                    // Show loading indicator
                    showLoadingIndicator();
                    
                    const params = buildParams();
                    params.set('ajax', '1');
                    
                    const res = await fetch('DeptHeadHistory.php?' + params.toString());
                    const data = await res.json();
                    
                    if (!data.success) {
                        console.error('Failed to fetch data');
                        return;
                    }
                    
                    updateTable(data.records || []);
                    updateSummaries(data.summary || {});
                    
                    // Update URL without page reload
                    const newUrl = window.location.pathname + '?' + params.toString();
                    window.history.pushState({}, '', newUrl);
                    
                } catch (e) { 
                    console.error('Error fetching data:', e);
                    showError('Failed to load data. Please try again.');
                } finally {
                    hideLoadingIndicator();
                }
            }

            // Update table with new data
            function updateTable(records) {
                const tbody = document.querySelector('.results-container table tbody');
                if (!tbody) return;
                
                if (!records.length) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 30px; color: #6c757d;">
                                <i class="fas fa-info-circle" style="font-size: 48px; margin-bottom: 15px; display: block; color: #DBE2EF;"></i>
                                No attendance records found for the selected filters.
                            </td>
                        </tr>
                    `;
                    return;
                }
                
                const rows = records.map(r => {
                    const status = r.status || '';
                    let statusBadge = '';
                    if (r.is_on_leave == 1) {
                        statusBadge = '<span class="status-badge status-on-leave">ON-LEAVE</span>';
                    } else if (status) {
                        let statusClass = status;
                        let statusText = status;
                        
                        // Map status values to display text and CSS classes
                        switch (status) {
                            case 'on_time':
                                statusClass = 'present';
                                statusText = 'On-time';
                                break;
                            case 'early_in':
                                statusClass = 'early';
                                statusText = 'Early-in';
                                break;
                            case 'halfday':
                                statusClass = 'halfday';
                                statusText = 'Half-day';
                                break;
                            case 'late':
                                statusClass = 'late';
                                statusText = 'Late';
                                break;
                            default:
                                statusClass = status;
                                statusText = status.charAt(0).toUpperCase() + status.slice(1);
                        }
                        
                        statusBadge = `<span class="status-badge status-${statusClass}">${statusText}</span>`;
                    } else {
                        statusBadge = '<span class="status-badge status-absent">-</span>';
                    }
                    
                    // Simplified attendance type display
                    let attendanceType = r.attendance_type || '-';
                    let attendanceClass = attendanceType;
                    let attendanceText = attendanceType;
                    
                    if (attendanceType === 'present') {
                        attendanceText = 'Present';
                        attendanceClass = 'present';
                    } else if (attendanceType === 'absent') {
                        attendanceText = 'Absent';
                        attendanceClass = 'absent';
                    }
                    
                    
                    return `
                        <tr>
                            <td>${r.attendance_date || '-'}</td>
                            <td>${r.EmployeeID || ''}</td>
                            <td>${r.EmployeeName || ''}</td>
                            <td>${r.Department || ''}</td>
                            <td>${formatShiftTimeJS(r.Shift)}</td>
                            <td>${formatTime12HourJS(r.time_in)}</td>
                            <td>${formatTime12HourJS(r.time_out)}</td>
                            <td>${statusBadge}</td>
                            <td>
                                <span class="attendance-type ${attendanceClass}">
                                    ${attendanceText}
                                </span>
                            </td>
                            <td>${r.late_minutes > 0 ? r.late_minutes : '-'}</td>
                            <td>${r.early_out_minutes > 0 ? r.early_out_minutes : '-'}</td>
                            <td>${r.overtime_hours > 0 ? Number(r.overtime_hours).toFixed(2) : '-'}</td>
                        </tr>
                    `;
                }).join('');
                
                tbody.innerHTML = rows;
            }

            // Update summary cards
            function updateSummaries(summary) {
                const values = document.querySelectorAll('.summary-value');
                if (values && values.length >= 4) {
                    values[0].textContent = summary.total_records ?? 0;
                    values[1].textContent = summary.present_count ?? 0;
                    values[2].textContent = summary.absent_count ?? 0;
                    values[3].textContent = summary.late_count ?? 0;
                }
            }

            // Loading indicator functions
            function showLoadingIndicator() {
                const tbody = document.querySelector('.results-container table tbody');
                if (tbody) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 30px;">
                                <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #3F72AF; margin-right: 10px;"></i>
                                Loading...
                            </td>
                        </tr>
                    `;
                }
            }

            function hideLoadingIndicator() {
                // Loading indicator will be replaced by updateTable
            }

            function showError(message) {
                const tbody = document.querySelector('.results-container table tbody');
                if (tbody) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 30px; color: #ff4757;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-right: 10px;"></i>
                                ${message}
                            </td>
                        </tr>
                    `;
                }
            }

            // Reset filters function
            window.resetFilters = function() {
                // Clear all filter inputs
                document.getElementById('date').value = '';
                document.getElementById('month').value = '';
                document.getElementById('year').value = '';
                document.getElementById('department').value = '';
                document.getElementById('attendance_type').value = '';
                document.getElementById('shift').value = '';
                document.getElementById('status').value = '';
                document.getElementById('searchInput').value = '';
                
                // Reset to year filter mode
                document.querySelector('[data-filter="year"]').click();
                
                // Refresh data
                refreshDeptHistory();
            };

            // Add event listeners for real-time filtering
            const filterElements = [
                'date', 'month', 'year', 'department', 'attendance_type', 'shift', 'status'
            ];
            
            filterElements.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('change', () => {
                        refreshDeptHistory();
                    });
                }
            });

            // Search input with debouncing
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', () => {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        refreshDeptHistory();
                    }, 500); // 500ms delay for search
                });
            }

            // Initial load
            refreshDeptHistory();

            // Function to show attendance details modal
            window.showAttendanceDetails = async function(employeeId, attendanceDate) {
                console.log('showAttendanceDetails called with:', employeeId, attendanceDate);
                try {
                    // Show loading state
                    showModalLoading();
                    console.log('Loading state shown');

                    // Fetch detailed attendance data
                    const url = `fetch_attendance_details.php?employee_id=${encodeURIComponent(employeeId)}&attendance_date=${encodeURIComponent(attendanceDate)}`;
                    console.log('Fetching from URL:', url);
                    
                    const response = await fetch(url);
                    console.log('Response received:', response.status);
                    
                    const result = await response.json();
                    console.log('Result:', result);

                    if (!result.success) {
                        throw new Error(result.error || 'Failed to fetch attendance details');
                    }

                    // Populate modal with data
                    populateModal(result.data);
                    console.log('Modal populated');

                    // Show modal
                    modal.classList.add('show');
                    console.log('Modal shown');

                } catch (error) {
                    console.error('Error fetching attendance details:', error);
                    showModalError('Failed to load attendance details. Please try again.');
                }
            };

            // Function to show loading state in modal
            function showModalLoading() {
                document.getElementById('modalEmployeeId').textContent = 'Loading...';
                document.getElementById('modalEmployeeName').textContent = 'Loading...';
                document.getElementById('modalDepartment').textContent = 'Loading...';
                document.getElementById('modalShift').textContent = 'Loading...';
                document.getElementById('modalDate').textContent = 'Loading...';
                document.getElementById('modalTimeIn').textContent = 'Loading...';
                document.getElementById('modalTimeOut').textContent = 'Loading...';
                document.getElementById('modalMorningIn').textContent = 'Loading...';
                document.getElementById('modalMorningOut').textContent = 'Loading...';
                document.getElementById('modalAfternoonIn').textContent = 'Loading...';
                document.getElementById('modalAfternoonOut').textContent = 'Loading...';
                document.getElementById('modalLateMinutes').textContent = 'Loading...';
                document.getElementById('modalEarlyOutMinutes').textContent = 'Loading...';
                document.getElementById('modalOvertimeHours').textContent = 'Loading...';
                document.getElementById('modalStatusBadges').innerHTML = '<div class="status-badge-large present">Loading...</div>';
            }

            // Function to show error in modal
            function showModalError(message) {
                document.getElementById('modalEmployeeId').textContent = 'Error';
                document.getElementById('modalEmployeeName').textContent = message;
                document.getElementById('modalDepartment').textContent = '-';
                document.getElementById('modalShift').textContent = '-';
                document.getElementById('modalDate').textContent = '-';
                document.getElementById('modalTimeIn').textContent = '-';
                document.getElementById('modalTimeOut').textContent = '-';
                document.getElementById('modalMorningIn').textContent = '-';
                document.getElementById('modalMorningOut').textContent = '-';
                document.getElementById('modalAfternoonIn').textContent = '-';
                document.getElementById('modalAfternoonOut').textContent = '-';
                document.getElementById('modalLateMinutes').textContent = '-';
                document.getElementById('modalEarlyOutMinutes').textContent = '-';
                document.getElementById('modalOvertimeHours').textContent = '-';
                document.getElementById('modalStatusBadges').innerHTML = '<div class="status-badge-large absent">Error</div>';
            }

            // Function to populate modal with attendance data
            function populateModal(data) {
                console.log('populateModal called with data:', data);
                
                // Employee information
                const employeeIdEl = document.getElementById('modalEmployeeId');
                const employeeNameEl = document.getElementById('modalEmployeeName');
                const departmentEl = document.getElementById('modalDepartment');
                const shiftEl = document.getElementById('modalShift');
                const dateEl = document.getElementById('modalDate');
                
                console.log('Modal elements found:', {
                    employeeIdEl: !!employeeIdEl,
                    employeeNameEl: !!employeeNameEl,
                    departmentEl: !!departmentEl,
                    shiftEl: !!shiftEl,
                    dateEl: !!dateEl
                });
                
                if (employeeIdEl) {
                    employeeIdEl.textContent = data.EmployeeID || '-';
                    console.log('Set EmployeeID to:', data.EmployeeID || '-');
                } else {
                    console.error('modalEmployeeId element not found!');
                }
                if (employeeNameEl) {
                    employeeNameEl.textContent = data.EmployeeName || '-';
                    console.log('Set EmployeeName to:', data.EmployeeName || '-');
                } else {
                    console.error('modalEmployeeName element not found!');
                }
                if (departmentEl) {
                    departmentEl.textContent = data.Department || '-';
                    console.log('Set Department to:', data.Department || '-');
                } else {
                    console.error('modalDepartment element not found!');
                }
                if (shiftEl) {
                    shiftEl.textContent = data.Shift || '-';
                    console.log('Set Shift to:', data.Shift || '-');
                } else {
                    console.error('modalShift element not found!');
                }
                if (dateEl) {
                    dateEl.textContent = data.attendance_date || '-';
                    console.log('Set Date to:', data.attendance_date || '-');
                } else {
                    console.error('modalDate element not found!');
                }
                
                console.log('Employee info populated');

                // Time records
                const timeInEl = document.getElementById('modalTimeIn');
                const timeOutEl = document.getElementById('modalTimeOut');
                const morningInEl = document.getElementById('modalMorningIn');
                const morningOutEl = document.getElementById('modalMorningOut');
                const afternoonInEl = document.getElementById('modalAfternoonIn');
                const afternoonOutEl = document.getElementById('modalAfternoonOut');
                
                console.log('Time record elements found:', {
                    timeInEl: !!timeInEl,
                    timeOutEl: !!timeOutEl,
                    morningInEl: !!morningInEl,
                    morningOutEl: !!morningOutEl,
                    afternoonInEl: !!afternoonInEl,
                    afternoonOutEl: !!afternoonOutEl
                });
                
                if (timeInEl) timeInEl.textContent = data.time_in || '-';
                if (timeOutEl) timeOutEl.textContent = data.time_out || '-';
                if (morningInEl) morningInEl.textContent = data.time_in_morning || '-';
                if (morningOutEl) morningOutEl.textContent = data.time_out_morning || '-';
                if (afternoonInEl) afternoonInEl.textContent = data.time_in_afternoon || '-';
                if (afternoonOutEl) afternoonOutEl.textContent = data.time_out_afternoon || '-';

                // Metrics
                const lateMinutesEl = document.getElementById('modalLateMinutes');
                const earlyOutMinutesEl = document.getElementById('modalEarlyOutMinutes');
                const overtimeHoursEl = document.getElementById('modalOvertimeHours');
                
                console.log('Metrics elements found:', {
                    lateMinutesEl: !!lateMinutesEl,
                    earlyOutMinutesEl: !!earlyOutMinutesEl,
                    overtimeHoursEl: !!overtimeHoursEl
                });
                
                // Determine halfday status and session type
                const isHalfDay = (data.status === 'half_day' || data.status === 'halfday');
                const hasAmPairOnly = data.time_in_morning && data.time_out_morning && !data.time_in_afternoon && !data.time_out_afternoon;
                const hasPmPairOnly = data.time_in_afternoon && data.time_out_afternoon && !data.time_in_morning && !data.time_out_morning;
                
                // For halfday: remove late mins if only afternoon attended, remove early out mins if only morning attended
                if (lateMinutesEl) {
                    if (isHalfDay && hasPmPairOnly) {
                        lateMinutesEl.textContent = '-';
                    } else {
                        lateMinutesEl.textContent = data.late_minutes > 0 ? data.late_minutes + ' min' : '-';
                    }
                }
                if (earlyOutMinutesEl) {
                    if (isHalfDay && hasAmPairOnly) {
                        earlyOutMinutesEl.textContent = '-';
                    } else {
                        earlyOutMinutesEl.textContent = data.early_out_minutes > 0 ? data.early_out_minutes + ' min' : '-';
                    }
                }
                if (overtimeHoursEl) overtimeHoursEl.textContent = data.overtime_hours > 0 ? data.overtime_hours.toFixed(2) + ' hrs' : '-';

                // Status badges
                const statusBadges = document.getElementById('modalStatusBadges');
                statusBadges.innerHTML = '';

                // Attendance type badge
                const attendanceType = data.attendance_type || 'absent';
                const attendanceClass = attendanceType === 'present' ? 'present' : 'absent';
                const attendanceText = attendanceType === 'present' ? 'Present' : 'Absent';
                statusBadges.innerHTML += `<div class="status-badge-large ${attendanceClass}"><i class="fas fa-${attendanceType === 'present' ? 'check' : 'times'}-circle"></i> ${attendanceText}</div>`;

                // Status badge (late, early, etc.)
                if (data.status && data.status !== 'no_record') {
                    const isLate = Number(data.late_minutes || 0) > 0;
                    const statusClass = isLate ? 'late' : (data.status === 'on_time' ? 'present' : data.status);
                    const statusText = isLate ? 'Late' : (data.status === 'on_time' ? 'On Time' : data.status.charAt(0).toUpperCase() + data.status.slice(1));
                    const statusIcon = isLate ? 'clock' : (data.status === 'early' ? 'clock' : 'check-circle');
                    statusBadges.innerHTML += `<div class="status-badge-large ${statusClass}"><i class="fas fa-${statusIcon}"></i> ${statusText}</div>`;
                }

                // Overtime badge
                if (data.is_overtime == 1 || data.overtime_hours > 0) {
                    statusBadges.innerHTML += `<div class="status-badge-large overtime"><i class="fas fa-clock"></i> Overtime</div>`;
                }
            }

            // Function to add click handlers to table rows
            function addRowClickHandlers() {
                const tableRows = document.querySelectorAll('.results-container table tbody tr');
                console.log('Found table rows:', tableRows.length);
                
                tableRows.forEach((row, index) => {
                    // Skip the "no records" row
                    if (row.querySelector('td[colspan]')) {
                        console.log('Skipping row with colspan:', index);
                        return;
                    }

                    row.addEventListener('click', function() {
                        console.log('Row clicked:', index);
                        const cells = row.querySelectorAll('td');
                        console.log('Cells found:', cells.length);
                        
                        if (cells.length >= 3) {
                            const employeeId = cells[1].textContent.trim();
                            const attendanceDate = cells[0].textContent.trim();
                            
                            console.log('Employee ID:', employeeId);
                            console.log('Attendance Date:', attendanceDate);
                            
                            if (employeeId && attendanceDate) {
                                console.log('Calling showAttendanceDetails...');
                                showAttendanceDetails(employeeId, attendanceDate);
                            } else {
                                console.log('Missing employee ID or date');
                            }
                        } else {
                            console.log('Not enough cells in row');
                        }
                    });
                });
            }

            // Update the updateTable function to include row click handlers
            const originalUpdateTable = updateTable;
            updateTable = function(records) {
                originalUpdateTable(records);
                // Add click handlers after table is updated
                setTimeout(addRowClickHandlers, 100);
            };
        });

        function showAttendance() {
            document.getElementById('attendanceBtn').classList.add('active');
            document.getElementById('payrollBtn').classList.remove('active');
            
            // Show attendance section
            document.querySelector('.filter-container').style.display = 'block';
            document.querySelector('.results-container').style.display = 'block';
            document.getElementById('payrollHistorySection').style.display = 'none';
            
            // Update title
            document.getElementById('historyTitle').innerHTML = 'History - <?php echo (isset($_GET['department']) && $_GET['department'] !== '') ? htmlspecialchars($_GET['department']) : 'All Departments'; ?>';
        }

        function showPayroll() {
            // Redirect to PayrollHistory.php with current month filter
            const currentDate = new Date();
            const currentMonth = currentDate.getFullYear() + '-' + String(currentDate.getMonth() + 1).padStart(2, '0');
            window.location.href = `PayrollHistory.php?month=${currentMonth}`;
        }

        function testModal() {
            console.log('Test modal button clicked');
            const modal = document.getElementById('attendanceModal');
            if (modal) {
                console.log('Modal element found');
                modal.classList.add('show');
                console.log('Modal should be visible now');
                
                // Test with hardcoded data
                const testData = {
                    EmployeeID: 'TEST001',
                    EmployeeName: 'Test Employee',
                    Department: 'Test Department',
                    Shift: '08:00-17:00',
                    attendance_date: '2025-01-01',
                    time_in: '09:00 AM',
                    time_out: '05:00 PM',
                    time_in_morning: '09:00 AM',
                    time_out_morning: '12:00 PM',
                    time_in_afternoon: '01:00 PM',
                    time_out_afternoon: '05:00 PM',
                    late_minutes: 60,
                    early_out_minutes: 0,
                    overtime_hours: 2.5,
                    attendance_type: 'present',
                    status: 'late'
                };
                
                console.log('Testing modal with hardcoded data:', testData);
                populateModal(testData);
            } else {
                console.log('Modal element not found');
            }
        }

        // Function to open employee modal with attendance details
        async function openEmployeeModal(employeeId, employeeName, attendanceDate) {
            try {
                const modal = document.getElementById('attendanceModal');
                const modalBody = document.getElementById('modalEmployeeId');
                
                console.log('Opening modal for:', { employeeId, employeeName, attendanceDate });
                
                if (!modal) {
                    console.error('Attendance modal not found');
                    return;
                }
                
                // Show loading state
                if (modalBody) {
                    modalBody.textContent = 'Loading...';
                }
                
                // Show modal
                modal.classList.add('show');
                
                // Fetch attendance details
                const url = `fetch_attendance_details.php?employee_id=${employeeId}&attendance_date=${attendanceDate}`;
                console.log('Fetching from URL:', url);
                
                const response = await fetch(url);
                console.log('Response status:', response.status);
                console.log('Response ok:', response.ok);
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Response error:', errorText);
                    throw new Error(`HTTP ${response.status}: ${errorText}`);
                }
                
                const data = await response.json();
                console.log('Fetched data:', data);
                
                if (data.success && data.attendance) {
                    // Populate modal with attendance data using existing function
                    console.log('Populating modal with:', data.attendance);
                    populateModal(data.attendance);
                } else {
                    console.error('Failed to load attendance details:', data);
                    
                    // Test with hardcoded data to see if populateModal works
                    console.log('Testing with hardcoded data...');
                    const testData = {
                        EmployeeID: employeeId,
                        EmployeeName: employeeName,
                        Department: 'Test Department',
                        Shift: '08:00-17:00',
                        attendance_date: attendanceDate,
                        time_in: '09:00 AM',
                        time_out: '05:00 PM',
                        time_in_morning: '09:00 AM',
                        time_out_morning: '12:00 PM',
                        time_in_afternoon: '01:00 PM',
                        time_out_afternoon: '05:00 PM',
                        late_minutes: 60,
                        early_out_minutes: 0,
                        overtime_hours: 2.5,
                        attendance_type: 'present',
                        status: 'late'
                    };
                    populateModal(testData);
                    
                    throw new Error(data.message || data.error || 'Failed to load attendance details');
                }
                
            } catch (error) {
                console.error('Error opening employee modal:', error);
                if (modalBody) {
                    modalBody.textContent = 'Error: ' + error.message;
                }
            }
        }
        

        // Function to handle per-page change
        function changePerPage() {
            const perPage = document.getElementById('per_page').value;
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('per_page', perPage);
            currentUrl.searchParams.delete('page'); // Reset to page 1
            currentUrl.searchParams.delete('ajax'); // Remove ajax flag
            window.location.href = currentUrl.toString();
        }

        // Payroll History Functions
        function loadPayrollHistory() {
            const month = document.getElementById('payrollMonth').value;
            const year = document.getElementById('payrollYear').value;
            const department = document.getElementById('payrollDepartment').value;
            
            if (!month && !year) {
                alert('Please select a month or year to view payroll history.');
                return;
            }
            
            // Show loading state
            showPayrollLoading();
            
            // Build parameters
            const params = new URLSearchParams();
            if (month) params.set('payroll_month', month);
            if (year) params.set('payroll_year', year);
            if (department) params.set('payroll_department', department);
            params.set('ajax', '1');
            params.set('action', 'payroll_history');
            
            // Fetch payroll history data
            console.log('Fetching payroll data with params:', params.toString());
            fetch('DeptHeadHistory.php?' + params.toString())
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Received data:', data);
                    console.log('Data type:', typeof data);
                    console.log('Data success:', data.success);
                    console.log('Data records length:', data.records ? data.records.length : 'no records');
                    
                    if (data && data.success === true) {
                        console.log('Success! Updating display...');
                        updatePayrollHistoryDisplay(data.records || [], data.summary || {});
                    } else {
                        console.error('Payroll data error:', data.error);
                        showPayrollError(data.error || 'Failed to load payroll data');
                    }
                })
                .catch(error => {
                    console.error('Error loading payroll history:', error);
                    showPayrollError('Failed to load payroll data. Please try again.');
                });
        }
        
        function showPayrollLoading() {
            // Hide all sections
            document.getElementById('payrollSummaryCards').style.display = 'none';
            document.getElementById('payrollDepartmentOverview').style.display = 'none';
            document.getElementById('payrollCardsContainer').style.display = 'none';
            document.getElementById('payrollNoDataState').style.display = 'none';
            
            // Show loading state
            document.getElementById('payrollLoadingState').style.display = 'block';
        }
        
        function updatePayrollHistoryDisplay(records, summary) {
            console.log('Updating payroll display with:', records.length, 'records');
            console.log('Summary:', summary);
            
            // Hide loading state
            document.getElementById('payrollLoadingState').style.display = 'none';
            
            if (!records || !records.length) {
                showPayrollNoData();
                return;
            }
            
            // Update summary cards
            updatePayrollSummaryCards(summary);
            
            // Update department overview
            updatePayrollDepartmentOverview(summary.department_counts || {});
            
            // Update payroll cards
            updatePayrollCards(records);
        }
        
        function updatePayrollSummaryCards(summary) {
            console.log('Updating summary cards with:', summary);
            const summaryCards = document.getElementById('payrollSummaryCards');
            
            if (!summaryCards) {
                console.error('payrollSummaryCards element not found!');
                return;
            }
            
            summaryCards.innerHTML = `
                <div class="summary-card luxury-card">
                    <div class="card-header">
                        <h3><i class="fas fa-users"></i> Total Employees</h3>
                    </div>
                    <div class="card-content">
                        <div class="card-value">${summary.total_employees || 0}</div>
                        <div class="card-subtitle">Active Employees</div>
                    </div>
                    <div class="card-glow"></div>
                </div>
                
                <div class="summary-card luxury-card">
                    <div class="card-header">
                        <h3><i class="fas fa-money-bill-wave"></i> Total Payroll Amount</h3>
                    </div>
                    <div class="card-content">
                        <div class="card-value">₱${Number(summary.total_net || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                        <div class="card-subtitle">Net Pay Total</div>
                    </div>
                    <div class="card-glow"></div>
                </div>
                
                <div class="summary-card luxury-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Average Salary</h3>
                    </div>
                    <div class="card-content">
                        <div class="card-value">₱${Number(summary.average_salary || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                        <div class="card-subtitle">Per Employee</div>
                    </div>
                    <div class="card-glow"></div>
                </div>
                
                <div class="summary-card luxury-card">
                    <div class="card-header">
                        <h3><i class="fas fa-percentage"></i> Total Deductions</h3>
                    </div>
                    <div class="card-content">
                        <div class="card-value">₱${Number(summary.total_deductions || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                        <div class="card-subtitle">All Deductions</div>
                    </div>
                    <div class="card-glow"></div>
                </div>
            `;
            summaryCards.style.display = 'grid';
        }
        
        function updatePayrollDepartmentOverview(departmentCounts) {
            const departmentGrid = document.getElementById('payrollDepartmentGrid');
            const departmentOverview = document.getElementById('payrollDepartmentOverview');
            
            if (Object.keys(departmentCounts).length === 0) {
                departmentOverview.style.display = 'none';
                return;
            }
            
            departmentGrid.innerHTML = Object.entries(departmentCounts).map(([deptName, count]) => {
                const deptIcon = deptName.charAt(0).toUpperCase();
                return `
                    <div class="department-card" onclick="filterPayrollByDepartment('${deptName}')" data-dept="${deptName}">
                        <div class="dept-icon">${deptIcon}</div>
                        <div class="dept-info">
                            <h4>${deptName}</h4>
                            <p>${count} Employee${count > 1 ? 's' : ''}</p>
                        </div>
                    </div>
                `;
            }).join('');
            
            departmentOverview.style.display = 'block';
        }
        
        function updatePayrollCards(records) {
            console.log('Updating payroll cards with:', records.length, 'records');
            const payrollContainer = document.getElementById('payrollCardsContainer');
            
            if (!payrollContainer) {
                console.error('payrollCardsContainer element not found!');
                return;
            }
            
            // Group employees by department
            const employeesByDept = {};
            records.forEach(record => {
                const dept = record.Department || 'Unassigned';
                if (!employeesByDept[dept]) {
                    employeesByDept[dept] = [];
                }
                employeesByDept[dept].push(record);
            });
            
            console.log('Grouped employees by department:', employeesByDept);
            
            let html = '';
            Object.entries(employeesByDept).forEach(([deptName, deptEmployees]) => {
                html += `
                    <div class="department-section" data-department="${deptName}">
                        <div class="department-header">
                            <h3>
                                <i class="fas fa-building"></i>
                                ${deptName}
                            </h3>
                            <span class="dept-badge">${deptEmployees.length} Employee${deptEmployees.length > 1 ? 's' : ''}</span>
                        </div>
                        
                        <div class="payroll-grid">
                `;
                
                deptEmployees.forEach(employee => {
                    html += `
                        <div class="employee-card" onclick="viewPayrollDetails('${employee.EmployeeID}', '${employee.PayPeriod}')" 
                             data-employee-id="${employee.EmployeeID}"
                             data-employee-name="${employee.EmployeeName}"
                             data-department="${employee.Department}"
                             data-days-worked="${employee.DaysWorked}"
                             data-total-hours="${employee.TotalHours}"
                             data-base-salary="${employee.BaseSalary}"
                             data-overtime-pay="${employee.OvertimePay}"
                             data-special-holiday-pay="${employee.SpecialHolidayPay}"
                             data-legal-holiday-pay="${employee.LegalHolidayPay}"
                             data-night-shift-diff="${employee.NightShiftDiff}"
                             data-leave-pay="${employee.LeavePay}"
                             data-13th-month="${employee.ThirteenthMonthPay}"
                             data-lates="${employee.LateAmount}"
                             data-phic-employee="${employee.PHICEmployee}"
                             data-phic-employer="${employee.PHICEmployer}"
                             data-pagibig-employee="${employee.PagIBIGEmployee}"
                             data-pagibig-employer="${employee.PagIBIGEmployer}"
                             data-sss-employee="${employee.SSSEmployee}"
                             data-sss-employer="${employee.SSSEmployer}"
                             data-net-pay="${employee.NetPay}"
                             data-gross-pay="${employee.GrossPay}"
                             data-admin-fee="${employee.AdminFee}"
                             data-total-deductions="${employee.Deductions}">
                            <div class="employee-header">
                                <div class="employee-avatar">
                                    ${employee.EmployeeName.charAt(0).toUpperCase()}
                                </div>
                                <div class="employee-info">
                                    <h3>${employee.EmployeeName}</h3>
                                    <p>ID: ${employee.EmployeeID}</p>
                                </div>
                            </div>
                            
                            <div class="employee-payroll-computation">
                                <div class="employee-computation-item">
                                    <div class="employee-computation-label">Days Worked</div>
                                    <div class="employee-computation-value">${employee.DaysWorked}</div>
                                </div>
                                <div class="employee-computation-item">
                                    <div class="employee-computation-label">Total Hours</div>
                                    <div class="employee-computation-value">${Number(employee.TotalHours).toFixed(1)}h</div>
                                </div>
                                <div class="employee-computation-item">
                                    <div class="employee-computation-label">Gross Pay</div>
                                    <div class="employee-computation-value">₱${Number(employee.GrossPay).toFixed(2)}</div>
                                </div>
                                <div class="employee-computation-item">
                                    <div class="employee-computation-label">Deductions</div>
                                    <div class="employee-computation-value">-₱${Number(employee.Deductions).toFixed(2)}</div>
                                </div>
                            </div>
                            
                            <div class="employee-net-pay-banner">
                                <div class="employee-net-pay-label">Net Pay (Σ)</div>
                                <div class="employee-net-pay-value">₱${Number(employee.NetPay).toFixed(2)}</div>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            });
            
            payrollContainer.innerHTML = html;
            payrollContainer.style.display = 'block';
            console.log('Payroll cards updated and displayed');
        }
        
        function showPayrollError(message) {
            // Hide all sections
            document.getElementById('payrollSummaryCards').style.display = 'none';
            document.getElementById('payrollDepartmentOverview').style.display = 'none';
            document.getElementById('payrollCardsContainer').style.display = 'none';
            document.getElementById('payrollLoadingState').style.display = 'none';
            
            // Show error state
            const noDataState = document.getElementById('payrollNoDataState');
            noDataState.innerHTML = `
                <div style="text-align: center; padding: 50px; color: #ff4757;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 20px;"></i>
                    <h3>Error Loading Payroll Data</h3>
                    <p>${message}</p>
                </div>
            `;
            noDataState.style.display = 'block';
        }
        
        function showPayrollNoData() {
            // Hide all sections
            document.getElementById('payrollSummaryCards').style.display = 'none';
            document.getElementById('payrollDepartmentOverview').style.display = 'none';
            document.getElementById('payrollCardsContainer').style.display = 'none';
            document.getElementById('payrollLoadingState').style.display = 'none';
            
            // Show no data state
            document.getElementById('payrollNoDataState').style.display = 'block';
        }
        
        function resetPayrollFilters() {
            document.getElementById('payrollMonth').value = '';
            document.getElementById('payrollYear').value = '';
            document.getElementById('payrollDepartment').value = '';
            
            // Hide all sections
            document.getElementById('payrollSummaryCards').style.display = 'none';
            document.getElementById('payrollDepartmentOverview').style.display = 'none';
            document.getElementById('payrollCardsContainer').style.display = 'none';
            document.getElementById('payrollLoadingState').style.display = 'none';
            document.getElementById('payrollNoDataState').style.display = 'none';
        }
        
        function filterPayrollByDepartment(department) {
            document.getElementById('payrollDepartment').value = department;
            loadPayrollHistory();
        }
        
        function viewPayrollDetails(employeeId, payPeriod) {
            // Open payroll details modal or redirect to detailed view
            window.open(`view_payslip.php?employee_id=${employeeId}&pay_period=${payPeriod}`, '_blank');
        }
        
        function exportEmployeePayroll(employeeId, payPeriod) {
            // Export individual employee payroll
            window.open(`generate_payslip_pdf.php?employee_id=${employeeId}&pay_period=${payPeriod}`, '_blank');
        }
        
        function exportPayrollHistory() {
            const month = document.getElementById('payrollMonth').value;
            const year = document.getElementById('payrollYear').value;
            const department = document.getElementById('payrollDepartment').value;
            
            if (!month && !year) {
                alert('Please select a month or year to export payroll history.');
                return;
            }
            
            // Build export URL
            const params = new URLSearchParams();
            if (month) params.set('month', month);
            if (year) params.set('year', year);
            if (department) params.set('department', department);
            params.set('export', '1');
            
            window.open(`export_payroll_history.php?${params.toString()}`, '_blank');
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
                        
                        console.log('DeptHead row clicked:', { employeeId, employeeName, attendanceDate });
                        
                        if (employeeId && employeeName && attendanceDate) {
                            openEmployeeModal(employeeId, employeeName, attendanceDate);
                        } else {
                            console.error('Missing required data for DeptHead modal:', { employeeId, employeeName, attendanceDate });
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
                        
                        console.log('DeptHead button clicked:', { employeeId, employeeName, attendanceDate });
                        
                        if (employeeId && employeeName && attendanceDate) {
                            openEmployeeModal(employeeId, employeeName, attendanceDate);
                        } else {
                            console.error('Missing required data for DeptHead modal:', { employeeId, employeeName, attendanceDate });
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