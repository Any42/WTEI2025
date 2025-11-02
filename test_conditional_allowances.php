<?php
/**
 * Test script for conditional allowance display
 * This script tests that allowances are only shown when employees have values > 0
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

echo "<h2>Testing Conditional Allowance Display</h2>\n";

// Test 1: Get all employees and check their allowance values
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
    echo "<th>Eligible</th>\n";
    echo "<th>Rice Allowance</th>\n";
    echo "<th>Medical Allowance</th>\n";
    echo "<th>Laundry Allowance</th>\n";
    echo "<th>Should Show Rice</th>\n";
    echo "<th>Should Show Medical</th>\n";
    echo "<th>Should Show Laundry</th>\n";
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
        
        // Determine if allowances should be shown
        $showRice = $allowances['rice_allowance'] > 0;
        $showMedical = $allowances['medical_allowance'] > 0;
        $showLaundry = $allowances['laundry_allowance'] > 0;
        
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
        echo "<td style='color: " . ($showRice ? 'green' : 'red') . "; font-weight: bold;'>" . ($showRice ? 'YES' : 'NO') . "</td>\n";
        echo "<td style='color: " . ($showMedical ? 'green' : 'red') . "; font-weight: bold;'>" . ($showMedical ? 'YES' : 'NO') . "</td>\n";
        echo "<td style='color: " . ($showLaundry ? 'green' : 'red') . "; font-weight: bold;'>" . ($showLaundry ? 'YES' : 'NO') . "</td>\n";
        echo "</tr>\n";
    }
    
    echo "</table>\n";
} else {
    echo "<p>No employees found.</p>\n";
}

// Test 2: Test specific scenarios
echo "<h3>Test Scenarios for Conditional Display</h3>\n";

$testScenarios = [
    ['rice' => 0, 'medical' => 0, 'laundry' => 0, 'description' => 'All allowances = 0 - Should show NONE'],
    ['rice' => 100, 'medical' => 0, 'laundry' => 0, 'description' => 'Only rice = 100 - Should show ONLY rice'],
    ['rice' => 0, 'medical' => 50, 'laundry' => 0, 'description' => 'Only medical = 50 - Should show ONLY medical'],
    ['rice' => 0, 'medical' => 0, 'laundry' => 200, 'description' => 'Only laundry = 200 - Should show ONLY laundry'],
    ['rice' => 100, 'medical' => 50, 'laundry' => 200, 'description' => 'All allowances > 0 - Should show ALL'],
    ['rice' => 0, 'medical' => 50, 'laundry' => 200, 'description' => 'Medical + Laundry only - Should show medical and laundry'],
];

echo "<ul>\n";
foreach ($testScenarios as $scenario) {
    echo "<li><strong>" . $scenario['description'] . "</strong><br>\n";
    echo "Rice: " . ($scenario['rice'] > 0 ? 'SHOW' : 'HIDE') . " | ";
    echo "Medical: " . ($scenario['medical'] > 0 ? 'SHOW' : 'HIDE') . " | ";
    echo "Laundry: " . ($scenario['laundry'] > 0 ? 'SHOW' : 'HIDE') . "</li>\n";
}
echo "</ul>\n";

echo "<h3>Implementation Summary</h3>\n";
echo "<p>The conditional allowance display has been implemented with the following rules:</p>\n";
echo "<ul>\n";
echo "<li><strong>Payroll.php</strong>: JavaScript shows/hides allowance rows based on values > 0</li>\n";
echo "<li><strong>PayrollHistory.php</strong>: JavaScript shows/hides allowance rows based on values > 0</li>\n";
echo "<li><strong>EmpPayroll.php</strong>: PHP conditionally renders allowance rows based on values > 0</li>\n";
echo "<li><strong>generate_payslip_pdf.php</strong>: PHP conditionally adds allowance lines to PDF based on values > 0</li>\n";
echo "</ul>\n";

echo "<h3>Files Modified</h3>\n";
echo "<ul>\n";
echo "<li>Payroll.php - Added conditional display in payslip modal</li>\n";
echo "<li>PayrollHistory.php - Added conditional display in payslip modal</li>\n";
echo "<li>EmpPayroll.php - Added conditional display in earnings section and modal</li>\n";
echo "<li>generate_payslip_pdf.php - Added conditional display in PDF export</li>\n";
echo "</ul>\n";

$conn->close();
?>
