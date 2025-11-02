<?php
/**
 * Centralized Payroll Computations
 * This file contains all payroll calculation functions used across the WTEI system.
 * 
 * @author WTEI Development Team
 * @version 1.1
 * @date 2024
 */

/**
 * Check if employee is on night shift
 * 
 * @param int $employeeId - Employee ID
 * @param mysqli $conn - Database connection
 * @return bool - True if employee is on night shift
 */
function isNightShiftEmployee($employeeId, $conn) {
    $shiftQuery = "SELECT Shift FROM empuser WHERE EmployeeID = ?";
    $stmt = $conn->prepare($shiftQuery);
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $shiftRow = $result->fetch_assoc();
    $stmt->close();
    
    if (!$shiftRow || !$shiftRow['Shift']) {
        return false;
    }
    
    $shift = strtolower($shiftRow['Shift']);
    
    // Check for night shift patterns
    return (
        strpos($shift, '22:00') !== false || 
        strpos($shift, '22:00-06:00') !== false || 
        strpos($shift, 'nsd') !== false || 
        strpos($shift, 'night') !== false ||
        strpos($shift, '22:00-6') !== false
    );
}

/**
 * Check if employee is eligible for allowances based on work period
 * Allowances are only given for employees who have worked for at least half a month (15+ days)
 * 
 * @param int $employeeId - Employee ID
 * @param string $currentMonth - Month in YYYY-MM format
 * @param mysqli $conn - Database connection
 * @return bool - True if eligible for allowances
 */
function isEligibleForAllowances($employeeId, $currentMonth, $conn) {
    // Get employee's work period for the current month
    $workPeriodQuery = "SELECT 
                        MIN(DATE(a.attendance_date)) as first_work_date,
                        MAX(DATE(a.attendance_date)) as last_work_date,
                        COUNT(DISTINCT DATE(a.attendance_date)) as total_work_days
                        FROM attendance a
                        WHERE a.EmployeeID = ? 
                        AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                        AND a.time_in IS NOT NULL";
    
    $stmt = $conn->prepare($workPeriodQuery);
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("is", $employeeId, $currentMonth);
    $stmt->execute();
    $result = $stmt->get_result();
    $workData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$workData || $workData['total_work_days'] < 15) {
        return false;
    }
    
    // Check if employee has worked for at least 15 days in the month
    return intval($workData['total_work_days']) >= 15;
}

/**
 * Get employee allowances from database
 * 
 * @param int $employeeId - Employee ID
 * @param string $currentMonth - Month in YYYY-MM format
 * @param mysqli $conn - Database connection
 * @return array - Allowances data
 */
function getEmployeeAllowances($employeeId, $currentMonth, $conn) {
    // Check if employee is eligible for allowances
    if (!isEligibleForAllowances($employeeId, $currentMonth, $conn)) {
        return [
            'laundry_allowance' => 0,
            'medical_allowance' => 0,
            'rice_allowance' => 0
        ];
    }
    
    // Get allowances from database
    $allowanceQuery = "SELECT 
                       COALESCE(rice_allowance, 0) as rice_allowance,
                       COALESCE(medical_allowance, 0) as medical_allowance,
                       COALESCE(laundry_allowance, 0) as laundry_allowance
                       FROM empuser 
                       WHERE EmployeeID = ?";
    
    $stmt = $conn->prepare($allowanceQuery);
    if (!$stmt) {
        return [
            'laundry_allowance' => 0,
            'medical_allowance' => 0,
            'rice_allowance' => 0
        ];
    }
    
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $allowanceData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$allowanceData) {
        return [
            'laundry_allowance' => 0,
            'medical_allowance' => 0,
            'rice_allowance' => 0
        ];
    }
    
    return [
        'laundry_allowance' => floatval($allowanceData['laundry_allowance']),
        'medical_allowance' => floatval($allowanceData['medical_allowance']),
        'rice_allowance' => floatval($allowanceData['rice_allowance'])
    ];
}

/**
 * Calculate comprehensive payroll for an employee
 * 
 * @param int $employeeId - Employee ID
 * @param float $baseSalary - Base daily salary
 * @param string $currentMonth - Month in YYYY-MM format
 * @param mysqli $conn - Database connection
 * @return array - Complete payroll data
 */
