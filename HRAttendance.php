<?php
// Restore required bootstrap initialization to avoid undefined vars and parse errors
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

// Ensure DB connection and prerequisites
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("Connection Failed: " . $conn->connect_error); }
$conn->query("SET time_zone = '+08:00'");

// Attendance calculations utility
require_once __DIR__ . '/attendance_calculations.php';

// Function to format shift time to AM/PM format
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

// Helper function to convert 24-hour time to 12-hour format
function formatTime12Hour($time) {
    if (empty($time)) return '-';
    
    try {
        // Handle various time formats
        $time = trim($time);
        
        // If already in 12-hour format, return as is
        if (stripos($time, 'am') !== false || stripos($time, 'pm') !== false) {
            return $time;
        }
        
        // Convert 24-hour format to 12-hour
        $timeObj = DateTime::createFromFormat('H:i', $time);
        if ($timeObj === false) {
            // Try with seconds
            $timeObj = DateTime::createFromFormat('H:i:s', $time);
        }
        
        if ($timeObj !== false) {
            return $timeObj->format('g:i A');
        }
        
        return $time; // Return original if conversion fails
    } catch (Exception $e) {
        return $time; // Return original if any error occurs
    }
}

// AJAX flag used later
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1';

// --- Filter Logic --- 
$department_filter = isset($_GET['department']) ? $conn->real_escape_string($_GET['department']) : '';
$shift_filter = isset($_GET['shift']) ? $conn->real_escape_string($_GET['shift']) : '';
$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
$type_filter = isset($_GET['attendance_type']) ? $conn->real_escape_string($_GET['attendance_type']) : '';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$today = date('Y-m-d');

// Debug logging for AJAX requests
if ($is_ajax) {
    error_log("AJAX Request - Search term: " . $search_term . ", Department: " . $department_filter . ", Shift: " . $shift_filter . ", Status: " . $status_filter);
}

// Always show today's records
$date_condition = "DATE(a.attendance_date) = ?";

// Add department condition if set
$department_condition = "";
if (!empty($department_filter)) {
    $department_condition = " AND e.Department = ?";
}

// Add shift condition if set
$shift_condition = "";
if (!empty($shift_filter)) {
    $shift_condition = " AND e.Shift = ?";
}

// Status filter will be applied after calculations, not at database level
$status_condition = "";

// Add attendance type condition if set (DB enum: present, absent)
$type_condition = "";
if (!empty($type_filter)) {
    if ($type_filter === 'present') {
        $type_condition = " AND a.attendance_type = 'present'";
    } elseif ($type_filter === 'absent') {
        // Include rows with explicit absent OR no attendance row at all (LEFT JOIN => a.id IS NULL)
        $type_condition = " AND (a.attendance_type = 'absent' OR a.id IS NULL)";
    }
}

// No time-out filter (only records without time_out)
$no_timeout_filter = isset($_GET['no_timeout']) && $_GET['no_timeout'] == '1';
$no_timeout_condition = $no_timeout_filter ? " AND a.id IS NOT NULL AND a.attendance_type = 'present' AND a.time_out IS NULL" : "";

// Get attendance data for today with absent records
$main_params = [];
$main_types = "";

$query = "SELECT 
            COALESCE(a.id, 0) as id,
            e.EmployeeID,
            COALESCE(a.attendance_date, ?) as attendance_date,
            COALESCE(a.attendance_type, 'absent') as attendance_type,
            COALESCE(a.status, 'no_record') as status,
            COALESCE(a.status, 'no_record') as db_status,
            COALESCE(a.time_in, NULL) as time_in,
            COALESCE(a.time_out, NULL) as time_out,
            COALESCE(a.time_in_morning, NULL) as time_in_morning,
            COALESCE(a.time_out_morning, NULL) as time_out_morning,
            COALESCE(a.time_in_afternoon, NULL) as time_in_afternoon,
            COALESCE(a.time_out_afternoon, NULL) as time_out_afternoon,
            COALESCE(a.notes, 'No attendance record') as notes,
            COALESCE(a.data_source, 'biometric') as data_source,
            COALESCE(a.overtime_hours, 0) as overtime_hours,
            COALESCE(a.is_overtime, 0) as is_overtime,
            COALESCE(a.total_hours, 0) as total_hours,
            COALESCE(a.late_minutes, 0) as late_minutes,
            COALESCE(a.early_out_minutes, 0) as early_out_minutes,
            COALESCE(a.is_on_leave, 0) as is_on_leave,
            e.EmployeeName,
            e.Department,
            e.Shift
          FROM empuser e
          LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND $date_condition
          WHERE e.Status = 'active'";

// Add search condition if provided
if (!empty($search_term)) {
    $query = str_replace("WHERE e.Status = 'active'", "WHERE e.Status = 'active' AND (e.EmployeeName LIKE ? OR e.EmployeeID LIKE ?)", $query);
}

// Add parameters
$main_params[] = $today; // For COALESCE
$main_types .= "s";
$main_params[] = $today; // For LEFT JOIN
$main_types .= "s";

// Add search parameters if provided
if (!empty($search_term)) {
    $search_param = "%$search_term%";
    $main_params[] = $search_param;
    $main_params[] = $search_param;
    $main_types .= "ss";
}

// Add additional conditions
$query .= $department_condition . $shift_condition . $status_condition . $type_condition . $no_timeout_condition;

// Add additional parameters
if (!empty($department_filter)) {
    $main_params[] = $department_filter;
    $main_types .= "s";
}
if (!empty($shift_filter)) {
    $main_params[] = $shift_filter;
    $main_types .= "s";
}
// No bound param for status filter because condition is inlined above
// No bound param for type filter since we inline constants above

$query .= " ORDER BY COALESCE(a.attendance_date, ?) DESC, COALESCE(a.time_in, '00:00:00') DESC";

// Add the date parameter for ORDER BY
$main_params[] = $today;
$main_types .= "s";

// Debug logging for AJAX requests
if ($is_ajax) {
    error_log("Final Query: " . $query);
    error_log("Main Params: " . print_r($main_params, true));
    error_log("Main Types: " . $main_types);
}

$stmt_attendance = $conn->prepare($query);
if (!$stmt_attendance) {
    error_log("Prepare failed: " . $conn->error);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
        exit;
    }
    die("Database error occurred");
}

if (!empty($main_params)) {
    $stmt_attendance->bind_param($main_types, ...$main_params);
}

if (!$stmt_attendance->execute()) {
    error_log("Execute failed: " . $stmt_attendance->error);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database query failed: ' . $stmt_attendance->error]);
        exit;
    }
    die("Database query failed");
}

$attendance_result = $stmt_attendance->get_result();

// Get all attendance records and apply accurate calculations
$attendance_records = [];
while ($row = $attendance_result->fetch_assoc()) {
    $attendance_records[] = $row;
}

// Apply accurate calculations to all attendance records
$attendance_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);

// Apply status filter based on database status (before calculations override it)
if (!empty($status_filter)) {
    $attendance_records = array_values(array_filter($attendance_records, function($record) use ($status_filter) {
        // Use original database status, not calculated status
        $db_status = $record['db_status'] ?? $record['status'] ?? null;
        switch ($status_filter) {
            case 'late':
                return $db_status === 'late';
            case 'early_in':
                return $db_status === 'early_in';
            case 'on_time':
                return $db_status === 'on_time';
            case 'halfday':
                return $db_status === 'halfday';
            case 'on_leave':
                return ($record['is_on_leave'] ?? 0) == 1;
            default:
                return true;
        }
    }));
    
    // Recalculate stats based on filtered records
    $stats['total_present'] = count(array_filter($attendance_records, function($record) {
        return ($record['attendance_type'] ?? '') === 'present';
    }));
    $stats['still_present'] = count(array_filter($attendance_records, function($record) {
        return ($record['attendance_type'] ?? '') === 'present' && empty($record['time_out']);
    }));
    $stats['late_arrivals'] = count(array_filter($attendance_records, function($record) {
        return ($record['status'] ?? '') === 'late';
    }));
    $stats['early_in'] = count(array_filter($attendance_records, function($record) {
        return ($record['status'] ?? '') === 'early_in';
    }));
    $stats['total_absent'] = count(array_filter($attendance_records, function($record) {
        return ($record['attendance_type'] ?? '') === 'absent' || (isset($record['id']) && (int)$record['id'] === 0);
    }));
}

// Get departments for filter dropdowns
$departments_query = "SELECT DISTINCT Department FROM empuser WHERE Department IS NOT NULL AND Department != '' ORDER BY Department";
$departments_result_obj = $conn->query($departments_query);
$departments = [];
if ($departments_result_obj) {
while ($dept_row = $departments_result_obj->fetch_assoc()) {
    $departments[] = $dept_row['Department'];
    }
} else {
    // Handle query error
    error_log("Department query failed: " . $conn->error);
    $departments = [];
}

// Stats for today - include all employees
$stats_params = [];
$stats_types = "";

$stats_query = "SELECT 
                COUNT(DISTINCT CASE WHEN a.attendance_type = 'present' THEN e.EmployeeID END) as total_present,
                COUNT(DISTINCT CASE WHEN a.attendance_type = 'present' AND a.time_out IS NULL THEN e.EmployeeID END) as still_present,
                COUNT(DISTINCT CASE WHEN a.status = 'late' THEN e.EmployeeID END) as late_arrivals,
                COUNT(DISTINCT CASE WHEN a.status = 'early_in' THEN e.EmployeeID END) as early_in,
                COUNT(DISTINCT CASE WHEN a.attendance_type = 'absent' OR a.id IS NULL THEN e.EmployeeID END) as total_absent
                FROM empuser e
                LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND $date_condition
                WHERE e.Status = 'active'";

// Add search condition to stats query if provided
if (!empty($search_term)) {
    $stats_query = str_replace("WHERE e.Status = 'active'", "WHERE e.Status = 'active' AND (e.EmployeeName LIKE ? OR e.EmployeeID LIKE ?)", $stats_query);
}

// Add date parameter for LEFT JOIN
$stats_params[] = $today;
$stats_types .= "s";

// Add search parameters to stats query if provided
if (!empty($search_term)) {
    $search_param = "%$search_term%";
    $stats_params[] = $search_param;
    $stats_params[] = $search_param;
    $stats_types .= "ss";
}

// Add additional conditions to stats query (excluding status filter which is applied after calculations)
$stats_query .= $department_condition . $shift_condition . $type_condition . $no_timeout_condition;

// Add additional parameters for stats query
if (!empty($department_filter)) {
    $stats_params[] = $department_filter;
    $stats_types .= "s";
}
if (!empty($shift_filter)) {
    $stats_params[] = $shift_filter;
    $stats_types .= "s";
}
// No bound param for status/type filters; conditions are inlined above

$stmt_stats = $conn->prepare($stats_query);
if (!$stmt_stats) {
    error_log("Stats prepare failed: " . $conn->error);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Stats prepare failed: ' . $conn->error]);
        exit;
    }
    $stats = ['total_present' => 0, 'still_present' => 0, 'late_arrivals' => 0, 'early_in' => 0, 'total_absent' => 0];
} else {
    if (!empty($stats_params)) {
        $stmt_stats->bind_param($stats_types, ...$stats_params);
    }
    
    if (!$stmt_stats->execute()) {
        error_log("Stats execute failed: " . $stmt_stats->error);
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Stats query failed: ' . $stmt_stats->error]);
            exit;
        }
        $stats = ['total_present' => 0, 'still_present' => 0, 'late_arrivals' => 0, 'early_in' => 0, 'total_absent' => 0];
    } else {
$stats_result = $stmt_stats->get_result();
$stats = $stats_result->fetch_assoc();
    }
}

// Get total employees company-wide (unfiltered) for attendance rate denominator
$total_emp_query = "SELECT COUNT(DISTINCT EmployeeID) as total_employees FROM empuser WHERE Status = 'active'";
$total_result = $conn->query($total_emp_query);
if ($total_result) {
$total_data = $total_result->fetch_assoc();
    $total_employees = (int)($total_data['total_employees'] ?? 0);
} else {
    error_log("Total employees query failed: " . $conn->error);
    $total_employees = 0;
}

// Daily calculations
$actual_absent = max(0, $total_employees - (int)($stats['total_present'] ?? 0));
$attendance_rate = $total_employees > 0 
    ? round((($stats['total_present'] ?? 0) / $total_employees) * 100)
    : 0;

// Get recent biometric activities (last 10 entries for real-time monitoring)
$recent_activities_query = "SELECT a.*, e.EmployeeName, e.Department 
                           FROM attendance a 
                           JOIN empuser e ON a.EmployeeID = e.EmployeeID 
                           WHERE DATE(a.attendance_date) = CURDATE()
                           ORDER BY 
                             CASE 
                               WHEN a.time_out IS NOT NULL THEN CONCAT(a.attendance_date, ' ', a.time_out)
                               ELSE CONCAT(a.attendance_date, ' ', a.time_in)
                             END DESC 
                           LIMIT 10";

$recent_activities_result = $conn->query($recent_activities_query);
$recent_activities = [];
if ($recent_activities_result) {
    while ($activity = $recent_activities_result->fetch_assoc()) {
        $recent_activities[] = $activity;
    }
}

// If AJAX, return JSON payload and exit
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'records' => $attendance_records,
        'search_term' => $search_term,
        'stats' => [
            'total_present' => $stats['total_present'] ?? 0,
            'still_present' => $stats['still_present'] ?? 0,
            'late_arrivals' => $stats['late_arrivals'] ?? 0,
            'early_in' => $stats['early_in'] ?? 0,
            'absent' => $actual_absent
        ],
        'recent_activities' => $recent_activities
    ]);
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HR Attendance</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
        :root {
            --primary-color: #112D4E;      /* Dark blue */
            --secondary-color: #3F72AF;    /* Medium blue */
            --bg-color: #F9F7F7;           /* Off white */
            --text-color: #FFFFFF;          /* Sidebar text/icons */
            --content-text: #2D3748;        /* Body text */
            --border-color: #DBE2EF;
            --sidebar-width: 320px;
            --sidebar-collapsed-width: 90px;
            --transition-speed: 0.3s;
        }

        html, body {
            height: 100%;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Poppins', Arial, sans-serif;
            background: var(--bg-color);
            color: var(--content-text);
            display: flex;
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

        
        /* Additional styles for biometric features */
        .biometric-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #e8f5e8;
            color: #2d5a2d;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            margin-left: 15px;
        }
        
        .pulse-dot {
            width: 8px;
            height: 8px;
            background: #27ae60;
            border-radius: 50%;
            animation: pulse 2s ease-in-out infinite alternate;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            100% { opacity: 0.3; }
        }
        
        .recent-activities-placeholder {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            color: #666;
        }

        .recent-activities-placeholder i {
            font-size: 24px;
            margin-bottom: 10px;
            color: #3F72AF;
            display: block;
        }
        
        .activity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .activity-employee {
            font-weight: 600;
            color: #333;
        }
        
        .activity-action {
            font-size: 0.9rem;
            color: #666;
        }
        
        .activity-time {
            font-size: 0.9rem;
            color: #888;
            font-weight: 500;
        }
        
        .status-present {
            background: #d4edda;
            color: #155724;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .status-late {
            background: #fff9c4;
            color: #F57F17;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
            word-break: keep-all;
            overflow-wrap: normal;
        }
        
        .status-early-in {
            background: #e8f5e9;
            color: #4CAF50;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
            word-break: keep-all;
            overflow-wrap: normal;
        }
        
        .status-on-time {
            background: linear-gradient(135deg, #fff3e0, #fff8e1);
            color: #FF8F00;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 1px 3px rgba(255, 193, 7, 0.3);
            white-space: nowrap;
            word-break: keep-all;
            overflow-wrap: normal;
        }
        
        .status-absent {
            background: #f8d7da;
            color: #721c24;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .status-no-record {
            background: #e2e3e5;
            color: #383d41;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .card-biometric {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .card-biometric .card-title {
            color: rgba(255,255,255,0.9);
        }
        
        .card-biometric .card-value {
            color: white;
        }
        
        .refresh-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #27ae60;
            font-size: 0.9rem;
            margin-left: auto;
        }
        
        .auto-refresh-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: 20px;
        }
        
        .toggle-switch {
            position: relative;
            width: 50px;
            height: 25px;
            background: #ddd;
            border-radius: 25px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .toggle-switch.active {
            background: #27ae60;
        }
        
        .toggle-slider {
            position: absolute;
            top: 2px;
            left: 2px;
            width: 21px;
            height: 21px;
            background: white;
            border-radius: 50%;
            transition: left 0.3s;
        }
        
        .toggle-switch.active .toggle-slider {
            left: 27px;
        }

        /* AdminAttendance.php UI Styles */
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

        .btn-success {
            background-color: #16C79A;
            color: white;
        }

        .btn-success:hover {
            background-color: #14a085;
            transform: translateY(-2px);
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

        .summary-icon.hours {
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
        
        .summary-icon.early-in {
            background-color: rgba(13, 202, 240, 0.15);
            color: #0dcaf0;
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
            border-bottom: 2px solid #DBE2EF;
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
            align-items: center;
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
            min-width: 1200px;
        }

        table th {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 18px 15px;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        table th:first-child {
            border-top-left-radius: 12px;
        }

        table th:last-child {
            border-top-right-radius: 12px;
        }

        table td {
            padding: 18px 15px;
            border-bottom: 1px solid #F0F4F8;
            color: #2D3748;
            font-size: 14px;
            transition: all 0.2s ease;
            vertical-align: middle;
        }

        /* Align specific columns */
        table td:nth-child(1),
        table th:nth-child(1) { width: 120px; }
        table td:nth-child(2),
        table th:nth-child(2) { width: 220px; }
        table td:nth-child(3),
        table th:nth-child(3) { width: 180px; }
        table td:nth-child(4),
        table th:nth-child(4) { width: 120px; }
        table td:nth-child(5),
        table th:nth-child(5) { width: 120px; }
        table td:nth-child(6),
        table th:nth-child(6) { width: 120px; }
        table td:nth-child(7),
        table th:nth-child(7) { width: 140px; }
        table td:nth-child(8),
        table th:nth-child(8) { width: 140px; }
        table td:nth-child(9),
        table th:nth-child(9) { width: 140px; }
        table td:nth-child(10),
        table th:nth-child(10) { width: 160px; text-align: center; }

        /* Unified action button style */
        .btn.action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #DBE2EF;
            background: #F8FBFF;
            color: #3F72AF;
            font-weight: 600;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            transition: all .2s ease;
        }
        .btn.action:hover { background: #E9F2FF; transform: translateY(-1px); }
        .btn.action i { color: inherit; }

        /* Pretty toggle (late only) */
        .switch {
            position: relative;
            display: inline-block;
            width: 40px; /* smaller to match form-control height better */
            height: 22px;
            vertical-align: middle;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #E8EEF8;
            transition: .2s;
            border-radius: 8px; /* rectangular corners */
            box-shadow: inset 0 1px 3px rgba(17,45,78,0.15);
            border: 1px solid #DBE2EF; /* match inputs border */
        }
        .slider:before {
            position: absolute;
            content: '';
            height: 16px; width: 16px;
            left: 3px; top: 3px;
            background-color: #fff;
            transition: .2s;
            border-radius: 3px; /* rectangular thumb */
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .switch input:checked + .slider { background: linear-gradient(135deg, #FFB199 0%, #FF6B6B 100%); }
        .switch input:checked + .slider:before { transform: translateX(18px); }
        .switch-label {
            margin-left: 6px;
            font-weight: 600;
            color: #3F72AF;
            font-size: 14px;
        }

        /* Align toggle row with other filter controls */
        .filter-option .toggle-row {
            display: flex;
            align-items: center;
            gap: 8px;
            min-height: 44px; /* match select/form-control height */
            border: 1px solid #ddd; /* match form-control border exactly */
            border-radius: 8px; /* match form-control border-radius */
            padding: 12px; /* match form-control padding */
            background: #fff;
            cursor: pointer;
            transition: border-color 0.3s; /* match form-control transition */
        }

        .filter-option .toggle-row:hover {
            background-color: #F8FBFF;
            box-shadow: 0 4px 10px rgba(63, 114, 175, 0.08);
        }

        .filter-option .toggle-row:focus-within {
            outline: none;
            border-color: #000; /* match form-control:focus border-color */
        }
        .filter-option .toggle-row .switch { 
            vertical-align: middle;
            pointer-events: none;
        }
        .filter-option .toggle-row .switch-label { 
            line-height: 1;
            pointer-events: none;
            user-select: none;
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

        .status-late {
            background-color: rgba(255, 235, 59, 0.15);
            color: #F57F17;
        }
        
        .status-early-in {
            background-color: rgba(76, 175, 80, 0.15);
            color: #4CAF50;
        }
        
        .status-on-time {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 235, 59, 0.2));
            color: #FF8F00;
            box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3);
        }
        
        .status-no-record {
            background-color: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }

        .status-half_day {
            background-color: rgba(33, 150, 243, 0.15);
            color: #2196F3;
        }

        /* Enhanced Header Layout */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
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

        .header-left {
            display: flex;
            flex-direction: column;
            gap: 15px;
            flex: 1;
        }

        .header-left h1 {
            margin: 0;
            font-size: 28px;
            color: #112D4E;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-left h1 i {
            color: #3F72AF;
        }

        .biometric-status {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #e8f5e8;
            color: #2d5a2d;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            width: fit-content;
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            background: #27ae60;
            border-radius: 50%;
            animation: pulse 2s ease-in-out infinite alternate;
        }

        /* Header Actions Layout */
        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: nowrap;
            justify-content: flex-end;
            margin-left: auto;
        }

        .auto-refresh-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
        }

        .welcome-text {
            white-space: nowrap;
            margin-left: 15px;
            font-weight: 500;
            color: #112D4E;
            padding: 8px 15px;
            background: rgba(63, 114, 175, 0.1);
            border-radius: 20px;
            font-size: 0.9rem;
        }

        /* Ensure buttons stay horizontal */
        .header-actions .btn {
            flex-shrink: 0;
            transition: all 0.3s ease;
        }

        .header-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .header-actions .auto-refresh-toggle {
            flex-shrink: 0;
            background: rgba(255, 255, 255, 0.9);
            padding: 8px 15px;
            border-radius: 20px;
            border: 1px solid #DBE2EF;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            position: relative;
        }

        .modal-header {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            padding: 20px 25px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Bulk modal progress bar */
        .bulk-progress {
            height: 6px;
            width: 100%;
            background: rgba(255,255,255,0.25);
            position: relative;
            overflow: hidden;
        }
        .bulk-progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #3F72AF 0%, #112D4E 60%, #16C79A 100%);
            box-shadow: 0 0 10px rgba(63,114,175,0.35);
            transition: width 300ms ease;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .modal-header .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            line-height: 1;
        }

        .modal-header .close:hover {
            opacity: 0.8;
        }

        .modal-body {
            padding: 25px;
            max-height: 400px;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #DBE2EF;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        /* Enhanced Edit Attendance Modal Styles */
        .edit-attendance-modal {
            background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(17, 45, 78, 0.15);
            width: 95%;
            max-width: 800px;
            max-height: 90vh;
            overflow: hidden;
            border: 1px solid rgba(219, 226, 239, 0.3);
            position: relative;
        }

        .edit-attendance-modal::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3F72AF 0%, #112D4E 50%, #16C79A 100%);
        }

        .edit-attendance-modal .modal-header {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
        }

        .edit-attendance-modal .modal-title-section {
            flex: 1;
        }

        .edit-attendance-modal .modal-header h2 {
            margin: 0 0 8px 0;
            font-size: 24px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .edit-attendance-modal .modal-header h2 i {
            font-size: 20px;
            opacity: 0.9;
        }

        .employee-info-display {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .employee-name {
            font-size: 16px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.95);
        }

        .employee-details {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 400;
        }

        .edit-attendance-modal .modal-body {
            padding: 30px;
            max-height: 60vh;
            overflow-y: auto;
            background: #FAFBFC;
        }

        .edit-attendance-modal .modal-footer {
            padding: 25px 30px;
            border-top: 1px solid #E1E8ED;
            background: #FFFFFF;
            border-radius: 0 0 20px 20px;
        }

        .footer-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .action-group {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .primary-actions .save-btn {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(63, 114, 175, 0.3);
        }

        .primary-actions .save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(63, 114, 175, 0.4);
        }

        .secondary-actions .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        /* Time Tracking Section */
        .time-tracking-section {
            margin-bottom: 25px;
        }

        .time-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .time-section {
            background: #FFFFFF;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #E1E8ED;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .morning-section {
            border-left: 4px solid #FFB74D;
        }

        .afternoon-section {
            border-left: 4px solid #81C784;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #F0F4F8;
        }

        .section-header i {
            font-size: 18px;
            color: #3F72AF;
        }

        .section-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: #2C3E50;
        }

        .time-inputs {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .time-input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .time-input-group label {
            font-size: 13px;
            font-weight: 500;
            color: #5A6C7D;
            margin: 0;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .time-input {
            flex: 1;
            padding: 12px 45px 12px 15px;
            border: 2px solid #E1E8ED;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #FFFFFF;
        }

        .time-input:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
        }

        .quick-time-btn {
            position: absolute;
            right: 8px;
            background: #F8F9FA;
            border: 1px solid #E1E8ED;
            border-radius: 6px;
            padding: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #6C757D;
        }

        .quick-time-btn:hover {
            background: #3F72AF;
            color: white;
            transform: scale(1.05);
        }

        .quick-time-btn i {
            font-size: 12px;
        }

        /* Override Section */
        .override-section {
            background: #FFF8E1;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #FFE0B2;
        }

        .override-section .section-header i {
            color: #FF9800;
        }

        .override-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-text {
            font-size: 11px;
            color: #8D6E63;
            margin-top: 4px;
            font-style: italic;
        }

        /* Notes Section */
        .notes-section {
            margin-bottom: 25px;
        }

        .notes-section label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #2C3E50;
            margin-bottom: 8px;
        }

        .notes-section label i {
            color: #3F72AF;
        }

        .notes-textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #E1E8ED;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #FFFFFF;
            resize: vertical;
            min-height: 80px;
        }

        .notes-textarea:focus {
            outline: none;
            border-color: #3F72AF;
            box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
        }

        .notes-textarea::placeholder {
            color: #A0AEC0;
            font-style: italic;
        }

        /* Leave Section */
        .leave-section {
            background: #FFF3E0;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #FFE0B2;
            border-left: 4px solid #FF9800;
        }

        .leave-toggle {
            margin-bottom: 15px;
        }

        .leave-checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            position: relative;
        }

        .leave-checkbox-label input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .checkmark {
            position: relative;
            width: 24px;
            height: 24px;
            background: #FFFFFF;
            border: 2px solid #FF9800;
            border-radius: 6px;
            margin-right: 15px;
            transition: all 0.3s ease;
        }

        .leave-checkbox-label input[type="checkbox"]:checked + .checkmark {
            background: #FF9800;
            border-color: #FF9800;
        }

        .leave-checkbox-label input[type="checkbox"]:checked + .checkmark::after {
            content: '';
            position: absolute;
            left: 6px;
            top: 2px;
            width: 6px;
            height: 12px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .leave-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .leave-content i {
            font-size: 18px;
            color: #FF9800;
        }

        .leave-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .leave-title {
            font-weight: 600;
            color: #E65100;
            font-size: 14px;
        }

        .leave-description {
            font-size: 12px;
            color: #8D6E63;
        }

        .leave-balance-info {
            background: #FFFFFF;
            border-radius: 8px;
            padding: 12px;
            font-size: 12px;
            color: #666;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #E1E8ED;
        }

        .leave-balance-info i {
            color: #3F72AF;
        }

        /* Half Day Section Modal Styles */
        .halfday-section-modal {
            background: #FFF8E1;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #FFE0B2;
            border-left: 4px solid #FF9800;
        }

        .halfday-section-modal .section-header i {
            color: #FF9800;
        }

        .halfday-content {
            margin-top: 15px;
        }

        .halfday-description {
            color: #8D6E63;
            font-size: 14px;
            margin-bottom: 15px;
            font-style: italic;
        }

        .halfday-buttons-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .halfday-option-btn {
            padding: 15px 20px;
            border: 2px solid #FF9800;
            border-radius: 10px;
            background: #FFFFFF;
            color: #FF9800;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            text-align: center;
            cursor: pointer;
        }

        .halfday-option-btn:hover {
            background: #FF9800;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 152, 0, 0.3);
        }

        .halfday-option-btn i {
            font-size: 20px;
        }

        .halfday-option-btn small {
            font-size: 11px;
            opacity: 0.8;
            font-weight: 400;
        }

        .halfday-option-btn:hover small {
            opacity: 1;
        }

        /* Toggle Switch Styles */
        .toggle-switch-container {
            display: flex;
            align-items: center;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 8px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .toggle-switch input:checked + .toggle-slider {
            background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        }

        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(30px);
        }

        .toggle-text {
            font-size: 10px;
            font-weight: 600;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
            z-index: 1;
        }

        .overtime-filter-option {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .overtime-filter-option label {
            font-weight: 500;
            color: #2C3E50;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .edit-attendance-modal {
                width: 98%;
                margin: 2% auto;
            }

            .time-grid {
                grid-template-columns: 1fr;
            }

            .override-inputs {
                grid-template-columns: 1fr;
            }

            .halfday-buttons-container {
                grid-template-columns: 1fr;
            }

            .footer-actions {
                flex-direction: column;
                gap: 15px;
            }

            .action-group {
                width: 100%;
                justify-content: center;
            }
        }

        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #27ae60;
            font-size: 0.9rem;
        }

        .activity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #F0F4F8;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .activity-employee {
            font-weight: 600;
            color: #333;
        }

        .activity-action {
            font-size: 0.9rem;
            color: #666;
        }

        .activity-time {
            font-size: 0.9rem;
            color: #888;
            font-weight: 500;
        }

        /* Responsive styles */
        @media (max-width: 1200px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

            .header-left {
                width: 100%;
            }

            .header-actions {
                width: 100%;
                flex-wrap: wrap;
                justify-content: flex-start;
                margin-left: 0;
            }

            .filter-options {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
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
        /* Enhanced Manual Attendance Modal Styles */
.manual-entry-modal {
    background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
    border-radius: 20px;
    box-shadow: 0 20px 40px rgba(17, 45, 78, 0.15);
    width: 98%;
    max-width: 1200px;
    max-height: 95vh;
    overflow: hidden;
    border: 1px solid rgba(219, 226, 239, 0.3);
    position: relative;
}

.manual-entry-modal::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #3F72AF 0%, #112D4E 50%, #16C79A 100%);
}

.manual-entry-modal .modal-header {
    background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
    padding: 25px 30px;
    border-radius: 20px 20px 0 0;
    position: relative;
    overflow: hidden;
}

.manual-entry-modal .modal-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    animation: float 6s ease-in-out infinite;
}

