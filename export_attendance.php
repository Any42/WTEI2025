<?php
session_start();

// Require login (HR role assumed for this export page)
if (!isset($_SESSION['loggedin'])) {
    header("Location: login.php");
    exit();
}

date_default_timezone_set('Asia/Manila');

// Connect to the database
$host = "localhost";
$user = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->query("SET time_zone = '+08:00'");

// Include attendance calculations for proper status computation
require_once 'attendance_calculations.php';

// --- Mirror HRAttendance.php filter logic (today + optional filters) ---
$department_filter = isset($_GET['department']) ? $conn->real_escape_string($_GET['department']) : '';
$shift_filter = isset($_GET['shift']) ? $conn->real_escape_string($_GET['shift']) : '';
$late_only_filter = isset($_GET['late_only']) && $_GET['late_only'] == '1';
$type_filter = isset($_GET['attendance_type']) ? $conn->real_escape_string($_GET['attendance_type']) : '';
$no_timeout_filter = isset($_GET['no_timeout']) && $_GET['no_timeout'] == '1';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$today = date('Y-m-d');

$date_condition = "DATE(a.attendance_date) = ?";

$department_condition = !empty($department_filter) ? " AND e.Department = ?" : "";
$shift_condition = !empty($shift_filter) ? " AND e.Shift = ?" : "";
$status_condition = $late_only_filter ? " AND (a.status='late' OR a.late_minutes > 0)" : "";

$type_condition = "";
if (!empty($type_filter)) {
    if ($type_filter === 'present') {
        $type_condition = " AND a.attendance_type = 'present'";
    } elseif ($type_filter === 'absent') {
        $type_condition = " AND (a.attendance_type = 'absent' OR a.id IS NULL)";
    }
}

$no_timeout_condition = $no_timeout_filter ? " AND a.id IS NOT NULL AND a.attendance_type = 'present' AND a.time_out IS NULL" : "";

// Build query identical in spirit to HRAttendance (LEFT JOIN to include absents/no records)
$params = [];
$types = "";

$query = "SELECT 
            COALESCE(a.id, 0) as id,
            e.EmployeeID,
            COALESCE(a.attendance_date, ?) as attendance_date,
            COALESCE(a.attendance_type, 'absent') as attendance_type,
            COALESCE(a.status, 'no_record') as status,
            COALESCE(a.time_in, NULL) as time_in,
            COALESCE(a.time_out, NULL) as time_out,
            COALESCE(a.time_in_morning, NULL) as time_in_morning,
            COALESCE(a.time_out_morning, NULL) as time_out_morning,
            COALESCE(a.time_in_afternoon, NULL) as time_in_afternoon,
            COALESCE(a.time_out_afternoon, NULL) as time_out_afternoon,
            COALESCE(a.notes, 'No attendance record') as notes,
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

// Search condition (same as page)
if (!empty($search_term)) {
    $query = str_replace("WHERE e.Status = 'active'", "WHERE e.Status = 'active' AND (e.EmployeeName LIKE ? OR e.EmployeeID LIKE ?)", $query);
}

// Parameters for COALESCE and LEFT JOIN date
$params[] = $today; $types .= 's';
$params[] = $today; $types .= 's';

// Search params
if (!empty($search_term)) {
    $search_like = "%$search_term%";
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= 'ss';
}

// Append additional conditions
$query .= $department_condition . $shift_condition . $status_condition . $type_condition . $no_timeout_condition;

// Additional params
if (!empty($department_filter)) { $params[] = $department_filter; $types .= 's'; }
if (!empty($shift_filter)) { $params[] = $shift_filter; $types .= 's'; }

// Order by same as page
$query .= " ORDER BY COALESCE(a.attendance_date, ?) DESC, COALESCE(a.time_in, '00:00:00') DESC";
$params[] = $today; $types .= 's';

$stmt = $conn->prepare($query);
if (!$stmt) {
    die('Prepare failed: ' . $conn->error);
}
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
if (!$stmt->execute()) {
    die('Execute failed: ' . $stmt->error);
}
$result = $stmt->get_result();
$records = [];
while ($row = $result->fetch_assoc()) { $records[] = $row; }
$stmt->close();

// Apply attendance calculations to get proper status values
$records = AttendanceCalculator::calculateAttendanceMetrics($records);

$conn->close();