function calculatePayroll($employeeId, $baseSalary, $currentMonth, $conn) {
    // Get employee allowances from database
    $allowances = getEmployeeAllowances($employeeId, $currentMonth, $conn);
    
    // Initialize payroll data structure
    $payrollData = [
        'base_salary' => floatval($baseSalary),
        'basic_salary' => 0, // Will be calculated from attendance
        'lates_amount' => 0,
        'leave_pay' => 0,
        'thirteenth_month_pay' => 0,
        'admin_fee' => 0,
        'gross_pay' => floatval($baseSalary),
        'vat' => 0,
        'overtime_pay' => 0,
        'special_holiday_pay' => 0,
        'legal_holiday_pay' => 0,
        'night_shift_diff' => 0,
        'nsd_overtime_pay' => 0,
        'regular_overtime_hours' => 0,
        'nsd_overtime_hours' => 0,
        'regular_holiday_pay' => 0,
        'rest_day_pay' => 0,
        'total_deductions' => 0,
        'net_pay' => floatval($baseSalary),
        'phic_employee' => 0,
        'phic_employer' => 0,
        'pagibig_employee' => 200,
        'pagibig_employer' => 200,
        'sss_employee' => 0,
        'sss_employer' => 0,
        // Employee allowances from database
        'laundry_allowance' => $allowances['laundry_allowance'],
        'medical_allowance' => $allowances['medical_allowance'],
        'rice_allowance' => $allowances['rice_allowance']
    ];

    // Calculate lates (per-minute precision) using employee's shift schedule
    // Formula: Lates = (Base Daily Salary / 8 / 60) * total late minutes
    $latesQuery = "SELECT SUM(
        CASE 
            WHEN TIME(a.time_in) > CONCAT(SUBSTRING_INDEX(e.Shift, '-', 1), ':00') 
            THEN TIMESTAMPDIFF(MINUTE, CONCAT(SUBSTRING_INDEX(e.Shift, '-', 1), ':00'), TIME(a.time_in))
            ELSE 0 
        END
    ) as late_minutes 
    FROM attendance a
    JOIN empuser e ON a.EmployeeID = e.EmployeeID
    WHERE a.EmployeeID = ? 
    AND a.time_in IS NOT NULL
    AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
    AND (DAYOFWEEK(a.attendance_date) != 1 OR (
        e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%'
    ))";
    
    $stmt = $conn->prepare($latesQuery);
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $latesResult = $stmt->get_result();
        $latesRow = $latesResult->fetch_assoc();
        
        if ($latesRow && $latesRow['late_minutes'] > 0) {
            $hourlyRate = $baseSalary / 8;
            $payrollData['lates_amount'] = ($hourlyRate / 60) * floatval($latesRow['late_minutes']);
        }
        $stmt->close();
    }

    // Calculate 13th month pay - Only in December
    // Formula: (daily base salary * days worked with "present" record) / 12
    $currentMonthNum = date('n', strtotime($currentMonth . '-01'));
    if ($currentMonthNum == 12) {
        $year = date('Y', strtotime($currentMonth . '-01'));
        $daysWorkedQuery = "SELECT COUNT(DISTINCT DATE(attendance_date)) as days_worked 
                              FROM attendance 
                              WHERE EmployeeID = ? 
                              AND attendance_type = 'present' 
                              AND DATE_FORMAT(attendance_date, '%Y') = ?";
        
        $stmt = $conn->prepare($daysWorkedQuery);
        $daysWorked = 0;
        if ($stmt) {
            $stmt->bind_param("is", $employeeId, $year);
            $stmt->execute();
            $daysResult = $stmt->get_result();
            $daysRow = $daysResult->fetch_assoc();
            
            if ($daysRow && $daysRow['days_worked'] > 0) {
                $daysWorked = intval($daysRow['days_worked']);
            }
            $stmt->close();
        }
        
        $payrollData['thirteenth_month_pay'] = ($baseSalary * $daysWorked) / 12;
    } else {
        $payrollData['thirteenth_month_pay'] = 0;
    }

    // Calculate leave pay
    $payrollData['leave_pay'] = calculateLeavePay($employeeId, $baseSalary, $currentMonth, $conn);

    // Calculate Basic Salary: (base_salary / 8) * total_hours
    $hoursQuery = "SELECT 
                      COALESCE(SUM(a.total_hours), 0) AS total_hours
                   FROM attendance a
                   JOIN empuser e ON a.EmployeeID = e.EmployeeID
                   WHERE a.EmployeeID = ?
                    AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                    AND a.time_in IS NOT NULL
                    AND (DAYOFWEEK(a.attendance_date) != 1 OR (
                        e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%'
                    ))";

    $payrollData['gross_pay'] = $baseSalary;

    $stmt = $conn->prepare($hoursQuery);
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $hoursResult = $stmt->get_result();
        $hoursRow = $hoursResult->fetch_assoc();
        $stmt->close();

        $totalHours = $hoursRow ? floatval($hoursRow['total_hours']) : 0.0;

        // Basic Salary = (base_salary / 8) * total_hours
        $hourlyRate = $baseSalary / 8.0;
        $payrollData['basic_salary'] = $hourlyRate * $totalHours;
        $payrollData['gross_pay'] = $payrollData['basic_salary'];
    }

    // Admin fee removed
    $payrollData['admin_fee'] = 0;

    // Calculate VAT (12% of gross billing)
    $payrollData['vat'] = $payrollData['gross_pay'] * 0.12;

    // Calculate PHIC contributions
    $workingDaysQuery = "SELECT COUNT(DISTINCT DATE(a.attendance_date)) AS working_days
                         FROM attendance a
                         JOIN empuser e ON a.EmployeeID = e.EmployeeID
                         WHERE a.EmployeeID = ?
                           AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                           AND a.time_in IS NOT NULL
                           AND (DAYOFWEEK(a.attendance_date) != 1 OR (
                               e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%'
                           ))";
    $stmt = $conn->prepare($workingDaysQuery);
    $workingDays = 0;
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $wdRes = $stmt->get_result();
        $wdRow = $wdRes->fetch_assoc();
        $stmt->close();
        $workingDays = $wdRow ? intval($wdRow['working_days']) : 0;
    }
    
    $monthlySalary = $baseSalary * $workingDays;
    $phicTotal = $monthlySalary * 0.03;
    
    if ($monthlySalary < 10000) {
        $payrollData['phic_employee'] = 195;
        $payrollData['phic_employer'] = 195;
    } else {
        $payrollData['phic_employee'] = $phicTotal / 2;
        $payrollData['phic_employer'] = $phicTotal / 2;
    }

    // Pag-IBIG: 2% of monthly salary
    $payrollData['pagibig_employee'] = $monthlySalary * 0.02;
    $payrollData['pagibig_employer'] = 0;

    // SSS: 5% of monthly salary
    $payrollData['sss_employee'] = $monthlySalary * 0.05;
    $payrollData['sss_employer'] = 0;

    // Calculate total deductions
    $payrollData['total_deductions'] = 
        $payrollData['lates_amount'] + 
        $payrollData['phic_employee'] + 
        $payrollData['pagibig_employee'] +
        $payrollData['sss_employee'];

    // Calculate overtime pay breakdown
    // Regular OT: (base_salary / 8) * 1.25 * regular_OT_hours
    // NSD OT: (hourly_rate * NSD_OT_hours) + (hourly_rate * 0.10 * 1.25 * NSD_OT_hours)
    $overtimeResult = calculateOvertimePay($employeeId, $baseSalary, $currentMonth, $conn);
    $payrollData['overtime_pay'] = $overtimeResult['regular_overtime_pay'];
    $payrollData['nsd_overtime_pay'] = $overtimeResult['nsd_overtime_pay'];
    $payrollData['regular_overtime_hours'] = $overtimeResult['regular_overtime_hours'];
    $payrollData['nsd_overtime_hours'] = $overtimeResult['nsd_overtime_hours'];
    
    if ($overtimeResult['total_overtime_pay'] > 0) {
        error_log("Overtime calculated for Employee {$employeeId}: Regular OT = {$overtimeResult['regular_overtime_pay']} (Hours: {$overtimeResult['regular_overtime_hours']}), NSD OT = {$overtimeResult['nsd_overtime_pay']} (Hours: {$overtimeResult['nsd_overtime_hours']})");
    }
    
    // Calculate NSD for regular working days
    $nsdRegular = calculateNSDRegularHours($employeeId, $baseSalary, $currentMonth, $conn);
    
    // Calculate holiday pay
    $holidayPay = calculateHolidayPay($employeeId, $baseSalary, $currentMonth, $conn);
    $payrollData['special_holiday_pay'] = $holidayPay['special_holiday'];
    $payrollData['legal_holiday_pay'] = $holidayPay['legal_holiday'];
    $payrollData['regular_holiday_pay'] = $holidayPay['regular_holiday'];
    $payrollData['rest_day_pay'] = $holidayPay['rest_day'];
    $payrollData['night_shift_diff'] = $holidayPay['night_shift_diff'] + $nsdRegular;
    
    // Special Holiday Pay: Only base calculation (baseSalary / 8 * 130% * 8) per special holiday
    // Special holiday overtime is NOT included in special_holiday_pay (user requirement)
    // $payrollData['special_holiday_pay'] += calculateSpecialHolidayOvertime($employeeId, $baseSalary, $currentMonth, $conn);
    
    // Update gross pay
    $payrollData['gross_pay'] = $payrollData['basic_salary'] + 
        $payrollData['overtime_pay'] + 
        $payrollData['nsd_overtime_pay'] +  // NSD OT Pay is separate from regular OT Pay
        $payrollData['special_holiday_pay'] + 
        $payrollData['legal_holiday_pay'] + 
        $payrollData['regular_holiday_pay'] + 
        $payrollData['rest_day_pay'] + 
        $payrollData['night_shift_diff'] +
        $payrollData['laundry_allowance'] +
        $payrollData['medical_allowance'] +
        $payrollData['rice_allowance'];

    // Calculate net pay
    $payrollData['net_pay'] = 
        $payrollData['gross_pay'] + 
        $payrollData['leave_pay'] + 
        $payrollData['thirteenth_month_pay'] -
        $payrollData['total_deductions'];

    // Finalize monetary values with consistent rounding
    $moneyFields = [
        'base_salary','basic_salary','lates_amount','leave_pay','thirteenth_month_pay','admin_fee','gross_pay','vat',
        'overtime_pay','special_holiday_pay','legal_holiday_pay','night_shift_diff','nsd_overtime_pay','regular_holiday_pay','rest_day_pay',
        'total_deductions','net_pay','phic_employee','phic_employer','pagibig_employee','pagibig_employer','sss_employee','sss_employer',
        'laundry_allowance','medical_allowance','rice_allowance'
    ];
    foreach ($moneyFields as $field) {
        if (isset($payrollData[$field])) {
            $payrollData[$field] = roundMoney($payrollData[$field], 3);
        }
    }

    return $payrollData;
}

