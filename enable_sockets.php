<?php
/**
 * Script to check and help enable PHP socket extension
 */

echo "<h2>PHP Socket Extension Check</h2>";

// Check if socket functions are available
if (function_exists('socket_create')) {
    echo "<p style='color: green;'>✓ PHP socket extension is ENABLED</p>";
    echo "<p>You can use the direct TCP enrollment method.</p>";
} else {
    echo "<p style='color: red;'>✗ PHP socket extension is NOT ENABLED</p>";
    echo "<p>You need to enable it to use direct TCP enrollment.</p>";
}

echo "<h3>How to Enable Socket Extension:</h3>";
echo "<ol>";
echo "<li>Open your php.ini file (usually in XAMPP/php/php.ini)</li>";
echo "<li>Find the line: <code>;extension=sockets</code></li>";
echo "<li>Remove the semicolon: <code>extension=sockets</code></li>";
echo "<li>Save the file</li>";
echo "<li>Restart Apache in XAMPP Control Panel</li>";
echo "</ol>";

echo "<h3>Alternative: Use C# Service</h3>";
echo "<p>If you can't enable sockets, the system will automatically fall back to using the C# service for enrollment.</p>";

echo "<h3>Current PHP Configuration:</h3>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Loaded Extensions: " . implode(', ', get_loaded_extensions()) . "</p>";

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
?>
