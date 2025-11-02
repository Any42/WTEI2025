<?php
session_start();

if (!isset($_SESSION['loggedin']) || !in_array($_SESSION['role'], ['depthead', 'employee'])) {
    header("Location: Login.php");
    exit;
}

// Include centralized payroll computations
require_once 'payroll_computations.php';

// Check if FPDF library exists
if (!file_exists('fpdf/fpdf.php')) {
    die("FPDF library not found. Please ensure fpdf/fpdf.php exists.");
}

require_once 'fpdf/fpdf.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Get employee ID and month from request
$employeeId = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

if (!$employeeId) {
    die("Employee ID is required");
}

// Security check: Employees can only export their own payslips
if ($_SESSION['role'] === 'employee' && $_SESSION['employee_id'] != $employeeId) {
    die("Access denied: You can only export your own payslip");
}

// Get employee details
$employee_query = "SELECT * FROM empuser WHERE EmployeeID = ?";
$stmt = $conn->prepare($employee_query);
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
    $stmt->close();

if (!$employee) {
    die("Employee not found");
}

// Calculate payroll
$payroll = calculatePayroll($employeeId, $employee['base_salary'], $month, $conn);

// Get attendance data first
$attendance_summary = getEmployeeAttendanceSummary($employeeId, $month, $conn);

// Use centralized computed basic salary to avoid float drift between UI and PDF
$hourlyRate = $employee['base_salary'] / 8.0;
$basicSalary = $payroll['basic_salary'];

// Calculate working days and rates
$working_days = calculateWorkingDaysInMonth($month);
$monthly_rate = $employee['base_salary'] * $working_days;
$daily_rate = $employee['base_salary'];

// Get allowances from payroll calculation (includes eligibility check)
$laundry_allowance = $payroll['laundry_allowance'];
$medical_allowance = $payroll['medical_allowance'];
$rice_allowance = $payroll['rice_allowance'];

// Additional deductions are now handled by payroll_computations.php
// No need to query deductions table as it has been removed
$additional_deductions = 0;
$final_net_pay = $payroll['net_pay'];

// Calculate date range
$month_start = date('Y-m-01', strtotime($month . '-01'));
$month_end = date('Y-m-t', strtotime($month . '-01'));
$date_covered = date('m/d/Y', strtotime($month_start)) . ' TO ' . date('m/d/Y', strtotime($month_end));

// Create PDF
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();

// Set font
$pdf->SetFont('Arial', 'B', 16);

// Company Name
$pdf->Cell(0, 8, 'WILLIAM TAN ENTERPRISES INC.', 0, 1, 'L');

// Employee Name and ID
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 6, $employee['EmployeeID'] . ' ' . strtoupper($employee['EmployeeName']), 0, 1, 'L');

// Employee Details Table with specific format and proper spacing
$pdf->SetFont('Arial', '', 10);

// Add spacing after employee name
$pdf->Ln(8);

// First row - Date Covered and Pag-ibig
$pdf->Cell(40, 6, 'Date Covered:', 0, 0, 'L');
$pdf->Cell(60, 6, $date_covered, 0, 0, 'L');
$pdf->Cell(30, 6, 'Pag-ibig:', 0, 0, 'L');
$pdf->Cell(0, 6, $employee['HDMF'] ?: 'N/A', 0, 1, 'L');

// Second row - Monthly Rate and SSS
$pdf->Cell(40, 6, 'Monthly Rate:', 0, 0, 'L');
$pdf->Cell(60, 6, number_format($monthly_rate, 2), 0, 0, 'L');
$pdf->Cell(30, 6, 'SSS:', 0, 0, 'L');
$pdf->Cell(0, 6, $employee['SSS'] ?: 'N/A', 0, 1, 'L');

// Third row - Daily Rate and Philhealth
$pdf->Cell(40, 6, 'Daily Rate:', 0, 0, 'L');
$pdf->Cell(60, 6, number_format($daily_rate, 2), 0, 0, 'L');
$pdf->Cell(30, 6, 'Philhealth:', 0, 0, 'L');
$pdf->Cell(0, 6, $employee['PHIC'] ?: 'N/A', 0, 1, 'L');

// Fourth row - Tax Status (removed TIN as requested)
$pdf->Cell(40, 6, 'Tax Status:', 0, 0, 'L');
$pdf->Cell(60, 6, 'S', 0, 0, 'L');
$pdf->Cell(30, 6, '', 0, 0, 'L');
$pdf->Cell(0, 6, '', 0, 1, 'L');

// Add more spacing between employee details and earnings
$pdf->Ln(10);

// Earnings Section
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 6, 'EARNINGS', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);

// Basic Pay - use calculated basic salary from attendance
$pdf->Cell(40, 6, 'Basic Pay:', 0, 0, 'L');
$pdf->Cell(0, 6, number_format($basicSalary, 2), 0, 1, 'R');

// Absences (if any)
if ($attendance_summary['absences'] > 0) {
    $absence_deduction = $attendance_summary['absences'] * $daily_rate;
    $pdf->Cell(40, 6, 'Absences:', 0, 0, 'L');
    $pdf->Cell(20, 6, $attendance_summary['absences'] . 'D', 0, 0, 'L');
    $pdf->Cell(0, 6, '(' . number_format($absence_deduction, 2) . ')', 0, 1, 'R');
}

// Lates (if any)
if ($attendance_summary['late_minutes'] > 0) {
    $pdf->Cell(40, 6, 'Lates:', 0, 0, 'L');
    $pdf->Cell(20, 6, $attendance_summary['late_minutes'] . 'M', 0, 0, 'L');
    $pdf->Cell(0, 6, '(' . number_format($payroll['lates_amount'], 2) . ')', 0, 1, 'R');
}

