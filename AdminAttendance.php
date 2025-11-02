<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
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
    die("Connection Failed: " . $conn->connect_error);
}

// Align MySQL session timezone with PHP for consistent DATE() comparisons
$conn->query("SET time_zone = '+08:00'");

// --- Filter Logic & Query Building ---
$attendance_records = [];
$where_conditions = ["1=1"]; // Start with a base condition
$params = [];
$param_types = "";

// Pagination params
$per_page = isset($_GET['per_page']) && $_GET['per_page'] !== '' ? (int)$_GET['per_page'] : 50;
if ($per_page <= 0) { $per_page = 50; }
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $per_page;

// Apply search filter
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
    $search_term = "%$search%";
    $where_conditions[] = "(e.EmployeeName LIKE ? OR e.EmployeeID LIKE ? OR e.Department LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= "sss";
}

// Force date filter to today only for AdminAttendance
$filter_mode = 'today';
$today_date = date('Y-m-d');
$where_conditions[] = "DATE(a.attendance_date) = ?";
$params[] = $today_date;
$param_types .= "s";


// Apply department filter
if (isset($_GET['department']) && !empty($_GET['department'])) {
    $filter_department = $conn->real_escape_string($_GET['department']);
    $where_conditions[] = "e.Department = ?";
    $params[] = $filter_department;
    $param_types .= "s";
}

// Apply attendance type filter
if (isset($_GET['attendance_type']) && !empty($_GET['attendance_type'])) {
    $filter_attendance_type = $conn->real_escape_string($_GET['attendance_type']);
    $where_conditions[] = "COALESCE(a.attendance_type, 'absent') = ?";
    $params[] = $filter_attendance_type;
    $param_types .= "s";
}

// Apply status filter
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $filter_status = $conn->real_escape_string($_GET['status']);
    $where_conditions[] = "a.status = ?";
    $params[] = $filter_status;
    $param_types .= "s";
}

// Apply shift filter
if (isset($_GET['shift']) && !empty($_GET['shift'])) {
    $filter_shift = $conn->real_escape_string($_GET['shift']);
    $where_conditions[] = "e.Shift = ?";
    $params[] = $filter_shift;
    $param_types .= "s";
}

// Construct the final WHERE clause
$where_clause = implode(' AND ', $where_conditions);

// Build the main query to include all active employees (show absents) using LEFT JOIN on today's date
$query = "SELECT 
            COALESCE(a.id, 0) as id,
            e.EmployeeID,
            COALESCE(a.attendance_date, ?) as attendance_date,
            COALESCE(a.attendance_type, 'absent') as attendance_type,
            COALESCE(a.status, NULL) as status,
            COALESCE(a.time_in, NULL) as time_in,
            COALESCE(a.time_out, NULL) as time_out,
            COALESCE(a.time_in_morning, NULL) as time_in_morning,
            COALESCE(a.time_out_morning, NULL) as time_out_morning,
            COALESCE(a.time_in_afternoon, NULL) as time_in_afternoon,
            COALESCE(a.time_out_afternoon, NULL) as time_out_afternoon,
            COALESCE(a.notes, 'No attendance record') as notes,
            COALESCE(a.overtime_hours, 0) as overtime_hours,
            COALESCE(a.is_overtime, 0) as is_overtime,
            COALESCE(a.late_minutes, 0) as late_minutes,
            COALESCE(a.early_out_minutes, 0) as early_out_minutes,
            COALESCE(a.is_on_leave, 0) as is_on_leave,
            e.EmployeeName,
            e.Department,
            e.Shift
          FROM empuser e
          LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND DATE(a.attendance_date) = ?
          WHERE e.Status = 'active'";

// Append optional non-date filters
if (!empty($filter_department)) { $query .= " AND e.Department = ?"; }
if (!empty($filter_attendance_type)) { $query .= " AND COALESCE(a.attendance_type, 'absent') = ?"; }
if (!empty($filter_status)) { 
    if ($filter_status === 'on_leave') {
        $query .= " AND a.is_on_leave = 1";
    } else {
        $query .= " AND a.status = ?";
    }
}
if (!empty($filter_shift)) { $query .= " AND e.Shift = ?"; }
if (!empty($search_term)) { $query .= " AND (e.EmployeeName LIKE ? OR e.EmployeeID LIKE ? OR e.Department LIKE ?)"; }

$query .= " ORDER BY COALESCE(a.attendance_date, ?) DESC, COALESCE(a.time_in, '00:00:00') DESC";

// Apply pagination
$query .= " LIMIT ? OFFSET ?";

// Prepare and bind parameters: coalesce date, join date, then optional filters, then order-by date
$stmt = $conn->prepare($query);

if (!$stmt) {
    die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
}

$bind_params = [];
$bind_types = '';

// coalesce date and join date
$bind_params[] = $today_date;
$bind_types .= 's';
$bind_params[] = $today_date;
$bind_types .= 's';

// optional filters in same order as appended
if (!empty($filter_department)) { $bind_params[] = $filter_department; $bind_types .= 's'; }
if (!empty($filter_attendance_type)) { $bind_params[] = $filter_attendance_type; $bind_types .= 's'; }
if (!empty($filter_status) && $filter_status !== 'on_leave') { $bind_params[] = $filter_status; $bind_types .= 's'; }
if (!empty($filter_shift)) { $bind_params[] = $filter_shift; $bind_types .= 's'; }
if (!empty($search_term)) { $bind_params[] = $search_term; $bind_params[] = $search_term; $bind_params[] = $search_term; $bind_types .= 'sss'; }

// order-by date
$bind_params[] = $today_date;
$bind_types .= 's';

// pagination params
$bind_params[] = $per_page;
$bind_types .= 'i';
$bind_params[] = $offset;
$bind_types .= 'i';