.manual-entry-modal .modal-header h2 {
    color: white;
    font-size: 22px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.manual-entry-modal .modal-header h2 i {
    font-size: 24px;
    color: #DBE2EF;
}

.manual-entry-modal .modal-body {
    padding: 30px;
    max-height: calc(95vh - 200px);
    overflow-y: visible;
    background: white;
}

/* Custom scrollbar for modal body */
.manual-entry-modal .modal-body::-webkit-scrollbar {
    width: 6px;
}

.manual-entry-modal .modal-body::-webkit-scrollbar-track {
    background: #F8F9FA;
    border-radius: 3px;
}

.manual-entry-modal .modal-body::-webkit-scrollbar-thumb {
    background: #DBE2EF;
    border-radius: 3px;
}

.manual-entry-modal .modal-body::-webkit-scrollbar-thumb:hover {
    background: #3F72AF;
}

/* Form Grid Layout */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-bottom: 30px;
}

.form-section {
    background: linear-gradient(135deg, #FFFFFF 0%, #F8FBFF 100%);
    border-radius: 16px;
    padding: 25px;
    border: 2px solid rgba(219, 226, 239, 0.3);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.form-section::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: linear-gradient(90deg, #3F72AF 0%, #16C79A 100%);
    transition: all 0.3s ease;
}

.form-section:hover {
    border-color: rgba(63, 114, 175, 0.3);
    box-shadow: 0 8px 25px rgba(63, 114, 175, 0.1);
    transform: translateY(-2px);
}

.form-section h3 {
    color: #112D4E;
    font-size: 18px;
    font-weight: 600;
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 12px;
    border-bottom: 1px solid rgba(219, 226, 239, 0.5);
}

.form-section h3 i {
    color: #3F72AF;
    font-size: 20px;
}

/* Employee Section Specific Styling */
.employee-section {
    border-left: 4px solid #3F72AF;
}

.employee-section::before {
    background: linear-gradient(90deg, #3F72AF 0%, #112D4E 100%);
}

/* Details Section Specific Styling */
.details-section {
    border-left: 4px solid #16C79A;
}

.details-section::before {
    background: linear-gradient(90deg, #16C79A 0%, #3F72AF 100%);
}

/* Time Section - Full Width */
.time-section {
    grid-column: 1 / -1;
    border-left: 4px solid #FFC75F;
}

.time-section::before {
    background: linear-gradient(90deg, #FFC75F 0%, #3F72AF 100%);
}

.time-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
}

/* Notes Section - Full Width */
.notes-section {
    grid-column: 1 / -1;
    border-left: 4px solid #FF6B6B;
}

.notes-section::before {
    background: linear-gradient(90deg, #FF6B6B 0%, #3F72AF 100%);
}

/* Form Group Styling */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #112D4E;
    font-weight: 600;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Enhanced Form Controls */
.large-input, .large-textarea {
    width: 100%;
    padding: 15px 18px;
    border: 2px solid #DBE2EF;
    border-radius: 12px;
    font-size: 16px;
    font-family: 'Poppins', sans-serif;
    transition: all 0.3s ease;
    background: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    position: relative;
}

.large-input:focus, .large-textarea:focus {
    outline: none;
    border-color: #3F72AF;
    box-shadow: 0 0 0 4px rgba(63, 114, 175, 0.1), 0 4px 12px rgba(63, 114, 175, 0.15);
    transform: translateY(-2px);
}

.large-input::placeholder, .large-textarea::placeholder {
    color: #9CA3AF;
    font-style: italic;
}

/* Select Dropdown Styling */
.large-input[type="date"],
.large-input[type="time"],
select.large-input {
    cursor: pointer;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    background-position: right 12px center;
    background-repeat: no-repeat;
    background-size: 16px;
    padding-right: 45px;
}

/* Remove calendar icon from date inputs */
.large-input[type="date"] {
    background-image: none;
    padding-right: 18px;
}

/* Remove dropdown arrow for time inputs - show clock icon instead */
.large-input[type="time"] {
    background-image: none;
    padding-right: 18px;
}

/* Keep dropdown arrow only for select inputs */
select.large-input {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
}

/* Style for time inputs with clock icon */
.large-input[type="time"]::-webkit-calendar-picker-indicator {
    background: transparent;
    bottom: 0;
    color: transparent;
    cursor: pointer;
    height: auto;
    left: 0;
    position: absolute;
    right: 0;
    top: 0;
    width: auto;
}

/* Employee Details Card */
.employee-details-card {
    background: linear-gradient(135deg, rgba(63, 114, 175, 0.05) 0%, rgba(22, 199, 154, 0.05) 100%);
    border: 2px solid rgba(63, 114, 175, 0.1);
    border-radius: 12px;
    padding: 20px;
    margin-top: 15px;
    display: none;
    animation: fadeInUp 0.4s ease;
}

.employee-details-card .detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid rgba(63, 114, 175, 0.1);
}

.employee-details-card .detail-item:last-child {
    border-bottom: none;
}

.employee-details-card .detail-label {
    font-weight: 600;
    color: #3F72AF;
    font-size: 14px;
}

.employee-details-card .detail-item span:last-child {
    font-weight: 500;
    color: #112D4E;
    background: rgba(255, 255, 255, 0.8);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 14px;
}

/* Form Actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    margin-top: 30px;
    padding-top: 25px;
    border-top: 2px solid rgba(219, 226, 239, 0.3);
}

.large-btn {
    padding: 15px 30px;
    font-size: 16px;
    font-weight: 600;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
    text-decoration: none;
    position: relative;
    overflow: hidden;
    min-width: 140px;
    justify-content: center;
}

.large-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s ease;
}

.large-btn:hover::before {
    left: 100%;
}

.large-btn.btn-primary {
    background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(63, 114, 175, 0.3);
}

.large-btn.btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(63, 114, 175, 0.4);
}

.large-btn.btn-secondary {
    background: linear-gradient(135deg, #DBE2EF 0%, #F9F7F7 100%);
    color: #112D4E;
    border: 2px solid #DBE2EF;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.large-btn.btn-secondary:hover {
    background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
    color: white;
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(63, 114, 175, 0.3);
}

.large-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.large-btn i {
    font-size: 18px;
}

/* Animations */
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

@keyframes float {
    0%, 100% { transform: translateY(0px) rotate(0deg); }
    50% { transform: translateY(-10px) rotate(5deg); }
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-50px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Enhanced Bulk Edit Modal Styles */
.bulk-step {
    margin-bottom: 0;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.bulk-step h3 {
    color: #112D4E;
    margin-bottom: 20px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #FFFFFF 0%, #F8FBFF 100%);
    border-radius: 12px;
    border-left: 4px solid #3F72AF;
    font-size: 1.3rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 4px 12px rgba(17, 45, 78, 0.08);
    border-bottom: none;
}

#bulkEditModal .bulk-filter-section {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
    margin-bottom: 0;
    padding: 24px;
    background: linear-gradient(135deg, #FFFFFF 0%, #F8FBFF 100%);
    border-radius: 16px;
    box-shadow: 0 8px 24px rgba(17, 45, 78, 0.08);
    border: 1px solid rgba(63, 114, 175, 0.1);
    position: relative;
    overflow: hidden;
}

#bulkEditModal .bulk-filter-section::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #3F72AF 0%, #16C79A 50%, #3F72AF 100%);
    background-size: 200% 100%;
    animation: shimmer 3s ease-in-out infinite;
}

@keyframes shimmer {
    0%, 100% { background-position: 200% 0; }
    50% { background-position: -200% 0; }
}

#bulkEditModal .bulk-filter-section .form-group label {
    font-size: 14px;
    font-weight: 600;
    color: #112D4E;
    margin-bottom: 8px;
    display: block;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

#bulkEditModal .bulk-filter-section .form-control {
    border: 2px solid #DBE2EF;
    border-radius: 12px;
    padding: 14px 16px;
    box-shadow: 0 4px 12px rgba(17, 45, 78, 0.06);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 14px;
    background: #FFFFFF;
}

#bulkEditModal .bulk-filter-section .form-control:focus {
    border-color: #3F72AF;
    box-shadow: 0 0 0 4px rgba(63, 114, 175, 0.1), 0 8px 20px rgba(63, 114, 175, 0.15);
    outline: none;
    transform: translateY(-2px);
}

#bulkEditModal .bulk-filter-section select.form-control {
    appearance: none;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none'%3e%3cpath d='M6 8l4 4 4-4' stroke='%236884b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 14px center;
    background-size: 16px 16px;
    padding-right: 44px;
}


.bulk-filter-section {
    display: grid;
    grid-template-columns: repeat(3, minmax(240px, 1fr));
    gap: 24px;
    margin-bottom: 25px;
    padding: 24px;
    background: linear-gradient(135deg, #FFFFFF 0%, #F6FAFF 100%);
    border-radius: 12px;
    box-shadow: 0 8px 20px rgba(17,45,78,0.08);
    border: 1px solid #E3ECF9;
}

#bulkEditModal .bulk-step-1-container {
    display: grid;
    grid-template-columns: 920px 1fr;
    gap: 24px;
    height: 100%;
    padding: 12px;
    border: 1px solid #E3ECF9;
    border-radius: 12px;
    background: linear-gradient(180deg, #F8FBFF 0%, #EFF4FB 100%);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.6);
}

        .bulk-step-1-left {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.bulk-step-1-right {
    display: flex;
    flex-direction: column;
    border-left: 2px solid #E8F4F8;
    padding-left: 30px;
    min-height: 0;
}

#bulkEditModal .employee-selection-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: linear-gradient(135deg, #FFFFFF 0%, #F8FBFF 100%);
    border-radius: 16px;
    box-shadow: 0 12px 32px rgba(17, 45, 78, 0.12);
    border: 1px solid rgba(63, 114, 175, 0.1);
    overflow: hidden;
    min-height: 0;
    height: 100%;
    position: relative;
}

#bulkEditModal .employee-selection-container::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #16C79A 0%, #3F72AF 50%, #16C79A 100%);
    background-size: 200% 100%;
    animation: shimmer 3s ease-in-out infinite;
}

#bulkEditModal .selection-header {
    display: flex;
    position: sticky;
    top: 0;
    z-index: 1;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: linear-gradient(135deg, #F8FBFF 0%, #E8F4F8 100%);
    border-bottom: 2px solid rgba(63, 114, 175, 0.1);
    font-weight: 600;
    color: #112D4E;
    backdrop-filter: blur(10px);
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

        .modal-body {
    padding: 32px;
    padding-bottom: 0;
    overflow: auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
    background: linear-gradient(135deg, #F8FBFF 0%, #F1F5F9 100%);
    position: relative;
}

.modal-body::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, #3F72AF 0%, #16C79A 50%, #3F72AF 100%);
    background-size: 200% 100%;
    animation: shimmer 4s ease-in-out infinite;
}

#bulkEditModal .employee-list {
    flex: 1;
    height: auto;
    min-height: 220px;
    overflow-y: auto;
    background: linear-gradient(180deg, #FFFFFF 0%, #F8FBFF 100%);
    border-radius: 0;
    box-shadow: none;
    padding: 8px;
}

#bulkEditModal .employee-list::-webkit-scrollbar {
    width: 8px;
}

#bulkEditModal .employee-list::-webkit-scrollbar-track {
    background: #F1F5F9;
    border-radius: 4px;
}

#bulkEditModal .employee-list::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #3F72AF 0%, #16C79A 100%);
    border-radius: 4px;
}

#bulkEditModal .employee-list::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #112D4E 0%, #3F72AF 100%);
}

/* Enhanced Stepper styles for bulk edit modal */
#bulkEditModal .bulk-stepper {
    display: flex;
    justify-content: center;
    align-items: stretch;
    gap: 24px;
    margin-bottom: 32px;
    background: linear-gradient(135deg, #FFFFFF 0%, #F8FBFF 100%);
    border-radius: 20px;
    box-shadow: 0 12px 32px rgba(17, 45, 78, 0.12);
    padding: 20px;
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(63, 114, 175, 0.1);
}
/* subtle moving glow */
@keyframes stepperGlow {
    0% { opacity: 0.1; transform: translateX(-30%); }
    100% { opacity: 0.25; transform: translateX(130%); }
}
#bulkEditModal .bulk-stepper::after {
    content: '';
    position: absolute;
    top: 0; left: -20%;
    height: 4px; width: 40%;
    background: linear-gradient(90deg, rgba(63,114,175,0), rgba(63,114,175,0.4), rgba(22,199,154,0));
    animation: stepperGlow 2.5s ease-in-out infinite;
}

/* Enhanced stepper visuals */
#bulkEditModal .step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    color: #6B7280;
    font-family: 'Poppins', sans-serif;
    font-size: 1.1em;
    padding: 12px 16px;
    border-radius: 12px;
    position: relative;
}

#bulkEditModal .step-item:hover {
    transform: translateY(-3px);
    color: #3F72AF;
    background: rgba(63, 114, 175, 0.05);
}

#bulkEditModal .step-item.active {
    color: #112D4E;
    font-weight: 600;
    background: rgba(63, 114, 175, 0.08);
    transform: translateY(-2px);
}

#bulkEditModal .step-item.active .step-badge {
    background: linear-gradient(135deg, #3F72AF 0%, #16C79A 100%);
    color: #fff;
    box-shadow: 0 6px 20px rgba(63, 114, 175, 0.25);
    transform: scale(1.1);
}

/* Stepper highlight animation when changing steps */
@keyframes stepHighlight {
    from { transform: scale(0.96); box-shadow: 0 0 0 rgba(59,130,246,0); }
    to { transform: scale(1); box-shadow: 0 6px 16px rgba(59,130,246,0.18); }
}
#bulkEditModal .step-item.just-activated {
    animation: stepHighlight 220ms ease-out;
}

/* Step transition animations */
@keyframes stepFadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes stepFadeOutDown {
    from { opacity: 1; transform: translateY(0); }
    to { opacity: 0; transform: translateY(6px); }
}
.step-anim-enter { animation: stepFadeInUp 260ms ease both; }
.step-anim-exit { animation: stepFadeOutDown 200ms ease both; }

/* Reactive buttons */
#bulkEditModal .modal-footer .btn {
    transition: transform 150ms ease, box-shadow 150ms ease, background 150ms ease;
}
#bulkEditModal .modal-footer .btn:active {
    transform: translateY(0);
    box-shadow: none;
}

/* Elevate right list */
#bulkEditModal .employee-selection-container {
    backdrop-filter: saturate(1.05);
}

/* Remove excess bottom gap in Step 1 container inside bulk modal */
#bulkEditModal .bulk-step { margin-bottom: 0; flex: 1; min-height: 0; }

/* Ensure Step 1 grid stretches and columns fill height */
#bulkEditModal .bulk-step-1-container { align-items: stretch; height: 100%; }
#bulkEditModal .bulk-step-1-left { height: 100%; min-height: 0; overflow: auto; }
#bulkEditModal .bulk-step-1-left .bulk-filter-section {
    background: linear-gradient(135deg, rgba(255,255,255,0.98) 0%, rgba(245,248,255,0.98) 100%);
}

#bulkEditModal .step-item {
    display: flex;
    align-items: center;
    gap: 12px;
    background: linear-gradient(180deg, #ffffff 0%, #f4f8ff 100%);
    border: 1px solid #E3ECF9;
    padding: 12px 14px;
    border-radius: 14px;
    box-shadow: 0 6px 18px rgba(17,45,78,0.06);
    opacity: 0.95;
    transition: transform 180ms ease, box-shadow 180ms ease, border-color 180ms ease;
}
#bulkEditModal .step-item:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(17,45,78,0.10); }

#bulkEditModal .step-item.active {
    border-color: #3F72AF;
    box-shadow: 0 4px 12px rgba(63, 114, 175, 0.12);
    opacity: 1;
}

#bulkEditModal .step-badge {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #DBE2EF 0%, #EAF0FB 100%);
    color: #112D4E;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.06);
}

#bulkEditModal .employee-list::-webkit-scrollbar { width: 8px; }
#bulkEditModal .employee-list::-webkit-scrollbar-track { background: #f3f6fb; }
#bulkEditModal .employee-list::-webkit-scrollbar-thumb { background: #cfd9ea; border-radius: 8px; }
#bulkEditModal .employee-list::-webkit-scrollbar-thumb:hover { background: #b6c6e0; }

#bulkEditModal .employee-item {
    display: flex;
    align-items: flex-start;
    padding: 10px 14px;
    min-height: 56px; /* fixed row height for consistent rows visible */
    border-bottom: 1px solid #F0F4F8;
    transition: all 0.3s ease;
    position: relative;
}
#bulkEditModal .employee-item:hover { background: #f9fbff; }
#bulkEditModal .employee-item.selected { background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%); border-left: 4px solid #3B82F6; }
#bulkEditModal .employee-item:nth-child(odd) { background: #fafcff; }

        /* Subtle scrollbar for long employee lists */
        #bulkEditModal .employee-list::-webkit-scrollbar { width: 8px; }
        #bulkEditModal .employee-list::-webkit-scrollbar-track { background: #f3f6fb; }
        #bulkEditModal .employee-list::-webkit-scrollbar-thumb { background: #cfd9ea; border-radius: 8px; }
        #bulkEditModal .employee-list::-webkit-scrollbar-thumb:hover { background: #b6c6e0; }

        #bulkEditModal .employee-item {
    display: flex;
    align-items: flex-start;
    padding: 10px 14px;
    min-height: 56px;
    border-bottom: 1px solid #F0F4F8;
    transition: all 0.3s ease;
    position: relative;
}

        #bulkEditModal .employee-item:hover {
    background: #f9fbff;
}

        #bulkEditModal .employee-item.selected {
    background: linear-gradient(135deg, #E0F2FE 0%, #DBEAFE 100%);
    border-left: 5px solid #2563EB;
    box-shadow: 0 6px 24px rgba(59,130,246,0.18);
}

        /* Alternating rows for easier scanning */
        #bulkEditModal .employee-item:nth-child(odd) { background: #fafcff; }

        /* Tighter name line and clearer columns */
        .employee-header {
    margin-bottom: 6px;
}
        .employee-details {
    font-size: 12.5px;
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
    margin-bottom: 6px;
}

        .employee-name {
    font-weight: 600;
    color: #112d4e;
    font-size: 15px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 520px;
}

        .attendance-status {
    display: flex;
    align-items: center;
    gap: 8px;
}

        .attn-badge {
    padding: 3px 10px;
    border-radius: 14px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
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
    font-size: 12.5px;
    color: #5b6b83;
    line-height: 1.4;
}

        .detail-row {
    margin-bottom: 2px;
}

        .detail-label {
    font-weight: 600;
    color: #475569;
}

        /* Card polish for bulk filter section */
        .bulk-filter-section {
    background: #ffffff;
    border: 1px solid #e8eef7;
    box-shadow: 0 6px 18px rgba(17,45,78,0.06);
    border-radius: 14px;
    padding: 22px;
}

        .bulk-filter-section .form-control {
    border-radius: 12px;
    padding: 12px 14px;
    border: 2px solid #DBE2EF;
    background: #ffffff;
    transition: all 0.2s ease;
}
        .bulk-filter-section .form-control:hover {
    border-color: #c8d6f0;
    box-shadow: 0 4px 12px rgba(63,114,175,0.08);
}
        .bulk-filter-section select.form-control {
    appearance: none;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none'%3e%3cpath d='M6 8l4 4 4-4' stroke='%236884b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 14px center;
    background-size: 16px 16px;
    padding-right: 44px;
}
        .bulk-filter-section .form-control:focus {
    border-color: #3F72AF;
    box-shadow: 0 0 0 3px rgba(63,114,175,0.12);
}

        .bulk-filter-section .form-group label {
    color: #274869;
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

/* Attendance status badge */
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
    border: 2px solid #e8f4f8;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
}

.shift-option:hover {
    border-color: #3b82f6;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

.shift-option.selected {
    border-color: #112d4e;
    background: #e8f4f8;
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
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    font-size: 24px;
}

.shift-option.selected .shift-icon {
    background: linear-gradient(135deg, #112d4e, #0f1b2e);
}

.shift-info h4 {
    margin: 0 0 5px 0;
    color: #112d4e;
    font-size: 1.1rem;
}

.shift-info p {
    margin: 0;
    color: #666;
    font-size: 0.9rem;
}

.shift-info-display {
    padding: 15px;
    background: #e8f4f8;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid #112d4e;
}

.shift-info-display h4 {
    margin: 0 0 5px 0;
    color: #112d4e;
}

.shift-info-display p {
    margin: 0;
    color: #666;
    font-size: 0.9rem;
}

/* Custom scrollbar styling for modals */
#viewAttendanceBody::-webkit-scrollbar {
    width: 8px;
}

#viewAttendanceBody::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

#viewAttendanceBody::-webkit-scrollbar-thumb {
    background: #3F72AF;
    border-radius: 4px;
}

#viewAttendanceBody::-webkit-scrollbar-thumb:hover {
    background: #112D4E;
}

/* Modal Display Enhancement */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(17, 45, 78, 0.8);
    backdrop-filter: blur(5px);
    z-index: 1000;
    animation: fadeIn 0.3s ease;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

