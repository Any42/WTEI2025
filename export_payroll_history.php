<?php
session_start();

// Check if user is logged in and is a Department Head
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'depthead') {
    header("Location: login.php");
    exit;
}

// Include FPDF library
require_once('fpdf/fpdf.php');

// Database connection
$host = "localhost";
$user = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($host, $user, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get filter parameters
$month = $_GET['month'] ?? '';
$year = $_GET['year'] ?? '';
$department = $_GET['department'] ?? '';

// Build query conditions
$where_conditions = ["e.Status='active'"];
$params = [];
$types = "";

if ($department) {
    $where_conditions[] = "e.Department = ?";
    $params[] = $department;
    $types .= "s";
}

// Build date filter
$date_filter = "";
if ($month) {
    $date_filter = "AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?";
    $params[] = $month;
    $types .= "s";
} elseif ($year) {
    $date_filter = "AND DATE_FORMAT(a.attendance_date, '%Y') = ?";
    $params[] = $year;
    $types .= "s";
}

// Query to get payroll history data
$query = "SELECT 
    e.EmployeeID,
    e.EmployeeName,
    e.Department,
    DATE_FORMAT(a.attendance_date, '%Y-%m') as PayPeriod,
    COUNT(DISTINCT DATE(a.attendance_date)) as DaysWorked,
    COALESCE(e.base_salary, 0) as BaseSalary,
    COALESCE(SUM(TIMESTAMPDIFF(MINUTE, 
        CONCAT(DATE(a.attendance_date), ' ', TIME(a.time_in)), 
        CASE 
            WHEN a.time_out IS NOT NULL THEN CONCAT(DATE(a.attendance_date), ' ', TIME(a.time_out))
            ELSE CONCAT(DATE(a.attendance_date), ' 17:00:00')
        END
    )) / 60, 0) as TotalHours
    FROM empuser e
    LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID 
    AND a.attendance_type = 'present'
    " . $date_filter . "
    WHERE " . implode(' AND ', $where_conditions) . "
    GROUP BY e.EmployeeID, DATE_FORMAT(a.attendance_date, '%Y-%m')
    ORDER BY PayPeriod DESC, e.Department, e.EmployeeName";

$stmt = $conn->prepare($query);
$payroll_records = [];

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Calculate payroll components (simplified)
        $gross_pay = $row['BaseSalary'] * $row['DaysWorked'];
        $deductions = $gross_pay * 0.15; // Simplified 15% deduction
        $net_pay = $gross_pay - $deductions;
        
        $payroll_records[] = [
            'EmployeeID' => $row['EmployeeID'],
            'EmployeeName' => $row['EmployeeName'],
            'Department' => $row['Department'],
            'PayPeriod' => $row['PayPeriod'],
            'DaysWorked' => $row['DaysWorked'],
            'GrossPay' => $gross_pay,
            'Deductions' => $deductions,
            'NetPay' => $net_pay
        ];
    }
    $stmt->close();
}

$conn->close();

// Create PDF
$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);

// Header
$pdf->Cell(0, 10, 'WTEI Corporation - Payroll History Report', 0, 1, 'C');
$pdf->Ln(5);

// Report details
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 8, 'Report Period: ' . ($month ? date('F Y', strtotime($month . '-01')) : ($year ? $year : 'All Time')), 0, 1);
if ($department) {
    $pdf->Cell(0, 8, 'Department: ' . $department, 0, 1);
}
$pdf->Cell(0, 8, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1);
$pdf->Ln(10);

// Table header
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(25, 8, 'Emp ID', 1, 0, 'C');
$pdf->Cell(40, 8, 'Name', 1, 0, 'C');
$pdf->Cell(30, 8, 'Department', 1, 0, 'C');
$pdf->Cell(20, 8, 'Period', 1, 0, 'C');
$pdf->Cell(20, 8, 'Days', 1, 0, 'C');
$pdf->Cell(25, 8, 'Gross Pay', 1, 0, 'C');
$pdf->Cell(25, 8, 'Deductions', 1, 0, 'C');
$pdf->Cell(25, 8, 'Net Pay', 1, 1, 'C');

// Table data
$pdf->SetFont('Arial', '', 9);
$total_gross = 0;
$total_deductions = 0;
$total_net = 0;

foreach ($payroll_records as $record) {
    $pdf->Cell(25, 6, $record['EmployeeID'], 1, 0, 'C');
    $pdf->Cell(40, 6, substr($record['EmployeeName'], 0, 20), 1, 0, 'L');
    $pdf->Cell(30, 6, substr($record['Department'], 0, 15), 1, 0, 'C');
    $pdf->Cell(20, 6, $record['PayPeriod'], 1, 0, 'C');
    $pdf->Cell(20, 6, $record['DaysWorked'], 1, 0, 'C');
    $pdf->Cell(25, 6, '₱' . number_format($record['GrossPay'], 2), 1, 0, 'R');
    $pdf->Cell(25, 6, '₱' . number_format($record['Deductions'], 2), 1, 0, 'R');
    $pdf->Cell(25, 6, '₱' . number_format($record['NetPay'], 2), 1, 1, 'R');
    
    $total_gross += $record['GrossPay'];
    $total_deductions += $record['Deductions'];
    $total_net += $record['NetPay'];
}

// Total row
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(135, 8, 'TOTAL:', 1, 0, 'R');
$pdf->Cell(25, 8, '₱' . number_format($total_gross, 2), 1, 0, 'R');
$pdf->Cell(25, 8, '₱' . number_format($total_deductions, 2), 1, 0, 'R');
$pdf->Cell(25, 8, '₱' . number_format($total_net, 2), 1, 1, 'R');

// Summary
$pdf->Ln(10);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'Summary:', 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, 'Total Employees: ' . count($payroll_records), 0, 1);
$pdf->Cell(0, 6, 'Total Gross Pay: ₱' . number_format($total_gross, 2), 0, 1);
$pdf->Cell(0, 6, 'Total Deductions: ₱' . number_format($total_deductions, 2), 0, 1);
$pdf->Cell(0, 6, 'Total Net Pay: ₱' . number_format($total_net, 2), 0, 1);

// Output PDF
$filename = 'payroll_history_' . ($month ?: $year ?: 'all') . '_' . date('Y-m-d') . '.pdf';
$pdf->Output('D', $filename);
?>
