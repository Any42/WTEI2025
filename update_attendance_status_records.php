<?php
/**
 * Script to update existing attendance records with new status values
 * This script will:
 * 1. Read all existing attendance records
 * 2. Calculate the correct status using AttendanceCalculator
 * 3. Update the records with the new status values
 */

// Include required files
require_once 'attendance_calculations.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set timezone
$conn->query("SET time_zone = '+08:00');

echo "Starting attendance status update process...\n";
echo "==========================================\n\n";

try {
    // Get all attendance records with employee shift information
    $query = "SELECT 
                a.id,
                a.EmployeeID,
                a.attendance_date,
                a.attendance_type,
                a.status as current_status,
                a.time_in,
                a.time_out,
                a.time_in_morning,
                a.time_out_morning,
                a.time_in_afternoon,
                a.time_out_afternoon,
                a.late_minutes,
                a.early_out_minutes,
                a.total_hours,
                e.Shift,
                e.EmployeeName
              FROM attendance a
              JOIN empuser e ON a.EmployeeID = e.EmployeeID
              WHERE a.attendance_type = 'present'
              ORDER BY a.attendance_date DESC, a.EmployeeID";

    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }

    $total_records = $result->num_rows;
    echo "Found {$total_records} attendance records to process.\n\n";

    $updated_count = 0;
    $error_count = 0;
    $batch_size = 100;
    $processed = 0;

    // Prepare update statement
    $update_stmt = $conn->prepare("UPDATE attendance SET 
                                   status = ?, 
                                   late_minutes = ?, 
                                   early_out_minutes = ?, 
                                   total_hours = ?
                                   WHERE id = ?");

    if (!$update_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    while ($row = $result->fetch_assoc()) {
        $processed++;
        
        // Prepare record for calculation
        $record = [
            'id' => $row['id'],
            'EmployeeID' => $row['EmployeeID'],
            'attendance_date' => $row['attendance_date'],
            'attendance_type' => $row['attendance_type'],
            'time_in' => $row['time_in'],
            'time_out' => $row['time_out'],
            'time_in_morning' => $row['time_in_morning'],
            'time_out_morning' => $row['time_out_morning'],
            'time_in_afternoon' => $row['time_in_afternoon'],
            'time_out_afternoon' => $row['time_out_afternoon'],
            'Shift' => $row['Shift']
        ];

        try {
            // Calculate the correct status and metrics
            $calculated_records = AttendanceCalculator::calculateAttendanceMetrics([$record]);
            $calculated_record = $calculated_records[0];

            $new_status = $calculated_record['status'] ?? null;
            $new_late_minutes = $calculated_record['late_minutes'] ?? 0;
            $new_early_out_minutes = $calculated_record['early_out_minutes'] ?? 0;
            $new_total_hours = $calculated_record['total_hours'] ?? 0;

            // Map status to database enum values
            $db_status = null;
            if (in_array($new_status, ['late', 'early', 'early_in', 'on_time', 'halfday'])) {
                $db_status = $new_status;
            }

            // Update the record
            $update_stmt->bind_param("siiid", 
                $db_status, 
                $new_late_minutes, 
                $new_early_out_minutes, 
                $new_total_hours, 
                $row['id']
            );

            if ($update_stmt->execute()) {
                $updated_count++;
                
                // Show progress for every 50 records
                if ($processed % 50 == 0) {
                    echo "Processed {$processed}/{$total_records} records...\n";
                }
                
                // Show detailed info for status changes
                if ($row['current_status'] !== $db_status) {
                    echo "Updated Record ID {$row['id']} ({$row['EmployeeName']} - {$row['attendance_date']}): ";
                    echo "Status changed from '{$row['current_status']}' to '{$db_status}'\n";
                }
            } else {
                $error_count++;
                echo "Error updating record ID {$row['id']}: " . $update_stmt->error . "\n";
            }

        } catch (Exception $e) {
            $error_count++;
            echo "Error processing record ID {$row['id']}: " . $e->getMessage() . "\n";
        }
    }

    $update_stmt->close();

    echo "\n==========================================\n";
    echo "Update process completed!\n";
    echo "Total records processed: {$processed}\n";
    echo "Successfully updated: {$updated_count}\n";
    echo "Errors encountered: {$error_count}\n";
    echo "==========================================\n";

    // Show summary of status distribution
    echo "\nStatus distribution after update:\n";
    $status_query = "SELECT status, COUNT(*) as count FROM attendance WHERE attendance_type = 'present' GROUP BY status ORDER BY count DESC";
    $status_result = $conn->query($status_query);
    
    if ($status_result) {
        while ($status_row = $status_result->fetch_assoc()) {
            $status_name = $status_row['status'] ?? 'NULL';
            echo "- {$status_name}: {$status_row['count']} records\n";
        }
    }

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
} finally {
    $conn->close();
}

echo "\nScript execution completed.\n";
?>
