<?php
/**
 * Script to help fix PHP socket extension
 */

echo "<h2>PHP Socket Extension Fix</h2>";

// Check current status
if (function_exists('socket_create')) {
    echo "<p style='color: green;'>✓ PHP socket extension is ENABLED</p>";
    echo "<p>You can use direct TCP enrollment.</p>";
} else {
    echo "<p style='color: red;'>✗ PHP socket extension is NOT ENABLED</p>";
    echo "<p>You need to enable it for direct TCP enrollment.</p>";
}

echo "<h3>Step-by-Step Fix:</h3>";
echo "<ol>";
echo "<li><strong>Open php.ini file:</strong><br>";
echo "   Navigate to: <code>C:\\xampp\\php\\php.ini</code></li>";

echo "<li><strong>Find the socket extension line:</strong><br>";
echo "   Look for: <code>;extension=sockets</code></li>";

echo "<li><strong>Remove the semicolon:</strong><br>";
echo "   Change: <code>;extension=sockets</code><br>";
echo "   To: <code>extension=sockets</code></li>";

echo "<li><strong>Save the file</strong></li>";

echo "<li><strong>Restart Apache:</strong><br>";
echo "   - Open XAMPP Control Panel<br>";
echo "   - Stop Apache<br>";
echo "   - Start Apache</li>";
echo "</ol>";

echo "<h3>Alternative: Use C# Service</h3>";
echo "<p>If you can't enable sockets, the system will automatically use the C# service.</p>";

echo "<h3>Current PHP Info:</h3>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>PHP SAPI: " . php_sapi_name() . "</p>";

// Show loaded extensions
$extensions = get_loaded_extensions();
echo "<p>Loaded Extensions: " . count($extensions) . "</p>";
echo "<p>Socket functions available: " . (function_exists('socket_create') ? 'YES' : 'NO') . "</p>";

// Test socket creation if available
if (function_exists('socket_create')) {
    echo "<h3>Socket Test:</h3>";
    try {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket !== false) {
            echo "<p style='color: green;'>✓ Socket creation test PASSED</p>";
            socket_close($socket);
        } else {
            echo "<p style='color: red;'>✗ Socket creation test FAILED</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Socket test error: " . $e->getMessage() . "</p>";
    }
}

echo "<h3>Quick Test Links:</h3>";
echo "<p><a href='test_csharp_service.php'>Test C# Service Connection</a></p>";
echo "<p><a href='test_tcp_enrollment.php'>Test TCP Enrollment (if sockets enabled)</a></p>";
?>
