<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: AdminEmployees.php");
    exit;
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    $_SESSION['error'] = 'Database connection failed';
    header("Location: AdminEmployees.php");
    exit;
}

// Get employee ID from URL parameter
$employee_id = $_GET['id'] ?? null;
if (!$employee_id) {
    $_SESSION['error'] = 'No employee specified';
    header("Location: AdminEmployees.php");
    exit;
}

try {
    // Begin transaction
    $conn->begin_transaction();

    // First, check if employee exists
    $check_stmt = $conn->prepare("SELECT * FROM empuser WHERE EmployeeID = ?");
    $check_stmt->bind_param("s", $employee_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        $_SESSION['error'] = 'Employee not found';
        header("Location: AdminEmployees.php");
        exit;
    }
    
    $employee = $result->fetch_assoc();

    // Prepare variables for binding
    $status = 'Archived'; // Force status to Archived
    $archived_at = date('Y-m-d H:i:s');
    $archived_by = $_SESSION['EmployeeName'] ?? $_SESSION['username'] ?? 'System';

    // Insert into archive table
    $insert_query = "INSERT INTO employee_archive (
        NO, EmployeeID, EmployeeName, Birthdate, Age, LengthOfService,
        BloodType, TIN, SSS, PHIC, HDMF, PresentHomeAddress, PermanentHomeAddress,
        Allowance, LastDayEmployed, DateTransferred, AreaOfAssignment, EmployeeEmail,
        Password, DateHired, profile_picture, Department, Position, Contact,
        base_salary, Role, Status, created_at, updated_at, history,
        archived_at, archived_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $insert_stmt = $conn->prepare($insert_query);
    
    // Bind parameters correctly - all variables, no literals
    $insert_stmt->bind_param("isssissssssssdssssssssssdsssssss",
        $employee['NO'],
        $employee['EmployeeID'],
        $employee['EmployeeName'],
        $employee['Birthdate'],
        $employee['Age'],
        $employee['LengthOfService'],
        $employee['BloodType'],
        $employee['TIN'],
        $employee['SSS'],
        $employee['PHIC'],
        $employee['HDMF'],
        $employee['PresentHomeAddress'],
        $employee['PermanentHomeAddress'],
        $employee['Allowance'],
        $employee['LastDayEmployed'],
        $employee['DateTransferred'],
        $employee['AreaOfAssignment'],
        $employee['EmployeeEmail'],
        $employee['Password'],
        $employee['DateHired'],
        $employee['profile_picture'],
        $employee['Department'],
        $employee['Position'],
        $employee['Contact'],
        $employee['base_salary'],
        $employee['Role'],
        $status, // Now a variable
        $employee['created_at'],
        $employee['updated_at'],
        $employee['history'],
        $archived_at,
        $archived_by
    );

    if (!$insert_stmt->execute()) {
        throw new Exception("Failed to insert into archive: " . $insert_stmt->error);
    }

    // Then delete from empuser
    $delete_query = "DELETE FROM empuser WHERE EmployeeID = ?";
    $delete_stmt = $conn->prepare($delete_query);
    $delete_stmt->bind_param("s", $employee_id);
    
    if (!$delete_stmt->execute()) {
        throw new Exception("Failed to delete from empuser: " . $delete_stmt->error);
    }

    $conn->commit();
    
    $_SESSION['success'] = 'Employee successfully archived.';
    header("Location: AdminEmployees.php");
    exit;

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = 'Error during archiving: ' . $e->getMessage();
    header("Location: AdminEmployees.php");
    exit;
} finally {
    $conn->close();
}