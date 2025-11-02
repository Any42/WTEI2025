<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

// Check if user is logged in
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Include attendance calculations
require_once 'attendance_calculations.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

try {
    // Align MySQL session timezone
    $conn->query("SET time_zone = '+08:00'");
    // Get form data
    $employee_id = (int)$_POST['employee_id'];
    $attendance_date = $conn->real_escape_string($_POST['attendance_date']);
    $status = isset($_POST['status']) ? $conn->real_escape_string($_POST['status']) : '';
    // Support both overall and split inputs; prefer split when provided
    $am_in = isset($_POST['am_in']) && $_POST['am_in'] !== '' ? $conn->real_escape_string($_POST['am_in']) : null;
    $am_out = isset($_POST['am_out']) && $_POST['am_out'] !== '' ? $conn->real_escape_string($_POST['am_out']) : null;
    $pm_in = isset($_POST['pm_in']) && $_POST['pm_in'] !== '' ? $conn->real_escape_string($_POST['pm_in']) : null;
    $pm_out = isset($_POST['pm_out']) && $_POST['pm_out'] !== '' ? $conn->real_escape_string($_POST['pm_out']) : null;
    $time_in = isset($_POST['time_in']) && $_POST['time_in'] !== '' ? $conn->real_escape_string($_POST['time_in']) : null;
    $time_out = isset($_POST['time_out']) && $_POST['time_out'] !== '' ? $conn->real_escape_string($_POST['time_out']) : null;
    $notes = $conn->real_escape_string($_POST['notes']);
    $is_manual = isset($_POST['manual_entry']) ? true : false;
    $is_on_leave = isset($_POST['is_on_leave']) ? (int)$_POST['is_on_leave'] : 0;
    
    // Get manual OT hours from form
    $manual_overtime_hours = isset($_POST['overtime_hours']) && $_POST['overtime_hours'] !== '' ? floatval($_POST['overtime_hours']) : 0;
    $is_overtime_checked = isset($_POST['is_overtime']) && $_POST['is_overtime'] == '1' ? 1 : 0;
    $overtime_reason = isset($_POST['overtime_reason']) ? $conn->real_escape_string($_POST['overtime_reason']) : '';

    // Normalize HH:MM to HH:MM:SS
    $normalizeTime = function($t) {
        if ($t === null || $t === '') return $t;
        if (preg_match('/^\d{1,2}:\d{2}$/', $t)) {
            list($h,$m) = explode(':', $t, 2);
            $h = max(0, min(23, (int)$h));
            $m = max(0, min(59, (int)$m));
            return str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ':00';
        }
        return $t;
    };
    $am_in = $normalizeTime($am_in);
    $am_out = $normalizeTime($am_out);
    $pm_in = $normalizeTime($pm_in);
    $pm_out = $normalizeTime($pm_out);
    $time_in = $normalizeTime($time_in);
    $time_out = $normalizeTime($time_out);

    // Synthesize overall in/out from split if not provided
    if (!$time_in) { $time_in = $am_in ?: ($pm_in ?: null); }
    if (!$time_out) { $time_out = $pm_out ?: ($am_out ?: null); }

    // Validate required fields
    if (empty($employee_id) || empty($attendance_date) || empty($status)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    // Validate employee exists and get shift
    $emp_check = "SELECT EmployeeID, EmployeeName, Shift FROM empuser WHERE EmployeeID = ?";
    $stmt_emp = $conn->prepare($emp_check);
    $stmt_emp->bind_param("i", $employee_id);
    $stmt_emp->execute();
    $emp_result = $stmt_emp->get_result();
    
    if ($emp_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit;
    }
    $employee_data = $emp_result->fetch_assoc();
    $stmt_emp->close();

    // Check leave limits if setting employee on leave
    if ($is_on_leave) {
        $leave_check_stmt = $conn->prepare("SELECT leave_pay_counts FROM empuser WHERE EmployeeID = ?");
        $leave_check_stmt->bind_param("i", $employee_id);
        $leave_check_stmt->execute();
        $leave_result = $leave_check_stmt->get_result();
        $leave_data = $leave_result->fetch_assoc();
        $leave_check_stmt->close();
        
        if (!$leave_data || $leave_data['leave_pay_counts'] <= 0) {
            echo json_encode(['success' => false, 'message' => 'Employee has no remaining leave days. Cannot set on leave.']);
            exit;
        }
    }

    // If employee is on leave, nullify all time inputs and set attendance_type to absent
    if ($is_on_leave) {
        $time_in = null;
        $time_out = null;
        $am_in = null;
        $am_out = null;
        $pm_in = null;
        $pm_out = null;
        $attendance_type = 'absent';
    } else {
        // Build attendance_type from provided times
        $hasAnyTime = ($time_in || $time_out || $am_in || $am_out || $pm_in || $pm_out);
        $attendance_type = $hasAnyTime ? 'present' : 'absent';
    }

    // Prepare record for calculation
    $record = [
        'attendance_date' => $attendance_date,
        'time_in' => $time_in,
        'time_out' => $time_out,
        'time_in_morning' => $am_in,
        'time_out_morning' => $am_out,
        'time_in_afternoon' => $pm_in,
        'time_out_afternoon' => $pm_out,
        'Shift' => $employee_data['Shift'] ?? ''
    ];
    // Calculate metrics
    $calculated_record = AttendanceCalculator::calculateAttendanceMetrics([$record])[0];
    $calculated_status = $calculated_record['status'] ?? null;
    if (!empty($calculated_status)) {
        $status = $calculated_status;
    }
    // Constrain status to DB enum (late/early) or NULL
    $db_status = (in_array($status, ['late','early'], true)) ? $status : null;
    
    // Use manual OT hours if provided, otherwise use calculated
    $final_overtime_hours = $is_overtime_checked && $manual_overtime_hours > 0 ? $manual_overtime_hours : ($calculated_record['overtime_hours'] ?? 0);
    $final_is_overtime = $is_overtime_checked ? 1 : ($calculated_record['is_overtime'] ?? 0);
    
    // Append OT reason to notes if provided
    if ($is_overtime_checked && !empty($overtime_reason)) {
        $final_notes .= ($final_notes ? ' | ' : '') . "OT Reason: {$overtime_reason}";
    }

    // Check if attendance record already exists for this date
    $check_query = "SELECT id FROM attendance WHERE EmployeeID = ? AND attendance_date = ?";
    $stmt_check = $conn->prepare($check_query);
    $stmt_check->bind_param("is", $employee_id, $attendance_date);
    $stmt_check->execute();
    $check_result = $stmt_check->get_result();
    
    // Prepare notes - if user didn't touch the record, leave notes blank
    $final_notes = $notes;
    if ($is_manual) {
        $hr_name = $_SESSION['full_name'] ?? 'HR';
        $final_notes = "Manual entry by {$hr_name}. " . $notes;
    } else {
        // If not manual entry, leave notes blank by default
        $final_notes = '';
    }

    if ($check_result->num_rows > 0) {
        // Update existing record
        $existing_record = $check_result->fetch_assoc();
        
        $update_query = "UPDATE attendance SET 
                         attendance_type = ?,
                         status = ?, 
                         time_in = ?, 
                         time_out = ?, 
                         time_in_morning = ?,
                         time_out_morning = ?,
                         time_in_afternoon = ?,
                         time_out_afternoon = ?,
                         notes = CONCAT(IFNULL(notes, ''), IF(notes IS NULL OR notes = '', '', ' | '), ?),
                         overtime_hours = ?,
                         early_out_minutes = ?,
                         late_minutes = ?,
                         is_overtime = ?,
                         total_hours = ?,
                         is_on_leave = ?
                         WHERE id = ?";
        
        $stmt_update = $conn->prepare($update_query);
        $stmt_update->bind_param("sssssssssdiiiidi", 
                                $attendance_type,
                                $db_status,
                                $time_in,
                                $time_out,
                                $am_in,
                                $am_out,
                                $pm_in,
                                $pm_out,
                                $final_notes,
                                $final_overtime_hours,
                                $calculated_record['early_out_minutes'],
                                $calculated_record['late_minutes'],
                                $final_is_overtime,
                                $calculated_record['total_hours'],
                                $is_on_leave,
                                $existing_record['id']);
        
        if ($stmt_update->execute()) {
            // Handle leave day deduction/refund for updates
            $current_leave_stmt = $conn->prepare("SELECT is_on_leave FROM attendance WHERE id = ?");
            $current_leave_stmt->bind_param("i", $existing_record['id']);
            $current_leave_stmt->execute();
            $current_leave_result = $current_leave_stmt->get_result();
            $current_leave_data = $current_leave_result->fetch_assoc();
            $current_leave_stmt->close();
            
            $was_on_leave = $current_leave_data['is_on_leave'] ?? 0;
            
            if ($is_on_leave && !$was_on_leave) {
                // Employee is being set on leave - deduct leave day
                $deduct_leave_stmt = $conn->prepare("UPDATE empuser SET leave_pay_counts = leave_pay_counts - 1 WHERE EmployeeID = ? AND leave_pay_counts > 0");
                $deduct_leave_stmt->bind_param("i", $employee_id);
                $deduct_leave_stmt->execute();
                $deduct_leave_stmt->close();
            } elseif (!$is_on_leave && $was_on_leave) {
                // Employee is being removed from leave - refund leave day (max 10)
                $refund_leave_stmt = $conn->prepare("UPDATE empuser SET leave_pay_counts = LEAST(leave_pay_counts + 1, 10) WHERE EmployeeID = ?");
                $refund_leave_stmt->bind_param("i", $employee_id);
                $refund_leave_stmt->execute();
                $refund_leave_stmt->close();
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Attendance updated successfully',
                'action' => 'updated',
                'employee_name' => $employee_data['EmployeeName']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update attendance']);
        }
        $stmt_update->close();
        
    } else {
        // Insert new record with calculated values
        $insert_query = "INSERT INTO attendance (
                            EmployeeID, attendance_date, attendance_type, status,
                            time_in, time_out,
                            time_in_morning, time_out_morning,
                            time_in_afternoon, time_out_afternoon,
                            notes, overtime_hours, early_out_minutes, late_minutes, is_overtime, total_hours, is_on_leave
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt_insert = $conn->prepare($insert_query);
        // Types: i + 10 x s + d + i + i + i + d
        $stmt_insert->bind_param("issssssssssdiiiid",
                                $employee_id,
                                $attendance_date,
                                $attendance_type,
                                $db_status,
                                $time_in,
                                $time_out,
                                $am_in,
                                $am_out,
                                $pm_in,
                                $pm_out,
                                $final_notes,
                                $final_overtime_hours,
                                $calculated_record['early_out_minutes'],
                                $calculated_record['late_minutes'],
                                $final_is_overtime,
                                $calculated_record['total_hours'],
                                $is_on_leave
                                );
        
        if ($stmt_insert->execute()) {
            // Deduct leave day if employee is set on leave
            if ($is_on_leave) {
                $deduct_leave_stmt = $conn->prepare("UPDATE empuser SET leave_pay_counts = leave_pay_counts - 1 WHERE EmployeeID = ? AND leave_pay_counts > 0");
                $deduct_leave_stmt->bind_param("i", $employee_id);
                $deduct_leave_stmt->execute();
                $deduct_leave_stmt->close();
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Attendance added successfully',
                'action' => 'added',
                'employee_name' => $employee_data['EmployeeName']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add attendance', 'error' => $stmt_insert->error]);
        }
        $stmt_insert->close();
    }
    
    $stmt_check->close();

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
?>