/**
 * Calculate additional deductions (deprecated)
 */
function calculateAdditionalDeductions($employeeId, $baseSalary, $deductions) {
    return 0;
}

/**
 * Calculate working days in a month (excluding Sundays)
 */
function calculateWorkingDaysInMonth($month) {
    $monthStart = date('Y-m-01', strtotime($month . '-01'));
    $monthEnd = date('Y-m-t', strtotime($month . '-01'));
    $workingDays = 0;
    $currentDate = strtotime($monthStart);
    $endDate = strtotime($monthEnd);
    
    while ($currentDate <= $endDate) {
        $dayOfWeek = date('w', $currentDate);
        if ($dayOfWeek != 0) {
            $workingDays++;
        }
        $currentDate = strtotime('+1 day', $currentDate);
    }
    
    return $workingDays;
}

/**
 * Calculate monthly rate based on working days
 */
function calculateMonthlyRate($baseSalary, $workingDays) {
    return $baseSalary * $workingDays;
}

/**
 * Get employee attendance summary for payroll
 */
function getEmployeeAttendanceSummary($employeeId, $currentMonth, $conn) {
    $summary = [
        'days_worked' => 0,
        'total_hours' => 0,
        'absences' => 0,
        'late_minutes' => 0
    ];
    
    $daysQuery = "SELECT COUNT(DISTINCT DATE(a.attendance_date)) as days_worked
                  FROM attendance a
                  JOIN empuser e ON a.EmployeeID = e.EmployeeID
                  WHERE a.EmployeeID = ? 
                  AND a.status IN ('present', 'late') 
                  AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                  AND (DAYOFWEEK(a.attendance_date) != 1 OR (
                      e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%'
                  ))";
    
    $stmt = $conn->prepare($daysQuery);
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $summary['days_worked'] = $row ? intval($row['days_worked']) : 0;
        $stmt->close();
    }
    
    $hoursQuery = "SELECT COALESCE(SUM(a.total_hours), 0) as total_hours
                  FROM attendance a
                  JOIN empuser e ON a.EmployeeID = e.EmployeeID
                  WHERE a.EmployeeID = ? 
                  AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                  AND a.time_in IS NOT NULL
                  AND (DAYOFWEEK(a.attendance_date) != 1 OR (
                      e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%'
                  ))";
    
    $stmt = $conn->prepare($hoursQuery);
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $summary['total_hours'] = $row ? floatval($row['total_hours']) : 0;
        $stmt->close();
    }
    
    $absencesQuery = "SELECT COUNT(*) as absence_count 
                      FROM (
                          SELECT DATE(a.attendance_date) as date 
                          FROM attendance a
                          JOIN empuser e ON a.EmployeeID = e.EmployeeID
                          WHERE a.EmployeeID = ? 
                          AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                          AND a.status = 'absent'
                          AND DAYOFWEEK(a.attendance_date) != 1
                      ) as absences";
    
    $stmt = $conn->prepare($absencesQuery);
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $summary['absences'] = $row ? intval($row['absence_count']) : 0;
        $stmt->close();
    }
    
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
                  AND a.time_in IS NOT NULL
                  AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                  AND (DAYOFWEEK(a.attendance_date) != 1 OR (
                      e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%'
                  ))";
    
    $stmt = $conn->prepare($lateQuery);
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $summary['late_minutes'] = $row && $row['late_minutes'] ? intval($row['late_minutes']) : 0;
        $stmt->close();
    }
    
    return $summary;
}

