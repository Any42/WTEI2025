<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['loggedin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

$employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;

if ($employee_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid employee ID']);
    $conn->close();
    exit;
}

// Get employee leave balance
$stmt = $conn->prepare("SELECT leave_pay_counts FROM empuser WHERE EmployeeID = ?");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
$stmt->close();

if (!$employee) {
    echo json_encode(['success' => false, 'message' => 'Employee not found']);
    $conn->close();
    exit;
}

echo json_encode([
    'success' => true,
    'leave_balance' => (int)$employee['leave_pay_counts']
]);

$conn->close();
?>