if (!$stmt->bind_param($bind_types, ...$bind_params)) {
    die("Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error);
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

// --- Total count for pagination (respecting the same filters) ---
$count_sql = "SELECT COUNT(*) AS total_count
              FROM empuser e
              LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND DATE(a.attendance_date) = ?
              WHERE e.Status = 'active'";
if (!empty($filter_department)) { $count_sql .= " AND e.Department = ?"; }
if (!empty($filter_attendance_type)) { $count_sql .= " AND COALESCE(a.attendance_type, 'absent') = ?"; }
if (!empty($filter_status)) {
    if ($filter_status === 'on_leave') {
        $count_sql .= " AND a.is_on_leave = 1";
    } else {
        $count_sql .= " AND a.status = ?";
    }
}
if (!empty($filter_shift)) { $count_sql .= " AND e.Shift = ?"; }
if (!empty($search_term)) { $count_sql .= " AND (e.EmployeeName LIKE ? OR e.EmployeeID LIKE ? OR e.Department LIKE ?)"; }

$count_stmt = $conn->prepare($count_sql);
if (!$count_stmt) {
    die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
}

$count_bind_params = [];
$count_bind_types = '';
$count_bind_params[] = $today_date; $count_bind_types .= 's';
if (!empty($filter_department)) { $count_bind_params[] = $filter_department; $count_bind_types .= 's'; }
if (!empty($filter_attendance_type)) { $count_bind_params[] = $filter_attendance_type; $count_bind_types .= 's'; }
if (!empty($filter_status) && $filter_status !== 'on_leave') { $count_bind_params[] = $filter_status; $count_bind_types .= 's'; }
if (!empty($filter_shift)) { $count_bind_params[] = $filter_shift; $count_bind_types .= 's'; }
if (!empty($search_term)) { $count_bind_params[] = $search_term; $count_bind_params[] = $search_term; $count_bind_params[] = $search_term; $count_bind_types .= 'sss'; }

if (!empty($count_bind_params)) {
    if (!$count_stmt->bind_param($count_bind_types, ...$count_bind_params)) {
        die("Binding parameters failed: (" . $count_stmt->errno . ") " . $count_stmt->error);
    }
}

if(!$count_stmt->execute()){
    die("Execute failed: (" . $count_stmt->errno . ") " . $count_stmt->error);
}
$count_res = $count_stmt->get_result();
$total_records = 0;
if ($count_res) {
    $row = $count_res->fetch_assoc();
    $total_records = (int)($row['total_count'] ?? 0);
}
$count_stmt->close();

$total_pages = $per_page > 0 ? max(1, (int)ceil($total_records / $per_page)) : 1;

// Apply accurate calculations to all attendance records
$attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);

// --- End Filter Logic & Query Building ---

// Handle attendance status update (Keep existing logic, but use prepared statements if modifying data)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['attendance_id'])) {
        // Status update logic (Consider using prepared statements here too for safety)
        $attendance_id = (int)$_POST['attendance_id']; // Cast to int
        $action = $_POST['action'];

        if ($action === 'approve' || $action === 'reject') {
            $status = ($action === 'approve') ? 'present' : 'absent';
            
            // Get the attendance record to recalculate metrics
            $get_record_query = "SELECT * FROM attendance WHERE id = ?";
            $stmt_get = $conn->prepare($get_record_query);
            $stmt_get->bind_param("i", $attendance_id);
            $stmt_get->execute();
            $record_result = $stmt_get->get_result();
            $record = $record_result->fetch_assoc();
            $stmt_get->close();
            
            if ($record) {
                // Recalculate metrics with updated status
                $updated_records = AttendanceCalculator::calculateAttendanceMetrics([$record]);
                $updated_record = $updated_records[0];
                $updated_record['status'] = $status; // Override status based on action
                
                // Update with accurate calculations
                AttendanceCalculator::updateAttendanceRecord($conn, $attendance_id, $updated_record);
            }
        }
    } elseif(isset($_POST['employee'])) { // Check if it's the add attendance form submission
        // --- Removed Handle Add Attendance Form Submission ---
    }

    // Redirect to refresh the page with current filters
    $redirect_url = "AdminAttendance.php" . (isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    header("Location: " . $redirect_url);
    exit;
}

// --- Summary & Filters Support (aligned with AdminHistory) ---
// Get departments for filter dropdown
$departments = [];
$departments_result = $conn->query("SELECT DISTINCT Department FROM empuser ORDER BY Department");
if ($departments_result) {
    while ($dept = $departments_result->fetch_assoc()) {
        $departments[] = $dept['Department'];
    }
}

// Summary analytics - build query similar to main query but for summary
$summary_where_conditions = ["e.Status = 'active'"];
$summary_params = [];
$summary_param_types = "";

// Apply same filters as main query
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
    $search_term = "%$search%";
    $summary_where_conditions[] = "(e.EmployeeName LIKE ? OR e.EmployeeID LIKE ? OR e.Department LIKE ?)";
    $summary_params[] = $search_term;
    $summary_params[] = $search_term;
    $summary_params[] = $search_term;
    $summary_param_types .= "sss";
}

// Force date filter to today only
$summary_where_conditions[] = "DATE(a.attendance_date) = ?";
$summary_params[] = $today_date;
$summary_param_types .= "s";

// Apply department filter
if (isset($_GET['department']) && !empty($_GET['department'])) {
    $filter_department = $conn->real_escape_string($_GET['department']);
    $summary_where_conditions[] = "e.Department = ?";
    $summary_params[] = $filter_department;
    $summary_param_types .= "s";
}

// Apply attendance type filter
if (isset($_GET['attendance_type']) && !empty($_GET['attendance_type'])) {
    $filter_attendance_type = $conn->real_escape_string($_GET['attendance_type']);
    $summary_where_conditions[] = "COALESCE(a.attendance_type, 'absent') = ?";
    $summary_params[] = $filter_attendance_type;
    $summary_param_types .= "s";
}

// Apply status filter
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $filter_status = $conn->real_escape_string($_GET['status']);
    if ($filter_status === 'on_leave') {
        $summary_where_conditions[] = "a.is_on_leave = 1";
    } else {
        $summary_where_conditions[] = "a.status = ?";
        $summary_params[] = $filter_status;
        $summary_param_types .= "s";
    }
}

// Apply shift filter
if (isset($_GET['shift']) && !empty($_GET['shift'])) {
    $filter_shift = $conn->real_escape_string($_GET['shift']);
    $summary_where_conditions[] = "e.Shift = ?";
    $summary_params[] = $filter_shift;
    $summary_param_types .= "s";
}

$summary_where_clause = implode(' AND ', $summary_where_conditions);

$summary_query = "SELECT 
    COUNT(*) as total_records,
    SUM(CASE WHEN COALESCE(a.attendance_type, 'absent') = 'present' THEN 1 ELSE 0 END) as present_count,
    SUM(CASE WHEN COALESCE(a.attendance_type, 'absent') = 'absent' THEN 1 ELSE 0 END) as absent_count,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
    SUM(CASE WHEN a.early_out_minutes > 0 THEN 1 ELSE 0 END) as early_count,
    SUM(CASE WHEN COALESCE(a.is_on_leave, 0) = 1 THEN 1 ELSE 0 END) as on_leave_count
    FROM empuser e
    LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND DATE(a.attendance_date) = ?
    WHERE $summary_where_clause";

$summary_stmt = $conn->prepare($summary_query);
if ($summary_stmt) {
    // Use the already built $summary_params array and add the JOIN date parameter
    $all_summary_params = [$today_date]; // First parameter for the JOIN condition
    $all_summary_types = 's';
    
    // Add all the WHERE clause parameters
    foreach ($summary_params as $param) {
        $all_summary_params[] = $param;
    }
    $all_summary_types .= $summary_param_types;
    
    if (!empty($all_summary_params)) {
        $summary_stmt->bind_param($all_summary_types, ...$all_summary_params);
    }
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    $summary_data = $summary_result ? $summary_result->fetch_assoc() : [
        'total_records' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'early_count' => 0, 'on_leave_count' => 0
    ];
    $summary_stmt->close();
} else {
    $summary_data = ['total_records' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'early_count' => 0, 'on_leave_count' => 0];
}

// Map summary to UI variables and compute period-aware metrics
$present_today = (int)($summary_data['present_count'] ?? 0);
$late_today = (int)($summary_data['late_count'] ?? 0);
$early_today = (int)($summary_data['early_count'] ?? 0);
$on_leave_today = (int)($summary_data['on_leave_count'] ?? 0);

