<?php
session_start();

if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: Login.php');
    exit;
}

$servername = 'localhost';
$username = 'root';
$password = '';
$dbname = 'wteimain1';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    $_SESSION['error'] = 'Database connection failed.';
    header('Location: AdminHome.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminId = $_SESSION['userid'];
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new === '' || $confirm === '' || $current === '') {
        $_SESSION['error'] = 'Please fill in all password fields.';
        header('Location: AdminHome.php');
        exit;
    }
    if ($new !== $confirm) {
        $_SESSION['error'] = 'New password and confirmation do not match.';
        header('Location: AdminHome.php');
        exit;
    }
    // Policy: at least 8 chars, includes number, lowercase and uppercase
    $policyOk = strlen($new) >= 8 && preg_match('/[0-9]/', $new) && preg_match('/[a-z]/', $new) && preg_match('/[A-Z]/', $new);
    if (!$policyOk) {
        $_SESSION['error'] = 'Password must be at least 8 characters and include numbers, lowercase and uppercase letters.';
        header('Location: AdminHome.php');
        exit;
    }

    $stmt = $conn->prepare('SELECT AdminPassword FROM adminuser WHERE AdminID = ?');
    if (!$stmt) {
        $_SESSION['error'] = 'Error preparing statement.';
        header('Location: AdminHome.php');
        exit;
    }
    $stmt->bind_param('s', $adminId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        $_SESSION['error'] = 'Account not found.';
        header('Location: AdminHome.php');
        exit;
    }

    $stored = $row['AdminPassword'] ?? '';
    $valid = ($current === $stored) || (function_exists('password_verify') && password_verify($current, $stored));
    if (!$valid) {
        $_SESSION['error'] = 'Current password is incorrect.';
        header('Location: AdminHome.php');
        exit;
    }

    $hashed = password_hash($new, PASSWORD_DEFAULT);
    $update = $conn->prepare('UPDATE adminuser SET AdminPassword = ? WHERE AdminID = ?');
    if (!$update) {
        $_SESSION['error'] = 'Error preparing update.';
        header('Location: AdminHome.php');
        exit;
    }
    $update->bind_param('ss', $hashed, $adminId);
    if ($update->execute()) {
        $_SESSION['success'] = 'Password updated successfully.';
        $_SESSION['password_changed'] = true;
    } else {
        $_SESSION['error'] = 'Failed to update password.';
    }
    $update->close();
}

$conn->close();
header('Location: AdminHome.php');
exit;
?>


