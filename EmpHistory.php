<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: login.php");
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
    die("Connection failed: " . $conn->connect_error);
}

// Get employee information
$employee_id = $_SESSION['employee_id'];
$employee_query = "SELECT EmployeeID, EmployeeName, Department, DateHired FROM empuser WHERE EmployeeID = ?";
$stmt = $conn->prepare($employee_query);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$employee_result = $stmt->get_result();

if ($employee_result->num_rows === 0) {
    // Employee not found, redirect to login
    session_destroy();
    header("Location: login.php");
    exit();
}

$employee = $employee_result->fetch_assoc();
$employee_hire_date = $employee['DateHired'];
$stmt->close();

// Get system date range (earliest and latest recorded attendance dates)
$system_date_sql = "SELECT MIN(DATE(attendance_date)) as system_start_date, MAX(DATE(attendance_date)) as system_end_date FROM attendance";
$system_date_stmt = $conn->prepare($system_date_sql);
$system_date_stmt->execute();
$system_date_result = $system_date_stmt->get_result();
$system_date_data = $system_date_result->fetch_assoc();
$system_start_date = $system_date_data['system_start_date'] ?? date('Y-m-d');
$system_end_date = $system_date_data['system_end_date'] ?? date('Y-m-d');
$system_date_stmt->close();

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

// --- Filter Logic & Query Building --- 
$attendance_records = [];

// Determine the date range for the query
$query_start_date = $system_start_date;
$query_end_date = $system_end_date;
$filter_type = null;
$filter_value = null;

if (isset($_GET['date']) && !empty($_GET['date'])) {
    $filter_date = $conn->real_escape_string($_GET['date']);
    $query_start_date = $filter_date;
    $query_end_date = $filter_date;
    $filter_type = 'date';
    $filter_value = $filter_date;
} elseif (isset($_GET['month']) && !empty($_GET['month'])) {
    $filter_month = $conn->real_escape_string($_GET['month']);
    $month_start = $filter_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $query_start_date = max($month_start, $system_start_date);
    $query_end_date = min($month_end, $system_end_date);
    $filter_type = 'month';
    $filter_value = $filter_month;
} elseif (isset($_GET['year']) && !empty($_GET['year'])) {
    $filter_year = $conn->real_escape_string($_GET['year']);
    $year_start = $filter_year . '-01-01';
    $year_end = $filter_year . '-12-31';
    $query_start_date = max($year_start, $system_start_date);
    $query_end_date = min($year_end, $system_end_date);
    $filter_type = 'year';
    $filter_value = $filter_year;
} else {
    // Default: show all records from system start date
    $query_start_date = $system_start_date;
    $query_end_date = $system_end_date;
}

// Generate working days for the query range
$working_days = generateWorkingDays($query_start_date, $query_end_date, $filter_type, $filter_value);

// Pagination settings
$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$records_per_page = $show_all ? 999999 : (isset($_GET['records_per_page']) ? max(10, (int)$_GET['records_per_page']) : 10); // Show 10 records per page by default, or all if show_all=1
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = $show_all ? 0 : (($current_page - 1) * $records_per_page);

// Apply status filter logic
$status_filter_condition = "";
$status_params = [];
$status_types = "";

if (isset($_GET['status']) && !empty($_GET['status'])) {
    $filter_status = $conn->real_escape_string($_GET['status']);
    if ($filter_status === 'present') {
        // For present: only show records where there's actual attendance (not absent)
        $status_filter_condition = "AND (a.id IS NOT NULL AND (a.attendance_type = 'present' OR a.status = 'on_time' OR a.status = 'late'))";
    } elseif ($filter_status === 'absent') {
        // For absent: show records where there's no attendance record OR attendance_type is 'absent'
        $status_filter_condition = "AND (a.id IS NULL OR a.attendance_type = 'absent')";
    } elseif ($filter_status === 'on_leave') {
        // For on leave: show records where is_on_leave = 1
        $status_filter_condition = "AND a.is_on_leave = 1";
    } else {
        // For specific status: show records with that exact status
        $status_filter_condition = "AND a.status = ?";
        $status_params[] = $filter_status;
        $status_types .= "s";
    }
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
              WHERE e.EmployeeID = ?
                  AND wd.working_date >= e.DateHired
                  $status_filter_condition
              ORDER BY wd.working_date DESC, COALESCE(a.time_in, '00:00:00') DESC" . 
              ($show_all ? "" : " LIMIT $records_per_page OFFSET $offset");
} else {
    // Fallback to original query if no working days
    $query = "SELECT a.*, e.EmployeeName, e.Department, e.Shift
              FROM attendance a 
              JOIN empuser e ON a.EmployeeID = e.EmployeeID 
              WHERE e.EmployeeID = ?
              $status_filter_condition
              ORDER BY a.attendance_date DESC, a.time_in DESC" . 
              ($show_all ? "" : " LIMIT $records_per_page OFFSET $offset");
}

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
}

// Bind parameters: employee_id first, then status filter parameters if any
$param_types = "i" . $status_types;
$all_params = array_merge([$employee_id], $status_params);

if (!empty($all_params)) {
    $stmt->bind_param($param_types, ...$all_params);
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
                    WHERE e.EmployeeID = ?
                        AND wd.working_date >= e.DateHired
                        $status_filter_condition";
} else {
    $count_query = "SELECT COUNT(*) as total_count
                    FROM attendance a 
                    JOIN empuser e ON a.EmployeeID = e.EmployeeID 
                    WHERE e.EmployeeID = ?
                    $status_filter_condition";
}

$count_stmt = $conn->prepare($count_query);
if ($count_stmt) {
    $count_param_types = "i" . $status_types;
    $count_all_params = array_merge([$employee_id], $status_params);
    
    if (!empty($count_all_params)) {
        $count_stmt->bind_param($count_param_types, ...$count_all_params);
    }
    
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result ? $count_result->fetch_assoc()['total_count'] : 0;
    $count_stmt->close();
} else {
    $total_records = 0;
}

$total_pages = ceil($total_records / $records_per_page); // Use dynamic records per page for pagination calculation

// Apply accurate calculations to all attendance records
$attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);

// Determine current filter mode and selected values for UI labeling
$is_date_filter = isset($_GET['date']) && !empty($_GET['date']);
$is_month_filter = isset($_GET['month']) && !empty($_GET['month']);
$is_year_filter = isset($_GET['year']) && !empty($_GET['year']);
$selected_date = $is_date_filter ? $_GET['date'] : date('Y-m-d');
$selected_month = $is_month_filter ? $_GET['month'] : date('Y-m');
$selected_year = $is_year_filter ? preg_replace('/[^0-9]/', '', $_GET['year']) : date('Y');
$filter_mode = $is_date_filter ? 'date' : ($is_month_filter ? 'month' : 'year');