/**
 * Format currency for display
 */
function formatCurrency($amount) {
    return '₱' . number_format($amount, 3);
}

/**
 * Round monetary values using half-up strategy for consistent 2-decimal accuracy
 * Do NOT use during intermediate math; apply only when persisting/returning/displaying
 *
 * @param float|null $amount
 * @param int $precision
 * @return float
 */
function roundMoney($amount, $precision = 2) {
    if ($amount === null) {
        return 0.0;
    }
    return round((float)$amount, $precision, PHP_ROUND_HALF_UP);
}

/**
 * Validate month format (YYYY-MM)
 */
function validateMonthFormat($month) {
    return preg_match('/^\d{4}-\d{2}$/', $month);
}

/**
 * Get current month in YYYY-MM format
 */
function getCurrentMonth() {
    return date('Y-m');
}

/**
 * Get month display name
 */
function getMonthDisplayName($month) {
    return date('F Y', strtotime($month . '-01'));
}

/**
 * Extract shift end time from shift string
 */
function extractShiftEndTime($shift) {
    if (empty($shift)) {
        return null;
    }
    
    $shift = trim($shift);
    
    if (preg_match('/(\d{1,2}:\d{2})-(\d{1,2}:\d{2})/', $shift, $matches)) {
        $endTime = $matches[2];
        if (strpos($endTime, ':') !== false) {
            $timeParts = explode(':', $endTime);
            $hour = intval($timeParts[0]);
            $minute = $timeParts[1];
            
            if ($hour < 12 && (stripos($shift, 'pm') !== false || $hour < 8)) {
                $hour += 12;
            }
            
            return sprintf('%02d:%s', $hour, $minute);
        }
        return $endTime;
    }
    
    if (preg_match('/(\d{1,2})-(\d{1,2})/', $shift, $matches)) {
        $endHour = intval($matches[2]);
        if ($endHour < 12 && $endHour < 8) {
            $endHour += 12;
        }
        return sprintf('%02d:00', $endHour);
    }
    
    if (stripos($shift, '22:00') !== false || stripos($shift, 'nsd') !== false || stripos($shift, 'night') !== false) {
        return '06:00';
    }
    
    return '17:00';
}

/**
 * Calculate overtime pay for an employee
 * COMPUTATION:
 * Regular OT = (base_salary / 8) * 1.25 * regular_OT_hours (before 10pm)
 * NSD OT = (hourly_rate * NSD_OT_hours) + (hourly_rate * 0.10 * 1.25 * NSD_OT_hours)
 *         = hourly_rate * (1 + 0.125) * NSD_OT_hours
 *         = hourly_rate * 1.125 * NSD_OT_hours
 * 
 * @param int $employeeId - Employee ID
 * @param float $baseSalary - Base daily salary
 * @param string $currentMonth - Month in YYYY-MM format
 * @param mysqli $conn - Database connection
 * @return array - Overtime pay breakdown
 */
