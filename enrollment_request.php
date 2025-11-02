<?php
/**
 * File-based enrollment request system
 * This creates a request file that the C# service can monitor
 */

function createEnrollmentRequest($employeeId, $employeeName) {
    $requestData = [
        'action' => 'enroll',
        'employeeId' => $employeeId,
        'employeeName' => $employeeName,
        'timestamp' => time(),
        'requestId' => uniqid(),
        'source' => 'web_app'
    ];
    
    // Create enrollment requests directory if it doesn't exist
    $requestsDir = __DIR__ . '/enrollment_requests';
    if (!is_dir($requestsDir)) {
        mkdir($requestsDir, 0755, true);
    }
    
    // Create request file
    $filename = $requestsDir . '/enroll_' . $employeeId . '_' . time() . '.json';
    $result = file_put_contents($filename, json_encode($requestData, JSON_PRETTY_PRINT));
    
    if ($result !== false) {
        error_log("Enrollment request created: {$filename}");
        return [
            'success' => true,
            'filename' => $filename,
            'requestId' => $requestData['requestId']
        ];
    } else {
        error_log("Failed to create enrollment request file: {$filename}");
        return [
            'success' => false,
            'error' => 'Failed to create enrollment request file'
        ];
    }
}

function checkEnrollmentStatus($requestId) {
    $responsesDir = __DIR__ . '/enrollment_responses';
    if (!is_dir($responsesDir)) {
        return ['status' => 'pending'];
    }
    
    $responseFile = $responsesDir . '/response_' . $requestId . '.json';
    if (file_exists($responseFile)) {
        $response = json_decode(file_get_contents($responseFile), true);
        if ($response) {
            // Clean up the response file after reading
            unlink($responseFile);
            return $response;
        }
    }
    
    return ['status' => 'pending'];
}

// Handle direct enrollment request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['employeeId']) && isset($_POST['employeeName'])) {
    $employeeId = intval($_POST['employeeId']);
    $employeeName = trim($_POST['employeeName']);
    
    $result = createEnrollmentRequest($employeeId, $employeeName);
    
    if ($result['success']) {
        echo json_encode([
            'status' => 'success',
            'message' => "Enrollment request created for {$employeeName}",
            'requestId' => $result['requestId'],
            'instructions' => [
                'step1' => 'Employee data has been sent to the K30 device',
                'step2' => 'Device should display the employee name',
                'step3' => 'Employee should place finger on the sensor',
                'step4' => 'Device will automatically capture fingerprint'
            ]
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => $result['error']
        ]);
    }
    exit;
}

// Handle status check
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['requestId'])) {
    $requestId = $_GET['requestId'];
    $status = checkEnrollmentStatus($requestId);
    echo json_encode($status);
    exit;
}
?>
