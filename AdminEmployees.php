<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}
// ADD THIS TO THE TOP OF YOUR PHP FILE
// Ensure proper UTF-8 handling throughout
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
if (function_exists('mb_regex_encoding')) {
    mb_regex_encoding('UTF-8');
}
header('Content-Type: text/html; charset=utf-8');

// Include K30 device configuration
require_once 'k30_config.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Debug: Check if we're in debug mode
$debug_mode = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($debug_mode) {
    error_log("=== DEBUG MODE ENABLED ===");
    error_log("PHP Version: " . phpversion());
    error_log("CURL Available: " . (function_exists('curl_init') ? 'Yes' : 'No'));
    error_log("JSON Available: " . (function_exists('json_encode') ? 'Yes' : 'No'));
    error_log("Current Time: " . date('Y-m-d H:i:s'));
    error_log("Server: " . $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown');
}

// Handle AJAX request for next employee ID
if (isset($_GET['action']) && $_GET['action'] === 'get_next_employee_id') {
    header('Content-Type: application/json');
    
    try {
        $current_year = date('Y');
        
        // Get the highest employee ID for current year
        $query = "SELECT MAX(CAST(SUBSTRING(EmployeeID, 5, 3) AS UNSIGNED)) as max_number 
                  FROM empuser 
                  WHERE EmployeeID LIKE '$current_year%' 
                  AND LENGTH(EmployeeID) = 7";
        
        $result = $conn->query($query);
        
        if ($result && $row = $result->fetch_assoc()) {
            $next_number = ($row['max_number'] ?? 0) + 1;
            $next_employee_id = $current_year . str_pad($next_number, 3, '0', STR_PAD_LEFT);
            echo json_encode(['next_employee_id' => $next_employee_id]);
        } else {
            echo json_encode(['next_employee_id' => $current_year . '001']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    
    $conn->close();
    exit;
}

// Handle view_employee parameter to show specific employee modal
$viewEmployeeID = null;
$employeeToView = null;
if (isset($_GET['view_employee']) && !empty($_GET['view_employee'])) {
    $viewEmployeeID = $_GET['view_employee'];
    
    // Fetch employee data for the modal
    $view_query = "SELECT * FROM empuser WHERE EmployeeID = ? AND Status = 'active'";
    $stmt_view = $conn->prepare($view_query);
    if ($stmt_view) {
        $stmt_view->bind_param("s", $viewEmployeeID);
        $stmt_view->execute();
        $result_view = $stmt_view->get_result();
        if ($result_view && $result_view->num_rows > 0) {
            $employeeToView = $result_view->fetch_assoc();
        }
        $stmt_view->close();
    }
}

// =======================
// ARCHIVE EMPLOYEE
function archiveEmployee($employeeID, $archivedBy, $conn) {
    try {
        // Ensure employee exists
        $check = $conn->prepare("SELECT COUNT(*) AS c FROM empuser WHERE EmployeeID = ?");
        $check->bind_param("i", $employeeID);
        $check->execute();
        $cRes = $check->get_result();
        $row = $cRes ? $cRes->fetch_assoc() : ["c" => 0];
        $check->close();
        if ((int)($row['c'] ?? 0) === 0) {
            return ["status" => "error", "message" => "Employee not found in empuser."];
        }

        // Begin transaction for atomic move
        $conn->begin_transaction();

        // Insert snapshot into employee_archive using INSERT ... SELECT for schema-aligned copy
        $ins = $conn->prepare(
            "INSERT INTO employee_archive (
                NO, EmployeeID, EmployeeName, Birthdate, Age, LengthOfService, BloodType, TIN, SSS, PHIC, HDMF,
                PresentHomeAddress, PermanentHomeAddress, LastDayEmployed, DateTransferred, AreaOfAssignment,
                EmployeeEmail, Password, DateHired, profile_picture, Department, Position, Contact, base_salary, Role,
                Status, fingerprint_enrolled, fingerprint_date, created_at, updated_at, history, archived_at, archived_by
             )
             SELECT 
                NO, EmployeeID, EmployeeName, Birthdate, Age, LengthOfService, BloodType, TIN, SSS, PHIC, HDMF,
                PresentHomeAddress, PermanentHomeAddress, LastDayEmployed, DateTransferred, AreaOfAssignment,
                EmployeeEmail, Password, DateHired, profile_picture, Department, Position, Contact, base_salary, Role,
                Status, fingerprint_enrolled, fingerprint_date, created_at, updated_at, history, NOW(), NULL
             FROM empuser WHERE EmployeeID = ?"
        );
        $ins->bind_param("i", $employeeID);
        if (!$ins->execute()) {
            $msg = $conn->error ?: 'Insert to archive failed';
            $ins->close();
            $conn->rollback();
            return ["status" => "error", "message" => $msg];
        }
        $ins->close();

        // Delete from source table to avoid duplication (true transfer)
        $del = $conn->prepare("DELETE FROM empuser WHERE EmployeeID = ?");
        $del->bind_param("i", $employeeID);
        if (!$del->execute()) {
            $errNo = $del->errno ?: $conn->errno;
            $msg = $del->error ?: ($conn->error ?: 'Delete from empuser failed');
            $del->close();
            // If FK constraint prevents delete, fallback to soft-archive source and commit
            if ($errNo === 1451) { // ER_ROW_IS_REFERENCED_2
                $upd = $conn->prepare("UPDATE empuser SET Status = 'archived', LastDayEmployed = COALESCE(LastDayEmployed, CURDATE()) WHERE EmployeeID = ?");
                $upd->bind_param("i", $employeeID);
                $upd->execute();
                $upd->close();
                $conn->commit();
                return ["status" => "success", "message" => "Employee archived (snapshot saved). Source retained due to related attendance records."];
            }
            $conn->rollback();
            return ["status" => "error", "message" => $msg];
        }
        $del->close();

        // Commit transaction
        $conn->commit();

        return ["status" => "success", "message" => "Employee archived and removed from active records."];
    } catch (Exception $e) {
        if ($conn && $conn->errno === 0) {
            // Best-effort rollback if in transaction
            $conn->rollback();
        }
        return ["status" => "error", "message" => "Error during archiving: " . $e->getMessage()];
    }
}
// Handle Archive Employee Request
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['archive_employee'])) {
    $employeeID = intval($_POST['employee_id']);
    $archivedBy = $_SESSION['username'] ?? 'admin'; // Get the current user
    
    $result = archiveEmployee($employeeID, $archivedBy, $conn);
    
    if ($result['status'] === 'success') {
        $_SESSION['success'] = $result['message'];
        // Refresh the page to show updated employee list
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $_SESSION['error'] = $result['message'];
    }
}
// FUNCTION: Extract first and last words from employee name for K30 device
function extractFirstLastName($name) {
    error_log("=== FIRST/LAST NAME EXTRACTION START ===");
    error_log("Input: '{$name}'");
    
    // Step 1: Ensure proper UTF-8 encoding
    if (!mb_check_encoding($name, 'UTF-8')) {
        error_log("WARNING: Name is not valid UTF-8, converting...");
        $name = mb_convert_encoding($name, 'UTF-8', mb_detect_encoding($name));
    }
    
    // Step 2: Remove any leading/trailing whitespace
    $trimmed = trim($name);
    
    // Step 3: Split by spaces and filter out empty strings
    $words = array_filter(explode(' ', $trimmed), function($word) {
        return !empty(trim($word));
    });
    
    // Step 4: Get first and last words
    $firstWord = !empty($words) ? reset($words) : '';
    $lastWord = count($words) > 1 ? end($words) : '';
    
    // Step 5: Combine first and last name
    if (!empty($firstWord) && !empty($lastWord)) {
        $extracted = $firstWord . ' ' . $lastWord;
    } elseif (!empty($firstWord)) {
        $extracted = $firstWord;
    }
    
    if (empty($extracted)) {
        $extracted = 'Employee';
    }
    
    error_log("Words found: " . count($words));
    error_log("First word: '{$firstWord}'");
    error_log("Last word: '{$lastWord}'");
    error_log("Extracted: '{$extracted}'");
    error_log("=== FIRST/LAST NAME EXTRACTION END ===");
    
    return $extracted;
}

// ENHANCED FUNCTION: Sanitize name for K30 device with better character handling
function sanitizeForK30($name) {
    error_log("=== K30 Name Sanitization Start ===");
    error_log("Input: '{$name}' (Length: " . strlen($name) . " bytes)");
    
    // Step 1: Extract first and last name only
    $sanitized = extractFirstLastName($name);
    error_log("After first/last extraction: '{$sanitized}'");
    
    // Step 2: Ensure proper UTF-8 encoding
    if (!mb_check_encoding($sanitized, 'UTF-8')) {
        error_log("WARNING: Name is not valid UTF-8, converting...");
        $sanitized = mb_convert_encoding($sanitized, 'UTF-8', mb_detect_encoding($sanitized));
    }
    
    // Step 3: Remove any leading/trailing whitespace
    $sanitized = trim($sanitized);
    
    // Step 3: Convert accented characters to ASCII equivalents
    $replacements = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
        'ñ' => 'n', 'Ñ' => 'N',
        'ç' => 'c', 'Ç' => 'C',
        'ß' => 'ss',
        // Additional problematic characters that might cause issues
        'ş' => 's', 'Ş' => 'S',
        'ğ' => 'g', 'Ğ' => 'G',
        'ı' => 'i', 'İ' => 'I'
    ];
    
    foreach ($replacements as $search => $replace) {
        $sanitized = str_replace($search, $replace, $sanitized);
    }
    
    // Step 4: Use iconv for additional character conversion
    $sanitized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $sanitized);
    
    // Step 5: Keep only safe ASCII characters (letters, numbers, spaces, hyphens, periods)
    $sanitized = preg_replace('/[^A-Za-z0-9 \-\.]/', '', $sanitized);
    
    // Step 6: Replace multiple consecutive spaces with single space
    $sanitized = preg_replace('/\s+/', ' ', $sanitized);
    
    // Step 7: Trim again
    $sanitized = trim($sanitized);
    
    // Step 8: Limit to 23 characters (K30 device limitation)
    if (strlen($sanitized) > 23) {
        $sanitized = substr($sanitized, 0, 23);
        $sanitized = trim($sanitized);
    }
    
    // Step 9: If name became empty, use default
    if (empty($sanitized)) {
        $sanitized = 'Employee';
    }
    
    // Step 10: Final validation - ensure all characters are safe ASCII
    $finalSanitized = '';
    for ($i = 0; $i < strlen($sanitized); $i++) {
        $char = $sanitized[$i];
        $ascii = ord($char);
        
        // Only allow printable ASCII characters (32-126) and specifically safe ones
        if (($ascii >= 32 && $ascii <= 126) && 
            (($ascii >= 65 && $ascii <= 90) ||  // A-Z
             ($ascii >= 97 && $ascii <= 122) || // a-z
             ($ascii >= 48 && $ascii <= 57) ||  // 0-9
             $ascii == 32 ||  // space
             $ascii == 45 ||  // hyphen
             $ascii == 46)) { // period
            $finalSanitized .= $char;
            continue;
        }
        error_log("WARNING: Removed unsafe character '{$char}' (ASCII {$ascii})");
    }
    
    $sanitized = $finalSanitized;
    
    // Log detailed character analysis
    error_log("Output: '{$sanitized}'");
    error_log("Length: " . strlen($sanitized) . " characters");
    error_log("Character breakdown:");
    for ($i = 0; $i < strlen($sanitized); $i++) {
        $char = $sanitized[$i];
        $ascii = ord($char);
        error_log("  [{$i}] '{$char}' = ASCII {$ascii}");
    }
    
    // Final check - ensure no problematic characters remain
    $hasProblematicChars = false;
    for ($i = 0; $i < strlen($sanitized); $i++) {
        $char = $sanitized[$i];
        $ascii = ord($char);
        if ($ascii < 32 || $ascii > 126) {
            error_log("ERROR: Found problematic character '{$char}' (ASCII {$ascii})");
            $hasProblematicChars = true;
        }
    }
    
    if ($hasProblematicChars) {
        error_log("ERROR: Sanitized name still contains problematic characters!");
        $sanitized = 'Employee'; // Fallback to safe default
    }
    
    error_log("=== K30 Name Sanitization End ===");
    
    return $sanitized;
}

// TEST FUNCTION: Test name sanitization with specific problematic names
function testNameSanitization() {
    $testNames = [
        'Eugenio Berdandino Cautibar',
        'Lay Mark Tulipat Dabalus',
        'José María González',
        'François Müller',
        'José-Luis García',
        'María José Fernández',
        'Jean-Pierre Dubois',
        'Müller-Schmidt',
        'François-Xavier',
        'María del Carmen',
        'John Michael Smith',
        'Anna Maria',
        'José',
        'SingleName'
    ];
    
    error_log("=== TESTING NAME SANITIZATION ===");
    foreach ($testNames as $testName) {
        $extracted = extractFirstLastName($testName);
        $sanitized = sanitizeForK30($testName);
        error_log("Test: '{$testName}' -> Extracted: '{$extracted}' -> Final: '{$sanitized}' (Length: " . strlen($sanitized) . ")");
    }
    error_log("=== END NAME SANITIZATION TEST ===");
}

// Uncomment the line below to run the test
// testNameSanitization();

// Quick test to demonstrate the new functionality
if (isset($_GET['test_names']) && $_GET['test_names'] === '1') {
    echo "<h3>Name Extraction Test Results:</h3>";
    $testNames = [
        'Eugenio Berdandino Cautibar',
        'Lay Mark Tulipat Dabalus',
        'José María González',
        'John Michael Smith',
        'Anna Maria',
        'José',
        'SingleName'
    ];
    
    foreach ($testNames as $testName) {
        $extracted = extractFirstLastName($testName);
        $sanitized = sanitizeForK30($testName);
        echo "<p><strong>Original:</strong> '{$testName}'<br>";
        echo "<strong>First/Last:</strong> '{$extracted}'<br>";
        echo "<strong>Final (23 char limit):</strong> '{$sanitized}' (Length: " . strlen($sanitized) . ")</p><hr>";
    }
    exit;
}