function calculateOvertimePay($employeeId, $baseSalary, $currentMonth, $conn) {
    $hourlyRate = $baseSalary / 8;
    
    // Get overtime hours breakdown (regular OT vs NSD OT using 10pm cutoff)
    $otHoursBreakdown = calculateOvertimeHoursBreakdown($employeeId, $currentMonth, $conn);
    $regularOTHours = $otHoursBreakdown['regular_ot_hours'];
    $nsdOTHours = $otHoursBreakdown['nsd_ot_hours'];
    
    // Regular OT Pay: (base_salary / 8) * 1.25 * regular_OT_hours
    $regularOvertimePay = $hourlyRate * 1.25 * $regularOTHours;
    
    // NSD OT Pay: (hourly_rate * NSD_OT_hours) + (hourly_rate * 0.10 * 1.25 * NSD_OT_hours)
    // Step 1: Calculate NSD OT premium: hourly_rate * 0.10 * 1.25 * NSD_OT_hours
    $nsdOTPremium = $hourlyRate * 0.10 * 1.25 * $nsdOTHours;
    // Step 2: Calculate regular hourly rate pay for NSD OT hours: hourly_rate * NSD_OT_hours
    $regularPayForNSDHours = $hourlyRate * $nsdOTHours;
    // Step 3: Total NSD OT Pay = regular hourly rate pay + NSD premium
    $nsdOvertimePay = $regularPayForNSDHours + $nsdOTPremium;
    // Simplified formula: hourly_rate * 1.125 * NSD_OT_hours
    
    $totalOvertimePay = $regularOvertimePay + $nsdOvertimePay;

    return [
        'regular_overtime_pay' => $regularOvertimePay,
        'nsd_overtime_pay' => $nsdOvertimePay,
        'total_overtime_pay' => $totalOvertimePay,
        'regular_overtime_hours' => $regularOTHours,
        'nsd_overtime_hours' => $nsdOTHours,
    ];
}

/**
 * Calculate OT breakdown with NSD
 * FIXED COMPUTATION:
 * Regular OT: (hourlyRate × 1.25) × regular_OT_hours
 * NSD OT: (hourlyRate × 1.25 + hourlyRate × 0.10 × 1.25) × NSD_OT_hours (full rate for NSD hours)
 * 
 * NSD starts at 10pm: After 10pm, rate = OT_rate + NSD_OT_rate
 * Example: If hourly rate = 53.75
 *   - Regular OT rate per hour = 53.75 × 1.25 = 67.1875
 *   - NSD OT rate per hour = 53.75 × 0.10 × 1.25 = 6.71875 (additional)
 *   - Total rate after 10pm = 67.1875 + 6.71875 = 73.90625
 * 
 * @param string $timeIn - Time in
 * @param string $timeOut - Time out
 * @param float $hourlyRate - Hourly rate
 * @param float $totalOvertimeHours - Total overtime hours
 * @param string $shiftEndTime - Shift end time
 * @return array - OT breakdown
 */
function calculateOTWithNSD($timeIn, $timeOut, $hourlyRate, $totalOvertimeHours, $shiftEndTime = null) {
    // Return only hours split; monetary computation happens in calculateOvertimePay
    
    $timeInObj = new DateTime($timeIn);
    $timeOutObj = new DateTime($timeOut);
    
    // Determine OT start time (shift end + 30min grace)
    $otStartTime = null;
    if ($shiftEndTime) {
        $shiftEndObj = new DateTime($timeInObj->format('Y-m-d') . ' ' . $shiftEndTime);
        $otStartTime = clone $shiftEndObj;
        $otStartTime->add(new DateInterval('PT30M'));
    }
    
    if (!$otStartTime) {
        $otStartTime = clone $timeInObj;
        $otStartTime->setTime(18, 0, 0); // Default 6:00 PM
    }
    
    // 10pm cutoff for NSD
    $nsdCutoffTime = clone $timeInObj;
    $nsdCutoffTime->setTime(22, 0, 0);
    
    if ($timeOutObj > $otStartTime) {
        // Regular OT: from OT start to 10pm (or end, whichever is earlier)
        $regularOTEndTime = min($timeOutObj, $nsdCutoffTime);
        
        if ($regularOTEndTime > $otStartTime) {
            $regularOTHours = ($regularOTEndTime->getTimestamp() - $otStartTime->getTimestamp()) / 3600;
            $regularOTHours = max(0, $regularOTHours);
        }
        
        // NSD OT: from 10pm onwards (full NSD OT rate = OT rate + NSD additional)
        if ($timeOutObj > $nsdCutoffTime) {
            $nsdOTHours = ($timeOutObj->getTimestamp() - $nsdCutoffTime->getTimestamp()) / 3600;
            $nsdOTHours = max(0, $nsdOTHours);
        }
    }
    
    return [
        'regular_ot' => 0,
        'nsd_ot' => 0,
        'regular_ot_hours' => isset($regularOTHours) ? $regularOTHours : 0,
        'nsd_ot_hours' => isset($nsdOTHours) ? $nsdOTHours : 0
    ];
}

/**
 * Calculate overtime hours breakdown with 10pm cutoff
 */
function calculateOvertimeHoursBreakdown($employeeId, $currentMonth, $conn) {
    $regularOTHours = 0;
    $nsdOTHours = 0;
    
    $overtimeQuery = "SELECT 
                        DATE(a.attendance_date) as work_date,
                        a.time_in,
                        a.time_out,
                        a.overtime_hours,
                        e.Shift
                      FROM attendance a
                      JOIN empuser e ON a.EmployeeID = e.EmployeeID
                      WHERE a.EmployeeID = ? 
                      AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                      AND a.is_overtime = 1
                      AND a.overtime_hours > 0
                      AND (DAYOFWEEK(a.attendance_date) != 1 OR (
                          e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%'
                      ))";
    
    $stmt = $conn->prepare($overtimeQuery);
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $timeIn = $row['time_in'];
            $timeOut = $row['time_out'];
            $shift = $row['Shift'];
            
            if ($timeIn && $timeOut) {
                $shiftEndTime = extractShiftEndTime($shift);
                $otBreakdown = calculateOTWithNSD($timeIn, $timeOut, 0, floatval($row['overtime_hours']), $shiftEndTime);
                
                $regularOTHours += $otBreakdown['regular_ot_hours'];
                $nsdOTHours += $otBreakdown['nsd_ot_hours'];
            }
        }
        $stmt->close();
    }
    
    return [
        'regular_ot_hours' => $regularOTHours,
        'nsd_ot_hours' => $nsdOTHours,
        'total_ot_hours' => $regularOTHours + $nsdOTHours
    ];
}

