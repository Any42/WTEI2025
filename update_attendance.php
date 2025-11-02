<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

/**
 * Get shift schedule and lunch window based on shift string
 * Based on the logic from attendance_calculations.php
 */
function getShiftScheduleAndLunch($shift) {
    $shift = trim($shift);
    
    // Handle specific shift patterns
    switch ($shift) {
        case '08:00-17:00':
        case '08:00-17:00pb':
        case '8-5pb':
            return ['08:00:00', '17:00:00', '12:00:00', '13:00:00'];
        case '08:30-17:30':
        case '8:30-5:30pm':
        case '8:30-17:30':
            return ['08:30:00', '17:30:00', '12:30:00', '13:30:00'];
        case '09:00-18:00':
        case '9am-6pm':
            return ['09:00:00', '18:00:00', '13:00:00', '14:00:00'];
        case '22:00-06:00':
        case 'NSD':
        case 'nsd':
        case 'Night':
        case 'night':
            return ['22:00:00', '06:00:00', '02:00:00', '03:00:00']; // 10PM to 6AM (next day)
    }
    
    // Handle generic patterns like "8:30-17:30", "8-5", "9-6"
    if (preg_match('/(\d{1,2}:\d{2})-(\d{1,2}:\d{2})/', $shift, $matches)) {
        $startTime = $matches[1];
        $endTime = $matches[2];
        
        // Ensure proper format
        $startTime = strlen($startTime) === 4 ? '0' . $startTime : $startTime;
        $endTime = strlen($endTime) === 4 ? '0' . $endTime : $endTime;
        
        // Calculate lunch window based on shift
        $startHour = (int)substr($startTime, 0, 2);
        $endHour = (int)substr($endTime, 0, 2);
        
        // Default lunch times based on shift
        if ($startHour <= 8) {
            $lunchStart = '12:00:00';
            $lunchEnd = '13:00:00';
        } elseif ($startHour <= 9) {
            $lunchStart = '12:30:00';
            $lunchEnd = '13:30:00';
        } else {
            $lunchStart = '13:00:00';
            $lunchEnd = '14:00:00';
        }
        
        return [$startTime . ':00', $endTime . ':00', $lunchStart, $lunchEnd];
    }
    
    // Handle patterns like "8-5", "9-6"
    if (preg_match('/(\d{1,2})-(\d{1,2})/', $shift, $matches)) {
        $startHour = intval($matches[1]);
        $endHour = intval($matches[2]);
        
        // Convert to 24-hour format if needed
        if ($endHour < 12 && $endHour < 8) {
            $endHour += 12;
        }
        
        // Calculate lunch window
        if ($startHour <= 8) {
            $lunchStart = '12:00:00';
            $lunchEnd = '13:00:00';
        } elseif ($startHour <= 9) {
            $lunchStart = '12:30:00';
            $lunchEnd = '13:30:00';
        } else {
            $lunchStart = '13:00:00';
            $lunchEnd = '14:00:00';
        }
        
        return [
            sprintf('%02d:00:00', $startHour),
            sprintf('%02d:00:00', $endHour),
            $lunchStart,
            $lunchEnd
        ];
    }
    
    // Default fallback
    return ['08:00:00', '17:00:00', '12:00:00', '13:00:00'];
}

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
$halfday_period = isset($_POST['halfday_period']) ? trim($_POST['halfday_period']) : null;
$action = isset($_POST['action']) ? trim($_POST['action']) : null;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
$is_on_leave = isset($_POST['is_on_leave']) ? (int)$_POST['is_on_leave'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    $conn->close();
    exit;
}

// Get current attendance record to check if it was previously on leave
$current_stmt = $conn->prepare("SELECT EmployeeID, is_on_leave FROM attendance WHERE id = ?");
$current_stmt->bind_param("i", $id);
$current_stmt->execute();
$current_result = $current_stmt->get_result();
$current_record = $current_result->fetch_assoc();
$current_stmt->close();

if (!$current_record) {
    echo json_encode(['success' => false, 'message' => 'Record not found']);
    $conn->close();
    exit;
}