if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['enroll_fingerprint'])) {
    error_log("========== FINGERPRINT ENROLLMENT STARTED ==========");
    error_log("POST Data: " . print_r($_POST, true));
    
    // Check if this is an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    $employeeId = intval($_POST['employee_id']);
    
    // CRITICAL FIX: Ensure proper UTF-8 handling
    $employeeName = trim($_POST['employee_name']);
    
    // Log the original name with encoding info
    error_log("Original employee name: '{$employeeName}'");
    error_log("Name encoding: " . mb_detect_encoding($employeeName, 'UTF-8, ISO-8859-1, ASCII', true));
    error_log("Name length: " . strlen($employeeName) . " bytes, " . mb_strlen($employeeName, 'UTF-8') . " characters");
    
    // Ensure UTF-8 encoding
    if (!mb_check_encoding($employeeName, 'UTF-8')) {
        error_log("WARNING: Name is not valid UTF-8, converting...");
        $employeeName = mb_convert_encoding($employeeName, 'UTF-8', mb_detect_encoding($employeeName));
    }
    
    // SANITIZE NAME FOR K30 DEVICE COMPATIBILITY
    // The K30 device only supports basic ASCII characters
    $sanitizedName = sanitizeForK30($employeeName);
    error_log("Sanitized name for K30: '{$sanitizedName}'");

    error_log("Processing enrollment - ID: {$employeeId}, Original: {$employeeName}, Sanitized: {$sanitizedName}");

    // Validation
    if ($employeeId <= 0) {
        error_log("ERROR: Invalid Employee ID: {$employeeId}");
        $_SESSION['error'] = 'Invalid Employee ID. Please select a valid employee.';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } elseif (empty($employeeName) || $employeeName === "0") {
        error_log("ERROR: Invalid Employee Name: {$employeeName}");
        $_SESSION['error'] = 'Invalid Employee Name. Please select a valid employee.';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Check employee in database
    $checkEmployeeStmt = $conn->prepare("SELECT EmployeeID, EmployeeName, fingerprint_enrolled, Status FROM empuser WHERE EmployeeID = ?");
    $checkEmployeeStmt->bind_param("i", $employeeId);
    $checkEmployeeStmt->execute();
    $result = $checkEmployeeStmt->get_result();
    
    if ($result->num_rows === 0) {
        error_log("ERROR: Employee ID {$employeeId} not found in database");
        $_SESSION['error'] = 'Employee not found in database.';
        $checkEmployeeStmt->close();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $employee = $result->fetch_assoc();
    $checkEmployeeStmt->close();
    
    // Check if active
    if (strtolower($employee['Status']) !== 'active') {
        error_log("ERROR: Employee is not active. Status: {$employee['Status']}");
        $_SESSION['error'] = "Cannot enroll inactive employee.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Check if already enrolled
    if ($employee['fingerprint_enrolled'] === 'yes') {
        error_log("WARNING: Employee already enrolled");
        $_SESSION['warning'] = "Employee already enrolled for fingerprint access.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Prepare enrollment data - USE SANITIZED NAME
    $enrollmentData = [
        "employeeId" => $employeeId,
        "employeeName" => $sanitizedName,  // Send sanitized name to C#
        "timestamp" => time(),
        "requestId" => uniqid('enroll_', true)
    ];

    // CRITICAL: Use JSON_UNESCAPED_UNICODE to preserve UTF-8 and ensure proper encoding
    $jsonData = json_encode($enrollmentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // Additional validation to ensure JSON is properly encoded
    if ($jsonData === false) {
        error_log("ERROR: JSON encoding failed: " . json_last_error_msg());
        $_SESSION['error'] = 'Failed to encode employee data for transmission.';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    error_log("JSON Data to send: " . $jsonData);
    error_log("JSON encoding: " . mb_detect_encoding($jsonData, 'UTF-8, ISO-8859-1, ASCII', true));
    error_log("JSON length: " . strlen($jsonData) . " bytes");
    
    // Get K30 service configuration
    $ports = getK30ServicePorts();
    $host = K30_SERVICE_HOST;
    
    error_log("Attempting to connect to K30 service at {$host}");
    
    $success = false;
    $lastError = '';
    
    foreach ($ports as $port) {
        $url = "http://{$host}:{$port}/enroll";
        
        error_log("Trying K30 service on port {$port}...");
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',  // Specify UTF-8
                'Content-Length: ' . strlen($jsonData),
                'Connection: Keep-Alive',
                'User-Agent: WTEI-PHP-Client/1.0',
                'Accept: application/json; charset=utf-8',
                'Accept-Charset: utf-8',
                'Accept-Encoding: identity',  // Disable compression to avoid encoding issues
                'Expect:'  // Empty Expect header
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => K30_SERVICE_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => K30_CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING => 'utf-8',  // Ensure UTF-8 encoding
            CURLOPT_VERBOSE => true
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        error_log("CURL Response - HTTP Code: {$httpCode}" . ($error ? ", Error: {$error}" : ""));
        error_log("CURL Info: " . print_r($curlInfo, true));
        
        if ($result !== false && $httpCode === 200) {
            $response = json_decode($result, true);
            error_log("C# service response: " . print_r($response, true));
            
            if ($response && isset($response['status']) && $response['status'] === "success") {
                $success = true;
                error_log("SUCCESS: C# service accepted enrollment request on port {$port}");
                
                if ($isAjax) {
                    // Return JSON response for AJAX
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => 'Employee data sent to K30 device successfully',
                        'employee_id' => $employeeId,
                        'employee_name' => $sanitizedName
                    ]);
                    exit;
                } else {
                    // Redirect with success for non-AJAX requests
                    $encodedName = urlencode($sanitizedName);
                    header("Location: " . $_SERVER['PHP_SELF'] . "?enrollment_success=true&employee_id={$employeeId}&employee_name={$encodedName}&show_modal=true");
                    exit;
                }
            } else {
                $lastError = $response['message'] ?? 'Unknown error from C# service';
                error_log("C# service returned non-success: {$lastError}");
            }
        } else {
            $lastError = $error ?: "HTTP {$httpCode}";
            error_log("Failed to connect to port {$port}: {$lastError}");
        }
    }
    
    // If we get here, all ports failed
    if (!$success) {
        error_log("ERROR: Could not connect to K30 service on any port");
        
        if ($isAjax) {
            // Return JSON error response for AJAX
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => "Cannot connect to K30 service. Please ensure the K30RealtimeSync service is running. Last error: {$lastError}"
            ]);
            exit;
        } else {
            // Redirect with error for non-AJAX requests
            $_SESSION['error'] = "Cannot connect to K30 service. Please ensure the K30RealtimeSync service is running.<br><br>Last error: {$lastError}";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}


// Handle Add Employee Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_employee'])) {
    // Get form data and sanitize
    $employeeID = $conn->real_escape_string(trim($_POST['employeeID']));
    $employeeName = $conn->real_escape_string(trim($_POST['employeeName']));
    $employeeEmail = $conn->real_escape_string(trim($_POST['employeeEmail']));
    $employeePassword = $_POST['employeePassword'];
    $dateHired = $conn->real_escape_string($_POST['dateHired']);
    $birthdate = $conn->real_escape_string($_POST['birthdate']);
    $age = $conn->real_escape_string(trim($_POST['age']));
    $lengthOfService = $conn->real_escape_string(trim($_POST['lengthOfService']));
    $bloodType = $conn->real_escape_string(trim($_POST['bloodType']));
    $tin = $conn->real_escape_string(trim($_POST['tin']));
    $sss = $conn->real_escape_string(trim($_POST['sss']));
    $phic = $conn->real_escape_string(trim($_POST['phic']));
    $hdmf = $conn->real_escape_string(trim($_POST['hdmf']));
    $presentHomeAddress = $conn->real_escape_string(trim($_POST['presentHomeAddress']));
    $permanentHomeAddress = $conn->real_escape_string(trim($_POST['permanentHomeAddress']));
    
    // Safety check: if permanent address is empty or "0", use present address
    if (empty($permanentHomeAddress) || $permanentHomeAddress === '0') {
        $permanentHomeAddress = $presentHomeAddress;
    }
    $lastDayEmployed = $conn->real_escape_string(trim($_POST['lastDayEmployed']));
    $dateTransferred = $conn->real_escape_string(trim($_POST['dateTransferred']));
    $areaOfAssignment = $conn->real_escape_string(trim($_POST['areaOfAssignment']));
    $department = $conn->real_escape_string($_POST['department']);
    $position = $conn->real_escape_string($_POST['position'] ?? 'Employee');
    $shift = $conn->real_escape_string($_POST['shift'] ?? '');
    $contact = $conn->real_escape_string(trim($_POST['contact'] ?? ''));
    $baseSalary = isset($_POST['baseSalary']) && trim($_POST['baseSalary']) !== '' ? floatval($_POST['baseSalary']) : 0.00;
    $riceAllowance = isset($_POST['riceAllowance']) && trim($_POST['riceAllowance']) !== '' ? intval($_POST['riceAllowance']) : 0;
    $medicalAllowance = isset($_POST['medicalAllowance']) && trim($_POST['medicalAllowance']) !== '' ? intval($_POST['medicalAllowance']) : 0;
    $laundryAllowance = isset($_POST['laundryAllowance']) && trim($_POST['laundryAllowance']) !== '' ? intval($_POST['laundryAllowance']) : 0;
    $leavePayCounts = isset($_POST['leavePayCounts']) && trim($_POST['leavePayCounts']) !== '' ? intval($_POST['leavePayCounts']) : 10;
    $status = $conn->real_escape_string($_POST['status']);
    $role = 'employee';

    // Validate EmployeeID
    $current_year_string = date('Y');
    $form_submission_error = false;

    if (empty($employeeID)) {
        $_SESSION['error'] = "EmployeeID is required.";
        $form_submission_error = true;
        echo "<script>
            setTimeout(function() {
                showNotification('EmployeeID is required.', 'error', 6000);
            }, 500);
        </script>";
    } elseif (strlen($employeeID) != 7) {
        $_SESSION['error'] = "EmployeeID must be 7 characters long (e.g., YYYYNNN).";
        $form_submission_error = true;
        echo "<script>
            setTimeout(function() {
                showNotification('EmployeeID must be 7 characters long (e.g., YYYYNNN).', 'error', 6000);
            }, 500);
        </script>";
    } elseif (substr($employeeID, 0, 4) != $current_year_string) {
        $_SESSION['error'] = "EmployeeID must start with the current year: " . $current_year_string . ".";
        $form_submission_error = true;
        echo "<script>
            setTimeout(function() {
                showNotification('EmployeeID must start with the current year: {$current_year_string}.', 'error', 6000);
            }, 500);
        </script>";
    } elseif (!ctype_digit(substr($employeeID, 0, 4)) || !ctype_digit(substr($employeeID, 4, 3))) {
        $_SESSION['error'] = "EmployeeID must be in YYYYNNN format (e.g., " . $current_year_string . "001).";
        $form_submission_error = true;
        echo "<script>
            setTimeout(function() {
                showNotification('EmployeeID must be in YYYYNNN format (e.g., {$current_year_string}001).', 'error', 6000);
            }, 500);
        </script>";
    } else {
        // Check for uniqueness
        $checkIDStmt = $conn->prepare("SELECT EmployeeID FROM empuser WHERE EmployeeID = ?");
        $checkIDStmt->bind_param("s", $employeeID);
        $checkIDStmt->execute();
        if ($checkIDStmt->get_result()->num_rows > 0) {
            $_SESSION['error'] = "EmployeeID '" . htmlspecialchars($employeeID) . "' already exists.";
            $form_submission_error = true;
            echo "<script>
                setTimeout(function() {
                    showNotification('EmployeeID {$employeeID} already exists. Please use a different ID.', 'error', 6000);
                }, 500);
            </script>";
        }
        $checkIDStmt->close();
    }

    // Basic Validation for other fields
    if (!$form_submission_error) {
        if (empty($employeeName) || empty($employeeEmail) || empty($employeePassword) || empty($department) || $baseSalary <= 0) {
            $_SESSION['error'] = "Please fill in all required fields.";
            $form_submission_error = true;
            echo "<script>
                setTimeout(function() {
                    showNotification('Please fill in all required fields.', 'error', 6000);
                }, 500);
            </script>";
        } elseif (!filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Invalid email format.";
            $form_submission_error = true;
            echo "<script>
                setTimeout(function() {
                    showNotification('Invalid email format. Please enter a valid email address.', 'error', 6000);
                }, 500);
            </script>";
        } else {
            if (empty($shift)) {
                $_SESSION['error'] = "Please select a shift schedule.";
                $form_submission_error = true;
                echo "<script>
                    setTimeout(function() {
                        showNotification('Please select a shift schedule.', 'error', 6000);
                    }, 500);
                </script>";
            }
            // Check for existing email
            $checkEmailStmt = $conn->prepare("SELECT EmployeeID FROM empuser WHERE EmployeeEmail = ?");
            $checkEmailStmt->bind_param("s", $employeeEmail);
            $checkEmailStmt->execute();
            if ($checkEmailStmt->get_result()->num_rows > 0) {
                $_SESSION['error'] = "Email '" . htmlspecialchars($employeeEmail) . "' already exists.";
                $form_submission_error = true;
                echo "<script>
                    setTimeout(function() {
                        showNotification('Email {$employeeEmail} already exists. Please use a different email.', 'error', 6000);
                    }, 500);
                </script>";
            }
            $checkEmailStmt->close();
        }
    }

    // Insert new employee if no errors
    if (!$form_submission_error) {
        // Hash the password
        $hashedPassword = password_hash($employeePassword, PASSWORD_DEFAULT);

        // Normalize optional dates to NULL when empty
        $lastDayEmployed = ($lastDayEmployed === '') ? null : $lastDayEmployed;
        $dateTransferred = ($dateTransferred === '') ? null : $dateTransferred;

        // Prepare insert statement with all new columns
$insertStmt = $conn->prepare("INSERT INTO empuser (
    EmployeeID, EmployeeName, EmployeeEmail, Password, DateHired, Birthdate, Age, 
    LengthOfService, BloodType, TIN, SSS, PHIC, HDMF, PresentHomeAddress, 
    PermanentHomeAddress, LastDayEmployed, DateTransferred, AreaOfAssignment, 
    Department, Position, Contact, base_salary, rice_allowance, medical_allowance, laundry_allowance, leave_pay_counts, Role, Status, Shift
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$insertStmt->bind_param("ssssssissssssssssssssdiiiisss", 
    $employeeID, $employeeName, $employeeEmail, $hashedPassword, $dateHired, $birthdate, 
    $age, $lengthOfService, $bloodType, $tin, $sss, $phic, $hdmf, $presentHomeAddress, 
    $permanentHomeAddress, $lastDayEmployed, $dateTransferred, $areaOfAssignment, 
    $department, $position, $contact, $baseSalary, $riceAllowance, $medicalAllowance, $laundryAllowance, $leavePayCounts, $role, $status, $shift
);

        if ($insertStmt->execute()) {
            // Auto-create absent attendance records based on hire date
            require_once 'auto_absent_attendance.php';
            $absent_result = AutoAbsentAttendance::createAbsentRecords($conn, $employeeID, $dateHired, $shift);
            
            $success_message = "Employee added successfully! Employee ID: " . htmlspecialchars($employeeID);
            if ($absent_result['success'] && $absent_result['records_created'] > 0) {
                $success_message .= " ({$absent_result['records_created']} absent records created)";
            }
            
            $_SESSION['success'] = $success_message;
            echo "<script>
                // Show success notification
                setTimeout(function() {
                    showNotification('Employee {$employeeName} added successfully! Employee ID: {$employeeID}', 'success', 8000);
                }, 500);
            </script>";
        } else {
            $_SESSION['error'] = "Error adding employee: " . $insertStmt->error;
            echo "<script>
                // Show error notification
                setTimeout(function() {
                    showNotification('Error adding employee: {$insertStmt->error}', 'error', 8000);
                }, 500);
            </script>";
        }
        $insertStmt->close();
    }
}

// Get all NON-ARCHIVED employees with updated column names
$employees = [];
$query = "SELECT EmployeeID, EmployeeName, Birthdate, Age, LengthOfService, BloodType, TIN, 
                 SSS, PHIC, HDMF, PresentHomeAddress, PermanentHomeAddress,
                 LastDayEmployed, DateTransferred, AreaOfAssignment, EmployeeEmail, Password, 
                 DateHired, profile_picture, Department, Position, Shift, Contact, base_salary, 
                 rice_allowance, medical_allowance, laundry_allowance, leave_pay_counts, Role, 
                 Status, fingerprint_enrolled, fingerprint_date, created_at, updated_at, history
          FROM empuser
          WHERE Status IN ('active','inactive')
          ORDER BY EmployeeName ASC";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Group employees by department for the department view - ONLY non-archived employees
$employeesByDepartment = [];
foreach ($employees as $employee) {
    $status = strtolower($employee['Status'] ?? '');
    if ($status === 'active') {
        $dept = $employee['Department'] ?? 'Other';
        if (!isset($employeesByDepartment[$dept])) {
            $employeesByDepartment[$dept] = [];
        }
        $employeesByDepartment[$dept][] = $employee;
    }
}

// Pad departments with less than 4 employees with empty entries
foreach ($employeesByDepartment as $dept => $deptEmployees) {
    if (count($deptEmployees) < 4) {
        $emptyCount = 4 - count($deptEmployees);
        for ($i = 0; $i < $emptyCount; $i++) {
            $employeesByDepartment[$dept][] = [
                'EmployeeName' => ' Vacant Position',
                'Position' => ''
            ];
        }
    }
}

// Get statistics for dashboard cards - count only non-archived employees
$totalEmployees = count($employees);

// Analytics for overall employees
// Active employees count
$activeCount = 0;
$activeRes = $conn->query("SELECT COUNT(*) AS c FROM empuser WHERE Status = 'active'");
if ($activeRes) { $activeCount = (int)($activeRes->fetch_assoc()['c'] ?? 0); }

// Number of departments with at least one active employee
$deptCount = 0;
$deptRes = $conn->query("SELECT COUNT(DISTINCT Department) AS c FROM empuser WHERE Status = 'active' AND Department IS NOT NULL AND Department <> ''");
if ($deptRes) { $deptCount = (int)($deptRes->fetch_assoc()['c'] ?? 0); }

$allDepartments = [
    'Treasury', 'HR', 'Sales', 'Tax', 'Admin', 
    'Finance', 'Accounting', 'Marketing', 'CMCD', 'Security'
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Management - WTEI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    body {
        background-color: #F9F7F7;
        display: flex;
        min-height: 100vh;
    }
    
    .sidebar {
        width: 280px;
        background-color: #112D4E;
        padding: 20px 0;
        box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
        display: flex;
        flex-direction: column;
        color: white;
        position: fixed;
        height: 100vh;
        transition: all 0.3s ease;
    }
    
    .logo {
        font-weight: bold;
        font-size: 32px;
        padding: 25px;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 20px;
        color: #DBE2EF;
        letter-spacing: 2px;
    }
    
    .menu {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        padding: 0 15px;
    }
    
    .menu-item {
        display: flex;
        align-items: center;
        padding: 15px 25px;
        color: rgba(255, 255, 255, 0.8);
        text-decoration: none;
        transition: all 0.3s;
        border-radius: 12px;
        margin-bottom: 5px;
    }
    
    .menu-item:hover, .menu-item.active {
        background-color: #3F72AF;
        color: #DBE2EF;
        transform: translateX(5px);
    }
    
    .menu-item i {
        margin-right: 15px;
        width: 20px;
        text-align: center;
        font-size: 18px;
    }
    
    .logout-btn {
        background-color: #3F72AF;
        color: white;
        border: none;
        padding: 15px;
        margin: 20px;
        border-radius: 12px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: all 0.3s;
    }
    
    .logout-btn:hover {
        background-color: #DBE2EF;
        color: #112D4E;
        transform: translateY(-2px);
    }
    
    .logout-btn i {
        margin-right: 10px;
    }
    
    .main-content {
        flex-grow: 1;
        padding: 30px;
        margin-left: 280px;
        overflow-y: auto;
        transition: all 0.3s ease;
    }
    
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        background-color: white;
        padding: 25px;
        border-radius: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        border-bottom: 2px solid #DBE2EF;
    }
    
    .header h1 {
        font-size: 28px;
        color: #112D4E;
        font-weight: 600;
    }
    
    .header-actions {
        display: flex;
        gap: 15px;
    }
    
    .search-box {
        position: relative;
        width: 300px;
    }
    
    .search-box input {
        width: 100%;
        padding: 12px 40px 12px 20px;
        border: 2px solid #eee;
        border-radius: 12px;
        font-size: 14px;
        background-color: #fff;
        transition: all 0.3s;
    }
    
    .search-box input:focus {
        outline: none;
        border-color: #3F72AF;
        box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
    }
    
    .search-box i {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #112D4E;
    }
    
    .employee-table {
        background-color: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }
    
    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-top: 20px;
    }
    
    table th, table td {
        padding: 16px;
        text-align: left;
        border-bottom: 1px solid #eee;
    }
    
    table th {
        color: #19456B;
        font-weight: 600;
        font-size: 14px;
        background-color: #F8F1F1;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    table th:first-child {
        border-top-left-radius: 12px;
    }

    table th:last-child {
        border-top-right-radius: 12px;
    }
    
    table td {
        color: #333;
    }

    table tr:hover {
        background-color: #F8F1F1;
        cursor: pointer;
    }
    
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    
    .action-btn {
        padding: 8px 16px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    
    .view-btn {
        background-color: #11698E;
        color: white;
    }
    
    .edit-btn {
        background-color: #16C79A;
        color: white;
    }
    
    .delete-btn {
        background-color: #ff4757;
        color: white;
    }

    .fingerprint-btn {
        background-color: #FF6B35;
        color: white;
    }

    .action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }
    
    .action-btn i {
        margin-right: 5px;
        font-size: 14px;
    }

    /* Dashboard Cards Styles */
    .dashboard-cards {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 25px;
        margin-bottom: 30px;
    }

    .card {
        background-color: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
    }

    .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
    }

    .card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(to right, #11698E, #16C79A);
    }

    .card-content {
        position: relative;
        z-index: 1;
    }

    .card-title {
        font-size: 14px;
        color: #19456B;
        margin-bottom: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .card-value {
        font-size: 28px;
        font-weight: 600;
        color: #11698E;
        margin-bottom: 15px;
    }

    .card-icon {
        position: absolute;
        right: 20px;
        bottom: 20px;
        font-size: 48px;
        color: rgba(22, 199, 154, 0.1);
    }

    .pagination {
        display: flex;
        justify-content: center;
        gap: 10px;
        margin-top: 30px;
    }
    
    .pagination button {
        padding: 10px 20px;
        border: none;
        background-color: white;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        color: #19456B;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
    }
    
    .pagination button:hover {
        background-color: #F8F1F1;
        transform: translateY(-2px);
    }
    
    .pagination button.active {
        background-color: #16C79A;
        color: white;
    }

    /* Alert Styles */
    .alert {
        padding: 16px;
        margin-bottom: 20px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 500;
        display: flex;
        align-items: center;
        animation: slideIn 0.3s ease;
    }

    @keyframes slideIn {
        from {
            transform: translateY(-10px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .alert-success {
        background-color: rgba(22, 199, 154, 0.1);
        color: #16C79A;
        border: 1px solid rgba(22, 199, 154, 0.2);
    }

    .alert-error {
        background-color: rgba(255, 71, 87, 0.1);
        color: #ff4757;
        border: 1px solid rgba(255, 71, 87, 0.2);
    }

    .alert i {
        margin-right: 10px;
        font-size: 18px;
    }

    .btn-primary {
        background-color: #3F72AF;
        color: white;
        border: none;
        padding: 10px 18px;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    
    .btn-primary:hover {
        background-color: #112D4E;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    }

    .btn-secondary {
        background-color: #DBE2EF;
        color: #112D4E;
        border: none;
        padding: 10px 18px;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-secondary:hover {
        background-color: #3F72AF;
        color: white;
    }
    
    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.45);
        justify-content: center;
        align-items: center;
        z-index: 1000;
        animation: fadeIn 0.2s ease;
        backdrop-filter: blur(2px);
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }

    .modal-content {
        background: white;
        padding: 30px;
        border-radius: 16px;
        width: 30%;
        position: relative;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        animation: slideUp 0.3s ease;
    }

    @keyframes slideUp {
        from {
            transform: translateY(20px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .close-modal {
        cursor: pointer;
        position: absolute;
        top: 12px;
        right: 15px;
        font-size: 28px;
        color: #ffffff;
        background: rgba(255, 255, 255, 0.1);
        border: none;
        transition: all 0.2s;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
    }

    .close-modal:hover {
        background: rgba(255, 255, 255, 0.2);
        color: #ffffff;
        transform: scale(1.1);
    }

    .modal-header {
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid #DBE2EF;
    }

    .modal-header h2 {
        font-size: 24px;
        color: #112D4E;
        font-weight: 600;
    }

    /* Add Employee Modal Styles */
    #addEmployeeModal .modal-content {
        width: 88%;
        max-width: 1100px;
        height: auto;
        max-height: 90vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        padding: 0;
        border: 1px solid #DBE2EF;
        box-shadow: 0 16px 48px rgba(17,45,78,0.15);
        border-radius: 18px;
    }

    #addEmployeeModal .modal-header {
        position: sticky;
        top: 0;
        z-index: 5;
        background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
        border-bottom: 1px solid #DBE2EF;
        padding: 22px 28px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    #addEmployeeModal .modal-header h2 {
        margin: 0;
        font-size: 22px;
        color: #112D4E;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    #addEmployeeModal .modal-body {
        padding: 24px 28px 8px;
        overflow-y: auto;
    }

    #addEmployeeModal form {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 18px 22px;
    }

    #addEmployeeModal .form-group {
        margin-bottom: 15px;
    }

    #addEmployeeModal .form-group label {
        display: block;
        font-weight: 600;
        color: #112D4E;
        font-size: 14px;
        margin-bottom: 8px;
    }

    #addEmployeeModal .form-control {
        border: 1.5px solid #DBE2EF;
        border-radius: 10px;
        padding: 12px 14px;
        width: 100%;
        box-sizing: border-box;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.2s ease;
        background-color: #fff;
        font-size: 14px;
        color: #2D3748;
    }

    #addEmployeeModal .form-control::placeholder { color: #9aa6b2; }

    #addEmployeeModal .form-control:focus {
        border-color: #3F72AF;
        box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.18);
        outline: none;
        background-color: #FFFFFF;
    }

    #addEmployeeModal textarea.form-control {
        min-height: 80px;
        resize: vertical;
    }

    #addEmployeeModal select.form-control {
        appearance: none;
        background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 10px center;
        background-size: 16px;
    }

    /* Section headers inside modal */
    #addEmployeeModal .form-section-header {
        grid-column: span 2;
        font-size: 15px;
        font-weight: 700;
        color: #112D4E;
        margin: 6px 0 10px;
        padding: 10px 0 8px;
        border-bottom: 2px solid #DBE2EF;
        letter-spacing: 0.3px;
        position: relative;
    }
    #addEmployeeModal .form-section-header::after {
        content: '';
        position: absolute;
        left: 0;
        bottom: -2px;
        width: 80px;
        height: 2px;
        background: linear-gradient(90deg, #3F72AF 0%, #112D4E 100%);
    }

    #addEmployeeModal .form-group-span-2 { grid-column: span 2; }

    #addEmployeeModal .form-actions {
        grid-column: span 2;
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        position: sticky;
        bottom: 0;
        background: linear-gradient(180deg, rgba(255,255,255,0.9) 0%, rgba(255,255,255,1) 100%);
        padding: 14px 0 6px;
        margin-top: 8px;
        border-top: 1px solid #DBE2EF;
        backdrop-filter: blur(2px);
    }

    /* Helper hint text */
    #addEmployeeModal .form-text { color: #6b7a8a; font-size: 12px; }
    #addEmployeeModal .form-text.text-muted { color: #8a99aa; }

    /* Buttons in modal */
    #addEmployeeModal .btn-primary { padding: 10px 18px; border-radius: 10px; }
    #addEmployeeModal .btn-secondary { padding: 10px 18px; border-radius: 10px; }

    /* Polished form group focus state */
    #addEmployeeModal .form-group { position: relative; transition: box-shadow 0.15s ease, background-color 0.2s ease, border-color 0.15s ease; border-radius: 12px; }
    #addEmployeeModal .form-group:focus-within { background: #FBFDFF; box-shadow: 0 6px 18px rgba(17,45,78,0.06); }

    /* Subtle validation cues */
    #addEmployeeModal .form-control:focus:valid { border-color: #16C79A; box-shadow: 0 0 0 3px rgba(22, 199, 154, 0.18); }
    #addEmployeeModal .form-control:focus:invalid { border-color: #FF6B35; box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.15); }

    /* Improve autofill button to match theme */
    #addEmployeeModal .btn-autofill { border-radius: 10px; padding: 10px 14px; border: 1px solid #C9D7EA; }
    #addEmployeeModal .btn-autofill:hover { transform: translateY(-1px); box-shadow: 0 6px 14px rgba(17,45,78,0.12); }

    /* Modal header top accent */
    #addEmployeeModal .modal-content::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, #3F72AF 0%, #112D4E 100%);
    }

    /* Smooth scroll and slim scrollbar for modal body */
    #addEmployeeModal .modal-body { scroll-behavior: smooth; }
    #addEmployeeModal .modal-body::-webkit-scrollbar { width: 10px; }
    #addEmployeeModal .modal-body::-webkit-scrollbar-thumb { background: #C9D7EA; border-radius: 8px; }
    #addEmployeeModal .modal-body::-webkit-scrollbar-thumb:hover { background: #B4C6E2; }

    /* Password Requirements Styles */
    .password-container {
        position: relative;
    }

    .password-toggle {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #112D4E;
    }

    .password-requirements {
        margin-top: 8px;
        padding: 10px;
        background-color: #F9F7F7;
        border-radius: 8px;
    }

    .requirement {
        display: flex;
        align-items: center;
        margin-bottom: 5px;
        font-size: 12px;
    }

    .requirement i {
        margin-right: 8px;
        width: 16px;
        text-align: center;
    }

    .requirement.valid {
        color: #16C79A;
    }

    .requirement.invalid {
        color: #666;
    }

    .password-match {
        margin-top: 5px;
        font-size: 12px;
        padding: 5px;
        border-radius: 4px;
    }

    .password-match.valid {
        color: #16C79A;
        background-color: rgba(22, 199, 154, 0.1);
    }

    .password-match.invalid {
        color: #ff4757;
        background-color: rgba(255, 71, 87, 0.1);
    }

    /* Auto-fill Button Styles */
    .btn-autofill {
        padding: 10px 15px;
        background-color: #DBE2EF;
        color: #112D4E;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 12px;
        white-space: nowrap;
    }

    .btn-autofill:hover {
        background-color: #3F72AF;
        color: white;
    }

    /* Section Headers */
    .form-section-header {
        grid-column: span 2;
        font-size: 16px;
        font-weight: 600;
        color: #112D4E;
        margin: 15px 0 10px;
        padding-bottom: 8px;
        border-bottom: 2px solid #DBE2EF;
    }

    /* View Toggle Styles */
    .view-toggle {
        display: flex;
        gap: 10px;
    }
    
    .view-btn {
        padding: 10px 20px;
        border: 2px solid #3F72AF;
        background-color: white;
        color: #3F72AF;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .view-btn.active {
        background-color: #3F72AF;
        color: white;
    }
    
    .view-btn:hover {
        background-color: #3F72AF;
        color: white;
        transform: translateY(-2px);
    }

    /* Department View Styles */
    .departments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}
    