/**
 * Calculate holiday pay for an employee
 */
function calculateHolidayPay($employeeId, $baseSalary, $currentMonth, $conn) {
    $hourlyRate = $baseSalary / 8.0;
    $holidayPay = [
        'special_holiday' => 0,
        'legal_holiday' => 0,
        'regular_holiday' => 0,
        'rest_day' => 0,
        'night_shift_diff' => 0
    ];
    
    // Special holidays
    $specialHolidayQuery = "SELECT holiday_date, holiday_name 
                           FROM special_non_working_holidays 
                           WHERE DATE_FORMAT(holiday_date, '%Y-%m') = ?";
    
    $stmt = $conn->prepare($specialHolidayQuery);
    if ($stmt) {
        $stmt->bind_param("s", $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $workedQuery = "SELECT COUNT(*) as worked 
                           FROM attendance 
                           WHERE EmployeeID = ? 
                           AND DATE(attendance_date) = ? 
                           AND time_in IS NOT NULL";
            
            $workedStmt = $conn->prepare($workedQuery);
            if ($workedStmt) {
                $workedStmt->bind_param("is", $employeeId, $row['holiday_date']);
                $workedStmt->execute();
                $workedResult = $workedStmt->get_result();
                $workedRow = $workedResult->fetch_assoc();
                
                if ($workedRow && $workedRow['worked'] > 0) {
                    // Special Holiday: baseSalary / 8 * 130% * 8 = baseSalary * 1.30
                    // Formula: (baseSalary / 8) * 1.30 * 8 = baseSalary * 1.30
                    $holidayPay['special_holiday'] += $hourlyRate * 1.30 * 8;
                    // Note: NSD Special Holiday is NOT added to special_holiday_pay
                    // NSD is handled separately in night_shift_diff calculation
                }
                $workedStmt->close();
            }
        }
        $stmt->close();
    }
    
    // Legal holidays
    $legalHolidayQuery = "SELECT holiday_date, holiday_name 
                         FROM regular_holidays 
                         WHERE DATE_FORMAT(holiday_date, '%Y-%m') = ?";
    
    $stmt = $conn->prepare($legalHolidayQuery);
    if ($stmt) {
        $stmt->bind_param("s", $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $workedQuery = "SELECT COUNT(*) as worked 
                           FROM attendance 
                           WHERE EmployeeID = ? 
                           AND DATE(attendance_date) = ? 
                           AND time_in IS NOT NULL";
            
            $workedStmt = $conn->prepare($workedQuery);
            if ($workedStmt) {
                $workedStmt->bind_param("is", $employeeId, $row['holiday_date']);
                $workedStmt->execute();
                $workedResult = $workedStmt->get_result();
                $workedRow = $workedResult->fetch_assoc();
                
                if ($workedRow && $workedRow['worked'] > 0) {
                    // Legal Holiday: hourlyRate × 2.00 × 8 hours
                    $holidayPay['legal_holiday'] += $hourlyRate * 2.00 * 8;
                    
                    // NSD Legal Holiday
                    $workedHoursQuery = "SELECT SUM(a.total_hours) as total_hours
                                          FROM attendance a
                                          JOIN empuser e ON a.EmployeeID = e.EmployeeID
                                          WHERE a.EmployeeID = ? 
                                          AND DATE(a.attendance_date) = ? 
                                          AND a.time_in IS NOT NULL";
                    
                    $workedHoursStmt = $conn->prepare($workedHoursQuery);
                    if ($workedHoursStmt) {
                        $workedHoursStmt->bind_param("is", $employeeId, $row['holiday_date']);
                        $workedHoursStmt->execute();
                        $workedHoursResult = $workedHoursStmt->get_result();
                        $workedHoursRow = $workedHoursResult->fetch_assoc();
                        
                        if ($workedHoursRow && $workedHoursRow['total_hours'] > 0) {
                            $holidayPay['legal_holiday'] += $hourlyRate * 0.10 * $workedHoursRow['total_hours'] * 2.00;
                        }
                        $workedHoursStmt->close();
                    }
                    
                    // Legal holiday OT
                    $overtimeQuery = "SELECT SUM(a.total_hours) as total_hours
                                      FROM attendance a
                                      JOIN empuser e ON a.EmployeeID = e.EmployeeID
                                      WHERE a.EmployeeID = ? 
                                      AND DATE(a.attendance_date) = ? 
                                      AND a.time_in IS NOT NULL";
                    
                    $overtimeStmt = $conn->prepare($overtimeQuery);
                    if ($overtimeStmt) {
                        $overtimeStmt->bind_param("is", $employeeId, $row['holiday_date']);
                        $overtimeStmt->execute();
                        $overtimeResult = $overtimeStmt->get_result();
                        $overtimeRow = $overtimeResult->fetch_assoc();
                        
                        if ($overtimeRow && $overtimeRow['total_hours'] > 8) {
                            $overtimeHours = $overtimeRow['total_hours'] - 8;
                            $holidayPay['legal_holiday'] += $hourlyRate * 1.25 * 2.00 * 1.30 * $overtimeHours;
                        }
                        $overtimeStmt->close();
                    }
                }
                $workedStmt->close();
            }
        }
        $stmt->close();
    }
    
    // Night shift differential
    $nightShiftQuery = "SELECT SUM(a.total_hours) as night_hours
                        FROM attendance a
                        JOIN empuser e ON a.EmployeeID = e.EmployeeID
                        WHERE a.EmployeeID = ? 
                        AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                        AND (
                            (TIME(a.time_in) >= '22:00:00' AND TIME(a.time_in) <= '23:59:59') OR
                            (TIME(a.time_in) >= '00:00:00' AND TIME(a.time_in) <= '06:00:00') OR
                            (e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%')
                        )
                        AND a.time_in IS NOT NULL";
    
    $stmt = $conn->prepare($nightShiftQuery);
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row && $row['night_hours'] > 0) {
            $holidayPay['night_shift_diff'] = $hourlyRate * 0.10 * $row['night_hours'];
        }
        $stmt->close();
    }
    
    return $holidayPay;
}