// Summary analytics for this employee, respecting current filters
if (!empty($working_days)) {
    $summary_query = "SELECT 
        COUNT(*) as total_records,
        SUM(CASE WHEN (COALESCE(a.attendance_type, 'absent') = 'present' OR COALESCE(a.status, 'no_record') = 'on_time') THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN COALESCE(a.attendance_type, 'absent') = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN COALESCE(a.status, 'no_record') = 'late' THEN 1 ELSE 0 END) as late_count
        FROM empuser e 
        CROSS JOIN (
            SELECT working_date FROM (
                SELECT '{$working_days[0]}' as working_date" . 
                (count($working_days) > 1 ? " UNION ALL SELECT '" . implode("' UNION ALL SELECT '", array_slice($working_days, 1)) . "'" : "") . "
            ) as wd
        ) wd
        LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID 
            AND DATE(a.attendance_date) = wd.working_date
        WHERE e.EmployeeID = ?
            AND wd.working_date >= e.DateHired
            $status_filter_condition";
} else {
    $summary_query = "SELECT 
        COUNT(*) as total_records,
        SUM(CASE WHEN (a.attendance_type = 'present' OR a.status = 'on_time') THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN a.attendance_type = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count
        FROM attendance a 
        JOIN empuser e ON a.EmployeeID = e.EmployeeID 
        WHERE e.EmployeeID = ?
        $status_filter_condition";
}

$summary_stmt = $conn->prepare($summary_query);
if ($summary_stmt) {
    $summary_param_types = "i" . $status_types;
    $summary_all_params = array_merge([$employee_id], $status_params);
    
    if (!empty($summary_all_params)) {
        $summary_stmt->bind_param($summary_param_types, ...$summary_all_params);
    }
    
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    $summary_data = $summary_result ? $summary_result->fetch_assoc() : [
        'total_records' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0
    ];
    $summary_stmt->close();
} else {
    $summary_data = ['total_records' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0];
}

// Per-employee distinct day counts for the selected period (ignores status dropdown)
{
    $period_conditions = ["a.EmployeeID = ?"];
    $period_params = [$employee_id];
    $period_types = 'i';
    if ($is_date_filter) {
        $period_conditions[] = "DATE(a.attendance_date) = ?";
        $period_params[] = $conn->real_escape_string($selected_date);
        $period_types .= 's';
    } elseif ($is_month_filter) {
        $period_conditions[] = "DATE_FORMAT(a.attendance_date, '%Y-%m') = ?";
        $period_params[] = $conn->real_escape_string($selected_month);
        $period_types .= 's';
    } else { // year or default
        $period_conditions[] = "YEAR(a.attendance_date) = ?";
        $period_params[] = $conn->real_escape_string($selected_year);
        $period_types .= 's';
    }

    $period_where = implode(' AND ', $period_conditions);
    $employee_counts_query = "
        SELECT 
            COUNT(DISTINCT CASE WHEN (a.attendance_type = 'present' OR a.status = 'on_time') THEN DATE(a.attendance_date) END) AS days_attended,
            COUNT(DISTINCT CASE WHEN a.attendance_type = 'absent' THEN DATE(a.attendance_date) END) AS days_absent,
            COUNT(DISTINCT CASE WHEN a.status = 'late' THEN DATE(a.attendance_date) END) AS days_late
        FROM attendance a
        WHERE $period_where";
    $ec_stmt = $conn->prepare($employee_counts_query);
    if ($ec_stmt) {
        $ec_stmt->bind_param($period_types, ...$period_params);
        $ec_stmt->execute();
        $ec_res = $ec_stmt->get_result();
        $ec_row = $ec_res ? $ec_res->fetch_assoc() : [
            'days_attended' => 0,
            'days_absent' => 0,
            'days_late' => 0
        ];
        $summary_data['present_employees'] = (int)($ec_row['days_attended'] ?? 0);
        $summary_data['absent_employees'] = (int)($ec_row['days_absent'] ?? 0);
        $summary_data['late_employees'] = (int)($ec_row['days_late'] ?? 0);
        // Total attendance equals days attended (including late)
        $summary_data['total_employees_attended'] = $summary_data['present_employees'];
        $ec_stmt->close();
    } else {
        $summary_data['present_employees'] = 0;
        $summary_data['absent_employees'] = 0;
        $summary_data['late_employees'] = 0;
        $summary_data['total_employees_attended'] = 0;
    }
}

// When viewing by year, compute metadata for that year's total recorded days (independent of status filters)
$year_meta = ['total_year_days' => 0];
if ($is_year_filter) {
    $year_meta_query = "SELECT COUNT(DISTINCT DATE(a.attendance_date)) AS total_year_days
                        FROM attendance a
                        WHERE a.EmployeeID = ? AND YEAR(a.attendance_date) = ?";
    $ym_stmt = $conn->prepare($year_meta_query);
    if ($ym_stmt) {
        $ym_stmt->bind_param('ii', $employee_id, $selected_year);
        $ym_stmt->execute();
        $ym_res = $ym_stmt->get_result();
        if ($ym_res) {
            $year_meta = $ym_res->fetch_assoc() ?: ['total_year_days' => 0];
        }
        $ym_stmt->close();
    }
}

// Today's analytics for this employee (independent of selected date/month/year)
$today_conditions = ["a.EmployeeID = ?", "DATE(a.attendance_date) = ?"]; 
$today_params = [$employee_id, date('Y-m-d')]; 
$today_types = "is";
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $today_conditions[] = "a.status = ?";
    $today_params[] = $conn->real_escape_string($_GET['status']);
    $today_types .= "s";
}
$today_where = implode(' AND ', $today_conditions);
$today_query = "SELECT 
    SUM(CASE WHEN (a.status = 'present' OR a.status = 'on_time') THEN 1 ELSE 0 END) as present_today,
    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_today,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_today
    FROM attendance a 
    JOIN empuser e ON a.EmployeeID = e.EmployeeID 
    WHERE $today_where";
$today_stmt = $conn->prepare($today_query);
if ($today_stmt) {
    $today_stmt->bind_param($today_types, ...$today_params);
    $today_stmt->execute();
    $today_result = $today_stmt->get_result();
    $today_data = $today_result ? $today_result->fetch_assoc() : ['present_today' => 0, 'absent_today' => 0, 'late_today' => 0];
    $today_stmt->close();
} else {
    $today_data = ['present_today' => 0, 'absent_today' => 0, 'late_today' => 0];
}

$conn->close();

// If this is an AJAX request, return JSON payload and exit before rendering HTML
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'records' => $attendance_records,
        'summary' => $summary_data,
        'today' => $today_data,
        'isYear' => $is_year_filter,
        'year' => $selected_year,
        'yearMeta' => $year_meta,
        'filterMode' => $filter_mode,
        'selectedDate' => $selected_date,
        'selectedMonth' => $selected_month,
    ]);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance History</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    
    <style>
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
    width: 280px;
    background: linear-gradient(180deg, var(--primary-color) 0%, #6BC4E4 100%);
    padding: 20px 0;
    box-shadow: 4px 0 10px var(--shadow-color);
    display: flex;
    flex-direction: column;
    color: var(--secondary-color);
    position: fixed;
    height: 100vh;
    transition: all 0.3s ease;
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
    background-color: var(--secondary-color);
    color: white;
    transform: translateX(5px);
}

.menu-item i {
    margin-right: 15px;
    width: 20px;
    text-align: center;
    font-size: 18px;
}