// Total employees (company-wide)
$all_emp_res = $conn->query("SELECT COUNT(*) AS total_employees FROM empuser");
$all_emp_row = $all_emp_res ? $all_emp_res->fetch_assoc() : ['total_employees' => 0];
$all_employees = (int)($all_emp_row['total_employees'] ?? 0);

// Determine if month filter is active (based on earlier filter_mode & params)
$is_month_filter = ($filter_mode === 'month' && !empty($filter_month));

if ($is_month_filter) {
    // Count present occurrences in the month respecting current where clause
    $monthly_present_sql = "SELECT COUNT(CASE WHEN a.attendance_type='present' THEN 1 END) AS total_present
                            FROM attendance a
                            JOIN empuser e ON a.EmployeeID = e.EmployeeID
                            WHERE $where_clause";
    $monthly_present_stmt = $conn->prepare($monthly_present_sql);
    if (!empty($params)) { $monthly_present_stmt->bind_param($param_types, ...$params); }
    $monthly_present_stmt->execute();
    $monthly_present_res = $monthly_present_stmt->get_result();
    $monthly_present_row = $monthly_present_res ? $monthly_present_res->fetch_assoc() : ['total_present' => 0];
    $total_present_occurrences = (int)($monthly_present_row['total_present'] ?? 0);
    $monthly_present_stmt->close();

    // Recorded days in the month
    $monthly_days_sql = "SELECT COUNT(DISTINCT DATE(a.attendance_date)) AS recorded_days
                         FROM attendance a
                         JOIN empuser e ON a.EmployeeID = e.EmployeeID
                         WHERE $where_clause";
    $monthly_days_stmt = $conn->prepare($monthly_days_sql);
    if (!empty($params)) { $monthly_days_stmt->bind_param($param_types, ...$params); }
    $monthly_days_stmt->execute();
    $monthly_days_res = $monthly_days_stmt->get_result();
    $monthly_days_row = $monthly_days_res ? $monthly_days_res->fetch_assoc() : ['recorded_days' => 0];
    $recorded_days = (int)($monthly_days_row['recorded_days'] ?? 0);
    $monthly_days_stmt->close();

    $total_slots = $all_employees * $recorded_days;
    $absent_today = $total_slots > 0 ? max(0, $total_slots - $total_present_occurrences) : 0; // repurpose var as monthly absent count
    $attendance_rate = $total_slots > 0 ? round(($total_present_occurrences / $total_slots) * 100) : 0;
    $present_today = $total_present_occurrences; // reuse var for display
    $within_days_text = $recorded_days > 0 ? "within {$recorded_days} days" : "within 0 days";
} else {
    // Daily/date: absent = total employees - present employees today
    // If filtering by "on leave", show summary for all employees, not just filtered ones
    if (isset($_GET['status']) && $_GET['status'] === 'on_leave') {
        // For "on leave" filter, get summary for all employees
        $all_summary_sql = "SELECT 
            COUNT(*) as total_records,
            SUM(CASE WHEN COALESCE(a.attendance_type, 'absent') = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN COALESCE(a.attendance_type, 'absent') = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN a.early_out_minutes > 0 THEN 1 ELSE 0 END) as early_count,
            SUM(CASE WHEN COALESCE(a.is_on_leave, 0) = 1 THEN 1 ELSE 0 END) as on_leave_count
            FROM empuser e
            LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND DATE(a.attendance_date) = ?
            WHERE e.Status = 'active'";
        
        $all_summary_stmt = $conn->prepare($all_summary_sql);
        $all_summary_stmt->bind_param("s", $today_date);
        $all_summary_stmt->execute();
        $all_summary_result = $all_summary_stmt->get_result();
        $all_summary_data = $all_summary_result ? $all_summary_result->fetch_assoc() : [
            'total_records' => 0, 'present_count' => 0, 'absent_count' => 0, 'late_count' => 0, 'early_count' => 0, 'on_leave_count' => 0
        ];
        $all_summary_stmt->close();
        
        $present_today = (int)($all_summary_data['present_count'] ?? 0);
        $late_today = (int)($all_summary_data['late_count'] ?? 0);
        $early_today = (int)($all_summary_data['early_count'] ?? 0);
        $on_leave_today = (int)($all_summary_data['on_leave_count'] ?? 0);
    }
    
    $absent_today = max(0, $all_employees - $present_today);
    // Attendance rate against ALL employees
    $active_sql = "SELECT COUNT(*) AS total_active FROM empuser";
    $active_res = $conn->query($active_sql);
    $active_row = $active_res ? $active_res->fetch_assoc() : ['total_active' => 0];
    $total_active_employees = (int)($active_row['total_active'] ?? 0);
    $attendance_rate = $total_active_employees > 0 
        ? round(($present_today / $total_active_employees) * 100)
        : 0;
    $within_days_text = '';
}

