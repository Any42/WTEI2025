<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = null;

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Set charset
    $conn->set_charset("utf8");
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception("Invalid JSON input or empty body");
    }
    
    $employee_id = isset($input['employee_id']) ? trim($input['employee_id']) : '';
    $status = isset($input['status']) ? trim($input['status']) : '';
    $message = isset($input['message']) ? trim($input['message']) : '';
    
    if (empty($employee_id) || empty($status)) {
        throw new Exception("Employee ID and status are required. Received: employee_id='" . $employee_id . "', status='" . $status . "'");
    }
    
    // Valid status values
    $valid_statuses = ['pending', 'in_progress', 'completed', 'failed'];
    if (!in_array($status, $valid_statuses)) {
        throw new Exception("Invalid status '" . $status . "'. Must be one of: " . implode(', ', $valid_statuses));
    }
    
    // Check if table exists first
    $table_check = $conn->query("SHOW TABLES LIKE 'fingerprint_enrollment_queue'");
    if ($table_check->num_rows == 0) {
        // Table doesn't exist, create it
        $create_table = "CREATE TABLE `fingerprint_enrollment_queue` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `employee_id` varchar(10) NOT NULL,
            `status` enum('pending','in_progress','completed','failed') DEFAULT 'pending',
            `message` text DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if (!$conn->query($create_table)) {
            throw new Exception("Failed to create table: " . $conn->error);
        }
    }
    
    // Update enrollment status
    $update_query = "UPDATE fingerprint_enrollment_queue 
                     SET status = ?, message = ?, updated_at = NOW() 
                     WHERE employee_id = ?";
    
    $stmt = $conn->prepare($update_query);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("sss", $status, $message, $employee_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    // If no rows were affected, the employee_id might not exist in queue
    if ($affected_rows === 0) {
        throw new Exception("No enrollment record found for employee ID: " . $employee_id . ". Please initiate enrollment from web interface first.");
    }
    
    // If enrollment is completed, also update the empuser table
    if ($status === 'completed') {
        // Check if empuser table exists and has the required columns
        $empuser_check = $conn->query("SHOW TABLES LIKE 'empuser'");
        if ($empuser_check->num_rows > 0) {
            // Check if columns exist
            $column_check = $conn->query("SHOW COLUMNS FROM empuser LIKE 'fingerprint_enrolled'");
            if ($column_check->num_rows == 0) {
                // Add the columns if they don't exist
                $conn->query("ALTER TABLE empuser ADD COLUMN fingerprint_enrolled enum('yes','no') DEFAULT 'no'");
                $conn->query("ALTER TABLE empuser ADD COLUMN fingerprint_date datetime DEFAULT NULL");
            }
            
            $update_user_query = "UPDATE empuser 
                                  SET fingerprint_enrolled = 'yes', 
                                      fingerprint_date = NOW() 
                                  WHERE EmployeeDisplayID = ?";
            
            $user_stmt = $conn->prepare($update_user_query);
            if ($user_stmt) {
                $user_stmt->bind_param("s", $employee_id);
                $user_stmt->execute();
                $user_stmt->close();
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Enrollment status updated successfully',
        'employee_id' => $employee_id,
        'status' => $status,
        'updated_message' => $message,
        'affected_rows' => $affected_rows
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => true,
        'message' => $e->getMessage(),
        'file' => __FILE__,
        'line' => __LINE__
    ]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => true,
        'message' => 'Fatal error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} finally {
    if ($conn && !$conn->connect_error) {
        $conn->close();
    }
}
?>