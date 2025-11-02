<?php
// Database migration script to add overtime columns
require_once 'db_connect.php';

echo "===============================================================\n";
echo "           ADDING OVERTIME COLUMNS TO ATTENDANCE TABLE\n";
echo "===============================================================\n";

try {
    // Check if columns already exist
    $checkQuery = "SHOW COLUMNS FROM attendance LIKE 'overtime_time_in'";
    $result = $conn->query($checkQuery);
    
    if ($result->num_rows > 0) {
        echo "✓ Overtime columns already exist\n";
    } else {
        echo "Adding overtime columns...\n";
        
        // Add overtime time columns
        $alterQuery = "ALTER TABLE attendance 
                       ADD COLUMN overtime_time_in TIME NULL COMMENT '5th punch - overtime time in',
                       ADD COLUMN overtime_time_out TIME NULL COMMENT '6th punch - overtime time out'";
        
        if ($conn->query($alterQuery)) {
            echo "✓ Successfully added overtime columns\n";
        } else {
            echo "✗ Error adding columns: " . $conn->error . "\n";
            exit(1);
        }
    }
    
    // Add indexes for better performance
    echo "Adding indexes...\n";
    
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_overtime_time_in ON attendance(overtime_time_in)",
        "CREATE INDEX IF NOT EXISTS idx_overtime_time_out ON attendance(overtime_time_out)",
        "CREATE INDEX IF NOT EXISTS idx_employee_date_overtime ON attendance(EmployeeID, attendance_date, overtime_time_in, overtime_time_out)"
    ];
    
    foreach ($indexes as $indexQuery) {
        if ($conn->query($indexQuery)) {
            echo "✓ Index created successfully\n";
        } else {
            echo "⚠ Index creation warning: " . $conn->error . "\n";
        }
    }
    
    // Show the updated table structure
    echo "\nUpdated table structure:\n";
    echo "===============================================================\n";
    
    $describeQuery = "DESCRIBE attendance";
    $result = $conn->query($describeQuery);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            echo sprintf("%-20s %-15s %-5s %-5s %-10s %s\n", 
                $row['Field'], 
                $row['Type'], 
                $row['Null'], 
                $row['Key'], 
                $row['Default'], 
                $row['Extra']
            );
        }
    }
    
    echo "\n===============================================================\n";
    echo "✓ Migration completed successfully!\n";
    echo "✓ Overtime columns are now ready for 5th+ fingerprint punches\n";
    echo "===============================================================\n";
    
} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    $conn->close();
}
?>