$employee_id = $current_record['EmployeeID'];
$was_on_leave = $current_record['is_on_leave'];

    // Handle half day marking action
    if ($action === 'mark_halfday' && $halfday_period) {
        // Get current attendance record with employee shift information
        $current_stmt = $conn->prepare("SELECT a.*, e.Shift FROM attendance a 
                                       JOIN empuser e ON a.EmployeeID = e.EmployeeID 
                                       WHERE a.id = ?");
        $current_stmt->bind_param("i", $id);
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        $current_record = $current_result->fetch_assoc();
        $current_stmt->close();
        
        if (!$current_record) {
            echo json_encode(['success' => false, 'message' => 'Record not found']);
            $conn->close();
            exit;
        }
        
        // Get shift schedule and lunch window based on employee's shift
        $shift = $current_record['Shift'] ?? '';
        [$schedStart, $schedEnd, $lunchStart, $lunchEnd] = getShiftScheduleAndLunch($shift);
        
        // Set half day based on period using proper shift schedule
        if ($halfday_period === 'morning') {
            // Morning only - work from shift start until lunch break
            // Use shift schedule times to ensure logical sequence
            $time_in = $schedStart; // Always start at shift start
            $time_out = $lunchStart; // Always end at lunch start
            $am_in = $schedStart;
            $am_out = $lunchStart;
            $pm_in = null;
            $pm_out = null;
            $attendance_type = 'present';
            $status = 'halfday';
            $notes = ($current_record['notes'] ? $current_record['notes'] . ' | ' : '') . 'Half Day - Morning Only';
        } elseif ($halfday_period === 'afternoon') {
            // Afternoon only - work from lunch break to end of shift
            // Use shift schedule times to ensure logical sequence
            $time_in = $lunchEnd; // Always start at lunch end
            $time_out = $schedEnd; // Always end at shift end
            $am_in = null;
            $am_out = null;
            $pm_in = $lunchEnd;
            $pm_out = $schedEnd;
            $attendance_type = 'present';
            $status = 'halfday';
            $notes = ($current_record['notes'] ? $current_record['notes'] . ' | ' : '') . 'Half Day - Afternoon Only';
        }
        
        // Log the values being set for debugging
        error_log("Half Day Debug - Period: $halfday_period, Shift: $shift, SchedStart: $schedStart, SchedEnd: $schedEnd, LunchStart: $lunchStart, LunchEnd: $lunchEnd");
        error_log("Half Day Debug - Time In: $time_in, Time Out: $time_out, AM In: $am_in, AM Out: $am_out, PM In: $pm_in, PM Out: $pm_out");
        
        // Update the record with half day settings
        $update_stmt = $conn->prepare("UPDATE attendance SET 
            time_in = ?, time_out = ?, 
            time_in_morning = ?, time_out_morning = ?, 
            time_in_afternoon = ?, time_out_afternoon = ?, 
            attendance_type = ?, status = ?, notes = ?, 
            data_source = 'manual' 
            WHERE id = ?");
        $update_stmt->bind_param("sssssssssi", 
            $time_in, $time_out, 
            $am_in, $am_out, 
            $pm_in, $pm_out, 
            $attendance_type, $status, $notes, 
            $id);
        
        if ($update_stmt->execute()) {
            // Insert notification
            $conn->query("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(50), message TEXT, created_at DATETIME, unread TINYINT(1) DEFAULT 1)");
            $msg = sprintf('HR marked attendance as Half Day (%s) for %s (Record #%d)', 
                ucfirst($halfday_period), 
                $current_record['EmployeeID'] ?? 'Employee', 
                $id);
            $ins = $conn->prepare("INSERT INTO notifications(type, message, created_at, unread) VALUES('attendance_edit', ?, NOW(), 1)");
            $ins->bind_param("s", $msg);
            $ins->execute();
            $ins->close();
            
            echo json_encode(['success' => true, 'message' => 'Successfully marked as half day']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update attendance']);
        }
        
        $update_stmt->close();
        $conn->close();
        exit;
    }

// Check leave limits if setting employee on leave (and wasn't already on leave)
if ($is_on_leave && !$was_on_leave) {
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

// Normalize HH:MM to HH:MM:SS and clamp to 24h format (avoid 20:30 instead of 08:30)
$normalizeTime = function($t) {
    if ($t === null || $t === '') return $t;
    if (preg_match('/^\d{1,2}:\d{2}$/', $t)) {
        list($h,$m) = explode(':', $t, 2);
        $h = max(0, min(23, intval($h)));
        $m = max(0, min(59, intval($m)));
        return str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ':00';
    }
    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $t, $mm)) {
        $h = max(0, min(23, intval($mm[1])));
        $m = max(0, min(59, intval($mm[2])));
        $s = max(0, min(59, intval($mm[3])));
        return str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
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

// Synthesize overall from split with same precedence as attendance pages
if ($am_in) { $time_in = $am_in; }
elseif (!$time_in && $pm_in) { $time_in = $pm_in; }
if ($pm_out) { $time_out = $pm_out; }
elseif (!$time_out && $am_out) { $time_out = $am_out; }

// Validate time sequence to prevent illogical entries
if ($time_in && $time_out && $time_in > $time_out) {
    echo json_encode(['success' => false, 'message' => 'Time in cannot be after time out. Please check your time entries.']);
    $conn->close();
    exit;
}

// Validate morning/afternoon time sequences
if ($am_in && $am_out && $am_in > $am_out) {
    echo json_encode(['success' => false, 'message' => 'Morning time in cannot be after morning time out.']);
    $conn->close();
    exit;
}

if ($pm_in && $pm_out && $pm_in > $pm_out) {
    echo json_encode(['success' => false, 'message' => 'Afternoon time in cannot be after afternoon time out.']);
    $conn->close();
    exit;
}

// Validate cross-period logic (morning out should be before afternoon in if both exist)
if ($am_out && $pm_in && $am_out > $pm_in) {
    echo json_encode(['success' => false, 'message' => 'Morning time out should be before afternoon time in.']);
    $conn->close();
    exit;
}

// If half day and no afternoon session, ensure we have morning data
if ($half_day && !$am_in && !$am_out && ($pm_in || $pm_out)) {
    $am_in = $pm_in;
    $am_out = $pm_out;
    $pm_in = null;
    $pm_out = null;
}

// Update provided fields
$fields = [];
$params = [];
$types = '';
if ($time_in !== null && $time_in !== '') { $fields[] = 'time_in = ?'; $params[] = $time_in; $types .= 's'; }
if ($time_out !== null && $time_out !== '') { $fields[] = 'time_out = ?'; $params[] = $time_out; $types .= 's'; }
if ($am_in !== null) { $fields[] = 'time_in_morning = ?'; $params[] = ($am_in !== '' ? $am_in : null); $types .= 's'; }
if ($am_out !== null) { $fields[] = 'time_out_morning = ?'; $params[] = ($am_out !== '' ? $am_out : null); $types .= 's'; }
if ($pm_in !== null) { $fields[] = 'time_in_afternoon = ?'; $params[] = ($pm_in !== '' ? $pm_in : null); $types .= 's'; }
if ($pm_out !== null) { $fields[] = 'time_out_afternoon = ?'; $params[] = ($pm_out !== '' ? $pm_out : null); $types .= 's'; }
if ($attendance_type !== null) { $fields[] = 'attendance_type = ?'; $params[] = $attendance_type; $types .= 's'; }
if ($status !== null && $status !== '') { $fields[] = 'status = ?'; $params[] = $status; $types .= 's'; }
if ($overtime_hours !== null) { $fields[] = 'overtime_hours = ?'; $params[] = $overtime_hours; $types .= 'd'; }
if ($is_overtime !== null) { $fields[] = 'is_overtime = ?'; $params[] = $is_overtime; $types .= 'i'; }
if ($notes !== null) { $fields[] = 'notes = ?'; $params[] = $notes; $types .= 's'; }
if ($is_on_leave !== null) { $fields[] = 'is_on_leave = ?'; $params[] = $is_on_leave; $types .= 'i'; }
$fields[] = 'data_source = ?'; $params[] = 'manual'; $types .= 's'; // Mark as manual entry

if (!empty($fields)) {
    $sql = 'UPDATE attendance SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $types .= 'i';
    $params[] = $id;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Failed to update attendance']);
        $stmt->close();
        $conn->close();
        exit;
    }
    $stmt->close();
}

// Handle leave deduction/refund based on leave status changes
if ($is_on_leave !== null) {
    if ($is_on_leave && !$was_on_leave) {
        // Employee is being set on leave - deduct leave day
        $deduct_stmt = $conn->prepare("UPDATE empuser SET leave_pay_counts = leave_pay_counts - 1 WHERE EmployeeID = ?");
        $deduct_stmt->bind_param("i", $employee_id);
        $deduct_stmt->execute();
        $deduct_stmt->close();
    } elseif (!$is_on_leave && $was_on_leave) {
        // Employee is being removed from leave - refund leave day
        $refund_stmt = $conn->prepare("UPDATE empuser SET leave_pay_counts = leave_pay_counts + 1 WHERE EmployeeID = ? AND leave_pay_counts < 10");
        $refund_stmt->bind_param("i", $employee_id);
        $refund_stmt->execute();
        $refund_stmt->close();
    }
}

require_once 'attendance_calculations.php';

// Re-fetch with joined shift for recalculation
$stmt2 = $conn->prepare("SELECT a.*, e.Shift, e.EmployeeName FROM attendance a JOIN empuser e ON a.EmployeeID = e.EmployeeID WHERE a.id = ?");
$stmt2->bind_param("i", $id);
$stmt2->execute();
$res2 = $stmt2->get_result();
$updated = $res2->fetch_assoc();
$stmt2->close();

if (!$updated) {
    echo json_encode(['success' => false, 'message' => 'Record not found post-update']);
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
    $id
);
$persist->execute();
$persist->close();

// Insert notification for edit
$conn->query("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(50), message TEXT, created_at DATETIME, unread TINYINT(1) DEFAULT 1)");
$msg = sprintf('HR edited attendance for %s (Record #%d)', $updated['EmployeeName'] ?? 'Employee', $id);
$ins = $conn->prepare("INSERT INTO notifications(type, message, created_at, unread) VALUES('attendance_edit', ?, NOW(), 1)");
$ins->bind_param("s", $msg);
$ins->execute();
$ins->close();

$conn->close();

echo json_encode(['success' => true]);
