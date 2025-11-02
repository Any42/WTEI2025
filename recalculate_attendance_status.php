<?php
/**
 * Recalculate Attendance Status Script
 * This script recalculates the status for all attendance records using the updated logic
 */

session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Asia/Manila');

// Include attendance calculations
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

// Align MySQL session timezone with PHP
$conn->query("SET time_zone = '+08:00'");

echo "<h1>Recalculating Attendance Status</h1>";
echo "<p>This script will update the status for all attendance records using the new half-day logic.</p>";

// Get all attendance records that need recalculation
$query = "SELECT a.*, e.Shift FROM attendance a 
          JOIN empuser e ON a.EmployeeID = e.EmployeeID
          WHERE a.time_in IS NOT NULL
          ORDER BY a.attendance_date DESC, a.EmployeeID";

$result = $conn->query($query);

if (!$result) {
    die("Error fetching records: " . $conn->error);
}

$total_records = $result->num_rows;
$updated_count = 0;
$halfday_count = 0;
$status_changes = [];

echo "<p>Found {$total_records} attendance records to process...</p>";
echo "<div style='max-height: 400px; overflow-y: auto; border: 1px solid #ccc; padding: 10px;'>";

while ($record = $result->fetch_assoc()) {
    // Get shift schedule
    [$schedStart, $schedEnd] = AttendanceCalculator::getShiftSchedule($record['Shift'] ?? '');
    
    // Recalculate status using the new logic
    $new_status = AttendanceCalculator::determineStatus(
        $record['time_in'],
        $record['time_out'],
        $record['late_minutes'] ?? 0,
        $record['early_out_minutes'] ?? 0,
        $schedEnd,
        $record['attendance_date'],
        $schedStart,
        $record['time_in_morning'],
        $record['time_out_morning'],
        $record['time_in_afternoon'],
        $record['time_out_afternoon'],
        $record['total_hours'] ?? 0
    );
    
    $old_status = $record['status'];
    
    // Update the record if status changed
    if ($old_status !== $new_status) {
        $update_query = "UPDATE attendance SET status = ? WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("si", $new_status, $record['id']);
        
        if ($stmt->execute()) {
            $updated_count++;
            $status_changes[] = [
                'employee_id' => $record['EmployeeID'],
                'date' => $record['attendance_date'],
                'old_status' => $old_status,
                'new_status' => $new_status
            ];
            
            if ($new_status === 'halfday') {
                $halfday_count++;
            }
            
            echo "<div style='margin: 2px 0; padding: 2px; background: #f0f0f0;'>";
            echo "Employee {$record['EmployeeID']} ({$record['attendance_date']}): {$old_status} → {$new_status}";
            echo "</div>";
        }
        
        $stmt->close();
    }
}

echo "</div>";

echo "<h2>Summary</h2>";
echo "<p><strong>Total records processed:</strong> {$total_records}</p>";
echo "<p><strong>Records updated:</strong> {$updated_count}</p>";
echo "<p><strong>Half-day records found:</strong> {$halfday_count}</p>";

if (!empty($status_changes)) {
    echo "<h3>Status Changes Summary</h3>";
    $status_summary = [];
    foreach ($status_changes as $change) {
        $key = $change['old_status'] . ' → ' . $change['new_status'];
        $status_summary[$key] = ($status_summary[$key] ?? 0) + 1;
    }
    
    echo "<ul>";
    foreach ($status_summary as $change => $count) {
        echo "<li>{$change}: {$count} records</li>";
    }
    echo "</ul>";
}

echo "<p><strong>Recalculation completed at:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "<p><a href='AdminAttendance.php'>← Back to Admin Attendance</a></p>";

$conn->close();
?>