/**
 * Calculate special holiday overtime pay
 */
function calculateSpecialHolidayOvertime($employeeId, $baseSalary, $currentMonth, $conn) {
    $hourlyRate = $baseSalary / 8.0;
    $specialHolidayOT = 0;
    
    $specialHolidayQuery = "SELECT holiday_date 
                           FROM special_non_working_holidays 
                           WHERE DATE_FORMAT(holiday_date, '%Y-%m') = ?";
    
    $stmt = $conn->prepare($specialHolidayQuery);
    if ($stmt) {
        $stmt->bind_param("s", $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $overtimeQuery = "SELECT SUM(a.total_hours) as total_hours
                              FROM attendance a
                              JOIN empuser e ON a.EmployeeID = e.EmployeeID
                              WHERE a.EmployeeID = ? 
                              AND DATE(a.attendance_date) = ? 
                              AND a.time_in IS NOT NULL";
            
            $overtimeStmt = $conn->prepare($overtimeQuery);
            if ($overtimeStmt) {
                $overtimeStmt->bind_param("is", $employeeId, $row['holiday_date']);
                $overtimeStmt->execute();
                $overtimeResult = $overtimeStmt->get_result();
                $overtimeRow = $overtimeResult->fetch_assoc();
                
                if ($overtimeRow && $overtimeRow['total_hours'] > 8) {
                    $overtimeHours = $overtimeRow['total_hours'] - 8;
                    $specialHolidayOT += $hourlyRate * 1.30 * 1.30 * $overtimeHours;
                }
                $overtimeStmt->close();
            }
        }
        $stmt->close();
    }
    
    return $specialHolidayOT;
}

/**
 * Calculate Leave Pay
 */
function calculateLeavePay($employeeId, $baseSalary, $currentMonth, $conn) {
    $currentMonthNum = date('n', strtotime($currentMonth . '-01'));
    if ($currentMonthNum != 12) {
        return 0;
    }
    
    $leaveBalanceQuery = "SELECT COALESCE(leave_pay_counts, 0) as leave_balance 
                          FROM empuser 
                          WHERE EmployeeID = ?";
    
    $stmt = $conn->prepare($leaveBalanceQuery);
    if (!$stmt) {
        return 0;
    }
    
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $leaveData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$leaveData || $leaveData['leave_balance'] <= 0) {
        return 0;
    }
    
    $leaveBalance = intval($leaveData['leave_balance']);
    $leavePay = $baseSalary * $leaveBalance;
    
    return $leavePay;
}

/**
 * Calculate NSD for regular working days
 */
function calculateNSDRegularHours($employeeId, $baseSalary, $currentMonth, $conn) {
    $hourlyRate = $baseSalary / 8.0;
    $nsdRegular = 0;
    
    $nsdQuery = "SELECT 
                    DATE(a.attendance_date) as work_date,
                    a.total_hours
                  FROM attendance a
                  JOIN empuser e ON a.EmployeeID = e.EmployeeID
                  WHERE a.EmployeeID = ? 
                  AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                  AND a.time_in IS NOT NULL
                  AND (
                      (TIME(a.time_in) >= '22:00:00' AND TIME(a.time_in) <= '23:59:59') OR
                      (TIME(a.time_in) >= '00:00:00' AND TIME(a.time_in) <= '06:00:00') OR
                      (e.Shift = '22:00-06:00' OR e.Shift LIKE '%NSD%' OR e.Shift LIKE '%nsd%' OR e.Shift LIKE '%Night%' OR e.Shift LIKE '%night%')
                  )
                  AND a.attendance_date NOT IN (
                      SELECT holiday_date FROM special_non_working_holidays 
                      WHERE DATE_FORMAT(holiday_date, '%Y-%m') = ?
                  )
                  AND a.attendance_date NOT IN (
                      SELECT holiday_date FROM regular_holidays 
                      WHERE DATE_FORMAT(holiday_date, '%Y-%m') = ?
                  )";
    
    $stmt = $conn->prepare($nsdQuery);
    if ($stmt) {
        $stmt->bind_param("isss", $employeeId, $currentMonth, $currentMonth, $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            if ($row['total_hours'] > 0) {
                $nsdRegular += $hourlyRate * 0.10 * $row['total_hours'];
            }
        }
        $stmt->close();
    }
    
    return $nsdRegular;
}

/**
 * Validate consistency between payroll and attendance
 */