$conn->close(); // Close the main connection here, after all data fetching
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management - WTEI</title>
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

        .summary-icon.early {
            background-color: rgba(76, 175, 80, 0.15);
            color: #4caf50;
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

        .filter-mode-indicator {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: 10px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
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
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }

        .filter-mode-buttons .date-filter-btn:last-child {
            border-right: none;
        }

        .date-filter-btn.active {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }

        .date-filter-btn.active i {
            color: white;
        }

        .date-filter-btn:not(.active):hover {
            background-color: #F8FBFF;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(63, 114, 175, 0.15);
        }

        .date-filter-btn:not(.active):hover i {
            color: #3F72AF;
        }

        .filter-input-area {
            padding: 15px 0;
            border-top: 1px solid #DBE2EF;
            transition: all 0.3s ease;
        }

        .filter-input-area > div {
            padding-top: 15px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .date-input-container label,
        .month-input-container label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #112D4E;
        }

        .date-input-container label i,
        .month-input-container label i {
            color: #3F72AF;
            font-size: 16px;
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

        /* Enhanced Date and Month Input Styling */
        .date-input-container input[type="date"],
        .month-input-container input[type="month"] {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #DBE2EF;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            color: #112D4E;
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            position: relative;
            cursor: pointer;
        }

        .date-input-container input[type="date"]:focus,
        .month-input-container input[type="month"]:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.15);
            transform: translateY(-2px);
            background: white;
        }

        .date-input-container input[type="date"]:hover,
        .month-input-container input[type="month"]:hover {
            border-color: #3F72AF;
            box-shadow: 0 6px 16px rgba(63, 114, 175, 0.12);
            transform: translateY(-1px);
        }

        /* Custom styling for date/month inputs */
        .date-input-container,
        .month-input-container {
            position: relative;
            margin-bottom: 20px;
        }

        .date-input-container::before,
        .month-input-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(63, 114, 175, 0.05) 0%, rgba(17, 45, 78, 0.02) 100%);
            border-radius: 12px;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .date-input-container:focus-within::before,
        .month-input-container:focus-within::before {
            opacity: 1;
        }

        /* Enhanced label styling for date/month inputs */
        .date-input-container label,
        .month-input-container label {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            font-weight: 600;
            font-size: 15px;
            color: #112D4E;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .date-input-container label i,
        .month-input-container label i {
            color: #3F72AF;
            font-size: 18px;
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Input container wrapper styling */
        .date-input-container,
        .month-input-container {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border: 1px solid rgba(219, 226, 239, 0.3);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .date-input-container:hover,
        .month-input-container:hover {
            box-shadow: 0 8px 25px rgba(63, 114, 175, 0.1);
            transform: translateY(-2px);
        }

        /* Placeholder styling for date inputs */
        .date-input-container input[type="date"]::-webkit-datetime-edit-text,
        .month-input-container input[type="month"]::-webkit-datetime-edit-text {
            color: #6c757d;
            font-weight: 500;
        }

        .date-input-container input[type="date"]::-webkit-datetime-edit-month-field,
        .date-input-container input[type="date"]::-webkit-datetime-edit-day-field,
        .date-input-container input[type="date"]::-webkit-datetime-edit-year-field,
        .month-input-container input[type="month"]::-webkit-datetime-edit-month-field,
        .month-input-container input[type="month"]::-webkit-datetime-edit-year-field {
            color: #112D4E;
            font-weight: 600;
        }

        /* Calendar icon styling */
        .date-input-container input[type="date"]::-webkit-calendar-picker-indicator,
        .month-input-container input[type="month"]::-webkit-calendar-picker-indicator {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            border-radius: 6px;
            padding: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
            filter: drop-shadow(0 2px 4px rgba(63, 114, 175, 0.2));
        }

        .date-input-container input[type="date"]::-webkit-calendar-picker-indicator:hover,
        .month-input-container input[type="month"]::-webkit-calendar-picker-indicator:hover {
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(63, 114, 175, 0.3);
        }

        /* Additional visual enhancements */
        .date-input-container input[type="date"]:not(:placeholder-shown),
        .month-input-container input[type="month"]:not(:placeholder-shown) {
            border-color: #3F72AF;
            background: white;
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.1);
        }

        /* Loading state animation */
        .date-input-container.loading,
        .month-input-container.loading {
            position: relative;
            overflow: hidden;
        }

        .date-input-container.loading::after,
        .month-input-container.loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(63, 114, 175, 0.1), transparent);
            animation: shimmer 1.5s infinite;
        }

        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        /* Success state styling */
        .date-input-container.success input[type="date"],
        .month-input-container.success input[type="month"] {
            border-color: #16C79A;
            background: linear-gradient(135deg, rgba(22, 199, 154, 0.05) 0%, #FFFFFF 100%);
        }

        .date-input-container.success::before,
        .month-input-container.success::before {
            background: linear-gradient(135deg, rgba(22, 199, 154, 0.1) 0%, rgba(22, 199, 154, 0.02) 100%);
            opacity: 1;
        }

        /* Error state styling */
        .date-input-container.error input[type="date"],
        .month-input-container.error input[type="month"] {
            border-color: #FF6B6B;
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.05) 0%, #FFFFFF 100%);
        }

        .date-input-container.error::before,
        .month-input-container.error::before {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.1) 0%, rgba(255, 107, 107, 0.02) 100%);
            opacity: 1;
        }

        /* Add subtle animation to the input containers */
        .date-input-container,
        .month-input-container {
            animation: fadeInUp 0.5s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Add a subtle glow effect when focused */
        .date-input-container:focus-within,
        .month-input-container:focus-within {
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1), 0 8px 25px rgba(63, 114, 175, 0.15);
        }

        /* Add a subtle border animation */
        .date-input-container input[type="date"],
        .month-input-container input[type="month"] {
            position: relative;
        }

        .date-input-container input[type="date"]::after,
        .month-input-container input[type="month"]::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            transition: width 0.3s ease;
        }

        .date-input-container input[type="date"]:focus::after,
        .month-input-container input[type="month"]:focus::after {
            width: 100%;
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
            min-width: 1000px;
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

        .status-on-leave {
            background: linear-gradient(135deg, rgba(156, 39, 176, 0.2), rgba(233, 30, 99, 0.2));
            color: #9C27B0;
            box-shadow: 0 2px 4px rgba(156, 39, 176, 0.3);
            font-weight: bold;
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
        /* Enhanced Modal Styling */
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
            display: flex !important; 
            align-items: center; 
            justify-content: center; 
        }
        
        .modal .modal-content { 
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.1);
            border: none;
            border-radius: 20px;
            overflow: hidden;
            max-width: 700px;
            width: 90%;
            max-height: 90vh;
            animation: slideInUp 0.4s ease;
            position: relative;
        }
        
        .modal .modal-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3F72AF, #112D4E, #16C79A);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            padding: 25px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        
        .modal-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: shimmer 3s infinite;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            z-index: 1;
        }
        
        .modal-header h2 i {
            font-size: 24px;
            color: #DBE2EF;
        }
        
        .modal .close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }
        
        .modal .close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
        .modal-body {
            padding: 30px;
            line-height: 1.8;
            color: #2D3748;
            background: white;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .modal-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: #3F72AF;
            border-radius: 3px;
        }
        
        .modal-body::-webkit-scrollbar-thumb:hover {
            background: #112D4E;
        }
        
        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #E2E8F0;
            display: flex;
            justify-content: flex-end;
            background: #F8F9FA;
        }
        
        /* Enhanced modal content styling */
        .modal-body > div {
            margin-bottom: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #F0F4F8;
            display: flex;
            align-items: center;
            transition: all 0.2s ease;
        }
        
        .modal-body > div:hover {
            background: rgba(63, 114, 175, 0.05);
            margin: 0 -30px;
            padding: 12px 30px;
            border-radius: 8px;
        }
        
        .modal-body > div:last-child {
            border-bottom: none;
        }
        
        .modal-body strong {
            color: #112D4E;
            font-weight: 600;
            min-width: 140px;
            display: inline-block;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .modal-body hr {
            border: none;
            height: 2px;
            background: linear-gradient(90deg, transparent, #3F72AF, transparent);
            margin: 20px 0;
            border-radius: 1px;
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
        
        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(30deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(30deg); }
        }
        
        /* Responsive modal */
        @media (max-width: 768px) {
            .modal .modal-content {
                width: 95%;
                margin: 20px;
            }
            
            .modal-header {
                padding: 20px 25px;
            }
            
            .modal-header h2 {
                font-size: 18px;
            }
            
            .modal-body {
                padding: 25px;
            }
            
            .modal-footer {
                padding: 15px 25px;
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
    </style>
</head>
<body>
    <div class="sidebar">
    <img src="LOGO/newLogo_transparent.png" class="logo" style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding:-250px; padding-bottom: 20px;">

        <div class="menu">
            <a href="AdminHome.php" class="menu-item">
                <i class="fas fa-th-large"></i> <span>Dashboard</span>
            </a>
            <a href="AdminEmployees.php" class="menu-item">
                <i class="fas fa-users"></i> <span>Employees</span>
            </a>
            <a href="AdminAttendance.php" class="menu-item active">
                <i class="fas fa-calendar-check"></i> <span>Attendance</span>
            </a>
            <a href="AdminPayroll.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i> <span>Payroll</span>
            </a>
            <a href="AdminHistory.php" class="menu-item">
                <i class="fas fa-history"></i> <span>History</span>
            </a>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </a>
    </div>

    <div class="main-content">
        <div class="header">
            <h1>Attendance Today</h1>
            <div class="header-actions"></div>
        </div>

        <!-- Enhanced View Details Modal -->
        <div id="viewAttendanceModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-user-clock"></i> Employee Attendance Details</h2>
                    <button class="close" onclick="closeViewAttendanceModal()" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body" id="viewAttendanceBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeViewAttendanceModal()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon present">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="present-today-value"><?php echo $present_today; ?></div>
                    <div class="summary-label">
                        <?php echo $is_month_filter ? 'Total Present' : 'Present Today'; ?>
                        <?php if (!empty($within_days_text)): ?>
                            <div style="color:#6c757d; font-size:12px; font-weight:500;">(<?php echo htmlspecialchars($within_days_text); ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon rate">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="attendance-rate-value"><?php echo $attendance_rate; ?>%</div>
                    <div class="summary-label">
                        Attendance Rate
                        <?php if (!empty($within_days_text)): ?>
                            <div style="color:#6c757d; font-size:12px; font-weight:500;">(<?php echo htmlspecialchars($within_days_text); ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon absent">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="absent-today-value"><?php echo $absent_today; ?></div>
                    <div class="summary-label">
                        <?php echo $is_month_filter ? 'Total Absent' : 'Absent Today'; ?>
                        <?php if (!empty($within_days_text)): ?>
                            <div style="color:#6c757d; font-size:12px; font-weight:500;">(<?php echo htmlspecialchars($within_days_text); ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon late">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="late-today-value"><?php echo $late_today; ?></div>
                    <div class="summary-label">Late Today</div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon early">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="early-today-value"><?php echo $early_today; ?></div>
                    <div class="summary-label">Early Today</div>
                </div>
            </div>
        </div>

       <!-- Replace the filter container with this -->
<div class="filter-container card">
    <form method="GET" action="AdminAttendance.php" id="filterForm">
        <div class="filter-header">
            <h2>
                <i class="fas fa-filter"></i> Today Filters
                <span class="filter-mode-indicator" id="mode-indicator">Today</span>
            </h2>
        </div>

        <div class="filter-controls">
            <div class="search-box" style="max-width: 420px;">
                <input type="text" name="search" placeholder="Search by employee name, ID, or department..."
                       value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                <i class="fas fa-search"></i>
            </div>

            <div class="filter-options">
                <div class="filter-option">
                    <label for="department">Department:</label>
                    <select id="department" name="department" class="form-control">
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
                    <select id="attendance_type" name="attendance_type" class="form-control">
                        <option value="">All Types</option>
                        <option value="present" <?php echo (isset($_GET['attendance_type']) && $_GET['attendance_type'] === 'present') ? 'selected' : ''; ?>>Present</option>
                        <option value="absent" <?php echo (isset($_GET['attendance_type']) && $_GET['attendance_type'] === 'absent') ? 'selected' : ''; ?>>Absent</option>
                    </select>
                </div>
                <div class="filter-option">
                    <label for="status">Status:</label>
                    <select id="status" name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="late" <?php echo (isset($_GET['status']) && $_GET['status'] === 'late') ? 'selected' : ''; ?>>Late</option>
                        <option value="early" <?php echo (isset($_GET['status']) && $_GET['status'] === 'early') ? 'selected' : ''; ?>>Early</option>
                        <option value="halfday" <?php echo (isset($_GET['status']) && $_GET['status'] === 'halfday') ? 'selected' : ''; ?>>Half Day</option>
                        <option value="on_leave" <?php echo (isset($_GET['status']) && $_GET['status'] === 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                    </select>
                </div>
                <div class="filter-option">
                    <label for="shift">Shift:</label>
                    <select id="shift" name="shift" class="form-control">
                        <option value="">All Shifts</option>
                        <option value="08:00-17:00" <?php echo (isset($_GET['shift']) && $_GET['shift'] === '08:00-17:00') ? 'selected' : ''; ?>>8:00 AM - 5:00 PM</option>
                        <option value="08:30-17:30" <?php echo (isset($_GET['shift']) && $_GET['shift'] === '08:30-17:30') ? 'selected' : ''; ?>>8:30 AM - 5:30 PM</option>
                        <option value="09:00-18:00" <?php echo (isset($_GET['shift']) && $_GET['shift'] === '09:00-18:00') ? 'selected' : ''; ?>>9:00 AM - 6:00 PM</option>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                    <i class="fas fa-undo"></i> Reset
                </button>
            </div>
        </div>
    </form>
</div>

        <!-- Results Container -->
        <div class="results-container card">
            <div class="results-header">
                <h2>Attendance Records</h2>
                <div class="results-actions">
                    
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Shift</th>
                            <th>Date</th>
                            <th>Duration</th>
                            <th>Attendance Type</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="attendance-results">
                        <!-- Results will be loaded here by JavaScript -->
                    </tbody>
                </table>
            </div>

            <?php $base_params = $_GET; ?>
            <?php if (isset($total_pages) && $total_pages > 1): ?>
            <div class="pagination" style="display:flex; gap:8px; justify-content:center; align-items:center; margin-top: 25px;">
                <?php if ($current_page > 1): ?>
                    <a class="btn btn-secondary" href="<?php echo '?' . http_build_query(array_merge($base_params, ['page' => 1, 'per_page' => $per_page])); ?>"><i class="fas fa-angle-double-left"></i></a>
                    <a class="btn btn-secondary" href="<?php echo '?' . http_build_query(array_merge($base_params, ['page' => $current_page - 1, 'per_page' => $per_page])); ?>"><i class="fas fa-angle-left"></i></a>
                <?php endif; ?>

                <?php 
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <a class="btn <?php echo $i == $current_page ? 'btn-primary' : 'btn-secondary'; ?>" href="<?php echo '?' . http_build_query(array_merge($base_params, ['page' => $i, 'per_page' => $per_page])); ?>"><?php echo $i; ?></a>
                <?php endfor; ?>

                <?php if ($current_page < $total_pages): ?>
                    <a class="btn btn-secondary" href="<?php echo '?' . http_build_query(array_merge($base_params, ['page' => $current_page + 1, 'per_page' => $per_page])); ?>"><i class="fas fa-angle-right"></i></a>
                    <a class="btn btn-secondary" href="<?php echo '?' . http_build_query(array_merge($base_params, ['page' => $total_pages, 'per_page' => $per_page])); ?>"><i class="fas fa-angle-double-right"></i></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
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
        
        // ---- Element References ----
        const resultsBody = document.getElementById('attendance-results');
        const searchInput = document.querySelector('#filterForm input[name="search"]');
        const filterForm = document.getElementById('filterForm'); // Get the filter form
        const dateInputContainer = null;
        const monthInputContainer = null;
        const dateInput = null;
        const monthInput = null;
        const departmentSelect = document.getElementById('department'); // Corrected ID
        const attendanceTypeSelect = document.getElementById('attendance_type'); // New filter
        const statusSelect = document.getElementById('status'); // Corrected ID

        let currentFilterMode = 'today';
        let searchTimeout;

        // ---- Filter Mode Switching ----
        function setFilterMode(mode) {
            currentFilterMode = 'today';
            const modeIndicator = document.getElementById('mode-indicator');
            if (modeIndicator) modeIndicator.textContent = 'Today';
        }

        // ---- Data Rendering ----
        function renderAttendanceTable(records) {
            if(!resultsBody) return; // Exit if table body doesn't exist
            resultsBody.innerHTML = ''; // Clear previous results

            if (!records || records.length === 0) {
                const colSpan = 10; // Number of columns in the admin table (including new columns)
                resultsBody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align: center; padding: 20px;">No attendance records found for the selected filters.</td></tr>`;
                return;
            }

            records.forEach(record => {
                const row = document.createElement('tr');
                const dateFormatted = record.attendance_date ? new Date(record.attendance_date).toLocaleDateString('en-US', {month:'short', day:'2-digit', year:'numeric'}) : '-';
                // Duration logic based on time-in and time-out status
                let duration = '';
                if (!record.time_in) {
                    duration = 'No Time-in';
                } else if (record.time_in && !record.time_out) {
                    duration = 'In Progress';
                } else if (record.time_in && record.time_out) {
                    // Calculate total hours worked (excluding lunch windows by shift)
                    const toDate = (t) => new Date('1970-01-01T' + t);
                    let start = toDate(record.time_in);
                    const end = toDate(record.time_out);
                    
                    // Get shift start time and don't count early arrival before shift starts
                    const shift = record.Shift || '';
                    const shiftTimes = {
                        '08:00-17:00': '08:00:00',
                        '08:30-17:30': '08:30:00',
                        '09:00-18:00': '09:00:00'
                    };
                    
                    if (shiftTimes[shift]) {
                        const shiftStart = toDate(shiftTimes[shift]);
                        // If employee arrived before shift start, use shift start time instead
                        if (start < shiftStart) {
                            start = shiftStart;
                        }
                    }
                    
                    let workedMs = Math.max(0, end - start);
                    
                    // Subtract lunch break time based on shift
                    const lunchByShift = {
                        '08:00-17:00': ['12:00', '13:00'],
                        '08:30-17:30': ['12:30', '13:30'],
                        '09:00-18:00': ['13:00', '14:00']
                    };
                    
                    if (lunchByShift[shift]) {
                        const [ls, le] = lunchByShift[shift];
                        const lunchStart = new Date('1970-01-01T' + ls + ':00');
                        const lunchEnd = new Date('1970-01-01T' + le + ':00');
                        const overlap = Math.max(0, Math.min(end, lunchEnd) - Math.max(start, lunchStart));
                        workedMs -= overlap;
                    }
                    
                    const hours = Math.floor(workedMs / 3600000);
                    const minutes = Math.floor((workedMs % 3600000) / 60000);
                    duration = `${hours}h ${String(minutes).padStart(2,'0')}m`;
                } else {
                    duration = 'No Record';
                }
                const source = record.notes && record.notes.includes('Manual') ? 
                    '<i class="fas fa-edit" title="Manual Entry"></i>' :
                    '<i class="fas fa-fingerprint" title="Biometric Device"></i>';

                row.classList.add('clickable-row');
                row.setAttribute('data-employee_id', record.EmployeeID || '');
                row.setAttribute('data-employee_name', record.EmployeeName || '');
                row.setAttribute('data-department', record.Department || '');
                row.setAttribute('data-shift', record.Shift || '');
                row.setAttribute('data-date', record.attendance_date || '');
                row.setAttribute('data-time_in', record.time_in || '');
                row.setAttribute('data-time_out', record.time_out || '');
                row.setAttribute('data-am_in', record.time_in_morning || '');
                row.setAttribute('data-am_out', record.time_out_morning || '');
                row.setAttribute('data-pm_in', record.time_in_afternoon || '');
                row.setAttribute('data-pm_out', record.time_out_afternoon || '');
                row.setAttribute('data-late', record.late_minutes || 0);
                row.setAttribute('data-early', record.early_out_minutes || 0);
                row.setAttribute('data-ot', record.overtime_hours || 0);
                row.setAttribute('data-attendance_type', record.attendance_type || '');
                row.setAttribute('data-status', record.status || '');
                row.setAttribute('data-notes', record.notes || '');

                const displayShift = (function fmtShift(s){
                    if(!s) return 'N/A';
                    const m = s.match(/^(\d{2}:\d{2})-(\d{2}:\d{2})$/);
                    const toAmPm = (hhmm) => {
                        const [hh, mm] = hhmm.split(':').map(Number);
                        const h12 = ((hh + 11) % 12) + 1;
                        const suffix = hh < 12 ? 'AM' : 'PM';
                        return `${h12}:${String(mm).padStart(2,'0')} ${suffix}`;
                    };
                    if(m){
                        return `${toAmPm(m[1])} - ${toAmPm(m[2])}`;
                    }
                    return s; // fallback
                })(record.Shift || '');

                row.innerHTML = `
                    <td>${record.EmployeeID || 'N/A'}</td>
                    <td>${record.EmployeeName || 'N/A'}</td>
                    <td>${record.Department || 'N/A'}</td>
                    <td>${displayShift}</td>
                    <td>${dateFormatted}</td>
                    <td>${duration}</td>
                    <td>${(record.attendance_type || '').charAt(0).toUpperCase() + (record.attendance_type || '').slice(1)}</td>
                    <td><span class="status-badge ${record.is_on_leave == 1 ? 'status-on-leave' : 'status-' + (record.status || '').toLowerCase().replace(/\s+/g, '-')}">${record.is_on_leave == 1 ? 'ON-LEAVE' : (record.status ? record.status.charAt(0).toUpperCase() + record.status.slice(1) : 'N/A')}</span></td>
                    <td style="text-align:center;">${source}</td>
                    <td><button type="button" class="btn btn-secondary" onclick="event.stopPropagation(); viewRowDetails(this)"><i class="fas fa-eye"></i> View</button></td>
                `;
                resultsBody.appendChild(row);
            });
        }

        // Function to submit the filter form
        function applyFilters() {
            if (filterForm) {
                filterForm.submit();
            }
        }

        // Also sanitize on direct form submit (e.g., pressing Enter)
        if (filterForm) {
            filterForm.addEventListener('submit', function() {
            });
        }

        // Function to reset filters
        function resetFilters() {
            window.location.href = 'AdminAttendance.php'; // Redirect to clear all GET parameters
        }

        // ---- Event Listeners ----

        // No date switching in today-only view

        // Search Input (debounce submit of the main filter form)
        if(searchInput) {
            searchInput.addEventListener('keyup', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (filterForm) filterForm.submit();
                }, 500);
            });
        }

        // Auto-submit when month input changes
        if(monthInput) {
            monthInput.addEventListener('change', function() {
                if (currentFilterMode === 'month' && this.value) {
                    // Add loading state
                    const container = this.closest('.month-input-container');
                    if (container) {
                        container.classList.add('loading');
                        setTimeout(() => {
                            container.classList.remove('loading');
                            container.classList.add('success');
                        }, 500);
                    }
                    if (filterForm) filterForm.submit();
                }
            });

            // Add focus/blur effects
            monthInput.addEventListener('focus', function() {
                const container = this.closest('.month-input-container');
                if (container) {
                    container.classList.remove('error');
                }
            });
        }

        // Auto-submit when date input changes
        if(dateInput) {
            dateInput.addEventListener('change', function() {
                if (currentFilterMode === 'date' && this.value) {
                    // Add loading state
                    const container = this.closest('.date-input-container');
                    if (container) {
                        container.classList.add('loading');
                        setTimeout(() => {
                            container.classList.remove('loading');
                            container.classList.add('success');
                        }, 500);
                    }
                    if (filterForm) filterForm.submit();
                }
            });

            // Add focus/blur effects
            dateInput.addEventListener('focus', function() {
                const container = this.closest('.date-input-container');
                if (container) {
                    container.classList.remove('error');
                }
            });
        }

        // ---- AJAX Filter Functions ----
        function fetchFilteredAttendance() {
            // Show loading state
            if (resultsBody) {
                resultsBody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #3F72AF;"></i><br><br>Loading attendance records...</td></tr>';
            }

            // Get filter values
            const formData = new FormData(filterForm);
            const params = new URLSearchParams(formData);

            // Fetch filtered data
            fetch('AdminAttendance.php?' + params.toString(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.text())
            .then(html => {
                // Parse the HTML response to extract the attendance records data
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const scriptTag = doc.querySelector('script');
                
                if (scriptTag) {
                    // Extract the PHP data from the script
                    const scriptContent = scriptTag.textContent;
                    const match = scriptContent.match(/const phpData = (\[.*?\]);/s);
                    
                    if (match && match[1]) {
                        try {
                            const records = JSON.parse(match[1]);
                            renderAttendanceTable(records);
                            updateSummaryCards(doc);
                        } catch (e) {
                            console.error('Error parsing attendance data:', e);
                            if (resultsBody) {
                                resultsBody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding: 20px; color: #ff4757;">Error loading data. Please try again.</td></tr>';
                            }
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching attendance data:', error);
                if (resultsBody) {
                    resultsBody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding: 20px; color: #ff4757;">Error loading data. Please try again.</td></tr>';
                }
            });
        }

        function updateSummaryCards(doc) {
            // Update summary card values from the fetched HTML
            const presentValue = doc.querySelector('#present-today-value');
            const rateValue = doc.querySelector('#attendance-rate-value');
            const absentValue = doc.querySelector('#absent-today-value');
            const lateValue = doc.querySelector('#late-today-value');
            const earlyValue = doc.querySelector('#early-today-value');

            if (presentValue) {
                const localPresent = document.getElementById('present-today-value');
                if (localPresent) localPresent.textContent = presentValue.textContent;
            }
            if (rateValue) {
                const localRate = document.getElementById('attendance-rate-value');
                if (localRate) localRate.textContent = rateValue.textContent;
            }
            if (absentValue) {
                const localAbsent = document.getElementById('absent-today-value');
                if (localAbsent) localAbsent.textContent = absentValue.textContent;
            }
            if (lateValue) {
                const localLate = document.getElementById('late-today-value');
                if (localLate) localLate.textContent = lateValue.textContent;
            }
            if (earlyValue) {
                const localEarly = document.getElementById('early-today-value');
                if (localEarly) localEarly.textContent = earlyValue.textContent;
            }
        }

        // Add event listeners for AJAX filtering
        if (departmentSelect) {
            departmentSelect.addEventListener('change', function() {
                fetchFilteredAttendance();
            });
        }

        if (attendanceTypeSelect) {
            attendanceTypeSelect.addEventListener('change', function() {
                fetchFilteredAttendance();
            });
        }

        if (statusSelect) {
            statusSelect.addEventListener('change', function() {
                fetchFilteredAttendance();
            });
        }

        const shiftSelect = document.getElementById('shift');
        if (shiftSelect) {
            shiftSelect.addEventListener('change', function() {
                fetchFilteredAttendance();
            });
        }

        // Update search to use AJAX as well
        if(searchInput) {
            searchInput.addEventListener('keyup', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    fetchFilteredAttendance();
                }, 500);
            });
        }

        // View details helpers
        function viewRowDetails(btn) {
            try {
                const tr = btn.closest('tr');
                if (!tr) return;
                openViewAttendanceModal(tr);
            } catch (e) {
                console.error('View details failed', e);
            }
        }

        function openViewAttendanceModal(rowEl) {
            const body = document.getElementById('viewAttendanceBody');
            const fmt = (t)=> t ? new Date('1970-01-01T' + t).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '-';
            const fmtShift = (s)=>{
                if(!s) return '-';
                const m = s.match(/^(\d{2}:\d{2})-(\d{2}:\d{2})$/);
                const toAmPm = (hhmm) => {
                    const [hh, mm] = hhmm.split(':').map(Number);
                    const h12 = ((hh + 11) % 12) + 1;
                    const suffix = hh < 12 ? 'AM' : 'PM';
                    return `${h12}:${String(mm).padStart(2,'0')} ${suffix}`;
                };
                if(m){
                    return `${toAmPm(m[1])} - ${toAmPm(m[2])}`;
                }
                return s;
            };
            const name = rowEl.getAttribute('data-employee_name') || '-';
            const id = rowEl.getAttribute('data-employee_id') || '-';
            const dept = rowEl.getAttribute('data-department') || '-';
            const shift = fmtShift(rowEl.getAttribute('data-shift'));
            const date = rowEl.getAttribute('data-date') || '-';
            const amIn = fmt(rowEl.getAttribute('data-am_in'));
            const amOut = fmt(rowEl.getAttribute('data-am_out'));
            const pmIn = fmt(rowEl.getAttribute('data-pm_in'));
            const pmOut = fmt(rowEl.getAttribute('data-pm_out'));
            const status = rowEl.getAttribute('data-status') || '-';
            const type = rowEl.getAttribute('data-attendance_type') || '-';
            const late = rowEl.getAttribute('data-late') || '0';
            const early = rowEl.getAttribute('data-early') || '0';
            const ot = rowEl.getAttribute('data-ot') || '0';
            const notes = rowEl.getAttribute('data-notes') || '-';
            const rawAmIn = rowEl.getAttribute('data-am_in');
            const rawAmOut = rowEl.getAttribute('data-am_out');
            const rawPmIn = rowEl.getAttribute('data-pm_in');
            const rawPmOut = rowEl.getAttribute('data-pm_out');
            const isHalfDay = (status === 'half_day' || status === 'halfday');
            const hasAmPairOnly = rawAmIn && rawAmOut && !rawPmIn && !rawPmOut;
            const hasPmPairOnly = rawPmIn && rawPmOut && !rawAmIn && !rawAmOut;
            // Create beautiful modal content with icons and better formatting
            const statusIcon = type === 'present' ? 'fas fa-check-circle' : 'fas fa-times-circle';
            const statusColor = type === 'present' ? '#16C79A' : '#FF6B6B';
            
            body.innerHTML = `
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 25px; padding: 20px; background: linear-gradient(135deg, rgba(63, 114, 175, 0.1) 0%, rgba(17, 45, 78, 0.05) 100%); border-radius: 12px; border-left: 4px solid #3F72AF;">
                    <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px;">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; color: #112D4E; font-size: 20px; font-weight: 600;">${name}</h3>
                        <p style="margin: 5px 0 0 0; color: #6c757d; font-size: 14px;">Employee ID: ${id}</p>
                        <p style="margin: 5px 0 0 0; color: #6c757d; font-size: 14px;">${dept} Department</p>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
                    <div style="padding: 15px; background: #F8F9FA; border-radius: 10px; border-left: 3px solid #3F72AF;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <i class="fas fa-calendar-alt" style="color: #3F72AF;"></i>
                            <strong>Date:</strong>
                        </div>
                        <div style="color: #2D3748; font-size: 16px;">${new Date(date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</div>
                    </div>
                    <div style="padding: 15px; background: #F8F9FA; border-radius: 10px; border-left: 3px solid #16C79A;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <i class="fas fa-clock" style="color: #16C79A;"></i>
                            <strong>Shift:</strong>
                        </div>
                        <div style="color: #2D3748; font-size: 16px;">${shift}</div>
                    </div>
                </div>
                
                <div style="margin-bottom: 25px;">
                    <h4 style="color: #112D4E; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-clock" style="color: #3F72AF;"></i>
                        Time Records
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div style="padding: 15px; background: ${amIn !== '-' ? '#E8F5E8' : '#FFF3CD'}; border-radius: 10px; border-left: 3px solid ${amIn !== '-' ? '#16C79A' : '#FFC75F'};">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <i class="fas fa-sun" style="color: ${amIn !== '-' ? '#16C79A' : '#FFC75F'}; font-size: 16px;"></i>
                                <strong>Morning In:</strong>
                            </div>
                            <div style="color: #2D3748; font-size: 16px; font-weight: 600;">${amIn}</div>
                        </div>
                        <div style="padding: 15px; background: ${amOut !== '-' ? '#E8F5E8' : '#FFF3CD'}; border-radius: 10px; border-left: 3px solid ${amOut !== '-' ? '#16C79A' : '#FFC75F'};">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <i class="fas fa-utensils" style="color: ${amOut !== '-' ? '#16C79A' : '#FFC75F'}; font-size: 16px;"></i>
                                <strong>Lunch Start:</strong>
                            </div>
                            <div style="color: #2D3748; font-size: 16px; font-weight: 600;">${amOut}</div>
                        </div>
                        <div style="padding: 15px; background: ${pmIn !== '-' ? '#E8F5E8' : '#FFF3CD'}; border-radius: 10px; border-left: 3px solid ${pmIn !== '-' ? '#16C79A' : '#FFC75F'};">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <i class="fas fa-coffee" style="color: ${pmIn !== '-' ? '#16C79A' : '#FFC75F'}; font-size: 16px;"></i>
                                <strong>Lunch End:</strong>
                            </div>
                            <div style="color: #2D3748; font-size: 16px; font-weight: 600;">${pmIn}</div>
                        </div>
                        <div style="padding: 15px; background: ${pmOut !== '-' ? '#E8F5E8' : '#FFF3CD'}; border-radius: 10px; border-left: 3px solid ${pmOut !== '-' ? '#16C79A' : '#FFC75F'};">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <i class="fas fa-moon" style="color: ${pmOut !== '-' ? '#16C79A' : '#FFC75F'}; font-size: 16px;"></i>
                                <strong>Evening Out:</strong>
                            </div>
                            <div style="color: #2D3748; font-size: 16px; font-weight: 600;">${pmOut}</div>
                        </div>
                    </div>
                </div>
                
                <div style="margin-bottom: 25px;">
                    <h4 style="color: #112D4E; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-chart-line" style="color: #3F72AF;"></i>
                        Attendance Summary
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 20px;">
                        <div style="padding: 15px; background: ${type === 'present' ? '#E8F5E8' : '#FFE8E8'}; border-radius: 10px; border-left: 3px solid ${type === 'present' ? '#16C79A' : '#FF6B6B'};">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <i class="${statusIcon}" style="color: ${statusColor}; font-size: 16px;"></i>
                                <strong>Status:</strong>
                            </div>
                            <div style="color: #2D3748; font-size: 16px; font-weight: 600;">${type.charAt(0).toUpperCase() + type.slice(1)}</div>
                        </div>
                        <div style="padding: 15px; background: #FFF3CD; border-radius: 10px; border-left: 3px solid #FFC75F;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <i class="fas fa-exclamation-triangle" style="color: #FFC75F; font-size: 16px;"></i>
                                <strong>Late Minutes:</strong>
                            </div>
                            <div style="color: #2D3748; font-size: 16px; font-weight: 600;">${(isHalfDay && hasPmPairOnly) ? '-' : (isHalfDay ? '-' : late + ' min')}</div>
                        </div>
                        <div style="padding: 15px; background: #E3F2FD; border-radius: 10px; border-left: 3px solid #2196F3;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <i class="fas fa-clock" style="color: #2196F3; font-size: 16px;"></i>
                                <strong>Early Out:</strong>
                            </div>
                            <div style="color: #2D3748; font-size: 16px; font-weight: 600;">${(isHalfDay && hasAmPairOnly) ? '-' : (isHalfDay ? '-' : early + ' min')}</div>
                        </div>
                        <div style="padding: 15px; background: #E8F5E8; border-radius: 10px; border-left: 3px solid #16C79A;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <i class="fas fa-plus-circle" style="color: #16C79A; font-size: 16px;"></i>
                                <strong>Overtime:</strong>
                            </div>
                            <div style="color: #2D3748; font-size: 16px; font-weight: 600;">${parseFloat(ot).toFixed(2)} hrs</div>
                        </div>
                    </div>
                </div>
                
                ${notes !== '-' ? `
                <div style="padding: 15px; background: #F8F9FA; border-radius: 10px; border-left: 4px solid #3F72AF;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <i class="fas fa-sticky-note" style="color: #3F72AF;"></i>
                        <strong>Notes:</strong>
                    </div>
                    <div style="color: #2D3748; font-style: italic;">${notes}</div>
                </div>
                ` : ''}
            `;
            const modal = document.getElementById('viewAttendanceModal');
            modal.classList.add('show');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeViewAttendanceModal() {
            const modal = document.getElementById('viewAttendanceModal');
            modal.classList.remove('show');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        // Close on overlay click
        document.getElementById('viewAttendanceModal').addEventListener('click', function(e){
            if (e.target === this) closeViewAttendanceModal();
        });

        // Initialize with PHP data on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Render the PHP data that was already fetched by the server
            const phpData = <?php echo json_encode($attendance_records); ?>;
            renderAttendanceTable(phpData);

            // Set initial filter mode based on URL parameters from PHP
            const urlParams = new URLSearchParams(window.location.search);
            let initialMode = 'today'; // Default

            if (urlParams.has('date') && urlParams.get('date') !== '') {
                initialMode = 'date';
            } else if (urlParams.has('month') && urlParams.get('month') !== '') {
                initialMode = 'month';
            }
            // No need to set input values here, PHP already does it in the HTML

            setFilterMode(initialMode); // This will correctly show/hide the date/month inputs
        });

        // Placeholder functions for action buttons (if they are not handled by forms)
        function viewAttendance(id) {
            console.log('View attendance record with ID:', id);
            // Implement modal or redirect for viewing details
        }

        function editAttendance(id) {
            console.log('Edit attendance record with ID:', id);
            // Implement modal or redirect for editing
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