// Build filename: Attendance_Record_<YYYY-MM-DD>_<filters>.xls
$parts = [];
$parts[] = $today;
$parts[] = 'Dept-' . (!empty($department_filter) ? preg_replace('/[^A-Za-z0-9-_ ]/', '', $department_filter) : 'All');
$parts[] = 'Shift-' . (!empty($shift_filter) ? preg_replace('/[^A-Za-z0-9-_: ]/', '', $shift_filter) : 'All');
if (!empty($type_filter)) { $parts[] = 'Type-' . $type_filter; }
if ($late_only_filter) { $parts[] = 'LateOnly'; }
if ($no_timeout_filter) { $parts[] = 'NoTimeout'; }
if (!empty($search_term)) { $parts[] = 'Search'; }
$filename = 'Attendance_Record_' . implode('_', $parts) . '.xls';

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
</Styles>';

echo '<Worksheet ss:Name="Attendance Records">
    <Table>';

// Define column widths (in pixels)
echo '<Column ss:Width="100"/>'; // Employee ID
echo '<Column ss:Width="200"/>'; // Name
echo '<Column ss:Width="150"/>'; // Department
echo '<Column ss:Width="120"/>'; // Shift
echo '<Column ss:Width="120"/>'; // Date
echo '<Column ss:Width="100"/>'; // Time In
echo '<Column ss:Width="100"/>'; // Time Out
echo '<Column ss:Width="120"/>'; // Time In Morning
echo '<Column ss:Width="120"/>'; // Time Out Morning
echo '<Column ss:Width="120"/>'; // Time In Afternoon
echo '<Column ss:Width="120"/>'; // Time Out Afternoon
echo '<Column ss:Width="100"/>'; // Total Hours
echo '<Column ss:Width="120"/>'; // Overtime Hours
echo '<Column ss:Width="130"/>'; // Attendance Type
echo '<Column ss:Width="100"/>'; // Status
echo '<Column ss:Width="100"/>'; // On Leave
echo '<Column ss:Width="100"/>'; // Source

// Header row
echo '<Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Employee ID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Name</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Department</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Shift</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Date</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time In</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time Out</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time In Morning</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time Out Morning</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time In Afternoon</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Time Out Afternoon</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Total Hours</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Overtime Hours</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Attendance Type</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Status</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">On Leave</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Source</Data></Cell>
</Row>';

foreach ($records as $row) {
    // Status normalization matching the web app logic
    $status = $row['status'] ?? 'no_record';
    $attendance_type = $row['attendance_type'] ?? 'absent';
    
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

    $date = date('Y-m-d', strtotime($row['attendance_date']));
    $total_hours = isset($row['total_hours']) ? (float)$row['total_hours'] : 0;
    $overtime_hours = isset($row['overtime_hours']) ? (float)$row['overtime_hours'] : 0;
    $source = (!empty($row['notes']) && stripos($row['notes'], 'manual') !== false) ? 'Manual' : 'Biometric';
    $isOnLeave = (int)($row['is_on_leave'] ?? 0);
    $onLeaveText = $isOnLeave ? 'Yes' : 'No';
    
    // Time data
    $time_in = $row['time_in'] ?? '';
    $time_out = $row['time_out'] ?? '';
    $time_in_morning = $row['time_in_morning'] ?? '';
    $time_out_morning = $row['time_out_morning'] ?? '';
    $time_in_afternoon = $row['time_in_afternoon'] ?? '';
    $time_out_afternoon = $row['time_out_afternoon'] ?? '';
    
    $employeeId = htmlspecialchars($row['EmployeeID'] ?? '', ENT_XML1);
    $employeeName = htmlspecialchars($row['EmployeeName'] ?? '', ENT_XML1);
    $department = htmlspecialchars($row['Department'] ?? '', ENT_XML1);
    $shift = htmlspecialchars($row['Shift'] ?? '', ENT_XML1);
    $attendanceType = htmlspecialchars(ucfirst($attendance_type ?? '-'), ENT_XML1);
    $statusText = htmlspecialchars($statusText, ENT_XML1);
    
    echo '<Row>
        <Cell><Data ss:Type="String">' . $employeeId . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $employeeName . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $department . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $shift . '</Data></Cell>
        <Cell ss:StyleID="Date"><Data ss:Type="DateTime">' . $date . 'T00:00:00.000</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_in, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_out, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_in_morning, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_out_morning, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_in_afternoon, ENT_XML1) . '</Data></Cell>
        <Cell><Data ss:Type="String">' . htmlspecialchars($time_out_afternoon, ENT_XML1) . '</Data></Cell>
        <Cell ss:StyleID="Number"><Data ss:Type="Number">' . $total_hours . '</Data></Cell>
        <Cell ss:StyleID="Number"><Data ss:Type="Number">' . $overtime_hours . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $attendanceType . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $statusText . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $onLeaveText . '</Data></Cell>
        <Cell><Data ss:Type="String">' . $source . '</Data></Cell>
    </Row>';
}

echo '</Table>
</Worksheet>
</Workbook>';
exit;