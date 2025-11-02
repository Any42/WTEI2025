<?php
session_start();

// Check if user is logged in and is a Department Head
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'depthead' || !isset($_SESSION['user_department'])) {
    header("Location: login.php");
    exit;
}

$dept_head_name = $_SESSION['username'] ?? 'Dept Head';
$managed_department = $_SESSION['user_department'];
$dept_head_id = $_SESSION['userid'] ?? null;

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

$has_payroll_access = ($managed_department == 'Accounting');

// Fetch employees for the managed department
$employees_in_dept = [];
$stmt = $conn->prepare("SELECT EmployeeID, EmployeeName, EmployeeEmail, Position, Status, Contact, base_salary FROM empuser WHERE Department = ? ORDER BY EmployeeName");
$stmt->bind_param("s", $managed_department);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $employees_in_dept[] = $row;
    }
}

// Get present today count (fixed query using LEFT JOIN like HRAttendance.php)
$today = date('Y-m-d');
$presentQuery = "SELECT COUNT(DISTINCT CASE WHEN a.attendance_type = 'present' THEN e.EmployeeID END) as present
                 FROM empuser e
                 LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID AND DATE(a.attendance_date) = ?
                 WHERE e.Status = 'active'";
$stmt_present = $conn->prepare($presentQuery);
$stmt_present->bind_param("s", $today);
$stmt_present->execute();
$presentResult = $stmt_present->get_result();
$presentToday = $presentResult->fetch_assoc()['present'] ?? 0;
$stmt_present->close();

// Get monthly payroll total
$currentMonth = date('m');
$currentYear = date('Y');
$payrollQuery = "SELECT SUM(net_pay) as total FROM payroll WHERE MONTH(payment_date) = '$currentMonth' AND YEAR(payment_date) = '$currentYear'";
$payrollResult = $conn->query($payrollQuery);
$monthlyPayroll = $payrollResult->fetch_assoc()['total'] ?? 0;

$allDepartments = [
    'Treasury', 'HR', 'Sales', 'Tax', 'Admin', 
    'Finance', 'Accounting', 'Marketing', 'CMCD', 'Security'
];

// Get all employees with updated column names
$employees = [];
$query = "SELECT e.NO, e.EmployeeID, e.EmployeeName, e.EmployeeEmail, e.Department, e.Position, 
          e.base_salary, e.rice_allowance, e.medical_allowance, e.laundry_allowance, e.Contact, IFNULL(e.Status, 'active') as Status, e.history, e.DateHired, 
          e.Birthdate, e.Age, e.LengthOfService, e.BloodType, e.TIN, e.SSS, e.PHIC, e.HDMF, 
          e.PresentHomeAddress, e.PermanentHomeAddress, e.LastDayEmployed, 
          e.DateTransferred, e.AreaOfAssignment, e.Shift
          FROM empuser e 
          ORDER BY e.NO DESC";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Group employees by department for the department view
$employeesByDepartment = [];
foreach ($employees as $employee) {
    $dept = $employee['Department'] ?? 'Other';
    if (!isset($employeesByDepartment[$dept])) {
        $employeesByDepartment[$dept] = [];
    }
    $employeesByDepartment[$dept][] = $employee;
}

