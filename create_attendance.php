<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['loggedin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

// Align MySQL session timezone
$conn->query("SET time_zone = '+08:00'");

$employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
$attendance_date = isset($_POST['attendance_date']) ? trim($_POST['attendance_date']) : null;
$time_in = isset($_POST['time_in']) ? trim($_POST['time_in']) : null;
$time_out = isset($_POST['time_out']) ? trim($_POST['time_out']) : null;
$am_in = isset($_POST['am_in']) ? trim($_POST['am_in']) : null;
$am_out = isset($_POST['am_out']) ? trim($_POST['am_out']) : null;
$pm_in = isset($_POST['pm_in']) ? trim($_POST['pm_in']) : null;
$pm_out = isset($_POST['pm_out']) ? trim($_POST['pm_out']) : null;
$attendance_type = isset($_POST['attendance_type']) ? trim($_POST['attendance_type']) : 'present';
$status = isset($_POST['status']) ? trim($_POST['status']) : null;
$overtime_hours = isset($_POST['overtime_hours']) ? (float)$_POST['overtime_hours'] : 0;
$is_overtime = isset($_POST['is_overtime']) ? (int)$_POST['is_overtime'] : 0;
$half_day = isset($_POST['half_day']) ? (int)$_POST['half_day'] : 0;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
$is_on_leave = isset($_POST['is_on_leave']) ? (int)$_POST['is_on_leave'] : 0;

if ($employee_id <= 0 || !$attendance_date) {
    echo json_encode(['success' => false, 'message' => 'Invalid employee ID or attendance date']);
    $conn->close();
    exit;
}

// Check leave limits if setting employee on leave
if ($is_on_leave) {
    $leave_check_stmt = $conn->prepare("SELECT leave_pay_counts FROM empuser WHERE EmployeeID = ?");
    $leave_check_stmt->bind_param("i", $employee_id);
    $leave_check_stmt->execute();
    $leave_result = $leave_check_stmt->get_result();
    $employee_data = $leave_result->fetch_assoc();
    $leave_check_stmt->close();
    
    if (!$employee_data || $employee_data['leave_pay_counts'] <= 0) {
        echo json_encode(['success' => false, 'message' => 'Employee has no remaining leave days. Cannot set on leave.']);
        $conn->close();
        exit;
    }
}

// Normalize HH:MM to HH:MM:SS
$normalizeTime = function($t) {
    if ($t === null || $t === '') return $t;
    if (preg_match('/^\d{2}:\d{2}$/', $t)) {
        return $t . ':00';
    }
    return $t;
};

$time_in = $normalizeTime($time_in);
$time_out = $normalizeTime($time_out);
$am_in = $normalizeTime($am_in);
$am_out = $normalizeTime($am_out);
$pm_in = $normalizeTime($pm_in);
$pm_out = $normalizeTime($pm_out);

// Handle half day logic - clear afternoon times if half day
if ($half_day) {
    $pm_in = null;
    $pm_out = null;
}

// If employee is on leave, nullify all time inputs, set attendance_type to absent, and status to on_leave
if ($is_on_leave) {
    $time_in = null;
    $time_out = null;
    $am_in = null;
    $am_out = null;
    $pm_in = null;
    $pm_out = null;
    $attendance_type = 'absent';
    $status = 'on_leave';
}

// If split provided and single not provided, synthesize main in/out
if ((!$time_in || $time_in === '') && ($am_in || $pm_in)) {
    $time_in = $am_in ?: $pm_in;
}
if ((!$time_out || $time_out === '') && ($pm_out || $am_out)) {
    $time_out = $pm_out ?: $am_out;
}

// Check if record already exists
$check_stmt = $conn->prepare("SELECT id FROM attendance WHERE EmployeeID = ? AND attendance_date = ?");
$check_stmt->bind_param("is", $employee_id, $attendance_date);
$check_stmt->execute();
$check_result = $check_stmt->get_result();
$existing_record = $check_result->fetch_assoc();
$check_stmt->close();

if ($existing_record) {
    echo json_encode(['success' => false, 'message' => 'Attendance record already exists for this employee on this date']);
    $conn->close();
    exit;
}

// Insert new attendance record
$insert_sql = "INSERT INTO attendance (
    EmployeeID, attendance_date, attendance_type, status, 
    time_in, time_out, time_in_morning, time_out_morning, 
    time_in_afternoon, time_out_afternoon, notes, data_source,
    overtime_hours, is_overtime, late_minutes, early_out_minutes, is_on_leave
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($insert_sql);
$late_minutes = 0; // will be calculated
$early_out_minutes = 0; // will be calculated

$data_source = 'manual'; // Mark as manual entry
$notes = $notes ?: 'Manual Entry'; // Set default notes to "Manual Entry" when user touches record
$stmt->bind_param("isssssssssssdiiii",
    $employee_id,
    $attendance_date,
    $attendance_type,
    $status,
    $time_in,
    $time_out,
    $am_in,
    $am_out,
    $pm_in,
    $pm_out,
    $notes,
    $data_source,
    $overtime_hours,
    $is_overtime,
    $late_minutes,
    $early_out_minutes,
    $is_on_leave
);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to create attendance record']);
    $stmt->close();
    $conn->close();
    exit;
}