.logout-btn {
    background-color: var(--secondary-color);
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
    background-color: #1a252f;
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
        .header-actions { gap: 18px; }

        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            border-left: 4px solid #3F72AF;
        }

        .summary-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 16px;
            font-size: 22px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .summary-icon.present { background-color: rgba(63, 114, 175, 0.15); color: #3F72AF; }
        .summary-icon.absent { background-color: rgba(255, 107, 107, 0.15); color: #FF6B6B; }
        .summary-icon.late { background-color: rgba(255, 199, 95, 0.15); color: #FFC75F; }

        .summary-info { flex-grow: 1; }
        .summary-value { font-size: 28px; font-weight: 700; color: #112D4E; margin-bottom: 4px; }
        .summary-label { font-size: 13px; color: #6c757d; font-weight: 500; }

        @media (max-width: 992px) {
            .summary-cards { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 576px) {
            .summary-cards { grid-template-columns: 1fr; }
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
            border: 1px solid #DBE2EF;
            border-radius: 12px;
            overflow: hidden;
            padding: 0;
            width: fit-content;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .date-filter-btn {
            background-color: white;
            color: #4A5568;
            border: none;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border-right: 1px solid #DBE2EF;
        }

        .filter-mode-buttons .date-filter-btn:last-child {
            border-right: none;
        }

        .date-filter-btn.active {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .date-filter-btn:not(.active):hover {
            background-color: #F8FBFF;
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
            max-width: 260px; /* make inputs smaller than full width */
            padding: 12px;
            border: 2px solid #DBE2EF;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
            background-color: white;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        /* Also reduce widths for the primary date/month/year inputs */
        #date,
        #month,
        #year {
            max-width: 220px;
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

        .attendance-type-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .attendance-type-present {
            background-color: rgba(76, 175, 80, 0.15);
            color: #4CAF50;
        }

        .attendance-type-absent {
            background-color: rgba(244, 67, 54, 0.15);
            color: #F44336;
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
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
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
        /* Enhanced View Attendance Modal */
        #viewAttendanceModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease-in-out;
        }

        #viewAttendanceModal.show {
            display: flex !important;
            align-items: center;
            justify-content: center;
        }

        #viewAttendanceModal .modal-content {
            background: #fff;
            width: 92%;
            max-width: 600px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            position: relative;
            animation: modalSlideUp 0.3s ease-out;
            max-height: 90vh;
            overflow-y: auto;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes modalSlideUp {
            from { 
                opacity: 0; 
                transform: translateY(30px) scale(0.95);
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1);
            }
        }

        #viewAttendanceModal .modal-header {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: #fff;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-radius: 16px 16px 0 0;
        }

        #viewAttendanceModal .modal-header h2 {
            color: #fff;
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        #viewAttendanceModal .modal-header h2 i {
            color: #fff;
            font-size: 18px;
        }

        #viewAttendanceModal .close {
            color: #fff;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            background: none;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        #viewAttendanceModal .close:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        #viewAttendanceModal .modal-body {
            padding: 20px;
            line-height: 1.8;
        }

        #viewAttendanceModal .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            background: #f8f9fa;
            border-radius: 0 0 16px 16px;
            gap: 10px;
        }

        /* Enhanced Modal Content Styles - Compact Layout */
        .attendance-modal-content {
            padding: 0;
        }

        .main-info-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #3F72AF;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .info-row:last-child {
            margin-bottom: 0;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-label {
            font-weight: 600;
            color: #112D4E;
            font-size: 14px;
        }

        .info-value {
            color: #2D3748;
            font-size: 15px;
            font-weight: 500;
        }

        .info-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .time-section,
        .metrics-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #3F72AF;
        }

        .metrics-section:last-child {
            margin-bottom: 0;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #112D4E;
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }

        .section-title i {
            color: #3F72AF;
            font-size: 14px;
        }

        .time-container {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .time-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .time-label {
            font-weight: 600;
            color: #112D4E;
            font-size: 13px;
        }

        .time-value {
            color: #2D3748;
            font-size: 14px;
            font-weight: 500;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }

        .metric-item {
            background: white;
            padding: 12px;
            border-radius: 6px;
            text-align: center;
            border: 1px solid #e9ecef;
        }

        .metric-label {
            display: block;
            font-weight: 600;
            color: #112D4E;
            font-size: 12px;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .metric-value {
            color: #2D3748;
            font-size: 16px;
            font-weight: 600;
        }

        .notes-section {
            background: white;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .notes-label {
            font-weight: 600;
            color: #112D4E;
            font-size: 13px;
            margin-right: 8px;
        }

        .notes-value {
            color: #2D3748;
            font-size: 14px;
        }

        /* Status-specific styling for badges */
        .info-badge.type-present {
            background: linear-gradient(135deg, rgba(63, 114, 175, 0.2), rgba(63, 114, 175, 0.1));
            color: #3F72AF;
            border: 1px solid rgba(63, 114, 175, 0.3);
        }

        .info-badge.status-present,
        .info-badge.status-on_time {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.2), rgba(76, 175, 80, 0.1));
            color: #4CAF50;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .info-badge.status-late {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 193, 7, 0.1));
            color: #F57F17;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }

        .info-badge.status-absent {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.2), rgba(255, 107, 107, 0.1));
            color: #FF6B6B;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .info-badge.status-half_day {
            background: linear-gradient(135deg, rgba(33, 150, 243, 0.2), rgba(33, 150, 243, 0.1));
            color: #2196F3;
            border: 1px solid rgba(33, 150, 243, 0.3);
        }

        .info-badge.status-early_in {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.2), rgba(76, 175, 80, 0.1));
            color: #4CAF50;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .info-row {
                flex-direction: column;
                gap: 10px;
            }
            
            .metrics-grid {
                grid-template-columns: 1fr;
            }
            
            .main-info-section,
            .time-section,
            .metrics-section {
                padding: 15px;
            }

            #viewAttendanceModal .modal-footer {
                padding: 15px;
                flex-direction: column;
            }

            #viewAttendanceModal .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .info-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .time-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
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

        /* Payroll specific styles */
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
        }

        .status-pending {
            background-color: rgba(255, 199, 95, 0.15);
            color: #FFC75F;
        }

        .payroll-summary-card {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            border-left: 4px solid #3F72AF;
            margin-bottom: 20px;
        }

        .payroll-loading {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }

        .payroll-loading i {
            font-size: 24px;
            margin-bottom: 10px;
        }

        /* Payslip Modal Styles */
        .payslip-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(17, 45, 78, 0.95);
            backdrop-filter: blur(10px);
            z-index: 2000;
            overflow-y: auto;
            padding: 20px;
            box-sizing: border-box;
            animation: fadeIn 0.3s ease-in-out;
        }

        .payslip-modal[style*="display: block"] {
            display: flex !important;
            align-items: center;
            justify-content: center;
        }

        @keyframes fadeIn {
            from { 
                opacity: 0; 
            }
            to { 
                opacity: 1; 
            }
        }

        .payslip-content {
            position: relative;
            background: white;
            margin: auto;
            width: 100%;
            max-width: 1400px;
            max-height: 90vh;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            animation: modalSlideUp 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex;
            flex-direction: column;
        }

        @keyframes modalSlideUp {
            from { 
                opacity: 0; 
                transform: translateY(60px) scale(0.9);
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1);
            }
        }

        .modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 24px;
            color: white;
            cursor: pointer;
            z-index: 10;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 300;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.35);
            transform: rotate(90deg) scale(1.15);
            border-color: white;
        }

        .header-export-btn {
            position: absolute;
            top: 20px;
            right: 65px;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 11px;
            border: 2px solid rgba(255, 255, 255, 0.4);
            background: rgba(255, 255, 255, 0.15);
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 10;
            backdrop-filter: blur(10px);
        }

        .header-export-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .payslip-header {
            background: linear-gradient(135deg, #3F72AF 0%, #2563af 50%, #112D4E 100%);
            color: white;
            padding: 20px 40px;
            position: relative;
            overflow: hidden;
            text-align: center;
            flex-shrink: 0;
        }

        .company-info h2 {
            font-size: 16px;
            margin: 0 0 3px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .company-info p {
            margin: 0;
            font-size: 10px;
            opacity: 0.9;
            font-weight: 400;
        }

        .pay-period h3 {
            font-size: 14px;
            margin: 0;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .payslip-body {
            padding: 0;
            background: white;
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .employee-section {
            background: white;
            padding: 20px 40px;
            margin: 0;
            border-bottom: 2px solid #e9ecef;
            animation: slideInLeft 0.5s ease-out;
            flex-shrink: 0;
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .section-title {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            color: #112D4E;
            font-size: 14px;
            font-weight: 700;
            padding-bottom: 0;
            border-bottom: none;
        }

        .employee-details {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px 30px;
        }

        .detail-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .detail-group label {
            font-size: 10px;
            color: #6c757d;
            font-weight: 600;
            text-transform: none;
            letter-spacing: 0;
        }

        .detail-group span {
            font-size: 13px;
            font-weight: 600;
            color: #000;
        }

        .payslip-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            margin-bottom: 0;
            padding: 20px 40px;
            animation: slideInRight 0.6s ease-out;
            flex-shrink: 0;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .payslip-section {
            background: white;
            padding: 0;
            border-radius: 0;
            box-shadow: none;
            border: none;
            margin-bottom: 0;
        }

        .breakdown-container {
            max-height: none;
            border: 2px solid #3F72AF;
            border-radius: 10px;
            padding: 15px 20px;
            background: white;
            transition: all 0.3s ease;
        }

        .breakdown-container:hover {
            box-shadow: 0 4px 15px rgba(63, 114, 175, 0.15);
            transform: translateY(-2px);
        }

        .breakdown-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: none;
            font-size: 12px;
            align-items: center;
            transition: all 0.2s;
        }

        .breakdown-row span:first-child {
            color: #000;
            font-weight: 400;
        }

        .breakdown-row span:last-child {
            color: #000;
            font-weight: 600;
            font-size: 12px;
        }

        .breakdown-row.total-row {
            background: transparent;
            font-weight: 700;
            color: #000;
            margin-top: 0;
            padding: 8px 0;
            border-radius: 0;
            border: none;
            border-top: 2px solid #e9ecef;
        }

        .breakdown-row.total-row span {
            color: #000;
            font-size: 13px;
            font-weight: 700;
        }

        .summary-section {
            background: white;
            border-radius: 0;
            padding: 20px 40px;
            box-shadow: none;
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            border-top: 2px solid #e9ecef;
            animation: slideInUp 0.7s ease-out;
            flex-shrink: 0;
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .summary-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 15px;
            font-size: 13px;
            position: relative;
            z-index: 1;
            background: linear-gradient(135deg, #3F72AF 0%, #2563af 50%, #112D4E 100%);
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .summary-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(63, 114, 175, 0.3);
        }

        .summary-item span:first-child {
            color: rgba(255, 255, 255, 0.95);
            font-weight: 600;
            font-size: 11px;
            margin-bottom: 6px;
        }

        .summary-item span:last-child {
            color: white;
            font-weight: 700;
            font-size: 16px;
        }

        .summary-item.total {
            border-top: none;
            font-size: 16px;
            font-weight: 800;
            color: white;
            margin-top: 0;
            padding-top: 15px;
            padding-bottom: 15px;
        }

        .summary-item.total span:first-child {
            font-size: 12px;
        }

        .summary-item.total span:last-child {
            color: white;
            font-size: 20px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .payslip-footer {
            padding: 10px 40px;
            border-top: none;
            background: white;
            text-align: center;
            flex-shrink: 0;
        }

        .footer-note {
            font-size: 9px;
            color: #999;
            font-style: italic;
        }

        .footer-note p {
            margin: 0;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .payslip-header {
                flex-direction: column;
                text-align: center;
            }
            
            .payslip-grid {
                grid-template-columns: 1fr;
            }
            
            .payslip-footer {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
        }
    </style>
</head>
<body>
<div class="sidebar">
<img src="LOGO/newLogo_transparent.png" class="logo" style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding:-250px; padding-bottom: 20px;">       
 <div class="menu">
            <a href="EmployeeHome.php" class="menu-item">
                <i class="fas fa-home"></i> Dashboard
            </a>
            
            <a href="EmpAttendance.php" class="menu-item">
                <i class="fas fa-calendar-check"></i> Attendance
            </a>
            <a href="EmpPayroll.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i> Payroll
            </a>
            <a href="EmpHistory.php" class="menu-item active">
                <i class="fas fa-history"></i> History
            </a>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="main-content">
        <div class="header">
            <h1 id="page-title"><i class="fas fa-history"></i> My Attendance History</h1>
            <div class="header-actions">
                <div class="user-info">
                    <span style="font-weight: 600; color: #112D4E;"><?php echo htmlspecialchars($employee['EmployeeName']); ?></span>
                    <span style="color: #6c757d; font-size: 14px;"><?php echo htmlspecialchars($employee['Department']); ?></span>
                </div>
               
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
        <div class="summary-cards" style="margin-top: -10px;">
            <div class="summary-card">
                <div class="summary-icon present"><i class="fas fa-database"></i></div>
                <div class="summary-info">
                    <div id="summary-total" class="summary-value"><?php echo (int)($summary_data['total_records'] ?? 0); ?></div>
                    <div id="summary-total-label" class="summary-label">
                        <?php if ($is_year_filter): ?>
                            Total Records in <?php echo htmlspecialchars($selected_year); ?>
                        <?php elseif ($is_month_filter): ?>
                            Total Records in <?php echo htmlspecialchars($selected_month); ?>
                        <?php elseif ($is_date_filter): ?>
                            Total Records on <?php echo htmlspecialchars($selected_date); ?>
                        <?php else: ?>
                            Total Records (All Time)
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon present"><i class="fas fa-check-circle"></i></div>
                <div class="summary-info">
                    <div id="summary-present" class="summary-value"><?php echo (int)($summary_data['present_count'] ?? 0); ?></div>
                    <div id="summary-present-label" class="summary-label">
                        <?php if ($is_year_filter): ?>
                            Present Days in <?php echo htmlspecialchars($selected_year); ?>
                        <?php elseif ($is_month_filter): ?>
                            Present Days in <?php echo htmlspecialchars($selected_month); ?>
                        <?php elseif ($is_date_filter): ?>
                            Present on <?php echo htmlspecialchars($selected_date); ?>
                        <?php else: ?>
                            Total Present Days
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon absent"><i class="fas fa-times-circle"></i></div>
                <div class="summary-info">
                    <div id="summary-absent" class="summary-value"><?php echo (int)($summary_data['absent_count'] ?? 0); ?></div>
                    <div id="summary-absent-label" class="summary-label">
                        <?php if ($is_year_filter): ?>
                            Absent Days in <?php echo htmlspecialchars($selected_year); ?>
                        <?php elseif ($is_month_filter): ?>
                            Absent Days in <?php echo htmlspecialchars($selected_month); ?>
                        <?php elseif ($is_date_filter): ?>
                            Absent on <?php echo htmlspecialchars($selected_date); ?>
                        <?php else: ?>
                            Total Absent Days
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon late"><i class="fas fa-clock"></i></div>
                <div class="summary-info">
                    <div id="summary-late" class="summary-value"><?php echo (int)($summary_data['late_count'] ?? 0); ?></div>
                    <div id="summary-late-label" class="summary-label">
                        <?php if ($is_year_filter): ?>
                            Late Days in <?php echo htmlspecialchars($selected_year); ?>
                        <?php elseif ($is_month_filter): ?>
                            Late Days in <?php echo htmlspecialchars($selected_month); ?>
                        <?php elseif ($is_date_filter): ?>
                            Late on <?php echo htmlspecialchars($selected_date); ?>
                        <?php else: ?>
                            Total Late Days
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Attendance Filter Section -->
        <div class="filter-container card" id="attendance-filter">
            <div class="filter-header">
                <h2><i class="fas fa-filter"></i> Attendance Filters</h2>
            </div>
            <form method="GET" action="">
                <div class="filter-controls">
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
                            <label for="status">Status:</label>
                            <select name="status" id="status" class="form-control">
                                <option value="">All Statuses</option>
                                <option value="present" <?php echo (isset($_GET['status']) && $_GET['status'] == 'present') ? 'selected' : ''; ?>>Present</option>
                                <option value="absent" <?php echo (isset($_GET['status']) && $_GET['status'] == 'absent') ? 'selected' : ''; ?>>Absent</option>
                                <option value="late" <?php echo (isset($_GET['status']) && $_GET['status'] == 'late') ? 'selected' : ''; ?>>Late</option>
                                <option value="halfday" <?php echo (isset($_GET['status']) && $_GET['status'] == 'halfday') ? 'selected' : ''; ?>>Half Day</option>
                                <option value="on_leave" <?php echo (isset($_GET['status']) && $_GET['status'] == 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                            </select>
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="EmpHistory.php" class="btn btn-secondary">
                            <i class="fas fa-sync-alt"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Attendance Results Table -->
        <div class="results-container" id="attendance-results">
            <div class="results-header">
                <h2><i class="fas fa-calendar-check"></i> My Attendance Records</h2>
                <div class="results-actions">
                    
                </div>
            </div>

            <!-- View Attendance Modal -->
            <div id="viewAttendanceModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2><i class="fas fa-id-card"></i> Attendance Details</h2>
                        <button class="close" onclick="closeViewAttendanceModal()" aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body" id="viewAttendanceBody" style="line-height:1.8;"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" onclick="closeViewAttendanceModal()">
                            <i class="fas fa-check"></i>
                            Close
                        </button>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Total Hours</th>
                            <th>Status</th>
                            <th>Attendance Type</th>
                            <th>Late Minutes</th>
                            <th>Early Out Minutes</th>
                            <th>Overtime Hours</th>
                        </tr>
                    </thead>
                    <tbody id="results-body">
                        <?php if (!empty($attendance_records)): ?>
                            <?php foreach ($attendance_records as $record): ?>
                                <tr class="clickable-row"
                                    data-date="<?php echo htmlspecialchars($record['attendance_date']); ?>"
                                    data-am_in="<?php echo htmlspecialchars($record['time_in_morning'] ?? ''); ?>"
                                    data-am_out="<?php echo htmlspecialchars($record['time_out_morning'] ?? ''); ?>"
                                    data-pm_in="<?php echo htmlspecialchars($record['time_in_afternoon'] ?? ''); ?>"
                                    data-pm_out="<?php echo htmlspecialchars($record['time_out_afternoon'] ?? ''); ?>"
                                    data-status="<?php echo htmlspecialchars($record['status'] ?? ''); ?>"
                                    data-attendance_type="<?php echo htmlspecialchars($record['attendance_type'] ?? ''); ?>"
                                    data-late="<?php echo htmlspecialchars($record['late_minutes'] ?? 0); ?>"
                                    data-early="<?php echo htmlspecialchars($record['early_out_minutes'] ?? 0); ?>"
                                    data-ot="<?php echo htmlspecialchars($record['overtime_hours'] ?? 0); ?>"
                                    data-notes="<?php echo htmlspecialchars($record['notes'] ?? '-'); ?>"
                                    data-shift="<?php echo htmlspecialchars($record['Shift'] ?? ''); ?>">
                                    <td><?php echo htmlspecialchars($record['attendance_date']); ?></td>
                                    <td><?php echo htmlspecialchars($record['time_in']); ?></td>
                                    <td><?php echo htmlspecialchars($record['time_out']); ?></td>
                                    <td>
                                        <?php 
                                            $th = 0.00;
                                            if (isset($record['total_hours'])) {
                                                $th = (float)$record['total_hours'];
                                            } elseif (!empty($record['time_in']) && !empty($record['time_out'])) {
                                                $th = (float)AttendanceCalculator::calculateTotalHours($record['time_in'], $record['time_out']);
                                            }
                                            echo $th > 0 ? number_format($th, 2) : '-';
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (($record['is_on_leave'] ?? 0) == 1): ?>
                                            <span class="status-badge status-on-leave">ON-LEAVE</span>
                                        <?php else: ?>
                                            <span class="status-badge status-<?php echo htmlspecialchars($record['status']); ?>">
                                                <?php 
                                                    if ($record['status'] === 'present' || $record['status'] === 'on_time') {
                                                        echo 'On-Time';
                                                    } else {
                                                        echo ucfirst(htmlspecialchars($record['status']));
                                                    }
                                                ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="attendance-type-badge attendance-type-<?php echo htmlspecialchars($record['attendance_type']); ?>">
                                            <?php 
                                                // Determine attendance type based on status and attendance_type
                                                if ($record['attendance_type'] === 'present' || 
                                                    $record['status'] === 'present' || 
                                                    $record['status'] === 'on_time' || 
                                                    $record['status'] === 'late') {
                                                    echo 'Present';
                                                } else {
                                                    echo 'Absent';
                                                }
                                            ?>
                                        </span>
                                    </td>
                                    <td><?php echo ($record['late_minutes'] > 0 ? $record['late_minutes'] : '-'); ?></td>
                                    <td><?php echo ($record['early_out_minutes'] > 0 ? $record['early_out_minutes'] : '-'); ?></td>
                                    <td><?php echo ($record['overtime_hours'] > 0 ? number_format($record['overtime_hours'], 2) : '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center;">No attendance records found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination Controls -->
            <?php if ($total_pages > 1 && !$show_all): ?>
            <div class="pagination-container" style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                <div class="pagination-info">
                    <span style="color: #6c757d; font-size: 14px;">
                        Showing <?php echo (($current_page - 1) * $records_per_page) + 1; ?> to 
                        <?php echo min($current_page * $records_per_page, $total_records); ?> of 
                        <?php echo number_format($total_records); ?> records
                    </span>
                </div>
                
                <div class="records-per-page" style="display: flex; align-items: center; gap: 10px;">
                    <label for="recordsPerPage" style="color: #6c757d; font-size: 14px;">Records per page:</label>
                    <select id="recordsPerPage" onchange="changeRecordsPerPage(this.value)" style="padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="10" <?php echo $records_per_page == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="25" <?php echo $records_per_page == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $records_per_page == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $records_per_page == 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                </div>
                
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="btn btn-secondary" style="margin-right: 5px;">
                            <i class="fas fa-angle-double-left"></i> First
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>" class="btn btn-secondary" style="margin-right: 5px;">
                            <i class="fas fa-angle-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                           class="btn <?php echo $i == $current_page ? 'btn-primary' : 'btn-secondary'; ?>" 
                           style="margin-right: 5px;">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>" class="btn btn-secondary" style="margin-right: 5px;">
                            Next <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="btn btn-secondary">
                            Last <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="show-all-container">
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
                        Showing all <?php echo number_format($total_records); ?> records
                    </span>
                </div>
                <div class="show-all-container">
                    <a href="?<?php echo http_build_query(array_diff_key($_GET, ['show_all' => ''])); ?>" class="btn btn-secondary">
                        <i class="fas fa-th-list"></i> Show Paginated
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Payroll History Section -->
        <div class="results-container" id="payroll-results" style="display: none;">
            <div class="results-header">
                <h2><i class="fas fa-money-bill-wave"></i> My Payroll History</h2>
            </div>

            <!-- Payroll Filter Section -->
            <div class="filter-container card" style="margin-bottom: 20px;">
                <div class="filter-header">
                    <h2><i class="fas fa-filter"></i> Payroll Filters</h2>
                </div>
                <form method="GET" action="" id="payroll-filter-form">
                    <div class="filter-controls">
                        <div class="filter-options">
                            <div class="filter-option">
                                <label for="payroll-year">Select Year:</label>
                                <select name="payroll_year" id="payroll-year" class="form-control">
                                    <option value="">All Years</option>
                                    <?php
                                    $current_year = date('Y');
                                    for ($year = $current_year; $year >= 2020; $year--) {
                                        $selected = (isset($_GET['payroll_year']) && $_GET['payroll_year'] == $year) ? 'selected' : '';
                                        echo "<option value='$year' $selected>$year</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="filter-option">
                                <label for="payroll-month">Select Month:</label>
                                <select name="payroll_month" id="payroll-month" class="form-control">
                                    <option value="">All Months</option>
                                    <?php
                                    $months = [
                                        '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
                                        '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
                                        '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                                    ];
                                    foreach ($months as $num => $name) {
                                        $selected = (isset($_GET['payroll_month']) && $_GET['payroll_month'] == $num) ? 'selected' : '';
                                        echo "<option value='$num' $selected>$name</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="EmpHistory.php" class="btn btn-secondary">
                                <i class="fas fa-sync-alt"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Payroll Summary Cards -->
            <div class="summary-cards" id="payroll-summary-cards" style="margin-bottom: 20px;">
                <div class="summary-card">
                    <div class="summary-icon present"><i class="fas fa-calendar"></i></div>
                    <div class="summary-info">
                        <div id="payroll-periods-count" class="summary-value">0</div>
                        <div class="summary-label">Payroll Periods</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon present"><i class="fas fa-dollar-sign"></i></div>
                    <div class="summary-info">
                        <div id="total-gross-pay" class="summary-value">₱0.00</div>
                        <div class="summary-label">Total Gross Pay</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon absent"><i class="fas fa-minus-circle"></i></div>
                    <div class="summary-info">
                        <div id="total-deductions" class="summary-value">₱0.00</div>
                        <div class="summary-label">Total Deductions</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon late"><i class="fas fa-wallet"></i></div>
                    <div class="summary-info">
                        <div id="total-net-pay" class="summary-value">₱0.00</div>
                        <div class="summary-label">Total Net Pay</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon present"><i class="fas fa-gift"></i></div>
                    <div class="summary-info">
                        <div id="current-13th-month-pay" class="summary-value">₱0.00</div>
                        <div class="summary-label">Current 13th Month Pay</div>
                    </div>
                </div>
            </div>

            <!-- Payroll History Table -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Pay Period</th>
                            <th>Basic Salary</th>
                            <th>Overtime Pay</th>
                            <th>Holiday Pay</th>
                            <th>Gross Pay</th>
                            <th>Deductions</th>
                            <th>Net Pay</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="payroll-results-body">
                        <tr>
                            <td colspan="9" style="text-align: center;">No payroll records found</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Payslip Modal -->
    <div id="payslipModal" class="payslip-modal">
        <div class="payslip-content">
            <span class="modal-close" onclick="closePayslipModal()">&times;</span>
            <button class="header-export-btn" onclick="exportPayslipPDF()">
                <i class="fas fa-file-export"></i> Export PDF
            </button>
            
            <div class="payslip-header">
                <div class="company-info">
                    <h2>WTEI Corporation</h2>
                    <p>Malvar st. Brgy.Mandaragat, Puerto Princesa City, Palawan</p>
                </div>
                <div class="pay-period">
                    <h3 id="modal-pay-period">Pay Period: <span id="modal-pay-period-value"></span></h3>
                </div>
            </div>
            
            <div class="payslip-body">
                <div class="employee-section">
                    <div class="section-title">
                        Employee Information
                    </div>
                    <div class="employee-details">
                        <div class="detail-group">
                            <label>Name</label>
                            <span id="modal-employee-name"></span>
                        </div>
                        <div class="detail-group">
                            <label>Employee ID</label>
                            <span id="modal-employee-id"></span>
                        </div>
                        <div class="detail-group">
                            <label>Department</label>
                            <span id="modal-employee-dept"></span>
                        </div>
                        <div class="detail-group">
                            <label>Pay Period</label>
                            <span id="modal-pay-period-detail"></span>
                        </div>
                        <div class="detail-group">
                            <label>Payment Date</label>
                            <span id="modal-payment-date"></span>
                        </div>
                    </div>
                </div>
                
                <div class="payslip-grid">
                    <div class="payslip-section">
                        <div class="section-title">
                            Earnings:
                        </div>
                        <div class="breakdown-container">
                            <div class="breakdown-row">
                                <span>Basic Salary</span>
                                <span id="modal-basic-salary"></span>
                            </div>
                            <div class="breakdown-row">
                                <span>Overtime Pay</span>
                                <span id="modal-overtime-pay"></span>
                            </div>
                            <div class="breakdown-row">
                                <span>Holiday Pay</span>
                                <span id="modal-holiday-pay"></span>
                            </div>
                            <div class="breakdown-row total-row">
                                <span>Total Earnings</span>
                                <span id="modal-total-earnings"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="payslip-section">
                        <div class="section-title">
                            Deductions:
                        </div>
                        <div class="breakdown-container">
                            <div class="breakdown-row">
                                <span>SSS</span>
                                <span id="modal-sss-deduction"></span>
                            </div>
                            <div class="breakdown-row">
                                <span>PhilHealth</span>
                                <span id="modal-philhealth-deduction"></span>
                            </div>
                            <div class="breakdown-row">
                                <span>Pag-IBIG</span>
                                <span id="modal-pagibig-deduction"></span>
                            </div>
                            <div class="breakdown-row">
                                <span>Withholding Tax</span>
                                <span id="modal-tax-deduction"></span>
                            </div>
                            <div class="breakdown-row total-row">
                                <span>Total Deductions</span>
                                <span id="modal-total-deductions"></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="summary-section">
                    <div class="summary-item">
                        <span>Total Earnings:</span>
                        <span id="modal-summary-earnings"></span>
                    </div>
                    <div class="summary-item">
                        <span>Total Deductions:</span>
                        <span id="modal-summary-deductions"></span>
                    </div>
                    <div class="summary-item total">
                        <span>Net Pay:</span>
                        <span id="modal-net-pay"></span>
                    </div>
                </div>
            </div>
            
            <div class="payslip-footer">
                <div class="footer-note">
                    <p>This is a computer-generated document. No signature is required.</p>
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
                closePayslipModal();
            }
        });

        // Close payslip modal when clicking outside
        window.onclick = function(event) {
            const payslipModal = document.getElementById('payslipModal');
            if (event.target === payslipModal) {
                closePayslipModal();
            }
        }
    
    // Filter toggle functionality
    document.addEventListener('DOMContentLoaded', function() {
        const filterButtons = document.querySelectorAll('.date-filter-btn');
        const dateFilter = document.getElementById('dateFilter');
        const monthFilter = document.getElementById('monthFilter');
        const yearFilter = document.getElementById('yearFilter');
        const dateInput = document.getElementById('date');
        const monthInput = document.getElementById('month');
        const yearSelect = document.getElementById('year');
        const form = document.querySelector('#attendance-filter form');
        const resultsBody = document.getElementById('results-body');
        const summaryTotal = document.getElementById('summary-total');
        const summaryTotalLabel = document.getElementById('summary-total-label');
        const summaryPresent = document.getElementById('summary-present');
        const summaryPresentLabel = document.getElementById('summary-present-label');
        const summaryAbsent = document.getElementById('summary-absent');
        const summaryAbsentLabel = document.getElementById('summary-absent-label');
        const summaryLate = document.getElementById('summary-late');
        const summaryLateLabel = document.getElementById('summary-late-label');
        
        // Show attendance filter by default, hide payroll results
        document.getElementById('attendance-filter').style.display = 'block';
        document.getElementById('payroll-results').style.display = 'none';
        
        // Check URL parameters to determine which filter was used
        const urlParams = new URLSearchParams(window.location.search);
        
        // Determine which filter is active based on which parameter exists
        const hasNonEmpty = (key) => urlParams.has(key) && (urlParams.get(key) || '').trim() !== '';
        let activeFilter = 'all'; // default to show all records from system start
        if (hasNonEmpty('date')) {
            activeFilter = 'date';
        } else if (hasNonEmpty('month')) {
            activeFilter = 'month';
        } else if (hasNonEmpty('year')) {
            activeFilter = 'year';
        }
        
        // Activate the correct filter button based on URL
        filterButtons.forEach(button => {
            // Remove active class from all buttons first
            button.classList.remove('active');
            
            // Add active class to the button that matches the active filter
            // If activeFilter is 'all', don't activate any button
            if (activeFilter !== 'all' && button.dataset.filter === activeFilter) {
                button.classList.add('active');
            }
            
            // Set up click handlers
            button.addEventListener('click', function() {
                // Update active button
                filterButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
                
                // Show corresponding filter
                const filterType = this.dataset.filter;
                activeFilter = filterType;
                dateFilter.style.display = 'none';
                monthFilter.style.display = 'none';
                yearFilter.style.display = 'none';
                
                if (filterType === 'date') {
                    dateFilter.style.display = 'block';
                } else if (filterType === 'month') {
                    monthFilter.style.display = 'block';
                } else if (filterType === 'year') {
                    yearFilter.style.display = 'block';
                }

                // Ensure default selection exists for the newly active filter
                const now = new Date();
                const yyyy = String(now.getFullYear());
                const mm = String(now.getMonth() + 1).padStart(2, '0');
                const dd = String(now.getDate()).padStart(2, '0');
                if (filterType === 'date' && dateInput && !dateInput.value) {
                    dateInput.value = `${yyyy}-${mm}-${dd}`;
                }
                if (filterType === 'month' && monthInput && !monthInput.value) {
                    monthInput.value = `${yyyy}-${mm}`;
                }
                if (filterType === 'year' && yearSelect && !yearSelect.value) {
                    yearSelect.value = yyyy;
                }

                // Immediately fetch results for the new filter
                fetchResults();
            });
        });
        
        // Show the correct filter input based on active filter
        dateFilter.style.display = activeFilter === 'date' ? 'block' : 'none';
        monthFilter.style.display = activeFilter === 'month' ? 'block' : 'none';
        yearFilter.style.display = activeFilter === 'year' ? 'block' : 'none';
        
        // If activeFilter is 'all', hide all filter inputs
        if (activeFilter === 'all') {
            dateFilter.style.display = 'none';
            monthFilter.style.display = 'none';
            yearFilter.style.display = 'none';
        }

        // If no specific filter provided, set the year select to current year
        if (!hasNonEmpty('date') && !hasNonEmpty('month') && !hasNonEmpty('year')) {
            if (yearSelect) {
                yearSelect.value = String(new Date().getFullYear());
            }
        }

        // Ensure only active filter input is submitted (prevents empty params overriding active tab)
        function buildQueryParams(includeAjax = true) {
            const params = new URLSearchParams();
            const current = document.querySelector('.date-filter-btn.active')?.dataset.filter || activeFilter;
            
            // If current is 'all', don't add any date/month/year params (shows all records from system start)
            if (current !== 'all') {
                if (current === 'date' && dateInput && dateInput.value) params.set('date', dateInput.value);
                if (current === 'month' && monthInput && monthInput.value) params.set('month', monthInput.value);
                if (current === 'year' && yearSelect && yearSelect.value) params.set('year', yearSelect.value);
            }
            
            const status = document.getElementById('status');
            if (status && status.value) {
                // If status is 'present', we need to handle it specially in the backend
                params.set('status', status.value);
            }
            if (includeAjax) params.set('ajax', '1');
            return params;
        }

        function renderResults(json) {
            if (!resultsBody) return;
            const rows = (json.records || []).map(rec => {
                const th = (() => {
                    if (typeof rec.total_hours === 'number') return rec.total_hours;
                    if (rec.time_in && rec.time_out) return null; // server already computes if needed
                    return 0;
                })();
                const totalHoursDisplay = (th && th > 0) ? Number(th).toFixed(2) : '-';
                const late = (rec.late_minutes && rec.late_minutes > 0) ? rec.late_minutes : '-';
                const early = (rec.early_out_minutes && rec.early_out_minutes > 0) ? rec.early_out_minutes : '-';
                const ot = (rec.overtime_hours && rec.overtime_hours > 0) ? Number(rec.overtime_hours).toFixed(2) : '-';
                const statusClass = rec.is_on_leave == 1 ? 'status-on-leave' : `status-${(rec.status || '').toLowerCase()}`;
                const statusText = rec.is_on_leave == 1 ? 'ON-LEAVE' : 
                                  (rec.status === 'present' || rec.status === 'on_time') ? 'On-Time' : 
                                  (rec.status || '').charAt(0).toUpperCase() + (rec.status || '').slice(1);
                // Determine attendance type
                const attendanceType = (rec.attendance_type === 'present' || 
                                      rec.status === 'present' || 
                                      rec.status === 'on_time' || 
                                      rec.status === 'late') ? 'Present' : 'Absent';
                const attendanceTypeClass = attendanceType.toLowerCase();
                
                return `
                    <tr>
                        <td>${rec.attendance_date ?? ''}</td>
                        <td>${rec.time_in ?? ''}</td>
                        <td>${rec.time_out ?? ''}</td>
                        <td>${totalHoursDisplay}</td>
                        <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                        <td><span class="attendance-type-badge attendance-type-${attendanceTypeClass}">${attendanceType}</span></td>
                        <td>${late}</td>
                        <td>${early}</td>
                        <td>${ot}</td>
                    </tr>`;
            }).join('');
            resultsBody.innerHTML = rows || '<tr><td colspan="9" style="text-align: center;">No attendance records found</td></tr>';

            // Update summary
            if (summaryTotal) summaryTotal.textContent = Number((json.summary && json.summary.total_records) || 0);
            if (summaryPresent) summaryPresent.textContent = Number((json.summary && json.summary.present_count) || 0);
            if (summaryAbsent) summaryAbsent.textContent = Number((json.summary && json.summary.absent_count) || 0);
            if (summaryLate) summaryLate.textContent = Number((json.summary && json.summary.late_count) || 0);

            const isYear = !!json.isYear;
            const yr = json.year;
            if (summaryTotalLabel) {
                if (json.filterMode === 'year') {
                    summaryTotalLabel.textContent = `Total Records in ${yr}`;
                } else if (json.filterMode === 'month') {
                    summaryTotalLabel.textContent = `Total Records in ${json.selectedMonth}`;
                } else if (json.filterMode === 'date') {
                    summaryTotalLabel.textContent = `Total Records on ${json.selectedDate}`;
                } else {
                    summaryTotalLabel.textContent = 'Total Records (All Time)';
                }
            }
            if (summaryPresentLabel) {
                if (json.filterMode === 'year') {
                    summaryPresentLabel.textContent = `Present Days in ${yr}`;
                } else if (json.filterMode === 'month') {
                    summaryPresentLabel.textContent = `Present Days in ${json.selectedMonth}`;
                } else if (json.filterMode === 'date') {
                    summaryPresentLabel.textContent = `Present on ${json.selectedDate}`;
                } else {
                    summaryPresentLabel.textContent = 'Total Present Days';
                }
            }
            if (summaryAbsentLabel) {
                if (json.filterMode === 'year') {
                    summaryAbsentLabel.textContent = `Absent Days in ${yr}`;
                } else if (json.filterMode === 'month') {
                    summaryAbsentLabel.textContent = `Absent Days in ${json.selectedMonth}`;
                } else if (json.filterMode === 'date') {
                    summaryAbsentLabel.textContent = `Absent on ${json.selectedDate}`;
                } else {
                    summaryAbsentLabel.textContent = 'Total Absent Days';
                }
            }
            if (summaryLateLabel) {
                if (json.filterMode === 'year') {
                    summaryLateLabel.textContent = `Late Days in ${yr}`;
                } else if (json.filterMode === 'month') {
                    summaryLateLabel.textContent = `Late Days in ${json.selectedMonth}`;
                } else if (json.filterMode === 'date') {
                    summaryLateLabel.textContent = `Late on ${json.selectedDate}`;
                } else {
                    summaryLateLabel.textContent = 'Total Late Days';
                }
            }
        }

        async function fetchResults() {
            const params = buildQueryParams(true);
            const url = `${window.location.pathname}?${params.toString()}`;
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const json = await res.json();
            renderResults(json);
        }

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                fetchResults();
            });
        }

        // Trigger on change for inputs to quickly show results
        if (dateInput) dateInput.addEventListener('change', fetchResults);
        if (monthInput) monthInput.addEventListener('change', fetchResults);
        if (yearSelect) yearSelect.addEventListener('change', fetchResults);
        const statusSel = document.getElementById('status');
        if (statusSel) statusSel.addEventListener('change', fetchResults);

        // Payroll filter form handling
        const payrollFilterForm = document.getElementById('payroll-filter-form');
        if (payrollFilterForm) {
            payrollFilterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                loadPayrollData();
            });
        }

        // Payroll filter change events
        const payrollYear = document.getElementById('payroll-year');
        const payrollMonth = document.getElementById('payroll-month');
        
        if (payrollYear) {
            payrollYear.addEventListener('change', loadPayrollData);
        }
        if (payrollMonth) {
            payrollMonth.addEventListener('change', loadPayrollData);
        }

        // Add click event listeners for attendance modal
        document.addEventListener('click', function(e){
            const tr = e.target.closest('tr.clickable-row');
            if (tr && !e.target.closest('button') && !e.target.closest('.status-badge')) {
                console.log('Opening modal for row:', tr);
                openViewAttendanceModal(tr);
            }
        });
        
        // Close on overlay click
        const viewAttendanceModal = document.getElementById('viewAttendanceModal');
        if (viewAttendanceModal) {
            viewAttendanceModal.addEventListener('click', function(e){
                if (e.target === this) closeViewAttendanceModal();
            });
        }
    });

    function exportToExcel() {
        // Implement Excel export functionality
        alert('Exporting attendance data to Excel');
    }

    // Tab switching functions
    function showAttendance() {
        document.getElementById('attendanceBtn').classList.add('active');
        document.getElementById('payrollBtn').classList.remove('active');
        document.getElementById('attendance-results').style.display = 'block';
        document.getElementById('payroll-results').style.display = 'none';
        document.getElementById('attendance-filter').style.display = 'block';
        
        // Update header title
        document.getElementById('page-title').innerHTML = '<i class="fas fa-history"></i> My Attendance History';
        
        // Reset attendance summary cards to original state
        resetAttendanceSummaryCards();
    }

    function showPayroll() {
        document.getElementById('payrollBtn').classList.add('active');
        document.getElementById('attendanceBtn').classList.remove('active');
        document.getElementById('attendance-results').style.display = 'none';
        document.getElementById('payroll-results').style.display = 'block';
        document.getElementById('attendance-filter').style.display = 'none';
        
        // Update header title
        document.getElementById('page-title').innerHTML = '<i class="fas fa-money-bill-wave"></i> Payroll History';
        
        // Load payroll data when switching to payroll tab
        loadPayrollData();
    }

    // Payroll functions
    function loadPayrollData() {
        const year = document.getElementById('payroll-year').value;
        const month = document.getElementById('payroll-month').value;
        
        // Show loading state
        const payrollBody = document.getElementById('payroll-results-body');
        payrollBody.innerHTML = '<tr><td colspan="9" style="text-align: center;"><i class="fas fa-spinner fa-spin"></i> Loading payroll data...</td></tr>';
        
        // Update attendance summary cards based on payroll filters
        updateAttendanceSummaryForPayroll(year, month);
        
        // Fetch payroll data
        fetch(`get_employee_payroll_history.php?employee_id=<?php echo $employee_id; ?>&year=${year}&month=${month}`)
            .then(response => response.json())
            .then(data => {
                renderPayrollData(data);
            })
            .catch(error => {
                console.error('Error loading payroll data:', error);
                payrollBody.innerHTML = '<tr><td colspan="9" style="text-align: center;">Error loading payroll data</td></tr>';
            });
    }

    function renderPayrollData(data) {
        const payrollBody = document.getElementById('payroll-results-body');
        
        if (data.records && data.records.length > 0) {
            const rows = data.records.map(record => {
                const statusClass = record.status === 'processed' ? 'status-present' : 'status-pending';
                const statusText = record.status === 'processed' ? 'Processed' : 'Pending';
                
                return `
                    <tr data-period="${record.pay_period}" 
                        data-basic-salary="${record.basic_salary}" 
                        data-overtime-pay="${record.overtime_pay || 0}" 
                        data-holiday-pay="${record.holiday_pay || 0}" 
                        data-gross-pay="${record.gross_pay}" 
                        data-deductions="${record.total_deductions}" 
                        data-net-pay="${record.net_pay}">
                        <td>${record.pay_period}</td>
                        <td>₱${parseFloat(record.basic_salary).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td>₱${parseFloat(record.overtime_pay || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td>₱${parseFloat(record.holiday_pay || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td>₱${parseFloat(record.gross_pay).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td>₱${parseFloat(record.total_deductions).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td>₱${parseFloat(record.net_pay).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                        <td>
                            <button class="btn btn-primary btn-sm" onclick="viewPayslip('${record.pay_period}')">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
            
            payrollBody.innerHTML = rows;
        } else {
            payrollBody.innerHTML = '<tr><td colspan="9" style="text-align: center;">No payroll records found</td></tr>';
        }
        
        // Update summary cards
        updatePayrollSummary(data.summary || {});
    }

    function updatePayrollSummary(summary) {
        document.getElementById('payroll-periods-count').textContent = summary.periods_count || 0;
        document.getElementById('total-gross-pay').textContent = '₱' + (parseFloat(summary.total_gross_pay || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        document.getElementById('total-deductions').textContent = '₱' + (parseFloat(summary.total_deductions || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        document.getElementById('total-net-pay').textContent = '₱' + (parseFloat(summary.total_net_pay || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        document.getElementById('current-13th-month-pay').textContent = '₱' + (parseFloat(summary.current_13th_month_pay || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    }

    function updateAttendanceSummaryForPayroll(year, month) {
        // Build attendance filter parameters based on payroll filters
        let attendanceParams = new URLSearchParams();
        attendanceParams.set('ajax', '1');
        
        if (year && month) {
            attendanceParams.set('month', `${year}-${month}`);
        } else if (year) {
            attendanceParams.set('year', year);
        }
        
        // Fetch attendance data with the same filters as payroll
        fetch(`${window.location.pathname}?${attendanceParams.toString()}`)
            .then(response => response.json())
            .then(data => {
                // Update attendance summary cards
                updateAttendanceSummaryCards(data.summary, year, month);
            })
            .catch(error => {
                console.error('Error loading attendance data for payroll filters:', error);
            });
    }

    function updateAttendanceSummaryCards(summary, year, month) {
        // Update summary values
        document.getElementById('summary-total').textContent = Number(summary.total_records || 0);
        document.getElementById('summary-present').textContent = Number(summary.present_count || 0);
        document.getElementById('summary-absent').textContent = Number(summary.absent_count || 0);
        document.getElementById('summary-late').textContent = Number(summary.late_count || 0);
        
        // Update summary labels based on payroll filters
        const totalLabel = document.getElementById('summary-total-label');
        const presentLabel = document.getElementById('summary-present-label');
        const absentLabel = document.getElementById('summary-absent-label');
        const lateLabel = document.getElementById('summary-late-label');
        
        if (year && month) {
            const monthName = new Date(year, month - 1).toLocaleDateString('en-US', { month: 'long' });
            totalLabel.textContent = `Total Records in ${monthName} ${year}`;
            presentLabel.textContent = `Present Days in ${monthName} ${year}`;
            absentLabel.textContent = `Absent Days in ${monthName} ${year}`;
            lateLabel.textContent = `Late Days in ${monthName} ${year}`;
        } else if (year) {
            totalLabel.textContent = `Total Records in ${year}`;
            presentLabel.textContent = `Present Days in ${year}`;
            absentLabel.textContent = `Absent Days in ${year}`;
            lateLabel.textContent = `Late Days in ${year}`;
        } else {
            totalLabel.textContent = 'Total Records (All Time)';
            presentLabel.textContent = 'Total Present Days';
            absentLabel.textContent = 'Total Absent Days';
            lateLabel.textContent = 'Total Late Days';
        }
    }

    function resetAttendanceSummaryCards() {
        // Reset to original PHP values
        <?php
        // Get original summary data for reset
        $original_summary = $summary_data;
        ?>
        
        // Update summary values to original state
        document.getElementById('summary-total').textContent = <?php echo (int)($original_summary['total_records'] ?? 0); ?>;
        document.getElementById('summary-present').textContent = <?php echo (int)($original_summary['present_count'] ?? 0); ?>;
        document.getElementById('summary-absent').textContent = <?php echo (int)($original_summary['absent_count'] ?? 0); ?>;
        document.getElementById('summary-late').textContent = <?php echo (int)($original_summary['late_count'] ?? 0); ?>;
        
        // Reset labels to original state
        document.getElementById('summary-total-label').textContent = '<?php echo $is_year_filter ? "Total Records in " . htmlspecialchars($selected_year) : ($is_month_filter ? "Total Records in " . htmlspecialchars($selected_month) : ($is_date_filter ? "Total Records on " . htmlspecialchars($selected_date) : "Total Records (All Time)")); ?>';
        document.getElementById('summary-present-label').textContent = '<?php echo $is_year_filter ? "Present Days in " . htmlspecialchars($selected_year) : ($is_month_filter ? "Present Days in " . htmlspecialchars($selected_month) : ($is_date_filter ? "Present on " . htmlspecialchars($selected_date) : "Total Present Days")); ?>';
        document.getElementById('summary-absent-label').textContent = '<?php echo $is_year_filter ? "Absent Days in " . htmlspecialchars($selected_year) : ($is_month_filter ? "Absent Days in " . htmlspecialchars($selected_month) : ($is_date_filter ? "Absent on " . htmlspecialchars($selected_date) : "Total Absent Days")); ?>';
        document.getElementById('summary-late-label').textContent = '<?php echo $is_year_filter ? "Late Days in " . htmlspecialchars($selected_year) : ($is_month_filter ? "Late Days in " . htmlspecialchars($selected_month) : ($is_date_filter ? "Late on " . htmlspecialchars($selected_date) : "Total Late Days")); ?>';
    }

    function viewPayslip(payPeriod) {
        // Get the payroll record data from the table row
        const payrollRow = document.querySelector(`tr[data-period="${payPeriod}"]`);
        if (!payrollRow) {
            console.error('Payroll record not found for period:', payPeriod);
            return;
        }
        
        // Extract data from the row
        const basicSalary = payrollRow.dataset.basicSalary || '0';
        const overtimePay = payrollRow.dataset.overtimePay || '0';
        const holidayPay = payrollRow.dataset.holidayPay || '0';
        const grossPay = payrollRow.dataset.grossPay || '0';
        const deductions = payrollRow.dataset.deductions || '0';
        const netPay = payrollRow.dataset.netPay || '0';
        
        // Format currency
        const formatCurrency = (value) => '₱' + parseFloat(value.replace(/,/g, '')).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        
        // Set employee information
        document.getElementById('modal-employee-name').textContent = '<?php echo htmlspecialchars($employee['EmployeeName']); ?>';
        document.getElementById('modal-employee-id').textContent = '<?php echo $employee_id; ?>';
        document.getElementById('modal-employee-dept').textContent = '<?php echo htmlspecialchars($employee['Department']); ?>';
        document.getElementById('modal-pay-period-detail').textContent = payPeriod;
        document.getElementById('modal-payment-date').textContent = new Date().toLocaleDateString();
        
        // Set pay period in header
        document.getElementById('modal-pay-period-value').textContent = payPeriod;
        
        // Set earnings
        document.getElementById('modal-basic-salary').textContent = formatCurrency(basicSalary);
        document.getElementById('modal-overtime-pay').textContent = formatCurrency(overtimePay);
        document.getElementById('modal-holiday-pay').textContent = formatCurrency(holidayPay);
        
        // Calculate total earnings
        const totalEarnings = parseFloat(basicSalary.replace(/,/g, '')) + 
                            parseFloat(overtimePay.replace(/,/g, '')) + 
                            parseFloat(holidayPay.replace(/,/g, ''));
        document.getElementById('modal-total-earnings').textContent = formatCurrency(totalEarnings.toString());
        document.getElementById('modal-summary-earnings').textContent = formatCurrency(totalEarnings.toString());
        
        // Set deductions (simplified for employee view)
        const totalDeductions = parseFloat(deductions.replace(/,/g, ''));
        document.getElementById('modal-sss-deduction').textContent = formatCurrency((totalDeductions * 0.3).toString());
        document.getElementById('modal-philhealth-deduction').textContent = formatCurrency((totalDeductions * 0.3).toString());
        document.getElementById('modal-pagibig-deduction').textContent = formatCurrency((totalDeductions * 0.2).toString());
        document.getElementById('modal-tax-deduction').textContent = formatCurrency((totalDeductions * 0.2).toString());
        document.getElementById('modal-total-deductions').textContent = formatCurrency(deductions);
        document.getElementById('modal-summary-deductions').textContent = formatCurrency(deductions);
        
        // Set net pay
        document.getElementById('modal-net-pay').textContent = formatCurrency(netPay);
        
        // Show the modal
        document.getElementById('payslipModal').style.display = 'block';
    }

    function closePayslipModal() {
        document.getElementById('payslipModal').style.display = 'none';
    }

    function exportPayslipPDF() {
        const employeeId = '<?php echo $employee_id; ?>';
        const year = document.getElementById('payroll-year').value;
        const month = document.getElementById('payroll-month').value;
        
        if (!employeeId) {
            alert('Employee ID not found');
            return;
        }
        
        // Build month parameter for the payslip generator
        let monthParam = '';
        if (month && year) {
            monthParam = `${year}-${month.padStart(2, '0')}`;
        } else if (year) {
            monthParam = `${year}-01`; // Default to January if only year provided
        } else {
            // Use current month if no filters selected
            const now = new Date();
            monthParam = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
        }
        
        // Create download link using the existing payslip generator
        const downloadUrl = `generate_payslip_pdf.php?employee_id=${employeeId}&month=${monthParam}`;
        
        // Create temporary link and trigger download
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = `Payslip_${employeeId}_${monthParam}.pdf`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // View Attendance Modal Functions
    function openViewAttendanceModal(rowEl) {
        console.log('openViewAttendanceModal called with:', rowEl);
        const body = document.getElementById('viewAttendanceBody');
        if (!body) {
            console.error('viewAttendanceBody element not found');
            return;
        }
        
        const fmt = (t)=> t ? new Date('1970-01-01T' + t).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '-';
        const date = rowEl.getAttribute('data-date') || '-';
        const rawAmIn = rowEl.getAttribute('data-am_in');
        const rawAmOut = rowEl.getAttribute('data-am_out');
        const rawPmIn = rowEl.getAttribute('data-pm_in');
        const rawPmOut = rowEl.getAttribute('data-pm_out');
        const amIn = fmt(rawAmIn);
        const amOut = fmt(rawAmOut);
        const pmIn = fmt(rawPmIn);
        const pmOut = fmt(rawPmOut);
        const status = rowEl.getAttribute('data-status') || '-';
        const type = rowEl.getAttribute('data-attendance_type') || '-';
        const late = rowEl.getAttribute('data-late') || '0';
        const early = rowEl.getAttribute('data-early') || '0';
        const ot = rowEl.getAttribute('data-ot') || '0';
        const notes = rowEl.getAttribute('data-notes') || '-';
        const shift = rowEl.getAttribute('data-shift') || '-';
        const isHalfDay = status === 'half_day';
        const hasAmPairOnly = rawAmIn && rawAmOut && !rawPmIn && !rawPmOut;
        const hasPmPairOnly = rawPmIn && rawPmOut && !rawAmIn && !rawAmOut;
        let sessionHtml = '';
        if (isHalfDay && hasPmPairOnly) {
            sessionHtml = `
                <div class="time-row">
                    <span class="time-label">PM In (Lunch End):</span>
                    <span class="time-value">${pmIn}</span>
                </div>
                <div class="time-row">
                    <span class="time-label">PM Out:</span>
                    <span class="time-value">${pmOut}</span>
                </div>
            `;
        } else if (isHalfDay && hasAmPairOnly) {
            sessionHtml = `
                <div class="time-row">
                    <span class="time-label">AM In:</span>
                    <span class="time-value">${amIn}</span>
                </div>
                <div class="time-row">
                    <span class="time-label">AM Out (Lunch Start):</span>
                    <span class="time-value">${amOut}</span>
                </div>
            `;
        } else {
            sessionHtml = `
                <div class="time-row">
                    <span class="time-label">AM In:</span>
                    <span class="time-value">${amIn}</span>
                </div>
                <div class="time-row">
                    <span class="time-label">AM Out (Lunch Start):</span>
                    <span class="time-value">${amOut}</span>
                </div>
                <div class="time-row">
                    <span class="time-label">PM In (Lunch End):</span>
                    <span class="time-value">${pmIn}</span>
                </div>
                <div class="time-row">
                    <span class="time-label">PM Out:</span>
                    <span class="time-value">${pmOut}</span>
                </div>
            `;
        }
        body.innerHTML = `
            <div class="attendance-modal-content">
                <!-- Main Info Section -->
                <div class="main-info-section">
                    <div class="info-row">
                        <div class="info-item">
                            <span class="info-label">Date:</span>
                            <span class="info-value">${new Date(date).toLocaleDateString()}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Shift:</span>
                            <span class="info-value">${shift}</span>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-item">
                            <span class="info-label">Type:</span>
                            <span class="info-badge type-${type.toLowerCase()}">${type.charAt(0).toUpperCase() + type.slice(1)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Status:</span>
                            <span class="info-badge status-${status.toLowerCase()}">${status === 'half_day' ? 'Half Day' : (status.charAt(0).toUpperCase() + status.slice(1))}</span>
                        </div>
                    </div>
                </div>

                <!-- Time Tracking Section -->
                <div class="time-section">
                    <h4 class="section-title">
                        <i class="fas fa-clock"></i>
                        Time Tracking
                    </h4>
                    <div class="time-container">
                        ${sessionHtml}
                    </div>
                </div>

                <!-- Metrics Section -->
                <div class="metrics-section">
                    <h4 class="section-title">
                        <i class="fas fa-chart-line"></i>
                        Performance Metrics
                    </h4>
                    <div class="metrics-grid">
                        <div class="metric-item">
                            <span class="metric-label">Late (min)</span>
                            <span class="metric-value">${status === 'half_day' ? '-' : late}</span>
                        </div>
                        <div class="metric-item">
                            <span class="metric-label">Early Out (min)</span>
                            <span class="metric-value">${status === 'half_day' ? '-' : early}</span>
                        </div>
                        <div class="metric-item">
                            <span class="metric-label">Overtime (hrs)</span>
                            <span class="metric-value">${parseFloat(ot).toFixed(2)}</span>
                        </div>
                    </div>
                    ${notes !== '-' ? `
                    <div class="notes-section">
                        <span class="notes-label">Notes:</span>
                        <span class="notes-value">${notes}</span>
                    </div>
                    ` : ''}
                </div>
            </div>
        `;
        const modal = document.getElementById('viewAttendanceModal');
        if (!modal) {
            console.error('viewAttendanceModal element not found');
            return;
        }
        console.log('Showing modal');
        modal.classList.add('show');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    
    function closeViewAttendanceModal() {
        const modal = document.getElementById('viewAttendanceModal');
        if (modal) {
            modal.classList.remove('show');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    // Records per page change function
    function changeRecordsPerPage(value) {
        const url = new URL(window.location);
        url.searchParams.set('records_per_page', value);
        url.searchParams.set('page', '1'); // Reset to first page
        window.location.href = url.toString();
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