.department-card {
    background: linear-gradient(135deg, #FFFFFF 0%, #F8F9FA 100%);
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(63, 114, 175, 0.1);
}
.department-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 30px rgba(63, 114, 175, 0.15);
    border-color: rgba(63, 114, 175, 0.2);
}
    
.department-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: linear-gradient(to right, #3F72AF, #112D4E);
}
    
.department-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(219, 226, 239, 0.5);
}
    
.department-name {
    font-size: 20px;
    font-weight: 600;
    color: #112D4E;
    position: relative;
    padding-left: 30px;
}
.department-name::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    background-color: #3F72AF;
    border-radius: 6px;
    opacity: 0.7;
}
    
.employee-count {
    background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
    color: white;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    box-shadow: 0 4px 8px rgba(63, 114, 175, 0.3);
}
    
    .department-icon {
        font-size: 48px;
        color: rgba(17, 105, 142, 0.1);
        position: absolute;
        bottom: 20px;
        right: 20px;
    }
    
    .department-preview {
    margin-top: 15px;
    min-height: 160px;
}
.preview-employee {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    padding: 10px;
    background-color: rgba(219, 226, 239, 0.3);
    border-radius: 8px;
    transition: all 0.2s ease;
}
.preview-employee:hover {
    background-color: rgba(63, 114, 175, 0.1);
    transform: translateX(5px);
}
    
.preview-employee i {
    margin-right: 10px;
    color: #3F72AF;
    width: 18px;
    text-align: center;
    font-size: 14px;
}
.preview-employee-name {
    font-weight: 500;
    color: #19456B;
    font-size: 14px;
}
.preview-employee-position {
    font-size: 12px;
    color: #6c757d;
    margin-top: 2px;
}
.empty-slot {
    height: 38px;
    background-color: rgba(219, 226, 239, 0.2);
    border-radius: 8px;
    margin-bottom: 10px;
    border: 1px dashed rgba(63, 114, 175, 0.3);
}

.more-employees {
    color: #3F72AF;
    font-size: 13px;
    font-weight: 500;
    text-align: center;
    margin-top: 10px;
    padding: 8px;
    background-color: rgba(63, 114, 175, 0.1);
    border-radius: 8px;
}

.department-icon {
    position: absolute;
    bottom: 15px;
    right: 15px;
    font-size: 40px;
    color: rgba(63, 114, 175, 0.1);
    z-index: 0;
}
    
    
    /* Department Detail View */
    .department-detail {
        display: none;
        background-color: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }
    
    .department-detail.active {
        display: block;
    }
    
    .detail-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 2px solid #DBE2EF;
    }
    
    .detail-header h2 {
        color: #112D4E;
        font-size: 24px;
    }
    
    .back-btn {
        background-color: #DBE2EF;
        color: #112D4E;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .back-btn:hover {
        background-color: #3F72AF;
        color: white;
    }

    /* Employee Info Modal Styles - Enhanced & Elegant */
    #employeeModal .modal-content {
        width: 90%;
        max-width: 1400px;
        min-width: 900px;
        max-height: 92vh;
        padding: 0;
        border-radius: 20px;
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        position: relative;
        overflow: hidden;
        border: 1px solid #e0e7ef;
    }

    .employee-profile-header {
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        padding: 25px 40px;
        display: flex;
        align-items: center;
        position: relative;
        overflow: hidden;
    }

    .employee-profile-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 300px;
        height: 300px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 50%;
    }

    .employee-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        font-weight: bold;
        margin-right: 25px;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        border: 3px solid rgba(255, 255, 255, 0.3);
        position: relative;
        z-index: 1;
    }

    .employee-header-info {
        flex-grow: 1;
        position: relative;
        z-index: 1;
    }

    .employee-name {
        font-size: 24px;
        font-weight: 700;
        color: #ffffff !important;
        margin-bottom: 6px;
        letter-spacing: 0.5px;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    .employee-position {
        font-size: 16px;
        color: #ffffff !important;
        margin-bottom: 8px;
        font-weight: 500;
        opacity: 0.95;
    }

    .employee-department {
        font-size: 13px;
        color: #ffffff !important;
        background-color: rgba(255, 255, 255, 0.2);
        padding: 5px 12px;
        border-radius: 20px;
        display: inline-block;
        font-weight: 600;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .employee-info-body {
        padding: 40px 50px;
        overflow-y: auto;
        max-height: calc(92vh - 180px);
    }

    .employee-info-body::-webkit-scrollbar {
        width: 8px;
    }

    .employee-info-body::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .employee-info-body::-webkit-scrollbar-thumb {
        background: #3F72AF;
        border-radius: 10px;
    }

    .employee-info-body::-webkit-scrollbar-thumb:hover {
        background: #112D4E;
    }

    .employee-info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 30px;
        margin-bottom: 30px;
    }

    .info-section {
        background: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
        border: 1px solid #e8eef5;
        transition: all 0.3s ease;
    }

    .info-section:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .section-title {
        font-size: 18px;
        font-weight: 700;
        color: #112D4E;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 3px solid #3F72AF;
        display: flex;
        align-items: center;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .section-title i {
        margin-right: 12px;
        color: #3F72AF;
        font-size: 20px;
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(63, 114, 175, 0.1);
        border-radius: 8px;
    }

    .info-item {
        display: flex;
        margin-bottom: 16px;
        align-items: flex-start;
        padding: 8px 0;
        border-bottom: 1px solid #f0f4f8;
    }

    .info-item:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }

    .info-label {
        flex: 0 0 160px;
        font-weight: 600;
        color: #5a6c7d;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .info-value {
        flex: 1;
        color: #2d3748;
        font-size: 15px;
        word-break: break-word;
        line-height: 1.6;
        font-weight: 500;
    }

    .info-value.salary {
        color: #16C79A;
        font-weight: 700;
        font-size: 20px;
        font-family: 'Courier New', monospace;
    }
    
    .night-shift-badge {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        color: #ffffff;
        padding: 8px 12px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: 0 4px 12px rgba(26, 26, 46, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .night-shift-badge i {
        color: #ffd700;
        font-size: 14px;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-badge::before {
        content: '';
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-right: 8px;
    }

    .status-active {
        background-color: rgba(22, 199, 154, 0.15);
        color: #16C79A;
        border: 2px solid rgba(22, 199, 154, 0.3);
    }

    .status-active::before {
        background-color: #16C79A;
        box-shadow: 0 0 8px rgba(22, 199, 154, 0.6);
    }

    .status-inactive {
        background-color: rgba(255, 71, 87, 0.15);
        color: #ff4757;
        border: 2px solid rgba(255, 71, 87, 0.3);
    }

    .status-inactive::before {
        background-color: #ff4757;
    }

    .history-section {
        grid-column: span 2;
        background: white;
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
        border: 1px solid #e8eef5;
    }

    .history-content {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 12px;
        padding: 20px;
        max-height: 150px;
        overflow-y: auto;
        font-size: 14px;
        line-height: 1.8;
        border: 2px solid #dee2e6;
        color: #495057;
    }

    .history-content::-webkit-scrollbar {
        width: 6px;
    }

    .history-content::-webkit-scrollbar-track {
        background: #e9ecef;
        border-radius: 10px;
    }

    .history-content::-webkit-scrollbar-thumb {
        background: #6c757d;
        border-radius: 10px;
    }
    /*Finger-print */
    /* Enhanced Fingerprint Enrollment Modal */
.fingerprint-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.95) 0%, rgba(30, 41, 59, 0.98) 100%);
    backdrop-filter: blur(20px) saturate(180%);
    justify-content: center;
    align-items: center;
    z-index: 10001;
    animation: fadeIn 0.5s ease;
}

.fingerprint-modal-content {
    background: linear-gradient(145deg, #0F172A 0%, #1E293B 50%, #334155 100%);
    border-radius: 32px;
    width: 1200px;
    height: 700px;
    max-width: 95vw;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 
        0 32px 100px rgba(0, 0, 0, 0.6),
        0 0 0 1px rgba(59, 130, 246, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.1);
    position: relative;
    animation: slideUpBounce 0.7s cubic-bezier(0.34, 1.56, 0.64, 1);
    border: 1px solid rgba(59, 130, 246, 0.3);
    display: flex;
    flex-direction: column;
}

.fingerprint-modal-content::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(147, 51, 234, 0.05) 100%);
    border-radius: 32px;
    pointer-events: none;
}

.fingerprint-header {
    background: linear-gradient(135deg, #1E40AF 0%, #3B82F6 50%, #1E3A8A 100%);
    padding: 30px 40px;
    color: white;
    position: relative;
    border-bottom: 1px solid rgba(59, 130, 246, 0.3);
    overflow: hidden;
}

.fingerprint-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.05) 100%);
    pointer-events: none;
}

.fingerprint-header::after {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.1) 0%, transparent 70%);
    animation: rotate 20s linear infinite;
    pointer-events: none;
}

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.fingerprint-header h2 {
    font-size: 1.8rem;
    font-weight: 800;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 16px;
    text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
    position: relative;
    z-index: 2;
    letter-spacing: -0.025em;
}

.fingerprint-header h2 i {
    font-size: 1.5rem;
    color: #60A5FA;
    filter: drop-shadow(0 0 8px rgba(96, 165, 250, 0.5));
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* Search Container Styles */
.search-container {
    margin-bottom: 20px;
}

.search-container label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #374151;
    font-size: 0.9rem;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-input-wrapper .search-icon {
    position: absolute;
    left: 12px;
    color: #6B7280;
    font-size: 0.9rem;
    z-index: 2;
}

.search-input-wrapper input {
    width: 100%;
    padding: 12px 16px 12px 40px;
    border: 2px solid #E5E7EB;
    border-radius: 12px;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    background: #FFFFFF;
}

.search-input-wrapper input:focus {
    outline: none;
    border-color: #3F72AF;
    box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
}

.search-input-wrapper input:disabled {
    background-color: #f9fafb;
    cursor: not-allowed;
}

/* Loading animation for search */
@keyframes searchPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.search-loading {
    animation: searchPulse 1.5s ease-in-out infinite;
}

.search-loading-indicator {
    position: absolute;
    right: 40px;
    top: 50%;
    transform: translateY(-50%);
    color: #3F72AF;
    font-size: 0.9rem;
}

.search-loading-indicator i {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.clear-search {
    position: absolute;
    right: 8px;
    background: #F3F4F6;
    border: none;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #6B7280;
    transition: all 0.2s ease;
}

.clear-search:hover {
    background: #E5E7EB;
    color: #374151;
}

/* Employee Dropdown Container */
.employee-dropdown-container {
    margin-bottom: 16px;
}

.employee-dropdown-container label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #374151;
    font-size: 0.9rem;
}

.employee-dropdown {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #E5E7EB;
    border-radius: 12px;
    font-size: 0.9rem;
    background: #FFFFFF;
    transition: all 0.3s ease;
}

.employee-dropdown:focus {
    outline: none;
    border-color: #3F72AF;
    box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
}

/* Enrollment Notification Styles */
.enrollment-notification {
    margin-top: 20px;
    padding: 20px;
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    border: 2px solid #10B981;
    border-radius: 16px;
    animation: slideInNotification 0.5s ease;
    box-shadow: 0 8px 32px rgba(16, 185, 129, 0.2);
    position: relative;
    overflow: hidden;
}

.notification-timer {
    margin-top: 10px;
    padding: 8px 12px;
    background: rgba(16, 185, 129, 0.1);
    border-radius: 8px;
    font-size: 14px;
    color: #065F46;
    font-weight: 500;
    text-align: center;
    border: 1px solid rgba(16, 185, 129, 0.2);
}

#countdownTimer {
    font-weight: bold;
    color: #10B981;
    font-size: 16px;
}

.employee-count-badge {
    font-size: 12px;
    color: #94A3B8;
    font-weight: normal;
    background: rgba(148, 163, 184, 0.1);
    padding: 2px 8px;
    border-radius: 12px;
    margin-left: 8px;
}

.enrollment-notification::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #10B981, #059669, #10B981);
    animation: shimmer 2s infinite;
}

.notification-content {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.notification-icon {
    color: #10B981;
    font-size: 1.2rem;
    margin-top: 2px;
}

.notification-details h4 {
    margin: 0 0 8px 0;
    color: #065F46;
    font-size: 1rem;
    font-weight: 600;
}

.notification-details p {
    margin: 4px 0;
    color: #047857;
    font-size: 0.85rem;
}

.notification-message {
    font-style: italic;
    margin-top: 8px !important;
}

@keyframes slideInNotification {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes shimmer {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(100%);
    }
}

/* Two-panel layout styles */
.fingerprint-body {
    display: flex;
    flex: 1;
    min-height: 0;
}

.fingerprint-left-panel {
    flex: 1;
    padding: 40px;
    border-right: 1px solid rgba(59, 130, 246, 0.2);
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
    position: relative;
    overflow: hidden;
}

.fingerprint-left-panel::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: radial-gradient(circle at 30% 20%, rgba(59, 130, 246, 0.1) 0%, transparent 50%);
    pointer-events: none;
}

.fingerprint-right-panel {
    flex: 1;
    padding: 30px;
    background: linear-gradient(135deg, #1E293B 0%, #334155 100%);
    display: flex;
    flex-direction: column;
    max-height: 70vh;
    overflow: hidden;
    position: relative;
}

.fingerprint-right-panel::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(147, 51, 234, 0.05) 100%);
    pointer-events: none;
}

.employee-list-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.employee-list-header {
    margin-bottom: 20px;
}

.employee-list-header h3 {
    margin: 0 0 15px 0;
    color: #F1F5F9;
    font-size: 1.3rem;
    font-weight: 700;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    position: relative;
    z-index: 2;
}

.employee-list {
    flex: 1;
    overflow-y: auto;
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 16px;
    background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
    backdrop-filter: blur(10px);
    box-shadow: 
        inset 0 1px 0 rgba(255, 255, 255, 0.1),
        0 8px 32px rgba(0, 0, 0, 0.3);
    position: relative;
    z-index: 2;
    contain: layout style paint;
    will-change: contents;
}

.employee-list-item {
    padding: 16px 20px;
    border-bottom: 1px solid rgba(59, 130, 246, 0.1);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    overflow: hidden;
}

.employee-list-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(147, 51, 234, 0.05) 100%);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.employee-list-item:hover {
    background: rgba(59, 130, 246, 0.1);
    border-left: 4px solid #3B82F6;
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
}

.employee-list-item:hover::before {
    opacity: 1;
}

.employee-list-item:last-child {
    border-bottom: none;
}

.employee-list-item.selected {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.2) 0%, rgba(147, 51, 234, 0.1) 100%);
    border-left: 4px solid #3B82F6;
    color: #60A5FA;
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
    transform: translateX(4px);
}

.employee-list-item.selected::before {
    opacity: 1;
}

