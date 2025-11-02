<?php
/**
 * Script to recalculate attendance status with HalfDay priority
 * This script ensures that HalfDay takes priority over Early-in, On-time, and Late
 * when employees work 4 hours or less, or have only morning/afternoon sessions
 */

require_once 'attendance_calculations.php';

// Database connection
$host = "localhost";
$user = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Starting HalfDay Priority Recalculation...\n";
echo "==========================================\n\n";

// Get all attendance records that need recalculation
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
    a.total_hours,
    a.late_minutes,
    a.early_out_minutes,
    a.overtime_hours,
    a.is_overtime,
    e.Shift
FROM attendance a
JOIN empuser e ON a.EmployeeID = e.EmployeeID
WHERE a.attendance_type = 'present'
ORDER BY a.attendance_date DESC, a.EmployeeID";

$result = $conn->query($query);

if (!$result) {
    die("Query failed: " . $conn->error);
}

$total_records = $result->num_rows;
$updated_count = 0;
$halfday_count = 0;

echo "Found {$total_records} attendance records to process...\n\n";

// Process each record
while ($row = $result->fetch_assoc()) {
    $original_status = $row['status'];
    
    // Create a record array for the calculator
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
        'total_hours' => $row['total_hours'],
        'late_minutes' => $row['late_minutes'],
        'early_out_minutes' => $row['early_out_minutes'],
        'overtime_hours' => $row['overtime_hours'],
        'is_overtime' => $row['is_overtime'],
        'Shift' => $row['Shift']
    ];
    
    // Apply the new calculation logic
    $updated_records = AttendanceCalculator::calculateAttendanceMetrics([$record]);
    $new_record = $updated_records[0];
    $new_status = $new_record['status'];
    
    // Check if status changed
    if ($original_status !== $new_status) {
        // Update the database record
        $update_query = "UPDATE attendance SET 
            status = ?,
            total_hours = ?,
            late_minutes = ?,
            early_out_minutes = ?,
            overtime_hours = ?,
            is_overtime = ?
            WHERE id = ?";
        
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sdddddi", 
            $new_status,
            $new_record['total_hours'],
            $new_record['late_minutes'],
            $new_record['early_out_minutes'],
            $new_record['overtime_hours'],
            $new_record['is_overtime'],
            $row['id']
        );
        
        if ($stmt->execute()) {
            $updated_count++;
            if ($new_status === 'halfday') {
                $halfday_count++;
            }
            
            echo "Updated Record ID {$row['id']} (Employee: {$row['EmployeeID']}, Date: {$row['attendance_date']}): ";
            echo "{$original_status} → {$new_status}";
            if ($new_record['total_hours'] > 0) {
                echo " (Hours: {$new_record['total_hours']})";
            }
            echo "\n";
        } else {
            echo "ERROR updating record ID {$row['id']}: " . $stmt->error . "\n";
        }
        
        $stmt->close();
    }
}

echo "\n==========================================\n";
echo "Recalculation Complete!\n";
echo "Total records processed: {$total_records}\n";
echo "Records updated: {$updated_count}\n";
echo "Records changed to HalfDay: {$halfday_count}\n";
echo "==========================================\n";

$conn->close();
?>
