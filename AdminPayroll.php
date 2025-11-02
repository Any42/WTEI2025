<?php
session_start();

// Prevent caching to ensure current month is always displayed
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in and is an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
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
    // Set timezone to ensure correct date calculation
    date_default_timezone_set('Asia/Manila');
    
    // AdminPayroll.php AJAX always uses current month - ignore month parameter
    $current_month = date('Y-m'); // Always use actual current month
    $department_filter = isset($_POST['department']) ? $conn->real_escape_string($_POST['department']) : '';
    
    // Debug: Log the request
    error_log('AJAX Request - Department: ' . $department_filter . ', Month: ' . $current_month);
    
    // Get employees for payroll generation (current month only)
    // Explicitly select all needed fields including Government IDs from empuser table
    $employees_query = "SELECT e.EmployeeID, e.EmployeeName, e.Department, 
                       COALESCE(e.base_salary, 0) as base_salary,
                       e.SSS, e.PHIC, e.TIN, e.HDMF, e.Shift,
                       COUNT(DISTINCT DATE(a.attendance_date)) as days_worked,
                       COALESCE(SUM(a.total_hours), 0) as total_hours
                       FROM empuser e
                       LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID 
                       AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                       AND a.time_in IS NOT NULL
                       WHERE e.Status = 'active'";

    $params = [$current_month];
    $types = "s";

    if ($department_filter) {
        $employees_query .= " AND e.Department = ?";
        $params[] = $department_filter;
        $types .= "s";
    }

    $employees_query .= " GROUP BY e.EmployeeID, e.EmployeeName, e.Department, e.base_salary, e.SSS, e.PHIC, e.TIN, e.HDMF, e.Shift 
                         ORDER BY e.Department, e.EmployeeName";

    $stmt = $conn->prepare($employees_query);
    $employees = [];
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        $stmt->close();
    }

    // Group employees by department
    $employeesByDept = [];
    foreach ($employees as $employee) {
        $dept = $employee['Department'] ?: 'Unassigned';
        if (!isset($employeesByDept[$dept])) {
            $employeesByDept[$dept] = [];
        }
        $employeesByDept[$dept][] = $employee;
    }
    ksort($employeesByDept);
    
    // Debug: Log departments found
    error_log('Departments found: ' . implode(', ', array_keys($employeesByDept)));
    error_log('Selected department: ' . $department_filter);

    // Return only the selected department or all departments if no filter
    if (empty($employeesByDept)) {
        echo '<div class="no-results">
            <i class="fas fa-inbox"></i>
            <h3>No payroll data found</h3>
            <p>Try adjusting your filters</p>
        </div>';
    } else {
        // If a specific department is selected, show only that department
        if ($department_filter && isset($employeesByDept[$department_filter])) {
            $deptName = $department_filter;
            $deptEmployees = $employeesByDept[$department_filter];
            
            // Calculate department totals
            $dept_total = 0;
            foreach ($deptEmployees as $emp) {
                $payroll = calculatePayroll($emp['EmployeeID'], $emp['base_salary'], $current_month, $conn);
                $dept_total += $payroll['net_pay'];
            }
            ?>
            <div class="department-section collapsed" data-department="<?php echo htmlspecialchars($deptName); ?>">
                <div class="department-header" onclick="toggleDepartment(this)">
                    <div class="department-info">
                        <div class="department-icon">
                            <?php echo strtoupper(substr($deptName, 0, 1)); ?>
                        </div>
                        <div class="department-details">
                            <h2><?php echo htmlspecialchars($deptName); ?></h2>
                            <p style="color: rgba(255, 255, 255, 0.8); font-size: 13px; margin-top: 5px; font-family: 'JetBrains Mono', monospace;">
                                Department Code: <?php echo strtoupper(substr($deptName, 0, 3)); ?>
                            </p>
                        </div>
                    </div>
                    <div class="department-stats">
                        <div class="department-stat">
                            <div class="department-stat-value"><?php echo count($deptEmployees); ?></div>
                            <div class="department-stat-label">Employees</div>
                        </div>
                        <div class="department-stat">
                            <div class="department-stat-value">₱<?php echo number_format($dept_total, 0); ?></div>
                            <div class="department-stat-label">Total Payroll</div>
                        </div>
                        <div class="department-toggle">
                            <i class="fas fa-chevron-down"></i>
                        </div>
                    </div>
                </div>
                
                <div class="department-search">
                    <div class="department-search-label">
                        <i class="fas fa-search"></i> Search Employees:
                    </div>
                    <div class="department-search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="department-search-input" placeholder="Search by name or ID..." data-department="<?php echo htmlspecialchars($deptName); ?>">
                        <i class="fas fa-times clear-icon"></i>
                    </div>
                </div>
                
                <div class="employees-grid">
                    <?php foreach ($deptEmployees as $employee): 
                        $payroll = calculatePayroll($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                        
                        // Get additional employee data
                        $month_start = date('Y-m-01', strtotime($current_month . '-01'));
                        $month_end = date('Y-m-t', strtotime($current_month . '-01'));
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
                            $stmt->bind_param("is", $employee['EmployeeID'], $current_month);
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
                            $stmt->bind_param("is", $employee['EmployeeID'], $current_month);
                            $stmt->execute();
                            $lateResult = $stmt->get_result();
                            $lateRow = $lateResult->fetch_assoc();
                            $late_minutes = $lateRow && $lateRow['late_minutes'] ? intval($lateRow['late_minutes']) : 0;
                            $stmt->close();
                        }
                        
                        // Get overtime hours breakdown
                        $otHoursBreakdown = calculateOvertimeHoursBreakdown($employee['EmployeeID'], $current_month, $conn);
                        $total_overtime_hours = $otHoursBreakdown['regular_ot_hours'] + $otHoursBreakdown['nsd_ot_hours'];
                        $nsd_overtime_hours = 0; // NSD OT hours no longer separated
                        $overtimeResult = calculateOvertimePay($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                        $holidayPay = calculateHolidayPay($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                        $basicSalaryEarned = $payroll['basic_salary'];
                        
                        // OT calculation: (base_salary / 8) * 1.25 * OT_hours
                        $regular_overtime_pay = $overtimeResult['total_overtime_pay'];
                    ?>
                        <div class="employee-payroll-card" 
                             data-employee-id="<?php echo $employee['EmployeeID']; ?>"
                             data-employee-name="<?php echo htmlspecialchars($employee['EmployeeName']); ?>"
                             data-department="<?php echo htmlspecialchars($employee['Department'] ?? ''); ?>"
                             data-days-worked="<?php echo intval($employee['days_worked']); ?>"
                             data-total-hours="<?php echo number_format($employee['total_hours'], 2); ?>"
                             data-base-salary="<?php echo number_format($employee['base_salary'], 2); ?>"
                             data-basic-salary-earned="<?php echo number_format($basicSalaryEarned, 2); ?>"
                             data-gross-pay="<?php echo number_format($payroll['gross_pay'], 2); ?>"
                             data-leave-pay="<?php echo number_format($payroll['leave_pay'], 2); ?>"
                             data-13th-month="<?php echo number_format($payroll['thirteenth_month_pay'], 2); ?>"
                             data-lates="<?php echo number_format($payroll['lates_amount'], 2); ?>"
                             data-phic-employee="<?php echo number_format($payroll['phic_employee'], 2); ?>"
                             data-pagibig-employee="<?php echo number_format($payroll['pagibig_employee'], 2); ?>"
                             data-sss-employee="<?php echo number_format($payroll['sss_employee'] ?? 0, 2); ?>"
                             data-total-deductions="<?php echo number_format($payroll['total_deductions'], 2); ?>"
                             data-net-pay="<?php echo number_format($payroll['net_pay'], 2); ?>"
                             data-monthly-rate="<?php echo number_format($monthly_rate, 2); ?>"
                             data-daily-rate="<?php echo number_format($daily_rate, 2); ?>"
                             data-absences="<?php echo $absences; ?>"
                             data-late-mins="<?php echo $late_minutes; ?>"
                             data-overtime-hours="<?php echo number_format($total_overtime_hours, 3); ?>"
                             data-nsd-overtime-hours="<?php echo number_format($nsd_overtime_hours, 3); ?>"
                             data-overtime-pay="<?php echo number_format($regular_overtime_pay, 2); ?>"
                             data-nsd-overtime-pay="0.00"
                             data-special-holiday-pay="<?php echo number_format($holidayPay['special_holiday'], 2); ?>"
                             data-legal-holiday-pay="<?php echo number_format($holidayPay['legal_holiday'], 2); ?>"
                             data-sss="<?php echo htmlspecialchars($employee['SSS'] ?? ''); ?>"
                             data-phic="<?php echo htmlspecialchars($employee['PHIC'] ?? ''); ?>"
                             data-tin="<?php echo htmlspecialchars($employee['TIN'] ?? ''); ?>"
                             data-hdmf="<?php echo htmlspecialchars($employee['HDMF'] ?? ''); ?>"
                             data-current-month="<?php echo htmlspecialchars($current_month); ?>"
                             onclick="viewPayslip(this)">
                            <div class="employee-card-header">
                                <div class="employee-avatar">
                                    <?php echo strtoupper(substr($employee['EmployeeName'], 0, 1)); ?>
                                </div>
                                <div class="employee-card-info">
                                    <h3><?php echo htmlspecialchars($employee['EmployeeName']); ?></h3>
                                    <p>ID: <?php echo htmlspecialchars($employee['EmployeeID']); ?></p>
                                </div>
                            </div>
                            
                            <div class="payroll-computation">
                                <div class="computation-item">
                                    <div class="computation-label">Days Worked</div>
                                    <div class="computation-value"><?php echo intval($employee['days_worked']); ?></div>
                                </div>
                                <div class="computation-item">
                                    <div class="computation-label">Total Hours</div>
                                    <div class="computation-value"><?php echo number_format($employee['total_hours'], 1); ?>h</div>
                                </div>
                                <div class="computation-item">
                                    <div class="computation-label">Gross Pay</div>
                                    <div class="computation-value">₱<?php echo number_format($payroll['gross_pay'], 2); ?></div>
                                </div>
                                <div class="computation-item">
                                    <div class="computation-label">Deductions</div>
                                    <div class="computation-value">-₱<?php echo number_format($payroll['total_deductions'], 2); ?></div>
                                </div>
                            </div>
                            
                            <div class="net-pay-banner">
                                <div class="net-pay-label">Net Pay (Σ)</div>
                                <div class="net-pay-value">₱<?php echo number_format($payroll['net_pay'], 2); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php } else {
            // Show all departments
            foreach ($employeesByDept as $deptName => $deptEmployees) {
                // Calculate department totals
                $dept_total = 0;
                foreach ($deptEmployees as $emp) {
                    $payroll = calculatePayroll($emp['EmployeeID'], $emp['base_salary'], $current_month, $conn);
                    $dept_total += $payroll['net_pay'];
                }
                ?>
                <div class="department-section collapsed" data-department="<?php echo htmlspecialchars($deptName); ?>">
                    <div class="department-header" onclick="toggleDepartment(this)">
                        <div class="department-info">
                            <div class="department-icon">
                                <?php echo strtoupper(substr($deptName, 0, 1)); ?>
                            </div>
                            <div class="department-details">
                                <h2><?php echo htmlspecialchars($deptName); ?></h2>
                                <p style="color: rgba(255, 255, 255, 0.8); font-size: 13px; margin-top: 5px; font-family: 'JetBrains Mono', monospace;">
                                    Department Code: <?php echo strtoupper(substr($deptName, 0, 3)); ?>
                                </p>
                            </div>
                        </div>
                        <div class="department-stats">
                            <div class="department-stat">
                                <div class="department-stat-value"><?php echo count($deptEmployees); ?></div>
                                <div class="department-stat-label">Employees</div>
                            </div>
                            <div class="department-stat">
                                <div class="department-stat-value">₱<?php echo number_format($dept_total, 0); ?></div>
                                <div class="department-stat-label">Total Payroll</div>
                            </div>
                            <div class="department-toggle">
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="department-search">
                        <div class="department-search-label">
                            <i class="fas fa-search"></i> Search Employees:
                        </div>
                        <div class="department-search-box">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" class="department-search-input" placeholder="Search by name or ID..." data-department="<?php echo htmlspecialchars($deptName); ?>">
                            <i class="fas fa-times clear-icon"></i>
                        </div>
                    </div>
                    
                    <div class="employees-grid">
                        <?php foreach ($deptEmployees as $employee): 
                            $payroll = calculatePayroll($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                            
                            // Get additional employee data
                            $month_start = date('Y-m-01', strtotime($current_month . '-01'));
                            $month_end = date('Y-m-t', strtotime($current_month . '-01'));
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
                                $stmt->bind_param("is", $employee['EmployeeID'], $current_month);
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
                                $stmt->bind_param("is", $employee['EmployeeID'], $current_month);
                                $stmt->execute();
                                $lateResult = $stmt->get_result();
                                $lateRow = $lateResult->fetch_assoc();
                                $late_minutes = $lateRow && $lateRow['late_minutes'] ? intval($lateRow['late_minutes']) : 0;
                                $stmt->close();
                            }
                            
                            // Get overtime hours breakdown
                            $otHoursBreakdown = calculateOvertimeHoursBreakdown($employee['EmployeeID'], $current_month, $conn);
                            $total_overtime_hours = $otHoursBreakdown['regular_ot_hours'] + $otHoursBreakdown['nsd_ot_hours'];
                            $nsd_overtime_hours = 0; // NSD OT hours no longer separated
                            $overtimeResult = calculateOvertimePay($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                            $holidayPay = calculateHolidayPay($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                            $basicSalaryEarned = $payroll['basic_salary'];
                            
                            // OT calculation: (base_salary / 8) * 1.25 * OT_hours
                            $regular_overtime_pay = $overtimeResult['total_overtime_pay'];
                        ?>
                            <div class="employee-payroll-card" 
                                 data-employee-id="<?php echo $employee['EmployeeID']; ?>"
                                 data-employee-name="<?php echo htmlspecialchars($employee['EmployeeName']); ?>"
                                 data-department="<?php echo htmlspecialchars($employee['Department'] ?? ''); ?>"
                                 data-days-worked="<?php echo intval($employee['days_worked']); ?>"
                                 data-total-hours="<?php echo number_format($employee['total_hours'], 2); ?>"
                                 data-base-salary="<?php echo number_format($employee['base_salary'], 2); ?>"
                                 data-basic-salary-earned="<?php echo number_format($basicSalaryEarned, 2); ?>"
                                 data-gross-pay="<?php echo number_format($payroll['gross_pay'], 2); ?>"
                                 data-leave-pay="<?php echo number_format($payroll['leave_pay'], 2); ?>"
                                 data-13th-month="<?php echo number_format($payroll['thirteenth_month_pay'], 2); ?>"
                                 data-lates="<?php echo number_format($payroll['lates_amount'], 2); ?>"
                                 data-phic-employee="<?php echo number_format($payroll['phic_employee'], 2); ?>"
                                 data-pagibig-employee="<?php echo number_format($payroll['pagibig_employee'], 2); ?>"
                                 data-sss-employee="<?php echo number_format($payroll['sss_employee'] ?? 0, 2); ?>"
                                 data-total-deductions="<?php echo number_format($payroll['total_deductions'], 2); ?>"
                                 data-net-pay="<?php echo number_format($payroll['net_pay'], 2); ?>"
                                 data-monthly-rate="<?php echo number_format($monthly_rate, 2); ?>"
                                 data-daily-rate="<?php echo number_format($daily_rate, 2); ?>"
                                 data-absences="<?php echo $absences; ?>"
                                 data-late-mins="<?php echo $late_minutes; ?>"
                                 data-overtime-hours="<?php echo number_format($total_overtime_hours, 3); ?>"
                                 data-nsd-overtime-hours="<?php echo number_format($nsd_overtime_hours, 3); ?>"
                                 data-overtime-pay="<?php echo number_format($regular_overtime_pay, 2); ?>"
                                 data-nsd-overtime-pay="<?php echo number_format($overtimeResult['nsd_overtime_pay'], 2); ?>"
                                 data-special-holiday-pay="<?php echo number_format($holidayPay['special_holiday'], 2); ?>"
                                 data-legal-holiday-pay="<?php echo number_format($holidayPay['legal_holiday'], 2); ?>"
                                 data-sss="<?php echo htmlspecialchars($employee['SSS'] ?? ''); ?>"
                                 data-phic="<?php echo htmlspecialchars($employee['PHIC'] ?? ''); ?>"
                                 data-tin="<?php echo htmlspecialchars($employee['TIN'] ?? ''); ?>"
                                 data-hdmf="<?php echo htmlspecialchars($employee['HDMF'] ?? ''); ?>"
                                 data-current-month="<?php echo htmlspecialchars($current_month); ?>"
                                 onclick="viewPayslip(this)">
                                <div class="employee-card-header">
                                    <div class="employee-avatar">
                                        <?php echo strtoupper(substr($employee['EmployeeName'], 0, 1)); ?>
                                    </div>
                                    <div class="employee-card-info">
                                        <h3><?php echo htmlspecialchars($employee['EmployeeName']); ?></h3>
                                        <p>ID: <?php echo htmlspecialchars($employee['EmployeeID']); ?></p>
                                    </div>
                                </div>
                                
                                <div class="payroll-computation">
                                    <div class="computation-item">
                                        <div class="computation-label">Days Worked</div>
                                        <div class="computation-value"><?php echo intval($employee['days_worked']); ?></div>
                                    </div>
                                    <div class="computation-item">
                                        <div class="computation-label">Total Hours</div>
                                        <div class="computation-value"><?php echo number_format($employee['total_hours'], 1); ?>h</div>
                                    </div>
                                    <div class="computation-item">
                                        <div class="computation-label">Gross Pay</div>
                                        <div class="computation-value">₱<?php echo number_format($payroll['gross_pay'], 2); ?></div>
                                    </div>
                                    <div class="computation-item">
                                        <div class="computation-label">Deductions</div>
                                        <div class="computation-value">-₱<?php echo number_format($payroll['total_deductions'], 2); ?></div>
                                    </div>
                                </div>
                                
                                <div class="net-pay-banner">
                                    <div class="net-pay-label">Net Pay (Σ)</div>
                                    <div class="net-pay-value">₱<?php echo number_format($payroll['net_pay'], 2); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php }
        }
    }
    exit;
}

// AdminPayroll.php always shows current month's payroll - ignore any month parameter in URL
// Set timezone to ensure correct date calculation (always set, don't check)
date_default_timezone_set('Asia/Manila'); // Philippines timezone

// Force current month - ALWAYS use the server's actual current month
// This ensures AdminPayroll.php always shows the current month, never past or future months
$current_month = date('Y-m'); // Gets current month from server (e.g., 2025-11 for November 2025)

// If a month parameter exists in URL, handle it (but always use current_month for display)
if (isset($_GET['month']) && !empty($_GET['month'])) {
    $requested_month = $_GET['month'];
    // Validate month format
    if (preg_match('/^\d{4}-\d{2}$/', $requested_month)) {
        // If requested month is in the past, redirect to PayrollHistory.php
        if ($requested_month < $current_month) {
            $redirect_url = 'PayrollHistory.php?month=' . urlencode($requested_month);
            if (isset($_GET['department']) && !empty($_GET['department'])) {
                $redirect_url .= '&department=' . urlencode($_GET['department']);
            }
            header("Location: " . $redirect_url);
            exit;
        }
        // If requested month is current month or future, use current_month (already set above)
        // We ignore the URL parameter and always use current month for AdminPayroll.php
    }
}

// Ensure $current_month is definitely the current month (in case of any issues)
$current_month = date('Y-m'); // Re-confirm current month

$department_filter = isset($_GET['department']) ? $conn->real_escape_string($_GET['department']) : '';

// Get employees for payroll generation (current month only)
// Explicitly select all needed fields including Government IDs from empuser table
$employees_query = "SELECT e.EmployeeID, e.EmployeeName, e.Department, 
                   COALESCE(e.base_salary, 0) as base_salary,
                   e.SSS, e.PHIC, e.TIN, e.HDMF, e.Shift,
                   COUNT(DISTINCT DATE(a.attendance_date)) as days_worked,
                   COALESCE(SUM(a.total_hours), 0) as total_hours
                   FROM empuser e
                   LEFT JOIN attendance a ON e.EmployeeID = a.EmployeeID 
                   AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                   AND a.time_in IS NOT NULL
                   WHERE e.Status = 'active'";

$params = [$current_month];
$types = "s";

if ($department_filter) {
    $employees_query .= " AND e.Department = ?";
    $params[] = $department_filter;
    $types .= "s";
}

$employees_query .= " GROUP BY e.EmployeeID, e.EmployeeName, e.Department, e.base_salary, e.SSS, e.PHIC, e.TIN, e.HDMF, e.Shift 
                     ORDER BY e.Department, e.EmployeeName";

$stmt = $conn->prepare($employees_query);
$employees = [];
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();
}

// Get departments for filter
$departments = [];
$dept_query = "SELECT DISTINCT Department FROM empuser WHERE Department IS NOT NULL AND Department != '' ORDER BY Department";
$dept_result = $conn->query($dept_query);
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row['Department'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Analytics - WTEI Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    
    <style>
    /* Luxury Mathematical/Computational Theme */
    :root {
        --primary-dark: #112D4E;
        --primary-blue: #3F72AF;
        --accent-cyan: #DBE2EF;
        --accent-purple: #F9F7F7;
        --bg-light: #F9F7F7;
        --text-dark: #112D4E;
        --border-light: #DBE2EF;
        --gradient-math: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        --gradient-data: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        --shadow-soft: 0 4px 15px rgba(0, 0, 0, 0.08);
        --shadow-hover: 0 8px 25px rgba(63, 114, 175, 0.2);
        
        /* Luxury Color Palette - Clear Premium Theme */
        --luxury-silver: #B8B8B8;
        --luxury-platinum: #D3D3D3;
        --luxury-steel: #A0A0A0;
        --luxury-pearl: #F5F5F5;
        --luxury-accent: #2C5F8A;
        --luxury-gradient: linear-gradient(135deg, #2C5F8A 0%, #4A90E2 50%, #87CEEB 100%);
        --luxury-shadow: 0 20px 40px rgba(44, 95, 138, 0.25);
        --luxury-glow: 0 0 30px rgba(44, 95, 138, 0.4);
    }

    /* Luxury Animations */
    @keyframes luxuryGlow {
        0%, 100% { box-shadow: 0 0 20px rgba(44, 95, 138, 0.3); }
        50% { box-shadow: 0 0 40px rgba(44, 95, 138, 0.5), 0 0 60px rgba(74, 144, 226, 0.4); }
    }

    @keyframes luxuryFloat {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-5px); }
    }

    @keyframes luxuryShimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }

    @keyframes luxuryPulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }

    /* Enhanced Analytics Cards */
    .analytics-card {
        position: relative;
        background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
        border: 2px solid transparent;
        background-clip: padding-box;
        border-radius: 20px;
        overflow: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        animation: luxuryFloat 6s ease-in-out infinite;
    }

    .analytics-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--luxury-gradient);
        border-radius: 20px;
        padding: 2px;
        mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
        mask-composite: exclude;
        -webkit-mask-composite: xor;
        z-index: -1;
    }

    .analytics-card:hover {
        transform: translateY(-10px) scale(1.02);
        animation: luxuryGlow 2s ease-in-out infinite;
        box-shadow: var(--luxury-shadow);
    }

    .analytics-card-value {
        color: var(--primary-dark);
        font-weight: 700;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .analytics-card-icon {
        background: var(--luxury-gradient);
        color: white;
        box-shadow: 0 4px 15px rgba(44, 95, 138, 0.4);
    }

    .analytics-card:hover .analytics-card-icon {
        animation: luxuryPulse 0.6s ease-in-out;
    }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
        font-family: 'Poppins', sans-serif;
        background: var(--bg-light);
        color: var(--text-dark);
            overflow-x: hidden;
        }
        
    /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background-color: #112D4E;
            padding: 20px 0;
            box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            color: white;
            position: fixed;
            height: 100vh;
            transition: all 0.3s ease;
        }
        
        .logo {
            font-weight: bold;
            font-size: 32px;
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 20px;
            color: #DBE2EF;
            letter-spacing: 2px;
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
            background-color: #3F72AF;
            color: #DBE2EF;
            transform: translateX(5px);
        }
        
        .menu-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            font-size: 18px;
        }
        
        .logout-btn {
            background-color: #3F72AF;
            color: white;
            border: none;
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
            background-color: #DBE2EF;
            color: #112D4E;
            transform: translateY(-2px);
        }
        
        .logout-btn i {
            margin-right: 10px;
        }
        
    /* Main Content */
        .main-content {
            margin-left: 280px;
        padding: 30px;
            min-height: 100vh;
        background: var(--bg-light);
        }
        
    /* Header with Mathematical Design */
        .header {
        background: white;
        padding: 30px;
        border-radius: 20px;
        box-shadow: var(--shadow-soft);
            margin-bottom: 30px;
        position: relative;
        overflow: hidden;
    }

    .header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
            width: 100%;
        height: 4px;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
    }

    .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        gap: 20px;
    }

    .header-title {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .header-title h1 {
        font-size: 32px;
        font-weight: 700;
        color: var(--primary-dark);
        font-family: 'JetBrains Mono', monospace;
        letter-spacing: -1px;
    }

    .header-title .formula-icon {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        border-radius: 12px;
            display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        font-weight: 700;
        font-family: 'JetBrains Mono', monospace;
    }

    /* Filter Bar with Computational Style */
    .filter-bar {
        background: white;
        padding: 25px 30px;
        border-radius: 20px;
        box-shadow: var(--shadow-soft);
        margin-bottom: 30px;
        display: flex;
        gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        
    .filter-group {
            display: flex;
        flex-direction: column;
            gap: 8px;
        flex: 1;
        min-width: 200px;
    }

    .filter-label {
        font-size: 12px;
        font-weight: 600;
        color: #3F72AF;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-family: 'JetBrains Mono', monospace;
    }

    .filter-select {
        padding: 12px 18px;
        border: 2px solid var(--border-light);
            border-radius: 12px;
            font-size: 14px;
        font-weight: 500;
        background: white;
        color: var(--text-dark);
        cursor: pointer;
        transition: all 0.3s ease;
        font-family: 'Poppins', sans-serif;
    }

    .filter-select:focus {
            outline: none;
            border-color: #3F72AF;
        box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.15);
    }
        
    .period-indicator {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 18px;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            color: white;
        border-radius: 12px;
        font-weight: 600;
        font-size: 14px;
    }

    .period-indicator i {
        font-size: 16px;
    }

    /* Analytics Cards */
    .analytics-grid {
            display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
            margin-bottom: 30px;
        }

    .analytics-card {
        background: white;
            padding: 25px;
        border-radius: 16px;
        box-shadow: var(--shadow-soft);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
    }

    .analytics-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
        width: 4px;
        height: 100%;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
    }

    .analytics-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
    }

    .analytics-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }

    .analytics-card-title {
        font-size: 13px;
        font-weight: 600;
        color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        font-family: 'JetBrains Mono', monospace;
    }

    .analytics-card-icon {
        width: 45px;
        height: 45px;
        border-radius: 12px;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 20px;
    }

    .analytics-card-value {
        font-size: 32px;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 10px;
        font-family: 'JetBrains Mono', monospace;
    }

    .analytics-card-description {
        font-size: 12px;
        color: #6c757d;
        line-height: 1.4;
        background: #f8f9fa;
        padding: 10px 12px;
        border-radius: 8px;
        margin-top: 10px;
        font-weight: 500;
    }

    /* Search Bar */
    .search-container {
        background: white;
        padding: 20px 25px;
            border-radius: 16px;
        box-shadow: var(--shadow-soft);
        margin-bottom: 30px;
        position: sticky;
        top: 20px;
        z-index: 50;
    }

    .search-box {
        position: relative;
            width: 100%;
    }

    .search-box input {
        width: 100%;
        padding: 15px 50px 15px 50px;
        border: 2px solid var(--border-light);
        border-radius: 12px;
        font-size: 15px;
        font-weight: 500;
        transition: all 0.3s ease;
        background: #f8f9fa;
    }

    .search-box input:focus {
        outline: none;
        border-color: #3F72AF;
        background: white;
        box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.15);
    }

    .search-box .search-icon {
        position: absolute;
        left: 18px;
        top: 50%;
        transform: translateY(-50%);
        color: #3F72AF;
            font-size: 18px;
    }

    .search-box .clear-icon {
        position: absolute;
        right: 18px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        font-size: 18px;
        cursor: pointer;
        display: none;
    }

    .search-box .clear-icon:hover {
        color: var(--primary-dark);
    }

    /* Department Grid */
    .departments-container {
        margin-top: 30px;
    }

    .department-section {
        background: white;
        border-radius: 20px;
        box-shadow: var(--shadow-soft);
        margin-bottom: 30px;
        overflow: hidden;
        transition: all 0.3s ease;
    }

    .department-header {
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
        padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .department-header:hover {
        background: linear-gradient(135deg, #112D4E 0%, #3F72AF 100%);
    }

    .department-info {
            display: flex;
        align-items: center;
        gap: 20px;
    }

    .department-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        font-weight: 700;
        color: white;
        font-family: 'JetBrains Mono', monospace;
    }

    .department-details h2 {
        font-size: 24px;
        font-weight: 700;
        color: white;
        margin-bottom: 5px;
        font-family: 'JetBrains Mono', monospace;
    }

    .department-stats {
        display: flex;
        gap: 30px;
        align-items: center;
    }

    .department-stat {
        text-align: center;
    }

    .department-stat-value {
        font-size: 24px;
        font-weight: 700;
        color: white;
        font-family: 'JetBrains Mono', monospace;
    }

    .department-stat-label {
        font-size: 11px;
        color: rgba(255, 255, 255, 0.8);
        text-transform: uppercase;
        letter-spacing: 1px;
        font-family: 'JetBrains Mono', monospace;
    }

    .department-toggle {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 20px;
            transition: all 0.3s ease;
        }

    .department-section.collapsed .department-toggle {
        transform: rotate(-90deg);
    }

    .department-section.collapsed .employees-grid {
        display: none;
    }

    .department-section.collapsed .department-search {
        display: none;
    }

    /* Department Search */
    .department-search {
        background: white;
        padding: 20px 30px;
        border-bottom: 1px solid #e9ecef;
            display: flex;
        align-items: center;
        gap: 15px;
    }

    .department-search-box {
        position: relative;
        flex: 1;
        max-width: 400px;
    }

    .department-search-box input {
        width: 100%;
        padding: 12px 40px 12px 40px;
        border: 2px solid #e9ecef;
        border-radius: 25px;
            font-size: 14px;
            transition: all 0.3s ease;
        background: #f8f9fa;
        outline: none;
    }

    .department-search-box input:focus {
        border-color: #3F72AF;
        background: white;
        box-shadow: 0 0 0 3px rgba(63, 114, 175, 0.15);
    }

    .department-search-box .search-icon {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
            color: #3F72AF;
        font-size: 16px;
    }

    .department-search-box .clear-icon {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        font-size: 16px;
        cursor: pointer;
        display: none;
    }

    .department-search-box .clear-icon:hover {
        color: #3F72AF;
    }

    .department-search-label {
        font-size: 14px;
        font-weight: 600;
            color: #112D4E;
        white-space: nowrap;
    }

    /* Employee Payroll Cards */
    .employees-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
        padding: 30px;
        background: #F9F7F7;
    }

    .employee-payroll-card {
        background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        border: 2px solid transparent;
        background-clip: padding-box;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        position: relative;
        overflow: hidden;
        animation: luxuryFloat 8s ease-in-out infinite;
    }

    .employee-payroll-card:hover {
        transform: translateY(-10px) scale(1.02);
        animation: luxuryGlow 2s ease-in-out infinite;
        box-shadow: var(--luxury-shadow);
    }

    .employee-payroll-card:hover::before {
        animation: luxuryShimmer 2s linear infinite;
    }

    .employee-card-header {
        display: flex;
        align-items: center;
        gap: 15px;
            margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f8f9fa;
    }

    .employee-avatar {
        width: 55px;
        height: 55px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3F72AF 0%, #112D4E 100%);
            display: flex;
            align-items: center;
        justify-content: center;
        color: white;
        font-size: 20px;
        font-weight: 700;
        font-family: 'JetBrains Mono', monospace;
        box-shadow: 0 4px 12px rgba(63, 114, 175, 0.3);
        transition: all 0.3s ease;
    }

    .employee-payroll-card:hover .employee-avatar {
        transform: scale(1.1) rotate(5deg);
    }

    .employee-card-info h3 {
        font-size: 16px;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 5px;
    }

    .employee-card-info p {
        font-size: 13px;
        color: #6c757d;
        font-family: 'JetBrains Mono', monospace;
    }

    .payroll-computation {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 15px;
    }

    .computation-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .computation-label {
        font-size: 11px;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-family: 'JetBrains Mono', monospace;
    }

    .computation-value {
        font-size: 15px;
        font-weight: 700;
        color: var(--primary-dark);
        font-family: 'JetBrains Mono', monospace;
    }

    .net-pay-banner {
        background: var(--luxury-gradient);
        padding: 20px;
        border-radius: 16px;
        text-align: center;
        margin-top: 20px;
        box-shadow: 0 8px 25px rgba(44, 95, 138, 0.3);
        position: relative;
        overflow: hidden;
    }

    .net-pay-banner::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
        transition: left 0.5s;
    }

    .net-pay-banner:hover::before {
        left: 100%;
    }

    .net-pay-label {
        font-size: 11px;
        color: rgba(255, 255, 255, 0.9);
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 5px;
        font-family: 'JetBrains Mono', monospace;
    }

    .net-pay-value {
        font-size: 26px;
        font-weight: 700;
            color: white;
        font-family: 'JetBrains Mono', monospace;
        }

    /* Payslip Modal */
    .payslip-modal {
            display: none;
            position: fixed;
            top: 0;
        left: 0;
            width: 100%;
            height: 100%;
        background: rgba(17, 45, 78, 0.95);
        backdrop-filter: blur(10px);
        z-index: 2000;
        overflow-y: auto;
        padding: 20px;
        animation: fadeIn 0.3s ease;
        align-items: center;
        justify-content: center;
    }

    .payslip-modal.show {
        display: flex !important;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .payslip-content {
        position: relative;
        background: white;
        margin: auto;
        width: 100%;
        max-width: 1200px;
        max-height: 90vh;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        overflow: hidden;
        animation: modalSlideUp 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex;
        flex-direction: column;
    }

    @keyframes modalSlideUp {
        from { 
            opacity: 0; 
            transform: translateY(60px) scale(0.9);
        }
        to { 
            opacity: 1; 
            transform: translateY(0) scale(1);
        }
        }

        .modal-close {
        font-size: 28px;
        color: white;
        cursor: pointer;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        transition: all 0.3s ease;
        border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .modal-close:hover {
        background: rgba(255, 255, 255, 0.35);
        transform: rotate(90deg) scale(1.15);
    }

    .payslip-header {
        background: #112D4E;
        color: white;
        padding: 40px;
        position: relative;
        text-align: center;
    }

    .header-actions {
        position: absolute;
        top: 20px;
        right: 20px;
        display: flex;
        gap: 15px;
        align-items: center;
    }

    .payslip-header h2 {
        font-size: 32px;
        font-weight: 700;
        margin: 30px 0 10px 0;
        text-transform: uppercase;
        letter-spacing: 2px;
    }

    .payslip-header p {
        font-size: 14px;
        opacity: 0.95;
        margin: 0;
    }

    .payslip-body {
        padding: 30px 40px;
        background: white;
        flex: 1;
        overflow-y: auto;
    }

    .employee-section {
        background: white;
        padding: 0 0 30px 0;
        margin-bottom: 30px;
    }

    .section-title {
        font-size: 18px;
        font-weight: 700;
        color: #112D4E;
        margin-bottom: 20px;
    }

    .employee-details-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 20px 30px;
    }

    .detail-group {
            display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .detail-group label {
        font-size: 12px;
        color: #6c757d;
        font-weight: 500;
        margin-bottom: 5px;
        display: block;
    }

    .detail-group span {
        font-size: 15px;
        font-weight: 700;
        color: #112D4E;
        display: block;
    }

    .payslip-main-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 30px;
        margin-bottom: 30px;
    }

    .payslip-section {
        background: white;
    }

    .breakdown-container {
        border: 1px solid #d1d5db;
        border-radius: 8px;
        padding: 20px;
        background: white;
    }

    .breakdown-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        font-size: 14px;
    }

    .breakdown-row span:first-child {
        color: #374151;
        font-weight: 500;
    }

    .breakdown-row span:last-child {
        color: #112D4E;
        font-weight: 700;
    }

    .breakdown-row.total-row {
        border-top: 2px solid #112D4E;
        margin-top: 10px;
        padding-top: 15px;
        font-weight: 700;
        color: #112D4E;
    }

    .summary-section {
        background: white;
        padding: 0;
        margin: 30px 0;
        display: flex;
        justify-content: space-between;
        gap: 20px;
    }

    .summary-item {
        flex: 1;
        text-align: center;
        padding: 25px 20px;
        background: #112D4E;
        border-radius: 8px;
    }

    .summary-item span:first-child {
        display: block;
        font-size: 13px;
        color: rgba(255, 255, 255, 0.9);
        margin-bottom: 10px;
        font-weight: 600;
    }

    .summary-item span:last-child {
        display: block;
        font-size: 24px;
        font-weight: 700;
        color: white;
    }

    .payslip-disclaimer {
        text-align: center;
        color: #9ca3af;
        font-size: 12px;
        padding: 20px 0;
        margin-top: 20px;
        border-top: 1px solid #e5e7eb;
    }

    /* No Results */
    .no-results {
        text-align: center;
        padding: 60px 20px;
        color: #6c757d;
    }

    .no-results i {
        font-size: 64px;
        margin-bottom: 20px;
        opacity: 0.3;
    }

    .no-results h3 {
        font-size: 20px;
        margin-bottom: 10px;
    }

    /* Logout Modal */
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

    /* Responsive Design */
    @media (max-width: 1024px) {
        .employees-grid {
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        }

        .employee-details {
            grid-template-columns: repeat(2, 1fr);
        }

        .payslip-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .main-content {
            margin-left: 0;
            padding: 20px;
        }

        .sidebar {
            transform: translateX(-100%);
        }

        .analytics-grid {
            grid-template-columns: 1fr;
        }

        .employees-grid {
            grid-template-columns: 1fr;
        }

        .department-stats {
                flex-direction: column;
            gap: 15px;
            }
            
        .summary-section {
            flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
    <img src="LOGO/newLogo_transparent.png" class="logo" style="width: 300px; height: 250px; object-fit: contain; margin-right: 50px;margin-bottom: 10px; margin-top: -20px; margin-left: -10px; padding-top: 40px; padding:-250px; padding-bottom: 20px;">
        <div class="menu">
            <a href="AdminHome.php" class="menu-item">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="AdminEmployees.php" class="menu-item">
                <i class="fas fa-users"></i> Employees
            </a>
            <a href="AdminAttendance.php" class="menu-item">
                <i class="fas fa-calendar-check"></i> Attendance
            </a>
            <a href="AdminPayroll.php" class="menu-item active">
                <i class="fas fa-money-bill-wave"></i> Payroll
            </a>
            <a href="AdminHistory.php" class="menu-item">
                <i class="fas fa-history"></i> History
            </a>
        </div>
        <a href="logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
    
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div class="header-title">
                    <div class="formula-icon">Σ</div>
                    <div>
                        <h1>Payroll Analytics</h1>
                        <p style="color: #6c757d; font-size: 14px; margin-top: 5px; font-family: 'JetBrains Mono', monospace;">
                            WTEI Payroll Analytics
                        </p>
                </div>
                </div>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label class="filter-label">📅 Current Period</label>
                <div class="period-indicator">
                    <i class="fas fa-calendar-alt"></i>
                    <span><?php echo date('F Y', strtotime($current_month . '-01')); ?></span>
                    </div>
                </div>
            <div class="filter-group">
                <label class="filter-label">🏢 Department</label>
                <select class="filter-select" id="departmentFilter" onchange="filterByDepartment()">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>"
                                <?php echo $department_filter === $dept ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                </div>
            </div>
            
        <!-- Analytics Cards -->
        <div class="analytics-grid">
            <div class="analytics-card">
                <div class="analytics-card-header">
                    <div>
                        <div class="analytics-card-title">Total Employees</div>
                        <div class="analytics-card-value"><?php echo count($employees); ?></div>
                    </div>
                    <div class="analytics-card-icon">
                        <i class="fas fa-users"></i>
                </div>
                </div>
                <div class="analytics-card-description">Active employees eligible for payroll processing this month</div>
            </div>
            
            <div class="analytics-card">
                <div class="analytics-card-header">
                    <div>
                        <div class="analytics-card-title">Total Payroll</div>
                        <div class="analytics-card-value">
                            <?php 
                            $total_payroll = 0;
                            foreach ($employees as $emp) {
                                $payroll = calculatePayroll($emp['EmployeeID'], $emp['base_salary'], $current_month, $conn);
                                $total_payroll += $payroll['net_pay'];
                            }
                            echo '₱' . number_format($total_payroll, 2);
                            ?>
                    </div>
                </div>
                    <div class="analytics-card-icon">
                        <i class="fas fa-calculator"></i>
                    </div>
                </div>
                <div class="analytics-card-description">Combined net pay for all employees after deductions</div>
            </div>
            
            <div class="analytics-card">
                <div class="analytics-card-header">
                    <div>
                        <div class="analytics-card-title">Average Salary</div>
                        <div class="analytics-card-value">
                            <?php 
                            $avg_salary = count($employees) > 0 ? $total_payroll / count($employees) : 0;
                            echo '₱' . number_format($avg_salary, 2);
                            ?>
                        </div>
                    </div>
                    <div class="analytics-card-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
                <div class="analytics-card-description">Mean salary per employee across all departments</div>
            </div>

            <div class="analytics-card">
                <div class="analytics-card-header">
                    <div>
                        <div class="analytics-card-title">Departments</div>
                        <div class="analytics-card-value"><?php echo count($departments); ?></div>
                    </div>
                    <div class="analytics-card-icon">
                        <i class="fas fa-building"></i>
                    </div>
                </div>
                <div class="analytics-card-description">Organizational units with active employees</div>
            </div>
        </div>
        
        <!-- Search Container -->
        <div class="search-container">
            <div class="search-box">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="employeeSearch" placeholder="Search employees by name or department..." autocomplete="off">
                <i class="fas fa-times clear-icon" id="clearSearch"></i>
                </div>
            </div>
            
        <!-- Departments Container -->
        <div class="departments-container">
            <?php
            // Group employees by department
            $employeesByDept = [];
            foreach ($employees as $employee) {
                $dept = $employee['Department'] ?: 'Unassigned';
                if (!isset($employeesByDept[$dept])) {
                    $employeesByDept[$dept] = [];
                }
                $employeesByDept[$dept][] = $employee;
            }
            ksort($employeesByDept);

            if (empty($employeesByDept)):
            ?>
                <div class="no-results">
                    <i class="fas fa-inbox"></i>
                    <h3>No payroll data found</h3>
                    <p>Try adjusting your filters</p>
                </div>
                    <?php else: ?>
                <?php foreach ($employeesByDept as $deptName => $deptEmployees): 
                    // Calculate department totals
                    $dept_total = 0;
                    foreach ($deptEmployees as $emp) {
                        $payroll = calculatePayroll($emp['EmployeeID'], $emp['base_salary'], $current_month, $conn);
                        $dept_total += $payroll['net_pay'];
                    }
                ?>
                    <div class="department-section collapsed" data-department="<?php echo htmlspecialchars($deptName); ?>">
                        <div class="department-header" onclick="toggleDepartment(this)">
                            <div class="department-info">
                                <div class="department-icon">
                                    <?php echo strtoupper(substr($deptName, 0, 1)); ?>
                                </div>
                                <div class="department-details">
                                    <h2><?php echo htmlspecialchars($deptName); ?></h2>
                                    <p style="color: rgba(255, 255, 255, 0.8); font-size: 13px; margin-top: 5px; font-family: 'JetBrains Mono', monospace;">
                                        Department Code: <?php echo strtoupper(substr($deptName, 0, 3)); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="department-stats">
                                <div class="department-stat">
                                    <div class="department-stat-value"><?php echo count($deptEmployees); ?></div>
                                    <div class="department-stat-label">Employees</div>
                                </div>
                                <div class="department-stat">
                                    <div class="department-stat-value">₱<?php echo number_format($dept_total, 0); ?></div>
                                    <div class="department-stat-label">Total Payroll</div>
                                </div>
                                <div class="department-toggle">
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="department-search">
                            <div class="department-search-label">
                                <i class="fas fa-search"></i> Search Employees:
                            </div>
                            <div class="department-search-box">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" class="department-search-input" placeholder="Search by name or ID..." data-department="<?php echo htmlspecialchars($deptName); ?>">
                                <i class="fas fa-times clear-icon"></i>
                            </div>
                        </div>
                        
                        <div class="employees-grid">
                            <?php foreach ($deptEmployees as $employee): 
                                $payroll = calculatePayroll($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                                
                                // Get additional employee data
                                $month_start = date('Y-m-01', strtotime($current_month . '-01'));
                                $month_end = date('Y-m-t', strtotime($current_month . '-01'));
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
                                    $stmt->bind_param("is", $employee['EmployeeID'], $current_month);
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
                                    $stmt->bind_param("is", $employee['EmployeeID'], $current_month);
                                    $stmt->execute();
                                    $lateResult = $stmt->get_result();
                                    $lateRow = $lateResult->fetch_assoc();
                                    $late_minutes = $lateRow && $lateRow['late_minutes'] ? intval($lateRow['late_minutes']) : 0;
                                    $stmt->close();
                                }
                                
                                // Get overtime hours breakdown using 10pm cutoff logic
                                $otHoursBreakdown = calculateOvertimeHoursBreakdown($employee['EmployeeID'], $current_month, $conn);
                                $total_overtime_hours = $otHoursBreakdown['regular_ot_hours'];
                                $nsd_overtime_hours = $otHoursBreakdown['nsd_ot_hours'];
                                $overtimeResult = calculateOvertimePay($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                                $holidayPay = calculateHolidayPay($employee['EmployeeID'], $employee['base_salary'], $current_month, $conn);
                                $basicSalaryEarned = $payroll['basic_salary'];
                                
                                // Calculate regular overtime pay: base OT pay for regular OT hours only
                                $hourlyRate = $employee['base_salary'] / 8;
                                $regular_overtime_pay = $hourlyRate * 1.25 * $total_overtime_hours;
                            ?>
                                <div class="employee-payroll-card" 
                                     data-employee-id="<?php echo $employee['EmployeeID']; ?>"
                                     data-employee-name="<?php echo htmlspecialchars($employee['EmployeeName']); ?>"
                                     data-department="<?php echo htmlspecialchars($employee['Department'] ?? ''); ?>"
                                     data-days-worked="<?php echo intval($employee['days_worked']); ?>"
                                     data-total-hours="<?php echo number_format($employee['total_hours'], 2); ?>"
                                     data-base-salary="<?php echo number_format($employee['base_salary'], 2); ?>"
                                     data-basic-salary-earned="<?php echo number_format($basicSalaryEarned, 2); ?>"
                                     data-gross-pay="<?php echo number_format($payroll['gross_pay'], 2); ?>"
                                     data-leave-pay="<?php echo number_format($payroll['leave_pay'], 2); ?>"
                                     data-13th-month="<?php echo number_format($payroll['thirteenth_month_pay'], 2); ?>"
                                     data-lates="<?php echo number_format($payroll['lates_amount'], 2); ?>"
                                     data-phic-employee="<?php echo number_format($payroll['phic_employee'], 2); ?>"
                                     data-pagibig-employee="<?php echo number_format($payroll['pagibig_employee'], 2); ?>"
                                     data-sss-employee="<?php echo number_format($payroll['sss_employee'] ?? 0, 2); ?>"
                                     data-total-deductions="<?php echo number_format($payroll['total_deductions'], 2); ?>"
                                     data-net-pay="<?php echo number_format($payroll['net_pay'], 2); ?>"
                                     data-monthly-rate="<?php echo number_format($monthly_rate, 2); ?>"
                                     data-daily-rate="<?php echo number_format($daily_rate, 2); ?>"
                                     data-absences="<?php echo $absences; ?>"
                                     data-late-mins="<?php echo $late_minutes; ?>"
                                     data-overtime-hours="<?php echo number_format($total_overtime_hours, 3); ?>"
                                     data-nsd-overtime-hours="<?php echo number_format($nsd_overtime_hours, 3); ?>"
                                     data-overtime-pay="<?php echo number_format($regular_overtime_pay, 2); ?>"
                                     data-nsd-overtime-pay="<?php echo number_format($overtimeResult['nsd_overtime_pay'], 2); ?>"
                                     data-special-holiday-pay="<?php echo number_format($holidayPay['special_holiday'], 2); ?>"
                                     data-legal-holiday-pay="<?php echo number_format($holidayPay['legal_holiday'], 2); ?>"
                                     data-sss="<?php echo htmlspecialchars($employee['SSS'] ?? ''); ?>"
                                     data-phic="<?php echo htmlspecialchars($employee['PHIC'] ?? ''); ?>"
                                     data-tin="<?php echo htmlspecialchars($employee['TIN'] ?? ''); ?>"
                                     data-hdmf="<?php echo htmlspecialchars($employee['HDMF'] ?? ''); ?>"
                                     data-shift="<?php echo htmlspecialchars($employee['Shift'] ?? ''); ?>"
                                     data-current-month="<?php echo htmlspecialchars($current_month); ?>"
                                     onclick="viewPayslip(this)">
                                    <div class="employee-card-header">
                                        <div class="employee-avatar">
                                            <?php echo strtoupper(substr($employee['EmployeeName'], 0, 1)); ?>
                                        </div>
                                        <div class="employee-card-info">
                                            <h3><?php echo htmlspecialchars($employee['EmployeeName']); ?></h3>
                                            <p>ID: <?php echo htmlspecialchars($employee['EmployeeID']); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="payroll-computation">
                                        <div class="computation-item">
                                            <div class="computation-label">Days Worked</div>
                                            <div class="computation-value"><?php echo intval($employee['days_worked']); ?></div>
                                        </div>
                                        <div class="computation-item">
                                            <div class="computation-label">Total Hours</div>
                                            <div class="computation-value"><?php echo number_format($employee['total_hours'], 1); ?>h</div>
                                        </div>
                                        <div class="computation-item">
                                            <div class="computation-label">Gross Pay</div>
                                            <div class="computation-value">₱<?php echo number_format($payroll['gross_pay'], 2); ?></div>
                                        </div>
                                        <div class="computation-item">
                                            <div class="computation-label">Deductions</div>
                                            <div class="computation-value">-₱<?php echo number_format($payroll['total_deductions'], 2); ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="net-pay-banner">
                                        <div class="net-pay-label">Net Pay (Σ)</div>
                                        <div class="net-pay-value">₱<?php echo number_format($payroll['net_pay'], 2); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
        </div>
    </div>

    <!-- Payslip Modal -->
    <div id="payslipModal" class="payslip-modal">
        <div class="payslip-content">
            <div class="payslip-header">
                <div class="header-actions">
                    <span class="modal-close" onclick="closePayslipModal()">&times;</span>
                </div>
                <h2>WTEI CORPORATION</h2>
                <p>Malvar st. Brgy.Mandaragat, Puerto Princesa City, Palawan</p>
                <p style="margin-top: 10px; font-size: 13px; opacity: 0.9;">Monthly Payslip - Current Period</p>
            </div>
            
            <div class="payslip-body">
                <div class="employee-section">
                    <div class="section-title">Employee Information</div>
                    <div class="employee-details-grid">
                        <div class="detail-group">
                            <label>Name</label>
                            <span id="modal-employee-name"></span>
                        </div>
                        <div class="detail-group">
                            <label>SSS</label>
                            <span id="modal-employee-sss"></span>
                        </div>
                        <div class="detail-group">
                            <label>Total Hours Worked</label>
                            <span id="modal-total-hours-worked"></span>
                        </div>
                        <div class="detail-group">
                            <label>Basic Salary (Earned)</label>
                            <span id="modal-basic-salary-earned"></span>
                        </div>
                        <div class="detail-group">
                            <label>NSD Overtime Hours</label>
                            <span id="modal-nsd-overtime-hours"></span>
                        </div>
                        <div class="detail-group">
                            <label>Employee ID</label>
                            <span id="modal-employee-id"></span>
                        </div>
                        <div class="detail-group">
                            <label>Philhealth</label>
                            <span id="modal-employee-phic"></span>
                        </div>
                        <div class="detail-group">
                            <label>Date Covered</label>
                            <span id="modal-date-covered"></span>
                        </div>
                        <div class="detail-group">
                            <label>Total Days Worked</label>
                            <span id="modal-days-worked"></span>
                        </div>
                        <div class="detail-group">
                            <label>Late Deduction</label>
                            <span id="modal-late-deduction"></span>
                        </div>
                        <div class="detail-group">
                            <label>Payroll Group</label>
                            <span>WTEICC</span>
                        </div>
                        <div class="detail-group">
                            <label>Pag-IBIG</label>
                            <span id="modal-employee-hdmf"></span>
                        </div>
                        <div class="detail-group">
                            <label>Payment Date</label>
                            <span id="modal-payment-date"></span>
                        </div>
                        <div class="detail-group">
                            <label>Late Minutes</label>
                            <span id="modal-late-mins"></span>
                        </div>
                        <div class="detail-group">
                            <label>Total Gross Income</label>
                            <span id="modal-total-gross-income"></span>
                        </div>
                        <div class="detail-group">
                            <label>Department</label>
                            <span id="modal-employee-dept"></span>
                        </div>
                        <div class="detail-group">
                            <label>TIN</label>
                            <span id="modal-employee-tin"></span>
                        </div>
                        <div class="detail-group">
                            <label>Base Salary (Database)</label>
                            <span id="modal-base-salary"></span>
                        </div>
                        <div class="detail-group">
                            <label>Overtime Hours</label>
                            <span id="modal-overtime-hours"></span>
                        </div>
                    </div>
                </div>
                
                <div class="payslip-main-grid">
                    <div class="payslip-section">
                        <div class="section-title">Deductions:</div>
                        <div class="breakdown-container">
                            <div id="modal-deductions-list"></div>
                            <div class="breakdown-row total-row">
                                <span>Total Deductions</span>
                                <span id="modal-total-deductions"></span>
                            </div>
                        </div>
                    </div>

                    <div class="payslip-section">
                        <div class="section-title">Earnings:</div>
                        <div class="breakdown-container">
                            <div id="modal-earnings-list"></div>
                            <div class="breakdown-row total-row">
                                <span>Total Earnings</span>
                                <span id="modal-total-earnings"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="summary-section">
                    <div class="summary-item">
                        <span>Total Earnings:</span>
                        <span id="modal-summary-earnings"></span>
                    </div>
                    <div class="summary-item">
                        <span>Total Deductions:</span>
                        <span id="modal-summary-deductions"></span>
                    </div>
                    <div class="summary-item">
                        <span>Net Pay:</span>
                        <span id="modal-net-pay"></span>
                    </div>
                </div>
                
                <div class="payslip-disclaimer">
                    This is a computer-generated document. No signature is required.
                </div>
            </div>
        </div>
    </div>

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
    
    <script>
        // Global variable for current month
        const currentMonth = '<?php echo $current_month; ?>';
        
        // Logout functions
        function confirmLogout() {
            document.getElementById('logoutModal').style.display = 'block';
            return false;
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').style.display = 'none';
        }

        function proceedLogout() {
            window.location.href = 'logout.php';
        }

        // AJAX Department Filter
        function filterByDepartment() {
            const department = document.getElementById('departmentFilter').value;
            
            console.log('Filtering by department:', department);
            
            // Show loading state
            const departmentsContainer = document.querySelector('.departments-container');
            departmentsContainer.style.opacity = '0.6';
            departmentsContainer.style.pointerEvents = 'none';
            
            // Create loading indicator
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'loading-indicator';
            loadingDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading department...';
            loadingDiv.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: white;
                padding: 20px 30px;
                border-radius: 12px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                z-index: 1000;
                display: flex;
                align-items: center;
                gap: 10px;
                color: #3F72AF;
                font-weight: 600;
            `;
            document.body.appendChild(loadingDiv);
            
            // Make AJAX request
            fetch('AdminPayroll.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `department=${encodeURIComponent(department)}&month=${encodeURIComponent(currentMonth)}&ajax=1`
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.text();
            })
            .then(data => {
                console.log('AJAX Response length:', data.length);
                console.log('AJAX Response preview:', data.substring(0, 200));
                
                // Remove loading indicator
                document.body.removeChild(loadingDiv);
                departmentsContainer.style.opacity = '1';
                departmentsContainer.style.pointerEvents = 'auto';
                
                // Update the departments container with the new content
                departmentsContainer.innerHTML = data;
                
                // Auto-expand only if a specific department is selected (not "All Departments")
                if (department && department !== '') {
                    const departmentSection = departmentsContainer.querySelector('.department-section');
                    if (departmentSection) {
                        departmentSection.classList.remove('collapsed');
                        const grid = departmentSection.querySelector('.employees-grid');
                        const search = departmentSection.querySelector('.department-search');
                        if (grid) grid.style.display = 'grid';
                        if (search) search.style.display = 'flex';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.body.removeChild(loadingDiv);
                departmentsContainer.style.opacity = '1';
                departmentsContainer.style.pointerEvents = 'auto';
                alert('Error loading department. Please try again.');
            });
        }

        // Department toggle
        function toggleDepartment(header) {
            const section = header.parentElement;
            section.classList.toggle('collapsed');
            const grid = section.querySelector('.employees-grid');
            const search = section.querySelector('.department-search');
            
            if (section.classList.contains('collapsed')) {
                grid.style.display = 'none';
                search.style.display = 'none';
            } else {
                grid.style.display = 'grid';
                search.style.display = 'flex';
            }
        }

        // Search functionality
        const searchInput = document.getElementById('employeeSearch');
        const clearBtn = document.getElementById('clearSearch');

        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            
            if (query.length > 0) {
                clearBtn.style.display = 'block';
            } else {
                clearBtn.style.display = 'none';
            }

            const allCards = document.querySelectorAll('.employee-payroll-card');
            const allDepts = document.querySelectorAll('.department-section');
            
            if (query.length === 0) {
                // Show all
                allCards.forEach(card => card.style.display = 'block');
                allDepts.forEach(dept => dept.style.display = 'block');
                return;
            }

            // Filter cards
            allCards.forEach(card => {
                const name = card.dataset.employeeName.toLowerCase();
                const dept = card.dataset.department.toLowerCase();
                const id = card.dataset.employeeId.toLowerCase();
                
                if (query.length === 0 || name.includes(query) || dept.includes(query) || id.includes(query)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });

            // Show/hide departments based on search results
            allDepts.forEach(dept => {
                const visibleCards = dept.querySelectorAll('.employee-payroll-card[style="display: block;"]');
                if (query.length > 0) {
                    if (visibleCards.length === 0) {
                        dept.style.display = 'none';
                    } else {
                        dept.style.display = 'block';
                        // Auto-expand departments that have matching employees
                        dept.classList.remove('collapsed');
                        const grid = dept.querySelector('.employees-grid');
                        const search = dept.querySelector('.department-search');
                        if (grid) grid.style.display = 'grid';
                        if (search) search.style.display = 'flex';
                    }
                } else {
                    dept.style.display = 'block';
                    // Reset to collapsed state when search is cleared
                    dept.classList.add('collapsed');
                    const grid = dept.querySelector('.employees-grid');
                    const search = dept.querySelector('.department-search');
                    if (grid) grid.style.display = 'none';
                    if (search) search.style.display = 'none';
                }
            });
        });

        clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
            clearBtn.style.display = 'none';
        });

        // Department search functionality
        document.addEventListener('input', function(event) {
            if (event.target.classList.contains('department-search-input')) {
                const query = event.target.value.toLowerCase().trim();
                const department = event.target.dataset.department;
                const section = event.target.closest('.department-section');
                const employeesGrid = section.querySelector('.employees-grid');
                const clearIcon = event.target.nextElementSibling;
                
                // Show/hide clear button
                if (query.length > 0) {
                    clearIcon.style.display = 'block';
                } else {
                    clearIcon.style.display = 'none';
                }
                
                // Filter employees within this department
                const employeeCards = employeesGrid.querySelectorAll('.employee-payroll-card');
                
                employeeCards.forEach(card => {
                    const name = card.dataset.employeeName.toLowerCase();
                    const id = card.dataset.employeeId.toLowerCase();
                    const dept = card.dataset.department.toLowerCase();
                    
                    if (query.length === 0 || name.includes(query) || id.includes(query) || dept.includes(query)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            }
        });

        // Clear department search
        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('clear-icon')) {
                const searchInput = event.target.previousElementSibling;
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                event.target.style.display = 'none';
            }
        });

        // View payslip
        function viewPayslip(card) {
            const formatCurrency = (value) => {
                if (!value && value !== 0) return '₱0.00';
                const numValue = typeof value === 'string' ? parseFloat(value.replace(/,/g, '')) : parseFloat(value);
                if (isNaN(numValue)) return '₱0.00';
                return '₱' + numValue.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
            };
            
            // Format date for Date Covered
            const monthDate = new Date(currentMonth + '-01');
            const monthStart = monthDate.toLocaleDateString('en-US', { month: 'numeric', day: 'numeric', year: 'numeric' });
            const monthEnd = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0).toLocaleDateString('en-US', { month: 'numeric', day: 'numeric', year: 'numeric' });
            
            // Set employee information fields (all data from empuser table and current month payroll)
            document.getElementById('modal-employee-name').textContent = card.dataset.employeeName || '';
            
            // Government IDs from empuser table - ensure values are displayed correctly
            const sssValue = (card.dataset.sss || '').trim();
            document.getElementById('modal-employee-sss').textContent = sssValue || '';
            
            const phicValue = (card.dataset.phic || '').trim();
            document.getElementById('modal-employee-phic').textContent = phicValue || '';
            
            const hdmfValue = (card.dataset.hdmf || '').trim();
            document.getElementById('modal-employee-hdmf').textContent = hdmfValue || '';
            
            const tinValue = (card.dataset.tin || '').trim();
            document.getElementById('modal-employee-tin').textContent = tinValue || '';
            
            // Debug: Log values to console for troubleshooting
            console.log('Employee Government IDs:', {
                sss: sssValue,
                phic: phicValue,
                tin: tinValue,
                hdmf: hdmfValue,
                employeeId: card.dataset.employeeId
            });
            
            // Payroll information for current month
            document.getElementById('modal-employee-id').textContent = card.dataset.employeeId || '';
            document.getElementById('modal-employee-dept').textContent = card.dataset.department || '';
            document.getElementById('modal-total-hours-worked').textContent = parseFloat(card.dataset.totalHours || 0).toFixed(2) + ' hours';
            document.getElementById('modal-basic-salary-earned').textContent = formatCurrency(card.dataset.basicSalaryEarned || '0');
            document.getElementById('modal-nsd-overtime-hours').textContent = parseFloat(card.dataset.nsdOvertimeHours || 0).toFixed(3) + ' hours';
            document.getElementById('modal-date-covered').textContent = monthStart + ' - ' + monthEnd;
            document.getElementById('modal-days-worked').textContent = card.dataset.daysWorked + ' days';
            document.getElementById('modal-late-deduction').textContent = formatCurrency(card.dataset.lates || '0');
            document.getElementById('modal-payment-date').textContent = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            const lateMins = parseInt(card.dataset.lateMins || 0);
            document.getElementById('modal-late-mins').textContent = lateMins + ' min' + (lateMins !== 1 ? 's' : '');
            document.getElementById('modal-base-salary').textContent = formatCurrency(card.dataset.baseSalary || '0');
            const otHours = parseFloat(card.dataset.overtimeHours || 0);
            document.getElementById('modal-overtime-hours').textContent = otHours.toFixed(3) + ' hours';
            
            // Populate earnings (show all items even if 0.00)
            const earningsList = document.getElementById('modal-earnings-list');
            earningsList.innerHTML = '';
            
            // Parse and validate earnings values, defaulting to 0 if invalid
            const parseValue = (value) => {
                if (!value || value === '' || value === 'undefined') return 0;
                const parsed = parseFloat(String(value).replace(/,/g, ''));
                return isNaN(parsed) ? 0 : parsed;
            };
            
            const basicSalaryEarned = parseValue(card.dataset.basicSalaryEarned);
            const overtimePay = parseValue(card.dataset.overtimePay);
            const nsdOvertimePay = parseValue(card.dataset.nsdOvertimePay);
            const specialHolidayPay = parseValue(card.dataset.specialHolidayPay);
            const legalHolidayPay = parseValue(card.dataset.legalHolidayPay);
            
            const earnings = [
                { name: 'Basic Salary', amount: basicSalaryEarned },
                { name: 'Overtime Pay', amount: overtimePay },
                { name: 'Special Holiday Pay', amount: specialHolidayPay },
                { name: 'Legal Holiday Pay', amount: legalHolidayPay },
                { name: 'NSD Overtime Pay', amount: nsdOvertimePay }
            ];
            
            earnings.forEach(earning => {
                // Always show all earnings items (including 0.00) to match payslip format
                const row = document.createElement('div');
                row.className = 'breakdown-row';
                const amount = isNaN(earning.amount) ? 0 : earning.amount;
                row.innerHTML = `<span>${earning.name}</span><span>${formatCurrency(amount.toString())}</span>`;
                earningsList.appendChild(row);
            });
            
            // Calculate total earnings - must match the sum of all earnings components
            const totalEarnings = basicSalaryEarned + overtimePay + nsdOvertimePay + specialHolidayPay + legalHolidayPay;
            document.getElementById('modal-total-earnings').textContent = formatCurrency(totalEarnings.toString());
            
            // Set Total Gross Income to match Total Earnings (they should be the same)
            document.getElementById('modal-total-gross-income').textContent = formatCurrency(totalEarnings.toString());
            
            // Populate deductions
            const deductionsList = document.getElementById('modal-deductions-list');
            deductionsList.innerHTML = '';
            
            const lates = parseFloat((card.dataset.lates || '0').replace(/,/g, ''));
            const phicEmployee = parseFloat((card.dataset.phicEmployee || '0').replace(/,/g, ''));
            const pagibigEmployee = parseFloat((card.dataset.pagibigEmployee || '0').replace(/,/g, ''));
            const sssEmployee = parseFloat((card.dataset.sssEmployee || '0').replace(/,/g, ''));
            
            const deductions = [
                { name: 'Late Deductions', amount: lates },
                { name: 'PHIC (Employee)', amount: phicEmployee },
                { name: 'Pag-IBIG (Employee)', amount: pagibigEmployee },
                { name: 'SSS (Employee)', amount: sssEmployee }
            ];
            
            deductions.forEach(deduction => {
                if (deduction.amount > 0) {
                    const row = document.createElement('div');
                    row.className = 'breakdown-row';
                    row.innerHTML = `<span>${deduction.name}</span><span>${formatCurrency(deduction.amount.toString())}</span>`;
                    deductionsList.appendChild(row);
                }
            });
            
            const totalDeductions = lates + phicEmployee + pagibigEmployee + sssEmployee;
            document.getElementById('modal-total-deductions').textContent = formatCurrency(totalDeductions.toString());
            
            // Set summary values
            document.getElementById('modal-summary-earnings').textContent = formatCurrency(totalEarnings.toString());
            document.getElementById('modal-summary-deductions').textContent = formatCurrency(totalDeductions.toString());
            
            // Calculate net pay accurately using backend formula: Gross Pay + Leave Pay + 13th Month Pay - Total Deductions
            // Use gross pay from backend (includes all earnings: basic salary, OT, holidays, allowances, night shift diff)
            const grossPay = parseFloat((card.dataset.grossPay || '0').replace(/,/g, ''));
            
            // Check if current month is December for leave pay and 13th month
            const currentMonthForCalc = card.dataset.currentMonth || currentMonth;
            const isDecember = currentMonthForCalc.endsWith('-12');
            const leavePayForNet = isDecember ? parseFloat((card.dataset.leavePay || '0').replace(/,/g, '')) : 0;
            const thirteenthMonthForNet = isDecember ? parseFloat((card.dataset['13thMonth'] || card.dataset['13th-month'] || '0').replace(/,/g, '')) : 0;
            
            // Net Pay = Gross Pay + Leave Pay + 13th Month Pay - Total Deductions
            // This matches the backend calculation in payroll_computations.php
            const calculatedNetPay = grossPay + leavePayForNet + thirteenthMonthForNet - totalDeductions;
            document.getElementById('modal-net-pay').textContent = formatCurrency(calculatedNetPay.toString());
            
            // Show modal
            const modal = document.getElementById('payslipModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closePayslipModal() {
            const modal = document.getElementById('payslipModal');
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const logoutModal = document.getElementById('logoutModal');
            const payslipModal = document.getElementById('payslipModal');
            if (event.target === logoutModal) {
                closeLogoutModal();
            }
            // Close payslip modal if clicking on the backdrop (not the content)
            if (event.target === payslipModal) {
                closePayslipModal();
            }
        }

        // Close with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeLogoutModal();
                closePayslipModal();
            }
        });
    </script>
</body>
</html> 