.employee-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3B82F6 0%, #1E40AF 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1rem;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    border: 2px solid rgba(59, 130, 246, 0.3);
    position: relative;
    z-index: 2;
}

.employee-avatar::before {
    content: '';
    position: absolute;
    top: -2px;
    left: -2px;
    right: -2px;
    bottom: -2px;
    background: linear-gradient(135deg, #3B82F6, #8B5CF6);
    border-radius: 50%;
    z-index: -1;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.employee-list-item:hover .employee-avatar::before {
    opacity: 1;
}

.employee-info {
    flex: 1;
    position: relative;
    z-index: 2;
}

.employee-name {
    font-weight: 700;
    color: #F1F5F9;
    margin-bottom: 4px;
    font-size: 1rem;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.employee-id {
    font-size: 0.85rem;
    color: #10B981;
    font-weight: 600;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.employee-status {
    font-size: 0.75rem;
    color: #10B981;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.2) 0%, rgba(16, 185, 129, 0.1) 100%);
    border: 1px solid rgba(16, 185, 129, 0.3);
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Override colors for fingerprint modal to make text white for better visibility */
.fingerprint-modal .employee-id {
    color: #FFFFFF !important;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
}

.fingerprint-modal .employee-count-badge {
    color: #FFFFFF !important;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
}

.fingerprint-modal .text-muted {
    color: #FFFFFF !important;
}

.fingerprint-modal .text-muted strong {
    color: #FFFFFF !important;
}

/* Search in right panel */
.right-panel-search {
    margin-bottom: 20px;
}

.right-panel-search .search-input-wrapper {
    position: relative;
}

.right-panel-search .search-input-wrapper input {
    width: 100%;
    padding: 14px 20px 14px 48px;
    border: 2px solid rgba(59, 130, 246, 0.3);
    border-radius: 16px;
    font-size: 0.95rem;
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
    background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
    color: #F1F5F9;
    backdrop-filter: blur(10px);
    box-shadow: 
        inset 0 1px 0 rgba(255, 255, 255, 0.1),
        0 4px 12px rgba(0, 0, 0, 0.2);
    position: relative;
    z-index: 2;
    will-change: border-color, box-shadow;
}

.right-panel-search .search-input-wrapper input::placeholder {
    color: #94A3B8;
    font-weight: 500;
}

.right-panel-search .search-input-wrapper input:focus {
    outline: none;
    border-color: #3B82F6;
    box-shadow: 
        0 0 0 4px rgba(59, 130, 246, 0.2),
        0 8px 25px rgba(59, 130, 246, 0.3);
}

.right-panel-search .search-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #60A5FA;
    font-size: 1rem;
    z-index: 3;
    filter: drop-shadow(0 0 4px rgba(96, 165, 250, 0.5));
}

/* Responsive design for modal */
@media (max-width: 1024px) {
    .fingerprint-modal-content {
        width: 95vw;
        flex-direction: column;
    }
    
    .fingerprint-body {
        flex-direction: column;
    }
    
    .fingerprint-left-panel {
        border-right: none;
        border-bottom: 1px solid #E5E7EB;
        padding: 20px;
    }
    
    .fingerprint-right-panel {
        max-height: 50vh;
    }
}

.fingerprint-header .close-btn {
    position: absolute;
    top: 25px;
    right: 25px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: white;
    font-size: 20px;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    backdrop-filter: blur(10px);
    z-index: 3;
}

.fingerprint-header .close-btn:hover {
    background: rgba(239, 68, 68, 0.8);
    border-color: rgba(239, 68, 68, 1);
    transform: scale(1.1) rotate(90deg);
    box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
}

.employee-selection {
    padding: 25px 30px 15px;
    border-bottom: 1px solid #DBE2EF;
}

.employee-selection label {
    display: block;
    font-weight: 600;
    color: #112D4E;
    margin-bottom: 10px;
    font-size: 14px;
}

.employee-dropdown {
    width: 100%;
    padding: 14px 15px;
    border: 2px solid #DBE2EF;
    border-radius: 10px;
    font-size: 15px;
    background: white;
    color: #112D4E;
    transition: all 0.3s ease;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%233F72AF' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 15px center;
    background-size: 16px;
    cursor: pointer;
    animation: fadeIn 0.3s ease;
}

.employee-dropdown:focus {
    outline: none;
    border-color: #3F72AF;
    box-shadow: 0 0 0 4px rgba(63, 114, 175, 0.15);
    transform: translateY(-2px);
}

.employee-dropdown:hover {
    border-color: #3F72AF;
}

.enrollment-summary {
    margin-top: 10px;
    font-size: 13px;
    color: #6c757d;
}

.enrollment-summary i {
    color: #3F72AF;
    margin-right: 5px;
}

.scan-status-container {
    padding: 35px 30px;
    text-align: center;
    margin: 0;
    transition: all 0.4s ease;
    max-width: 520px;
    min-height: 240px;
    border-radius: 12px;
    margin-left: auto;
    margin-right: auto;
    animation: fadeIn 0.3s ease;
}

.scan-status-container.ready {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 8px 32px rgba(102, 126, 234, 0.3);
}

.scan-status-container.sending {
    background: linear-gradient(135deg, #F9F7F7 0%, #e3f2fd 100%);
}

.scan-status-container.success {
    background: linear-gradient(135deg, #F9F7F7 0%, #e8f5e9 100%);
}

.scan-status-container.error {
    background: linear-gradient(135deg, #F9F7F7 0%, #ffebee 100%);
}

.fingerprint-animation {
    width: 90px;
    height: 90px;
    margin: 0 auto 25px;
    border-radius: 50%;
    background: #DBE2EF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 42px;
    color: #3F72AF;
    transition: all 0.4s ease;
    position: relative;
}

.scan-status-container.ready .fingerprint-animation {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
}

.fingerprint-animation::before {
    content: '';
    position: absolute;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: inherit;
    opacity: 0.3;
    animation: ripple 2s infinite;
}

@keyframes ripple {
    0% {
        transform: scale(1);
        opacity: 0.3;
    }
    100% {
        transform: scale(1.5);
        opacity: 0;
    }
}

.scan-status-container.sending .fingerprint-animation {
    background: #3F72AF;
    color: white;
    animation: fingerprint-pulse 1.5s ease-in-out infinite;
}

.scan-status-container.success .fingerprint-animation {
    background: #4CAF50;
    color: white;
    animation: success-bounce 0.6s ease;
}

.scan-status-container.error .fingerprint-animation {
    background: #f44336;
    color: white;
    animation: error-shake 0.6s ease;
}

/* Fingerprint Animation Keyframes */
@keyframes fingerprint-pulse {
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(63, 114, 175, 0.7);
    }
    50% {
        transform: scale(1.05);
        box-shadow: 0 0 0 15px rgba(63, 114, 175, 0);
    }
}

@keyframes success-bounce {
    0%, 100% {
        transform: scale(1);
    }
    25% {
        transform: scale(0.9);
    }
    50% {
        transform: scale(1.1);
    }
    75% {
        transform: scale(0.95);
    }
}

@keyframes error-shake {
    0%, 100% {
        transform: translateX(0);
    }
    10%, 30%, 50%, 70%, 90% {
        transform: translateX(-10px);
    }
    20%, 40%, 60%, 80% {
        transform: translateX(10px);
    }
}

@keyframes slideUpBounce {
    0% {
        transform: translateY(100px);
        opacity: 0;
    }
    60% {
        transform: translateY(-10px);
        opacity: 1;
    }
    80% {
        transform: translateY(5px);
    }
    100% {
        transform: translateY(0);
    }
}

.scan-status-text {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 8px;
    color: #112D4E;
}

.scan-status-container.ready .scan-status-text {
    color: white;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}

.scan-status-container.sending .scan-status-text {
    color: #3F72AF;
}

.scan-status-container.success .scan-status-text {
    color: #4CAF50;
}

.scan-status-container.error .scan-status-text {
    color: #f44336;
}

.scan-message {
    font-size: 14px;
}

.scan-status-container.ready .scan-message {
    color: rgba(255, 255, 255, 0.9);
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    line-height: 1.5;
}

.scan-details {
    margin-top: 10px;
    padding: 10px;
    background: rgba(63, 114, 175, 0.05);
    border-radius: 8px;
    font-size: 13px;
    text-align: left;
}

.fingerprint-instructions {
    padding: 20px 30px;
    background: #F9F7F7;
    border-top: 1px solid #DBE2EF;
}

.fingerprint-instructions h4 {
    margin: 0 0 12px 0;
    font-size: 15px;
    color: #112D4E;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.fingerprint-instructions h4 i {
    color: #3F72AF;
}

.fingerprint-instructions ul {
    margin: 0;
    padding-left: 20px;
    color: #6c757d;
    font-size: 13px;
    line-height: 1.6;
}

.fingerprint-instructions li {
    margin-bottom: 6px;
}

.fingerprint-footer {
    padding: 25px 40px;
    background: linear-gradient(135deg, #1E293B 0%, #334155 100%);
    border-top: 1px solid rgba(59, 130, 246, 0.3);
    display: flex;
    justify-content: flex-end;
    gap: 16px;
    position: relative;
    z-index: 2;
}

.fingerprint-footer::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(147, 51, 234, 0.05) 100%);
    pointer-events: none;
}

.fp-btn {
    padding: 14px 28px;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 140px;
    justify-content: center;
    position: relative;
    overflow: hidden;
}

.fp-btn::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    transform: translate(-50%, -50%);
    transition: width 0.6s, height 0.6s;
}

.fp-btn:hover::before {
    width: 300px;
    height: 300px;
}

.fp-btn-cancel {
    background: #DBE2EF;
    color: #112D4E;
}

.fp-btn-cancel:hover {
    background: #3F72AF;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(63, 114, 175, 0.3);
}

.fp-btn-start {
    background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(63, 114, 175, 0.2);
}

.fp-btn-start:hover:not(:disabled) {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(63, 114, 175, 0.4);
}

.fp-btn-start:disabled {
    background: #DBE2EF;
    color: #6c757d;
    cursor: not-allowed;
    box-shadow: none;
    opacity: 0.6;
}

.fp-btn i {
    font-size: 16px;
    transition: transform 0.3s ease;
}

.fp-btn:hover:not(:disabled) i {
    transform: scale(1.2);
}

    
    /* Responsive Design for Modal */
    @media (max-width: 768px) {
        .fingerprint-modal-content {
            width: 95vw;
            margin: 0 10px;
        }
        
        .fingerprint-header, .fingerprint-body, .fingerprint-footer {
            padding: 20px;
        }
        
        .progress-steps {
            margin: 20px 0;
        }
        
        .step-circle {
            width: 25px;
            height: 25px;
            font-size: 11px;
        }
        
        .step-label {
            font-size: 10px;
        }
    }

    /* Responsive Adjustments for main content */
    @media (max-width: 1400px) {
        #employeeModal .modal-content {
            width: 88%;
            min-width: 800px;
        }
    }

    @media (max-width: 1200px) {
        #employeeModal .modal-content {
            width: 90%;
            min-width: 750px;
        }
        
        .employee-info-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .history-section {
            grid-column: span 2;
        }
    }

    @media (max-width: 992px) {
        #employeeModal .modal-content {
            width: 92%;
            min-width: 650px;
            padding: 25px 40px;
        }
        #addEmployeeModal .modal-content { width: 94%; }
        #addEmployeeModal form { grid-template-columns: 1fr; }
        #addEmployeeModal .form-group-span-2,
        #addEmployeeModal .form-actions,
        #addEmployeeModal .form-section-header { grid-column: span 1; }
        
        .employee-avatar {
            width: 90px;
            height: 90px;
            font-size: 36px;
        }
        
        .employee-name {
            font-size: 28px;
        }
        
        .employee-position {
            font-size: 18px;
        }
    }

    @media (max-width: 768px) {
        #employeeModal .modal-content {
            width: 95%;
            min-width: unset;
            max-width: 95vw;
            padding: 20px 30px;
        }
        
        #addEmployeeModal .modal-content {
            width: 90%;
            padding: 20px;
        }
        
        #addEmployeeModal form {
            grid-template-columns: 1fr;
        }
        
        #addEmployeeModal .form-group-span-2,
        #addEmployeeModal .form-actions,
        #addEmployeeModal .form-section-header {
            grid-column: span 1;
        }
        
        .employee-info-grid {
            grid-template-columns: 1fr;
        }
        
        .history-section {
            grid-column: span 1;
        }
        
        .employee-profile-header {
            flex-direction: column;
            text-align: center;
        }
        
        .employee-avatar {
            margin-right: 0;
            margin-bottom: 20px;
        }
        
        .info-label {
            flex: 0 0 130px;
        }
    }

    @media (max-width: 576px) {
        #employeeModal .modal-content {
            padding: 20px 25px;
        }
        
        .employee-avatar {
            width: 80px;
            height: 80px;
            font-size: 32px;
        }
        
        .employee-name {
            font-size: 24px;
        }
        
        .section-title {
            font-size: 18px;
        }
        
        .info-label {
            flex: 0 0 120px;
            font-size: 15px;
        }
        
        .info-value {
            font-size: 15px;
        }
        
        .header {
            flex-direction: column;
            gap: 15px;
            align-items: flex-start;
        }
        
        .header-actions {
            width: 100%;
            flex-direction: column;
            gap: 10px;
        }
        
        .search-box {
            width: 100%;
        }
        
        .view-toggle {
            width: 100%;
            justify-content: space-between;
        }
        
        .view-btn {
            flex-grow: 1;
            text-align: center;
            justify-content: center;
        }
    }
    /* Enhanced List View */
#listView {
    background-color: white;
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    margin-top: 25px;
    display: none;
}

.list-view-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 1px solid #DBE2EF;
}

.list-view-header h2 {
    font-size: 22px;
    color: #112D4E;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
}

.list-view-header h2 i {
    color: #3F72AF;
}

.list-view-actions {
    display: flex;
    gap: 15px;
    align-items: center;
}

.export-btn {
    padding: 10px 18px;
    background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    font-size: 14px;
}

.export-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(63, 114, 175, 0.3);
}

.employee-table-container {
    overflow-x: auto;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.employee-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 1000px;
}

.employee-table thead {
    position: sticky;
    top: 0;
    z-index: 10;
}

.employee-table th {
    background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
    color: white;
    font-weight: 600;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 16px 12px;
    text-align: left;
    position: relative;
}

.employee-table th:first-child {
    border-top-left-radius: 12px;
}

.employee-table th:last-child {
    border-top-right-radius: 12px;
}

.employee-table th i {
    margin-left: 6px;
    font-size: 12px;
    opacity: 0.7;
}

.employee-table td {
    padding: 16px 12px;
    border-bottom: 1px solid #F0F4F8;
    color: #2D3748;
    font-size: 14px;
    position: relative;
    transition: all 0.2s ease;
}

.employee-table tbody tr {
    background-color: white;
    transition: all 0.2s ease;
}

.employee-table tbody tr:hover {
    background-color: #F8FBFF;
    transform: translateY(1px);
    box-shadow: 0 2px 8px rgba(63, 114, 175, 0.1);
}

.employee-table tbody tr:hover td {
    border-color: #DBE2EF;
}

.employee-id {
    font-weight: 600;
    color: #112D4E;
    font-family: 'Courier New', monospace;
}

.employee-name {
    font-weight: 600;
    color: #2D3748;
    display: flex;
    align-items: center;
    gap: 10px;
}

.employee-avatar-sm {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
    flex-shrink: 0;
}

.employee-email {
    color: #4A5568;
    display: flex;
    align-items: center;
    gap: 8px;
}

.employee-email i {
    color: #3F72AF;
    font-size: 14px;
}

.employee-department {
    padding: 6px 12px;
    background-color: rgba(63, 114, 175, 0.1);
    color: #3F72AF;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    display: inline-block;
}

.employee-position {
    color: #4A5568;
    font-size: 13px;
}

.employee-salary {
    font-weight: 600;
    color: #16C79A;
    font-family: 'Courier New', monospace;
}

.status-badge-table {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
}

.status-active {
    background-color: rgba(22, 199, 154, 0.15);
    color: #16C79A;
    border: 2px solid rgba(22, 199, 154, 0.3);
}

.status-inactive {
    background-color: rgba(255, 71, 87, 0.15);
    color: #ff4757;
    border: 2px solid rgba(255, 71, 87, 0.3);
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    padding: 8px 14px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 500;
}

.view-btn {
    background-color: rgba(17, 105, 142, 0.1);
    color: #11698E;
}

.view-btn:hover {
    background-color: #11698E;
    color: white;
    transform: translateY(-2px);
}

.edit-btn {
    background-color: rgba(22, 199, 154, 0.1);
    color: #16C79A;
}

.edit-btn:hover {
    background-color: #16C79A;
    color: white;
    transform: translateY(-2px);
}

.delete-btn {
    background-color: rgba(255, 71, 87, 0.1);
    color: #ff4757;
}

.delete-btn:hover {
    background-color: #ff4757;
    color: white;
    transform: translateY(-2px);
}

.fingerprint-status {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
}

.fingerprint-enrolled {
    color: #16C79A;
}

.fingerprint-pending {
    color: #FF6B35;
}

.no-employees {
    text-align: center;
    padding: 40px;
    color: #A0AEC0;
    font-style: italic;
}

.no-employees i {
    font-size: 48px;
    margin-bottom: 15px;
    display: block;
    color: #DBE2EF;
}

/* Pagination Styles */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 1px solid #DBE2EF;
}

.pagination-info {
    font-size: 14px;
    color: #4A5568;
}

.pagination {
    display: flex;
    gap: 8px;
}

.pagination button {
    padding: 10px 16px;
    border: 1px solid #DBE2EF;
    background-color: white;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    color: #4A5568;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
}

.pagination button:hover:not(.active):not(:disabled) {
    background-color: #F8FBFF;
    border-color: #3F72AF;
    color: #3F72AF;
}

.pagination button.active {
    background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
    color: white;
    border-color: #3F72AF;
}

