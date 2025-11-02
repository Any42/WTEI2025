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

// Check if this is a valid request
if (!isset($_POST['action']) || !in_array($_POST['action'], ['bulk_edit', 'mark_absent'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

$action = $_POST['action'];

// Debug: Log the received data
error_log("Bulk edit request - Action: " . $action);
error_log("POST data: " . print_r($_POST, true));

// Get form data
$employees = json_decode($_POST['employees'], true);
$attendance_date = isset($_POST['attendance_date']) && $_POST['attendance_date'] !== ''
    ? $_POST['attendance_date']
    : date('Y-m-d');

if (!$employees || !is_array($employees)) {
    echo json_encode(['success' => false, 'message' => 'Invalid employee data']);
    exit;
}

// Validate employee data structure
foreach ($employees as $index => $employee) {
    if (!isset($employee['id']) || !isset($employee['name'])) {
        echo json_encode(['success' => false, 'message' => "Invalid employee data at index {$index}. Missing id or name."]);
        exit;
    }
}

// Start transaction
$conn->autocommit(false);

try {
    $updated_count = 0;
    
    if ($action === 'mark_absent') {
        // Handle mark as absent action
        foreach ($employees as $employee) {
            $employee_id = $employee['id'];
            $employee_name = $employee['name'];
            
            // Delete attendance record for this employee and date
            $stmt = $conn->prepare("DELETE FROM attendance WHERE EmployeeID = ? AND DATE(attendance_date) = ?");
            $stmt->bind_param("is", $employee_id, $attendance_date);
            $stmt->execute();
            $deleted_rows = $stmt->affected_rows;
            $stmt->close();
            
            if ($deleted_rows > 0) {
                $updated_count++;
                
                // Insert notification for marking absent
                $msg = sprintf('HR marked %s as absent for %s', $employee_name, $attendance_date);
                $ins = $conn->prepare("INSERT INTO notifications(type, message, unread) VALUES('attendance_edit', ?, 1)");
                $ins->bind_param("s", $msg);
                $ins->execute();
                $ins->close();
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'updated_count' => $updated_count,
            'message' => "Successfully marked {$updated_count} employee(s) as absent."
        ]);
        
    } else {
        // Handle bulk edit action
        if (!isset($_POST['shift_type'])) {
            throw new Exception('Shift type is required for bulk edit action');
        }
        $shift_type = $_POST['shift_type'];
        
        // Get time/status fields
        $am_in = isset($_POST['am_in']) ? trim($_POST['am_in']) : null;
        $am_out = isset($_POST['am_out']) ? trim($_POST['am_out']) : null;
        $pm_in = isset($_POST['pm_in']) ? trim($_POST['pm_in']) : null;
        $pm_out = isset($_POST['pm_out']) ? trim($_POST['pm_out']) : null;
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
        $is_overtime = isset($_POST['is_overtime']) ? (int)$_POST['is_overtime'] : null;
        $overtime_hours = isset($_POST['overtime_hours']) && $_POST['overtime_hours'] !== '' ? (float)$_POST['overtime_hours'] : null;
        $no_time_out = isset($_POST['no_time_out']) && (int)$_POST['no_time_out'] === 1;
        $mark_half_day = isset($_POST['mark_half_day']) && (int)$_POST['mark_half_day'] === 1;
        $halfday_session = isset($_POST['halfday_session']) ? $_POST['halfday_session'] : 'morning';
        
        // Normalize time format (24h HH:MM[:SS])
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
        
        $am_in = $normalizeTime($am_in);
        $am_out = $normalizeTime($am_out);
        $pm_in = $normalizeTime($pm_in);
        $pm_out = $normalizeTime($pm_out);
        
        foreach ($employees as $employee) {
            $employee_id = $employee['id'];
            $employee_name = $employee['name'];
            
            // Get today's attendance record for this employee
            $stmt = $conn->prepare("SELECT id FROM attendance WHERE EmployeeID = ? AND DATE(attendance_date) = ?");
            $stmt->bind_param("is", $employee_id, $attendance_date);
            $stmt->execute();
            $result = $stmt->get_result();
            $attendance_record = $result->fetch_assoc();
            $stmt->close();
            
            if (!$attendance_record) {
                // Create new attendance record if it doesn't exist
                $stmt = $conn->prepare("INSERT INTO attendance (EmployeeID, attendance_date, attendance_type, data_source) VALUES (?, ?, 'present', 'bulk_edit')");
                $stmt->bind_param("is", $employee_id, $attendance_date);
                $stmt->execute();
                $attendance_id = $conn->insert_id;
                $stmt->close();
            } else {
                $attendance_id = $attendance_record['id'];
            }
            
            // Build update query based on shift type
            $fields = [];
            $params = [];
            $types = '';
            
            // Always update data_source to 'bulk_edit' for bulk edit operations
            $fields[] = "data_source = 'bulk_edit'";
            
            // Debug: Log the fields being updated
            error_log("Bulk edit - Employee ID: {$employee_id}, Fields: " . implode(', ', $fields));
            
            if ($shift_type === 'morning' || $shift_type === 'full') {
                if ($am_in !== null && $am_in !== '') {
                    $fields[] = 'time_in_morning = ?';
                    $params[] = $am_in;
                    $types .= 's';
                }
                if ($am_out !== null && $am_out !== '') {
                    $fields[] = 'time_out_morning = ?';
                    $params[] = $am_out;
                    $types .= 's';
                }
            }
            
            if ($shift_type === 'afternoon' || $shift_type === 'full') {
                if ($pm_in !== null && $pm_in !== '') {
                    $fields[] = 'time_in_afternoon = ?';
                    $params[] = $pm_in;
                    $types .= 's';
                }
                if ($pm_out !== null && $pm_out !== '') {
                    $fields[] = 'time_out_afternoon = ?';
                    $params[] = $pm_out;
                    $types .= 's';
                }
            }
            
            // Compute overall time_in/time_out based on provided segment times
            // time_in prefers AM in, then PM in; time_out prefers PM out, then AM out
            $overall_in = null;
            if ($am_in !== null && $am_in !== '') {
                $overall_in = $am_in;
            } elseif ($pm_in !== null && $pm_in !== '') {
                $overall_in = $pm_in;
            }
            $overall_out = null;
            if ($pm_out !== null && $pm_out !== '') {
                $overall_out = $pm_out;
            } elseif ($am_out !== null && $am_out !== '') {
                $overall_out = $am_out;
            }

            // Guard: if only one of in/out exists, do not synthesize the other.
            // AttendanceCalculator will use shift windows to compute late/early consistently with HRAttendance.

            if ($overall_in !== null && $overall_in !== '') {
                $fields[] = 'time_in = ?';
                $params[] = $overall_in;
                $types .= 's';
            }
            if ($overall_out !== null && $overall_out !== '') {
                $fields[] = 'time_out = ?';
                $params[] = $overall_out;
                $types .= 's';
            }

            // Apply no time out flag by clearing overall and segment OUT fields
            if ($no_time_out) {
                $fields[] = 'time_out = NULL';
                $fields[] = 'time_out_morning = NULL';
                $fields[] = 'time_out_afternoon = NULL';
            }
            
            // Apply half-day logic - clear opposite session and set 4-hour total
            if ($mark_half_day) {
                if ($halfday_session === 'morning') {
                    // Keep morning, clear afternoon
                    $fields[] = 'time_in_afternoon = NULL';
                    $fields[] = 'time_out_afternoon = NULL';
                } else {
                    // Keep afternoon, clear morning
                    $fields[] = 'time_in_morning = NULL';
                    $fields[] = 'time_out_morning = NULL';
                }
                // Set total hours to 4 for half day
                $fields[] = 'total_hours = 4.00';
                // Mark status as half_day
                $fields[] = "status = 'half_day'";
            }

            // Add notes if provided
            if ($notes !== null && $notes !== '') {
                $fields[] = 'notes = ?';
                $params[] = $notes;
                $types .= 's';
            }

            // Overtime controls (explicit)
            if ($is_overtime !== null) {
                $fields[] = 'is_overtime = ?';
                $params[] = $is_overtime;
                $types .= 'i';
            }
            if ($overtime_hours !== null) {
                $fields[] = 'overtime_hours = ?';
                $params[] = $overtime_hours;
                $types .= 'd';
            }

            // Ensure attendance_type present when adding times
            if (!empty($fields)) {
                $fields[] = "attendance_type = 'present'";
            }
            
            if (!empty($fields)) {
                $query = "UPDATE attendance SET " . implode(', ', $fields) . " WHERE id = ?";
                $params[] = $attendance_id;
                $types .= 'i';
                
                // Debug: Log the final query
                error_log("Bulk edit - Final query: {$query}");
                error_log("Bulk edit - Params: " . print_r($params, true));
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();

                // Recalculate attendance metrics based on full record + shift
                try {
                    require_once 'attendance_calculations.php';
                    $full = $conn->prepare("SELECT a.*, e.Shift FROM attendance a JOIN empuser e ON a.EmployeeID = e.EmployeeID WHERE a.id = ?");
                    $full->bind_param("i", $attendance_id);
                    $full->execute();
                    $res = $full->get_result();
                    $row = $res->fetch_assoc();
                    $full->close();
                    if ($row) {
                        $calcRec = AttendanceCalculator::calculateAttendanceMetrics([$row]);
                        $updated = $calcRec[0];
                        
                        // Preserve manually set overtime values (don't let calculator override them)
                        if ($is_overtime !== null) {
                            $updated['is_overtime'] = $is_overtime;
                        }
                        if ($overtime_hours !== null) {
                            $updated['overtime_hours'] = $overtime_hours;
                        }
                        
                        AttendanceCalculator::updateAttendanceRecord($conn, $attendance_id, $updated);
                    }
                } catch (Exception $calcError) {
                    error_log("Error in attendance calculations: " . $calcError->getMessage());
                }
                
                $updated_count++;
                
                // Insert notification for bulk edit
                $msg = sprintf('HR bulk edited attendance for %s (Record #%d)', $employee['name'], $attendance_id);
                $ins = $conn->prepare("INSERT INTO notifications(type, message, unread) VALUES('attendance_edit', ?, 1)");
                $ins->bind_param("s", $msg);
                $ins->execute();
                $ins->close();
                
            } else {
                // No time fields provided, just mark as present
                $stmt = $conn->prepare("UPDATE attendance SET attendance_type = 'present', data_source = 'bulk_edit' WHERE id = ?");
                $stmt->bind_param("i", $attendance_id);
                $stmt->execute();
                $stmt->close();
                $updated_count++;
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'updated_count' => $updated_count,
            'message' => "Successfully updated attendance for {$updated_count} employee(s)."
        ]);
    }
    
} catch (Exception $e) {
    $conn->rollback();
    
    // Log the error for debugging
    error_log("Bulk edit attendance error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'debug_info' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'action' => $action ?? 'unknown'
        ]
    ]);
}

$conn->close();
?>