<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Include payroll computations
require_once 'payroll_computations.php';

// Database connection
$host = "localhost";
$user = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$employee_id = $_SESSION['employee_id'];
$year = isset($_GET['year']) ? $_GET['year'] : '';
$month = isset($_GET['month']) ? $_GET['month'] : '';

// Get employee information
$employee_query = "SELECT EmployeeID, EmployeeName, Department, base_salary FROM empuser WHERE EmployeeID = ?";
$stmt = $conn->prepare($employee_query);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$employee_result = $stmt->get_result();
$employee = $employee_result->fetch_assoc();
$stmt->close();

if (!$employee) {
    http_response_code(404);
    echo json_encode(['error' => 'Employee not found']);
    exit();
}

// Build date filter conditions
$date_conditions = [];
$params = [];
$types = '';

if ($year && $month) {
    $date_conditions[] = "DATE_FORMAT(attendance_date, '%Y-%m') = ?";
    $params[] = $year . '-' . $month;
    $types .= 's';
} elseif ($year) {
    $date_conditions[] = "YEAR(attendance_date) = ?";
    $params[] = $year;
    $types .= 's';
}

$date_where = !empty($date_conditions) ? 'AND ' . implode(' AND ', $date_conditions) : '';

// Get available months for this employee
$months_query = "SELECT DISTINCT DATE_FORMAT(attendance_date, '%Y-%m') as month 
                 FROM attendance 
                 WHERE EmployeeID = ? 
                 $date_where
                 ORDER BY month DESC";
$months_stmt = $conn->prepare($months_query);
$months_stmt->bind_param("i" . $types, $employee_id, ...$params);
$months_stmt->execute();
$months_result = $months_stmt->get_result();
$available_months = [];
while ($row = $months_result->fetch_assoc()) {
    $available_months[] = $row['month'];
}
$months_stmt->close();

$payroll_records = [];

// Process each available month
foreach ($available_months as $month_period) {
    $payroll_data = calculatePayroll($employee_id, $employee['base_salary'], $month_period, $conn);
    
    if ($payroll_data) {
        $payroll_records[] = [
            'pay_period' => date('F Y', strtotime($month_period . '-01')),
            'month_period' => $month_period,
            'basic_salary' => $payroll_data['gross_pay'],
            'overtime_pay' => $payroll_data['overtime_pay'] ?? 0,
            'holiday_pay' => ($payroll_data['special_holiday_pay'] ?? 0) + ($payroll_data['regular_holiday_pay'] ?? 0),
            'gross_pay' => $payroll_data['gross_pay'],
            'total_deductions' => $payroll_data['total_deductions'],
            'net_pay' => $payroll_data['net_pay'],
            'status' => 'processed'
        ];
    }
}

$conn->close();

// Set headers for Excel download
$filename = 'payroll_history_' . ($year ?: 'all') . '_' . ($month ?: 'all') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Create CSV output
$output = fopen('php://output', 'w');

// CSV headers
fputcsv($output, [
    'Pay Period',
    'Basic Salary',
    'Overtime Pay',
    'Holiday Pay',
    'Gross Pay',
    'Total Deductions',
    'Net Pay',
    'Status'
]);

// CSV data
foreach ($payroll_records as $record) {
    fputcsv($output, [
        $record['pay_period'],
        number_format($record['basic_salary'], 2),
        number_format($record['overtime_pay'], 2),
        number_format($record['holiday_pay'], 2),
        number_format($record['gross_pay'], 2),
        number_format($record['total_deductions'], 2),
        number_format($record['net_pay'], 2),
        $record['status']
    ]);
}

fclose($output);
?>