.pagination button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Filter Bar */
.filter-bar {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    align-items: center;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-group label {
    font-size: 13px;
    font-weight: 600;
    color: #4A5568;
}

.filter-select {
    padding: 10px 12px;
    border: 1px solid #DBE2EF;
    border-radius: 8px;
    background-color: white;
    color: #2D3748;
    font-size: 14px;
    min-width: 150px;
}

.filter-select:focus {
    outline: none;
    border-color: #3F72AF;
    box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
}

    /* Notification Popup Styles */
    .notification-popup {
        position: fixed;
        right: 20px;
        bottom: 20px;
        top: auto;
        z-index: 10000;
        width: 360px;
        max-width: 90vw;
        max-height: 160px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        border: 1px solid #DBE2EF;
        transform: translateX(100%);
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        overflow: hidden;
    }

    .notification-popup.show {
        transform: translateX(0);
    }

    .notification-content {
        display: flex;
        align-items: center;
        padding: 16px;
        gap: 12px;
    }

    .notification-icon {
        flex-shrink: 0;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        color: white;
    }

    .notification-popup.success .notification-icon {
        background: linear-gradient(135deg, #16C79A 0%, #0F9D58 100%);
    }

    .notification-popup.error .notification-icon {
        background: linear-gradient(135deg, #ff4757 0%, #e74c3c 100%);
    }

    .notification-popup.warning .notification-icon {
        background: linear-gradient(135deg, #FF6B35 0%, #f39c12 100%);
    }

    .notification-popup.info .notification-icon {
        background: linear-gradient(135deg, #3F72AF 0%, #11698E 100%);
    }

    .notification-message {
        flex-grow: 1;
        min-width: 0;
    }

    .notification-title {
        font-weight: 600;
        font-size: 14px;
        color: #112D4E;
        margin-bottom: 5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .notification-text {
        font-size: 14px;
        color: #4A5568;
        line-height: 1.4;
        word-wrap: break-word;
        overflow: hidden;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        line-clamp: 3;
        -webkit-box-orient: vertical;
    }

    .notification-close {
        background: none;
        border: none;
        color: #A0AEC0;
        cursor: pointer;
        padding: 5px;
        border-radius: 50%;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        flex-shrink: 0;
    }

    .notification-close:hover {
        background-color: #F7FAFC;
        color: #4A5568;
        transform: scale(1.1);
    }

    .notification-close i {
        font-size: 14px;
    }

    /* Notification animations */
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }

    /* Responsive adjustments */
    @media (max-width: 1200px) {
        .list-view-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        
        .list-view-actions {
            width: 100%;
            justify-content: flex-end;
        }
    }

    @media (max-width: 768px) {
        .filter-bar {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .filter-group {
            width: 100%;
        }
        
        .filter-select {
            width: 100%;
        }
        
        .pagination-container {
            flex-direction: column;
            gap: 15px;
            align-items: center;
        }
        
        .action-buttons {
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 6px 10px;
            font-size: 12px;
        }
        
        /* Mobile notification adjustments */
        .notification-popup {
            top: auto;
            bottom: 12px;
            right: 12px;
            left: auto;
            width: 92vw;
            max-width: 360px;
            transform: translateX(100%);
        }
        
        .notification-popup.show {
            transform: translateX(0);
        }
        
        .notification-content {
            padding: 15px;
            gap: 12px;
        }
        
        .notification-icon {
            width: 35px;
            height: 35px;
            font-size: 16px;
        }
        
        .notification-title {
            font-size: 13px;
        }
        
        .notification-text {
            font-size: 13px;
        }
    }
    
        /* Custom Logout Confirmation Modal */
        .logout-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .logout-modal-content {
            background-color: #fff;
            margin: 15% auto;
            padding: 0;
            border-radius: 15px;
            width: 400px;
            max-width: 90%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .logout-modal-header {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            text-align: center;
            position: relative;
        }

        .logout-modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .logout-modal-header .close {
            position: absolute;
            right: 15px;
            top: 15px;
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.3s;
        }

        .logout-modal-header .close:hover {
            opacity: 1;
        }

        .logout-modal-body {
            padding: 25px;
            text-align: center;
        }

        .logout-modal-body .icon {
            font-size: 48px;
            color: #ff6b6b;
            margin-bottom: 15px;
        }

        .logout-modal-body p {
            margin: 0 0 25px 0;
            color: #555;
            font-size: 16px;
            line-height: 1.5;
        }

        .logout-modal-footer {
            padding: 0 25px 25px 25px;
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .logout-modal-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 100px;
        }

        .logout-modal-btn.cancel {
            background-color: #f8f9fa;
            color: #6c757d;
            border: 2px solid #dee2e6;
        }

        .logout-modal-btn.cancel:hover {
            background-color: #e9ecef;
            border-color: #adb5bd;
        }

        .logout-modal-btn.confirm {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            border: 2px solid transparent;
        }

        .logout-modal-btn.confirm:hover {
            background: linear-gradient(135deg, #ee5a52, #dc3545);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
        }

        .logout-modal-btn:active {
            transform: translateY(0);
        }

        /* Responsive design */
        @media (max-width: 480px) {
            .logout-modal-content {
                width: 95%;
                margin: 20% auto;
            }
            
            .logout-modal-footer {
                flex-direction: column;
            }
            
            .logout-modal-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Employee Info Modal - Enhanced & Elegant -->
<div id="employeeModal" class="modal">
    <div class="modal-content">
        <button class="close-modal" onclick="closeModal()">&times;</button>
        
        <div class="employee-profile-header">
            <div class="employee-avatar" id="empAvatar"></div>
            <div class="employee-header-info">
                <h1 class="employee-name" id="empName"></h1>
                <div class="employee-position" id="empPosition"></div>
                <div class="employee-department" id="empDepartment"></div>
            </div>
        </div>
        
        <div class="employee-info-body">
            <div class="employee-info-grid">
                <!-- Personal Information -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-user-circle"></i> Personal Information
                    </div>
                    <div class="info-item">
                        <div class="info-label">Employee ID</div>
                        <div class="info-value" id="empID"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Full Name</div>
                        <div class="info-value" id="empFullName"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email Address</div>
                        <div class="info-value" id="empEmail"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Contact Number</div>
                        <div class="info-value" id="empContact"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Birthdate</div>
                        <div class="info-value" id="empBirthdate"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Age</div>
                        <div class="info-value" id="empAge"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Blood Type</div>
                        <div class="info-value" id="empBloodType"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Present Address</div>
                        <div class="info-value" id="empPresentAddress"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Permanent Address</div>
                        <div class="info-value" id="empPermanentAddress"></div>
                    </div>
                </div>
                
                <!-- Employment Information -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-briefcase"></i> Employment Details
                    </div>
                    <div class="info-item">
                        <div class="info-label">Years of Service</div>
                        <div class="info-value" id="empServiceYears"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Employment Status</div>
                        <div class="info-value">
                            <span class="status-badge" id="empStatus"></span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Area of Assignment</div>
                        <div class="info-value" id="empAreaAssignment"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Work Shift</div>
                        <div class="info-value" id="empShift"></div>
                    </div>
                    <div class="info-item" id="nightShiftDifferential" style="display: none;">
                        <div class="info-label">Night Shift Differential</div>
                        <div class="info-value night-shift-badge">
                            <i class="fas fa-moon"></i> NSD (10PM-6AM) - 10% Additional Pay
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Base Salary</div>
                        <div class="info-value salary">₱<span id="empSalary"></span></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Rice Allowance</div>
                        <div class="info-value salary">₱<span id="empRiceAllowance"></span></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Medical Allowance</div>
                        <div class="info-value salary">₱<span id="empMedicalAllowance"></span></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Laundry Allowance</div>
                        <div class="info-value salary">₱<span id="empLaundryAllowance"></span></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Leave Pay Counts</div>
                        <div class="info-value"><span id="empLeavePayCounts"></span> days</div>
                    </div>
                </div>
                
                <!-- Government IDs -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-id-card"></i> Government IDs
                    </div>
                    <div class="info-item">
                        <div class="info-label">TIN</div>
                        <div class="info-value" id="empTIN"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">SSS</div>
                        <div class="info-value" id="empSSS"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">PHIC</div>
                        <div class="info-value" id="empPHIC"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">HDMF (Pag-IBIG)</div>
                        <div class="info-value" id="empHDMF"></div>
                    </div>
                </div>
                
                <!-- Employment History -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-history"></i> Employment History
                    </div>
                    <div class="info-item">
                        <div class="info-label">Date Hired</div>
                        <div class="info-value" id="empDateHired"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Last Day Employed</div>
                        <div class="info-value" id="empLastDay"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Date Transferred</div>
                        <div class="info-value" id="empTransferred"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">History</div>
                        <div class="info-value" id="empHistory"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="sidebar">
        <img src="LOGO/newLogo_transparent.png" class="logo" style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding-bottom: 20px;">
        <div class="menu">
            <a href="AdminHome.php" class="menu-item">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="AdminEmployees.php" class="menu-item active">
                <i class="fas fa-users"></i> Employees
            </a>
            <a href="AdminAttendance.php" class="menu-item">
                <i class="fas fa-calendar-check"></i> Attendance
            </a>
            <a href="AdminPayroll.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i> Payroll
            </a>
            <a href="AdminHistory.php" class="menu-item">
                <i class="fas fa-history"></i> History
            </a>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
    
    <div class="main-content">
    <div class="header">
        <h1>Employee Management</h1>
        <div class="header-actions">
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search employees...">
                <i class="fas fa-search"></i>
            </div>
            
            <div class="view-toggle">
                <a href="#" class="view-btn" onclick="showListView()">
                    <i class="fas fa-list"></i> List View
                </a>
                <a href="#" class="view-btn active" onclick="showDepartmentView()">
                    <i class="fas fa-building"></i> Department View
                </a>
                <button class="btn btn-primary" onclick="openFingerprintModal()">
                    <i class="fas fa-fingerprint"></i> Enroll Fingerprint
                </button>
            </div>
            
            <button onclick="openAddEmployeeModal()" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Add Employee
            </button>
        </div>
    </div>

        <!-- Dashboard Cards (Only 3 cards now) -->
        <div class="dashboard-cards" style="grid-template-columns: repeat(3, 1fr);">
            <div class="card">
                <div class="card-content">
                    <div class="card-title">Total Employees</div>
                    <div class="card-value"><?php echo $totalEmployees; ?></div>
                    <div class="card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-content">
                    <div class="card-title">Active Employees</div>
                    <div class="card-value"><?php echo $activeCount; ?></div>
                    <div class="card-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-content">
                    <div class="card-title">Departments</div>
                    <div class="card-value"><?php echo $deptCount; ?></div>
                    <div class="card-icon">
                        <i class="fas fa-building"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php 
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php 
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>

       

        <!-- Departments Grid View -->
<div id="departmentsView" class="departments-grid">
    <?php foreach ($allDepartments as $department): ?>
        <?php 
        $deptEmployees = $employeesByDepartment[$department] ?? []; 
        
        // Count only valid employees (non-empty names and not vacant positions)
        $validEmployeeCount = 0;
        foreach ($deptEmployees as $employee) {
            if (!empty($employee['EmployeeName']) && $employee['EmployeeName'] !== ' Vacant Position') {
                $validEmployeeCount++;
            }
        }
        ?>
        <div class="department-card" onclick="showDepartmentDetail('<?php echo $department; ?>')">
            <div class="department-header">
                <div class="department-name"><?php echo $department; ?></div>
                <div class="employee-count"><?php echo $validEmployeeCount; ?> employees</div>
            </div>
            <div class="department-preview">
                <?php 
                $displayedCount = 0;
                $maxDisplay = 4;
                
                foreach ($deptEmployees as $employee):
                    if ($displayedCount >= $maxDisplay) break;
                    
                    if (!empty($employee['EmployeeName']) && $employee['EmployeeName'] !== ' Vacant Position'):
                        $displayedCount++;
                ?>
                    <div class="preview-employee">
                        <i class="fas fa-user"></i>
                        <div>
                            <div class="preview-employee-name"><?php echo $employee['EmployeeName']; ?></div>
                            <?php if (!empty($employee['Position'])): ?>
                                <div class="preview-employee-position"><?php echo $employee['Position']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php 
                    endif;
                endforeach;
                
                // Fill remaining slots with empty placeholders
                $emptySlots = $maxDisplay - $displayedCount;
                for ($i = 0; $i < $emptySlots; $i++):
                ?>
                    <div class="empty-slot"></div>
                <?php endfor; ?>
                
                <?php if ($validEmployeeCount > $maxDisplay): ?>
                    <div class="more-employees">
                        <i class="fas fa-users"></i>
                        +<?php echo $validEmployeeCount - $maxDisplay; ?> more employees
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="department-icon">
                <?php 
                // Set different icons for different departments
                $icons = [
                    'Treasury' => 'university',
                    'HR' => 'users',
                    'Sales' => 'handshake',
                    'Tax' => 'file-invoice-dollar',
                    'Admin' => 'user-shield',
                    'Finance' => 'chart-line',
                    'Accounting' => 'calculator',
                    'Marketing' => 'bullhorn',
                    'CMCD' => 'project-diagram',
                    'Security' => 'shield-alt'
                ];
                $icon = $icons[$department] ?? 'building';
                ?>
                <i class="fas fa-<?php echo $icon; ?>"></i>
            </div>
        </div>
    <?php endforeach; ?>
</div>

        <!-- Department Detail View -->
        <div id="departmentDetail" class="department-detail">
            <div class="detail-header">
                <h2 id="departmentTitle">Department Employees</h2>
                <button class="back-btn" onclick="showDepartmentView()">
                    <i class="fas fa-arrow-left"></i> Back to Departments
                </button>
            </div>
            
            
            
            <table class="employees-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Position</th>
                        <th>Salary</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                    
                <tbody id="departmentEmployeesTable">
                    <!-- Employee rows will be populated here by JavaScript -->
                </tbody>
            </table>
        </div>

        <!-- List View -->
<div id="listView" class="employee-table" style="display: none;">
    <div class="list-view-header">
        <h2><i class="fas fa-list"></i> Employees - List View</h2>
        <div class="list-view-actions">
            <label for="listSortSelect" style="font-size: 14px; color:#4A5568;">Sort by</label>
            <select id="listSortSelect" class="filter-select" onchange="sortListView()">
                <option value="name_asc">Name (A–Z)</option>
                <option value="name_desc">Name (Z–A)</option>
                <option value="hired_desc">Date Hired (Newest)</option>
                <option value="hired_asc">Date Hired (Oldest)</option>
                <option value="created_desc">Recently Added (Newest)</option>
                <option value="created_asc">Recently Added (Oldest)</option>
            </select>
        </div>
    </div>
    <table id="employeeListTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Department</th>
                <th>Position</th>
                <th>Salary</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($employees)): ?>
                <tr>
                    <td colspan="7" style="text-align: center;">No employees found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($employees as $employee): ?>
                    <tr 
                        data-name="<?php echo htmlspecialchars($employee['EmployeeName'] ?? ''); ?>"
                        data-hired="<?php echo htmlspecialchars($employee['DateHired'] ?? ''); ?>"
                        data-created="<?php echo htmlspecialchars($employee['created_at'] ?? ''); ?>"
                        onclick="showEmployeeModal(
                        '<?php echo $employee['EmployeeID'] ?? ''; ?>',
                        '<?php echo addslashes($employee['EmployeeName'] ?? ''); ?>',
                        '<?php echo addslashes($employee['EmployeeEmail'] ?? ''); ?>',
                        '<?php echo addslashes($employee['Department'] ?? ''); ?>',
                        '<?php echo addslashes($employee['Position'] ?? ''); ?>',
                        '<?php echo addslashes($employee['Shift'] ?? 'Not Available'); ?>',
                        '<?php echo addslashes($employee['Contact'] ?? 'Not Available'); ?>',
                        '<?php echo addslashes($employee['DateHired'] ?? 'Not Available'); ?>',
                        '<?php echo addslashes($employee['LastDayEmployed'] ?? ''); ?>',
                        '<?php echo addslashes($employee['DateTransferred'] ?? ''); ?>',
                        '<?php echo addslashes($employee['Birthdate'] ?? 'Not Available'); ?>',
                        '<?php echo $employee['Age'] ?? ''; ?>',
                        '<?php echo addslashes($employee['Status'] ?? ''); ?>',
                        '<?php echo number_format($employee['base_salary'] ?? 0, 2); ?>',
                        '<?php echo $employee['rice_allowance'] ?? 0; ?>',
                        '<?php echo $employee['medical_allowance'] ?? 0; ?>',
                        '<?php echo $employee['laundry_allowance'] ?? 0; ?>',
                        '<?php echo $employee['leave_pay_counts'] ?? 10; ?>',
                        '<?php echo addslashes($employee['TIN'] ?? 'Not Available'); ?>',
                        '<?php echo addslashes($employee['SSS'] ?? 'Not Available'); ?>',
                        '<?php echo addslashes($employee['PHIC'] ?? 'Not Available'); ?>',
                        '<?php echo addslashes($employee['HDMF'] ?? 'Not Available'); ?>',
                        '<?php echo addslashes($employee['BloodType'] ?? 'Not Available'); ?>',
                        '<?php echo addslashes($employee['PresentHomeAddress'] ?? 'Not Available'); ?>',
                        '<?php echo addslashes($employee['PermanentHomeAddress'] ?? 'Not Available'); ?>',
                        '<?php echo addslashes($employee['AreaOfAssignment'] ?? 'Not Available'); ?>',
                        '<?php echo addslashes($employee['history'] ?? 'No history available'); ?>',
                        '<?php echo addslashes($employee['LengthOfService'] ?? 'Not Available'); ?>'
                    )">
                        <td><?php echo $employee['EmployeeID'] ?? ''; ?></td>
                        <td><?php echo $employee['EmployeeName'] ?? ''; ?></td>
                        <td><?php echo $employee['EmployeeEmail'] ?? ''; ?></td>
                        <td><?php echo $employee['Department'] ?? ''; ?></td>
                        <td><?php echo $employee['Position'] ?? ''; ?></td>
                        <td>₱<?php echo number_format($employee['base_salary'] ?? 0, 2); ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="AdminEdit.php?id=<?php echo $employee['EmployeeID']; ?>" class="action-btn edit-btn" onclick="event.stopPropagation();">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <form method="POST" action="AdminEmployees.php" style="display: inline;">
    <input type="hidden" name="archive_employee" value="1">
    <input type="hidden" name="employee_id" value="<?php echo $employee['EmployeeID']; ?>">
    <button type="submit" class="action-btn delete-btn" 
            onclick="event.stopPropagation(); return confirm('Are you sure you want to archive this employee?');">
        <i class="fas fa-trash"></i> Archive
    </button>
</form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div class="pagination">
        <button><i class="fas fa-chevron-left"></i></button>
        <button class="active">1</button>
        <button>2</button>
        <button>3</button>
        <button><i class="fas fa-chevron-right"></i></button>
    </div>
</div>
            
            

    <div id="addEmployeeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-plus"></i> Add New Employee</h2>
                <button class="close-modal" onclick="closeAddEmployeeModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
            <form id="addEmployeeForm" method="POST" action="AdminEmployees.php" onsubmit="return validateEmployeeForm()">
                <input type="hidden" name="add_employee" value="1">
                
                <div class="form-section-header">Basic Information</div>
                
                <div class="form-group">
                    <label for="employeeID">Employee ID</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" class="form-control" id="employeeID" name="employeeID" 
                               required pattern="^([0-9]{4})[0-9]{3}$" maxlength="7" 
                               title="Must be YYYYNNN format, e.g., 2025001.">
                        <button type="button" class="btn-autofill" id="autoFillBtn" 
                                onclick="autoFillEmployeeID()" title="Auto-fill next Employee ID">
                            <i class="fas fa-magic"></i> Auto-fill
                        </button>
                    </div>
                    <small class="form-text text-muted">Format: YYYYNNN (e.g., 2025001)</small>
                </div>
                
                <div class="form-group">
                    <label for="employeeName">Full Name</label>
                    <input type="text" class="form-control" id="employeeName" name="employeeName" required>
                </div>
                
                <div class="form-group">
                    <label for="employeeEmail">Email Address</label>
                    <input type="email" class="form-control" id="employeeEmail" name="employeeEmail" required>
                </div>
                
                <div class="form-group">
                    <label for="employeePassword">Password</label>
                    <div class="password-container">
                        <input type="password" class="form-control" id="employeePassword" name="employeePassword" required>
                        <i class="fas fa-eye password-toggle" id="togglePassword" onclick="togglePasswordVisibility('employeePassword', 'togglePassword')"></i>
                    </div>
                    <div class="password-requirements" id="passwordRequirements">
                        <div class="requirement" id="lengthReq">
                            <i class="fas fa-times"></i>
                            <span>At least 8 characters</span>
                        </div>
                        <div class="requirement" id="uppercaseReq">
                            <i class="fas fa-times"></i>
                            <span>At least 1 uppercase letter</span>
                        </div>
                        <div class="requirement" id="numberReq">
                            <i class="fas fa-times"></i>
                            <span>At least 1 number</span>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirmPassword">Confirm Password</label>
                    <div class="password-container">
                        <input type="password" class="form-control" id="confirmPassword" name="confirmPassword" required>
                        <i class="fas fa-eye password-toggle" id="toggleConfirmPassword" onclick="togglePasswordVisibility('confirmPassword', 'toggleConfirmPassword')"></i>
                    </div>
                    <div class="password-match" id="passwordMatch"></div>
                </div>
                
                <div class="form-group">
                    <label for="dateHired">Date Hired</label>
                    <input type="date" class="form-control" id="dateHired" name="dateHired" required>
                </div>
                
                <div class="form-group">
                    <label for="birthdate">Birthdate</label>
                    <input type="date" class="form-control" id="birthdate" name="birthdate" required>
                </div>
                
                <div class="form-group">
                    <label for="age">Age</label>
                    <input type="number" class="form-control" id="age" name="age" min="18" max="65" required>
                </div>
                
                <div class="form-group">
                    <label for="lengthOfService">Length of Service (years)</label>
                    <input type="text" class="form-control" id="lengthOfService" name="lengthOfService" required>
                </div>
                
                <div class="form-group">
                    <label for="bloodType">Blood Type</label>
                    <select class="form-control" id="bloodType" name="bloodType" required>
                        <option value="">Select Blood Type</option>
                        <option value="A+">A+</option>
                        <option value="A-">A-</option>
                        <option value="B+">B+</option>
                        <option value="B-">B-</option>
                        <option value="AB+">AB+</option>
                        <option value="AB-">AB-</option>
                        <option value="O+">O+</option>
                        <option value="O-">O-</option>
                    </select>
                </div>
                
                <div class="form-section-header">Government IDs</div>

<div class="form-group">
    <label for="tin">TIN</label>
    <input type="text" class="form-control" id="tin" name="tin" required 
           maxlength="15"
           title="Format: 123-456-789-012" 
           oninput="formatTIN(this)" 
           placeholder="123-456-789-012">
    <small class="form-text text-muted">Format: XXX-XXX-XXX-XXX (12 digits)</small>
</div>

<div class="form-group">
    <label for="sss">SSS</label>
    <input type="text" class="form-control" id="sss" name="sss" required 
           maxlength="12"
           title="Format: 12-3456789-0" 
           oninput="formatSSS(this)" 
           placeholder="12-3456789-0">
    <small class="form-text text-muted">Format: XX-XXXXXXX-X (10 digits)</small>
</div>

<div class="form-group">
    <label for="phic">PHIC</label>
    <input type="text" class="form-control" id="phic" name="phic" required 
           maxlength="14"
           title="Format: 12-345678901-2" 
           oninput="formatPHIC(this)" 
           placeholder="12-345678901-2">
    <small class="form-text text-muted">Format: XX-XXXXXXXXX-X (12 digits)</small>
</div>

<div class="form-group">
    <label for="hdmf">HDMF</label>
    <input type="text" class="form-control" id="hdmf" name="hdmf" required 
           maxlength="14"
           title="Format: 1234-5678-9012" 
           oninput="formatHDMF(this)" 
           placeholder="1234-5678-9012">
    <small class="form-text text-muted">Format: XXXX-XXXX-XXXX (12 digits)</small>
</div>
                
                <div class="form-section-header">Address Information</div>
                
                <div class="form-group form-group-span-2">
                    <label for="presentHomeAddress">Present Home Address</label>
                    <textarea class="form-control" id="presentHomeAddress" name="presentHomeAddress" rows="2" required></textarea>
                </div>
                
                <div class="form-group form-group-span-2">
                    <label for="permanentHomeAddress">Permanent Home Address</label>
                    <textarea class="form-control" id="permanentHomeAddress" name="permanentHomeAddress" rows="2" required></textarea>
                </div>
                
                <div class="form-section-header">Work Information</div>
                
                <div class="form-group">
                    <label for="department">Department</label>
                    <select class="form-control" id="department" name="department" required>
                        <option value="" disabled selected>Select Department</option>
                        <option value="HR">Human Resources</option>
                        <option value="Treasury">Treasury</option>
                        <option value="Finance">Finance</option>
                        <option value="Marketing">Marketing</option>
                        <option value="Admin">Admin</option>
                        <option value="Tax">Tax</option>
                        <option value="Accounting">Accounting</option>
                        <option value="CMCD">CMCD</option>
                        <option value="Sales">Sales</option>
                        <option value="Security">Security</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="position">Position</label>
                    <input type="text" class="form-control" id="position" name="position" value="Employee">
                </div>
                
                <div class="form-group">
                    <label for="shift">Shift</label>
                    <select class="form-control" id="shift" name="shift" required>
                        <option value="" disabled selected>Select Shift</option>
                        <option value="08:00-17:00">8:00 AM - 5:00 PM</option>
                        <option value="08:30-17:30">8:30 AM - 5:30 PM</option>
                        <option value="09:00-18:00">9:00 AM - 6:00 PM</option>
                        <option value="22:00-06:00">Night Shift (10:00 PM - 6:00 AM)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="contact">Contact Number</label>
                    <input type="text" class="form-control" id="contact" name="contact">
                </div>
                
                <div class="form-group">
                    <label for="areaOfAssignment">Area of Assignment</label>
                    <input type="text" class="form-control" id="areaOfAssignment" name="areaOfAssignment" required>
                </div>
                
                <div class="form-section-header">Financial Information</div>
                
                <div class="form-group">
                    <label for="baseSalary">Base Salary (₱)</label>
                    <input type="number" step="0.01" class="form-control" id="baseSalary" name="baseSalary" required>
                </div>
                
                <div class="form-group">
                    <label for="riceAllowance">Rice Allowance (₱)</label>
                    <input type="number" step="0.01" class="form-control" id="riceAllowance" name="riceAllowance" value="0" min="0">
                </div>
                
                <div class="form-group">
                    <label for="medicalAllowance">Medical Allowance (₱)</label>
                    <input type="number" step="0.01" class="form-control" id="medicalAllowance" name="medicalAllowance" value="0" min="0">
                </div>
                
                <div class="form-group">
                    <label for="laundryAllowance">Laundry Allowance (₱)</label>
                    <input type="number" step="0.01" class="form-control" id="laundryAllowance" name="laundryAllowance" value="0" min="0">
                </div>
                
                <div class="form-group">
                    <label for="leavePayCounts">Leave Pay Counts</label>
                    <input type="number" class="form-control" id="leavePayCounts" name="leavePayCounts" value="10" min="0" max="10" required>
                    <small class="form-text text-muted">Number of leave days with pay (maximum 10)</small>
                </div>
                
                <div class="form-section-header">Employment Status</div>
                
                <div class="form-group">
                    <label for="status">Status</label>
                    <select class="form-control" id="status" name="status" required>
                        <option value="Active" selected>Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="lastDayEmployed">Last Day Employed (if applicable)</label>
                    <input type="date" class="form-control" id="lastDayEmployed" name="lastDayEmployed">
                </div>
                
                <div class="form-group">
                    <label for="dateTransferred">Date Transferred (if applicable)</label>
                    <input type="date" class="form-control" id="dateTransferred" name="dateTransferred">
                </div>
                
                
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAddEmployeeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Add Employee</button>
                </div>
            </form>
            </div>
        </div>
        
    </div>


   <!-- ENHANCED FINGERPRINT ENROLLMENT MODAL -->
<div id="fingerprintModal" class="fingerprint-modal">
    <div class="fingerprint-modal-content">
        <div class="fingerprint-header">
            <h2><i class="fas fa-fingerprint"></i> Fingerprint Enrollment</h2>
            <button class="close-btn" onclick="closeFingerprintModal()">&times;</button>
        </div>

        <div class="fingerprint-body">
            <!-- Left Panel - Enrollment Process -->
            <div class="fingerprint-left-panel">
                <div id="scanStatusContainer" class="scan-status-container">
                    <div class="fingerprint-animation">
                        <i class="fas fa-fingerprint"></i>
                    </div>
                    <div class="scan-status-text">Ready to Enroll</div>
                    <div class="scan-message">Select an employee from the list to send their data to the K30 device for fingerprint enrollment.</div>
                </div>
                
                <!-- Notification area for enrollment success -->
                <div id="enrollmentNotification" class="enrollment-notification" style="display: none;">
                    <div class="notification-content">
                        <i class="fas fa-check-circle notification-icon"></i>
                        <div class="notification-details">
                            <h4>Enrollment Sent Successfully!</h4>
                            <p><strong>Employee ID:</strong> <span id="notificationEmployeeId"></span></p>
                            <p><strong>Employee Name:</strong> <span id="notificationEmployeeName"></span></p>
                            <p class="notification-message">The employee data has been sent to the K30 device for fingerprint enrollment.</p>
                            <div class="notification-timer">
                                <span id="countdownTimer">5</span> seconds until you can enroll another employee
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Panel - Employee List -->
            <div class="fingerprint-right-panel">
                <div class="employee-list-container">
                    <div class="employee-list-header">
                        <h3>Available Employees <span class="employee-count-badge">(Showing 5 of many)</span></h3>
                        <div class="right-panel-search">
                            <div class="search-input-wrapper">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" id="employeeSearch" placeholder="Search employees..." oninput="filterEmployees()" autocomplete="off">
                                <button type="button" class="clear-search" onclick="clearSearch()" style="display: none;">
                                    <i class="fas fa-times"></i>
                                </button>
                                <div class="search-loading-indicator" id="searchLoadingIndicator" style="display: none;">
                                    <i class="fas fa-spinner fa-spin"></i>
                                </div>
                            </div>
                        </div>
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i>
                            <strong id="availableCount">Loading...</strong> employees available for enrollment
                </small>
        </div>
        
                    <div class="employee-list" id="employeeList">
                        <!-- Employee list will be populated here -->
            </div>
        </div>
            </div>
        </div>
        
        <div class="fingerprint-footer">
            <button class="fp-btn fp-btn-cancel" onclick="closeFingerprintModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="fp-btn fp-btn-start" id="startFpBtn" onclick="startFingerprintEnrollment()" disabled>
                <i class="fas fa-fingerprint"></i> Send to Device
            </button>
        </div>
    </div>
</div>
    
    

<script>
    // Logout confirmation functions
    function confirmLogout() {
        document.getElementById('logoutModal').style.display = 'block';
        return false; // Prevent default link behavior
    }

    function closeLogoutModal() {
        document.getElementById('logoutModal').style.display = 'none';
    }

    function proceedLogout() {
        // Close modal and proceed with logout
        closeLogoutModal();
        window.location.href = 'logout.php';
    }

    // Close modal when clicking outside of it
    window.onclick = function(event) {
        const modal = document.getElementById('logoutModal');
        if (event.target === modal) {
            closeLogoutModal();
        }
    }

    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeLogoutModal();
        }
    });
    
    // ================== FIXED BUTTON FUNCTIONS ==================

    // Global variables
    let passwordValid = false;
    let passwordsMatch = false;
    let fpEnrollmentInterval = null;
    let fpSelectedEmployeeId = 0;
    let fpSelectedEmployeeName = '';
    let fpScanCount = 0;
    let fpDeviceConnected = false;

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Initializing employee management system...');
        
        // Set up event listeners for password validation
        const passwordInput = document.getElementById('employeePassword');
        const confirmInput = document.getElementById('confirmPassword');
        
        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                validatePassword(this.value);
                checkPasswordMatch();
            });
        }
        
        if (confirmInput) {
            confirmInput.addEventListener('input', checkPasswordMatch);
        }
        
        // Set up fingerprint enrollment event listeners
        // Note: Employee selection is now handled by the selectEmployee function
        // when users click on employee list items
        
        const startBtn = document.getElementById('startFpBtn');
        if (startBtn) {
            startBtn.addEventListener('click', startFingerprintEnrollment);
        }
        
        // Add escape key handler for modals
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modal = document.getElementById('fingerprintModal');
                if (modal && modal.style.display === 'flex') {
                    closeFingerprintModal();
                }
                
                const addModal = document.getElementById('addEmployeeModal');
                if (addModal && addModal.style.display === 'flex') {
                    closeAddEmployeeModal();
                }
                
                const empModal = document.getElementById('employeeModal');
                if (empModal && empModal.style.display === 'flex') {
                    closeModal();
                }
            }
        });
        
        // Initialize department view by default
        showDepartmentView();
        
        // Check if modal should be opened automatically
        const urlParams = new URLSearchParams(window.location.search);
        const showModal = urlParams.get('show_modal');
        
        if (showModal === 'true') {
            setTimeout(() => {
                openFingerprintModal();
            }, 500);
        }
        
        console.log('Employee management system initialized successfully');
    });

    // ================== VIEW TOGGLE FUNCTIONS ==================
    function showDepartmentView() {
        document.getElementById('departmentsView').style.display = 'grid';
        document.getElementById('departmentDetail').style.display = 'none';
        document.getElementById('listView').style.display = 'none';
        const searchBox = document.querySelector('.search-box');
        if (searchBox) searchBox.style.display = 'none';
        
        // Update active button state
        const viewButtons = document.querySelectorAll('.view-toggle .view-btn');
        if (viewButtons.length >= 2) {
            viewButtons[0].classList.remove('active');
            viewButtons[1].classList.add('active');
        }
    }

    function showListView() {
        document.getElementById('departmentsView').style.display = 'none';
        document.getElementById('departmentDetail').style.display = 'none';
        document.getElementById('listView').style.display = 'block';
        const searchBox = document.querySelector('.search-box');
        if (searchBox) searchBox.style.display = 'block';
        // default sort when switching to list view
        const sel = document.getElementById('listSortSelect');
        if (sel) sel.value = 'name_asc';
        if (typeof sortListView === 'function') sortListView();
        
        // Update active button state
        const viewButtons = document.querySelectorAll('.view-toggle .view-btn');
        if (viewButtons.length >= 2) {
            viewButtons[0].classList.add('active');
            viewButtons[1].classList.remove('active');
        }
    }

    // ================== LIST VIEW SORTING ==================
    function sortListView() {
        const table = document.getElementById('employeeListTable');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const sortValue = (document.getElementById('listSortSelect') || {}).value || 'name_asc';

        const parseDate = (v) => {
            if (!v) return 0;
            const d = new Date(v);
            return isNaN(d.getTime()) ? 0 : d.getTime();
        };

        rows.sort((a, b) => {
            const nameA = (a.getAttribute('data-name') || '').toLowerCase();
            const nameB = (b.getAttribute('data-name') || '').toLowerCase();
            const hiredA = parseDate(a.getAttribute('data-hired'));
            const hiredB = parseDate(b.getAttribute('data-hired'));
            const createdA = parseDate(a.getAttribute('data-created'));
            const createdB = parseDate(b.getAttribute('data-created'));

            switch (sortValue) {
                case 'name_desc':
                    return nameA < nameB ? 1 : nameA > nameB ? -1 : 0;
                case 'hired_desc':
                    return hiredB - hiredA; // newest first
                case 'hired_asc':
                    return hiredA - hiredB; // oldest first
                case 'created_desc':
                    return createdB - createdA; // newest first
                case 'created_asc':
                    return createdA - createdB; // oldest first
                case 'name_asc':
                default:
                    return nameA > nameB ? 1 : nameA < nameB ? -1 : 0;
            }
        });

        // Re-append rows in sorted order
        rows.forEach(r => tbody.appendChild(r));
    }

    // ================== EMPLOYEE ID AUTO-FILL ==================
    function autoFillEmployeeID() {
        const employeeIDInput = document.getElementById('employeeID');
        const autoFillBtn = document.getElementById('autoFillBtn');
        
        autoFillBtn.disabled = true;
        autoFillBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        
        fetch('AdminEmployees.php?action=get_next_employee_id')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    showNotification('Error: ' + data.error, 'error', 6000);
                } else if (data.next_employee_id) {
                    employeeIDInput.value = data.next_employee_id;
                    employeeIDInput.style.backgroundColor = '#e8f5e8';
                    employeeIDInput.style.border = '2px solid #4CAF50';
                    
                    // Show success notification
                    showNotification('Employee ID auto-filled successfully: ' + data.next_employee_id, 'success', 4000);
                    
                    setTimeout(() => {
                        employeeIDInput.style.backgroundColor = '';
                        employeeIDInput.style.border = '';
                    }, 2000);
                } else {
                    showNotification('Unexpected response format from server.', 'error', 6000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Failed to auto-fill Employee ID. Please try again or enter manually.', 'error', 6000);
            })
            .finally(() => {
                autoFillBtn.disabled = false;
                autoFillBtn.innerHTML = '<i class="fas fa-magic"></i> Auto-fill';
            });
    }

    // ================== PASSWORD FUNCTIONS ==================
    function togglePasswordVisibility(inputId, toggleId) {
        const passwordInput = document.getElementById(inputId);
        const toggleIcon = document.getElementById(toggleId);
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
    }

    function validatePassword(password) {
        const requirements = {
            length: password.length >= 8,
            uppercase: /[A-Z]/.test(password),
            number: /\d/.test(password)
        };

        updateRequirement('lengthReq', requirements.length);
        updateRequirement('uppercaseReq', requirements.uppercase);
        updateRequirement('numberReq', requirements.number);

        const passwordInput = document.getElementById('employeePassword');
        const allValid = Object.values(requirements).every(req => req);
        
        if (password.length > 0) {
            passwordInput.classList.toggle('valid', allValid);
            passwordInput.classList.toggle('invalid', !allValid);
        } else {
            passwordInput.classList.remove('valid', 'invalid');
        }

        passwordValid = allValid;
        updateSubmitButton();
        return allValid;
    }

    function updateRequirement(elementId, isValid) {
        const element = document.getElementById(elementId);
        const icon = element.querySelector('i');
        
        if (isValid) {
            element.classList.add('valid');
            element.classList.remove('invalid');
            icon.classList.remove('fa-times');
            icon.classList.add('fa-check');
        } else {
            element.classList.add('invalid');
            element.classList.remove('valid');
            icon.classList.remove('fa-check');
            icon.classList.add('fa-times');
        }
    }

    function checkPasswordMatch() {
        const password = document.getElementById('employeePassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        const matchElement = document.getElementById('passwordMatch');
        const confirmInput = document.getElementById('confirmPassword');

        if (confirmPassword.length === 0) {
            matchElement.textContent = '';
            confirmInput.classList.remove('valid', 'invalid');
            passwordsMatch = false;
        } else if (password === confirmPassword) {
            matchElement.textContent = '✓ Passwords match';
            matchElement.classList.add('valid');
            matchElement.classList.remove('invalid');
            confirmInput.classList.add('valid');
            confirmInput.classList.remove('invalid');
            passwordsMatch = true;
        } else {
            matchElement.textContent = '✗ Passwords do not match';
            matchElement.classList.add('invalid');
            matchElement.classList.remove('valid');
            confirmInput.classList.add('invalid');
            confirmInput.classList.remove('valid');
            passwordsMatch = false;
        }

        updateSubmitButton();
    }

    function updateSubmitButton() {
        const submitBtn = document.getElementById('submitBtn');
        const canSubmit = passwordValid && passwordsMatch;
        
        submitBtn.disabled = !canSubmit;
        if (!canSubmit) {
            submitBtn.style.opacity = '0.6';
            submitBtn.style.cursor = 'not-allowed';
        } else {
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
        }
    }

    // ================== MODAL FUNCTIONS ==================
    function openAddEmployeeModal() {
        const modal = document.getElementById('addEmployeeModal');
        const addEmployeeForm = document.getElementById('addEmployeeForm');
        
        if (addEmployeeForm) {
            addEmployeeForm.reset();
            passwordValid = false;
            passwordsMatch = false;
            updateSubmitButton();
            
            document.getElementById('employeePassword').classList.remove('valid', 'invalid');
            document.getElementById('confirmPassword').classList.remove('valid', 'invalid');
            document.getElementById('passwordMatch').textContent = '';
            
            updateRequirement('lengthReq', false);
            updateRequirement('uppercaseReq', false);
            updateRequirement('numberReq', false);
        }
        
        modal.style.display = 'flex';
    }

    function closeAddEmployeeModal() {
        document.getElementById('addEmployeeModal').style.display = 'none';
    }

    function showEmployeeModal(
        employeeID, 
        employeeName, 
        employeeEmail, 
        department, 
        position, 
        shift,
        contact, 
        dateHired,
        lastDayEmployed,
        dateTransferred,
        birthdate, 
        age, 
        status, 
        baseSalary, 
        riceAllowance,
        medicalAllowance,
        laundryAllowance,
        leavePayCounts,
        tin, 
        sss, 
        phic, 
        hdmf, 
        bloodType, 
        presentAddress,
        permanentAddress, 
        areaAssignment, 
        history,
        lengthOfService
    ) {
        // Set header info
        document.getElementById('empName').innerText = employeeName || 'Not Available';
        document.getElementById('empPosition').innerText = position || 'Not Available';
        document.getElementById('empDepartment').innerText = department || 'Not Available';
        
        // Set avatar initials
        const initials = getInitials(employeeName);
        document.getElementById('empAvatar').innerText = initials || 'NA';
        
        // Personal Information Section
        document.getElementById('empID').innerText = employeeID || 'Not Available';
        document.getElementById('empFullName').innerText = employeeName || 'Not Available';
        document.getElementById('empEmail').innerText = employeeEmail || 'Not Available';
        document.getElementById('empContact').innerText = contact || 'Not Available';
        document.getElementById('empBirthdate').innerText = birthdate ? formatDate(birthdate) : 'Not Available';
        document.getElementById('empAge').innerText = age ? age + ' years old' : 'Not Available';
        document.getElementById('empBloodType').innerText = bloodType || 'Not Available';
        // Ensure addresses never display as "0"; fallback: permanent uses present when invalid
        document.getElementById('empPresentAddress').innerText = getSafeAddress(presentAddress, 'Not Available');
        document.getElementById('empPermanentAddress').innerText = getSafeAddress(permanentAddress, getSafeAddress(presentAddress, 'Not Available'));
        
        // Employment Details Section
        document.getElementById('empDateHired').innerText = dateHired ? formatDate(dateHired) : 'Not Available';
        document.getElementById('empLastDay').innerText = (typeof lastDayEmployed !== 'undefined' && lastDayEmployed && lastDayEmployed !== '0000-00-00') ? formatDate(lastDayEmployed) : 'N/A';
        document.getElementById('empTransferred').innerText = (typeof dateTransferred !== 'undefined' && dateTransferred && dateTransferred !== '0000-00-00') ? formatDate(dateTransferred) : 'N/A';
        
        // Calculate years of service from date hired
        if (dateHired && dateHired !== 'Not Available' && dateHired !== '0000-00-00') {
            const yearsOfService = calculateYearsOfService(dateHired);
            if (yearsOfService === 'N/A') {
                document.getElementById('empServiceYears').innerText = 'Not Available';
            } else {
                document.getElementById('empServiceYears').innerText = yearsOfService + ' years';
            }
        } else {
            document.getElementById('empServiceYears').innerText = 'Not Available';
        }
        
        // Set status with appropriate class
        const statusElement = document.getElementById('empStatus');
        statusElement.innerText = status || 'Not Available';
        statusElement.className = 'status-badge';
        if (status && status.toLowerCase() === 'active') {
            statusElement.classList.add('status-active');
        } else {
            statusElement.classList.add('status-inactive');
        }
        
        document.getElementById('empAreaAssignment').innerText = areaAssignment || 'Not Available';
        
        // Handle shift display with NSD differential
        const shiftElement = document.getElementById('empShift');
        const nightShiftElement = document.getElementById('nightShiftDifferential');
        
        if (shift === '22:00-06:00') {
            shiftElement.innerText = 'Night Shift (10:00 PM - 6:00 AM)';
            nightShiftElement.style.display = 'block';
        } else {
            shiftElement.innerText = shift || 'Not Available';
            nightShiftElement.style.display = 'none';
        }
        
        document.getElementById('empSalary').innerText = baseSalary ? Number(baseSalary).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00';
        
        // Set allowances
        document.getElementById('empRiceAllowance').innerText = riceAllowance ? Number(riceAllowance).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00';
        document.getElementById('empMedicalAllowance').innerText = medicalAllowance ? Number(medicalAllowance).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00';
        document.getElementById('empLaundryAllowance').innerText = laundryAllowance ? Number(laundryAllowance).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00';
        document.getElementById('empLeavePayCounts').innerText = leavePayCounts || '10';
        
        // Government IDs Section - Fixed order
        document.getElementById('empTIN').innerText = tin || 'Not Available';
        document.getElementById('empSSS').innerText = sss || 'Not Available';
        document.getElementById('empPHIC').innerText = phic || 'Not Available';
        document.getElementById('empHDMF').innerText = hdmf || 'Not Available';
        
        // Employment History
        document.getElementById('empHistory').innerText = history || 'No employment history available.';
        
        // Show the modal
        document.getElementById('employeeModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('employeeModal').style.display = 'none';
    }

    // Helper function to get initials from name
    function getInitials(name) {
        if (!name || name === 'Not Available') return '';
        const names = name.split(' ');
        let initials = names[0].substring(0, 1).toUpperCase();
        if (names.length > 1) {
            initials += names[names.length - 1].substring(0, 1).toUpperCase();
        }
        return initials;
    }

    // Helper function to calculate years of service
    function calculateYearsOfService(hiredDate) {
        if (!hiredDate || hiredDate === '0000-00-00' || hiredDate === '') {
            return 'N/A';
        }
        
        try {
            const hired = new Date(hiredDate);
            // Check if the date is valid
            if (isNaN(hired.getTime())) {
                return 'N/A';
            }
            
            const today = new Date();
            let years = today.getFullYear() - hired.getFullYear();
            const monthDiff = today.getMonth() - hired.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < hired.getDate())) {
                years--;
            }
            
            // Return 0 if less than 1 year, otherwise return actual years
            return years < 0 ? 0 : years;
        } catch (e) {
            console.error('Error calculating years of service:', e);
            return 'N/A';
        }
    }

    // ================== SEARCH FUNCTIONALITY ==================
    document.getElementById('searchInput').addEventListener('keyup', function() {
        const input = this.value.toLowerCase();
        const rows = document.querySelectorAll('#listView tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(input) ? '' : 'none';
        });
    });

    // ================== DEPARTMENT VIEW FUNCTIONS ==================
    function showDepartmentDetail(department) {
        document.getElementById('departmentsView').style.display = 'none';
        document.getElementById('departmentDetail').style.display = 'block';
        document.getElementById('departmentTitle').textContent = department + ' Employees';
        
        const tableBody = document.getElementById('departmentEmployeesTable');
        tableBody.innerHTML = '';
        
        const deptEmployees = (<?php echo json_encode($employeesByDepartment); ?>[department] || [])
            .filter(emp => (emp.Status || '').toLowerCase() !== 'archived');
        
        if (deptEmployees.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="7" class="no-employees">No employees found in this department</td></tr>';
            return;
        }
        
        deptEmployees.forEach(emp => {
            const row = document.createElement('tr');
            row.onclick = () => showEmployeeModal(
                emp.EmployeeID || '',
                emp.EmployeeName || '',
                emp.EmployeeEmail || '',
                emp.Department || '',
                emp.Position || '',
                emp.Shift || 'Not Available',
                emp.Contact || 'Not Available',
                emp.DateHired || 'Not Available',
                emp.LastDayEmployed || '',
                emp.DateTransferred || '',
                emp.Birthdate || 'Not Available',
                emp.Age || '',
                emp.Status || '',
                emp.base_salary ? Number(emp.base_salary).toFixed(2) : '0.00',
                emp.rice_allowance || 0,
                emp.medical_allowance || 0,
                emp.laundry_allowance || 0,
                emp.leave_pay_counts || 10,
                emp.TIN || 'Not Available',
                emp.SSS || 'Not Available',
                emp.PHIC || 'Not Available',
                emp.HDMF || 'Not Available',
                emp.BloodType || 'Not Available',
                emp.PresentHomeAddress || 'Not Available',
                emp.PermanentHomeAddress || 'Not Available',
                emp.AreaOfAssignment || 'Not Available',
                emp.history || 'No history available',
                emp.LengthOfService || 'Not Available'
            );
            
            row.innerHTML = `
                <td>${emp.EmployeeID || ''}</td>
                <td>${emp.EmployeeName || ''}</td>
                <td>${emp.EmployeeEmail || ''}</td>
                <td>${emp.Position || ''}</td>
                <td>₱${emp.base_salary ? Number(emp.base_salary).toFixed(2) : '0.00'}</td>
                <td><span class="status-badge status-badge-table ${(emp.Status || '').toLowerCase() === 'active' ? 'status-active' : 'status-inactive'}">${emp.Status || ''}</span></td>
                <td>
                    <div class="action-buttons">
                        <a href="AdminEdit.php?id=${emp.EmployeeID}" class="action-btn edit-btn" onclick="event.stopPropagation();">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <form method="POST" action="AdminEmployees.php" style="display:inline;" onsubmit="event.stopPropagation(); return confirm('Are you sure you want to archive this employee?');">
                            <input type="hidden" name="archive_employee" value="1" />
                            <input type="hidden" name="employee_id" value="${emp.EmployeeID}" />
                            <button type="submit" class="action-btn delete-btn">
                                <i class="fas fa-trash"></i> Archive
                            </button>
                        </form>
                    </div>
                </td>
            `;
            tableBody.appendChild(row);
        });
    }

    // ================== FINGERPRINT ENROLLMENT FUNCTIONS ==================
    
    function openFingerprintModal() {
    console.log('Opening fingerprint enrollment modal...');
    
    const modal = document.getElementById('fingerprintModal');
    if (!modal) {
        console.error('Fingerprint modal not found!');
        alert('Fingerprint modal not found. Please check if the modal HTML is properly loaded.');
        return;
    }
    
    // Reset modal state
    resetFingerprintModal();
    
    // Show the modal
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Load default employees after modal is shown
    setTimeout(() => {
        loadDefaultEmployees();
    }, 100);
    
    // Also refresh the list when modal opens to ensure it's up-to-date
    setTimeout(() => {
        loadDefaultEmployees();
    }, 300);
    
    // Check if there's a pending enrollment notification
    const urlParams = new URLSearchParams(window.location.search);
    const enrollmentSuccess = urlParams.get('enrollment_success');
    const employeeId = urlParams.get('employee_id');
    const employeeName = urlParams.get('employee_name');
    
    if (enrollmentSuccess === 'true' && employeeId && employeeName) {
        setTimeout(() => {
            showEnrollmentNotification(employeeId, decodeURIComponent(employeeName));
        }, 500);
    }
    
    console.log('Fingerprint modal opened successfully');
}