function validatePayrollAttendanceConsistency($employeeId, $currentMonth, $conn) {
    $validation = [
        'is_consistent' => true,
        'issues' => [],
        'warnings' => [],
        'recommendations' => []
    ];
    
    $attendanceCheck = "SELECT 
                        COUNT(*) as total_records,
                        COUNT(CASE WHEN a.total_hours IS NULL THEN 1 END) as missing_total_hours,
                        COUNT(CASE WHEN a.overtime_hours IS NULL THEN 1 END) as missing_overtime_hours,
                        COUNT(CASE WHEN a.is_overtime IS NULL THEN 1 END) as missing_is_overtime
                        FROM attendance a
                        JOIN empuser e ON a.EmployeeID = e.EmployeeID
                        WHERE a.EmployeeID = ? 
                        AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                        AND a.time_in IS NOT NULL";
    
    $stmt = $conn->prepare($attendanceCheck);
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row) {
            if ($row['missing_total_hours'] > 0) {
                $validation['is_consistent'] = false;
                $validation['issues'][] = "Missing total_hours for {$row['missing_total_hours']} records";
            }
            
            if ($row['missing_overtime_hours'] > 0) {
                $validation['is_consistent'] = false;
                $validation['issues'][] = "Missing overtime_hours for {$row['missing_overtime_hours']} records";
            }
            
            if ($row['missing_is_overtime'] > 0) {
                $validation['warnings'][] = "Missing is_overtime flag for {$row['missing_is_overtime']} records";
            }
            
            if ($row['total_records'] == 0) {
                $validation['warnings'][] = "No attendance records found for this employee and month";
            }
        }
    }
    
    $inconsistencyCheck = "SELECT 
                           COUNT(CASE WHEN a.is_overtime = 1 AND a.overtime_hours <= 0 THEN 1 END) as ot_flag_no_hours,
                           COUNT(CASE WHEN a.is_overtime = 0 AND a.overtime_hours > 0 THEN 1 END) as no_ot_flag_has_hours
                           FROM attendance a
                           JOIN empuser e ON a.EmployeeID = e.EmployeeID
                           WHERE a.EmployeeID = ? 
                           AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                           AND a.time_in IS NOT NULL";
    
    $stmt = $conn->prepare($inconsistencyCheck);
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row) {
            if ($row['ot_flag_no_hours'] > 0) {
                $validation['warnings'][] = "Found {$row['ot_flag_no_hours']} records with overtime flag but no overtime hours";
            }
            
            if ($row['no_ot_flag_has_hours'] > 0) {
                $validation['warnings'][] = "Found {$row['no_ot_flag_has_hours']} records with overtime hours but no overtime flag";
            }
        }
    }
    
    if (!$validation['is_consistent']) {
        $validation['recommendations'][] = "Run attendance recalculation to fix missing data";
    }
    
    if (count($validation['warnings']) > 0) {
        $validation['recommendations'][] = "Review attendance calculations for data consistency";
    }
    
    return $validation;
}

/**
 * Recalculate attendance data if needed
 */
function recalculateAttendanceIfNeeded($employeeId, $currentMonth, $conn) {
    require_once 'attendance_calculations.php';
    
    $results = [
        'recalculated' => false,
        'records_updated' => 0,
        'errors' => []
    ];
    
    $query = "SELECT a.*, e.Shift FROM attendance a 
              JOIN empuser e ON a.EmployeeID = e.EmployeeID
              WHERE a.EmployeeID = ? 
              AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
              AND (a.total_hours IS NULL OR a.overtime_hours IS NULL OR a.is_overtime IS NULL)";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("is", $employeeId, $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (count($records) > 0) {
            $updatedRecords = \AttendanceCalculator::calculateAttendanceMetrics($records);
            
            foreach ($updatedRecords as $record) {
                if (\AttendanceCalculator::updateAttendanceRecord($conn, $record['id'], $record)) {
                    $results['records_updated']++;
                    continue;
                }
                $results['errors'][] = "Failed to update record ID: " . $record['id'];
            }
            
            $results['recalculated'] = true;
        }
    }
    
    return $results;
}

/**
 * Force recalculation of overtime for all employees on a specific date
 */
function forceRecalculateOvertimeForDate($date, $conn) {
    require_once 'attendance_calculations.php';
    
    $summary = [
        'total_records' => 0,
        'updated_records' => 0,
        'overtime_corrected' => 0,
        'errors' => []
    ];
    
    try {
        $summary = \AttendanceCalculator::forceRecalculateOvertimeForDate($conn, $date);
        error_log("Overtime recalculation for {$date}: {$summary['total_records']} records, {$summary['updated_records']} updated, {$summary['overtime_corrected']} overtime corrected");
    } catch (Exception $e) {
        $summary['errors'][] = "Error during recalculation: " . $e->getMessage();
        error_log("Error in forceRecalculateOvertimeForDate: " . $e->getMessage());
    }
    
    return $summary;
}

/**
 * Create the overtime_punches table if it doesn't exist
 */
function createOvertimePunchesTable($conn) {
    try {
        $createTableSQL = "
        CREATE TABLE IF NOT EXISTS overtime_punches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            EmployeeID INT NOT NULL,
            punch_date DATE NOT NULL,
            punch_time TIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_punch (EmployeeID, punch_date),
            INDEX idx_employee_date (EmployeeID, punch_date),
            INDEX idx_punch_date (punch_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $result = $conn->query($createTableSQL);
        
        if ($result) {
            error_log("Overtime punches table created successfully");
            return true;
        } else {
            error_log("Failed to create overtime punches table: " . $conn->error);
            return false;
        }
    } catch (Exception $e) {
        error_log("Error creating overtime punches table: " . $e->getMessage());
        return false;
    }
}
?>