// Get statistics for dashboard cards
$totalEmployees = count($employees);

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees - <?php echo htmlspecialchars($managed_department); ?> - WTEI</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
    /* Base Styles */
    :root {
        --primary-color: #112D4E;      /* Dark blue */
        --secondary-color: #3F72AF;    /* Medium blue */
        --accent-color: #DBE2EF;       /* Light blue/gray */
        --background-color: #F9F7F7;   /* Off white */
        --text-color: #112D4E;         /* Using dark blue for text */
        --border-color: #DBE2EF;       /* Light blue for borders */
        --shadow-color: rgba(17, 45, 78, 0.1); /* Primary color shadow */
        --success-color: #3F72AF;      /* Using secondary color for success */
        --warning-color: #DBE2EF;      /* Using accent color for warnings */
        --info-color: #3F72AF;         /* Info color for cards */
        --error-color: #ff4757;        /* Error color */
    }

    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(5px);
    }

    .modal-content {
        background-color: white;
        margin: 2% auto;
        padding: 0;
        border-radius: 16px;
        width: 90%;
        max-width: 1000px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        animation: modalSlideIn 0.3s ease;
    }

    @keyframes modalSlideIn {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .close-modal {
        position: absolute;
        top: 15px;
        right: 20px;
        font-size: 28px;
        font-weight: bold;
        color: #aaa;
        cursor: pointer;
        z-index: 1001;
        background: none;
        border: none;
        padding: 5px;
        transition: color 0.3s ease;
    }

    .close-modal:hover {
        color: var(--primary-color);
    }

    .employee-profile-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: white;
        padding: 30px;
        display: flex;
        align-items: center;
        gap: 20px;
        border-radius: 16px 16px 0 0;
    }

    .employee-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        font-weight: bold;
        color: white;
        border: 3px solid rgba(255, 255, 255, 0.3);
    }

    .employee-header-info h1 {
        margin: 0;
        font-size: 28px;
        font-weight: 600;
    }

    .employee-position {
        font-size: 16px;
        opacity: 0.9;
        margin: 5px 0;
    }

    .employee-department {
        font-size: 14px;
        opacity: 0.8;
        background: rgba(255, 255, 255, 0.2);
        padding: 4px 12px;
        border-radius: 20px;
        display: inline-block;
    }

    .employee-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 25px;
        padding: 30px;
    }

    .info-section {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 20px;
        border: 1px solid var(--border-color);
    }

    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 2px solid var(--accent-color);
        padding-bottom: 10px;
    }

    .section-title i {
        color: var(--secondary-color);
    }

    .info-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid rgba(219, 226, 239, 0.5);
    }

    .info-item:last-child {
        border-bottom: none;
    }

    .info-label {
        font-weight: 500;
        color: var(--primary-color);
        font-size: 14px;
    }

    .info-value {
        color: #333;
        font-size: 14px;
        text-align: right;
        max-width: 60%;
        word-break: break-word;
    }

    .info-value.salary {
        font-weight: 600;
        color: var(--success-color);
        font-size: 16px;
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

    .history-section {
        grid-column: 1 / -1;
    }

    .history-content {
        background: white;
        padding: 15px;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        min-height: 100px;
        font-size: 14px;
        line-height: 1.6;
        color: #333;
    }

    /* Pagination Styles */
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-top: 30px;
    }

    .pagination button {
        padding: 10px 15px;
        border: 1px solid var(--border-color);
        background-color: white;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        color: var(--primary-color);
        font-weight: 500;
    }

    .pagination button:hover:not(:disabled) {
        background-color: var(--accent-color);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .pagination button:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .pagination button.active {
        background-color: var(--secondary-color);
        color: white;
        border-color: var(--secondary-color);
    }

    #pageNumbers {
        display: flex;
        gap: 5px;
    }

    .page-number {
        padding: 10px 15px;
        border: 1px solid var(--border-color);
        background-color: white;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        color: var(--primary-color);
        font-weight: 500;
        min-width: 45px;
        text-align: center;
    }

    .page-number:hover {
        background-color: var(--accent-color);
        transform: translateY(-2px);
    }

    .page-number.active {
        background-color: var(--secondary-color);
        color: white;
        border-color: var(--secondary-color);
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Poppins', sans-serif;
    }

    body {
        background-color: var(--background-color);
        display: flex;
        min-height: 100vh;
        color: var(--text-color);
    }

    /* Sidebar Styles */
    .sidebar {
        width: 250px;  /* Slightly reduced from 280px */
        background: linear-gradient(180deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        padding: 20px 0;
        box-shadow: 4px 0 10px var(--shadow-color);
        display: flex;
        flex-direction: column;
        color: white;
        position: fixed;
        height: 100vh;
        transition: width 0.3s ease, margin-left 0.3s ease;
        border-right: 2px solid var(--accent-color);
        z-index: 100;
        left: 0;
        top: 0;
    }

    .logo {
        font-weight: 600;
        font-size: 24px;
        padding: 25px;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 20px;
        color: white;
        letter-spacing: 1px;
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
        background-color: rgba(219, 226, 239, 0.2); /* accent color with opacity */
        color: var(--background-color);
        transform: translateX(5px);
    }

    .menu-item i {
        margin-right: 15px;
        width: 20px;
        text-align: center;
        font-size: 18px;
    }

    .logout-btn {
        background-color: var(--accent-color);
        color: var(--primary-color);
        border: 1px solid var(--secondary-color);
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
        background-color: var(--secondary-color);
        color: var(--background-color);
        transform: translateY(-2px);
    }

    .logout-btn i {
        margin-right: 10px;
    }

    /* Main Content */
.main-content {
    flex-grow: 1;
    padding: 30px;
    margin-left: 250px;    
    overflow-y: auto;
    transition: all 0.3s ease;
    min-height: 100vh;
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
    border-bottom: 2px solid var(--border-color);
}

.header h1 {
    font-size: 28px;
    color: var(--primary-color);
    font-weight: 600;
}

.header-actions {
    display: flex;
    gap: 15px;
    align-items: center;
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
    border-color: var(--secondary-color);
    box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.1);
}

.search-box i {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--primary-color);
}

/* Dashboard Cards */
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
    background: linear-gradient(to right, var(--info-color), var(--success-color));
}

.card-content {
    position: relative;
    z-index: 1;
}