function resetFingerprintModal() {
    console.log('Resetting fingerprint modal...');
    
    // Reset employee list selection
    document.querySelectorAll('.employee-list-item').forEach(item => {
        item.classList.remove('selected');
    });
    
    // Reset status container
    const container = document.getElementById('scanStatusContainer');
    if (container) {
        container.className = 'scan-status-container ready';
        container.style.display = 'block';
        container.innerHTML = `
            <div class="fingerprint-animation">
                <i class="fas fa-fingerprint"></i>
            </div>
            <div class="scan-status-text">Ready to Enroll</div>
            <div class="scan-message">Select an employee from the list to send their data to the K30 device for fingerprint enrollment.</div>
        `;
    }
    
    // Hide notification
    const notification = document.getElementById('enrollmentNotification');
    if (notification) {
        notification.style.display = 'none';
    }
    
    // Reset start button
    const startBtn = document.getElementById('startFpBtn');
    if (startBtn) {
        startBtn.disabled = true;
        startBtn.innerHTML = '<i class="fas fa-fingerprint"></i> Select Employee';
    }
    
    // Reset global variables
    fpSelectedEmployeeId = 0;
    fpSelectedEmployeeName = '';
    
    console.log('Fingerprint modal reset complete');
}
// Note: validateEmployeeSelection function removed as it was for dropdown-based selection
// The current system uses selectEmployee() function for list-based selection

