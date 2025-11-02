<?php
/**
 * Utility script to recalculate all existing attendance records with accurate calculations
 * Run this script once to update all existing records in the database
 */

require_once 'attendance_calculations.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

echo "Starting attendance recalculation...\n";

// Get all attendance records that have time_in
$query = "SELECT * FROM attendance WHERE time_in IS NOT NULL ORDER BY attendance_date DESC";
$result = $conn->query($query);

if (!$result) {
    die("Error fetching records: " . $conn->error);
}

$total_records = $result->num_rows;
$updated_count = 0;
$error_count = 0;

echo "Found {$total_records} records to process...\n";

while ($record = $result->fetch_assoc()) {
    try {
        // Calculate accurate metrics
        $updated_records = AttendanceCalculator::calculateAttendanceMetrics([$record]);
        $updated_record = $updated_records[0];
        
        // Update the record in database
        if (AttendanceCalculator::updateAttendanceRecord($conn, $record['id'], $updated_record)) {
            $updated_count++;
            if ($updated_count % 100 == 0) {
                echo "Processed {$updated_count} records...\n";
            }
        } else {
            $error_count++;
            echo "Error updating record ID {$record['id']}\n";
        }
    } catch (Exception $e) {
        $error_count++;
        echo "Error processing record ID {$record['id']}: " . $e->getMessage() . "\n";
    }
}

echo "\nRecalculation completed!\n";
echo "Total records: {$total_records}\n";
echo "Successfully updated: {$updated_count}\n";
echo "Errors: {$error_count}\n";

$conn->close();
?>
