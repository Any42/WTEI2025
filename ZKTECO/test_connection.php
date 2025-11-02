<?php
/**
 * Test Connection Script for K30 Enrollment Service
 * Tests PHP to C# HTTP connection with proper HTTP/1.1 configuration
 */

// Allow access from anywhere
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: text/html; charset=UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>K30 Enrollment Service Connection Test</h1>\n";
echo "<pre>\n";

// Test Configuration
$serviceUrl = "http://127.0.0.1:8888";
$testEmployeeId = 9999;
$testEmployeeName = "Test Employee";

echo "===========================================\n";
echo "K30 SERVICE CONNECTION TEST\n";
echo "===========================================\n";
echo "Service URL: $serviceUrl\n";
echo "Test Employee ID: $testEmployeeId\n";
echo "Test Employee Name: $testEmployeeName\n";
echo "PHP Version: " . phpversion() . "\n";
echo "CURL Version: " . curl_version()['version'] . "\n";
echo "Date/Time: " . date('Y-m-d H:i:s') . "\n";
echo "===========================================\n\n";

// Test 1: Ping/Status Check
echo "TEST 1: Checking if service is running...\n";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$serviceUrl/status",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_HTTPHEADER => [
        'Connection: Keep-Alive',
        'User-Agent: WTEI-Test-Client/1.0',
        'Accept: application/json',
        'Expect:'
    ]
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$info = curl_getinfo($ch);

echo "  HTTP Code: $httpCode\n";
echo "  CURL Error: " . ($error ?: "None") . "\n";
echo "  Response: " . ($result ?: "Empty") . "\n";

if ($httpCode === 200) {
    echo "  ✓ Status: SERVICE IS RUNNING\n";
    $statusData = json_decode($result, true);
    if (isset($statusData['deviceConnected'])) {
        echo "  Device Connected: " . ($statusData['deviceConnected'] ? "YES" : "NO") . "\n";
    }
} else {
    echo "  ✗ Status: SERVICE NOT RESPONDING\n";
    echo "  Make sure the C# service is running!\n";
    curl_close($ch);
    exit(1);
}
curl_close($ch);
echo "\n";

// Test 2: Enrollment Request Test
echo "TEST 2: Testing enrollment request...\n";

$enrollmentData = [
    'employeeId' => $testEmployeeId,
    'employeeName' => $testEmployeeName,
    'timestamp' => time(),
    'requestId' => uniqid('test_', true)
];

$jsonData = json_encode($enrollmentData);
echo "  Request Data: $jsonData\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$serviceUrl/enroll",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonData,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonData),
        'Connection: Keep-Alive',
        'User-Agent: WTEI-Test-Client/1.0',
        'Accept: application/json',
        'Expect:'  // CRITICAL: Disable Expect header
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_VERBOSE => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,  // Force HTTP/1.1
    CURLOPT_HTTPPROXYTUNNEL => false,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_MAXREDIRS => 0
]);

// Capture verbose output
$verbose = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verbose);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$info = curl_getinfo($ch);

echo "  HTTP Code: $httpCode\n";
echo "  CURL Error: " . ($error ?: "None") . "\n";
echo "  Response: " . ($result ?: "Empty") . "\n";

// Get verbose output
rewind($verbose);
$verboseLog = stream_get_contents($verbose);
fclose($verbose);

if ($httpCode === 200) {
    echo "  ✓ Status: ENROLLMENT REQUEST SUCCESSFUL\n";
    $responseData = json_decode($result, true);
    if (isset($responseData['status'])) {
        echo "  Response Status: " . $responseData['status'] . "\n";
        if (isset($responseData['message'])) {
            echo "  Message: " . $responseData['message'] . "\n";
        }
    }
} else {
    echo "  ✗ Status: ENROLLMENT REQUEST FAILED\n";
    echo "\n  VERBOSE CURL OUTPUT:\n";
    echo "  " . str_replace("\n", "\n  ", $verboseLog) . "\n";
}

curl_close($ch);
echo "\n";

// Test 3: Connection Details
echo "TEST 3: Connection Analysis...\n";
echo "  Total Time: " . round($info['total_time'], 3) . " seconds\n";
echo "  Connect Time: " . round($info['connect_time'], 3) . " seconds\n";
echo "  HTTP Version: " . $info['http_version'] . "\n";
echo "  Primary IP: " . ($info['primary_ip'] ?? 'Unknown') . "\n";
echo "  Primary Port: " . ($info['primary_port'] ?? 'Unknown') . "\n";
echo "\n";

// Summary
echo "===========================================\n";
echo "TEST SUMMARY\n";
echo "===========================================\n";

if ($httpCode === 200 && !$error) {
    echo "✓ ALL TESTS PASSED\n";
    echo "✓ PHP to C# connection is working correctly\n";
    echo "✓ HTTP/1.1 protocol is being used\n";
    echo "✓ Service is ready for enrollment\n";
    echo "\nYou can now use the enrollment feature in AdminEmployees.php\n";
} else {
    echo "✗ TESTS FAILED\n";
    echo "✗ Connection has issues\n";
    echo "\nPLEASE CHECK:\n";
    echo "1. C# service is running (run ZKTest.exe)\n";
    echo "2. Service is listening on port 8888\n";
    echo "3. Firewall is not blocking localhost:8888\n";
    echo "4. No other service is using port 8888\n";
    echo "\nFor detailed troubleshooting, see HTTP_CONNECTION_FIX.md\n";
}

echo "===========================================\n";
echo "</pre>\n";
?>

