<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Read and execute SQL file
$sql = file_get_contents('database_setup.sql');

// Split SQL file into individual statements
$statements = array_filter(array_map('trim', explode(';', $sql)));

// Execute each statement
foreach ($statements as $statement) {
    if (!empty($statement)) {
        if ($conn->query($statement) === TRUE) {
            echo "Successfully executed: " . substr($statement, 0, 50) . "...<br>";
        } else {
            echo "Error executing statement: " . $conn->error . "<br>";
        }
    }
}

$conn->close();
echo "Database setup completed!";
?> 