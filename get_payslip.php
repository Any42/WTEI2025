<?php
session_start();

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['admin', 'depthead'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Include centralized payroll computations
require_once 'payroll_computations.php';

// Get parameters
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

try {
    // Get employee details
    $employee_query = "SELECT * FROM empuser WHERE EmployeeID = ?";
    $stmt = $conn->prepare($employee_query);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $employee_result = $stmt->get_result();
    $employee = $employee_result->fetch_assoc();
    $stmt->close();

    if (!$employee) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit;
    }

    // Calculate payroll
    $payroll_data = calculatePayroll($employee_id, $employee['base_salary'], $month, $conn);
    
    // Add month to payroll data
    $payroll_data['month'] = $month;

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'payslip' => $payroll_data,
        'employee' => $employee
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Error processing request: ' . $e->getMessage()
    ]);
}

$conn->close();
?>