function startFingerprintEnrollment() {
    console.log('Starting fingerprint enrollment...');
    
    const container = document.getElementById('scanStatusContainer');
    const startBtn = document.getElementById('startFpBtn');
    
    // Validate elements exist
    if (!container || !startBtn) {
        console.error('Required elements not found');
        showNotification('Required elements not found. Please refresh the page and try again.', 'error', 6000);
        return;
    }
    
    // Validate selection - check if an employee is selected
    if (!fpSelectedEmployeeId || !fpSelectedEmployeeName) {
        showNotification('Please select an employee first', 'warning', 4000);
        return;
    }
    
    console.log(`Starting enrollment for Employee ${fpSelectedEmployeeId}: ${fpSelectedEmployeeName}`);
    
    // Update UI to show sending data
    container.className = 'scan-status-container sending';
    container.innerHTML = `
        <div class="fingerprint-animation">
            <i class="fas fa-paper-plane"></i>
        </div>
        <div class="scan-status-text">Sending Data to Device</div>
        <div class="scan-message">Sending employee data to K30 device</div>
        <div class="scan-details">
            <strong>ID:</strong> ${fpSelectedEmployeeId}<br>
            <strong>Name:</strong> ${fpSelectedEmployeeName}
        </div>
    `;
    
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    
    // Use AJAX to submit enrollment without closing modal
    const formData = new FormData();
    formData.append('employee_id', fpSelectedEmployeeId);
    formData.append('employee_name', fpSelectedEmployeeName);
    formData.append('enroll_fingerprint', '1');
    
    fetch('AdminEmployees.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success notification
            showEnrollmentNotification(data.employee_id, data.employee_name);
        } else {
            throw new Error(data.message || 'Enrollment failed');
        }
    })
    .catch(error => {
        console.error('Enrollment error:', error);
        // Show error state
        container.innerHTML = `
            <div class="fingerprint-animation error">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="scan-status-text">Enrollment Failed</div>
            <div class="scan-message">There was an error sending the employee data. Please try again.</div>
        `;
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-fingerprint"></i> Send to Device';
    });
}

function closeFingerprintModal() {
    console.log('Closing fingerprint modal...');
    
    const modal = document.getElementById('fingerprintModal');
    if (modal) {
        modal.style.display = 'none';
    }
    document.body.style.overflow = '';
    
    fpSelectedEmployeeId = 0;
    fpSelectedEmployeeName = '';
    
    // Hide notification if visible
    const notification = document.getElementById('enrollmentNotification');
    if (notification) {
        notification.style.display = 'none';
    }
    
    console.log('Fingerprint modal closed');
}

// AJAX Search functionality for employees
let searchTimeout;
let activeRequest = null;

function filterEmployees() {
    const searchInput = document.getElementById('employeeSearch');
    const clearBtn = document.querySelector('.clear-search');
    if (!searchInput) return;

    const searchTerm = searchInput.value.trim();

    // Show/hide clear button
    if (clearBtn) {
        clearBtn.style.display = searchTerm ? 'flex' : 'none';
    }

    // Clear any previous timeout
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    // Cancel ongoing request if user types again
    if (activeRequest) {
        activeRequest.abort();
        activeRequest = null;
    }

    // If search box is empty, reload defaults quickly
    if (!searchTerm) {
        loadDefaultEmployees();
        return;
    }

    // Wait 300ms after user stops typing before searching
    // This prevents irritation from too many requests
    searchTimeout = setTimeout(() => {
        performAjaxSearch(searchTerm);
    }, 300);
}



function loadDefaultEmployees() {
    const employeeList = document.getElementById('employeeList');
    employeeList.innerHTML = '<div class="employee-list-item"><div class="employee-info"><div class="employee-name">Loading employees...</div></div></div>';
    
    // Load all available employees (not enrolled)
    fetch('ajax_search_employees.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'search_term=',
        credentials: 'same-origin' // Include cookies for session
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.employees) {
            populateEmployeeList(data.employees, '');
            updateAvailableCount(data.count || data.employees.length);
        } else {
            employeeList.innerHTML = '<div class="employee-list-item"><div class="employee-info"><div class="employee-name">Error: ' + (data.error || 'Unknown error') + '</div></div></div>';
        }
    })
    .catch(error => {
        employeeList.innerHTML = '<div class="employee-list-item"><div class="employee-info"><div class="employee-name">Error loading employees</div></div></div>';
    });
}

function populateEmployeeList(employees, searchTerm = '') {
    const employeeList = document.getElementById('employeeList');
    employeeList.innerHTML = '';
    
    // Update the count badge
    const countBadge = document.querySelector('.employee-count-badge');
    if (countBadge) {
        if (searchTerm) {
            countBadge.textContent = `(${employees.length} found)`;
        } else {
            countBadge.textContent = `(Showing ${employees.length} of many)`;
        }
    }
    
    if (employees.length === 0) {
        employeeList.innerHTML = '<div class="employee-list-item"><div class="employee-info"><div class="employee-name">No employees found</div></div></div>';
        return;
    }
    
    // Sort employees based on search term relevance
    const sortedEmployees = employees.sort((a, b) => {
        if (!searchTerm) return 0; // No sorting if no search term
        
        const aName = a.name.toLowerCase();
        const bName = b.name.toLowerCase();
        const aId = a.id.toString();
        const bId = b.id.toString();
        const search = searchTerm.toLowerCase();
        
        // Exact name match gets highest priority
        if (aName === search) return -1;
        if (bName === search) return 1;
        
        // Name starts with search term
        if (aName.startsWith(search) && !bName.startsWith(search)) return -1;
        if (bName.startsWith(search) && !aName.startsWith(search)) return 1;
        
        // Name contains search term
        if (aName.includes(search) && !bName.includes(search)) return -1;
        if (bName.includes(search) && !aName.includes(search)) return 1;
        
        // ID matches
        if (aId.includes(search) && !bId.includes(search)) return -1;
        if (bId.includes(search) && !aId.includes(search)) return 1;
        
        return 0;
    });
    
    sortedEmployees.forEach(employee => {
        const listItem = document.createElement('div');
        listItem.className = 'employee-list-item';
        listItem.setAttribute('data-employee-id', employee.id);
        listItem.setAttribute('data-employee-name', employee.name);
        listItem.onclick = () => selectEmployee(employee.id, employee.name);
        
        // Create avatar with first letter of name
        const avatar = document.createElement('div');
        avatar.className = 'employee-avatar';
        avatar.textContent = employee.name.charAt(0).toUpperCase();
        
        // Create employee info
        const employeeInfo = document.createElement('div');
        employeeInfo.className = 'employee-info';
        
        const employeeName = document.createElement('div');
        employeeName.className = 'employee-name';
        employeeName.textContent = employee.name;
        
        const employeeId = document.createElement('div');
        employeeId.className = 'employee-id';
        employeeId.textContent = 'ID: ' + employee.id;
        
        const employeeStatus = document.createElement('div');
        employeeStatus.className = 'employee-status';
        employeeStatus.textContent = 'Available';
        
        employeeInfo.appendChild(employeeName);
        employeeInfo.appendChild(employeeId);
        employeeInfo.appendChild(employeeStatus);
        
        listItem.appendChild(avatar);
        listItem.appendChild(employeeInfo);
        
        employeeList.appendChild(listItem);
    });
}

function selectEmployee(employeeId, employeeName) {
    // Remove previous selection
    document.querySelectorAll('.employee-list-item').forEach(item => {
        item.classList.remove('selected');
    });
    
    // Add selection to clicked item
    const selectedItem = document.querySelector(`[data-employee-id="${employeeId}"]`);
    if (selectedItem) {
        selectedItem.classList.add('selected');
    }
    
    // Update global variables
    fpSelectedEmployeeId = parseInt(employeeId);
    fpSelectedEmployeeName = employeeName;
    
    // Enable the start button
    const startBtn = document.getElementById('startFpBtn');
    if (startBtn) {
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-fingerprint"></i> Send to Device';
    }
    
    // Update status message
    const statusContainer = document.getElementById('scanStatusContainer');
    if (statusContainer) {
        statusContainer.innerHTML = `
            <div class="fingerprint-animation">
                <i class="fas fa-fingerprint"></i>
            </div>
            <div class="scan-status-text">Ready to Enroll</div>
            <div class="scan-message">Selected: <strong>${employeeName}</strong> (ID: ${employeeId})<br>Click "Send to Device" to proceed with fingerprint enrollment.</div>
        `;
    }
    
    console.log(`Selected Employee: ID=${employeeId}, Name=${employeeName}`);
}

function performAjaxSearch(searchTerm) {
    const controller = new AbortController();
    activeRequest = controller;

    const employeeList = document.getElementById('employeeList');
    const searchInput = document.getElementById('employeeSearch');
    const loadingIndicator = document.getElementById('searchLoadingIndicator');
    
    if (!employeeList) {
        return;
    }
    
    // Show loading state with minimal disruption
    employeeList.innerHTML = '<div class="employee-list-item"><div class="employee-info"><div class="employee-name">Searching...</div></div></div>';
    if (loadingIndicator) {
        loadingIndicator.style.display = 'block';
    }

    fetch('ajax_search_employees.php', {
        method: 'POST',
        body: new URLSearchParams({ search_term: searchTerm }),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        signal: controller.signal,
        credentials: 'same-origin' // Include cookies for session
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            populateEmployeeList(data.employees, searchTerm);
            updateAvailableCount(data.count || data.employees.length);
        } else {
            console.error('Search failed:', data.error);
            employeeList.innerHTML = '<div class="employee-list-item"><div class="employee-info"><div class="employee-name">Error: ' + (data.error || 'Unknown error') + '</div></div></div>';
        }
    })
    .catch(err => {
        if (err.name !== 'AbortError') {
            console.error('Search request failed:', err);
            employeeList.innerHTML = '<div class="employee-list-item"><div class="employee-info"><div class="employee-name">Error loading employees</div></div></div>';
        }
    })
    .finally(() => {
        // Reset loading state
        if (loadingIndicator) {
            loadingIndicator.style.display = 'none';
        }
        // Reset active request when done
        if (activeRequest === controller) activeRequest = null;
    });
}

