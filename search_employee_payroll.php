<?php
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'depthead') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Include centralized payroll computations
require_once 'payroll_computations.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "wteimain1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Handle AJAX requests
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    $department = isset($_POST['department']) ? $conn->real_escape_string($_POST['department']) : '';
    $month = isset($_POST['month']) ? $_POST['month'] : date('Y-m');
    
    // Get employees for the specific department
    $employees_query = "SELECT e.*, 
                       COUNT(DISTINCT DATE(a.attendance_date)) as days_worked,
                       COALESCE(SUM(TIMESTAMPDIFF(MINUTE, 
                           CONCAT(DATE(a.attendance_date), ' ', TIME(a.time_in)), 
                           CASE 
                               WHEN a.time_out IS NOT NULL THEN CONCAT(DATE(a.attendance_date), ' ', TIME(a.time_out))
                               ELSE CONCAT(DATE(a.attendance_date), ' 17:00:00')
                           END
                       )) / 60, 0) as total_hours,
                       COALESCE(e.base_salary, 0) as base_salary
                       FROM empuser e
                       LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID 
                       AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                       WHERE e.Department = ?
                       GROUP BY e.EmployeeID ORDER BY e.EmployeeName";

    $stmt = $conn->prepare($employees_query);
    $employees = [];
    if ($stmt) {
        $stmt->bind_param("ss", $month, $department);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        $stmt->close();
    }

    // Output employee cards
    if (empty($employees)) {
        echo '<div class="no-employees">
                <i class="fas fa-users"></i>
                <h3>No employees found</h3>
                <p>No employees found in this department for the selected period.</p>
              </div>';
    } else {
        foreach ($employees as $employee) {
            $payroll = calculatePayroll($employee['EmployeeID'], $employee['base_salary'], $month, $conn);
            
            // Get additional employee data
            $month_start = date('Y-m-01', strtotime($month . '-01'));
            $month_end = date('Y-m-t', strtotime($month . '-01'));
            $working_days = 0;
            $current_date = strtotime($month_start);
            $end_date = strtotime($month_end);
            while ($current_date <= $end_date) {
                if (date('w', $current_date) != 0) {
                    $working_days++;
                }
                $current_date = strtotime('+1 day', $current_date);
            }
            $monthly_rate = $employee['base_salary'] * $working_days;
            $daily_rate = $employee['base_salary'];
            
            // Get absences
            $absencesQuery = "SELECT COUNT(*) as absence_count 
                              FROM (
                                  SELECT DATE(attendance_date) as date 
                                  FROM attendance 
                                  WHERE EmployeeID = ? 
                                  AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
                                  AND status = 'absent'
                                  AND DAYOFWEEK(attendance_date) != 1
                              ) as absences";
            $stmt = $conn->prepare($absencesQuery);
            $absences = 0;
            if ($stmt) {
                $stmt->bind_param("is", $employee['EmployeeID'], $month);
                $stmt->execute();
                $absResult = $stmt->get_result();
                $absRow = $absResult->fetch_assoc();
                $absences = $absRow ? intval($absRow['absence_count']) : 0;
                $stmt->close();
            }
            
                              // Get late minutes using employee's shift start time
                              $lateQuery = "SELECT SUM(
                                CASE 
                                    WHEN TIME(a.time_in) > CONCAT(SUBSTRING_INDEX(e.Shift, '-', 1), ':00') 
                                    THEN TIMESTAMPDIFF(MINUTE, CONCAT(SUBSTRING_INDEX(e.Shift, '-', 1), ':00'), TIME(a.time_in))
                                    ELSE 0 
                                END
                            ) as late_minutes 
                            FROM attendance a
                            JOIN empuser e ON a.EmployeeID = e.EmployeeID
                            WHERE a.EmployeeID = ? 
                            AND a.status = 'late' 
                            AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?";
            $stmt = $conn->prepare($lateQuery);
            $late_minutes = 0;
            if ($stmt) {
                $stmt->bind_param("is", $employee['EmployeeID'], $month);
                $stmt->execute();
                $lateResult = $stmt->get_result();
                $lateRow = $lateResult->fetch_assoc();
                $late_minutes = $lateRow && $lateRow['late_minutes'] ? intval($lateRow['late_minutes']) : 0;
                $stmt->close();
            }
            ?>
            <div class="dept-employee-card" 
                 data-employee-id="<?php echo $employee['EmployeeID']; ?>"
                 data-employee-name="<?php echo htmlspecialchars($employee['EmployeeName']); ?>"
                 data-department="<?php echo htmlspecialchars($employee['Department']); ?>"
                 data-days-worked="<?php echo intval($employee['days_worked']); ?>"
                 data-total-hours="<?php echo number_format($employee['total_hours'], 1); ?>"
                 data-base-salary="<?php echo number_format($employee['base_salary'], 2); ?>"
                 data-overtime-pay="<?php echo number_format($payroll['overtime_pay'], 2); ?>"
                 data-special-holiday-pay="<?php echo number_format($payroll['special_holiday_pay'], 2); ?>"
                 data-legal-holiday-pay="<?php echo number_format($payroll['legal_holiday_pay'], 2); ?>"
                 data-night-shift-diff="<?php echo number_format($payroll['night_shift_diff'], 2); ?>"
                 data-gross-pay="<?php echo number_format($payroll['gross_pay'], 2); ?>"
                 data-leave-pay="<?php echo number_format($payroll['leave_pay'], 2); ?>"
                 data-13th-month="<?php echo number_format($payroll['thirteenth_month_pay'], 2); ?>"
                 data-lates="<?php echo number_format($payroll['lates_amount'], 2); ?>"
                 data-phic-employee="<?php echo number_format($payroll['phic_employee'], 2); ?>"
                 data-pagibig-employee="<?php echo number_format($payroll['pagibig_employee'], 2); ?>"
                 data-sss-employee="<?php echo number_format($payroll['sss_employee'], 2); ?>"
                 data-total-deductions="<?php echo number_format($payroll['total_deductions'], 2); ?>"
                 data-net-pay="<?php echo number_format($payroll['net_pay'], 2); ?>"
                 data-laundry-allowance="<?php echo number_format($payroll['laundry_allowance'], 2); ?>"
                 data-medical-allowance="<?php echo number_format($payroll['medical_allowance'], 2); ?>"
                 data-rice-allowance="<?php echo number_format($payroll['rice_allowance'], 2); ?>"
                 data-monthly-rate="<?php echo number_format($monthly_rate, 2); ?>"
                 data-daily-rate="<?php echo number_format($daily_rate, 2); ?>"
                 data-absences="<?php echo $absences; ?>"
                 data-late-mins="<?php echo $late_minutes; ?>"
                 data-sss="<?php echo $employee['SSS'] ?? 'N/A'; ?>"
                 data-philhealth="<?php echo $employee['PHIC'] ?? 'N/A'; ?>"
                 data-pagibig="<?php echo $employee['HDMF'] ?? 'N/A'; ?>"
                 data-tin="<?php echo $employee['TIN'] ?? 'N/A'; ?>"
                 onclick="viewPayslip('<?php echo $employee['EmployeeID']; ?>')">
                <div class="dept-employee-header">
                    <div class="dept-employee-avatar">
                        <?php echo strtoupper(substr($employee['EmployeeName'], 0, 1)); ?>
                    </div>
                    <div class="dept-employee-info">
                        <h4><?php echo htmlspecialchars($employee['EmployeeName']); ?></h4>
                        <p>ID: <?php echo htmlspecialchars($employee['EmployeeID']); ?></p>
                    </div>
                </div>
                
                <div class="dept-payroll-computation">
                    <div class="dept-computation-item">
                        <div class="dept-computation-label">Days Worked</div>
                        <div class="dept-computation-value"><?php echo intval($employee['days_worked']); ?></div>
                    </div>
                    <div class="dept-computation-item">
                        <div class="dept-computation-label">Total Hours</div>
                        <div class="dept-computation-value"><?php echo number_format($employee['total_hours'], 1); ?>h</div>
                    </div>
                    <div class="dept-computation-item">
                        <div class="dept-computation-label">Gross Pay</div>
                        <div class="dept-computation-value">₱<?php echo number_format($payroll['gross_pay'], 2); ?></div>
                    </div>
                    <div class="dept-computation-item">
                        <div class="dept-computation-label">Deductions</div>
                        <div class="dept-computation-value">-₱<?php echo number_format($payroll['total_deductions'], 2); ?></div>
                    </div>
                </div>
                
                <div class="dept-net-pay-banner">
                    <div class="dept-net-pay-label">Net Pay (Σ)</div>
                    <div class="dept-net-pay-value">₱<?php echo number_format($payroll['net_pay'], 2); ?></div>
                </div>
            </div>
            <?php
        }
    }
    exit;
}

// Handle regular search requests
if (isset($_GET['query'])) {
    $query = $conn->real_escape_string($_GET['query']);
    $month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
    
    $search_query = "SELECT e.*, 
                    COUNT(DISTINCT DATE(a.attendance_date)) as days_worked,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE, 
                        CONCAT(DATE(a.attendance_date), ' ', TIME(a.time_in)), 
                        CASE 
                            WHEN a.time_out IS NOT NULL THEN CONCAT(DATE(a.attendance_date), ' ', TIME(a.time_out))
                            ELSE CONCAT(DATE(a.attendance_date), ' 17:00:00')
                        END
                    )) / 60, 0) as total_hours,
                    COALESCE(e.base_salary, 0) as base_salary
                    FROM empuser e
                    LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID 
                    AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                    WHERE (e.EmployeeName LIKE ? OR e.EmployeeID LIKE ?)
                    GROUP BY e.EmployeeID ORDER BY e.EmployeeName LIMIT 10";

    $stmt = $conn->prepare($search_query);
    $employees = [];
    if ($stmt) {
        $search_term = "%$query%";
        $stmt->bind_param("sss", $month, $search_term, $search_term);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        $stmt->close();
    }

    echo json_encode($employees);
    exit;
}

$conn->close();
?>