.card-title {
    font-size: 14px;
    color: var(--primary-color);
    margin-bottom: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.card-value {
    font-size: 28px;
    font-weight: 600;
    color: var(--info-color);
    margin-bottom: 15px;
}

.card-icon {
    position: absolute;
    right: 20px;
    bottom: 20px;
    font-size: 48px;
    color: rgba(22, 199, 154, 0.1);
}

/* Buttons */
.btn {
    padding: 10px 18px;
    border-radius: 8px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.btn-primary {
    background-color: var(--secondary-color);
    color: white;
}

.btn-primary:hover {
    background-color: var(--primary-color);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}

.btn-secondary {
    background-color: var(--accent-color);
    color: var(--primary-color);
}

.btn-secondary:hover {
    background-color: var(--secondary-color);
    color: white;
}

/* View Toggle */
.view-toggle {
    display: flex;
    gap: 10px;
}

.view-btn {
    padding: 10px 20px;
    border: 2px solid var(--secondary-color);
    background-color: white;
    color: var(--secondary-color);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
}

.view-btn.active, .view-btn:hover {
    background-color: var(--secondary-color);
    color: white;
    transform: translateY(-2px);
}

/* Alerts */
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
    color: var(--success-color);
    border: 1px solid rgba(22, 199, 154, 0.2);
}

.alert-error {
    background-color: rgba(255, 71, 87, 0.1);
    color: var(--error-color);
    border: 1px solid rgba(255, 71, 87, 0.2);
}

.alert i {
    margin-right: 10px;
    font-size: 18px;
}

/* Tables */
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
    color: var(--primary-color);
    font-weight: 600;
    font-size: 14px;
    background-color: var(--accent-color);
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
    background-color: var(--accent-color);
    cursor: pointer;
}

/* Action Buttons */
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

.view-btn-action {
    background-color: var(--info-color);
    color: white;
}

.edit-btn {
    background-color: var(--success-color);
    color: white;
}

.delete-btn {
    background-color: var(--error-color);
    color: white;
}

.fingerprint-btn {
    background-color: var(--warning-color);
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

/* Department Cards */
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
    background: linear-gradient(to right, var(--secondary-color), var(--primary-color));
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
    color: var(--primary-color);
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
    background-color: var(--secondary-color);
    border-radius: 6px;
    opacity: 0.7;
}

.employee-count {
    background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
    color: white;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    box-shadow: 0 4px 8px rgba(63, 114, 175, 0.3);
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
    color: var(--secondary-color);
    width: 18px;
    text-align: center;
    font-size: 14px;
}

.preview-employee-name {
    font-weight: 500;
    color: var(--primary-color);
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
    color: var(--secondary-color);
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

/* Pagination */
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
    color: var(--primary-color);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
}

.pagination button:hover {
    background-color: var(--accent-color);
    transform: translateY(-2px);
}

.pagination button.active {
    background-color: var(--success-color);
    color: white;
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active {
    background-color: rgba(22, 199, 154, 0.1);
    color: var(--success-color);
}

.status-inactive {
    background-color: rgba(255, 71, 87, 0.1);
    color: var(--error-color);
}

/* Department Detail View */
.department-detail {
    display: none;
    background-color: white;
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
}

.detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 2px solid var(--border-color);
}

.detail-header h2 {
    color: var(--primary-color);
    font-size: 24px;
}

.back-btn {
    background-color: var(--accent-color);
    color: var(--primary-color);
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
    background-color: var(--secondary-color);
    color: white;
}

.employees-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin-top: 20px;
}

