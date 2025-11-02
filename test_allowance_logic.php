<?php
/**
 * Test script for half-month allowance logic
 * This script tests the new allowance eligibility function
 */

require_once 'payroll_computations.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

echo "<h2>Testing Half-Month Allowance Logic</h2>\n";

// Test 1: Get all employees and check their allowance eligibility
$test_month = date('Y-m');
echo "<h3>Testing for month: $test_month</h3>\n";

$employees_query = "SELECT EmployeeID, EmployeeName, Department, 
                   rice_allowance, medical_allowance, laundry_allowance
                   FROM empuser 
                   WHERE Status = 'active' 
                   ORDER BY Department, EmployeeName";

$result = $conn->query($employees_query);

if ($result && $result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
    echo "<tr style='background-color: #f0f0f0;'>\n";
    echo "<th>Employee ID</th>\n";
    echo "<th>Name</th>\n";
    echo "<th>Department</th>\n";
    echo "<th>Work Days</th>\n";
    echo "<th>Eligible for Allowances</th>\n";
    echo "<th>Rice Allowance</th>\n";
    echo "<th>Medical Allowance</th>\n";
    echo "<th>Laundry Allowance</th>\n";
    echo "</tr>\n";
    
    while ($employee = $result->fetch_assoc()) {
        $employeeId = $employee['EmployeeID'];
        $isEligible = isEligibleForAllowances($employeeId, $test_month, $conn);
        $allowances = getEmployeeAllowances($employeeId, $test_month, $conn);
        
        // Get work days for display
        $workDaysQuery = "SELECT COUNT(DISTINCT DATE(attendance_date)) as work_days
                         FROM attendance 
                         WHERE EmployeeID = ? 
                         AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
                         AND time_in IS NOT NULL";
        $stmt = $conn->prepare($workDaysQuery);
        $workDays = 0;
        if ($stmt) {
            $stmt->bind_param("is", $employeeId, $test_month);
            $stmt->execute();
            $workResult = $stmt->get_result();
            $workRow = $workResult->fetch_assoc();
            $workDays = $workRow ? intval($workRow['work_days']) : 0;
            $stmt->close();
        }
        
        $rowColor = $isEligible ? '#d4edda' : '#f8d7da';
        
        echo "<tr style='background-color: $rowColor;'>\n";
        echo "<td>" . htmlspecialchars($employee['EmployeeID']) . "</td>\n";
        echo "<td>" . htmlspecialchars($employee['EmployeeName']) . "</td>\n";
        echo "<td>" . htmlspecialchars($employee['Department']) . "</td>\n";
        echo "<td>" . $workDays . "</td>\n";
        echo "<td>" . ($isEligible ? 'YES' : 'NO') . "</td>\n";
        echo "<td>₱" . number_format($allowances['rice_allowance'], 2) . "</td>\n";
        echo "<td>₱" . number_format($allowances['medical_allowance'], 2) . "</td>\n";
        echo "<td>₱" . number_format($allowances['laundry_allowance'], 2) . "</td>\n";
        echo "</tr>\n";
    }
    
    echo "</table>\n";
} else {
    echo "<p>No employees found.</p>\n";
}

// Test 2: Test specific scenarios
echo "<h3>Test Scenarios</h3>\n";

// Test with different work day counts
$testScenarios = [
    ['days' => 5, 'expected' => false, 'description' => '5 days - Should NOT get allowances'],
    ['days' => 10, 'expected' => false, 'description' => '10 days - Should NOT get allowances'],
    ['days' => 15, 'expected' => true, 'description' => '15 days - Should get allowances'],
    ['days' => 20, 'expected' => true, 'description' => '20 days - Should get allowances'],
    ['days' => 25, 'expected' => true, 'description' => '25 days - Should get allowances']
];

echo "<ul>\n";
foreach ($testScenarios as $scenario) {
    echo "<li><strong>" . $scenario['description'] . "</strong> - ";
    echo "Expected: " . ($scenario['expected'] ? 'YES' : 'NO') . "</li>\n";
}
echo "</ul>\n";

echo "<h3>Summary</h3>\n";
echo "<p>The allowance logic has been implemented with the following rules:</p>\n";
echo "<ul>\n";
echo "<li>Employees must work at least 15 days in a month to be eligible for allowances</li>\n";
echo "<li>Allowances are retrieved from the database (rice_allowance, medical_allowance, laundry_allowance columns)</li>\n";
echo "<li>If an employee works less than 15 days, all allowances are set to 0</li>\n";
echo "<li>This applies to all payroll calculations, payslips, and exports</li>\n";
echo "</ul>\n";

$conn->close();
?>