function updateAvailableCount(count) {
    const countElement = document.getElementById('availableCount');
    if (countElement) {
        countElement.textContent = count;
    }
}

// Function to remove a specific employee from the current list
function removeEmployeeFromList(employeeId) {
    const employeeItems = document.querySelectorAll('.employee-list-item');
    employeeItems.forEach(item => {
        const employeeIdElement = item.querySelector('[data-employee-id]');
        if (employeeIdElement && employeeIdElement.getAttribute('data-employee-id') == employeeId) {
            item.remove();
        }
    });
    
    // Update the count badge
    const countBadge = document.querySelector('.employee-count-badge');
    if (countBadge) {
        const remainingItems = document.querySelectorAll('.employee-list-item').length;
        countBadge.textContent = `(Showing ${remainingItems} of many)`;
    }
}

function clearSearch() {
    const searchInput = document.getElementById('employeeSearch');
    const clearBtn = document.querySelector('.clear-search');
    
    if (searchInput) {
        searchInput.value = '';
        searchInput.focus();
    }
    
    if (clearBtn) {
        clearBtn.style.display = 'none';
    }
    
    // Load default employees when search is cleared
    loadDefaultEmployees();
}

// Show enrollment notification in modal
function showEnrollmentNotification(employeeId, employeeName) {
    const notification = document.getElementById('enrollmentNotification');
    const employeeIdSpan = document.getElementById('notificationEmployeeId');
    const employeeNameSpan = document.getElementById('notificationEmployeeName');
    
    if (notification && employeeIdSpan && employeeNameSpan) {
        // Immediately remove the enrolled employee from the current list
        removeEmployeeFromList(employeeId);
        
        employeeIdSpan.textContent = employeeId;
        employeeNameSpan.textContent = employeeName;
        notification.style.display = 'block';
        
        // Hide the scan status container
        const scanStatusContainer = document.getElementById('scanStatusContainer');
        if (scanStatusContainer) {
            scanStatusContainer.style.display = 'none';
        }
        
        // Auto-hide the green container after 5 seconds
        setTimeout(() => {
            notification.style.display = 'none';
            // Reset the modal state after hiding
            resetFingerprintModal();
            // Refresh the employee list to remove enrolled employees
            loadDefaultEmployees();
        }, 5000);
        
        // Start countdown timer for user feedback
        startCountdownTimer();
    }
}

function startCountdownTimer() {
    let countdown = 5;
    const timerElement = document.getElementById('countdownTimer');
    
    const countdownInterval = setInterval(() => {
        countdown--;
        if (timerElement) {
            timerElement.textContent = countdown;
        }
        
        if (countdown <= 0) {
            clearInterval(countdownInterval);
            // Timer will be handled by the auto-hide timeout
        }
    }, 1000);
}

function showNotification(message, type = 'info', duration = 5000) {
    // Remove existing notifications of the same type
    const existingNotifications = document.querySelectorAll(`.notification-popup.${type}`);
    existingNotifications.forEach(notification => notification.remove());
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification-popup ${type}`;
    
    // Set icon based on type
    const iconMap = {
        success: 'circle-check',
        error: 'triangle-exclamation',
        warning: 'circle-exclamation',
        info: 'circle-info'
    };
    const iconClass = iconMap[type] || 'circle-info';
    
    // Create notification content
    notification.innerHTML = `
        <div class="notification-content">
            <div class="notification-icon">
                <i class="fas fa-${iconClass}"></i>
            </div>
            <div class="notification-message">
                <div class="notification-title">${type.charAt(0).toUpperCase() + type.slice(1)}</div>
                <div class="notification-text">${message}</div>
            </div>
            <button class="notification-close" onclick="this.closest('.notification-popup').remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    // Add to body
    document.body.appendChild(notification);
    
    // Animate in
    setTimeout(() => notification.classList.add('show'), 10);
    
    // Auto remove after duration
    if (duration > 0) {
        setTimeout(() => {
            if (notification.parentNode) {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }
        }, duration);
    }
}


// ================== FORM VALIDATION ==================
function validateEmployeeForm() {
    const form = document.getElementById('addEmployeeForm');
    if (!form) return false;
    
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.style.borderColor = 'red';
            isValid = false;
        } else {
            field.style.borderColor = '';
        }
    });
    
    if (!isValid) {
        showNotification('Please fill in all required fields.', 'error', 6000);
        return false;
    }
    
    if (!passwordValid) {
        showNotification('Please ensure the password meets all requirements.', 'error', 6000);
        return false;
    }
    
    if (!passwordsMatch) {
        showNotification('Passwords do not match.', 'error', 6000);
        return false;
    }
    
    return true;
}

    // ================== EXPORT FUNCTIONS ==================
    function exportToExcel() {
        console.log('Exporting to Excel...');
        
        const table = document.getElementById('employeeTable');
        if (!table) {
            alert('Employee table not found!');
            return;
        }
        
        let csv = [];
        const rows = table.querySelectorAll('tr');
        
        for (let i = 0; i < rows.length; i++) {
            let row = [], cols = rows[i].querySelectorAll('td, th');
            
            for (let j = 0; j < cols.length; j++) {
                // Remove action buttons from export
                if (cols[j].querySelector('.action-buttons')) {
                    continue;
                }
                
                let text = cols[j].innerText;
                text = text.replace(/"/g, '""'); // Escape quotes
                row.push('"' + text + '"');
            }
            csv.push(row.join(','));
        }
        
        const csvString = csv.join('\n');
        const filename = 'employees_export_' + new Date().toISOString().slice(0, 10) + '.csv';
        
        const link = document.createElement('a');
        link.style.display = 'none';
        link.setAttribute('target', '_blank');
        link.setAttribute('href', 'data:text/csv;charset=utf-8,' + encodeURIComponent(csvString));
        link.setAttribute('download', filename);
        document.body.appendChild(link);
        
        link.click();
        document.body.removeChild(link);
        
        showNotification('Employee data exported successfully!', 'success');
    }

    function exportToPDF() {
        console.log('Exporting to PDF...');
        showNotification('PDF export feature is coming soon!', 'info');
    }

    // ================== UTILITY FUNCTIONS ==================
    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2
        }).format(amount);
    }

    function formatDate(dateString) {
        if (!dateString || dateString === '0000-00-00' || dateString === '') return 'N/A';
        
        try {
            const date = new Date(dateString);
            // Check if the date is valid
            if (isNaN(date.getTime())) {
                return 'Invalid Date';
            }
            return date.toLocaleDateString('en-PH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        } catch (e) {
            return 'Invalid Date';
        }
    }

    // Address display helper: treat '0' or empty as invalid and use fallback
    function getSafeAddress(value, fallback) {
        if (value === undefined || value === null) return fallback || 'Not Available';
        const v = String(value).trim();
        if (v === '' || v === '0') return fallback || 'Not Available';
        return v;
    }

    function calculateAge(birthdate) {
        if (!birthdate) return 'N/A';
        
        try {
            const birthDate = new Date(birthdate);
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const m = today.getMonth() - birthDate.getMonth();
            
            if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            
            return age;
        } catch (e) {
            return 'N/A';
        }
    }

    // ================== ERROR HANDLING ==================
    // Error handler removed to prevent false error notifications on page refresh

    //
    // ================== AGE CALCULATION FUNCTION ==================
function updateAgeFromBirthdate() {
    const birthdateInput = document.getElementById('birthdate');
    const ageInput = document.getElementById('age');
    
    if (birthdateInput && ageInput && birthdateInput.value) {
        const birthdate = new Date(birthdateInput.value);
        const today = new Date();
        
        let age = today.getFullYear() - birthdate.getFullYear();
        const monthDiff = today.getMonth() - birthdate.getMonth();
        
        // Adjust age if birthday hasn't occurred this year
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthdate.getDate())) {
            age--;
        }
        
        // Set the calculated age
        ageInput.value = age >= 0 ? age : '';
    }
}



// ================== INPUT MASKING FUNCTIONS ==================
function formatTIN(input) {
    // Get cursor position
    const cursorPosition = input.selectionStart;
    
    // Remove all non-digits
    let value = input.value.replace(/\D/g, '');
    
    // Limit to 12 digits
    if (value.length > 12) {
        value = value.substring(0, 12);
    }
    
    // Apply format: ***-***-***-***
    if (value.length > 9) {
        value = value.substring(0, 3) + '-' + value.substring(3, 6) + '-' + value.substring(6, 9) + '-' + value.substring(9, 12);
    } else if (value.length > 6) {
        value = value.substring(0, 3) + '-' + value.substring(3, 6) + '-' + value.substring(6, 9);
    } else if (value.length > 3) {
        value = value.substring(0, 3) + '-' + value.substring(3, 6);
    }
    
    // Update input value
    input.value = value;
    
    // Calculate new cursor position
    let newCursorPosition = cursorPosition;
    const oldLength = input.value.length;
    const newLength = value.length;
    
    if (newLength > oldLength) {
        newCursorPosition++;
    }
    
    // Restore cursor position
    setTimeout(() => {
        input.setSelectionRange(newCursorPosition, newCursorPosition);
    }, 0);
}

function formatSSS(input) {
    const oldValue = input.value;
    const oldCursor = input.selectionStart;
    let value = input.value.replace(/\D/g, '');
    if (value.length > 10) value = value.substring(0, 10);
    
    let formatted = '';
    if (value.length > 0) {
        formatted = value.substring(0, 2);
        if (value.length > 2) formatted += '-' + value.substring(2, 9);
        if (value.length > 9) formatted += '-' + value.substring(9, 10);
    }
    
    input.value = formatted;
    
    if (oldValue !== formatted) {
        let newCursor = oldCursor;
        const dashesBefore = (oldValue.substring(0, oldCursor).match(/-/g) || []).length;
        const dashesAfter = (formatted.substring(0, oldCursor).match(/-/g) || []).length;
        newCursor += (dashesAfter - dashesBefore);
        newCursor = Math.max(0, Math.min(newCursor, formatted.length));
        input.setSelectionRange(newCursor, newCursor);
    }
}

function formatPHIC(input) {
    const oldValue = input.value;
    const oldCursor = input.selectionStart;
    let value = input.value.replace(/\D/g, '');
    if (value.length > 12) value = value.substring(0, 12);
    
    let formatted = '';
    if (value.length > 0) {
        formatted = value.substring(0, 2);
        if (value.length > 2) formatted += '-' + value.substring(2, 11);
        if (value.length > 11) formatted += '-' + value.substring(11, 12);
    }
    
    input.value = formatted;
    
    if (oldValue !== formatted) {
        let newCursor = oldCursor;
        const dashesBefore = (oldValue.substring(0, oldCursor).match(/-/g) || []).length;
        const dashesAfter = (formatted.substring(0, oldCursor).match(/-/g) || []).length;
        newCursor += (dashesAfter - dashesBefore);
        newCursor = Math.max(0, Math.min(newCursor, formatted.length));
        input.setSelectionRange(newCursor, newCursor);
    }
}

function formatHDMF(input) {
    const oldValue = input.value;
    const oldCursor = input.selectionStart;
    let value = input.value.replace(/\D/g, '');
    if (value.length > 12) value = value.substring(0, 12);
    
    let formatted = '';
    if (value.length > 0) {
        formatted = value.substring(0, 4);
        if (value.length > 4) formatted += '-' + value.substring(4, 8);
        if (value.length > 8) formatted += '-' + value.substring(8, 12);
    }
    
    input.value = formatted;
    
    if (oldValue !== formatted) {
        let newCursor = oldCursor;
        const dashesBefore = (oldValue.substring(0, oldCursor).match(/-/g) || []).length;
        const dashesAfter = (formatted.substring(0, oldCursor).match(/-/g) || []).length;
        newCursor += (dashesAfter - dashesBefore);
        newCursor = Math.max(0, Math.min(newCursor, formatted.length));
        input.setSelectionRange(newCursor, newCursor);
    }
}

// ================== VALIDATION FUNCTIONS ==================
function validateTIN(tin) {
    // Remove dashes for validation
    const cleanTIN = tin.replace(/\D/g, '');
    return cleanTIN.length === 12;
}

function validateSSS(sss) {
    // Remove dashes for validation
    const cleanSSS = sss.replace(/\D/g, '');
    return cleanSSS.length === 10;
}

function validatePHIC(phic) {
    // Remove dashes for validation
    const cleanPHIC = phic.replace(/\D/g, '');
    return cleanPHIC.length === 12;
}

function validateHDMF(hdmf) {
    // Remove dashes for validation
    const cleanHDMF = hdmf.replace(/\D/g, '');
    return cleanHDMF.length === 12;
}

// ================== INITIALIZATION ==================
document.addEventListener('DOMContentLoaded', function() {
    // Auto-show employee modal if view_employee parameter is present
    <?php if ($employeeToView): ?>
    // Show the employee modal with the fetched data
    showEmployeeModal(
        '<?php echo addslashes($employeeToView['EmployeeID']); ?>',
        '<?php echo addslashes($employeeToView['EmployeeName']); ?>',
        '<?php echo addslashes($employeeToView['EmployeeEmail']); ?>',
        '<?php echo addslashes($employeeToView['Department']); ?>',
        '<?php echo addslashes($employeeToView['Position']); ?>',
        '<?php echo addslashes($employeeToView['Shift'] ?? 'Not Available'); ?>',
        '<?php echo addslashes($employeeToView['Contact'] ?? 'Not Available'); ?>',
        '<?php echo addslashes($employeeToView['DateHired']); ?>',
        '<?php echo addslashes($employeeToView['LastDayEmployed'] ?? ''); ?>',
        '<?php echo addslashes($employeeToView['DateTransferred'] ?? ''); ?>',
        '<?php echo addslashes($employeeToView['Birthdate']); ?>',
        '<?php echo addslashes($employeeToView['Age']); ?>',
        '<?php echo addslashes($employeeToView['Status']); ?>',
        '<?php echo number_format($employeeToView['base_salary'] ?? 0, 2); ?>',
        '<?php echo $employeeToView['rice_allowance'] ?? 0; ?>',
        '<?php echo $employeeToView['medical_allowance'] ?? 0; ?>',
        '<?php echo $employeeToView['laundry_allowance'] ?? 0; ?>',
        '<?php echo $employeeToView['leave_pay_counts'] ?? 10; ?>',
        '<?php echo addslashes($employeeToView['TIN'] ?? 'Not Available'); ?>',
        '<?php echo addslashes($employeeToView['SSS'] ?? 'Not Available'); ?>',
        '<?php echo addslashes($employeeToView['PHIC'] ?? 'Not Available'); ?>',
        '<?php echo addslashes($employeeToView['HDMF'] ?? 'Not Available'); ?>',
        '<?php echo addslashes($employeeToView['BloodType'] ?? 'Not Available'); ?>',
        '<?php echo addslashes($employeeToView['PresentHomeAddress'] ?? 'Not Available'); ?>',
        '<?php echo addslashes($employeeToView['PermanentHomeAddress'] ?? 'Not Available'); ?>',
        '<?php echo addslashes($employeeToView['AreaOfAssignment'] ?? 'Not Available'); ?>',
        '<?php echo addslashes($employeeToView['history'] ?? 'No history available'); ?>',
        '<?php echo addslashes($employeeToView['LengthOfService'] ?? 'Not Available'); ?>'
    );
    
    // Clean up URL by removing the view_employee parameter
    if (window.history.replaceState) {
        const url = new URL(window.location);
        url.searchParams.delete('view_employee');
        window.history.replaceState({}, '', url);
    }
    <?php endif; ?>
    
    // Add event listener for birthdate change
    const birthdateInput = document.getElementById('birthdate');
    if (birthdateInput) {
        birthdateInput.addEventListener('change', updateAgeFromBirthdate);
        birthdateInput.addEventListener('input', updateAgeFromBirthdate);
    }
    
    // Add event listener for date hired change to auto-calculate length of service
    const dateHiredInput = document.getElementById('dateHired');
    if (dateHiredInput) {
        dateHiredInput.addEventListener('change', updateLengthOfServiceFromDateHired);
        dateHiredInput.addEventListener('input', updateLengthOfServiceFromDateHired);
    }
    
    
    // Function to calculate length of service from date hired
    function updateLengthOfServiceFromDateHired() {
        const dateHiredInput = document.getElementById('dateHired');
        const lengthOfServiceInput = document.getElementById('lengthOfService');
        
        if (dateHiredInput && lengthOfServiceInput && dateHiredInput.value) {
            const dateHired = new Date(dateHiredInput.value);
            const today = new Date();
            
            let years = today.getFullYear() - dateHired.getFullYear();
            const monthDiff = today.getMonth() - dateHired.getMonth();
            
            // Adjust years if the anniversary hasn't occurred this year
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dateHired.getDate())) {
                years--;
            }
            
            // Set the calculated length of service (0 if less than 1 year)
            lengthOfServiceInput.value = years < 0 ? 0 : years;
        }
    }
    
    
    // Add input masking for government IDs
    const tinInput = document.getElementById('tin');
    if (tinInput) {
        tinInput.addEventListener('input', function() {
            formatTIN(this);
        });
    }
    
    const sssInput = document.getElementById('sss');
    if (sssInput) {
        sssInput.addEventListener('input', function() {
            formatSSS(this);
        });
    }
    
    const phicInput = document.getElementById('phic');
    if (phicInput) {
        phicInput.addEventListener('input', function() {
            formatPHIC(this);
        });
    }
    
    const hdmfInput = document.getElementById('hdmf');
    if (hdmfInput) {
        hdmfInput.addEventListener('input', function() {
            formatHDMF(this);
        });
    }
    
    // Update form validation to include government ID validation
    if (typeof window.validateEmployeeForm === 'function') {
        const originalValidateEmployeeForm = window.validateEmployeeForm;
        window.validateEmployeeForm = function() {
            if (!originalValidateEmployeeForm()) {
                return false;
            }
            
            // Additional validation for government IDs
            const tin = document.getElementById('tin').value;
            const sss = document.getElementById('sss').value;
            const phic = document.getElementById('phic').value;
            const hdmf = document.getElementById('hdmf').value;
            
            if (!validateTIN(tin)) {
                showNotification('TIN must be 12 digits in the format XXX-XXX-XXX-XXX', 'error', 6000);
                return false;
            }
            
            if (!validateSSS(sss)) {
                showNotification('SSS must be 10 digits in the format XX-XXXXXXX-X', 'error', 6000);
                return false;
            }
            
            if (!validatePHIC(phic)) {
                showNotification('PHIC must be 12 digits in the format XX-XXXXXXXXX-X', 'error', 6000);
                return false;
            }
            
            if (!validateHDMF(hdmf)) {
                showNotification('HDMF must be 12 digits in the format XXXX-XXXX-XXXX', 'error', 6000);
                return false;
            }
            
            return true;
        };
    }
    
    // Removed copyPresentToPermanent feature (checkbox no longer present)
    
    // Auto-update permanent address when present address changes (if checkbox is checked)
    // Checkbox logic removed; manual entry only
    
    // Ensure permanent address is set before form submission if checkbox is checked
    const addEmployeeForm = document.getElementById('addEmployeeForm');
    if (addEmployeeForm) {
        addEmployeeForm.addEventListener('submit', function(e) {
            // No checkbox anymore; server-side safeguard remains
        });
    }
});
    </script>

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="logout-modal">
        <div class="logout-modal-content">
            <div class="logout-modal-header">
                <h3><i class="fas fa-sign-out-alt"></i> Confirm Logout</h3>
                <span class="close" onclick="closeLogoutModal()">&times;</span>
            </div>
            <div class="logout-modal-body">
                <div class="icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <p>Are you sure you want to logout?<br>This will end your current session and you'll need to login again.</p>
            </div>
            <div class="logout-modal-footer">
                <button class="logout-modal-btn cancel" onclick="closeLogoutModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="logout-modal-btn confirm" onclick="proceedLogout()">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>
    </div>

</body>
</html>