.employees-table th, .employees-table td {
    padding: 16px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.employees-table th {
    color: var(--primary-color);
    font-weight: 600;
    font-size: 14px;
    background-color: var(--accent-color);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.employees-table th:first-child {
    border-top-left-radius: 12px;
}

.employees-table th:last-child {
    border-top-right-radius: 12px;
}

.employees-table td {
    color: #333;
}

.employees-table tr:hover {
    background-color: var(--accent-color);
    cursor: pointer;
}

.no-employees {
    text-align: center;
    color: #666;
    font-style: italic;
    padding: 40px;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .dashboard-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .departments-grid {
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    }
}

@media (max-width: 992px) {
    .sidebar {
        width: 200px;
    }
    
    .main-content {
        margin-left: 200px;
        padding: 20px;
    }
    
    .header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .header-actions {
        width: 100%;
        flex-direction: column;
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

@media (max-width: 768px) {
    .sidebar {
        width: 180px;
    }
    
    .main-content {
        margin-left: 180px;
        padding: 15px;
    }
    
    .dashboard-cards {
        grid-template-columns: 1fr;
    }
    
    .departments-grid {
        grid-template-columns: 1fr;
    }
    
    .employee-table {
        padding: 15px;
        overflow-x: auto;
    }
    
    table {
        min-width: 600px;
    }
}

@media (max-width: 576px) {
    .sidebar {
        width: 100%;
        position: relative;
        height: auto;
    }
    
    .main-content {
        margin-left: 0;
        padding: 15px;
    }
    
    .header {
        padding: 15px;
    }
    
    .card {
        padding: 15px;
    }
    
    .department-card {
        padding: 15px;
    }
    
    .action-buttons {
        flex-wrap: wrap;
    }
    
    .action-btn {
        padding: 6px 10px;
        font-size: 12px;
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
    <!-- Employee Info Modal -->
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
            
            <div class="employee-info-grid">
                <!-- Personal Information -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-user"></i> Personal Information
                    </div>
                    <div class="info-item">
                        <div class="info-label">Employee ID:</div>
                        <div class="info-value" id="empID"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email:</div>
                        <div class="info-value" id="empEmail"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Contact:</div>
                        <div class="info-value" id="empContact"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Birthdate:</div>
                        <div class="info-value" id="empBirthdate"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Age:</div>
                        <div class="info-value" id="empAge"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Blood Type:</div>
                        <div class="info-value" id="empBloodType"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Address:</div>
                        <div class="info-value" id="empAddress"></div>
                    </div>
                </div>
                
                <!-- Employment Information -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-briefcase"></i> Employment
                    </div>
                    <div class="info-item">
                        <div class="info-label">Years of Service:</div>
                        <div class="info-value" id="empServiceYears"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Status:</div>
                        <div class="info-value">
                            <span class="status-badge" id="empStatus"></span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Area of Assignment:</div>
                        <div class="info-value" id="empAreaAssignment"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Work Shift:</div>
                        <div class="info-value" id="empShift"></div>
                    </div>
                    <div class="info-item" id="nightShiftDifferential" style="display: none;">
                        <div class="info-label">Night Shift Differential:</div>
                        <div class="info-value night-shift-badge">
                            <i class="fas fa-moon"></i> NSD (10PM-6AM) - 10% Additional Pay
                        </div>
                    </div>
                </div>
                
                <!-- Financial Information -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-money-bill-wave"></i> Financial
                    </div>
                    <div class="info-item">
                        <div class="info-label">Base Salary:</div>
                        <div class="info-value salary">₱<span id="empSalary"></span></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Rice Allowance:</div>
                        <div class="info-value salary">₱<span id="empRiceAllowance"></span></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Medical Allowance:</div>
                        <div class="info-value salary">₱<span id="empMedicalAllowance"></span></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Laundry Allowance:</div>
                        <div class="info-value salary">₱<span id="empLaundryAllowance"></span></div>
                    </div>
                </div>
                
                <!-- Government IDs -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-id-card"></i> Government IDs
                    </div>
                    <div class="info-item">
                        <div class="info-label">SSS:</div>
                        <div class="info-value" id="empSSS"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">PHIC:</div>
                        <div class="info-value" id="empPHIC"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">TIN:</div>
                        <div class="info-value" id="empTIN"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">HDMF:</div>
                        <div class="info-value" id="empHDMF"></div>
                    </div>
                </div>
                
                <!-- Employment History Section -->
                <div class="info-section history-section">
                    <div class="section-title">
                        <i class="fas fa-history"></i> Employment History
                    </div>
                    <div class="info-item">
                        <div class="info-label">Date Hired:</div>
                        <div class="info-value" id="empDateHired"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Date Transferred:</div>
                        <div class="info-value" id="empDateTransferred"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Last Day Employed:</div>
                        <div class="info-value" id="empLastDayEmployed"></div>
                    </div>
                    <div class="history-content" id="empHistory"></div>
                </div>
                
                <!-- Memo Section -->
                <div class="info-section history-section">
                    <div class="section-title">
                        <i class="fas fa-sticky-note"></i> Memo
                    </div>
                    <div class="history-content" id="empMemo"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="sidebar">
        <div class="logo">
        <img src="LOGO/newLogo_transparent.png" class="logo" style="width: 230px; height: 230px;padding-top: 70px;margin-bottom: 20px; margin-top: -70px; object-fit:contain; padding-bottom: -50px; padding-left: 0px; margin-right: 25px;padding: -190px; margin: 190;">
            <i class="fas fa-user-shield"></i>
            <span>Accounting Head Portal</span>
        </div>
        <div class="menu">
            <a href="DeptHeadDashboard.php" class="menu-item"><i class="fas fa-th-large"></i> <span>Dashboard</span></a>
            <a href="DeptHeadEmployees.php" class="menu-item active"><i class="fas fa-users"></i> <span>Employees</span></a>
            <a href="DeptHeadAttendance.php" class="menu-item"><i class="fas fa-calendar-check"></i> <span>Attendance</span></a>
            
            <?php if($managed_department == 'Accounting'): ?>
            <a href="Payroll.php" class="menu-item">
                <i class="fas fa-money-bill-wave"></i>
                <span>Payroll</span>
            </a>
            <a href="DeptHeadHistory.php" class="menu-item">
                <i class="fas fa-history"></i> History
            </a>
            <?php endif; ?>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="header">
            <h1>Employee Management</h1>
            <div class="header-actions">
                <div class="view-toggle">
                    <a href="#" class="view-btn" onclick="showListView()">
                        <i class="fas fa-list"></i> List View
                    </a>
                    <a href="#" class="view-btn active" onclick="showDepartmentView()">
                        <i class="fas fa-building"></i> Department View
                    </a>
                </div>
            </div>
        </div>

        <!-- Dashboard Cards -->
        <div class="dashboard-cards" style="grid-template-columns: repeat(2, 1fr);">
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
                    <div class="card-title">Monthly Payroll</div>
                    <div class="card-value">₱<?php echo number_format($monthlyPayroll, 2); ?></div>
                    <div class="card-icon">
                        <i class="fas fa-money-bill-wave"></i>
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
                <?php $deptEmployees = $employeesByDepartment[$department] ?? []; ?>
                <div class="department-card" onclick="showDepartmentDetail('<?php echo $department; ?>')">
                    <div class="department-header">
                        <div class="department-name"><?php echo $department; ?></div>
                        <div class="employee-count"><?php echo count($deptEmployees); ?> employees</div>
                    </div>
                    <div class="department-preview">
                        <?php for ($i = 0; $i < min(3, count($deptEmployees)); $i++): ?>
                            <div class="preview-employee">
                                <i class="fas fa-user"></i> <?php echo $deptEmployees[$i]['EmployeeName']; ?> - <?php echo $deptEmployees[$i]['Position']; ?>
                            </div>
                        <?php endfor; ?>
                        <?php if (count($deptEmployees) > 3): ?>
                            <div class="more-employees">+<?php echo count($deptEmployees) - 3; ?> more employees...</div>
                        <?php elseif (count($deptEmployees) === 0): ?>
                            <div class="more-employees">No employees in this department</div>
                        <?php endif; ?>
                    </div>
                    <div class="department-icon">
                        <?php 
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
                        
                    </tr>
                </thead>
                    
                <tbody id="departmentEmployeesTable">
                    <!-- Employee rows will be populated here by JavaScript -->
                </tbody>
            </table>
        </div>

        <!-- List View -->
        <div id="listView" class="employee-table" style="display: none;">
            <!-- Search and Controls for List View -->
            <div class="list-controls" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div class="search-box" style="width: 300px;">
                    <input type="text" id="listSearchInput" placeholder="Search employees...">
                    <i class="fas fa-search"></i>
                </div>
                <div class="pagination-controls" style="display: flex; align-items: center; gap: 10px;">
                    <label for="itemsPerPage" style="font-weight: 500; color: var(--primary-color);">Show:</label>
                    <select id="itemsPerPage" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; background: white;">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="all">All</option>
                    </select>
                    <span style="color: #666; font-size: 14px;">per page</span>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Position</th>
                        <th>Salary</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="employeeTableBody">
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">No employees found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                            <tr onclick="showEmployeeModal(
                                '<?php echo $employee['EmployeeID'] ?? ''; ?>',
                                '<?php echo addslashes($employee['EmployeeName'] ?? ''); ?>',
                                '<?php echo addslashes($employee['EmployeeEmail'] ?? ''); ?>',
                                '<?php echo addslashes($employee['Department'] ?? ''); ?>',
                                '<?php echo addslashes($employee['Position'] ?? ''); ?>',
                                '<?php echo addslashes($employee['Contact'] ?? 'Not Available'); ?>',
                                '<?php echo addslashes($employee['DateHired'] ?? 'Not Available'); ?>',
                                '<?php echo addslashes($employee['Birthdate'] ?? 'Not Available'); ?>',
                                '<?php echo $employee['Age'] ?? ''; ?>',
                                '<?php echo addslashes($employee['Status'] ?? ''); ?>',
                                '<?php echo addslashes($employee['Shift'] ?? 'Not Available'); ?>',
                                '<?php echo number_format($employee['base_salary'] ?? 0, 2); ?>',
                                '<?php echo $employee['rice_allowance'] ?? 0; ?>',
                                '<?php echo $employee['medical_allowance'] ?? 0; ?>',
                                '<?php echo $employee['laundry_allowance'] ?? 0; ?>',
                                '<?php echo addslashes($employee['TIN'] ?? 'Not Available'); ?>',
                                '<?php echo addslashes($employee['SSS'] ?? 'Not Available'); ?>',
                                '<?php echo addslashes($employee['PHIC'] ?? 'Not Available'); ?>',
                                '<?php echo addslashes($employee['HDMF'] ?? 'Not Available'); ?>',
                                '<?php echo addslashes($employee['BloodType'] ?? 'Not Available'); ?>',
                                '<?php echo addslashes($employee['PresentHomeAddress'] ?? 'Not Available'); ?>',
                                '<?php echo addslashes($employee['AreaOfAssignment'] ?? 'Not Available'); ?>',
                                '<?php echo addslashes($employee['DateTransferred'] ?? 'Not Available'); ?>',
                                '<?php echo addslashes($employee['LastDayEmployed'] ?? 'Not Available'); ?>',
                                '<?php echo addslashes($employee['memo'] ?? 'No memo available'); ?>',
                                '<?php echo addslashes($employee['history'] ?? 'No history available'); ?>'
                            )">
                                <td><?php echo $employee['EmployeeID'] ?? ''; ?></td>
                                <td><?php echo $employee['EmployeeName'] ?? ''; ?></td>
                                <td><?php echo $employee['EmployeeEmail'] ?? ''; ?></td>
                                <td><?php echo $employee['Department'] ?? ''; ?></td>
                                <td><?php echo $employee['Position'] ?? ''; ?></td>
                                <td>₱<?php echo number_format($employee['base_salary'] ?? 0, 2); ?></td>
                                <td>
                                    <span class="status-badge <?php echo ($employee['Status'] ?? '') === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $employee['Status'] ?? ''; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="pagination" id="paginationContainer">
                <button id="prevBtn" onclick="changePage(-1)"><i class="fas fa-chevron-left"></i></button>
                <div id="pageNumbers"></div>
                <button id="nextBtn" onclick="changePage(1)"><i class="fas fa-chevron-right"></i></button>
            </div>
            
            <div class="pagination-info" style="text-align: center; margin-top: 15px; color: #666; font-size: 14px;">
                <span id="paginationInfo">Showing 1-25 of <?php echo count($employees); ?> employees</span>
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
        
        // Pagination variables
        let currentPage = 1;
        let itemsPerPage = 25;
        let filteredEmployees = [];
        let allEmployees = <?php echo json_encode($employees); ?>;

        // Function to get initials from name
        function getInitials(name) {
            if (!name) return '';
            const names = name.split(' ');
            let initials = names[0].substring(0, 1).toUpperCase();
            if (names.length > 1) {
                initials += names[names.length - 1].substring(0, 1).toUpperCase();
            }
            return initials;
        }

        // Initialize pagination and search
        function initializePagination() {
            filteredEmployees = [...allEmployees];
            updateTable();
            updatePagination();
        }

        // Update table display
        function updateTable() {
            const tbody = document.getElementById('employeeTableBody');
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const pageEmployees = filteredEmployees.slice(startIndex, endIndex);

            tbody.innerHTML = '';

            if (pageEmployees.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No employees found</td></tr>';
                return;
            }

            pageEmployees.forEach(employee => {
                const row = document.createElement('tr');
                row.onclick = () => showEmployeeModal(
                    employee.EmployeeID || '',
                    employee.EmployeeName || '',
                    employee.EmployeeEmail || '',
                    employee.Department || '',
                    employee.Position || '',
                    employee.Contact || 'Not Available',
                    employee.DateHired || 'Not Available',
                    employee.Birthdate || 'Not Available',
                    employee.Age || '',
                    employee.Status || '',
                    employee.Shift || 'Not Available',
                    employee.base_salary ? Number(employee.base_salary).toFixed(2) : '0.00',
                    employee.TIN || 'Not Available',
                    employee.SSS || 'Not Available',
                    employee.PHIC || 'Not Available',
                    employee.HDMF || 'Not Available',
                    employee.BloodType || 'Not Available',
                    employee.PresentHomeAddress || 'Not Available',
                    employee.AreaOfAssignment || 'Not Available',
                    employee.DateTransferred || 'Not Available',
                    employee.LastDayEmployed || 'Not Available',
                    employee.memo || 'No memo available',
                    employee.history || 'No history available'
                );

                row.innerHTML = `
                    <td>${employee.EmployeeID || ''}</td>
                    <td>${employee.EmployeeName || ''}</td>
                    <td>${employee.EmployeeEmail || ''}</td>
                    <td>${employee.Department || ''}</td>
                    <td>${employee.Position || ''}</td>
                    <td>₱${employee.base_salary ? Number(employee.base_salary).toFixed(2) : '0.00'}</td>
                    <td>
                        <span class="status-badge ${(employee.Status || '').toLowerCase() === 'active' ? 'status-active' : 'status-inactive'}">
                            ${employee.Status || ''}
                        </span>
                    </td>
                `;
                tbody.appendChild(row);
            });

            updatePaginationInfo();
        }

        // Update pagination controls
        function updatePagination() {
            const totalPages = Math.ceil(filteredEmployees.length / itemsPerPage);
            const pageNumbers = document.getElementById('pageNumbers');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');

            // Update prev/next buttons
            prevBtn.disabled = currentPage === 1;
            nextBtn.disabled = currentPage === totalPages || totalPages === 0;

            // Clear and rebuild page numbers
            pageNumbers.innerHTML = '';

            if (totalPages <= 7) {
                // Show all pages if 7 or fewer
                for (let i = 1; i <= totalPages; i++) {
                    const pageBtn = document.createElement('button');
                    pageBtn.className = `page-number ${i === currentPage ? 'active' : ''}`;
                    pageBtn.textContent = i;
                    pageBtn.onclick = () => goToPage(i);
                    pageNumbers.appendChild(pageBtn);
                }
            } else {
                // Show first, last, current, and surrounding pages
                const pages = [1];
                
                if (currentPage > 3) pages.push('...');
                
                const start = Math.max(2, currentPage - 1);
                const end = Math.min(totalPages - 1, currentPage + 1);
                
                for (let i = start; i <= end; i++) {
                    if (!pages.includes(i)) pages.push(i);
                }
                
                if (currentPage < totalPages - 2) pages.push('...');
                if (totalPages > 1) pages.push(totalPages);

                pages.forEach(page => {
                    if (page === '...') {
                        const ellipsis = document.createElement('span');
                        ellipsis.textContent = '...';
                        ellipsis.style.padding = '10px 5px';
                        ellipsis.style.color = '#666';
                        pageNumbers.appendChild(ellipsis);
                    } else {
                        const pageBtn = document.createElement('button');
                        pageBtn.className = `page-number ${page === currentPage ? 'active' : ''}`;
                        pageBtn.textContent = page;
                        pageBtn.onclick = () => goToPage(page);
                        pageNumbers.appendChild(pageBtn);
                    }
                });
            }
        }

        // Update pagination info
        function updatePaginationInfo() {
            const startIndex = (currentPage - 1) * itemsPerPage + 1;
            const endIndex = Math.min(currentPage * itemsPerPage, filteredEmployees.length);
            const total = filteredEmployees.length;
            
            document.getElementById('paginationInfo').textContent = 
                `Showing ${total > 0 ? startIndex : 0}-${endIndex} of ${total} employees`;
        }

        // Go to specific page
        function goToPage(page) {
            currentPage = page;
            updateTable();
            updatePagination();
        }

        // Change page (prev/next)
        function changePage(direction) {
            const totalPages = Math.ceil(filteredEmployees.length / itemsPerPage);
            const newPage = currentPage + direction;
            
            if (newPage >= 1 && newPage <= totalPages) {
                currentPage = newPage;
                updateTable();
                updatePagination();
            }
        }

        // Search functionality
        function searchEmployees() {
            const searchTerm = document.getElementById('listSearchInput').value.toLowerCase();
            
            filteredEmployees = allEmployees.filter(employee => {
                return (
                    (employee.EmployeeName || '').toLowerCase().includes(searchTerm) ||
                    (employee.EmployeeEmail || '').toLowerCase().includes(searchTerm) ||
                    (employee.Department || '').toLowerCase().includes(searchTerm) ||
                    (employee.Position || '').toLowerCase().includes(searchTerm) ||
                    (employee.EmployeeID || '').toString().includes(searchTerm)
                );
            });
            
            currentPage = 1;
            updateTable();
            updatePagination();
        }

        // Items per page change
        function changeItemsPerPage() {
            const select = document.getElementById('itemsPerPage');
            itemsPerPage = select.value === 'all' ? filteredEmployees.length : parseInt(select.value);
            currentPage = 1;
            updateTable();
            updatePagination();
        }

        // Function to calculate years of service
        function calculateLengthOfService(hiredDate) {
            try {
                const hired = new Date(hiredDate);
                const today = new Date();
                let years = today.getFullYear() - hired.getFullYear();
                const monthDiff = today.getMonth() - hired.getMonth();
                
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < hired.getDate())) {
                    years--;
                }
                
                return years;
            } catch (e) {
                console.error('Error calculating years of service:', e);
                return 'Not Available';
            }
        }

        // Function to display employee modal
        function showEmployeeModal(
            employeeID, 
            employeeName, 
            employeeEmail, 
            department, 
            position, 
            contact, 
            dateHired, 
            birthdate, 
            age, 
            status, 
            shift,
            baseSalary, 
            riceAllowance,
            medicalAllowance,
            laundryAllowance,
            tin, 
            sss, 
            phic, 
            hdmf, 
            bloodType, 
            address, 
            areaAssignment, 
            dateTransferred,
            lastDayEmployed,
            memo, 
            history
        ) {
            // Set basic info
            document.getElementById('empName').innerText = employeeName || 'Not Available';
            document.getElementById('empPosition').innerText = position || 'Not Available';
            document.getElementById('empDepartment').innerText = department || 'Not Available';
            document.getElementById('empID').innerText = employeeID || 'Not Available';
            
            // Set avatar initials
            const initials = getInitials(employeeName);
            document.getElementById('empAvatar').innerText = initials || 'NA';
            
            // Personal Information
            document.getElementById('empEmail').innerText = employeeEmail || 'Not Available';
            document.getElementById('empContact').innerText = contact || 'Not Available';
            document.getElementById('empBirthdate').innerText = birthdate || 'Not Available';
            document.getElementById('empAge').innerText = age || 'Not Available';
            document.getElementById('empBloodType').innerText = bloodType || 'Not Available';
            
            // Employment Information
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
            
            // Calculate years of service if dateHired is available
            if (dateHired && dateHired !== 'Not Available') {
                const yearsOfService = calculateLengthOfService(dateHired);
                document.getElementById('empServiceYears').innerText = yearsOfService;
            } else {
                document.getElementById('empServiceYears').innerText = 'Not Available';
            }
            
            // Status
            const statusElement = document.getElementById('empStatus');
            statusElement.innerText = status || 'Not Available';
            statusElement.className = 'status-badge';
            if (status && status.toLowerCase() === 'active') {
                statusElement.classList.add('status-active');
            } else {
                statusElement.classList.add('status-inactive');
            }
            
            // Financial Information
            document.getElementById('empSalary').innerText = baseSalary ? Number(baseSalary).toFixed(2) : '0.00';
            
            // Set allowances
            document.getElementById('empRiceAllowance').innerText = riceAllowance ? Number(riceAllowance).toFixed(2) : '0.00';
            document.getElementById('empMedicalAllowance').innerText = medicalAllowance ? Number(medicalAllowance).toFixed(2) : '0.00';
            document.getElementById('empLaundryAllowance').innerText = laundryAllowance ? Number(laundryAllowance).toFixed(2) : '0.00';
            
            // Government IDs
            document.getElementById('empSSS').innerText = sss || 'Not Available';
            document.getElementById('empPHIC').innerText = phic || 'Not Available';
            document.getElementById('empTIN').innerText = tin || 'Not Available';
            document.getElementById('empHDMF').innerText = hdmf || 'Not Available';
            
            // Employment History
            document.getElementById('empDateHired').innerText = dateHired || 'Not Available';
            document.getElementById('empDateTransferred').innerText = dateTransferred || 'Not Available';
            document.getElementById('empLastDayEmployed').innerText = lastDayEmployed || 'Not Available';
            
            // History and Memo
            document.getElementById('empHistory').innerText = history || 'No history available';
            document.getElementById('empMemo').innerText = memo || 'No memo available';
            
            // Show the modal
            document.getElementById('employeeModal').style.display = 'flex';
        }

        // Function to close modal
        function closeModal() {
            document.getElementById('employeeModal').style.display = 'none';
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const empModal = document.getElementById('employeeModal');
            if (event.target === empModal) {
                closeModal();
            }
        }

        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize pagination
            initializePagination();
            
            // Search functionality for list view
            document.getElementById('listSearchInput').addEventListener('keyup', searchEmployees);
            
            // Items per page change
            document.getElementById('itemsPerPage').addEventListener('change', changeItemsPerPage);
        });

        // Department view functions
        function showDepartmentView() {
            document.getElementById('departmentsView').style.display = 'grid';
            document.getElementById('departmentDetail').style.display = 'none';
            document.getElementById('listView').style.display = 'none';
            
            // Update active button state
            document.querySelector('.view-toggle .view-btn').classList.remove('active');
            document.querySelector('.view-toggle .view-btn:nth-child(2)').classList.add('active');
        }

        function showListView() {
            document.getElementById('departmentsView').style.display = 'none';
            document.getElementById('departmentDetail').style.display = 'none';
            document.getElementById('listView').style.display = 'block';
            
            // Update active button state
            document.querySelector('.view-toggle .view-btn').classList.add('active');
            document.querySelector('.view-toggle .view-btn:nth-child(2)').classList.remove('active');
            
            // Initialize pagination for list view
            initializePagination();
        }

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
                    emp.Contact || 'Not Available',
                    emp.DateHired || 'Not Available',
                    emp.Birthdate || 'Not Available',
                    emp.Age || '',
                    emp.Status || '',
                    emp.Shift || 'Not Available',
                    emp.base_salary ? Number(emp.base_salary).toFixed(2) : '0.00',
                    emp.rice_allowance || 0,
                    emp.medical_allowance || 0,
                    emp.laundry_allowance || 0,
                    emp.TIN || 'Not Available',
                    emp.SSS || 'Not Available',
                    emp.PHIC || 'Not Available',
                    emp.HDMF || 'Not Available',
                    emp.BloodType || 'Not Available',
                    emp.PresentHomeAddress || 'Not Available',
                    emp.AreaOfAssignment || 'Not Available',
                    emp.DateTransferred || 'Not Available',
                    emp.LastDayEmployed || 'Not Available',
                    emp.memo || 'No memo available',
                    emp.history || 'No history available'
                );
                
                row.innerHTML = `
                    <td>${emp.EmployeeID || ''}</td>
                    <td>${emp.EmployeeName || ''}</td>
                    <td>${emp.EmployeeEmail || ''}</td>
                    <td>${emp.Position || ''}</td>
                    <td>₱${emp.base_salary ? Number(emp.base_salary).toFixed(2) : '0.00'}</td>
                    <td><span class="status-badge ${emp.Status === 'Active' ? 'status-active' : 'status-inactive'}">${emp.Status || ''}</span></td>
                    <td>
                        
                    </td>
                `;
                tableBody.appendChild(row);
            });
        }

        // Initialize with department view
        showDepartmentView();
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