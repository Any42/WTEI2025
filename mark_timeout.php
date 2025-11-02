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

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    $conn->close();
    exit;
}

// Fetch the existing record with shift information
$stmt = $conn->prepare("SELECT a.*, e.Shift, e.EmployeeName FROM attendance a JOIN empuser e ON a.EmployeeID = e.EmployeeID WHERE a.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$record = $res->fetch_assoc();
$stmt->close();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Record not found']);
    $conn->close();
    exit;
}

// Check if already has time out
if (!empty($record['time_out'])) {
    echo json_encode(['success' => false, 'message' => 'Already has time out']);
    $conn->close();
    exit;
}

// Get employee's shift and determine the correct timeout time
$shift = $record['Shift'] ?? '';
// NOTE: We do not use server current time for setting device-driven fields.
// This endpoint is a manual correction tool; we infer AM/PM from existing record fields.

// Define shift end times and lunch windows
$shiftEndTimes = [
    '08:00-17:00' => '17:00:00',
    '08:00-17:00pb' => '17:00:00',
    '8-5pb' => '17:00:00',
    '08:30-17:30' => '17:30:00',
    '8:30-5:30pm' => '17:30:00',
    '09:00-18:00' => '18:00:00',
    '9am-6pm' => '18:00:00'
];

$lunchWindows = [
    '08:00-17:00' => [12.0, 13.0],
    '08:00-17:00pb' => [12.0, 13.0],
    '8-5pb' => [12.0, 13.0],
    '08:30-17:30' => [12.5, 13.5],
    '8:30-5:30pm' => [12.5, 13.5],
    '09:00-18:00' => [13.0, 14.0],
    '9am-6pm' => [13.0, 14.0]
];

// Get the scheduled end time for this employee's shift
$scheduledEndTime = isset($shiftEndTimes[$shift]) ? $shiftEndTimes[$shift] : '17:00:00';
$scheduledEndHour = (float)substr($scheduledEndTime, 0, 2) + (float)substr($scheduledEndTime, 3, 2) / 60;

// Determine which segment (AM/PM) based on existing punches, not current time
// Priority:
// 1) If afternoon in exists and no afternoon out → PM
// 2) Else if morning in exists and no morning out and no afternoon in → AM
// 3) Else default to PM (end-of-day correction)
$hasAmIn = !empty($record['time_in_morning']);
$hasAmOut = !empty($record['time_out_morning']);
$hasPmIn = !empty($record['time_in_afternoon']);
$hasPmOut = !empty($record['time_out_afternoon']);

$isAMTimeout = false;
if ($hasPmIn && !$hasPmOut) {
    $isAMTimeout = false; // PM
} elseif ($hasAmIn && !$hasAmOut && !$hasPmIn && !$hasPmOut) {
    $isAMTimeout = true; // AM
} else {
    $isAMTimeout = false; // default PM
}

// Determine which column to update and the appropriate timeout time
$targetColumn = $isAMTimeout ? 'time_out_morning' : 'time_out_afternoon';
$segment = $isAMTimeout ? 'AM' : 'PM';

// For AM timeout, use lunch start time; for PM timeout, use scheduled end time
if ($isAMTimeout) {
    // AM timeout should be at lunch start
    $lunchStart = isset($lunchWindows[$shift]) ? $lunchWindows[$shift][0] : 12.0;
    $timeString = sprintf('%02d:%02d:00', (int)$lunchStart, (int)(($lunchStart - (int)$lunchStart) * 60));
} else {
    // PM timeout should be at scheduled end time
    $timeString = $scheduledEndTime;
}

// Check if the target segment already has a timeout
$existingTimeout = $record[$targetColumn];
if (!empty($existingTimeout)) {
    echo json_encode(['success' => false, 'message' => "Already has {$segment} time out"]);
    $conn->close();
    exit;
}

// Update the appropriate segment
$update = $conn->prepare("UPDATE attendance SET {$targetColumn} = ? WHERE id = ?");
$update->bind_param("si", $timeString, $id);
if (!$update->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to update time out']);
    $update->close();
    $conn->close();
    exit;
}
$update->close();

// Also update the main time_out field to the latest of segment time-outs
$stmtMain = $conn->prepare("SELECT time_out_morning, time_out_afternoon FROM attendance WHERE id = ?");
$stmtMain->bind_param("i", $id);
$stmtMain->execute();
$segRes = $stmtMain->get_result();
$segRow = $segRes->fetch_assoc();
$stmtMain->close();

$latestOut = null;
if (!empty($segRow['time_out_morning'])) { $latestOut = $segRow['time_out_morning']; }
if (!empty($segRow['time_out_afternoon'])) {
    if ($latestOut === null || strtotime($segRow['time_out_afternoon']) > strtotime($latestOut)) {
        $latestOut = $segRow['time_out_afternoon'];
    }
}
if ($latestOut !== null) {
    $updateMain = $conn->prepare("UPDATE attendance SET time_out = ? WHERE id = ?");
    $updateMain->bind_param("si", $latestOut, $id);
    $updateMain->execute();
    $updateMain->close();
}

require_once 'attendance_calculations.php';

// Re-fetch with updated times
$stmt2 = $conn->prepare("SELECT a.*, e.Shift, e.EmployeeName FROM attendance a JOIN empuser e ON a.EmployeeID = e.EmployeeID WHERE a.id = ?");
$stmt2->bind_param("i", $id);
$stmt2->execute();
$res2 = $stmt2->get_result();
$updated = $res2->fetch_assoc();
$stmt2->close();

// Calculate accurate metrics
$calc = AttendanceCalculator::calculateAttendanceMetrics([$updated]);
$rec = $calc[0];

// Persist calculated metrics
$persist = $conn->prepare("UPDATE attendance SET overtime_hours=?, early_out_minutes=?, late_minutes=?, is_overtime=?, status=? WHERE id=?");
$persist->bind_param("diiisi", 
    $rec['overtime_hours'],
    $rec['early_out_minutes'],
    $rec['late_minutes'],
    $rec['is_overtime'],
    $rec['status'],
    $id
);
$persist->execute();
$persist->close();

// Insert notification row (create table if not exists: notifications(id, type, message, created_at, unread))
$conn->query("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(50), message TEXT, created_at DATETIME, unread TINYINT(1) DEFAULT 1)");
$msg = sprintf('HR marked %s time-out for %s (Record #%d)', $segment, $record['EmployeeName'] ?? 'Employee', $id);
$ins = $conn->prepare("INSERT INTO notifications(type, message, created_at, unread) VALUES('attendance', ?, NOW(), 1)");
$ins->bind_param("s", $msg);
$ins->execute();
$ins->close();

$conn->close();

echo json_encode([
    'success' => true, 
    'time_out' => $timeString,
    'segment' => $segment,
    'message' => "{$segment} time-out marked successfully at {$timeString}"
]);