/* Responsive Design */
@media (max-width: 992px) {
    .form-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .time-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .bulk-filter-section {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .bulk-step-1-container {
        grid-template-columns: 1fr;
        gap: 20px;
        min-height: auto;
    }
    
    .bulk-step-1-right {
        border-left: none;
        border-top: 2px solid #E8F4F8;
        padding-left: 0;
        padding-top: 20px;
    }
    
    .employee-selection-container {
        min-height: 300px;
    }
    
    .employee-list {
        min-height: 200px;
    }
    
    .manual-entry-modal {
        width: 98%;
        max-width: none;
        margin: 1vh auto;
        border-radius: 16px;
    }
    
    .manual-entry-modal .modal-body {
        padding: 20px;
        max-height: calc(90vh - 150px);
    }
    
    .form-section {
        padding: 20px;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .large-btn {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .manual-entry-modal .modal-header {
        padding: 20px;
    }
    
    .manual-entry-modal .modal-header h2 {
        font-size: 18px;
    }
    
    .form-section h3 {
        font-size: 16px;
    }
    
    .large-input, .large-textarea {
        padding: 12px 15px;
        font-size: 14px;
    }
}

@media (max-width: 480px) {
    .manual-entry-modal {
        width: 100%;
        height: 100%;
        border-radius: 0;
        max-height: 100vh;
    }
    
    .manual-entry-modal .modal-header {
        border-radius: 0;
    }
    
    .manual-entry-modal .modal-body {
        padding: 15px;
        max-height: calc(100vh - 140px);
    }
    
    .form-section {
        padding: 15px;
        border-radius: 12px;
    }
}

/* Status-based styling for time fields */
.time-section.hidden {
    display: none;
    animation: fadeOut 0.3s ease;
}

.time-section.visible {
    display: block;
    animation: fadeInUp 0.3s ease;
}

/* Loading state for submit button */
.large-btn.loading {
    position: relative;
    color: transparent;
}

.large-btn.loading::after {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    top: 50%;
    left: 50%;
    margin-left: -10px;
    margin-top: -10px;
    border: 2px solid transparent;
    border-top-color: currentColor;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
}

/* Employee Search Styles */
.employee-search-container {
    position: relative;
    width: 100%;
}

.employee-search-container-main {
    position: relative;
    width: 100%;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-input-wrapper input {
    padding-right: 40px; /* Make room for clear button */
}

.clear-search-btn {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #6c757d;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.2s ease;
    display: none; /* Hidden by default */
}

.clear-search-btn:hover {
    color: #dc3545;
    background-color: rgba(220, 53, 69, 0.1);
}

.clear-search-btn.show {
    display: block;
}

.employee-search-option {
    grid-column: 1 / -1; /* Full width for search */
    margin-bottom: 20px;
}

.search-results-main {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #DBE2EF;
    border-top: none;
    border-radius: 0 0 12px 12px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    display: none;
}

.search-results-main.show {
    display: block;
    animation: slideInDown 0.3s ease;
}

.search-result-item-main {
    padding: 12px 15px;
    cursor: pointer;
    border-bottom: 1px solid #F0F4F8;
    transition: all 0.2s ease;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.search-result-item-main:hover {
    background-color: #F8FBFF;
    color: #3F72AF;
}

.search-result-item-main:last-child {
    border-bottom: none;
}

.search-result-info-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.search-result-name-main {
    font-weight: 600;
    color: #112D4E;
}

.search-result-details-main {
    font-size: 0.85rem;
    color: #6c757d;
}

.search-result-id-main {
    font-size: 0.8rem;
    color: #3F72AF;
    background: rgba(63, 114, 175, 0.1);
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: 500;
}

.search-loading {
    padding: 15px;
    text-align: center;
    color: #16C79A;
    font-style: italic;
}

.no-results {
    padding: 15px;
    text-align: center;
    color: #dc3545;
    font-style: italic;
}

.search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #DBE2EF;
    border-top: none;
    border-radius: 0 0 12px 12px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    display: none;
}

.search-result-item {
    padding: 12px 15px;
    cursor: pointer;
    border-bottom: 1px solid #F0F4F8;
    transition: all 0.2s ease;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.search-result-item:hover {
    background-color: #F8FBFF;
    color: #3F72AF;
}

.search-result-item:last-child {
    border-bottom: none;
}

.search-result-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.search-result-name {
    font-weight: 600;
    color: #112D4E;
}

.search-result-details {
    font-size: 0.85rem;
    color: #6c757d;
}

.search-result-id {
    font-size: 0.8rem;
    color: #3F72AF;
    background: rgba(63, 114, 175, 0.1);
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: 500;
}

/* Overtime Section Styles */
.overtime-section {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid rgba(219, 226, 239, 0.5);
}

.overtime-checkbox-container {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px 20px;
    background: linear-gradient(135deg, rgba(255, 199, 95, 0.05) 0%, rgba(255, 199, 95, 0.1) 100%);
    border: 2px solid rgba(255, 199, 95, 0.2);
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-bottom: 0;
}

.overtime-checkbox-container:hover {
    background: linear-gradient(135deg, rgba(255, 199, 95, 0.1) 0%, rgba(255, 199, 95, 0.15) 100%);
    border-color: rgba(255, 199, 95, 0.3);
    transform: translateY(-1px);
}

.overtime-checkbox {
    width: 24px;
    height: 24px;
    background: white;
    border: 2px solid #DBE2EF;
    border-radius: 6px;
    position: relative;
    transition: all 0.3s ease;
    flex-shrink: 0;
    cursor: pointer;
}

.overtime-checkbox input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
    margin: 0;
}

.overtime-checkbox input[type="checkbox"]:checked + .checkmark {
    background: linear-gradient(135deg, #FFC75F 0%, #FF6B6B 100%);
    border-color: #FFC75F;
}

.overtime-checkbox input[type="checkbox"]:checked + .checkmark::after {
    content: '';
    position: absolute;
    left: 7px;
    top: 3px;
    width: 6px;
    height: 12px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.overtime-checkbox .checkmark {
    width: 100%;
    height: 100%;
    background: white;
    border: 2px solid #DBE2EF;
    border-radius: 6px;
    position: absolute;
    top: 0;
    left: 0;
    transition: all 0.3s ease;
}

.overtime-label-text {
    font-size: 16px;
    font-weight: 600;
    color: #112D4E;
    flex: 1;
}

.overtime-fields {
    margin-top: 20px;
    padding: 20px;
    background: linear-gradient(135deg, rgba(255, 199, 95, 0.05) 0%, rgba(255, 199, 95, 0.1) 100%);
    border: 2px solid rgba(255, 199, 95, 0.2);
    border-radius: 12px;
    animation: fadeInUp 0.3s ease;
    display: none;
}

.overtime-fields.show {
    display: block;
}

.overtime-fields .form-group {
    margin-bottom: 15px;
}

.overtime-fields .form-group:last-child {
    margin-bottom: 0;
}

/* Search Results Animation */
@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.search-results.show {
    display: block;
    animation: slideInDown 0.3s ease;
}

/* Filter Feedback Animations */
@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(100%);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes slideOutRight {
    from {
        opacity: 1;
        transform: translateX(0);
    }
    to {
        opacity: 0;
        transform: translateX(100%);
    }
}

/* Filter Feedback Styles */
.filter-feedback {
    position: fixed;
    top: 80px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 8px;
    color: white;
    font-weight: 500;
    z-index: 10000;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    animation: slideInRight 0.3s ease;
    max-width: 300px;
}

.filter-feedback-success {
    background: #16C79A;
}

.filter-feedback-error {
    background: #FF6B6B;
}

.filter-feedback-info {
    background: #3F72AF;
}

/* Enhanced Employee Details Card */
.employee-details-card .detail-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

/* Responsive adjustments for search */
@media (max-width: 768px) {
    .search-results {
        max-height: 150px;
    }
    
    .search-result-item {
        padding: 10px 12px;
    }
    
    .search-result-name {
        font-size: 14px;
    }
    
    .search-result-details {
        font-size: 12px;
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
            <a href="HRAttendance.php" class="menu-item active">
                <i class="fas fa-calendar-check"></i> <span>Attendance</span>
            </a>
            <a href="HRhistory.php" class="menu-item">
                <i class="fas fa-history"></i> <span>History</span>
            </a>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </a>
    </div>

    <!-- Bulk Edit Attendance Modal (container) -->
    <div id="bulkEditModal" class="modal" style="display:none;">
        <div class="modal-content" style="width: 96vw; max-width: 1600px; height: 92vh; border-radius: 18px; overflow: hidden; display:flex; flex-direction:column;">
            <div class="modal-header" style="background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%); color:#fff; padding: 18px 22px; display:flex; align-items:center; justify-content:space-between;">
                <h2><i class="fas fa-users-edit"></i> Bulk Edit Attendance</h2>
                <span class="close" onclick="closeBulkEditModal()" style="cursor:pointer; font-size: 26px;">&times;</span>
            </div>
            <div class="modal-body" style="background: linear-gradient(180deg, #F8FBFF 0%, #F1F5F9 100%); padding: 22px; min-height: 800px; overflow:hidden; flex:1;">
                <div id="bulk-stepper" class="bulk-stepper">
                    <div class="step-item active" id="bulk-stepper-1"><div class="step-badge">1</div><div>Select Employees</div></div>
                    <div class="step-item" id="bulk-stepper-2"><div class="step-badge">2</div><div>Select Shift</div></div>
                    <div class="step-item" id="bulk-stepper-3"><div class="step-badge">3</div><div>Edit Times</div></div>
                </div>
                <div id="bulk-step-1" class="bulk-step" style="display:block;">
                    <div class="bulk-step-1-container" style="display:grid; grid-template-columns: 420px 1fr; gap:24px; align-items:start; min-height:0; height:100%;">
                        <div class="bulk-step-1-left" style="min-height:0;">
                            <div class="bulk-filter-section" style="position:sticky; top:0;">
                                <div class="form-group">
                                    <label for="bulk-department-filter">Department</label>
                                    <select id="bulk-department-filter" class="form-control" onchange="onBulkDepartmentChanged()">
                                        <option value="">All Departments</option>
                                        <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="bulk-shift-filter">Shift</label>
                                    <select id="bulk-shift-filter" class="form-control" onchange="onBulkShiftChanged()">
                                        <option value="">All Shifts</option>
                                        <option value="08:00-17:00">8:00 AM - 5:00 PM</option>
                                        <option value="08:30-17:30">8:30 AM - 5:30 PM</option>
                                        <option value="09:00-18:00">9:00 AM - 6:00 PM</option>
                                        
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="bulk-employee-search">Search Employee</label>
                                    <input type="text" id="bulk-employee-search" class="form-control" placeholder="Type employee name or ID..." onkeyup="filterBulkEmployees()">
                                </div>
                            </div>
                        </div>
                        <div class="bulk-step-1-right" style="min-height:0; display:flex; flex-direction:column;">
                            <div class="employee-selection-container" style="flex:1; display:flex; flex-direction:column; min-height:0;">
                                <div class="selection-header">
                                    <label><input type="checkbox" id="select-all-employees" onchange="toggleAllEmployees()"> Select All Visible</label>
                                    <span id="selected-count">0 employees selected</span>
                                </div>
                                <div id="bulk-employee-list" class="employee-list" style="flex:1; overflow:auto; min-height:260px;"></div>
                            </div>
                            
                        </div>
                    </div>
                </div>
                <div id="bulk-step-2" class="bulk-step" style="display:none; max-height: calc(100% - 120px); overflow:auto; padding-bottom: 12px;">
                    <div class="shift-selection" style="margin-bottom: 0;">
                        <div class="shift-option" onclick="selectBulkShift('morning', this)"><div class="shift-icon"><i class="fas fa-sun"></i></div><div class="shift-info"><h4>Morning Shift</h4><p>Edit AM In/Out only</p></div></div>
                        <div class="shift-option" onclick="selectBulkShift('afternoon', this)"><div class="shift-icon"><i class="fas fa-cloud-sun"></i></div><div class="shift-info"><h4>Afternoon Shift</h4><p>Edit PM In/Out only</p></div></div>
                        <div class="shift-option" onclick="selectBulkShift('full', this)"><div class="shift-icon"><i class="fas fa-clock"></i></div><div class="shift-info"><h4>Full Day</h4><p>Edit all times</p></div></div>
                    </div>
                </div>
                <div id="bulk-step-3" class="bulk-step" style="display:none; max-height: calc(100% - 120px); overflow:auto; padding-bottom: 12px;">
                    <div id="bulk-shift-info" class="shift-info-display"></div>
                    <form id="bulkEditForm">
                    <div class="form-section" style="margin-bottom: 16px;">
                            <h3 style="display:flex; align-items:center; gap:8px;"><i class="fas fa-business-time"></i> Status & Overtime</h3>
                            <div class="form-grid" style="grid-template-columns: repeat(4, minmax(220px, 1fr)); gap: 16px;">
                                <div class="form-group" style="display:flex; align-items:center; gap:10px;">
                                    <input type="checkbox" id="bulk_mark_ot" style="transform: scale(1.2);">
                                    <label for="bulk_mark_ot" style="margin:0;">Mark Overtime</label>
                                </div>
                                <div class="form-group">
                                    <label for="bulk_overtime_hours">Overtime Hours</label>
                                    <input type="number" id="bulk_overtime_hours" class="form-control large-input" step="0.01" min="0" placeholder="0.00">
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
                            <small style="color:#6c757d;">If "Mark Overtime" is unchecked or "Mark as No Overtime" is checked, overtime will be cleared to 0. If "Mark as No Time Out" is checked, time out will be cleared. For half day, choose which session to keep.</small>
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
                                <div class="form-group" style="margin-top: 16px;">
                                    <label for="bulk_notes">Notes (Optional)</label>
                                    <textarea id="bulk_notes" name="notes" class="form-control large-textarea" rows="4"></textarea>
                                </div>
                            </div>
                        </div>
                        <div style="text-align: center; margin: 12px 0;">
                            <button type="button" class="btn btn-info" onclick="autofillShiftTimes(bulkEditData.selectedShift)">
                                <i class="fas fa-magic"></i> Auto-fill All Default Times
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="modal-footer" style="position:sticky; bottom:0; padding: 14px 20px; display:flex; justify-content:space-between; gap: 8px; background:linear-gradient(135deg, #FFFFFF 0%, #F8FBFF 100%); border-top: 1px solid rgba(63,114,175,0.12); box-shadow: 0 -6px 14px rgba(17,45,78,0.06);">
                <div>
                    <button type="button" class="btn btn-secondary" id="bulk-prev-btn" onclick="previousBulkStep()" style="display:none;">Previous</button>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="button" class="btn btn-primary" id="bulk-next-btn" onclick="nextBulkStep()">Next</button>
                    <button type="button" class="btn btn-success" id="bulk-save-btn" onclick="submitBulkEdit()" style="display:none;">Save Changes</button>
                </div>
            </div>
        </div>
    </div>
    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1>
                    <i class="fas fa-fingerprint"></i> Biometric Attendance Management
                </h1>
            </div>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="openRecentActivitiesModal()">
                    <i class="fas fa-clock"></i> Recent Activities
                </button>
                <button class="btn btn-primary" onclick="openAddAttendanceModal()">
                    <i class="fas fa-plus"></i> Add Attendance
</button>
                
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon present">
                    <i class="fas fa-users"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="total-present-value"><?php echo $stats['total_present'] ?? 0; ?></div>
                    <div class="summary-label">
                        <?php echo 'Total Present'; ?>
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon hours">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="still-present-value"><?php echo $stats['still_present'] ?? 0; ?></div>
                    <div class="summary-label">
                        <?php echo 'Still Present'; ?>
                    </div>
                </div>
            </div>
            
            
            
            <div class="summary-card">
                <div class="summary-icon late">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="late-arrivals-value"><?php echo $stats['late_arrivals'] ?? 0; ?></div>
                    <div class="summary-label">Late Arrivals</div>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="summary-icon hours">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="attendance-rate-value"><?php echo $attendance_rate; ?>%</div>
                    <div class="summary-label">
                        Attendance Rate
                        
                    </div>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="summary-icon absent">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="summary-info">
                    <div class="summary-value" id="absent-value"><?php echo $actual_absent; ?></div>
                    <div class="summary-label">
                        <?php echo 'Absent Today'; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activities Placeholder -->
        <div class="recent-activities-placeholder">
            <i class="fas fa-info-circle"></i>
            <p>Click "Recent Activities" button to view live biometric activities</p>
        </div>

        <!-- Filter Container -->
        <div class="filter-container">
            <div class="filter-header">
                <h2><i class="fas fa-filter"></i> Filter Today's Attendance Records</h2>
            </div>
            
            <div class="filter-controls">
                <div class="filter-options">
                    <div class="filter-option employee-search-option">
                        <label for="employee-search-main">Search Employee:</label>
                        <div class="employee-search-container-main">
                            <div class="search-input-wrapper">
                                <input type="text" id="employee-search-main" class="form-control" placeholder="Type employee name or ID..." autocomplete="off">
                                <button type="button" class="clear-search-btn" onclick="clearEmployeeSearch()" title="Clear search">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="search-results-main" id="searchResultsMain"></div>
                        </div>
                    </div>
                    
                    <div class="filter-option">
                        <label for="department-filter-hr">Department:</label>
                        <select id="department-filter-hr" class="form-control">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo ($dept == $department_filter) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-option">
                        <label for="shift-filter-hr">Shift:</label>
                        <select id="shift-filter-hr" class="form-control">
                            <option value="">All Shifts</option>
                            <option value="08:00-17:00" <?php echo ($shift_filter === '08:00-17:00') ? 'selected' : ''; ?>>8:00 AM - 5:00 PM</option>
                            <option value="08:30-17:30" <?php echo ($shift_filter === '08:30-17:30') ? 'selected' : ''; ?>>8:30 AM - 5:30 PM</option>
                            <option value="09:00-18:00" <?php echo ($shift_filter === '09:00-18:00') ? 'selected' : ''; ?>>9:00 AM - 6:00 PM</option>
                        </select>
                    </div>
                    <div class="filter-option">
                        <label for="status-filter-hr">Status:</label>
                        <select id="status-filter-hr" class="form-control">
                            <option value="">All Status</option>
                            <option value="early_in" <?php echo ($status_filter === 'early_in') ? 'selected' : ''; ?>>Early In</option>
                            <option value="on_time" <?php echo ($status_filter === 'on_time') ? 'selected' : ''; ?>>On Time</option>
                            <option value="late" <?php echo ($status_filter === 'late') ? 'selected' : ''; ?>>Late</option>
                            <option value="halfday" <?php echo ($status_filter === 'halfday') ? 'selected' : ''; ?>>Half Day</option>
                            <option value="on_leave" <?php echo ($status_filter === 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                        </select>
                    </div>
                    <div class="filter-option">
                        <label for="type-filter-hr">Attendance Type:</label>
                        <select id="type-filter-hr" class="form-control">
                            <option value="">All Types</option>
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>
                    <div class="filter-option">
                        <label for="no-timeout-filter">No Time Out:</label>
                        <select id="no-timeout-filter" class="form-control">
                            <option value="">All</option>
                            <option value="1" <?php echo $no_timeout_filter ? 'selected' : ''; ?>>Show Only</option>
                        </select>
                    </div>
                    <div class="filter-option overtime-filter-option">
                        <label for="overtime-filter">Show Overtime:</label>
                        <div class="toggle-switch-container">
                            <label class="toggle-switch">
                                <input type="checkbox" id="overtime-filter" onchange="toggleOvertimeFilter()">
                                <span class="toggle-slider">
                                    <span class="toggle-text">OFF</span>
                                    <span class="toggle-text">ON</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="filter-actions">
                    <button class="btn btn-secondary" onclick="resetFilters()">
                        <i class="fas fa-undo"></i> Reset All
                    </button>
                    <button class="btn btn-success" onclick="exportAttendance()">
                        <i class="fas fa-file-excel"></i> Export
                    </button>
                    <button class="btn btn-warning" onclick="openBulkEditModal()">
                        <i class="fas fa-users-edit"></i> Bulk Edit
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Results Container -->
        <div class="results-container">
            <div class="results-header">
                <h2><i class="fas fa-list"></i> Attendance Records</h2>
                <div class="results-actions">
                    <div style="color: #666; font-size: 0.9rem; margin-right: 15px;">
                        <i class="fas fa-sync-alt" id="tableRefreshIcon"></i>
                        Last updated: <span id="lastUpdated"><?php echo date('h:i:s A'); ?></span>
                    </div>
                </div>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Shift</th>
                            <th>Date</th>
                            <th>Total Hours</th>
                            <th>Attendance Type</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="attendance-results">
                        <?php 
                        if (count($attendance_records) > 0) {
                            foreach($attendance_records as $row) {
                                // Use the database status field, handle absent records
                                $status = $row['status'] ?? 'no_record';
                                if ($status === 'no_record' && $row['attendance_type'] === 'absent') {
                                    $status = 'absent';
                                }
                                $statusClass = 'status-' . $status;
                                // Handle status display text
                                if (($row['is_on_leave'] ?? 0) == 1) {
                                    $statusText = 'ON-LEAVE';
                                    $statusClass = 'status-on-leave';
                                } else {
                                    switch($status) {
                                        case 'early_in':
                                            $statusText = 'Early In';
                                            break;
                                        case 'on_time':
                                            $statusText = 'On Time';
                                            break;
                                        case 'half_day':
                                            $statusText = 'Half Day';
                                            break;
                                        case 'no_record':
                                            $statusText = 'No Record';
                                            break;
                                        default:
                                            $statusText = ucfirst($status);
                                            break;
                                    }
                                }
                                
                                $time_in = !empty($row['time_in']) ? date('h:i A', strtotime($row['time_in'])) : '-';
                                $time_out = !empty($row['time_out']) ? date('h:i A', strtotime($row['time_out'])) : 'Not yet';
                                $date = date('M d, Y', strtotime($row['attendance_date']));
                                
                                // Calculate duration preferring AM/PM segments when provided
                                $duration = 'In Progress';
                                
                                // Handle absent records
                                if ($row['attendance_type'] === 'absent' && empty($row['time_in'])) {
                                    $duration = 'Absent';
                                } else {
                                $dateYmd = date('Y-m-d', strtotime($row['attendance_date']));
                                $am_in = $row['time_in_morning'] ?? null;
                                $am_out = $row['time_out_morning'] ?? null;
                                $pm_in = $row['time_in_afternoon'] ?? null;
                                $pm_out = $row['time_out_afternoon'] ?? null;
                                $segment_seconds = 0;
                                $has_segment = false;
                                if (!empty($am_in) && !empty($am_out)) {
                                    $has_segment = true;
                                    $segment_seconds += max(0, strtotime($dateYmd.' '.$am_out) - strtotime($dateYmd.' '.$am_in));
                                } elseif (!empty($am_in) || !empty($am_out)) { $has_segment = true; }
                                if (!empty($pm_in) && !empty($pm_out)) {
                                    $has_segment = true;
                                    $segment_seconds += max(0, strtotime($dateYmd.' '.$pm_out) - strtotime($dateYmd.' '.$pm_in));
                                } elseif (!empty($pm_in) || !empty($pm_out)) { $has_segment = true; }
                                if ($has_segment) {
                                    $total_hours = round($segment_seconds / 3600, 2);
                                    $hours = floor($total_hours);
                                    $minutes = floor(($total_hours - $hours) * 60);
                                    $duration = sprintf("%dh %02dm", $hours, $minutes);
                                } elseif (!empty($row['time_in']) && !empty($row['time_out'])) {
                                    // Fallback when segments are not present: simple total from overall in/out
                                    $total_hours = AttendanceCalculator::calculateTotalHours($row['time_in'], $row['time_out']);
                                    $hours = floor($total_hours);
                                    $minutes = floor(($total_hours - $hours) * 60);
                                    $duration = sprintf("%dh %02dm", $hours, $minutes);
                                    }
                                }
                                
                                // Determine source based on data_source field
                                $dataSource = $row['data_source'] ?? 'biometric';
                                switch($dataSource) {
                                    case 'manual':
                                        $source = '<i class="fas fa-edit text-primary" title="Manual Entry (Web App)"></i>';
                                        break;
                                    case 'bulk_edit':
                                        $source = '<i class="fas fa-users-cog text-warning" title="Bulk Edit (Web App)"></i>';
                                        break;
                                    case 'biometric':
                                    default:
                                        $source = '<i class="fas fa-fingerprint text-success" title="Biometric Scanner (ZKteco Device)"></i>';
                                        break;
                                }
                                
                                $isNoTimeout = empty($row['time_out']);
                                $isAbsent = $row['attendance_type'] === 'absent' && empty($row['time_in']);
                                $isManual = !empty($row['notes']) && stripos($row['notes'], 'manual') !== false;
                                // Determine shift end datetime for this record
                                $shiftStr = isset($row['Shift']) ? $row['Shift'] : '';
                                $endMap = ['08:00-17:00'=>'17:00','08:00-17:00pb'=>'17:00','8-5pb'=>'17:00','08:30-17:30'=>'17:30','8:30-5:30pm'=>'17:30','09:00-18:00'=>'18:00','9am-6pm'=>'18:00'];
                                $endTime = isset($endMap[$shiftStr]) ? $endMap[$shiftStr] : '17:00';
                                $recDate = $row['attendance_date'];
                                $shiftEndTs = strtotime($recDate . ' ' . $endTime);
                                $nowTs = time();
                                $isShiftEnded = $nowTs >= $shiftEndTs;
                                
                                // Determine action button rules:
                                // - Absent: show Add button with employee pre-fill
                                // - Manual: show Edit; if no timeout, show Mark Out instead
                                // - Biometric: if no timeout, show Mark Out; else show Edit (one button only)
                                $actionButton = '';
                                if ($isAbsent) {
                                    $empId = htmlspecialchars($row['EmployeeID'], ENT_QUOTES);
                                    $empName = htmlspecialchars($row['EmployeeName'], ENT_QUOTES);
                                    $dept = htmlspecialchars($row['Department'], ENT_QUOTES);
                                    $shift = htmlspecialchars($row['Shift'], ENT_QUOTES);
                                    $date = htmlspecialchars($row['attendance_date'], ENT_QUOTES);
                                    $actionButton = "<button class='btn action' onclick=\"event.stopPropagation(); openAddAttendanceModalWithEmployee('$empId', '$empName', '$dept', '$shift', '$date')\"><i class='fas fa-plus'></i> Add</button>";
                                } else if ($isNoTimeout) {
                                    $actionButton = "<button class='btn action' onclick=\"event.stopPropagation(); markTimeOut('{$row['id']}', this)\"><i class='fas fa-sign-out-alt'></i> Mark Out</button>";
                                } else {
                                    $actionButton = "<button class='btn action' onclick=\"event.stopPropagation(); openEditAttendance('{$row['id']}')\"><i class='fas fa-edit'></i> Edit</button>";
                                }
                                
                                echo "<tr class='clickable-row' data-id='{$row['id']}'
                                    data-employee_id='" . htmlspecialchars($row['EmployeeID'] ?? '', ENT_QUOTES) . "'
                                    data-employee_name='" . htmlspecialchars($row['EmployeeName'] ?? '', ENT_QUOTES) . "'
                                    data-department='" . htmlspecialchars($row['Department'] ?? '', ENT_QUOTES) . "'
                                    data-shift='" . htmlspecialchars($row['Shift'] ?? '', ENT_QUOTES) . "'
                                    data-source='" . htmlspecialchars($row['data_source'] ?? 'biometric', ENT_QUOTES) . "'
                                    data-date='" . htmlspecialchars($row['attendance_date'] ?? '', ENT_QUOTES) . "'
                                    data-time_in='" . htmlspecialchars($row['time_in'] ?? '', ENT_QUOTES) . "'
                                    data-time_out='" . htmlspecialchars($row['time_out'] ?? '', ENT_QUOTES) . "'
                                    data-am_in='" . htmlspecialchars($row['time_in_morning'] ?? '', ENT_QUOTES) . "'
                                    data-am_out='" . htmlspecialchars($row['time_out_morning'] ?? '', ENT_QUOTES) . "'
                                    data-pm_in='" . htmlspecialchars($row['time_in_afternoon'] ?? '', ENT_QUOTES) . "'
                                    data-pm_out='" . htmlspecialchars($row['time_out_afternoon'] ?? '', ENT_QUOTES) . "'
                                    data-late='" . htmlspecialchars($row['late_minutes'] ?? '', ENT_QUOTES) . "'
                                    data-early='" . htmlspecialchars($row['early_out_minutes'] ?? '', ENT_QUOTES) . "'
                                    data-ot='" . htmlspecialchars($row['overtime_hours'] ?? '', ENT_QUOTES) . "'
                                    data-attendance_type='" . htmlspecialchars($row['attendance_type'] ?? '', ENT_QUOTES) . "'
                                    data-status='" . htmlspecialchars($row['status'] ?? '', ENT_QUOTES) . "'
                                    data-is_on_leave='" . htmlspecialchars($row['is_on_leave'] ?? '0', ENT_QUOTES) . "'
                                    data-notes='" . htmlspecialchars($row['notes'] ?? '', ENT_QUOTES) . "'>
                                    <td>{$row['EmployeeID']}</td>
                                    <td>{$row['EmployeeName']}</td>
                                    <td>{$row['Department']}</td>
                                    <td>" . (!empty($row['Shift']) ? formatShiftTime($row['Shift']) : '-') . "</td>
                                    <td>{$date}</td>
                                    <td>" . ((isset($row['total_hours']) && $row['total_hours'] > 0) ? number_format($row['total_hours'], 2) : '-') . "</td>
                                    <td>" . (!empty($row['attendance_type']) ? ucfirst($row['attendance_type']) : '-') . "</td>
                                    <td><span class='status-badge {$statusClass}'>" . (!empty($statusText) ? $statusText : '-') . "</span></td>
                                    <td style='text-align: center;'>{$source}</td>
                                    <td>{$actionButton}</td>
                                </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='11' style='text-align: center; padding: 20px;'>No attendance records found</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
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
    </div>

  <!-- Add Attendance Modal -->
<div id="addAttendanceModal" class="modal">
    <div class="modal-content manual-entry-modal">
        <div class="modal-header">
            <h2><i class="fas fa-user-clock"></i> Manual Attendance Entry</h2>
            <span class="close" onclick="closeAddAttendanceModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="addAttendanceForm" onsubmit="return submitAttendance(event)">
                <div class="form-grid">
                    <div class="form-section employee-section">
                        <h3><i class="fas fa-user"></i> Employee Information</h3>
                            
                        <div class="form-group">
                                <label for="department_filter">Filter by Department</label>
                                <select id="department_filter" class="form-control large-input" onchange="filterEmployeesByDepartment()">
                                    <option value="">All Departments</option>
                                    <?php 
                                    $conn = new mysqli($servername, $username, $password, $dbname);
                                    $dept_query = "SELECT DISTINCT Department FROM empuser WHERE Status = 'active' AND Department IS NOT NULL ORDER BY Department";
                                    $dept_result = $conn->query($dept_query);
                                    
                                    while($dept = $dept_result->fetch_assoc()): ?>
                                        <option value="<?php echo htmlspecialchars($dept['Department']); ?>">
                                            <?php echo htmlspecialchars($dept['Department']); ?>
                                        </option>
                                    <?php endwhile; 
                                    $conn->close();
                                    ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="employee_dropdown">Select Employee *</label>
                                <select id="employee_dropdown" name="employee_id" class="form-control large-input" required onchange="updateEmployeeDetails()">
                                <option value="">Select Employee</option>
                                <?php 
                                $conn = new mysqli($servername, $username, $password, $dbname);
                                    $emp_query = "SELECT EmployeeID, EmployeeName, Department, Position, Shift FROM empuser WHERE Status = 'active' ORDER BY EmployeeName";
                                $emp_result = $conn->query($emp_query);
                                
                                while($emp = $emp_result->fetch_assoc()): ?>
                                        <option value="<?php echo $emp['EmployeeID']; ?>" 
                                                data-dept="<?php echo htmlspecialchars($emp['Department']); ?>" 
                                                data-position="<?php echo htmlspecialchars($emp['Position']); ?>"
                                                data-shift="<?php echo htmlspecialchars($emp['Shift']); ?>">
                                            <?php echo htmlspecialchars($emp['EmployeeName']) . ' (' . htmlspecialchars($emp['EmployeeID']) . ') - ' . htmlspecialchars($emp['Department']); ?>
                                    </option>
                                <?php endwhile; 
                                $conn->close();
                                ?>
                            </select>
                        </div>
                            
                            <div class="form-group">
                                <label for="employee_search">Or Search Employee</label>
                                <div class="employee-search-container">
                                    <input type="text" id="employee_search" class="form-control large-input" placeholder="Type employee name or ID..." autocomplete="off">
                                    <div class="search-results" id="searchResults"></div>
                                </div>
                            </div>
                        
                        <div class="employee-details-card" id="employeeDetails">
                            <div class="detail-item">
                                <span class="detail-label">Department:</span>
                                <span id="detail-dept">-</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Position:</span>
                                <span id="detail-position">-</span>
                            </div>
                                <div class="detail-item">
                                    <span class="detail-label">Shift:</span>
                                    <span id="detail-shift">-</span>
                                </div>
                        </div>
                    </div>
                    
                    <div class="form-section details-section">
                        <h3><i class="fas fa-calendar-alt"></i> Attendance Details</h3>
                        <div class="form-group">
                            <label for="attendance_date">Date *</label>
                            <input type="date" id="attendance_date" name="attendance_date" class="form-control large-input" required 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="status">Status *</label>
                            <select id="status" name="status" class="form-control large-input" required onchange="toggleTimeFields()">
                                <option value="present">Present</option>
                                <option value="late">Late</option>
                                <option value="absent">Absent</option>
                                <option value="halfday">Half Day</option>
                                <option value="leave">On Leave</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-section time-section" id="timeFields">
                    <h3><i class="fas fa-clock"></i> Time Information</h3>
                    <div class="time-grid">
                        <div class="form-group">
                            <label for="am_in">Morning Time In</label>
                            <input type="time" id="am_in" name="am_in" class="form-control large-input">
                        </div>
                        <div class="form-group">
                            <label for="am_out">Morning Time Out</label>
                            <input type="time" id="am_out" name="am_out" class="form-control large-input">
                        </div>
                        <div class="form-group">
                            <label for="pm_in">Afternoon Time In</label>
                            <input type="time" id="pm_in" name="pm_in" class="form-control large-input">
                        </div>
                        <div class="form-group">
                            <label for="pm_out">Afternoon Time Out</label>
                            <input type="time" id="pm_out" name="pm_out" class="form-control large-input">
                        </div>
                    </div>
                    <div style="margin-top:10px;">
                        <button type="button" class="btn btn-secondary" onclick="autoFillDefaultShiftTimes()"><i class="fas fa-magic"></i> Auto-fill Shift Times</button>
                    </div>
                        
                        <div class="overtime-section">
                            <div class="overtime-checkbox-container" onclick="toggleOvertimeCheckbox()">
                                <div class="overtime-checkbox">
                                    <input type="checkbox" id="is_overtime" name="is_overtime" value="1" onchange="toggleOvertimeFields()">
                                    <span class="checkmark"></span>
                                </div>
                                <span class="overtime-label-text">
                                    <i class="fas fa-clock" style="margin-right: 8px; color: #FFC75F;"></i>
                                    Overtime Work
                                </span>
                            </div>
                            
                            <div class="overtime-fields" id="overtimeFields" style="display: none;">
                                <div class="form-group">
                                    <label for="overtime_hours">Overtime Hours</label>
                                    <input type="number" id="overtime_hours" name="overtime_hours" class="form-control large-input" min="0" max="12" step="0.5" placeholder="0.0">
                                </div>
                                
                                <div class="form-group">
                                    <label for="overtime_reason">Overtime Reason</label>
                                    <textarea id="overtime_reason" name="overtime_reason" class="form-control large-textarea" rows="2" placeholder="Reason for overtime work..."></textarea>
                                </div>
                            </div>
                        </div>
                </div>
                
                <div class="form-section notes-section">
                    <h3><i class="fas fa-sticky-note"></i> Additional Information</h3>
                    <div class="form-group">
                        <label for="notes">Notes/Reason</label>
                        <textarea id="notes" name="notes" class="form-control large-textarea" rows="4" placeholder="Reason for manual entry..."></textarea>
                    </div>
                </div>
                
                <!-- Leave Status Section -->
                <div class="form-section leave-section" style="background: #FFF3E0; padding: 15px; border-radius: 8px; border-left: 4px solid #FF9800; margin-bottom: 20px;">
                    <h3 style="color: #E65100; margin-bottom: 15px; font-size: 16px;">
                        <i class="fas fa-calendar-times"></i> Leave Status
                    </h3>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #E65100; font-size: 14px; cursor: pointer;">
                            <input type="checkbox" id="is_on_leave" name="is_on_leave" value="1" style="transform: scale(1.2);" onchange="toggleLeaveStatusAdd()">
                            <i class="fas fa-calendar-times"></i> Set Employee on Leave
                        </label>
                        <small style="color: #6c757d; font-size: 12px; margin-left: 25px;">
                            When checked, employee will be marked as on leave and one leave day will be deducted from their leave balance
                        </small>
                        <div id="leaveBalanceInfoAdd" style="margin-top: 8px; padding: 8px; background: #fff; border-radius: 4px; font-size: 12px; color: #666; display: none;">
                            <div id="leaveBalanceTextAdd">Loading leave balance...</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary large-btn" onclick="closeAddAttendanceModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary large-btn">
                        <i class="fas fa-save"></i> Save Attendance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Recent Activities Modal -->
    <div id="recentActivitiesModal" class="modal">
        <div class="modal-content">
            <div class="modal-header" style="min-height: 56px;">
                <h2><i class="fas fa-clock"></i> Recent Biometric Activities</h2>
                <div class="refresh-indicator" id="refreshIndicator">
                    <div class="pulse-dot"></div>
                    <span>Live Updates</span>
                </div>
                <span class="close" onclick="closeRecentActivitiesModal()">&times;</span>
            </div>
            <div class="modal-body" style="padding: 16px 20px; overflow: auto;">
                <div id="recent-activities-list">
                    <?php if (!empty($recent_activities)): ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-info">
                                    <div class="activity-employee"><?php echo htmlspecialchars($activity['EmployeeName']); ?></div>
                                    <div class="activity-action">
                                        <?php 
                                        $lastAction = !empty($activity['time_out']) ? 'Checked Out' : 'Checked In';
                                        $lastTime = !empty($activity['time_out']) ? $activity['time_out'] : $activity['time_in'];
                                        echo $lastAction . ' • ' . htmlspecialchars($activity['Department']);
                                        ?>
                                    </div>
                                </div>
                                <div class="activity-time">
                                    <?php echo date('h:i A', strtotime($lastTime)); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; color: #666; padding: 20px;">
                            <i class="fas fa-info-circle"></i> No recent activities today
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer" style="padding: 10px 16px;">
                <button type="button" class="btn btn-secondary" onclick="closeRecentActivitiesModal()">Close</button>
                <button type="button" class="btn btn-primary" onclick="refreshRecentActivities()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>
    </div>

    <!-- View Attendance Details Modal -->
    <div id="viewAttendanceModal" class="modal">
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
                <button class="close" onclick="closeViewAttendanceModal()" aria-label="Close" style="background:rgba(255,255,255,0.2); border:none; color:#fff; font-size:20px; cursor:pointer; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; transition:all 0.3s ease;">&times;</button>
            </div>
            <div class="modal-body" id="viewAttendanceBody" style="padding:28px; background:#fafbfc; max-height:70vh; overflow-y:auto; scrollbar-width: thin; scrollbar-color: #3F72AF #f1f1f1;"></div>
            <div class="modal-footer" style="padding:20px 28px; border-top:1px solid #e1e8ed; display:flex; justify-content:flex-end; background:#fff;">
                <button type="button" class="btn btn-secondary" onclick="closeViewAttendanceModal()" style="background:#6c757d; color:#fff; border:none; padding:10px 24px; border-radius:8px; font-weight:500; transition:all 0.3s ease;">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Attendance Modal -->
    <div id="editAttendanceModal" class="modal">
        <div class="modal-content edit-attendance-modal">
            <div class="modal-header">
                <div class="modal-title-section">
                    <h2><i class="fas fa-edit"></i> Edit Attendance</h2>
                    <div class="employee-info-display" id="editEmployeeInfo">
                        <span class="employee-name" id="editEmployeeName">Loading...</span>
                        <span class="employee-details" id="editEmployeeDetails">Department • Shift</span>
                    </div>
                </div>
                <span class="close" onclick="closeEditAttendanceModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editAttendanceForm" onsubmit="return submitEditAttendance(event)">
                    <input type="hidden" id="edit_id" name="id">
                    
                    <!-- Time Tracking Section -->
                    <div class="time-tracking-section">
                        <div class="time-grid">
                            <div class="time-section morning-section">
                                <div class="section-header">
                                    <i class="fas fa-sun"></i>
                                    <h3>Morning</h3>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="autoFillShiftTimes()" style="font-size: 12px; padding: 6px 12px; margin-left: auto;">
                                        <i class="fas fa-magic"></i> Auto-fill Shift Times
                                    </button>
                                </div>
                                <div class="time-inputs">
                                    <div class="time-input-group">
                                        <label for="edit_am_in">Time In</label>
                                        <div class="input-wrapper">
                                            <input type="time" id="edit_am_in" name="am_in" class="form-control time-input">
                                            <button type="button" class="quick-time-btn" onclick="markNow('am_in')" title="Mark current time">
                                                <i class="fas fa-clock"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="time-input-group">
                                        <label for="edit_am_out">Lunch Start</label>
                                        <div class="input-wrapper">
                                            <input type="time" id="edit_am_out" name="am_out" class="form-control time-input">
                                            <button type="button" class="quick-time-btn" onclick="markNow('am_out')" title="Mark current time">
                                                <i class="fas fa-clock"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="time-section afternoon-section">
                                <div class="section-header">
                                    <i class="fas fa-cloud-sun"></i>
                                    <h3>Afternoon</h3>
                                </div>
                                <div class="time-inputs">
                                    <div class="time-input-group">
                                        <label for="edit_pm_in">Lunch End</label>
                                        <div class="input-wrapper">
                                            <input type="time" id="edit_pm_in" name="pm_in" class="form-control time-input">
                                            <button type="button" class="quick-time-btn" onclick="markNow('pm_in')" title="Mark current time">
                                                <i class="fas fa-clock"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="time-input-group">
                                        <label for="edit_pm_out">Time Out</label>
                                        <div class="input-wrapper">
                                            <input type="time" id="edit_pm_out" name="pm_out" class="form-control time-input">
                                            <button type="button" class="quick-time-btn" onclick="markNow('pm_out')" title="Mark current time">
                                                <i class="fas fa-clock"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Override Section -->
                    <div class="override-section">
                        <div class="section-header">
                            <i class="fas fa-cog"></i>
                            <h3>Override Settings</h3>
                        </div>
                        <div class="override-inputs">
                            <div class="form-group">
                                <label for="edit_time_in">Single Time In Override</label>
                                <div class="input-wrapper">
                                    <input type="time" id="edit_time_in" name="time_in" class="form-control time-input">
                                    <button type="button" class="quick-time-btn" onclick="markNow('time_in')" title="Mark current time">
                                        <i class="fas fa-clock"></i>
                                    </button>
                                </div>
                                <small class="form-text">Override the morning time in with a single entry</small>
                            </div>
                            <div class="form-group">
                                <label for="edit_time_out">Single Time Out Override</label>
                                <div class="input-wrapper">
                                    <input type="time" id="edit_time_out" name="time_out" class="form-control time-input">
                                    <button type="button" class="quick-time-btn" onclick="markNow('time_out')" title="Mark current time">
                                        <i class="fas fa-clock"></i>
                                    </button>
                                </div>
                                <small class="form-text">Override the afternoon time out with a single entry</small>
                            </div>
                        </div>
                    </div>

                    <!-- Notes Section -->
                    <div class="notes-section">
                        <div class="form-group">
                            <label for="edit_notes">
                                <i class="fas fa-sticky-note"></i> Notes
                            </label>
                            <textarea id="edit_notes" name="notes" class="form-control notes-textarea" rows="3" placeholder="Add any additional notes or comments..."></textarea>
                        </div>
                    </div>
                    
                    <!-- Leave Status Section -->
                    <div class="leave-section">
                        <div class="leave-toggle">
                            <label class="leave-checkbox-label">
                                <input type="checkbox" id="edit_is_on_leave" name="is_on_leave" value="1" onchange="toggleLeaveStatus()">
                                <span class="checkmark"></span>
                                <div class="leave-content">
                                    <i class="fas fa-calendar-times"></i>
                                    <div class="leave-text">
                                        <span class="leave-title">Set Employee on Leave</span>
                                        <span class="leave-description">Mark as on leave and deduct from leave balance</span>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <div id="leaveBalanceInfo" class="leave-balance-info" style="display: none;">
                            <i class="fas fa-info-circle"></i> 
                            <span id="leaveBalanceText">Loading leave balance...</span>
                        </div>
                    </div>

                    <!-- Half Day Section -->
                    <div class="halfday-section-modal">
                        <div class="section-header">
                            <i class="fas fa-clock"></i>
                            <h3>Half Day Options</h3>
                        </div>
                        <div class="halfday-content">
                            <p class="halfday-description">Mark this attendance as half day and choose the working period:</p>
                            <div class="halfday-buttons-container">
                                <button type="button" class="btn btn-outline-warning halfday-option-btn" onclick="markHalfDay('morning')">
                                    <i class="fas fa-sun"></i> Morning Only
                                    <small>Work until lunch break</small>
                                </button>
                                <button type="button" class="btn btn-outline-warning halfday-option-btn" onclick="markHalfDay('afternoon')">
                                    <i class="fas fa-cloud-sun"></i> Afternoon Only
                                    <small>Work after lunch break</small>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <div class="footer-actions">
                    <div class="action-group primary-actions">
                        <button type="button" class="btn btn-primary save-btn" onclick="document.getElementById('editAttendanceForm').dispatchEvent(new Event('submit'))">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                    <div class="action-group secondary-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeEditAttendanceModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <?php 
    // Provide a full active employees list to ensure bulk edit can show everyone even if absent
    try {
        $__conn = new mysqli($servername, $username, $password, $dbname);
        if ($__conn && !$__conn->connect_error) {
            $__conn->query("SET time_zone = '+08:00'");
            $__emps = [];
            $__res = $__conn->query("SELECT EmployeeID, EmployeeName, Department, Shift FROM empuser WHERE Status='active' ORDER BY EmployeeName");
            if ($__res) {
                while ($__r = $__res->fetch_assoc()) { $__emps[] = $__r; }
            }
            $__conn->close();
            echo '<script>window.allActiveEmployees = ' . json_encode($__emps) . ';</script>';
        }
    } catch (Exception $__e) { /* silent fallback */ }
    ?>

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
        const departmentSelect = document.getElementById('department-filter-hr');
        const shiftSelect = document.getElementById('shift-filter-hr');
        const statusSelect = document.getElementById('status-filter-hr');
        const typeSelect = document.getElementById('type-filter-hr');
        const noTimeoutSelect = document.getElementById('no-timeout-filter');
        const totalPresentEl = document.getElementById('total-present-value');
        const stillPresentEl = document.getElementById('still-present-value');
        const lateArrivalsEl = document.getElementById('late-arrivals-value');
        const earlyInEl = document.getElementById('early-in-value');
        const absentEl = document.getElementById('absent-value');
        const lastUpdatedEl = document.getElementById('lastUpdated');
        const refreshIconEl = document.getElementById('tableRefreshIcon');
        const recentActivitiesList = document.getElementById('recent-activities-list');

        // Auto refresh management
        let autoRefreshEnabled = true;
        let refreshInterval;
        let filterDebounceTimeout;

        // ---- Auto Refresh Functions ----
        function toggleAutoRefresh() {
            const toggle = document.getElementById('autoRefreshToggle');
            autoRefreshEnabled = !autoRefreshEnabled;
            
            if (autoRefreshEnabled) {
                toggle.classList.add('active');
                startAutoRefresh();
            } else {
                toggle.classList.remove('active');
                stopAutoRefresh();
            }
        }

        function startAutoRefresh() {
            if (refreshInterval) clearInterval(refreshInterval);
            // Smooth periodic refresh (every 60s) to avoid noticeable flicker
            refreshInterval = setInterval(() => {
                refreshAttendanceData();
            }, 60000);
        }

        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
                refreshInterval = null;
            }
        }

        function debouncedRefreshAttendanceData() {
            // Clear existing timeout
            if (filterDebounceTimeout) {
                clearTimeout(filterDebounceTimeout);
            }
            
            // Set new timeout - reduced for faster response
            filterDebounceTimeout = setTimeout(() => {
                refreshAttendanceData();
            }, 150); // 150ms debounce for faster response
        }

        async function refreshAttendanceData() {
            try {
                // Show loading state
                refreshIconEl.style.animation = 'spin 1s linear infinite';
                refreshIconEl.style.color = '#3F72AF';
                
                // Add loading indicator to table
                const tbody = document.querySelector('#attendance-results');
                if (tbody) {
                    tbody.style.opacity = '0.6';
                    tbody.style.transition = 'opacity 0.3s ease';
                }
                
                const params = getCurrentFilters();
                const queryString = new URLSearchParams(params).toString();
                
                const response = await fetch(`HRAttendance.php?${queryString}&ajax=1`);
                const data = await response.json();
                
                if (data.success) {
                    // Update statistics with animation
                    animateValueChange(totalPresentEl, data.stats.total_present || 0);
                    animateValueChange(stillPresentEl, data.stats.still_present || 0);
                    animateValueChange(lateArrivalsEl, data.stats.late_arrivals || 0);
                    animateValueChange(earlyInEl, data.stats.early_in || 0);
                    animateValueChange(absentEl, data.stats.absent || 0);
                    
                    // Update table with smooth transition
                    updateAttendanceTable(data.records);
                    
                    // If there's a search term, move matching employees to top
                    if (data.search_term && data.search_term.length >= 2) {
                        moveSearchedEmployeeToTop(data.search_term, data.records);
                    }
                    
                    // Update recent activities
                    updateRecentActivities(data.recent_activities);
                    
                    // Update timestamp
                    lastUpdatedEl.textContent = new Date().toLocaleTimeString();
                    
                    // Suppress popup feedback on filter application
                } else {
                    // Suppress popup feedback on errors during auto-refresh
                }
            } catch (error) {
                console.error('Refresh failed:', error);
                // Suppress popup feedback on errors during auto-refresh
            } finally {
                // Remove loading state
                refreshIconEl.style.animation = '';
                refreshIconEl.style.color = '#27ae60';
                
                // Restore table opacity
                const tbody = document.querySelector('#attendance-results');
                if (tbody) {
                    tbody.style.opacity = '1';
                }
            }
        }

        function updateAttendanceTable(records) {
            const tbody = document.querySelector('#attendance-results');
            
            if (!records || records.length === 0) {
                tbody.innerHTML = "<tr><td colspan='13' style='text-align: center; padding: 20px;'>No attendance records found</td></tr>";
                return;
            }

            let html = '';
            records.forEach(record => {
                const statusClass = record.is_on_leave == 1 ? 'status-on-leave' : ('status-' + (record.status || 'present'));
                const statusText = record.is_on_leave == 1 ? 'ON-LEAVE' : (record.status === 'half_day' ? 'Half Day' : (record.status ? record.status.charAt(0).toUpperCase() + record.status.slice(1) : 'Present'));
                const timeIn = record.time_in ? new Date('1970-01-01 ' + record.time_in).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '-';
                const timeOut = record.time_out ? new Date('1970-01-01 ' + record.time_out).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : 'Not yet';
                const date = new Date(record.attendance_date).toLocaleDateString('en-US', {
                    month: 'short',
                    day: '2-digit',
                    year: 'numeric'
                });
                
                let duration = 'In Progress';
                // Prefer AM/PM segments when present
                const hasAmSeg = (record.time_in_morning || record.time_out_morning);
                const hasPmSeg = (record.time_in_afternoon || record.time_out_afternoon);
                if ((hasAmSeg || hasPmSeg)) {
                    const toTs = (t) => t ? new Date('1970-01-01T' + (t.length === 5 ? t+':00' : t)) : null;
                    let workedMs = 0;
                    if (record.time_in_morning && record.time_out_morning) {
                        const ain = toTs(record.time_in_morning);
                        const aout = toTs(record.time_out_morning);
                        if (ain && aout && aout > ain) workedMs += (aout - ain);
                    }
                    if (record.time_in_afternoon && record.time_out_afternoon) {
                        const pin = toTs(record.time_in_afternoon);
                        const pout = toTs(record.time_out_afternoon);
                        if (pin && pout && pout > pin) workedMs += (pout - pin);
                    }
                    const hours = Math.floor(workedMs / (1000 * 60 * 60));
                    const minutes = Math.floor((workedMs % (1000 * 60 * 60)) / (1000 * 60));
                    duration = `${hours}h ${minutes.toString().padStart(2, '0')}m`;
                } else if (record.time_in && record.time_out) {
                    // Lunch-excluded duration estimation on client
                    const toDate = (t) => new Date('1970-01-01T' + t);
                    const start = toDate(record.time_in);
                    const end = toDate(record.time_out);
                    let workedMs = Math.max(0, end - start);
                    const shift = record.Shift || record.shift || '';
                    const lunchByShift = {
                        '08:00-17:00': ['12:00', '13:00'],
                        '08:00-17:00pb': ['12:00', '13:00'],
                        '8-5pb': ['12:00', '13:00'],
                        '08:30-17:30': ['12:30', '13:30'],
                        '8:30-5:30pm': ['12:30', '13:30'],
                        '09:00-18:00': ['13:00', '14:00'],
                        '9am-6pm': ['13:00', '14:00'],
                    };
                    if (lunchByShift[shift]) {
                        const [ls, le] = lunchByShift[shift];
                        const lunchStart = new Date('1970-01-01T' + ls + ':00');
                        const lunchEnd = new Date('1970-01-01T' + le + ':00');
                        const overlap = Math.max(0, Math.min(end, lunchEnd) - Math.max(start, lunchStart));
                        workedMs -= overlap;
                    }
                    const hours = Math.floor(workedMs / (1000 * 60 * 60));
                    const minutes = Math.floor((workedMs % (1000 * 60 * 60)) / (1000 * 60));
                    duration = `${hours}h ${minutes.toString().padStart(2, '0')}m`;
                }
                
                // Determine source based on data_source field
                const dataSource = record.data_source || 'biometric';
                let source;
                switch(dataSource) {
                    case 'manual':
                        source = '<i class="fas fa-edit text-primary" title="Manual Entry (Web App)"></i>';
                        break;
                    case 'bulk_edit':
                        source = '<i class="fas fa-users-cog text-warning" title="Bulk Edit (Web App)"></i>';
                        break;
                    case 'biometric':
                    default:
                        source = '<i class="fas fa-fingerprint text-success" title="Biometric Scanner (ZKteco Device)"></i>';
                        break;
                }
                
                const isNoTimeout = !record.time_out;
                html += `<tr class="clickable-row" data-id="${record.id}"
                        data-employee_id="${record.EmployeeID || ''}"
                        data-employee_name="${record.EmployeeName || ''}"
                        data-department="${record.Department || ''}"
                        data-shift="${record.Shift || ''}"
                        data-source="${record.data_source || 'biometric'}"
                        data-date="${record.attendance_date || ''}"
                        data-time_in="${record.time_in || ''}"
                        data-time_out="${record.time_out || ''}"
                        data-am_in="${record.time_in_morning || ''}"
                        data-am_out="${record.time_out_morning || ''}"
                        data-pm_in="${record.time_in_afternoon || ''}"
                        data-pm_out="${record.time_out_afternoon || ''}"
                        data-late="${record.late_minutes || 0}"
                        data-early="${record.early_out_minutes || 0}"
                        data-ot="${record.overtime_hours || 0}"
                        data-attendance_type="${record.attendance_type || ''}"
                        data-status="${record.status || ''}"
                        data-notes="${record.notes || ''}">
                    <td>${record.EmployeeID}</td>
                    <td>${record.EmployeeName}</td>
                    <td>${record.Department}</td>
                    <td>${formatShiftTimeJS(record.Shift || '')}</td>
                    <td>${date}</td>
                    <td>${record.total_hours && parseFloat(record.total_hours) > 0 ? parseFloat(record.total_hours).toFixed(2) : '-'}</td>
                    <td>${(record.attendance_type || '').charAt(0).toUpperCase() + (record.attendance_type || '').slice(1)}</td>
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                    <td style="text-align: center;">${source}</td>
                    <td>
                        ${record.attendance_type === 'absent'
                            ? `<button class="btn action" onclick="event.stopPropagation(); openAddAttendanceModalWithEmployee('${record.EmployeeID}', '${record.EmployeeName}', '${record.Department}', '${record.Shift || ''}', '${record.attendance_date}')"><i class="fas fa-plus"></i> Add</button>`
                            : !record.time_out 
                                ? `<button class="btn action" onclick="event.stopPropagation(); markTimeOut('${record.id}', this)"><i class="fas fa-sign-out-alt"></i> Mark Out</button>`
                                : `<button class="btn action" onclick="event.stopPropagation(); openEditAttendance('${record.id}')"><i class="fas fa-edit"></i> Edit</button>`}
                    </td>
                </tr>`;
            });
            
            tbody.innerHTML = html;
            
            // Debug: Log the number of records received
            console.log(`Loaded ${records.length} records into table`);
            
            // Re-initialize pagination after table update
            if (typeof initializePagination === 'function') {
                initializePagination();
            }
            
            // Reload employees for search functionality
            loadAllEmployees();
        }

        function updateRecentActivities(activities) {
            if (!activities || activities.length === 0) {
                recentActivitiesList.innerHTML = `
                    <div style="text-align: center; color: #666; padding: 20px;">
                        <i class="fas fa-info-circle"></i> No recent activities today
                    </div>`;
                return;
            }

            let html = '';
            activities.forEach(activity => {
                const lastAction = activity.time_out ? 'Checked Out' : 'Checked In';
                const lastTime = activity.time_out || activity.time_in;
                const timeFormatted = new Date('1970-01-01 ' + lastTime).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', hour12: true});
                
                html += `
                    <div class="activity-item">
                        <div class="activity-info">
                            <div class="activity-employee">${activity.EmployeeName}</div>
                            <div class="activity-action">${lastAction} • ${activity.Department}</div>
                        </div>
                        <div class="activity-time">${timeFormatted}</div>
                    </div>`;
            });
            
            recentActivitiesList.innerHTML = html;
        }

        function animateValueChange(element, newValue) {
            if (!element) return;
            
            const currentValue = parseInt(element.textContent) || 0;
            const difference = newValue - currentValue;
            const duration = 500; // 500ms animation
            const steps = 20;
            const stepDuration = duration / steps;
            const stepValue = difference / steps;
            
            let currentStep = 0;
            const timer = setInterval(() => {
                currentStep++;
                const animatedValue = Math.round(currentValue + (stepValue * currentStep));
                element.textContent = animatedValue;
                
                // Add color animation
                if (difference > 0) {
                    element.style.color = '#16C79A';
                } else if (difference < 0) {
                    element.style.color = '#FF6B6B';
                }
                
                if (currentStep >= steps) {
                    clearInterval(timer);
                    element.textContent = newValue;
                    // Reset color after animation
                    setTimeout(() => {
                        element.style.color = '';
                    }, 1000);
                }
            }, stepDuration);
        }

        function showFilterFeedback(message, type = 'info') {
            // Remove any existing feedback
            const existingFeedback = document.getElementById('filter-feedback');
            if (existingFeedback) {
                existingFeedback.remove();
            }
            
            // Create feedback element
            const feedback = document.createElement('div');
            feedback.id = 'filter-feedback';
            feedback.className = `filter-feedback filter-feedback-${type}`;
            
            // Set styles
            feedback.style.cssText = `
                position: fixed;
                top: 80px;
                right: 20px;
                padding: 12px 20px;
                border-radius: 8px;
                color: white;
                font-weight: 500;
                z-index: 10000;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                font-size: 14px;
                display: flex;
                align-items: center;
                gap: 8px;
                animation: slideInRight 0.3s ease;
                max-width: 300px;
            `;
            
            // Set background based on type
            let bgColor, iconClass;
            switch(type) {
                case 'success':
                    bgColor = '#16C79A';
                    iconClass = 'fa-check-circle';
                    break;
                case 'error':
                    bgColor = '#FF6B6B';
                    iconClass = 'fa-exclamation-circle';
                    break;
                default:
                    bgColor = '#3F72AF';
                    iconClass = 'fa-info-circle';
            }
            
            feedback.style.background = bgColor;
            feedback.innerHTML = `
                <i class="fas ${iconClass}" style="font-size: 16px;"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(feedback);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                if (feedback.parentNode) {
                    feedback.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => feedback.remove(), 300);
                }
            }, 3000);
        }

        // ---- Export Function ----
        function exportAttendance() {
            const params = getCurrentFilters();
            const queryString = new URLSearchParams(params).toString();
            window.open(`export_attendance.php?${queryString}`, '_blank');
        }

        // ---- Modal Functions ----
        function openAddAttendanceModal() {
            const modal = document.getElementById('addAttendanceModal');
            if (modal) {
                modal.classList.add('show');
                modal.style.display = 'flex';
                
                // Reset form and update details
                const form = document.getElementById('addAttendanceForm');
                form.reset();
                
                // Reset search functionality
                document.getElementById('employee_search').value = '';
                document.getElementById('department_filter').value = '';
                document.getElementById('employee_dropdown').value = '';
                hideSearchResults();
                
                // Set default date to today
                document.getElementById('attendance_date').value = new Date().toISOString().split('T')[0];
                
                // Reset employee details
                updateEmployeeDetails();
                toggleTimeFields();
                toggleOvertimeFields();
                
                // Reset leave status
                document.getElementById('is_on_leave').checked = false;
                document.getElementById('leaveBalanceInfoAdd').style.display = 'none';
                
                // Add modal animation
                const modalContent = document.querySelector('.manual-entry-modal');
                modalContent.style.animation = 'modalSlideIn 0.4s ease-out';
                
                // Focus on department filter after animation
                setTimeout(() => {
                    document.getElementById('department_filter').focus();
                }, 400);
                
                // Add body class to prevent scrolling
                document.body.style.overflow = 'hidden';
            }
        }
        
        function openAddAttendanceModalWithEmployee(employeeId, employeeName, department, shift, date) {
            const modal = document.getElementById('addAttendanceModal');
            if (modal) {
                modal.classList.add('show');
                modal.style.display = 'flex';
                
                // Reset form
                const form = document.getElementById('addAttendanceForm');
                form.reset();
                
                // Pre-fill employee information
                document.getElementById('department_filter').value = department || '';
                
                // Filter employees by department first
                filterEmployeesByDepartment();
                
                // Small delay to ensure dropdown is populated
                setTimeout(() => {
                    const employeeDropdown = document.getElementById('employee_dropdown');
                    if (employeeDropdown) {
                        employeeDropdown.value = employeeId;
                        // Trigger change event to update employee details
                        updateEmployeeDetails();
                    }
                    
                    // Set the date
                    if (date) {
                        document.getElementById('attendance_date').value = date;
                    } else {
                        document.getElementById('attendance_date').value = new Date().toISOString().split('T')[0];
                    }
                    
                    // Set status to present by default
                    document.getElementById('status').value = 'present';
                    toggleTimeFields();
                    toggleOvertimeFields();
                    
                    // Reset leave status
                    document.getElementById('is_on_leave').checked = false;
                    document.getElementById('leaveBalanceInfoAdd').style.display = 'none';
                }, 100);
                
                // Add modal animation
                const modalContent = document.querySelector('.manual-entry-modal');
                modalContent.style.animation = 'modalSlideIn 0.4s ease-out';
                
                // Focus on status field after animation (since employee is pre-selected)
                setTimeout(() => {
                    document.getElementById('status').focus();
                }, 500);
                
                // Add body class to prevent scrolling
                document.body.style.overflow = 'hidden';
            }
        }

        function closeAddAttendanceModal() {
            const modal = document.getElementById('addAttendanceModal');
            if (modal) {
                const modalContent = document.querySelector('.manual-entry-modal');
                
                // Add closing animation
                modalContent.style.animation = 'fadeOut 0.3s ease';
                
                setTimeout(() => {
                    modal.classList.remove('show');
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }, 300);
            }
        }

        function openRecentActivitiesModal() {
            const modal = document.getElementById('recentActivitiesModal');
            if(modal) modal.style.display = 'block';
        }

        function closeRecentActivitiesModal() {
            const modal = document.getElementById('recentActivitiesModal');
            if(modal) modal.style.display = 'none';
        }

        function refreshRecentActivities() {
            // Refresh the recent activities data
            refreshAttendanceData();
        }

        // Employee search functionality
        let allEmployees = [];
        let searchTimeout;
        let mainSearchTimeout;

        // Load all employees on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadAllEmployees();
            setupEmployeeSearch();
        });

        function loadAllEmployees() {
            // Extract employee data from the dropdown options
            const employeeOptions = document.querySelectorAll('#employee_dropdown option[data-dept]');
            if (employeeOptions.length > 0) {
                allEmployees = Array.from(employeeOptions).map(option => ({
                    EmployeeID: option.value,
                    EmployeeName: option.textContent.split(' (')[0],
                    Department: option.getAttribute('data-dept'),
                    Position: option.getAttribute('data-position'),
                    Shift: option.getAttribute('data-shift') || ''
                }));
            } else {
                // Fallback: extract from main table rows
                const tableRows = document.querySelectorAll('#attendance-results tr[data-employee_id]');
                allEmployees = Array.from(tableRows).map(row => ({
                    EmployeeID: row.getAttribute('data-employee_id'),
                    EmployeeName: row.querySelector('.employee-name')?.textContent || '',
                    Department: row.querySelector('.department')?.textContent || '',
                    Position: '',
                    Shift: row.querySelector('.shift')?.textContent || ''
                }));
            }
            console.log('Loaded employees:', allEmployees.length);
        }

        function setupEmployeeSearch() {
            const employeeSearchInput = document.getElementById('employee_search');
            if (employeeSearchInput) {
                employeeSearchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        searchEmployees(this.value);
                    }, 300);
                });

                // Hide search results when clicking outside
                document.addEventListener('click', function(event) {
                    if (!event.target.closest('.employee-search-container')) {
                        hideSearchResults();
                    }
                });
            }
        }

        function filterEmployeesByDepartment() {
            const departmentFilter = document.getElementById('department_filter');
            const employeeDropdown = document.getElementById('employee_dropdown');
            const selectedDept = departmentFilter.value;
            
            // Reset dropdown
            employeeDropdown.innerHTML = '<option value="">Select Employee</option>';
            
            // Filter and populate dropdown
            allEmployees.forEach(emp => {
                if (!selectedDept || emp.Department === selectedDept) {
                    const option = document.createElement('option');
                    option.value = emp.EmployeeID;
                    option.setAttribute('data-dept', emp.Department);
                    option.setAttribute('data-position', emp.Position);
                    option.setAttribute('data-shift', emp.Shift);
                    option.textContent = `${emp.EmployeeName} (${emp.EmployeeID}) - ${emp.Department}`;
                    employeeDropdown.appendChild(option);
                }
            });
            
            // Clear search and employee details
            document.getElementById('employee_search').value = '';
            hideSearchResults();
            // Don't auto-select - let the calling function handle employee selection
            updateEmployeeDetails();
        }

        function searchEmployees(query) {
            if (!query || query.length < 2) {
                hideSearchResults();
                return;
            }

            const departmentFilter = document.getElementById('department_filter').value;
            
            const filtered = allEmployees.filter(emp => {
                const matchesSearch = emp.EmployeeName.toLowerCase().includes(query.toLowerCase()) ||
                                    emp.EmployeeID.toLowerCase().includes(query.toLowerCase()) ||
                                    emp.Department.toLowerCase().includes(query.toLowerCase());
                
                const matchesDepartment = !departmentFilter || emp.Department === departmentFilter;
                
                return matchesSearch && matchesDepartment;
            });

            displaySearchResults(filtered);
        }

        function searchEmployeesMain(query) {
            if (!query || query.length < 2) {
                hideMainSearchResults();
                return;
            }

            // Show loading state
            const searchResults = document.getElementById('searchResultsMain');
            searchResults.innerHTML = '<div class="search-loading">Searching...</div>';
            searchResults.classList.add('show');

            // Use the existing allEmployees array for search
            const departmentFilter = document.getElementById('department-filter-hr').value;
            
            const filtered = allEmployees.filter(emp => {
                const matchesSearch = emp.EmployeeName.toLowerCase().includes(query.toLowerCase()) ||
                                    emp.EmployeeID.toLowerCase().includes(query.toLowerCase()) ||
                                    emp.Department.toLowerCase().includes(query.toLowerCase());
                
                const matchesDepartment = !departmentFilter || emp.Department === departmentFilter;
                
                return matchesSearch && matchesDepartment;
            });

            if (filtered.length > 0) {
                displayMainSearchResults(filtered);
            } else {
                searchResults.innerHTML = '<div class="no-results">No employees found</div>';
            }
        }

        function displayMainSearchResults(employees) {
            const resultsContainer = document.getElementById('searchResultsMain');
            
            if (employees.length === 0) {
                resultsContainer.innerHTML = '<div class="search-result-item-main" style="color: #666; font-style: italic;">No employees found</div>';
            } else {
                resultsContainer.innerHTML = employees.map(emp => `
                    <div class="search-result-item-main" onclick="selectEmployeeMain('${emp.EmployeeID}', '${emp.EmployeeName}')">
                        <div class="search-result-info-main">
                            <div class="search-result-name-main">${emp.EmployeeName}</div>
                            <div class="search-result-details-main">${emp.Department} • ${emp.Shift || 'N/A'}</div>
                        </div>
                        <div class="search-result-id-main">${emp.EmployeeID}</div>
                    </div>
                `).join('');
            }
            
            resultsContainer.classList.add('show');
        }

        function hideMainSearchResults() {
            const resultsContainer = document.getElementById('searchResultsMain');
            resultsContainer.classList.remove('show');
        }

        function selectEmployeeMain(id, name) {
            // Clear the search input
            document.getElementById('employee-search-main').value = '';
            hideMainSearchResults();
            
            // Hide clear button
            const clearBtn = document.querySelector('.clear-search-btn');
            if (clearBtn) {
                clearBtn.classList.remove('show');
            }
            
            // Reorder table to show searched employee at top
            reorderTableByEmployee(id, name);
        }

        function reorderTableByEmployee(employeeId, employeeName) {
            const tbody = document.querySelector('#attendance-results');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Find the employee row
            const employeeRow = rows.find(row => row.getAttribute('data-employee_id') === employeeId);
            
            if (employeeRow) {
                // Remove the employee row from its current position
                employeeRow.remove();
                
                // Add it to the top of the table
                tbody.insertBefore(employeeRow, tbody.firstChild);
                
                // Add visual highlight
                employeeRow.style.backgroundColor = '#e8f5e8';
                employeeRow.style.borderLeft = '4px solid #16C79A';
                employeeRow.style.transition = 'all 0.3s ease';
                
                // Scroll to top to show the employee
                tbody.scrollIntoView({ behavior: 'smooth', block: 'start' });
                
                // Show success message
                showFilterFeedback(`${employeeName} moved to top of list`, 'success');
                
                // Add a temporary indicator in the table header
                addTopEmployeeIndicator(employeeName);
                
                // Remove highlight after 5 seconds
                setTimeout(() => {
                    employeeRow.style.backgroundColor = '';
                    employeeRow.style.borderLeft = '';
                }, 5000);
                
                // Add a subtle animation to draw attention
                employeeRow.style.transform = 'scale(1.02)';
                setTimeout(() => {
                    employeeRow.style.transform = 'scale(1)';
                }, 300);
                
            } else {
                // If employee not found in current data, fetch fresh data
                fetchEmployeeAttendance(employeeId, employeeName);
            }
        }

        async function fetchEmployeeAttendance(employeeId, employeeName) {
            try {
                // Show loading state
                showFilterFeedback(`Searching for ${employeeName}...`, 'info');
                
                // Fetch fresh attendance data
                const response = await fetch(`HRAttendance.php?ajax=1`);
                const data = await response.json();
                
                if (data.success && data.records) {
                    // Find the employee in the fresh data
                    const employeeRecord = data.records.find(record => record.EmployeeID === employeeId);
                    
                    if (employeeRecord) {
                        // Update the table with fresh data
                        updateAttendanceTable(data.records);
                        
                        // Wait for table to update, then reorder
                        setTimeout(() => {
                            reorderTableByEmployee(employeeId, employeeName);
                        }, 100);
                    } else {
                        showFilterFeedback(`No attendance record found for ${employeeName} today`, 'warning');
                    }
                } else {
                    showFilterFeedback(`Error loading attendance data`, 'error');
                }
            } catch (error) {
                console.error('Error fetching employee attendance:', error);
                showFilterFeedback(`Error searching for ${employeeName}`, 'error');
            }
        }

        function displaySearchResults(employees) {
            const resultsContainer = document.getElementById('searchResults');
            
            if (employees.length === 0) {
                resultsContainer.innerHTML = '<div class="search-result-item" style="color: #666; font-style: italic;">No employees found</div>';
            } else {
                resultsContainer.innerHTML = '';
                employees.forEach(emp => {
                    const resultItem = document.createElement('div');
                    resultItem.className = 'search-result-item';
                    resultItem.innerHTML = `
                        <div class="search-result-info">
                            <div class="search-result-name">${emp.EmployeeName}</div>
                            <div class="search-result-details">${emp.Department} • ${emp.Position}</div>
                        </div>
                        <div class="search-result-id">${emp.EmployeeID}</div>
                    `;
                    resultItem.addEventListener('click', () => {
                        selectEmployee(emp.EmployeeID, emp.EmployeeName, emp.Department, emp.Position, emp.Shift || '');
                    });
                    resultsContainer.appendChild(resultItem);
                });
            }
            
            resultsContainer.classList.add('show');
        }

        function hideSearchResults() {
            const resultsContainer = document.getElementById('searchResults');
            resultsContainer.classList.remove('show');
        }

        function selectEmployee(id, name, department, position, shift) {
            const dropdown = document.getElementById('employee_dropdown');
            let opt = Array.from(dropdown.options).find(o => o.value === String(id));
            if (!opt) {
                // Ensure option exists even if filtered out by department
                opt = document.createElement('option');
                opt.value = id;
                opt.setAttribute('data-dept', department || '');
                opt.setAttribute('data-position', position || '');
                opt.setAttribute('data-shift', shift || '');
                opt.textContent = `${name} (${id}) - ${department || ''}`;
                dropdown.appendChild(opt);
            } else {
                opt.setAttribute('data-dept', department || opt.getAttribute('data-dept') || '');
                opt.setAttribute('data-position', position || opt.getAttribute('data-position') || '');
                opt.setAttribute('data-shift', shift || opt.getAttribute('data-shift') || '');
                opt.textContent = `${name} (${id}) - ${department || opt.getAttribute('data-dept') || ''}`;
            }
            dropdown.value = id;
            
            // Clear search input and hide results
            document.getElementById('employee_search').value = '';
            hideSearchResults();
            
            // Update details and default times using provided metadata
            updateEmployeeDetails(id, name, department, position, shift);
        }

function updateEmployeeDetails(id = null, name = null, department = null, position = null, shiftProvided = null) {
            const employeeDropdown = document.getElementById('employee_dropdown');
    const employeeDetails = document.getElementById('employeeDetails');
    const deptElement = document.getElementById('detail-dept');
    const positionElement = document.getElementById('detail-position');
            const shiftElement = document.getElementById('detail-shift');
    
            if (employeeDropdown.value || id) {
                const selectedOption = employeeDropdown.options[employeeDropdown.selectedIndex];
        const deptVal = department || (selectedOption ? selectedOption.getAttribute('data-dept') : '');
        const posVal = position || (selectedOption ? selectedOption.getAttribute('data-position') : '');
                const shift = shiftProvided || (selectedOption ? selectedOption.getAttribute('data-shift') : '');
        
        deptElement.textContent = deptVal || '-';
        positionElement.textContent = posVal || '-';
                shiftElement.textContent = shift || '-';
                
                // Set default times based on shift
                setDefaultTimesByShift(shift);
                
                // Fetch leave balance if on leave is checked
                if (document.getElementById('is_on_leave').checked) {
                    fetchLeaveBalanceAdd();
                }
        
        // Show with animation
        employeeDetails.style.display = 'block';
        employeeDetails.style.animation = 'fadeInUp 0.4s ease';
    } else {
        employeeDetails.style.display = 'none';
    }
}

        function setDefaultTimesByShift(shift) {
            const amIn = document.getElementById('am_in');
            const amOut = document.getElementById('am_out');
            const pmIn = document.getElementById('pm_in');
            const pmOut = document.getElementById('pm_out');
            const overallIn = document.getElementById('time_in');
            const overallOut = document.getElementById('time_out');
            
            if (!shift) return;
            
            // Set default times based on shift
            switch(shift) {
                case '08:00-17:00':
                case '08:00-17:00pb':
                case '8-5pb':
                    amIn.value = '08:00'; amOut.value = '12:00';
                    pmIn.value = '13:00'; pmOut.value = '17:00';
                    break;
                case '08:30-17:30':
                case '8:30-5:30pm':
                    amIn.value = '08:30'; amOut.value = '12:30';
                    pmIn.value = '13:30'; pmOut.value = '17:30';
                    break;
                case '09:00-18:00':
                case '9am-6pm':
                    amIn.value = '09:00'; amOut.value = '13:00';
                    pmIn.value = '14:00'; pmOut.value = '18:00';
                    break;
                default:
                    amIn.value = '09:00'; amOut.value = '12:00';
                    pmIn.value = '13:00'; pmOut.value = '17:00';
            }
            // Set overall from split
            overallIn.value = amIn.value;
            overallOut.value = pmOut.value;
        }

        function autoFillDefaultShiftTimes() {
            const shiftElement = document.getElementById('detail-shift');
            const shift = shiftElement ? shiftElement.textContent : '';
            setDefaultTimesByShift(shift);
        }

function toggleTimeFields() {
    const statusSelect = document.getElementById('status');
    const timeFields = document.getElementById('timeFields');
    const timeInInput = document.getElementById('time_in');
    const timeOutInput = document.getElementById('time_out');
    
    if (statusSelect.value === 'absent' || statusSelect.value === 'leave') {
        timeFields.classList.remove('visible');
        timeFields.classList.add('hidden');
        
        setTimeout(() => {
            timeFields.style.display = 'none';
        }, 300);
        
        timeInInput.removeAttribute('required');
        timeOutInput.removeAttribute('required');
        timeInInput.value = '';
        timeOutInput.value = '';
                
                // Hide overtime fields for absent/leave
                document.getElementById('overtimeFields').style.display = 'none';
                document.getElementById('is_overtime').checked = false;
    } else {
        timeFields.style.display = 'block';
        timeFields.classList.remove('hidden');
        timeFields.classList.add('visible');
        
        timeInInput.setAttribute('required', 'true');
        timeOutInput.setAttribute('required', 'true');
        
        // Set default times if empty
        if (!timeInInput.value) timeInInput.value = '09:00';
        if (!timeOutInput.value) timeOutInput.value = '17:00';
    }
}

        function toggleOvertimeCheckbox() {
            const checkbox = document.getElementById('is_overtime');
            checkbox.checked = !checkbox.checked;
            toggleOvertimeFields();
        }

        function toggleOvertimeFields() {
            const isOvertimeCheckbox = document.getElementById('is_overtime');
            const overtimeFields = document.getElementById('overtimeFields');
            const overtimeHoursInput = document.getElementById('overtime_hours');
            
            if (isOvertimeCheckbox.checked) {
                overtimeFields.classList.add('show');
                overtimeFields.style.display = 'block';
                overtimeFields.style.animation = 'fadeInUp 0.3s ease';
                overtimeHoursInput.setAttribute('required', 'true');
            } else {
                overtimeFields.classList.remove('show');
                overtimeFields.style.display = 'none';
                overtimeHoursInput.removeAttribute('required');
                overtimeHoursInput.value = '';
                document.getElementById('overtime_reason').value = '';
    }
}

// Leave status functionality for Add Attendance modal
function toggleLeaveStatusAdd() {
    const leaveCheckbox = document.getElementById('is_on_leave');
    const leaveBalanceInfo = document.getElementById('leaveBalanceInfoAdd');
    const leaveBalanceText = document.getElementById('leaveBalanceTextAdd');
    
    if (leaveCheckbox.checked) {
        // Show leave balance info
        leaveBalanceInfo.style.display = 'block';
        fetchLeaveBalanceAdd();
        
        // Clear all time inputs when on leave is checked
        document.getElementById('am_in').value = '';
        document.getElementById('am_out').value = '';
        document.getElementById('pm_in').value = '';
        document.getElementById('pm_out').value = '';
        
        // Set status to leave
        document.getElementById('status').value = 'leave';
        
        // Hide time fields
        const timeFields = document.getElementById('timeFields');
        timeFields.classList.remove('visible');
        timeFields.classList.add('hidden');
        setTimeout(() => {
            timeFields.style.display = 'none';
        }, 300);
        
        // Clear overtime fields
        document.getElementById('overtime_hours').value = '';
        document.getElementById('is_overtime').checked = false;
        document.getElementById('overtimeFields').style.display = 'none';
    } else {
        // Hide leave balance info
        leaveBalanceInfo.style.display = 'none';
        
        // Show time fields
        const timeFields = document.getElementById('timeFields');
        timeFields.style.display = 'block';
        timeFields.classList.remove('hidden');
        timeFields.classList.add('visible');
    }
}

function fetchLeaveBalanceAdd() {
    const employeeId = document.getElementById('employee_dropdown').value;
    if (!employeeId) {
        document.getElementById('leaveBalanceTextAdd').textContent = 'Please select an employee first';
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
            document.getElementById('leaveBalanceTextAdd').innerHTML = 
                `<span style="color: ${color}; font-weight: bold;">${balance} leave days ${status}</span>`;
            
            // Disable checkbox if no leave days remaining
            const checkbox = document.getElementById('is_on_leave');
            if (balance <= 0) {
                checkbox.checked = false;
                checkbox.disabled = true;
                document.getElementById('leaveBalanceInfoAdd').style.display = 'none';
                alert('Employee has no remaining leave days. Cannot set on leave.');
            } else {
                checkbox.disabled = false;
            }
        } else {
            document.getElementById('leaveBalanceTextAdd').textContent = 'Error loading leave balance';
        }
    })
    .catch(error => {
        console.error('Error fetching leave balance:', error);
        document.getElementById('leaveBalanceTextAdd').textContent = 'Error loading leave balance';
    });
}

function submitAttendance(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    formData.append('manual_entry', '1'); // Flag for manual entry
    
    // Show loading state on submit button
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalContent = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    submitBtn.classList.add('loading');
    
    // Disable cancel button during submission
    const cancelBtn = form.querySelector('button[type="button"]');
    cancelBtn.disabled = true;
    
    fetch('add_attendance.php', {
        method: 'POST',
        body: formData
    })
    .then(async response => {
        let data;
        try {
            data = await response.json();
        } catch (e) {
            // Fallback if PHP emitted notices
            const text = await response.text();
            data = { success: false, message: text && text.trim() ? text.trim().slice(0, 400) : 'Invalid server response' };
        }
        return data;
    })
    .then(data => {
        if (data.success) {
            // Show success notification
            showNotification('Attendance record added successfully!', 'success');
            
            // Close modal with delay for better UX
            setTimeout(() => {
                closeAddAttendanceModal();
                refreshAttendanceData(); // Refresh the table
            }, 800);
        } else {
            const details = data.error ? `\nDetails: ${data.error}` : '';
            showNotification('Error: ' + (data.message || 'Unknown error occurred.') + details, 'error');
        }
    })
    .catch(error => {
        console.error('Error submitting attendance:', error);
        showNotification('An error occurred while adding attendance. Please try again.', 'error');
    })
    .finally(() => {
        // Restore button states
        submitBtn.innerHTML = originalContent;
        submitBtn.disabled = false;
        submitBtn.classList.remove('loading');
        cancelBtn.disabled = false;
    });
    
    return false;
}

function showNotification(message, type = 'info', duration = 5000) {
    // Remove any existing notification
    const existingNotification = document.getElementById('custom-notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    
    // Create notification element
    const notification = document.createElement('div');
    notification.id = 'custom-notification';
    notification.className = `notification notification-${type}`;
    
    // Set styles
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 20px 25px;
        border-radius: 12px;
        color: white;
        font-weight: 500;
        z-index: 10001;
        box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        max-width: 400px;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        backdrop-filter: blur(10px);
        animation: slideInRight 0.4s ease;
        cursor: pointer;
        transition: transform 0.2s ease;
    `;
    
    // Set background based on type
    let bgGradient, iconClass;
    switch(type) {
        case 'success':
            bgGradient = 'linear-gradient(135deg, #16C79A 0%, #14a085 100%)';
            iconClass = 'fa-check-circle';
            break;
        case 'error':
            bgGradient = 'linear-gradient(135deg, #FF6B6B 0%, #c44569 100%)';
            iconClass = 'fa-exclamation-circle';
            break;
        case 'warning':
            bgGradient = 'linear-gradient(135deg, #FFC75F 0%, #f39c12 100%)';
            iconClass = 'fa-exclamation-triangle';
            break;
        default:
            bgGradient = 'linear-gradient(135deg, #3F72AF 0%, #112D4E 100%)';
            iconClass = 'fa-info-circle';
    }
    
    notification.style.background = bgGradient;
    
    notification.innerHTML = `
        <i class="fas ${iconClass}" style="font-size: 20px; min-width: 20px;"></i>
        <span style="flex: 1;">${message}</span>
        <i class="fas fa-times" style="font-size: 14px; opacity: 0.7; margin-left: 8px;" onclick="this.parentElement.remove()"></i>
    `;
    
    // Add hover effect
    notification.addEventListener('mouseenter', () => {
        notification.style.transform = 'translateY(-2px) scale(1.02)';
    });
    
    notification.addEventListener('mouseleave', () => {
        notification.style.transform = 'translateY(0) scale(1)';
    });
    
    // Click to dismiss
    notification.addEventListener('click', (e) => {
        if (e.target.classList.contains('fa-times')) {
            e.stopPropagation();
        }
        notification.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    });
    
    document.body.appendChild(notification);
    
    // Auto remove after duration
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => notification.remove(), 300);
        }
    }, duration);
}


        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target == document.getElementById('addAttendanceModal')) {
                closeAddAttendanceModal();
            }
            if (event.target == document.getElementById('recentActivitiesModal')) {
                closeRecentActivitiesModal();
            }
        });


        // ---- Filter Functions ----
        function getCurrentFilters() {
            const params = {};
            const department = departmentSelect ? departmentSelect.value : '';
            const shift = shiftSelect ? shiftSelect.value : '';
            const type = typeSelect ? typeSelect.value : '';
            const noTimeout = noTimeoutSelect ? noTimeoutSelect.value : '';
            const status = document.getElementById('status-filter-hr') ? document.getElementById('status-filter-hr').value : '';
            const search = document.getElementById('employee-search-main') ? document.getElementById('employee-search-main').value : '';
            
            if (department) params.department = department;
            if (shift) params.shift = shift;
            if (status) params.status = status;
            if (noTimeout) params.no_timeout = noTimeout;
            if (type) params.attendance_type = type;
            if (search) params.search = search;
            
            return params;
        }

        async function markTimeOut(id, btnEl) {
            try {
                if (btnEl) { btnEl.disabled = true; btnEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking...'; }
                const res = await fetch('mark_timeout.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `id=${encodeURIComponent(id)}` });
                const data = await res.json();
                if (data.success) {
                    const message = data.message || `Time out marked successfully at ${data.time_out}`;
                    const segment = data.segment ? ` (${data.segment} session)` : '';
                    showNotification(message + segment, 'success');
                    await refreshAttendanceData();
                    // Update row action immediately without waiting for refresh (best-effort)
                    const row = document.querySelector(`tr[data-id='${id}']`);
                    if (row) {
                        const actionsCell = row.querySelector('td:last-child');
                        if (actionsCell) {
                            actionsCell.innerHTML = `<button class="btn btn-secondary" onclick="openEditAttendance('${id}')"><i class="fas fa-edit"></i> Edit</button>`;
                        }
                    }
                } else {
                    showNotification(data.message || 'Failed to mark time out.', 'error');
                }
            } catch (e) {
                console.error(e);
                showNotification('Request failed.', 'error');
            } finally {
                if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = '<i class="fas fa-sign-out-alt"></i> Mark Out'; }
            }
        }

        // Function to get shift schedule and lunch window based on shift string (mirrors PHP logic)
        function getShiftScheduleAndLunchJS(shift) {
            shift = shift.trim();
            
            // Handle specific shift patterns
            switch (shift) {
                case '08:00-17:00':
                case '08:00-17:00pb':
                case '8-5pb':
                    return { start: '08:00', end: '17:00', lunchStart: '12:00', lunchEnd: '13:00' };
                case '08:30-17:30':
                case '8:30-5:30pm':
                case '8:30-17:30':
                    return { start: '08:30', end: '17:30', lunchStart: '12:30', lunchEnd: '13:30' };
                case '09:00-18:00':
                case '9am-6pm':
                    return { start: '09:00', end: '18:00', lunchStart: '13:00', lunchEnd: '14:00' };
                case '22:00-06:00':
                case 'NSD':
                case 'nsd':
                case 'Night':
                case 'night':
                    return { start: '22:00', end: '06:00', lunchStart: '02:00', lunchEnd: '03:00' };
            }
            
            // Handle generic patterns like "8:30-17:30", "8-5", "9-6"
            if (shift.match(/(\d{1,2}:\d{2})-(\d{1,2}:\d{2})/)) {
                const matches = shift.match(/(\d{1,2}:\d{2})-(\d{1,2}:\d{2})/);
                let startTime = matches[1];
                let endTime = matches[2];
                
                // Ensure proper format
                startTime = startTime.length === 4 ? '0' + startTime : startTime;
                endTime = endTime.length === 4 ? '0' + endTime : endTime;
                
                // Calculate lunch window based on shift
                const startHour = parseInt(startTime.substring(0, 2));
                const endHour = parseInt(endTime.substring(0, 2));
                
                // Default lunch times based on shift
                let lunchStart, lunchEnd;
                if (startHour <= 8) {
                    lunchStart = '12:00';
                    lunchEnd = '13:00';
                } else if (startHour <= 9) {
                    lunchStart = '12:30';
                    lunchEnd = '13:30';
                } else {
                    lunchStart = '13:00';
                    lunchEnd = '14:00';
                }
                
                return { start: startTime, end: endTime, lunchStart, lunchEnd };
            }
            
            // Handle patterns like "8-5", "9-6"
            if (shift.match(/(\d{1,2})-(\d{1,2})/)) {
                const matches = shift.match(/(\d{1,2})-(\d{1,2})/);
                let startHour = parseInt(matches[1]);
                let endHour = parseInt(matches[2]);
                
                // Convert to 24-hour format if needed
                if (endHour < 12 && endHour < 8) {
                    endHour += 12;
                }
                
                // Calculate lunch window
                let lunchStart, lunchEnd;
                if (startHour <= 8) {
                    lunchStart = '12:00';
                    lunchEnd = '13:00';
                } else if (startHour <= 9) {
                    lunchStart = '12:30';
                    lunchEnd = '13:30';
                } else {
                    lunchStart = '13:00';
                    lunchEnd = '14:00';
                }
                
                return {
                    start: String(startHour).padStart(2, '0') + ':00',
                    end: String(endHour).padStart(2, '0') + ':00',
                    lunchStart,
                    lunchEnd
                };
            }
            
            // Default fallback
            return { start: '08:00', end: '17:00', lunchStart: '12:00', lunchEnd: '13:00' };
        }

        // Function to auto-fill time fields based on employee's shift
        function autoFillShiftTimes() {
            const row = document.querySelector(`tr[data-id='${document.getElementById('edit_id').value}']`);
            if (!row) return;
            
            const shiftValue = row.getAttribute('data-shift') || '';
            const shiftSchedule = getShiftScheduleAndLunchJS(shiftValue);
            
            // Auto-fill the time fields with shift schedule
            document.getElementById('edit_am_in').value = shiftSchedule.start;
            document.getElementById('edit_am_out').value = shiftSchedule.lunchStart;
            document.getElementById('edit_pm_in').value = shiftSchedule.lunchEnd;
            document.getElementById('edit_pm_out').value = shiftSchedule.end;
            
            // Update overall time fields
            document.getElementById('edit_time_in').value = shiftSchedule.start;
            document.getElementById('edit_time_out').value = shiftSchedule.end;
            
            showNotification('Time fields filled with shift schedule', 'info');
        }

        function openEditAttendance(id) {
            const row = document.querySelector(`tr[data-id='${id}']`);
            if (!row) return;
            
            // Get employee information from table cells and data attributes
            const cells = row.querySelectorAll('td');
            const employeeName = cells[1]?.textContent?.trim() || row.getAttribute('data-employee_name') || 'Unknown Employee';
            const department = row.getAttribute('data-department') || 'Unknown Department';
            const shiftData = row.getAttribute('data-shift') || 'Unknown Shift';
            const formattedShift = formatShiftTimeJS(shiftData);
            
            // Update modal header with employee info
            document.getElementById('editEmployeeName').textContent = employeeName;
            document.getElementById('editEmployeeDetails').textContent = `${department} • ${formattedShift}`;
            
            const timeIn = row.getAttribute('data-time_in') || '';
            const timeOut = row.getAttribute('data-time_out') || '';
            const notes = row.getAttribute('data-notes') || '';
            const shiftValue = row.getAttribute('data-shift') || '';
            const dataAmIn = row.getAttribute('data-am_in') || '';
            const dataAmOut = row.getAttribute('data-am_out') || '';
            const dataPmIn = row.getAttribute('data-pm_in') || '';
            const dataPmOut = row.getAttribute('data-pm_out') || '';

            // Get proper shift schedule and lunch window based on employee's shift
            const shiftSchedule = getShiftScheduleAndLunchJS(shiftValue);
            const s = shiftSchedule.start;
            const e = shiftSchedule.end;
            const ls = shiftSchedule.lunchStart;
            const le = shiftSchedule.lunchEnd;

            // Prefer existing split values; fall back to shift schedule defaults
            const amIn = (dataAmIn && dataAmIn.length >= 4) ? dataAmIn.substring(0,5) : s;
            const amOut = (dataAmOut && dataAmOut.length >= 4) ? dataAmOut.substring(0,5) : ls;
            const pmIn = (dataPmIn && dataPmIn.length >= 4) ? dataPmIn.substring(0,5) : le;
            const pmOut = (dataPmOut && dataPmOut.length >= 4) ? dataPmOut.substring(0,5) : e;

            // Overall in/out: use existing if present; otherwise synthesize from split defaults
            const overallIn = timeIn ? timeIn.substring(0,5) : (amIn || s);
            const overallOut = timeOut ? timeOut.substring(0,5) : (pmOut || e);

            document.getElementById('edit_id').value = id;
            document.getElementById('edit_time_in').value = overallIn;
            document.getElementById('edit_time_out').value = overallOut;
            document.getElementById('edit_notes').value = notes;
            document.getElementById('edit_am_in').value = amIn;
            document.getElementById('edit_am_out').value = amOut;
            document.getElementById('edit_pm_in').value = pmIn;
            document.getElementById('edit_pm_out').value = pmOut;

            // Reset leave checkbox
            document.getElementById('edit_is_on_leave').checked = false;
            document.getElementById('leaveBalanceInfo').style.display = 'none';

            const modal = document.getElementById('editAttendanceModal');
            modal.classList.add('show');
            modal.style.display = 'flex';
        }

        // Function to format shift time to AM/PM format in JavaScript
        function formatShiftTimeJS(shift) {
            if (!shift || shift === '-') return '-';
            
            try {
                const shiftStr = shift.trim();
                
                // Handle different shift formats
                if (shiftStr.includes('-')) {
                    // Format like "08:00-17:00"
                    const times = shiftStr.split('-');
                    if (times.length === 2) {
                        const start = times[0].trim();
                        const end = times[1].trim();
                        
                        // Convert to 12-hour format
                        const startFormatted = formatTime12HourJS(start);
                        const endFormatted = formatTime12HourJS(end);
                        
                        return startFormatted + ' - ' + endFormatted;
                    }
                }
                
                // If it's a single time, format it
                return formatTime12HourJS(shiftStr);
            } catch (e) {
                return shift; // Return original if any error occurs
            }
        }
        
        // Helper function to convert 24-hour time to 12-hour format in JavaScript
        function formatTime12HourJS(time) {
            if (!time || time === '-') return '-';
            
            try {
                const timeStr = time.trim();
                
                // If already in 12-hour format, return as is
                if (timeStr.toLowerCase().includes('am') || timeStr.toLowerCase().includes('pm')) {
                    return timeStr;
                }
                
                // Convert 24-hour format to 12-hour
                const timeObj = new Date('1970-01-01T' + timeStr);
                if (!isNaN(timeObj.getTime())) {
                    return timeObj.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit', hour12: true});
                }
                
                return timeStr; // Return original if conversion fails
            } catch (e) {
                return time; // Return original if any error occurs
            }
        }

        // View details modal handlers
        function openViewAttendanceModal(rowEl) {
            const body = document.getElementById('viewAttendanceBody');
            const fmt = (t)=> t ? new Date('1970-01-01T' + t).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', hour12: true}) : '-';
            const name = rowEl.getAttribute('data-employee_name') || '-';
            const id = rowEl.getAttribute('data-employee_id') || '-';
            const dept = rowEl.getAttribute('data-department') || '-';
            const shift = rowEl.getAttribute('data-shift') || '-';
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
            const dataSource = rowEl.getAttribute('data-source') || 'biometric';
            const isHalfDay = (rowEl.getAttribute('data-status') || '') === 'half_day';
            const hasAmPairOnly = rawAmIn && rawAmOut && !rawPmIn && !rawPmOut;
            const hasPmPairOnly = rawPmIn && rawPmOut && !rawAmIn && !rawAmOut;
            
            // Helper functions for source display
            const getSourceIcon = (source) => {
                switch(source) {
                    case 'manual':
                        return '<i class="fas fa-edit text-primary"></i>';
                    case 'bulk_edit':
                        return '<i class="fas fa-users-cog text-warning"></i>';
                    case 'biometric':
                    default:
                        return '<i class="fas fa-fingerprint text-success"></i>';
                }
            };
            
            const getSourceText = (source) => {
                switch(source) {
                    case 'manual':
                        return 'Manual Entry (Web App)';
                    case 'bulk_edit':
                        return 'Bulk Edit (Web App)';
                    case 'biometric':
                    default:
                        return 'Biometric Scanner (ZKteco Device)';
                }
            };
            
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
            
            let sessionHtml = '';
            if (isHalfDay && hasPmPairOnly) {
                sessionHtml = `
                    <div style="background: #E8F5E8; padding: 20px; border-radius: 12px; border-left: 4px solid #16C79A; margin-bottom: 20px;">
                        <h4 style="color: #16C79A; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-moon"></i> Afternoon Session (Half Day)
                        </h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">PM In (Lunch End)</div>
                                <div style="font-weight: 600; color: #333; font-size: 16px;">${pmIn}</div>
                            </div>
                            <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">PM Out</div>
                                <div style="font-weight: 600; color: #333; font-size: 16px;">${pmOut}</div>
                            </div>
                        </div>
                    </div>`;
            } else if (isHalfDay && hasAmPairOnly) {
                sessionHtml = `
                    <div style="background: #FFF8E1; padding: 20px; border-radius: 12px; border-left: 4px solid #FFC75F; margin-bottom: 20px;">
                        <h4 style="color: #F57C00; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-sun"></i> Morning Session (Half Day)
                        </h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">AM In</div>
                                <div style="font-weight: 600; color: #333; font-size: 16px;">${amIn}</div>
                            </div>
                            <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">AM Out (Lunch Start)</div>
                                <div style="font-weight: 600; color: #333; font-size: 16px;">${amOut}</div>
                            </div>
                        </div>
                    </div>`;
            } else {
                sessionHtml = `
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div style="background: #FFF8E1; padding: 20px; border-radius: 12px; border-left: 4px solid #FFC75F;">
                            <h4 style="color: #F57C00; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-sun"></i> Morning Session
                            </h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                    <div style="font-size: 12px; color: #666; margin-bottom: 4px;">AM In</div>
                                    <div style="font-weight: 600; color: #333; font-size: 16px;">${amIn}</div>
                                </div>
                                <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                    <div style="font-size: 12px; color: #666; margin-bottom: 4px;">AM Out (Lunch Start)</div>
                                    <div style="font-weight: 600; color: #333; font-size: 16px;">${amOut}</div>
                                </div>
                            </div>
                        </div>
                        <div style="background: #E8F5E8; padding: 20px; border-radius: 12px; border-left: 4px solid #16C79A;">
                            <h4 style="color: #16C79A; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-moon"></i> Afternoon Session
                            </h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                    <div style="font-size: 12px; color: #666; margin-bottom: 4px;">PM In (Lunch End)</div>
                                    <div style="font-weight: 600; color: #333; font-size: 16px;">${pmIn}</div>
                                </div>
                                <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                                    <div style="font-size: 12px; color: #666; margin-bottom: 4px;">PM Out</div>
                                    <div style="font-weight: 600; color: #333; font-size: 16px;">${pmOut}</div>
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
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Department</div>
                            <div style="font-weight: 600; color: #333; font-size: 16px;">${dept}</div>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Shift</div>
                            <div style="font-weight: 600; color: #333; font-size: 16px;">${formatShift(shift)}</div>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Date</div>
                            <div style="font-weight: 600; color: #333; font-size: 16px;">${new Date(date).toLocaleDateString('en-US', {weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'})}</div>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Data Source</div>
                            <div style="font-weight: 600; color: #333; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                ${getSourceIcon(dataSource)} ${getSourceText(dataSource)}
                            </div>
                        </div>
                    </div>
                </div>
                
                ${sessionHtml}
                
                <div style="background: #F0F8FF; padding: 20px; border-radius: 12px; border-left: 4px solid #3F72AF;">
                    <h4 style="color: #3F72AF; margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-chart-line"></i> Status & Metrics
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Attendance Type</div>
                            <div style="font-weight: 600; color: #333; font-size: 16px;">${type.charAt(0).toUpperCase() + type.slice(1)}</div>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Status</div>
                            <div style="font-weight: 600; color: #333; font-size: 16px;">${status === 'half_day' ? 'Half Day' : (status.charAt(0).toUpperCase() + status.slice(1))}</div>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Late (minutes)</div>
                            <div style="font-weight: 600; color: #333; font-size: 16px;">${(isHalfDay && hasPmPairOnly) ? '-' : (status === 'half_day' ? '-' : late)}</div>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Early Out (minutes)</div>
                            <div style="font-weight: 600; color: #333; font-size: 16px;">${(isHalfDay && hasAmPairOnly) ? '-' : (status === 'half_day' ? '-' : early)}</div>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Overtime (hours)</div>
                            <div style="font-weight: 600; color: #333; font-size: 16px;">${parseFloat(ot).toFixed(2)}</div>
                        </div>
                        <div style="background: #fff; padding: 12px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Notes</div>
                            <div style="font-weight: 600; color: #333; font-size: 16px;">${notes}</div>
                        </div>
                    </div>
                </div>
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

        // Row click binding
        document.addEventListener('click', function(e) {
            const tr = e.target.closest('tr.clickable-row');
            if (tr && !e.target.closest('button')) {
                openViewAttendanceModal(tr);
            }
        });
        // Close on overlay click
        document.getElementById('viewAttendanceModal').addEventListener('click', function(e){
            if (e.target === this) closeViewAttendanceModal();
        });

        function closeEditAttendanceModal() {
            const modal = document.getElementById('editAttendanceModal');
            modal.classList.remove('show');
            modal.style.display = 'none';
        }

        // Half Day Functions
        function markHalfDay(period) {
            const attendanceId = document.getElementById('edit_id').value;
            if (!attendanceId) {
                showNotification('No attendance record selected', 'error');
                return;
            }

            // Show confirmation dialog
            const periodText = period === 'morning' ? 'Morning Only' : 'Afternoon Only';
            if (!confirm(`Are you sure you want to mark this attendance as Half Day (${periodText})?`)) {
                return;
            }

            // Prepare data for half day marking
            const formData = new FormData();
            formData.append('id', attendanceId);
            formData.append('halfday_period', period);
            formData.append('action', 'mark_halfday');

            // Show loading state on the clicked button
            const clickedBtn = event.target.closest('.halfday-option-btn');
            const originalText = clickedBtn.innerHTML;
            clickedBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            clickedBtn.disabled = true;

            // Send AJAX request
            fetch('update_attendance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(`Successfully marked as Half Day (${periodText})`, 'success');
                    closeEditAttendanceModal();
                    // Refresh the attendance data
                    refreshAttendanceData();
                } else {
                    showNotification(data.message || 'Failed to mark as half day', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while processing the request', 'error');
            })
            .finally(() => {
                // Restore button state
                clickedBtn.innerHTML = originalText;
                clickedBtn.disabled = false;
            });
        }

        // Function to validate time entries
        function validateTimeEntries() {
            const amIn = document.getElementById('edit_am_in').value;
            const amOut = document.getElementById('edit_am_out').value;
            const pmIn = document.getElementById('edit_pm_in').value;
            const pmOut = document.getElementById('edit_pm_out').value;
            const timeIn = document.getElementById('edit_time_in').value;
            const timeOut = document.getElementById('edit_time_out').value;
            
            // Validate overall time sequence
            if (timeIn && timeOut && timeIn > timeOut) {
                showNotification('Time in cannot be after time out. Please check your time entries.', 'error');
                return false;
            }
            
            // Validate morning time sequence
            if (amIn && amOut && amIn > amOut) {
                showNotification('Morning time in cannot be after morning time out.', 'error');
                return false;
            }
            
            // Validate afternoon time sequence
            if (pmIn && pmOut && pmIn > pmOut) {
                showNotification('Afternoon time in cannot be after afternoon time out.', 'error');
                return false;
            }
            
            // Validate cross-period logic
            if (amOut && pmIn && amOut > pmIn) {
                showNotification('Morning time out should be before afternoon time in.', 'error');
                return false;
            }
            
            return true;
        }

        function submitEditAttendance(event) {
            event.preventDefault();
            
            // Validate time entries before submitting
            if (!validateTimeEntries()) {
                return false;
            }
            
            const form = document.getElementById('editAttendanceForm');
            const formData = new FormData(form);
            // If split provided, synthesize main time_in/time_out if empty
            const amIn = document.getElementById('edit_am_in').value;
            const amOut = document.getElementById('edit_am_out').value;
            const pmIn = document.getElementById('edit_pm_in').value;
            const pmOut = document.getElementById('edit_pm_out').value;
            // Always prefer split fields to define overall in/out to avoid wrong AM/PM
            if (amIn) { formData.set('time_in', amIn.length === 5 ? amIn + ':00' : amIn); }
            if (pmOut) { formData.set('time_out', pmOut.length === 5 ? pmOut + ':00' : pmOut); }
            fetch('update_attendance.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Attendance updated successfully.', 'success');
                        closeEditAttendanceModal();
                        refreshAttendanceData();
                    } else {
                        showNotification(data.message || 'Failed to update attendance.', 'error');
                    }
                })
                .catch(() => showNotification('Request failed.', 'error'));
            return false;
        }

        function markNow(which) {
            // Default by employee shift when available
            const row = document.querySelector(`#editAttendanceModal`);
            const shiftStr = document.querySelector('tr[data-id]') ? '' : '';
            const shift = document.getElementById('edit_shift') ? document.getElementById('edit_shift').value : (document.querySelector(".clickable-row[data-shift]")?.getAttribute('data-shift') || '');
            // Map shift defaults
            let s='08:00', ls='12:00', le='13:00', e='17:00';
            if (shift === '08:30-17:30' || shift === '8:30-5:30pm') { s='08:30'; ls='12:30'; le='13:30'; e='17:30'; }
            if (shift === '09:00-18:00' || shift === '9am-6pm') { s='09:00'; ls='13:00'; le='14:00'; e='18:00'; }
            const set = (id,val)=>{ const el=document.getElementById(id); if (el) el.value = val; };
            if (which === 'am_in') set('edit_am_in', s);
            if (which === 'am_out') set('edit_am_out', ls);
            if (which === 'pm_in') set('edit_pm_in', le);
            if (which === 'pm_out') set('edit_pm_out', e);
            // Also synthesize overall if empty
            const overallIn = document.getElementById('edit_time_in');
            const overallOut = document.getElementById('edit_time_out');
            if (overallIn && !overallIn.value) overallIn.value = s;
            if (overallOut && !overallOut.value) overallOut.value = e;
        }

        function applyFilters() {
            // Since we now have auto-refresh on filter changes, 
            // this function can trigger a manual refresh
            refreshAttendanceData();
        }

        function resetFilters() {
            if(departmentSelect) departmentSelect.value = '';
            if(shiftSelect) shiftSelect.value = '';
            if(statusSelect) statusSelect.value = '';
            if(noTimeoutSelect) noTimeoutSelect.value = '';
            
            // Reset Attendance Type filter
            if(typeSelect) typeSelect.value = '';
            
            // Reset Status filter
            const statusFilter = document.getElementById('status-filter-hr');
            if(statusFilter) statusFilter.value = '';
            
            // Reset Overtime filter
            const overtimeFilter = document.getElementById('overtime-filter');
            if(overtimeFilter) overtimeFilter.checked = false;
            
            // Clear employee search
            const employeeSearchMain = document.getElementById('employee-search-main');
            const clearBtn = document.querySelector('.clear-search-btn');
            if (employeeSearchMain) {
                employeeSearchMain.value = '';
            }
            if (clearBtn) {
                clearBtn.classList.remove('show');
            }
            
            // Hide search results
            hideMainSearchResults();
            
            // Show all table rows
            showAllTableRows();
            
            // Remove top employee indicator
            removeTopEmployeeIndicator();
            
            // Trigger AJAX refresh instead of page reload
            debouncedRefreshAttendanceData();
        }

        // Overtime Filter Toggle Function
        function toggleOvertimeFilter() {
            const overtimeToggle = document.getElementById('overtime-filter');
            const isOvertimeFilterActive = overtimeToggle.checked;
            
            // Apply overtime filter to table rows
            const tbody = document.querySelector('#attendance-results');
            const rows = tbody.querySelectorAll('tr');
            
            rows.forEach(row => {
                if (isOvertimeFilterActive) {
                    // Check if employee is on overtime
                    const overtimeHours = parseFloat(row.getAttribute('data-overtime_hours') || '0');
                    const isOvertime = row.getAttribute('data-is_overtime') === '1';
                    
                    if (overtimeHours > 0 || isOvertime) {
                        row.style.display = '';
                        row.style.backgroundColor = '#FFF3E0';
                        row.style.borderLeft = '4px solid #FF9800';
                    } else {
                        row.style.display = 'none';
                    }
                } else {
                    // Show all rows when filter is off
                    row.style.display = '';
                    row.style.backgroundColor = '';
                    row.style.borderLeft = '';
                }
            });
            
            // Update filter feedback
            if (isOvertimeFilterActive) {
                const overtimeCount = Array.from(rows).filter(row => {
                    const overtimeHours = parseFloat(row.getAttribute('data-overtime_hours') || '0');
                    const isOvertime = row.getAttribute('data-is_overtime') === '1';
                    return (overtimeHours > 0 || isOvertime) && row.style.display !== 'none';
                }).length;
                
                showFilterFeedback(`Showing ${overtimeCount} employee(s) on overtime`, 'success');
            } else {
                showFilterFeedback('Overtime filter disabled', 'info');
            }
        }

        function showAllTableRows() {
            const tbody = document.querySelector('#attendance-results');
            const rows = tbody.querySelectorAll('tr');
            
            rows.forEach(row => {
                row.style.display = '';
                row.style.backgroundColor = '';
                row.style.borderLeft = '';
                row.style.transform = '';
            });
            
            // Reset overtime filter toggle
            const overtimeFilter = document.getElementById('overtime-filter');
            if(overtimeFilter) overtimeFilter.checked = false;
        }

        function clearEmployeeSearch() {
            const searchInput = document.getElementById('employee-search-main');
            const clearBtn = document.querySelector('.clear-search-btn');
            
            // Clear the search input
            searchInput.value = '';
            
            // Hide clear button
            clearBtn.classList.remove('show');
            
            // Hide search results
            hideMainSearchResults();
            
            // Show all table rows
            showAllTableRows();
            
            // Remove top employee indicator
            removeTopEmployeeIndicator();
            
            // Show feedback
            showFilterFeedback('Search cleared - showing all employees', 'info');
        }

        function moveSearchedEmployeeToTop(searchTerm, records) {
            const tbody = document.querySelector('#attendance-results');
            if (!tbody) return;
            
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const searchLower = searchTerm.toLowerCase();
            
            // Find rows that match the search term
            const matchingRows = rows.filter(row => {
                const employeeId = row.getAttribute('data-employee_id') || '';
                const employeeName = row.querySelector('.employee-name')?.textContent || '';
                const department = row.querySelector('.department')?.textContent || '';
                
                return employeeId.toLowerCase().includes(searchLower) ||
                       employeeName.toLowerCase().includes(searchLower) ||
                       department.toLowerCase().includes(searchLower);
            });
            
            if (matchingRows.length > 0) {
                // Remove matching rows from their current positions
                matchingRows.forEach(row => row.remove());
                
                // Add them to the top
                matchingRows.forEach((row, index) => {
                    tbody.insertBefore(row, tbody.firstChild);
                    
                    // Add visual highlight
                    row.style.backgroundColor = '#e8f5e8';
                    row.style.borderLeft = '4px solid #16C79A';
                    row.style.transition = 'all 0.3s ease';
                    row.style.transform = 'scale(1.02)';
                    
                    // Remove highlight after animation
                    setTimeout(() => {
                        row.style.transform = 'scale(1)';
                    }, 300);
                });
                
                // Add top employee indicator
                addTopEmployeeIndicator(`Found ${matchingRows.length} employee(s) matching "${searchTerm}"`);
                
                // Scroll to top
                tbody.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function addTopEmployeeIndicator(employeeName) {
            // Remove any existing indicator
            removeTopEmployeeIndicator();
            
            // Create indicator element
            const indicator = document.createElement('div');
            indicator.id = 'top-employee-indicator';
            indicator.innerHTML = `
                <i class="fas fa-arrow-up" style="color: #16C79A; margin-right: 8px;"></i>
                <span style="color: #16C79A; font-weight: 600;">${employeeName} - Moved to Top</span>
            `;
            indicator.style.cssText = `
                background: linear-gradient(135deg, rgba(22, 199, 154, 0.1) 0%, rgba(22, 199, 154, 0.05) 100%);
                border: 1px solid rgba(22, 199, 154, 0.3);
                border-radius: 8px;
                padding: 8px 12px;
                margin: 10px 0;
                font-size: 14px;
                display: flex;
                align-items: center;
                animation: slideInDown 0.3s ease;
            `;
            
            // Insert after the table header
            const resultsHeader = document.querySelector('.results-header');
            if (resultsHeader) {
                resultsHeader.insertAdjacentElement('afterend', indicator);
            }
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                removeTopEmployeeIndicator();
            }, 5000);
        }

        function removeTopEmployeeIndicator() {
            const indicator = document.getElementById('top-employee-indicator');
            if (indicator) {
                indicator.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => {
                    if (indicator.parentNode) {
                        indicator.remove();
                    }
                }, 300);
            }
        }

        // ---- Initial Load ----
        document.addEventListener('DOMContentLoaded', function() {
            // Initial data load to ensure latest UI/table structure is applied
            refreshAttendanceData();
            // Start auto refresh after initial load
            if (autoRefreshEnabled) { startAutoRefresh(); }
            
            // Add search event listeners
            const employeeSearchInput = document.getElementById('employee_search');
            if (employeeSearchInput) {
                employeeSearchInput.addEventListener('input', function(e) {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        searchEmployees(e.target.value);
                    }, 300);
                });
                
                employeeSearchInput.addEventListener('blur', function() {
                    // Delay hiding to allow click on results
                    setTimeout(() => {
                        hideSearchResults();
                    }, 200);
                });
                
                employeeSearchInput.addEventListener('focus', function() {
                    if (this.value.length >= 2) {
                        searchEmployees(this.value);
                    }
                });
            }
            
            // Add main employee search event listeners
            const employeeSearchMainInput = document.getElementById('employee-search-main');
            const clearBtn = document.querySelector('.clear-search-btn');
            
            if (employeeSearchMainInput) {
                employeeSearchMainInput.addEventListener('input', function(e) {
                    const value = e.target.value;
                    
                    // Show/hide clear button
                    if (value.length > 0) {
                        clearBtn.classList.add('show');
                    } else {
                        clearBtn.classList.remove('show');
                    }
                    
                    clearTimeout(mainSearchTimeout);
                    mainSearchTimeout = setTimeout(() => {
                        searchEmployeesMain(value);
                        // Auto-apply search to filter results
                        debouncedRefreshAttendanceData();
                    }, 200); // Faster response for main search
                });
                
                employeeSearchMainInput.addEventListener('blur', function() {
                    // Delay hiding to allow click on results
                    setTimeout(() => {
                        hideMainSearchResults();
                    }, 200);
                });
                
                employeeSearchMainInput.addEventListener('focus', function() {
                    if (this.value.length >= 2) {
                        searchEmployeesMain(this.value);
                    }
                });
            }
            
            // Add filter change listeners for auto-refresh
            const departmentFilter = document.getElementById('department_filter');
            const shiftFilter = document.getElementById('shift_filter');
            const statusFilter = document.getElementById('status_filter');
            const noTimeoutFilter = document.getElementById('no_timeout_filter');

            // Main filter controls (top filters)
            if (departmentSelect) {
                departmentSelect.addEventListener('change', function() {
                    debouncedRefreshAttendanceData();
                });
            }
            if (shiftSelect) {
                shiftSelect.addEventListener('change', function() {
                    debouncedRefreshAttendanceData();
                });
            }
            if (typeSelect) {
                typeSelect.addEventListener('change', function() {
                    debouncedRefreshAttendanceData();
                });
            }
            if (noTimeoutSelect) {
                noTimeoutSelect.addEventListener('change', function() {
                    debouncedRefreshAttendanceData();
                });
            }
            
            if (departmentFilter) {
                departmentFilter.addEventListener('change', function() {
                    filterEmployeesByDepartment();
                    // Auto-refresh attendance data when department filter changes
                    debouncedRefreshAttendanceData();
                });
            }
            
            if (shiftFilter) {
                shiftFilter.addEventListener('change', function() {
                    // Auto-refresh attendance data when shift filter changes
                    debouncedRefreshAttendanceData();
                });
            }
            
            if (statusFilter) {
                statusFilter.addEventListener('change', function() {
                    // Auto-refresh attendance data when status filter changes
                    debouncedRefreshAttendanceData();
                });
            }
            
            if (noTimeoutFilter) {
                noTimeoutFilter.addEventListener('change', function() {
                    // Auto-refresh attendance data when no timeout filter changes
                    debouncedRefreshAttendanceData();
                });
            }

            // Status filter interactions and auto-refresh
            const statusFilterHr = document.getElementById('status-filter-hr');
            if (statusFilterHr) {
                statusFilterHr.addEventListener('change', function() {
                    debouncedRefreshAttendanceData();
                });
            }
            
            // Hide search results when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.employee-search-container')) {
                    hideSearchResults();
                }
                if (!e.target.closest('.employee-search-container-main')) {
                    hideMainSearchResults();
                }
            });
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            stopAutoRefresh();
        });

        // ===== BULK EDIT FUNCTIONALITY =====
        // Server's today date to ensure consistency across all date operations
        const serverToday = '<?php echo date('Y-m-d'); ?>';
        
        let bulkEditData = {
            currentStep: 1,
            selectedEmployees: [],
            selectedShift: null,
            allEmployees: [],
            // Stores the chosen shift pattern from Step 1 (e.g., 08:00-17:00, 08:30-17:30, 09:00-18:00)
            shiftPattern: null
        };

        function openBulkEditModal() {
            const modal = document.getElementById('bulkEditModal');
            // Ensure clean state and immediate paint
            modal.style.display = 'flex';
            modal.style.opacity = '1';
            modal.style.zIndex = '9999';
            modal.classList.add('show');
            
            
            // Reset bulk edit data
            bulkEditData = {
            currentStep: 1,
            selectedEmployees: [],
            selectedShift: null,
            allEmployees: []
        };

            
            
            

            // Populate departments from PHP or fallback to JS roster
            try {
                const deptSel = document.getElementById('bulk-department-filter');
                if (deptSel && deptSel.options.length <= 1 && Array.isArray(window.allActiveEmployees)) {
                    const uniq = Array.from(new Set(window.allActiveEmployees.map(e => (e.Department || '').trim()).filter(Boolean))).sort();
                    uniq.forEach(d => {
                        const opt = document.createElement('option');
                        opt.value = d;
                        opt.textContent = d;
                        deptSel.appendChild(opt);
                    });
                }
            } catch (e) {}

            // Load employees for Step 1 using current filters (Department, Shift, Search)
            try { loadBulkEmployees(); } catch (e) { /* fallback handled inside loader */ }

            // Initialize Next button state
            const nextBtn = document.getElementById('bulk-next-btn');
            if (nextBtn) {
                nextBtn.disabled = true;
                nextBtn.style.background = '#ccc';
                nextBtn.style.transform = 'translateY(0)';
                nextBtn.style.boxShadow = 'none';
            }

            // Ensure initial step is visible and painted immediately
            try {
                const bar = document.getElementById('bulkProgressBar');
                if (bar) bar.style.width = '33%';
                const stepEl = document.getElementById('bulk-step-1');
                if (stepEl) {
                    // Force reflow to avoid any async CSS transition glitches
                    void stepEl.offsetHeight;
                    stepEl.style.display = 'block';
                    stepEl.classList.add('step-anim-enter');
                    setTimeout(() => stepEl.classList.remove('step-anim-enter'), 280);
                }
            } catch (e) {}
        }

        function closeBulkEditModal() {
            const modal = document.getElementById('bulkEditModal');
            
            // Hide all steps first
            document.querySelectorAll('.bulk-step').forEach(stepEl => {
                stepEl.style.display = 'none';
                stepEl.classList.remove('step-anim-enter', 'step-anim-exit');
            });
            
            // Show only step 1
            const step1 = document.getElementById('bulk-step-1');
            if (step1) {
                step1.style.display = 'block';
            }
            
            // Reset stepper UI
            const s1 = document.getElementById('bulk-stepper-1');
            const s2 = document.getElementById('bulk-stepper-2');
            const s3 = document.getElementById('bulk-stepper-3');
            if (s1) s1.classList.add('active');
            if (s2) s2.classList.remove('active', 'just-activated');
            if (s3) s3.classList.remove('active', 'just-activated');
            
            // Reset buttons
            const prevBtn = document.getElementById('bulk-prev-btn');
            const nextBtn = document.getElementById('bulk-next-btn');
            const saveBtn = document.getElementById('bulk-save-btn');
            if (prevBtn) prevBtn.style.display = 'none';
            if (nextBtn) {
                nextBtn.style.display = 'inline-block';
                nextBtn.disabled = true;
            }
            if (saveBtn) saveBtn.style.display = 'none';
            
            // Reset shift options
            document.querySelectorAll('.shift-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Reset form
            const form = document.getElementById('bulkEditForm');
            if (form) form.reset();
            
            // Reset progress bar
            const bar = document.getElementById('bulkProgressBar');
            if (bar) bar.style.width = '33%';
            
            // Reset selection count
            const selCount = document.getElementById('selected-count');
            if (selCount) selCount.textContent = '0 employees selected';
            const selectAll = document.getElementById('select-all-employees');
            if (selectAll) selectAll.checked = false;
            
            // Reset data
            bulkEditData = {
                currentStep: 1,
                selectedEmployees: [],
                selectedShift: null,
                allEmployees: [],
                shiftPattern: null
            };
            
            // Finally hide modal
            modal.classList.remove('show');
            modal.style.display = 'none';
        }
            
            

        function loadBulkEmployees() {
            // Prefer Step 1 modal filters; fallback to page filters
            const currentParams = new URLSearchParams(window.location.search);
            const department = (document.getElementById('bulk-department-filter')?.value || '').trim();
            const shift = (document.getElementById('bulk-shift-filter')?.value || '').trim();
            const status = currentParams.get('status') || '';
            const attendance_type = currentParams.get('attendance_type') || '';
            const search = (document.getElementById('bulk-employee-search')?.value || '').trim();
            const late_only = currentParams.get('late_only') || '';
            
            // Build fetch URL with current filters
            let fetchUrl = 'fetch_attendance_for_bulk_edit.php?';
            if (department !== undefined && department !== null) fetchUrl += `&department=${encodeURIComponent(department)}`;
            if (shift !== undefined && shift !== null) fetchUrl += `&shift=${encodeURIComponent(shift)}`;
            if (status) fetchUrl += `&status=${encodeURIComponent(status)}`;
            if (attendance_type) fetchUrl += `&attendance_type=${encodeURIComponent(attendance_type)}`;
            if (search) fetchUrl += `&search=${encodeURIComponent(search)}`;
            if (late_only) fetchUrl += `&late_only=${encodeURIComponent(late_only)}`;
            
            // Use server's today date to ensure consistency with main attendance page
            fetchUrl += `&date=${serverToday}`;

            // Show loading state (in case it wasn't set by opener)
            const container = document.getElementById('bulk-employee-list');
            if (container) {
                container.innerHTML = '<div class="loading-state" style="text-align:center; padding:32px 0; color:#3B82F6;"><i class="fas fa-spinner fa-spin fa-2x"></i><br><span style="font-size:1.1em;">Loading employees...</span></div>';
                container.style.opacity = '1';
            }

            // Fetch all employees with attendance records based on current filters
            fetch(fetchUrl)
                .then(async (response) => {
                    let data;
                    try {
                        data = await response.json();
                    } catch (e) {
                        // PHP notices or non-JSON -> fallback with raw text
                        const raw = await response.text();
                        data = { success: false, message: raw && raw.trim() ? raw.trim().slice(0, 400) : 'Invalid response' };
                    }
                    return data;
                })
                .then(data => {
                    if (data.success) {
                        const records = Array.isArray(data.employees) ? data.employees : [];
                        bulkEditData.allEmployees = records.map(emp => ({
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
                        // Fallback to roster filtered by Step 1 if API returns empty
                        if (bulkEditData.allEmployees.length === 0 && Array.isArray(window.allActiveEmployees) && window.allActiveEmployees.length > 0) {
                            const todayStr = serverToday;
                            bulkEditData.allEmployees = window.allActiveEmployees
                                .filter(emp => (!department || emp.Department === department) && (!shift || (emp.Shift || '') === shift))
                                .map(emp => ({
                                    id: String(emp.EmployeeID || emp.id || ''),
                                    name: emp.EmployeeName || emp.name || '',
                                    department: emp.Department || emp.department || '',
                                    shift: emp.Shift || emp.shift || '',
                                    date: todayStr,
                                    attendanceType: 'absent',
                                    timeIn: '',
                                    timeOut: '',
                                    timeInMorning: '',
                                    timeOutMorning: '',
                                    timeInAfternoon: '',
                                    timeOutAfternoon: '',
                                    lateMinutes: 0,
                                    earlyOutMinutes: 0,
                                    overtimeHours: 0,
                                    status: 'absent'
                                }));
                        }
                        // Last-resort fallback: scrape current table to mirror Attendance Records (today)
                        if (bulkEditData.allEmployees.length === 0) {
                            const rows = Array.from(document.querySelectorAll('#attendance-results tr.clickable-row'));
                            const todayStr = serverToday;
                            bulkEditData.allEmployees = rows.map(r => ({
                                id: String(r.getAttribute('data-employee_id') || ''),
                                name: r.getAttribute('data-employee_name') || '',
                                department: r.getAttribute('data-department') || '',
                                shift: r.getAttribute('data-shift') || '',
                                date: r.getAttribute('data-date') || todayStr,
                                attendanceType: (r.getAttribute('data-attendance_type') || 'absent'),
                                timeIn: r.getAttribute('data-time_in') || '',
                                timeOut: r.getAttribute('data-time_out') || '',
                                timeInMorning: r.getAttribute('data-am_in') || '',
                                timeOutMorning: r.getAttribute('data-am_out') || '',
                                timeInAfternoon: r.getAttribute('data-pm_in') || '',
                                timeOutAfternoon: r.getAttribute('data-pm_out') || '',
                                lateMinutes: parseInt(r.getAttribute('data-late') || '0', 10),
                                earlyOutMinutes: parseInt(r.getAttribute('data-early') || '0', 10),
                                overtimeHours: parseFloat(r.getAttribute('data-ot') || '0') || 0,
                                status: r.getAttribute('data-status') || 'absent'
                            }));
                        }
                        filterBulkEmployees();
                        // Immediately reveal Step 1 right list after data arrives
                        const list = document.getElementById('bulk-employee-list');
                        if (list) { list.style.opacity = '1'; list.style.maxHeight = '520px'; list.style.minHeight = '360px'; }
                        // If nothing rendered, show a friendly empty state
                        if (list && !list.innerHTML.trim()) {
                            list.innerHTML = '<div class="empty-state" style="text-align:center; padding:40px 0; color:#EF4444; background:#FFF3F3; border-radius:8px; font-size:1.1em;">' +
                                '<i class="fas fa-users-slash fa-2x" style="margin-bottom:12px;"></i>' +
                                '<br><strong>No employees found for the current filters.</strong>' +
                                '<br><span style="color:#B91C1C;">Try adjusting your filters or check back later.</span>' +
                            '</div>';
                        }
                    } else {
                        container.innerHTML = '<div class="error-state" style="text-align:center; padding:40px 0; color:#B91C1C; background:#FFF3F3; border-radius:8px; font-size:1.1em;">' +
                            '<i class="fas fa-exclamation-triangle fa-2x" style="margin-bottom:12px;"></i>' +
                            '<br><strong>Error:</strong> ' + (data.message || 'Unable to load employees.') +
                            '<br><span style="color:#B91C1C;">Please try again or contact support.</span>' +
                        '</div>';
                    }
                })
                .catch(error => {
                    try { console.error('Error fetching employees:', error); } catch(e){}
                    // Graceful fallback to full roster
                    if (Array.isArray(window.allActiveEmployees) && window.allActiveEmployees.length > 0) {
                        const todayStr = serverToday;
                        bulkEditData.allEmployees = window.allActiveEmployees.map(emp => ({
                            id: String(emp.EmployeeID || emp.id || ''),
                            name: emp.EmployeeName || emp.name || '',
                            department: emp.Department || emp.department || '',
                            shift: emp.Shift || emp.shift || '',
                            date: todayStr,
                            attendanceType: 'absent',
                            timeIn: '',
                            timeOut: '',
                            timeInMorning: '',
                            timeOutMorning: '',
                            timeInAfternoon: '',
                            timeOutAfternoon: '',
                            lateMinutes: 0,
                            earlyOutMinutes: 0,
                            overtimeHours: 0,
                            status: 'absent'
                        }));
                        filterBulkEmployees();
                        const list = document.getElementById('bulk-employee-list');
                        if (list && !list.innerHTML.trim()) {
                            list.innerHTML = '<div class="empty-state" style="text-align:center; padding:40px 0; color:#EF4444; background:#FFF3F3; border-radius:8px; font-size:1.1em;">' +
                                '<i class="fas fa-users-slash fa-2x" style="margin-bottom:12px;"></i>' +
                                '<br><strong>No employees found.</strong>' +
                                '<br><span style="color:#B91C1C;">Try adjusting your filters or check back later.</span>' +
                            '</div>';
                        }
                    } else {
                        if (container) container.innerHTML = '<div class="error-state" style="text-align:center; padding:40px 0; color:#B91C1C; background:#FFF3F3; border-radius:8px; font-size:1.1em;">' +
                            '<i class="fas fa-exclamation-triangle fa-2x" style="margin-bottom:12px;"></i>' +
                            '<br><strong>Failed to load employees for bulk edit.</strong>' +
                            '<br><span style="color:#B91C1C;">Please try again or contact support.</span>' +
                        '</div>';
                    }
                })
                .finally(() => {
                    // Guard against stuck "Loading employees..."
                    const list = document.getElementById('bulk-employee-list');
                    if (!list) return;
                    const stillLoading = /Loading employees/i.test(list.textContent || '');
                    if (stillLoading) {
                        // Attempt to render whatever we have
                        if (Array.isArray(bulkEditData.allEmployees) && bulkEditData.allEmployees.length > 0) {
                            displayBulkEmployeeList(bulkEditData.allEmployees);
                        } else {
                            list.innerHTML = '<div class="empty-state" style="text-align:center; padding:40px 0; color:#6B7280;">No employees found for today.</div>';
                        }
                    }
                });
        }

        function filterBulkEmployees() {
            const departmentFilter = document.getElementById('bulk-department-filter').value.toLowerCase();
            const shiftFilter = document.getElementById('bulk-shift-filter').value.toLowerCase();
            const searchTerm = document.getElementById('bulk-employee-search').value.toLowerCase();
            
            const filtered = bulkEditData.allEmployees.filter(emp => {
                const matchesDepartment = !departmentFilter || emp.department.toLowerCase().includes(departmentFilter);
                const matchesShift = !shiftFilter || emp.shift.toLowerCase().includes(shiftFilter);
                const matchesSearch = !searchTerm || 
                    emp.name.toLowerCase().includes(searchTerm) || 
                    emp.id.toLowerCase().includes(searchTerm);
                return matchesDepartment && matchesShift && matchesSearch;
            });
            
            displayBulkEmployeeList(filtered);
        }

        function onBulkDepartmentChanged() {
            // Reload from backend to ensure accuracy; then apply client filter
            try { loadBulkEmployees(); } catch(e) { filterBulkEmployees(); }
        }

        function onBulkShiftChanged() {
            bulkEditData.shiftPattern = document.getElementById('bulk-shift-filter')?.value || bulkEditData.shiftPattern;
            try { loadBulkEmployees(); } catch(e) { filterBulkEmployees(); }
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
                const formattedDate = emp.date ? new Date(emp.date).toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: '2-digit', 
                    year: 'numeric' 
                }) : 'N/A';
                // Align with main attendance: Present only if (a) marked present AND (b) has any scan today AND (c) the record date is today
                const hasAnyTime = !!(emp.timeIn || emp.timeOut || emp.timeInMorning || emp.timeOutMorning || emp.timeInAfternoon || emp.timeOutAfternoon);
                const isToday = (emp.date ? String(emp.date).slice(0,10) : '') === serverToday;
                const present = ((emp.attendanceType || '').toLowerCase() === 'present') && hasAnyTime && isToday;
                const effectiveType = present ? 'present' : 'absent';
                
                // Format time display
                const timeDisplay = present ? 
                    `${emp.timeIn || emp.timeInMorning || emp.timeInAfternoon || 'N/A'} - ${emp.timeOut || emp.timeOutAfternoon || emp.timeOutMorning || 'N/A'}` : 
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
                                <span class="attn-badge ${effectiveType === 'present' ? 'present' : 'absent'}">${effectiveType === 'present' ? 'Present' : 'Absent'}</span>
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
                                <span class="detail-label">Date:</span> ${formattedDate} | 
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
                // Enforce single-shift constraint
                const distinctShifts = Array.from(new Set(bulkEditData.selectedEmployees.map(e => (e.shift || '').toLowerCase()).filter(Boolean)));
                const newShift = (shift || '').toLowerCase();
                if (distinctShifts.length > 0 && newShift && !distinctShifts.includes(newShift)) {
                    showNotification('Please select employees with the same shift only (single shift per bulk edit).', 'warning');
                    return;
                }
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
                const department = employeeDetails.match(/Department: ([^|]+)/)?.[1]?.trim();
                
                if (employeeId) {
                    if (selectAll.checked) {
                        // Add if not already selected
                        if (!bulkEditData.selectedEmployees.some(emp => emp.id === employeeId)) {
                            bulkEditData.selectedEmployees.push({
                                id: employeeId,
                                name: employeeName,
                                department: department || ''
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
            if (nextBtn) {
                nextBtn.disabled = count === 0;
                if (count > 0) {
                    nextBtn.style.background = 'linear-gradient(135deg, #3F72AF 0%, #16C79A 100%)';
                    nextBtn.style.transform = 'translateY(-2px)';
                    nextBtn.style.boxShadow = '0 6px 20px rgba(63, 114, 175, 0.3)';
                } else {
                    nextBtn.style.background = '#ccc';
                    nextBtn.style.transform = 'translateY(0)';
                    nextBtn.style.boxShadow = 'none';
                }
            }
        }

        function proceedToStep2() {
            nextBulkStep();
        }

        function nextBulkStep() {
            if (bulkEditData.currentStep === 1) {
                if (bulkEditData.selectedEmployees.length === 0) {
                    alert('Please select at least one employee.');
                    return;
                }
                // If exactly one employee is selected, capture their shift pattern for defaults
                try {
                    if (bulkEditData.selectedEmployees.length === 1) {
                        const onlyEmp = bulkEditData.selectedEmployees[0];
                        if (onlyEmp && onlyEmp.shift) {
                            bulkEditData.shiftPattern = onlyEmp.shift;
                        }
                    }
                } catch (e) { /* no-op */ }
                showBulkStep(2);
                // Activate stepper
                const s1 = document.getElementById('bulk-stepper-1');
                const s2 = document.getElementById('bulk-stepper-2');
                if (s1) s1.classList.remove('active');
                if (s2) s2.classList.add('active');
                // reactive button state
                document.getElementById('bulk-next-btn').disabled = !bulkEditData.selectedShift && bulkEditData.currentStep !== 3;
            } else if (bulkEditData.currentStep === 2) {
                if (!bulkEditData.selectedShift) {
                    alert('Please select a shift type.');
                    return;
                }
                showBulkStep(3);
                const s2 = document.getElementById('bulk-stepper-2');
                const s3 = document.getElementById('bulk-stepper-3');
                if (s2) s2.classList.remove('active');
                if (s3) s3.classList.add('active');
            }
        }

        function previousBulkStep() {
            if (bulkEditData.currentStep > 1) {
                showBulkStep(bulkEditData.currentStep - 1);
                const s1 = document.getElementById('bulk-stepper-1');
                const s2 = document.getElementById('bulk-stepper-2');
                const s3 = document.getElementById('bulk-stepper-3');
                if (bulkEditData.currentStep - 1 === 1) {
                    if (s2) s2.classList.remove('active');
                    if (s1) s1.classList.add('active');
                } else if (bulkEditData.currentStep - 1 === 2) {
                    if (s3) s3.classList.remove('active');
                    if (s2) s2.classList.add('active');
                }
            }
        }

        function showBulkStep(step) {
            // Hide all steps
            document.querySelectorAll('.bulk-step').forEach(stepEl => {
                // add exit anim where applicable
                if (stepEl.style.display !== 'none') {
                    stepEl.classList.remove('step-anim-enter');
                    stepEl.classList.add('step-anim-exit');
                    setTimeout(() => { stepEl.style.display = 'none'; stepEl.classList.remove('step-anim-exit'); }, 180);
                } else {
                    stepEl.style.display = 'none';
                }
            });
            
            // Show current step
            const activeStep = document.getElementById(`bulk-step-${step}`);
            activeStep.style.display = 'block';
            activeStep.classList.add('step-anim-enter');
            setTimeout(() => activeStep.classList.remove('step-anim-enter'), 280);
            
            // Update buttons
            const prevBtn = document.getElementById('bulk-prev-btn');
            const nextBtn = document.getElementById('bulk-next-btn');
            const saveBtn = document.getElementById('bulk-save-btn');
            
            prevBtn.style.display = step > 1 ? 'inline-block' : 'none';
            nextBtn.style.display = step < 3 ? 'inline-block' : 'none';
            saveBtn.style.display = step === 3 ? 'inline-block' : 'none';
            if (step === 3) {
                // Ensure Save/Cancel/Previous are aligned and Next hidden
                nextBtn.style.display = 'none';
            }
            
            bulkEditData.currentStep = step;
            
            // Capture the chosen shift pattern from Step 1 filters (fallback to existing or default)
            const step1ShiftSel = document.getElementById('bulk-shift-filter');
            if (step1ShiftSel && step1ShiftSel.value) {
                bulkEditData.shiftPattern = step1ShiftSel.value;
            } else if (!bulkEditData.shiftPattern) {
                bulkEditData.shiftPattern = '08:00-17:00';
            }

            // Update stepper UI with activation animation
            try {
                const s1 = document.getElementById('bulk-stepper-1');
                const s2 = document.getElementById('bulk-stepper-2');
                const s3 = document.getElementById('bulk-stepper-3');
                [s1, s2, s3].forEach((el, idx) => {
                    if (!el) return;
                    const isActive = (idx + 1) === step;
                    if (isActive) {
                        el.classList.add('active');
                        el.classList.add('just-activated');
                        setTimeout(() => el.classList.remove('just-activated'), 240);
                    } else {
                        el.classList.remove('active');
                    }
                });
            } catch (e) { /* no-op */ }

            if (step === 3) {
                updateShiftInfoDisplay();
                // Auto-fill default times when entering step 3
                if (bulkEditData.selectedShift) {
                    autofillShiftTimes(bulkEditData.selectedShift);
                }
                // If exactly one employee selected, use their own shift to set defaults
                try {
                    if (Array.isArray(bulkEditData.selectedEmployees) && bulkEditData.selectedEmployees.length === 1) {
                        const onlyEmp = bulkEditData.selectedEmployees[0];
                        const empShift = (onlyEmp && onlyEmp.shift) ? onlyEmp.shift : '';
                        if (empShift) {
                            bulkEditData.shiftPattern = empShift; // prefer this pattern
                            // Keep currently selectedShift (morning/afternoon/full) behavior but use employee's pattern for times
                            const type = bulkEditData.selectedShift || 'full';
                            autofillShiftTimes(type);
                        }
                    }
                } catch (e) { /* safe fallback */ }
            }

            // Update progress bar
            try {
                const bar = document.getElementById('bulkProgressBar');
                if (bar) {
                    const pct = step === 1 ? 33 : (step === 2 ? 66 : 100);
                    bar.style.width = pct + '%';
                }
            } catch (e) {}
        }

        function selectBulkShift(shiftType) {
            // Remove previous selection
            document.querySelectorAll('.shift-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add selection to clicked option
            event.currentTarget.classList.add('selected');
            
            bulkEditData.selectedShift = shiftType;
            
            // Enable next button
            const nb = document.getElementById('bulk-next-btn');
            if (nb) nb.disabled = false;
            
            // Auto-fill default times when moving to step 3
            if (bulkEditData.currentStep === 2) {
                setTimeout(() => {
                    autofillShiftTimes(shiftType);
                }, 100);
            }

            // Update the layout and info immediately
            updateShiftInfoDisplay();
        }
        
        // Returns the AM/PM default times based on shift pattern (Step 1)
        function getShiftDefaultsByPattern(pattern) {
            // Normalize pattern/aliases
            const p = (pattern || '').toLowerCase();
            // Map multiple aliases to standard patterns
            let key = '08:00-17:00';
            if (p.includes('08:30') || p.includes('8:30-5:30')) key = '08:30-17:30';
            else if (p.includes('09:00') || p.includes('9am-6pm')) key = '09:00-18:00';
            else if (p.includes('08:00') || p.includes('8-5pb') || p.includes('08:00-17:00pb')) key = '08:00-17:00';

            const map = {
                '08:00-17:00': { am_in: '08:00', am_out: '12:00', pm_in: '13:00', pm_out: '17:00' },
                '08:30-17:30': { am_in: '08:30', am_out: '12:30', pm_in: '13:30', pm_out: '17:30' },
                '09:00-18:00': { am_in: '09:00', am_out: '13:00', pm_in: '14:00', pm_out: '18:00' }
            };
            return map[key] || map['08:00-17:00'];
        }

        function autofillShiftTimes(shiftType) {
            // Determine the pattern selected in Step 1 (fallback default)
            // If exactly one employee is selected, prefer their shift for defaults
            let pattern = bulkEditData.shiftPattern || (document.getElementById('bulk-shift-filter')?.value) || '08:00-17:00';
            try {
                if (Array.isArray(bulkEditData.selectedEmployees) && bulkEditData.selectedEmployees.length === 1) {
                    const empShift = bulkEditData.selectedEmployees[0].shift || '';
                    if (empShift) pattern = empShift;
                }
            } catch (e) { /* ignore */ }
            const defaults = getShiftDefaultsByPattern(pattern);

            const amInField = document.getElementById('bulk_am_in');
            const amOutField = document.getElementById('bulk_am_out');
            const pmInField = document.getElementById('bulk_pm_in');
            const pmOutField = document.getElementById('bulk_pm_out');

            // Always override values when autofill is called
            if (shiftType === 'morning' || shiftType === 'full') {
                if (amInField) amInField.value = defaults.am_in;
                if (amOutField) amOutField.value = defaults.am_out;
            }
            if (shiftType === 'afternoon' || shiftType === 'full') {
                if (pmInField) pmInField.value = defaults.pm_in;
                if (pmOutField) pmOutField.value = defaults.pm_out;
            }
        }
        
        function markSelectedAsAbsent() {
            if (bulkEditData.selectedEmployees.length === 0) {
                alert('No employees selected.');
                return;
            }
            
            const confirmMessage = `Are you sure you want to mark ${bulkEditData.selectedEmployees.length} employee(s) as absent? This will delete their attendance records for today.`;
            if (!confirm(confirmMessage)) {
                return;
            }
            
            // Use server's today date to ensure consistency
            const attendanceDate = serverToday;
            
            // Collect form data
            const formData = new FormData();
            formData.append('action', 'mark_absent');
            formData.append('employees', JSON.stringify(bulkEditData.selectedEmployees));
            formData.append('attendance_date', attendanceDate);
            
            const notes = document.getElementById('bulk_notes').value;
            if (notes) formData.append('notes', notes);
            
            // Show loading state
            const markAbsentBtn = event.target;
            const originalText = markAbsentBtn.innerHTML;
            markAbsentBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking Absent...';
            markAbsentBtn.disabled = true;
            
            // Submit to backend
            fetch('bulk_edit_attendance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Successfully marked ${data.updated_count} employee(s) as absent.`);
                    // Keep modal open and refresh both the right list and main table
                    loadBulkEmployees();
                    refreshAttendanceData();
                } else {
                    alert('Error: ' + (data.message || 'Failed to mark employees as absent.'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error marking employees as absent. Please try again.');
            })
            .finally(() => {
                markAbsentBtn.innerHTML = originalText;
                markAbsentBtn.disabled = false;
            });
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

            // Dynamically adjust grid to avoid blank columns when only one section is shown
            const formGrid = document.querySelector('#bulk-step-3 .form-grid');
            if (formGrid) {
                formGrid.style.display = 'grid';
                formGrid.style.gridTemplateColumns = (showMorning && showAfternoon) ? '1fr 1fr' : '1fr';
                formGrid.style.gap = '30px';
            }
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

        function submitBulkEdit() {
            if (bulkEditData.selectedEmployees.length === 0) {
                alert('No employees selected.');
                return;
            }
            
            if (!bulkEditData.selectedShift) {
                alert('No shift type selected.');
                return;
            }
            
            // Use server's today date to ensure consistency with main attendance page
            const attendanceDate = serverToday;
            
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
        
        // Add overtime fields
        const markOT = document.getElementById('bulk_mark_ot');
        const noOT = document.getElementById('bulk_no_overtime');
        const overtimeHours = document.getElementById('bulk_overtime_hours');
        const noTimeout = document.getElementById('bulk_no_timeout');
        
        if (markOT && markOT.checked && !noOT.checked) {
            formData.append('is_overtime', '1');
            // Always send overtime_hours when marking overtime, default to 0 if empty
            const otHours = overtimeHours && overtimeHours.value ? overtimeHours.value : '0';
            formData.append('overtime_hours', otHours);
        } else if (noOT && noOT.checked) {
            formData.append('is_overtime', '0');
            formData.append('overtime_hours', '0');
        }
        
        if (noTimeout && noTimeout.checked) {
            formData.append('no_time_out', '1');
        }
        
        // Add half-day fields
        const markHalfDay = document.getElementById('bulk_mark_half_day');
        const halfDaySession = document.getElementById('bulk_halfday_session');
        
        if (markHalfDay && markHalfDay.checked) {
            formData.append('mark_half_day', '1');
            if (halfDaySession && halfDaySession.value) {
                formData.append('halfday_session', halfDaySession.value);
            }
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
        console.log('Overtime fields:', {
            is_overtime: markOT?.checked && !noOT?.checked ? '1' : (noOT?.checked ? '0' : 'not set'),
            overtime_hours: overtimeHours?.value || '0',
            no_time_out: noTimeout?.checked ? '1' : '0'
        });
        console.log('Half-day fields:', {
            mark_half_day: markHalfDay?.checked ? '1' : '0',
            halfday_session: markHalfDay?.checked ? halfDaySession?.value : 'not applicable'
        });
        
        // Show loading state
        const saveBtn = document.getElementById('bulk-save-btn');
            const originalText = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            saveBtn.disabled = true;
            
            // Submit to backend (attendance date not used in this modal anymore)

            fetch('bulk_edit_attendance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // In-app notification instead of alert
                    showNotification(`Successfully updated attendance for ${data.updated_count} employee${data.updated_count !== 1 ? 's' : ''}.`, 'success');
                    // Live refresh both the right-side list and the main table without closing the modal
                    loadBulkEmployees();
                    refreshAttendanceData();
                    // Close and fully reset the bulk edit modal for next use
                    try {
                        closeBulkEditModal();
                        // Reset internal state
                        bulkEditData = { currentStep: 1, selectedEmployees: [], selectedShift: null, allEmployees: [], shiftPattern: null };
                        // Reset UI counters and checkboxes
                        const selCount = document.getElementById('selected-count');
                        if (selCount) selCount.textContent = '0 employees selected';
                        const selectAll = document.getElementById('select-all-employees');
                        if (selectAll) selectAll.checked = false;
                        // Ensure step 1 is the visible step next time modal opens
                        showBulkStep(1);
                        const s1 = document.getElementById('bulk-stepper-1');
                        const s2 = document.getElementById('bulk-stepper-2');
                        const s3 = document.getElementById('bulk-stepper-3');
                        [s1, s2, s3].forEach((el, idx) => {
                            if (!el) return;
                            if (idx === 0) el.classList.add('active'); else el.classList.remove('active');
                        });
                        // Clear any time inputs/notes for a clean slate
                        const form = document.getElementById('bulkEditForm');
                        if (form) form.reset();
                    } catch (e) { /* no-op */ }
                } else {
                    console.error('Backend error:', data);
                    let errorMessage = 'Error: ' + (data.message || 'Failed to update attendance.');
                    if (data.debug_info) {
                        errorMessage += ` Debug: ${JSON.stringify(data.debug_info)}`;
                    }
                    showNotification(errorMessage, 'error');
                }
            })
            .catch(error => {
                console.error('Network/Fetch error:', error);
                showNotification('Error updating attendance. Please try again.', 'error');
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
            const tbody = document.querySelector('#attendance-results');
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
            document.getElementById('btn-first').disabled = currentPage === 1;
            document.getElementById('btn-prev').disabled = currentPage === 1;
            document.getElementById('btn-next').disabled = currentPage === totalPages;
            document.getElementById('btn-last').disabled = currentPage === totalPages;
            
            // Update page buttons
            renderPageButtons(totalPages);
        }
        
        function renderPageButtons(totalPages) {
            const pagesContainer = document.getElementById('pagination-pages');
            pagesContainer.innerHTML = '';
            
            const { currentPage } = paginationState;
            
            // Calculate which pages to show (show max 5 page buttons)
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            
            // Adjust if we're near the end
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
            setTimeout(initializePagination, 100); // Small delay to ensure table is rendered
        });
        
        // Re-initialize pagination when table is updated (e.g., after AJAX refresh)
        const originalRefreshAttendanceData = window.refreshAttendanceData;
        if (typeof originalRefreshAttendanceData === 'function') {
            window.refreshAttendanceData = function() {
                originalRefreshAttendanceData();
                setTimeout(initializePagination, 200); // Re-paginate after refresh
            };
        }

        // Leave status functionality
        function toggleLeaveStatus() {
            const leaveCheckbox = document.getElementById('edit_is_on_leave');
            const leaveBalanceInfo = document.getElementById('leaveBalanceInfo');
            const leaveBalanceText = document.getElementById('leaveBalanceText');
            
            if (leaveCheckbox.checked) {
                // Show leave balance info
                leaveBalanceInfo.style.display = 'block';
                fetchLeaveBalance();
            } else {
                // Hide leave balance info
                leaveBalanceInfo.style.display = 'none';
            }
        }

        function fetchLeaveBalance() {
            const employeeId = document.getElementById('edit_id').value;
            if (!employeeId || employeeId === '0') {
                document.getElementById('leaveBalanceText').textContent = 'Please select an employee first';
                return;
            }

            // Get employee ID from the row data
            const row = document.querySelector(`tr[data-id='${employeeId}']`);
            if (!row) {
                document.getElementById('leaveBalanceText').textContent = 'Employee not found';
                return;
            }

            const empId = row.getAttribute('data-employee_id');
            if (!empId) {
                document.getElementById('leaveBalanceText').textContent = 'Employee ID not found';
                return;
            }

            // Fetch leave balance via AJAX
            fetch('get_employee_leave_balance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `employee_id=${encodeURIComponent(empId)}`
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
        window.openEditAttendance = function(id) {
            originalOpenEditAttendance(id);
            
            // Reset leave status
            const leaveCheckbox = document.getElementById('edit_is_on_leave');
            const leaveBalanceInfo = document.getElementById('leaveBalanceInfo');
            leaveCheckbox.checked = false;
            leaveCheckbox.disabled = false;
            leaveBalanceInfo.style.display = 'none';
            
            // If this is an existing record, check if it's already on leave
            if (id && id !== '0') {
                const row = document.querySelector(`tr[data-id='${id}']`);
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