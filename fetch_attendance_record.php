<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

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

// Align MySQL session timezone
$conn->query("SET time_zone = '+08:00'");

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    $conn->close();
    exit;
}

// Fetch the attendance record with employee details
$stmt = $conn->prepare("SELECT a.*, e.Shift, e.EmployeeName, e.Department FROM attendance a JOIN empuser e ON a.EmployeeID = e.EmployeeID WHERE a.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$record = $result->fetch_assoc();
$stmt->close();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Record not found']);
    $conn->close();
    exit;
}

$conn->close();

echo json_encode(['success' => true, 'record' => $record]);
?>
