<?php
/**
 * Script to fix incorrect attendance data
 * This script identifies and corrects illogical time entries in the attendance table
 */

session_start();
date_default_timezone_set('Asia/Manila');

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}
$conn->query("SET time_zone = '+08:00'");

require_once 'attendance_calculations.php';

echo "<h2>Attendance Data Correction Report</h2>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .error { color: red; }
    .success { color: green; }
    .warning { color: orange; }
    .info { color: blue; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
</style>\n";

// Function to get shift schedule and lunch window
function getShiftScheduleAndLunch($shift) {
    $shift = trim($shift);
    
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
            return ['22:00:00', '06:00:00', '02:00:00', '03:00:00'];
    }
    
    // Handle generic patterns
    if (preg_match('/(\d{1,2}:\d{2})-(\d{1,2}:\d{2})/', $shift, $matches)) {
        $startTime = $matches[1];
        $endTime = $matches[2];
        
        $startTime = strlen($startTime) === 4 ? '0' . $startTime : $startTime;
        $endTime = strlen($endTime) === 4 ? '0' . $endTime : $endTime;
        
        $startHour = (int)substr($startTime, 0, 2);
        
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
    
    return ['08:00:00', '17:00:00', '12:00:00', '13:00:00'];
}

// Find problematic records
echo "<h3>1. Identifying Problematic Records</h3>\n";

$problems = [];

// Query for records with time_out before time_in
$query1 = "SELECT a.*, e.Shift, e.EmployeeName 
           FROM attendance a 
           JOIN empuser e ON a.EmployeeID = e.EmployeeID 
           WHERE a.time_in IS NOT NULL 
           AND a.time_out IS NOT NULL 
           AND a.time_in > a.time_out";

$result1 = $conn->query($query1);
if ($result1->num_rows > 0) {
    echo "<p class='error'>Found " . $result1->num_rows . " records with time_out before time_in:</p>\n";
    echo "<table>\n";
    echo "<tr><th>ID</th><th>Employee</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Shift</th></tr>\n";
    
    while ($row = $result1->fetch_assoc()) {
        $problems[] = $row;
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['EmployeeName'] . "</td>";
        echo "<td>" . $row['attendance_date'] . "</td>";
        echo "<td class='error'>" . $row['time_in'] . "</td>";
        echo "<td class='error'>" . $row['time_out'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . $row['Shift'] . "</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
}

// Query for records with morning time_out before morning time_in
$query2 = "SELECT a.*, e.Shift, e.EmployeeName 
           FROM attendance a 
           JOIN empuser e ON a.EmployeeID = e.EmployeeID 
           WHERE a.time_in_morning IS NOT NULL 
           AND a.time_out_morning IS NOT NULL 
           AND a.time_in_morning > a.time_out_morning";

$result2 = $conn->query($query2);
if ($result2->num_rows > 0) {
    echo "<p class='error'>Found " . $result2->num_rows . " records with morning time_out before morning time_in:</p>\n";
    echo "<table>\n";
    echo "<tr><th>ID</th><th>Employee</th><th>Date</th><th>AM In</th><th>AM Out</th><th>Status</th><th>Shift</th></tr>\n";
    
    while ($row = $result2->fetch_assoc()) {
        $problems[] = $row;
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['EmployeeName'] . "</td>";
        echo "<td>" . $row['attendance_date'] . "</td>";
        echo "<td class='error'>" . $row['time_in_morning'] . "</td>";
        echo "<td class='error'>" . $row['time_out_morning'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . $row['Shift'] . "</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
}

// Query for records with afternoon time_out before afternoon time_in
$query3 = "SELECT a.*, e.Shift, e.EmployeeName 
           FROM attendance a 
           JOIN empuser e ON a.EmployeeID = e.EmployeeID 
           WHERE a.time_in_afternoon IS NOT NULL 
           AND a.time_out_afternoon IS NOT NULL 
           AND a.time_in_afternoon > a.time_out_afternoon";

$result3 = $conn->query($query3);
if ($result3->num_rows > 0) {
    echo "<p class='error'>Found " . $result3->num_rows . " records with afternoon time_out before afternoon time_in:</p>\n";
    echo "<table>\n";
    echo "<tr><th>ID</th><th>Employee</th><th>Date</th><th>PM In</th><th>PM Out</th><th>Status</th><th>Shift</th></tr>\n";
    
    while ($row = $result3->fetch_assoc()) {
        $problems[] = $row;
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['EmployeeName'] . "</td>";
        echo "<td>" . $row['attendance_date'] . "</td>";
        echo "<td class='error'>" . $row['time_in_afternoon'] . "</td>";
        echo "<td class='error'>" . $row['time_out_afternoon'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . $row['Shift'] . "</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
}

// Query for records with illogical morning times (like 8 PM marked as morning)
$query4 = "SELECT a.*, e.Shift, e.EmployeeName 
           FROM attendance a 
           JOIN empuser e ON a.EmployeeID = e.EmployeeID 
           WHERE a.time_in_morning IS NOT NULL 
           AND TIME(a.time_in_morning) > '12:00:00'";

$result4 = $conn->query($query4);
if ($result4->num_rows > 0) {
    echo "<p class='warning'>Found " . $result4->num_rows . " records with morning time_in after 12:00 PM:</p>\n";
    echo "<table>\n";
    echo "<tr><th>ID</th><th>Employee</th><th>Date</th><th>AM In</th><th>Shift</th><th>Status</th></tr>\n";
    
    while ($row = $result4->fetch_assoc()) {
        $problems[] = $row;
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['EmployeeName'] . "</td>";
        echo "<td>" . $row['attendance_date'] . "</td>";
        echo "<td class='warning'>" . $row['time_in_morning'] . "</td>";
        echo "<td>" . $row['Shift'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
}

if (empty($problems)) {
    echo "<p class='success'>No problematic records found!</p>\n";
} else {
    echo "<h3>2. Fixing Problematic Records</h3>\n";
    
    $fixed_count = 0;
    $skipped_count = 0;
    
    foreach ($problems as $record) {
        echo "<h4>Fixing Record ID: " . $record['id'] . " (Employee: " . $record['EmployeeName'] . ")</h4>\n";
        
        // Get shift schedule
        $shift = $record['Shift'] ?? '';
        [$schedStart, $schedEnd, $lunchStart, $lunchEnd] = getShiftScheduleAndLunch($shift);
        
        echo "<p class='info'>Shift: $shift | Schedule: $schedStart - $schedEnd | Lunch: $lunchStart - $lunchEnd</p>\n";
        
        $updates = [];
        $params = [];
        $types = '';
        
        // Fix time sequence issues
        if ($record['time_in'] && $record['time_out'] && $record['time_in'] > $record['time_out']) {
            echo "<p class='error'>Fixing overall time sequence...</p>\n";
            
            // For halfday records, use proper shift schedule
            if ($record['status'] === 'halfday') {
                if (strpos($record['notes'], 'Morning Only') !== false) {
                    $updates[] = "time_in = ?";
                    $updates[] = "time_out = ?";
                    $updates[] = "time_in_morning = ?";
                    $updates[] = "time_out_morning = ?";
                    $updates[] = "time_in_afternoon = NULL";
                    $updates[] = "time_out_afternoon = NULL";
                    $params[] = $schedStart;
                    $params[] = $lunchStart;
                    $params[] = $schedStart;
                    $params[] = $lunchStart;
                    $types .= "ssss";
                } elseif (strpos($record['notes'], 'Afternoon Only') !== false) {
                    $updates[] = "time_in = ?";
                    $updates[] = "time_out = ?";
                    $updates[] = "time_in_morning = NULL";
                    $updates[] = "time_out_morning = NULL";
                    $updates[] = "time_in_afternoon = ?";
                    $updates[] = "time_out_afternoon = ?";
                    $params[] = $lunchEnd;
                    $params[] = $schedEnd;
                    $params[] = $lunchEnd;
                    $params[] = $schedEnd;
                    $types .= "ssss";
                }
            } else {
                // For regular records, swap the times if they're clearly wrong
                $updates[] = "time_in = ?";
                $updates[] = "time_out = ?";
                $params[] = $record['time_out']; // Swap them
                $params[] = $record['time_in'];
                $types .= "ss";
            }
        }
        
        // Fix morning time sequence
        if ($record['time_in_morning'] && $record['time_out_morning'] && $record['time_in_morning'] > $record['time_out_morning']) {
            echo "<p class='error'>Fixing morning time sequence...</p>\n";
            $updates[] = "time_in_morning = ?";
            $updates[] = "time_out_morning = ?";
            $params[] = $record['time_out_morning'];
            $params[] = $record['time_in_morning'];
            $types .= "ss";
        }
        
        // Fix afternoon time sequence
        if ($record['time_in_afternoon'] && $record['time_out_afternoon'] && $record['time_in_afternoon'] > $record['time_out_afternoon']) {
            echo "<p class='error'>Fixing afternoon time sequence...</p>\n";
            $updates[] = "time_in_afternoon = ?";
            $updates[] = "time_out_afternoon = ?";
            $params[] = $record['time_out_afternoon'];
            $params[] = $record['time_in_afternoon'];
            $types .= "ss";
        }
        
        // Fix illogical morning times
        if ($record['time_in_morning'] && TIME($record['time_in_morning']) > '12:00:00') {
            echo "<p class='warning'>Fixing illogical morning time...</p>\n";
            $updates[] = "time_in_morning = ?";
            $updates[] = "time_out_morning = ?";
            $params[] = $schedStart;
            $params[] = $lunchStart;
            $types .= "ss";
        }
        
        if (!empty($updates)) {
            $updates[] = "data_source = 'corrected'";
            $types .= "s";
            $params[] = 'corrected';
            $params[] = $record['id'];
            $types .= "i";
            
            $sql = "UPDATE attendance SET " . implode(", ", $updates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                echo "<p class='success'>✓ Fixed successfully</p>\n";
                $fixed_count++;
            } else {
                echo "<p class='error'>✗ Failed to fix: " . $stmt->error . "</p>\n";
                $skipped_count++;
            }
            $stmt->close();
        } else {
            echo "<p class='info'>No fixes needed for this record</p>\n";
            $skipped_count++;
        }
        
        echo "<hr>\n";
    }
    
    echo "<h3>3. Summary</h3>\n";
    echo "<p class='success'>Records fixed: $fixed_count</p>\n";
    echo "<p class='info'>Records skipped: $skipped_count</p>\n";
    echo "<p class='info'>Total records processed: " . count($problems) . "</p>\n";
}

$conn->close();

echo "<h3>4. Recommendations</h3>\n";
echo "<ul>\n";
echo "<li>Always validate time entries before saving to prevent future issues</li>\n";
echo "<li>Use the shift schedule as a reference for logical time ranges</li>\n";
echo "<li>For halfday records, always use the proper shift schedule times</li>\n";
echo "<li>Regularly audit attendance data for consistency</li>\n";
echo "</ul>\n";

echo "<p class='success'><strong>Data correction completed!</strong></p>\n";
?>
