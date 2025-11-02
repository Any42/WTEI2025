<?php
session_start();

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$host = 'localhost';
$user = 'root';
$password = '';
$dbname = 'wteimain1';

$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}
$conn->query("SET time_zone = '+08:00'");

// Include attendance calculations for proper status computation
require_once 'attendance_calculations.php';

// Inputs
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'all';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$department = isset($_GET['department']) ? trim($_GET['department']) : '';
$shift = isset($_GET['shift']) ? trim($_GET['shift']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';

$date = isset($_GET['date']) ? $_GET['date'] : '';
$month = isset($_GET['month']) ? $_GET['month'] : '';
$year = isset($_GET['year']) ? $_GET['year'] : '';
$start_month = isset($_GET['start_month']) ? $_GET['start_month'] : '';
$end_month = isset($_GET['end_month']) ? $_GET['end_month'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Build base WHERE for employees
$employee_where = ["e.Status='active'"];
$employee_params = [];
$employee_types = '';

if ($search_term !== '') {
    $employee_where[] = "(e.EmployeeName LIKE ? OR e.EmployeeID LIKE ?)";
    $like = "%$search_term%";
    $employee_params[] = $like; $employee_params[] = $like;
    $employee_types .= 'ss';
}
if ($department !== '') { $employee_where[] = "e.Department = ?"; $employee_params[] = $department; $employee_types .= 's'; }
if ($shift !== '') { $employee_where[] = "e.Shift = ?"; $employee_params[] = $shift; $employee_types .= 's'; }

// Attendance date constraint
$attendance_condition = '';
$attendance_params = [];
$attendance_types = '';

// Determine if we should generate working-day rows (ensures absent records with dates appear)
$range_start = '';
$range_end = '';
if ($mode === 'date' && $date !== '') {
    $range_start = $date;
    $range_end = $date;
} elseif ($mode === 'month' && $month !== '') {
    $range_start = $month . '-01';
    $range_end = date('Y-m-t', strtotime($range_start));
} elseif ($mode === 'year') {
    $y = $year !== '' ? $year : date('Y');
    $sm = $start_month !== '' ? str_pad($start_month, 2, '0', STR_PAD_LEFT) : '01';
    $em = $end_month !== '' ? str_pad($end_month, 2, '0', STR_PAD_LEFT) : '12';
    $range_start = $y . '-' . $sm . '-01';
    $range_end = date('Y-m-t', strtotime($y . '-' . $em . '-01'));
} elseif ($mode === 'daterange' && $start_date !== '' && $end_date !== '') {
    $range_start = $start_date;
    $range_end = $end_date;
}

// Status filter
$status_condition = '';
if ($status !== '') {
    if ($status === 'absent') {
        $status_condition = " AND (a.id IS NULL OR a.attendance_type = 'absent')";
    } elseif ($status === 'present') {
        $status_condition = " AND a.attendance_type = 'present'";
    } else {
        $status_condition = " AND a.status = ?";
        $attendance_params[] = $status; $attendance_types .= 's';
    }
}

// Build query. If we have a date range, generate working-day rows and left join attendance by date.
$use_working_days = ($range_start !== '' && $range_end !== '');

if ($use_working_days) {
    // Generate working dates (exclude Sundays) list for CROSS JOIN
    $working_days = [];
    $start_dt = new DateTime($range_start);
    $end_dt = new DateTime($range_end);
    while ($start_dt <= $end_dt) {
        if ((int)$start_dt->format('w') !== 0) { // exclude Sundays
            $working_days[] = $start_dt->format('Y-m-d');
        }
        $start_dt->add(new DateInterval('P1D'));
    }
    if (empty($working_days)) {
        // fallback: include range_start at least
        $working_days[] = $range_start;
    }
    $wd_select = "SELECT '" . $conn->real_escape_string($working_days[0]) . "' as working_date";
    for ($i = 1; $i < count($working_days); $i++) {
        $wd_select .= " UNION ALL SELECT '" . $conn->real_escape_string($working_days[$i]) . "'";
    }

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
                COALESCE(a.notes, '') as notes,
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
              CROSS JOIN ( $wd_select ) wd
              LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND DATE(a.attendance_date) = wd.working_date
              WHERE " . implode(' AND ', $employee_where) . $status_condition . "
              ORDER BY wd.working_date DESC, COALESCE(a.time_in, '00:00:00') DESC";

    $types = $employee_types; // no attendance date params due to inline wd
    $bind_params = $employee_params;
} else {
    // Fallback: basic LEFT JOIN without generated dates
    // $attendance_condition is already computed above (may be empty)
    $query = "SELECT 
                COALESCE(a.id, 0) as id,
                e.EmployeeID,
                COALESCE(a.attendance_date, NULL) as attendance_date,
                COALESCE(a.attendance_type, 'absent') as attendance_type,
                COALESCE(a.status, 'no_record') as status,
                COALESCE(a.time_in, NULL) as time_in,
                COALESCE(a.time_out, NULL) as time_out,
                COALESCE(a.time_in_morning, NULL) as time_in_morning,
                COALESCE(a.time_out_morning, NULL) as time_out_morning,
                COALESCE(a.time_in_afternoon, NULL) as time_in_afternoon,
                COALESCE(a.time_out_afternoon, NULL) as time_out_afternoon,
                COALESCE(a.notes, '') as notes,
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
              LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID
              WHERE " . implode(' AND ', $employee_where) . " " . ($attendance_condition !== '' ? $attendance_condition : '') . $status_condition . "
              ORDER BY COALESCE(a.attendance_date, '1900-01-01') DESC, COALESCE(a.time_in, '00:00:00') DESC";

    $types = $employee_types . $attendance_types;
    $bind_params = array_merge($employee_params, $attendance_params);
}

$stmt = $conn->prepare($query);
if (!$stmt) { die('Prepare failed: ' . $conn->error); }
if (!empty($bind_params)) { $stmt->bind_param($types, ...$bind_params); }
if (!$stmt->execute()) { die('Execute failed: ' . $stmt->error); }
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) { $rows[] = $row; }
$stmt->close();

// Apply attendance calculations to get proper status values
$rows = AttendanceCalculator::calculateAttendanceMetrics($rows);

$conn->close();

// Filename
$parts = ['Attendance_Record'];
if ($mode === 'date' && $date) { $parts[] = $date; }
elseif ($mode === 'month' && $month) { $parts[] = $month; }
elseif ($mode === 'year' && $year) { $parts[] = $year . '-' . ($start_month ?: '01') . '_to_' . ($end_month ?: '12'); }
elseif ($mode === 'daterange' && $start_date && $end_date) { $parts[] = $start_date . '_to_' . $end_date; }
else { $parts[] = date('Y-m-d'); }
if ($department !== '') { $parts[] = 'Dept-' . preg_replace('/[^A-Za-z0-9-_ ]/','', $department); }
if ($shift !== '') { $parts[] = 'Shift-' . preg_replace('/[^A-Za-z0-9-_: ]/','', $shift); }
if ($status !== '') { $parts[] = 'Status-' . $status; }
if ($search_term !== '') { $parts[] = 'Search'; }
$filename = implode('_', $parts) . '.xls';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Use HTML table format for better Excel compatibility with proper column widths
echo '<?xml version="1.0"?>';
echo '<?mso-application progid="Excel.Sheet"?>';
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
    xmlns:o="urn:schemas-microsoft-com:office:office"
    xmlns:x="urn:schemas-microsoft-com:office:excel"
    xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
    xmlns:html="http://www.w3.org/TR/REC-html40">';

echo '<Styles>
    <Style ss:ID="Header">
        <Font ss:Bold="1" ss:Color="#FFFFFF"/>
        <Interior ss:Color="#3F72AF" ss:Pattern="Solid"/>
        <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    </Style>
    <Style ss:ID="Date">
        <NumberFormat ss:Format="mmm dd, yyyy"/>
    </Style>
    <Style ss:ID="Number">
        <NumberFormat ss:Format="0.00"/>
    </Style>
    <Style ss:ID="Integer">
        <NumberFormat ss:Format="0"/>
    </Style>
</Styles>';

echo '<Worksheet ss:Name="Attendance History">
    <Table>';

// Define column widths (in pixels)
echo '<Column ss:Width="120"/>'; // Date
echo '<Column ss:Width="200"/>'; // Employee Name
echo '<Column ss:Width="100"/>'; // Employee ID
echo '<Column ss:Width="150"/>'; // Department
echo '<Column ss:Width="120"/>'; // Shift
echo '<Column ss:Width="100"/>'; // Time In
echo '<Column ss:Width="100"/>'; // Time Out
echo '<Column ss:Width="120"/>'; // Time In Morning
echo '<Column ss:Width="120"/>'; // Time Out Morning
echo '<Column ss:Width="120"/>'; // Time In Afternoon
echo '<Column ss:Width="120"/>'; // Time Out Afternoon
echo '<Column ss:Width="130"/>'; // Attendance Type
echo '<Column ss:Width="100"/>'; // Status
echo '<Column ss:Width="100"/>'; // On Leave
echo '<Column ss:Width="100"/>'; // Late Minutes
echo '<Column ss:Width="120"/>'; // Early Out Minutes
echo '<Column ss:Width="120"/>'; // Overtime Hours
echo '<Column ss:Width="100"/>'; // Total Hours

// Header row
echo '<Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Date</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Employee Name</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Employee ID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Department</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Shift</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time In</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time Out</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time In Morning</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time Out Morning</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time In Afternoon</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time Out Afternoon</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Attendance Type</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Status</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">On Leave</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Late Minutes</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Early Out Minutes</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Overtime Hours</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Total Hours</Data></Cell>
</Row>';

foreach ($rows as $r) {
    $dateStr = $r['attendance_date'] ? date('Y-m-d', strtotime($r['attendance_date'])) : '';
    
    // Status normalization matching the web app logic
    $status = $r['status'] ?? 'no_record';
    $attendance_type = $r['attendance_type'] ?? 'absent';
    
    // Handle status display text matching the web app
    if ($status === 'no_record' && $attendance_type === 'absent') {
        $status = 'absent';
    } elseif ($status === 'no_record' && $attendance_type === 'present') {
        $status = 'present';
    }
    
    // Format status text to match web app display
    switch($status) {
        case 'early_in':
            $statusText = 'Early In';
            break;
        case 'on_time':
            $statusText = 'On Time';
            break;
        case 'late':
            $statusText = 'Late';
            break;
        case 'halfday':
            $statusText = 'Half Day';
            break;
        case 'absent':
            $statusText = 'Absent';
            break;
        case 'present':
            $statusText = 'Present';
            break;
        case 'no_record':
            $statusText = 'No Record';
            break;
        default:
            $statusText = ucfirst($status);
            break;
    }
    
    // Time data
    $time_in = $r['time_in'] ?? '';
    $time_out = $r['time_out'] ?? '';
    $time_in_morning = $r['time_in_morning'] ?? '';
    $time_out_morning = $r['time_out_morning'] ?? '';
    $time_in_afternoon = $r['time_in_afternoon'] ?? '';
    $time_out_afternoon = $r['time_out_afternoon'] ?? '';
    
    $employeeName = htmlspecialchars($r['EmployeeName'] ?? '', ENT_XML1);
    $employeeId = htmlspecialchars($r['EmployeeID'] ?? '', ENT_XML1);
    $department = htmlspecialchars($r['Department'] ?? '', ENT_XML1);
    $shift = htmlspecialchars($r['Shift'] ?? '', ENT_XML1);
    $attendanceType = htmlspecialchars(ucfirst($attendance_type ?? '-'), ENT_XML1);
    $statusText = htmlspecialchars($statusText, ENT_XML1);
    
    $lateMinutes = (int)($r['late_minutes'] ?? 0);
    $earlyOutMinutes = (int)($r['early_out_minutes'] ?? 0);
    $overtimeHours = (float)($r['overtime_hours'] ?? 0);
    $totalHours = (float)($r['total_hours'] ?? 0);
    $isOnLeave = (int)($r['is_on_leave'] ?? 0);
    $onLeaveText = $isOnLeave ? 'Yes' : 'No';
    
    echo '<Row>
        <Cell><Data ss:Type="String">' . htmlspecialchars($dateStr, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $employeeName . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $employeeId . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $department . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $shift . '</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_in, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_out, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_in_morning, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_out_morning, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_in_afternoon, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_out_afternoon, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $attendanceType . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $statusText . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $onLeaveText . '</Data></Cell>
        <Cell ss:StyleID="Integer"><Data ss:Type="Number">' . $lateMinutes . '</Data></Cell>
        <Cell ss:StyleID="Integer"><Data ss:Type="Number">' . $earlyOutMinutes . '</Data></Cell>
        <Cell ss:StyleID="Number"><Data ss:Type="Number">' . $overtimeHours . '</Data></Cell>
        <Cell ss:StyleID="Number"><Data ss:Type="Number">' . $totalHours . '</Data></Cell>
    </Row>';
}

echo '</Table>
</Worksheet>
</Workbook>';
exit;


