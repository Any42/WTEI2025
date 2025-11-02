<?php
/**
 * Fix Overtime Calculations Script
 * 
 * This script fixes the overtime calculation issues caused by:
 * 1. ZKTECO Program.cs overwriting afternoon timeout with test punches
 * 2. Attendance calculations not being recalculated properly
 * 3. Payroll using incorrect overtime data
 * 
 * Usage: php fix_overtime_calculations.php [date]
 * If no date is provided, it will fix today's data
 */

require_once 'db_connect.php';
require_once 'attendance_calculations.php';
require_once 'payroll_computations.php';

// Get date from command line argument or use today
$targetDate = isset($argv[1]) ? $argv[1] : date('Y-m-d');

echo "===============================================================\n";
echo "           FIXING OVERTIME CALCULATIONS\n";
echo "===============================================================\n";
echo "Target Date: {$targetDate}\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "===============================================================\n\n";

try {
    // Create database connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    echo "✓ Database connection established\n";
    
    // Create overtime punches table
    echo "Creating overtime punches table...\n";
    if (createOvertimePunchesTable($conn)) {
        echo "✓ Overtime punches table ready\n";
    } else {
        echo "⚠ Warning: Could not create overtime punches table\n";
    }
    
    // Force recalculation of overtime for the target date
    echo "\nRecalculating overtime for {$targetDate}...\n";
    $summary = forceRecalculateOvertimeForDate($targetDate, $conn);
    
    echo "\n===============================================================\n";
    echo "                    RECALCULATION RESULTS\n";
    echo "===============================================================\n";
    echo "Total Records Processed: {$summary['total_records']}\n";
    echo "Records Updated: {$summary['updated_records']}\n";
    echo "Overtime Corrected: {$summary['overtime_corrected']}\n";
    
    if (!empty($summary['errors'])) {
        echo "\nErrors:\n";
        foreach ($summary['errors'] as $error) {
            echo "  - {$error}\n";
        }
    }
    
    // Show some examples of corrected overtime
    if ($summary['overtime_corrected'] > 0) {
        echo "\n===============================================================\n";
        echo "                OVERTIME CORRECTION EXAMPLES\n";
        echo "===============================================================\n";
        
        // Get some examples of corrected records
        $exampleQuery = "SELECT a.EmployeeID, e.EmployeeName, a.attendance_date, 
                                a.time_out_afternoon, a.overtime_hours, a.is_overtime
                         FROM attendance a 
                         JOIN empuser e ON a.EmployeeID = e.EmployeeID
                         WHERE a.attendance_date = ? 
                         AND a.overtime_hours > 0
                         ORDER BY a.overtime_hours DESC
                         LIMIT 5";
        
        $stmt = $conn->prepare($exampleQuery);
        $stmt->bind_param("s", $targetDate);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            echo "Employee {$row['EmployeeID']} ({$row['EmployeeName']}):\n";
            echo "  Time Out: {$row['time_out_afternoon']}\n";
            echo "  Overtime Hours: {$row['overtime_hours']}\n";
            echo "  Is Overtime: " . ($row['is_overtime'] ? 'Yes' : 'No') . "\n";
            echo "  ---\n";
        }
        $stmt->close();
    }
    
    echo "\n===============================================================\n";
    echo "                    NEXT STEPS\n";
    echo "===============================================================\n";
    echo "1. Restart the ZKTECO Program.cs to apply the punch fix\n";
    echo "2. Test with a few fingerprint punches to ensure no overwriting\n";
    echo "3. Check payroll calculations to verify overtime amounts\n";
    echo "4. Monitor the system for any remaining issues\n";
    echo "\nCompleted at: " . date('Y-m-d H:i:s') . "\n";
    echo "===============================================================\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
