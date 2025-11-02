<?php
/**
 * Batch update script for attendance status
 * More efficient for large datasets - processes records in batches
 */

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

$conn->query("SET time_zone = '+08:00');

echo "Starting batch attendance status update...\n";
echo "========================================\n\n";

try {
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM attendance WHERE attendance_type = 'present'";
    $count_result = $conn->query($count_query);
    $total_records = $count_result->fetch_assoc()['total'];
    
    echo "Total records to process: {$total_records}\n\n";

    $batch_size = 500;
    $offset = 0;
    $total_updated = 0;
    $total_errors = 0;

    while ($offset < $total_records) {
        echo "Processing batch: " . ($offset + 1) . " to " . min($offset + $batch_size, $total_records) . "\n";
        
        // Get batch of records
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
                  ORDER BY a.attendance_date DESC, a.EmployeeID
                  LIMIT {$batch_size} OFFSET {$offset}";

        $result = $conn->query($query);
        
        if (!$result) {
            throw new Exception("Query failed: " . $conn->error);
        }

        $batch_records = [];
        while ($row = $result->fetch_assoc()) {
            $batch_records[] = $row;
        }

        if (empty($batch_records)) {
            break;
        }

        // Prepare records for calculation
        $records_for_calculation = [];
        foreach ($batch_records as $row) {
            $records_for_calculation[] = [
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
        }

        // Calculate all records in batch
        $calculated_records = AttendanceCalculator::calculateAttendanceMetrics($records_for_calculation);

        // Prepare batch update
        $update_values = [];
        $update_ids = [];
        
        foreach ($calculated_records as $calculated_record) {
            $original_record = $batch_records[array_search($calculated_record['id'], array_column($batch_records, 'id'))];
            
            $new_status = $calculated_record['status'] ?? null;
            $new_late_minutes = $calculated_record['late_minutes'] ?? 0;
            $new_early_out_minutes = $calculated_record['early_out_minutes'] ?? 0;
            $new_total_hours = $calculated_record['total_hours'] ?? 0;

            // Map status to database enum values
            $db_status = null;
            if (in_array($new_status, ['late', 'early', 'early_in', 'on_time', 'halfday'])) {
                $db_status = $new_status;
            }

            $update_values[] = "('{$db_status}', {$new_late_minutes}, {$new_early_out_minutes}, {$new_total_hours}, {$original_record['id']})";
            $update_ids[] = $original_record['id'];
        }

        // Execute batch update
        if (!empty($update_values)) {
            $batch_update_sql = "UPDATE attendance SET 
                                 status = CASE id ";
            
            foreach ($update_values as $value) {
                $parts = explode(', ', trim($value, '()'));
                $status = $parts[0];
                $late_minutes = $parts[1];
                $early_out_minutes = $parts[2];
                $total_hours = $parts[3];
                $id = $parts[4];
                
                $batch_update_sql .= "WHEN {$id} THEN {$status} ";
            }
            
            $batch_update_sql .= "END,
                                 late_minutes = CASE id ";
            
            foreach ($update_values as $value) {
                $parts = explode(', ', trim($value, '()'));
                $late_minutes = $parts[1];
                $id = $parts[4];
                
                $batch_update_sql .= "WHEN {$id} THEN {$late_minutes} ";
            }
            
            $batch_update_sql .= "END,
                                 early_out_minutes = CASE id ";
            
            foreach ($update_values as $value) {
                $parts = explode(', ', trim($value, '()'));
                $early_out_minutes = $parts[2];
                $id = $parts[4];
                
                $batch_update_sql .= "WHEN {$id} THEN {$early_out_minutes} ";
            }
            
            $batch_update_sql .= "END,
                                 total_hours = CASE id ";
            
            foreach ($update_values as $value) {
                $parts = explode(', ', trim($value, '()'));
                $total_hours = $parts[3];
                $id = $parts[4];
                
                $batch_update_sql .= "WHEN {$id} THEN {$total_hours} ";
            }
            
            $batch_update_sql .= "END
                                 WHERE id IN (" . implode(',', $update_ids) . ")";

            if ($conn->query($batch_update_sql)) {
                $total_updated += count($update_values);
                echo "Updated " . count($update_values) . " records in this batch\n";
            } else {
                $total_errors += count($update_values);
                echo "Error in batch update: " . $conn->error . "\n";
            }
        }

        $offset += $batch_size;
    }

    echo "\n========================================\n";
    echo "Batch update completed!\n";
    echo "Total records updated: {$total_updated}\n";
    echo "Total errors: {$total_errors}\n";
    echo "========================================\n";

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
} finally {
    $conn->close();
}

echo "\nBatch update script completed.\n";
?>
