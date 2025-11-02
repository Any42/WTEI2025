<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// For debugging - remove in production
error_log("AJAX Search Request - Session ID: " . session_id());
error_log("AJAX Search Request - Session Data: " . print_r($_SESSION, true));

// Check if user is logged in and is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get search term from POST data
$searchTerm = isset($_POST['search_term']) ? trim($_POST['search_term']) : '';

try {
    // Build the query based on search term
    if (empty($searchTerm)) {
        // If no search term, get first 5 available employees (not enrolled)
        $query = "SELECT EmployeeID, EmployeeName, fingerprint_enrolled, fingerprint_date, Status
                  FROM empuser 
                  WHERE Status = 'active' AND (fingerprint_enrolled IS NULL OR fingerprint_enrolled = 'no')
                  ORDER BY EmployeeName ASC
                  LIMIT 5";
        $stmt = $conn->prepare($query);
    } else {
        // If search term provided, filter by name or ID (no limit for search results)
        $query = "SELECT EmployeeID, EmployeeName, fingerprint_enrolled, fingerprint_date, Status
                  FROM empuser 
                  WHERE Status = 'active' 
                  AND (fingerprint_enrolled IS NULL OR fingerprint_enrolled = 'no')
                  AND (EmployeeName LIKE ? OR EmployeeID LIKE ?)
                  ORDER BY EmployeeName ASC";
        $searchPattern = "%{$searchTerm}%";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $searchPattern, $searchPattern);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $employees = [];
    $count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $employees[] = [
            'id' => $row['EmployeeID'],
            'name' => $row['EmployeeName'],
            'display_text' => $row['EmployeeID'] . ' - ' . $row['EmployeeName'],
            'enrolled' => ($row['fingerprint_enrolled'] ?? 'no') === 'yes',
            'enroll_date' => $row['fingerprint_date']
        ];
        $count++;
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'employees' => $employees,
        'count' => $count,
        'search_term' => $searchTerm
    ]);
    
} catch (Exception $e) {
    error_log("AJAX Search Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred',
        'message' => $e->getMessage()
    ]);
}

?>