// Overtime: (base_salary / 8) * 1.25 * OT_hours
$regular_ot = $payroll['overtime_pay'];
$nsd_ot = 0; // NSD OT no longer calculated separately
if ($regular_ot > 0) {
    $pdf->Cell(40, 6, 'Overtime Pay:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($regular_ot, 2), 0, 1, 'R');
}

// Special Holiday Pay (if any)
if ($payroll['special_holiday_pay'] > 0) {
    $pdf->Cell(40, 6, 'Special Holiday:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($payroll['special_holiday_pay'], 2), 0, 1, 'R');
}

// Legal Holiday Pay (if any)
if ($payroll['legal_holiday_pay'] > 0) {
    $pdf->Cell(40, 6, 'Legal Holiday:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($payroll['legal_holiday_pay'], 2), 0, 1, 'R');
}

// Night Shift Differential (if any) - only show for night shift employees
$shift = $employee['Shift'] ?? '';
$isNightShift = strpos($shift, '22:00') !== false || strpos($shift, '22:00-06:00') !== false || 
                stripos($shift, 'night') !== false || stripos($shift, 'nsd') !== false;

if ($payroll['night_shift_diff'] > 0 && $isNightShift) {
    $pdf->Cell(40, 6, 'Night Shift Diff:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($payroll['night_shift_diff'], 2), 0, 1, 'R');
}

// Leave Pay (if any)
if ($payroll['leave_pay'] > 0) {
    $pdf->Cell(40, 6, 'Leave Pay:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($payroll['leave_pay'], 2), 0, 1, 'R');
}

// 13th Month Pay (if any)
if ($payroll['thirteenth_month_pay'] > 0) {
    $pdf->Cell(40, 6, '13th Month Pay:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($payroll['thirteenth_month_pay'], 2), 0, 1, 'R');
}

// Employee Allowances - only show if employee has allowance values
if ($laundry_allowance > 0) {
    $pdf->Cell(40, 6, 'Laundry Allowance:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($laundry_allowance, 2), 0, 1, 'R');
}

if ($medical_allowance > 0) {
    $pdf->Cell(40, 6, 'Medical Allowance:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($medical_allowance, 2), 0, 1, 'R');
}

if ($rice_allowance > 0) {
    $pdf->Cell(40, 6, 'Rice Allowance:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($rice_allowance, 2), 0, 1, 'R');
}

// Total Gross Income - use calculated basic salary and include all allowances
$night_shift_amount = $isNightShift ? $payroll['night_shift_diff'] : 0;
$total_gross = $basicSalary + $regular_ot + $nsd_ot + $payroll['special_holiday_pay'] +
    $payroll['legal_holiday_pay'] + $night_shift_amount + $payroll['leave_pay'] +
    $payroll['thirteenth_month_pay'] + $laundry_allowance + $medical_allowance + $rice_allowance;

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(40, 6, 'Total Gross Income:', 0, 0, 'L');
$pdf->Cell(0, 6, number_format($total_gross, 2), 0, 1, 'R');

// Add more spacing between earnings and deductions
$pdf->Ln(10);

// Deductions Section
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 6, 'DEDUCTIONS', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);

// Late Deductions (if any)
if ($payroll['lates_amount'] > 0) {
    $pdf->Cell(40, 6, 'Late Deductions:', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($payroll['lates_amount'], 2), 0, 1, 'R');
}

// SSS Deduction
if ($payroll['sss_employee'] > 0) {
    $pdf->Cell(40, 6, 'SSS (Employee):', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($payroll['sss_employee'], 2), 0, 1, 'R');
}

// PhilHealth Deduction
if ($payroll['phic_employee'] > 0) {
    $pdf->Cell(40, 6, 'PhilHealth (Employee):', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($payroll['phic_employee'], 2), 0, 1, 'R');
}

// Pag-IBIG Deduction
if ($payroll['pagibig_employee'] > 0) {
    $pdf->Cell(40, 6, 'Pag-IBIG (Employee):', 0, 0, 'L');
    $pdf->Cell(0, 6, number_format($payroll['pagibig_employee'], 2), 0, 1, 'R');
}

// Additional deductions are now handled by payroll_computations.php
// No additional deductions from database since deductions table has been removed

// Total Deductions
$total_deductions = $payroll['total_deductions'] + $payroll['lates_amount'];
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(40, 6, 'Total Deduction:', 0, 0, 'L');
$pdf->Cell(0, 6, number_format($total_deductions, 2), 0, 1, 'R');

// Add more spacing before net pay
$pdf->Ln(10);

// Net Pay
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(40, 8, 'Net Pay:', 0, 0, 'L');
$pdf->Cell(0, 8, number_format($final_net_pay, 2), 0, 1, 'R');

$pdf->Ln(15);

// Acknowledgment
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, 'I hereby acknowledge to have received the sum as full payment of my service rendered.', 0, 1, 'L');

$pdf->Ln(15);

// Signature line
$pdf->Cell(0, 6, '_________________________', 0, 1, 'L');
$pdf->Cell(0, 6, 'Signature', 0, 1, 'L');

// Output PDF with error handling
try {
    $filename = 'Payslip_' . $employee['EmployeeID'] . '_' . $employee['EmployeeName'] . '_' . $month . '.pdf';
    $pdf->Output('D', $filename);
} catch (Exception $e) {
    die("Error generating PDF: " . $e->getMessage());
}

$conn->close();
?>