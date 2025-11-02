<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = $_POST['employee'];
    $date_from = $_POST['date_from'];
    $date_to = $_POST['date_to'];

    // Get employee details
    $emp_query = "SELECT * FROM empuser WHERE EmployeeID = ?";
    $stmt = $conn->prepare($emp_query);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();

    // Get attendance records for the period
    $attendance_query = "SELECT COUNT(*) as days_present 
                        FROM attendance 
                        WHERE EmployeeID = ? 
                        AND attendance_date BETWEEN ? AND ?
                        AND status = 'present'";
    $stmt = $conn->prepare($attendance_query);
    $stmt->bind_param("iss", $employee_id, $date_from, $date_to);
    $stmt->execute();
    $attendance = $stmt->get_result()->fetch_assoc();
    $days_worked = $attendance['days_present'];

    // Calculate salary
    $daily_rate = $employee['base_salary'] / 22; // Assuming 22 working days per month
    $gross_pay = $daily_rate * $days_worked;

    // Calculate deductions
    $sss = $gross_pay * 0.0363; // 3.63% SSS
    $philhealth = $gross_pay * 0.03; // 3% PhilHealth
    $pagibig = 100; // Fixed Pag-IBIG
    $total_deductions = $sss + $philhealth + $pagibig;

    // Calculate net pay
    $net_pay = $gross_pay - $total_deductions;

    // Insert into payroll table
    $insert_query = "INSERT INTO payroll (
        EmployeeID,
        gross_pay,
        total_deductions,
        net_pay,
        payment_date,
        payment_type,
        description
    ) VALUES (?, ?, ?, ?, CURDATE(), 'salary', ?)";

    $description = "Payroll for period: " . date('M d, Y', strtotime($date_from)) . 
                  " to " . date('M d, Y', strtotime($date_to)) . 
                  " | Days worked: " . $days_worked;

    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("iddds", 
        $employee_id,    // i - integer
        $gross_pay,      // d - double
        $total_deductions, // d - double
        $net_pay,        // d - double
        $description     // s - string
    );

    if ($stmt->execute()) {
        // Generate printable payslip
        $payslip = '
        <!DOCTYPE html>
        <html>
        <head>
            <title>Payslip</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .payslip { max-width: 800px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; }
                .header { text-align: center; margin-bottom: 20px; }
                .info-row { display: flex; margin-bottom: 10px; }
                .label { width: 200px; font-weight: bold; }
                .value { flex: 1; }
                .section { margin: 20px 0; }
                .total { font-weight: bold; margin-top: 10px; }
            </style>
        </head>
        <body>
            <div class="payslip">
                <div class="header">
                    <h2>PAYSLIP</h2>
                    <p>Period: ' . date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to)) . '</p>
                </div>
                
                <div class="section">
                    <div class="info-row">
                        <div class="label">Employee Name:</div>
                        <div class="value">' . htmlspecialchars($employee['EmployeeName']) . '</div>
                    </div>
                    <div class="info-row">
                        <div class="label">Department:</div>
                        <div class="value">' . htmlspecialchars($employee['Department']) . '</div>
                    </div>
                    <div class="info-row">
                        <div class="label">Days Worked:</div>
                        <div class="value">' . $days_worked . '</div>
                    </div>
                </div>
                
                <div class="section">
                    <h3>Earnings</h3>
                    <div class="info-row">
                        <div class="label">Gross Pay:</div>
                        <div class="value">₱' . number_format($gross_pay, 2) . '</div>
                    </div>
                </div>
                
                <div class="section">
                    <h3>Deductions</h3>
                    <div class="info-row">
                        <div class="label">SSS:</div>
                        <div class="value">₱' . number_format($sss, 2) . '</div>
                    </div>
                    <div class="info-row">
                        <div class="label">PhilHealth:</div>
                        <div class="value">₱' . number_format($philhealth, 2) . '</div>
                    </div>
                    <div class="info-row">
                        <div class="label">Pag-IBIG:</div>
                        <div class="value">₱' . number_format($pagibig, 2) . '</div>
                    </div>
                    <div class="info-row total">
                        <div class="label">Total Deductions:</div>
                        <div class="value">₱' . number_format($total_deductions, 2) . '</div>
                    </div>
                </div>
                
                <div class="section">
                    <div class="info-row total">
                        <div class="label">NET PAY:</div>
                        <div class="value">₱' . number_format($net_pay, 2) . '</div>
                    </div>
                </div>
            </div>
            <script>
                window.onload = function() { window.print(); }
            </script>
        </body>
        </html>';

        // Store payslip in session and redirect
        $_SESSION['payslip'] = $payslip;
        $_SESSION['success'] = "Payslip generated successfully";
        header("Location: AdminPayroll.php");
        exit;
    } else {
        $_SESSION['error'] = "Error generating payslip: " . $conn->error;
        header("Location: AdminPayroll.php");
        exit;
    }
}

$conn->close();
?>
