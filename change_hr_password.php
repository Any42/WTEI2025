<?php
session_start();

if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] ?? '') !== 'hr') {
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
    header('Location: HRHome.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hrId = $_SESSION['userid'];
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new === '' || $confirm === '' || $current === '') {
        $_SESSION['error'] = 'Please fill in all password fields.';
        header('Location: HRHome.php');
        exit;
    }
    if ($new !== $confirm) {
        $_SESSION['error'] = 'New password and confirmation do not match.';
        header('Location: HRHome.php');
        exit;
    }
    $policyOk = strlen($new) >= 8 && preg_match('/[0-9]/', $new) && preg_match('/[a-z]/', $new) && preg_match('/[A-Z]/', $new);
    if (!$policyOk) {
        $_SESSION['error'] = 'Password must be at least 8 characters and include numbers, lowercase and uppercase letters.';
        header('Location: HRHome.php');
        exit;
    }

    $stmt = $conn->prepare('SELECT password FROM hr_accounts WHERE hr_id = ?');
    if (!$stmt) {
        $_SESSION['error'] = 'Error preparing statement.';
        header('Location: HRHome.php');
        exit;
    }
    $stmt->bind_param('i', $hrId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        $_SESSION['error'] = 'Account not found.';
        header('Location: HRHome.php');
        exit;
    }

    $stored = $row['password'] ?? '';
    $valid = ($current === $stored) || (function_exists('password_verify') && password_verify($current, $stored));
    if (!$valid) {
        $_SESSION['error'] = 'Current password is incorrect.';
        header('Location: HRHome.php');
        exit;
    }

    $hashed = password_hash($new, PASSWORD_DEFAULT);
    $update = $conn->prepare('UPDATE hr_accounts SET password = ? WHERE hr_id = ?');
    if (!$update) {
        $_SESSION['error'] = 'Error preparing update.';
        header('Location: HRHome.php');
        exit;
    }
    $update->bind_param('si', $hashed, $hrId);
    if ($update->execute()) {
        $_SESSION['success'] = 'Password updated successfully.';
    } else {
        $_SESSION['error'] = 'Failed to update password.';
    }
    $update->close();
}

$conn->close();
header('Location: HRHome.php');
exit;
?>


