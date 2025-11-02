<?php
session_start();
header('Content-Type: application/json');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

$conn->query("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(50), message TEXT, created_at DATETIME, unread TINYINT(1) DEFAULT 1)");

$upd = $conn->query("UPDATE notifications SET unread = 0 WHERE unread = 1");

$conn->close();

echo json_encode(['success' => true]);