$new_id = $conn->insert_id;
$stmt->close();

// Deduct leave day if employee is set on leave
if ($is_on_leave) {
    $deduct_stmt = $conn->prepare("UPDATE empuser SET leave_pay_counts = leave_pay_counts - 1 WHERE EmployeeID = ?");
    $deduct_stmt->bind_param("i", $employee_id);
    $deduct_stmt->execute();
    $deduct_stmt->close();
}

require_once 'attendance_calculations.php';

// Re-fetch with joined shift for recalculation
$stmt2 = $conn->prepare("SELECT a.*, e.Shift, e.EmployeeName FROM attendance a JOIN empuser e ON a.EmployeeID = e.EmployeeID WHERE a.id = ?");
$stmt2->bind_param("i", $new_id);
$stmt2->execute();
$res2 = $stmt2->get_result();
$updated = $res2->fetch_assoc();
$stmt2->close();

if (!$updated) {
    echo json_encode(['success' => false, 'message' => 'Record not found post-creation']);
    $conn->close();
    exit;
}

$calc = AttendanceCalculator::calculateAttendanceMetrics([$updated]);
$rec = $calc[0];

// Check if manual overtime was provided in the form
$manual_overtime_provided = isset($_POST['overtime_hours']) && $_POST['overtime_hours'] !== '' && $_POST['overtime_hours'] > 0;
$manual_is_overtime_provided = isset($_POST['is_overtime']) && $_POST['is_overtime'] !== '';

// Use manual overtime values if provided, otherwise use calculated values
$final_overtime_hours = $manual_overtime_provided ? $overtime_hours : $rec['overtime_hours'];
$final_is_overtime = $manual_is_overtime_provided ? $is_overtime : $rec['is_overtime'];

// If employee is on leave, set total_hours to 0 and status to on_leave regardless of calculation
$final_total_hours = $is_on_leave ? 0 : $rec['total_hours'];
$final_status = $is_on_leave ? 'on_leave' : $rec['status'];

$persist = $conn->prepare("UPDATE attendance SET overtime_hours=?, early_out_minutes=?, late_minutes=?, is_overtime=?, status=?, total_hours=? WHERE id=?");
$persist->bind_param("diiisdi",
    $final_overtime_hours,
    $rec['early_out_minutes'],
    $rec['late_minutes'],
    $final_is_overtime,
    $final_status,
    $final_total_hours,
    $new_id
);
$persist->execute();
$persist->close();

// Insert notification for creation
$conn->query("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(50), message TEXT, created_at DATETIME, unread TINYINT(1) DEFAULT 1)");
$msg = sprintf('HR created attendance record for %s (Record #%d)', $updated['EmployeeName'] ?? 'Employee', $new_id);
$ins = $conn->prepare("INSERT INTO notifications(type, message, created_at, unread) VALUES('attendance_create', ?, NOW(), 1)");
$ins->bind_param("s", $msg);
$ins->execute();
$ins->close();

$conn->close();

echo json_encode(['success' => true, 'id' => $new_id]);
?>
