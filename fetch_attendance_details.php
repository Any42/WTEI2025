<?php
session_start();

// Check if user is logged in and is a Department Head
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'depthead') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Department Head access required']);
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

// Database connection
$host = "localhost";
$user = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($host, $user, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'Database connection failed']));
}

// Get parameters
$employee_id = isset($_GET['employee_id']) ? $conn->real_escape_string($_GET['employee_id']) : '';
$attendance_date = isset($_GET['attendance_date']) ? $conn->real_escape_string($_GET['attendance_date']) : '';

// Debug logging
error_log("fetch_attendance_details.php called with employee_id: $employee_id, attendance_date: $attendance_date");

if (empty($employee_id) || empty($attendance_date)) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

// Query to get detailed attendance information
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
    a.is_overtime,
    e.EmployeeName,
    e.Department,
    e.Shift,
    e.DateHired
FROM attendance a 
JOIN empuser e ON a.EmployeeID = e.EmployeeID
WHERE a.EmployeeID = ? AND DATE(a.attendance_date) = ?
LIMIT 1";

$stmt = $conn->prepare($query);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("ss", $employee_id, $attendance_date);
$stmt->execute();
$result = $stmt->get_result();

error_log("Main attendance query returned " . $result->num_rows . " rows");

if ($result->num_rows === 0) {
    // If no attendance record found, get employee info and create absent record
    $employee_query = "SELECT 
        EmployeeID,
        EmployeeName,
        Department,
        Shift,
        DateHired
    FROM empuser 
    WHERE EmployeeID = ? AND Status = 'active'
    LIMIT 1";
    
    $emp_stmt = $conn->prepare($employee_query);
    if ($emp_stmt) {
        $emp_stmt->bind_param("s", $employee_id);
        $emp_stmt->execute();
        $emp_result = $emp_stmt->get_result();
        
        if ($emp_result->num_rows > 0) {
            $employee_data = $emp_result->fetch_assoc();
            
            // Create absent record structure
            $attendance_data = [
                'id' => 0,
                'EmployeeID' => $employee_data['EmployeeID'],
                'attendance_date' => $attendance_date,
                'attendance_type' => 'absent',
                'status' => 'no_record',
                'time_in' => null,
                'time_out' => null,
                'time_in_morning' => null,
                'time_out_morning' => null,
                'time_in_afternoon' => null,
                'time_out_afternoon' => null,
                'overtime_hours' => 0,
                'late_minutes' => 0,
                'early_out_minutes' => 0,
                'is_overtime' => 0,
                'EmployeeName' => $employee_data['EmployeeName'],
                'Department' => $employee_data['Department'],
                'Shift' => $employee_data['Shift'],
                'DateHired' => $employee_data['DateHired']
            ];
        } else {
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
            exit;
        }
        $emp_stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Employee query failed']);
        exit;
    }
} else {
    $attendance_data = $result->fetch_assoc();
    error_log("Attendance data found: " . json_encode($attendance_data));
}

$stmt->close();
$conn->close();

// Apply attendance calculations
$attendance_records = [$attendance_data];
$calculated_records = AttendanceCalculator::calculateAttendanceMetrics($attendance_records);
$attendance_data = $calculated_records[0];

// Format the response
$response = [
    'success' => true,
    'data' => [
        'id' => $attendance_data['id'],
        'EmployeeID' => $attendance_data['EmployeeID'],
        'EmployeeName' => $attendance_data['EmployeeName'],
        'Department' => $attendance_data['Department'],
        'Shift' => formatShiftTime($attendance_data['Shift']),
        'attendance_date' => $attendance_data['attendance_date'],
        'attendance_type' => $attendance_data['attendance_type'],
        'status' => $attendance_data['status'],
        'time_in' => formatTime12Hour($attendance_data['time_in']),
        'time_out' => formatTime12Hour($attendance_data['time_out']),
        'time_in_morning' => formatTime12Hour($attendance_data['time_in_morning']),
        'time_out_morning' => formatTime12Hour($attendance_data['time_out_morning']),
        'time_in_afternoon' => formatTime12Hour($attendance_data['time_in_afternoon']),
        'time_out_afternoon' => formatTime12Hour($attendance_data['time_out_afternoon']),
        'overtime_hours' => $attendance_data['overtime_hours'],
        'late_minutes' => $attendance_data['late_minutes'],
        'early_out_minutes' => $attendance_data['early_out_minutes'],
        'is_overtime' => $attendance_data['is_overtime']
    ]
];

header('Content-Type: application/json');
error_log("Sending response: " . json_encode($response));
echo json_encode($response);
?>
