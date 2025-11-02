<?php
header('Content-Type: application/json');

// Connect to the database
$host = "localhost";
$user = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($host, $user, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Get parameters
$employee_id = isset($_GET['employee_id']) ? $conn->real_escape_string($_GET['employee_id']) : '';
$date = isset($_GET['date']) ? $conn->real_escape_string($_GET['date']) : '';

if (empty($employee_id) || empty($date)) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

try {
    // Get employee details
    $employee_query = "SELECT EmployeeID, EmployeeName, Department, Position, Shift, DateHired, Status 
                       FROM empuser 
                       WHERE EmployeeID = ? AND Status = 'active'";
    
    $employee_stmt = $conn->prepare($employee_query);
    $employee_stmt->bind_param("s", $employee_id);
    $employee_stmt->execute();
    $employee_result = $employee_stmt->get_result();
    
    if ($employee_result->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Employee not found']);
        exit;
    }
    
    $employee = $employee_result->fetch_assoc();
    $employee_stmt->close();
    
    // Get attendance details for the specific date - use DATE() function for proper matching
    // Select all fields from attendance table as per database structure
    $attendance_query = "SELECT 
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
                         COALESCE(a.is_on_nsdot, 0) as is_on_nsdot
                         FROM attendance a
                         WHERE a.EmployeeID = ? AND DATE(a.attendance_date) = DATE(?)";
    
    $attendance_stmt = $conn->prepare($attendance_query);
    if (!$attendance_stmt) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
        exit;
    }
    
    $attendance_stmt->bind_param("ss", $employee_id, $date);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result();
    
    if ($attendance_result->num_rows === 0) {
        // No attendance record found, create a default absent record with all fields
        $attendance = [
            'id' => 0,
            'EmployeeID' => $employee_id,
            'attendance_date' => $date,
            'attendance_type' => 'absent',
            'status' => null,
            'time_in' => null,
            'time_out' => null,
            'time_in_morning' => null,
            'time_out_morning' => null,
            'time_in_afternoon' => null,
            'time_out_afternoon' => null,
            'notes' => null,
            'data_source' => 'biometric',
            'overtime_hours' => 0,
            'overtime_time_in' => null,
            'overtime_time_out' => null,
            'is_overtime' => 0,
            'late_minutes' => 0,
            'early_out_minutes' => 0,
            'total_hours' => 0,
            'is_on_leave' => 0,
            'nsd_ot_hours' => 0,
            'is_on_nsdot' => 0
        ];
    } else {
        $attendance = $attendance_result->fetch_assoc();
    }
    $attendance_stmt->close();
    
    // Apply attendance calculations if we have a real record
    if ($attendance['id'] > 0) {
        require_once 'attendance_calculations.php';
        // Add shift information to the attendance record for proper calculation
        $attendance['Shift'] = $employee['Shift'];
        $calculated_records = AttendanceCalculator::calculateAttendanceMetrics([$attendance]);
        if (!empty($calculated_records)) {
            $calculated = $calculated_records[0];
            // Preserve original database values, only update calculated fields
            $attendance['status'] = $calculated['status'] ?? $attendance['status'];
            $attendance['late_minutes'] = $calculated['late_minutes'] ?? $attendance['late_minutes'];
            $attendance['early_out_minutes'] = $calculated['early_out_minutes'] ?? $attendance['early_out_minutes'];
            $attendance['total_hours'] = $calculated['total_hours'] ?? $attendance['total_hours'];
            $attendance['overtime_hours'] = $calculated['overtime_hours'] ?? $attendance['overtime_hours'];
            $attendance['is_overtime'] = $calculated['is_overtime'] ?? $attendance['is_overtime'];
            // Ensure all database fields are present
            $attendance['data_source'] = $attendance['data_source'] ?? 'biometric';
            $attendance['notes'] = $attendance['notes'] ?? null;
            $attendance['overtime_time_in'] = $attendance['overtime_time_in'] ?? null;
            $attendance['overtime_time_out'] = $attendance['overtime_time_out'] ?? null;
            $attendance['nsd_ot_hours'] = $attendance['nsd_ot_hours'] ?? 0;
            $attendance['is_on_nsdot'] = $attendance['is_on_nsdot'] ?? 0;
        }
    }
    
    echo json_encode([
        'success' => true,
        'employee' => $employee,
        'attendance' => $attendance
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
?>
