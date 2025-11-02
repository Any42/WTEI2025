<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $position = $conn->real_escape_string($_POST['position']);
    $department = $conn->real_escape_string($_POST['department']);
    $salary = floatval($_POST['salary']);
    
    // Generate a default password (you might want to change this)
    $default_password = password_hash('password123', PASSWORD_DEFAULT);

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format";
        header("Location: AdminEmployees.php");
        exit;
    }

    // Check if email already exists
    $check_email = $conn->query("SELECT EmployeeID FROM empuser WHERE Email = '$email'");
    if ($check_email->num_rows > 0) {
        $_SESSION['error'] = "Email already exists";
        header("Location: AdminEmployees.php");
        exit;
    }

    // Insert into empuser table
    $sql = "INSERT INTO empuser (EmployeeName, Email, Password, Department, Position, base_salary, Role, Status) 
            VALUES ('$name', '$email', '$default_password', '$department', '$position', $salary, 'employee', 'active')";
    
    if ($conn->query($sql) === TRUE) {
        $_SESSION['success'] = "Employee added successfully";
    } else {
        $_SESSION['error'] = "Error: " . $conn->error;
    }
}

$conn->close();
header("Location: AdminEmployees.php");
exit;
?>