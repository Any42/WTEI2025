<?php
/**
 * Auto-Insert Absent Attendance Records
 * Creates absent attendance records for new employees based on their hire date
 * and the system's attendance date range
 */

require_once 'attendance_calculations.php';

class AutoAbsentAttendance {
    
    /**
     * Auto-insert absent attendance records for a new employee
     * 
     * @param mysqli $conn Database connection
     * @param int $employee_id Employee ID
     * @param string $date_hired Employee's hire date (Y-m-d format)
     * @param string $shift Employee's shift schedule
     * @return array Result with success status and details
     */
    public static function createAbsentRecords($conn, $employee_id, $date_hired, $shift = '') {
        try {
            // Get system attendance date range
            $system_range = self::getSystemAttendanceRange($conn);
            if (!$system_range) {
                return [
                    'success' => false,
                    'message' => 'Could not determine system attendance date range',
                    'records_created' => 0
                ];
            }
            
            $system_start = $system_range['start_date'];
            $system_end = $system_range['end_date'];
            
            // Determine the effective start date for absent records
            $effective_start = self::getEffectiveStartDate($date_hired, $system_start);
            
            // Generate working days between effective start and system end
            $working_days = self::generateWorkingDays($effective_start, $system_end);
            
            if (empty($working_days)) {
                return [
                    'success' => true,
                    'message' => 'No working days to create absent records for',
                    'records_created' => 0
                ];
            }
            
            // Check which dates already have attendance records
            $existing_dates = self::getExistingAttendanceDates($conn, $employee_id, $working_days);
            
            // Filter out dates that already have records
            $dates_to_create = array_diff($working_days, $existing_dates);
            
            if (empty($dates_to_create)) {
                return [
                    'success' => true,
                    'message' => 'All dates already have attendance records',
                    'records_created' => 0
                ];
            }
            
            // Create absent records for missing dates
            $created_count = self::insertAbsentRecords($conn, $employee_id, $dates_to_create, $shift);
            
            return [
                'success' => true,
                'message' => "Created {$created_count} absent attendance records",
                'records_created' => $created_count,
                'date_range' => [
                    'effective_start' => $effective_start,
                    'system_end' => $system_end,
                    'total_working_days' => count($working_days),
                    'dates_created' => $dates_to_create
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error creating absent records: ' . $e->getMessage(),
                'records_created' => 0
            ];
        }
    }
    
    /**
     * Get the system's attendance date range
     * 
     * @param mysqli $conn Database connection
     * @return array|false Array with start_date and end_date, or false on error
     */
    public static function getSystemAttendanceRange($conn) {
        $query = "SELECT 
                    MIN(DATE(attendance_date)) as start_date,
                    MAX(DATE(attendance_date)) as end_date
                  FROM attendance 
                  WHERE attendance_date IS NOT NULL";
        
        $result = $conn->query($query);
        if (!$result || $result->num_rows === 0) {
            // If no attendance records exist, use current month as default
            $current_month = date('Y-m-01');
            $current_date = date('Y-m-d');
            return [
                'start_date' => $current_month,
                'end_date' => $current_date
            ];
        }
        
        $row = $result->fetch_assoc();
        return [
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date']
        ];
    }
    
    /**
     * Determine the effective start date for creating absent records
     * 
     * @param string $date_hired Employee's hire date
     * @param string $system_start System's attendance start date
     * @return string Effective start date for absent records
     */
    private static function getEffectiveStartDate($date_hired, $system_start) {
        // If employee was hired before system start, use system start (fill all system range)
        if ($date_hired < $system_start) {
            return $system_start;
        }
        
        // If employee was hired on or after system start, use hire date (only from hire date onwards)
        return $date_hired;
    }
    
    /**
     * Generate working days (excluding Sundays) between two dates
     * 
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @return array Array of working day dates
     */
    private static function generateWorkingDays($start_date, $end_date) {
        $working_days = [];
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        
        while ($start <= $end) {
            // Skip Sundays (day of week 0 = Sunday)
            if ($start->format('w') != 0) {
                $working_days[] = $start->format('Y-m-d');
            }
            $start->add(new DateInterval('P1D'));
        }
        
        return $working_days;
    }
    
    /**
     * Get existing attendance dates for an employee
     * 
     * @param mysqli $conn Database connection
     * @param int $employee_id Employee ID
     * @param array $date_list List of dates to check
     * @return array Array of dates that already have attendance records
     */
    private static function getExistingAttendanceDates($conn, $employee_id, $date_list) {
        if (empty($date_list)) {
            return [];
        }
        
        // Create placeholders for the IN clause
        $placeholders = str_repeat('?,', count($date_list) - 1) . '?';
        
        $query = "SELECT DISTINCT DATE(attendance_date) as date 
                  FROM attendance 
                  WHERE EmployeeID = ? AND DATE(attendance_date) IN ($placeholders)";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        // Bind parameters: employee_id + all dates
        $params = array_merge([$employee_id], $date_list);
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
        
        $existing_dates = [];
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $existing_dates[] = $row['date'];
            }
        }
        
        $stmt->close();
        return $existing_dates;
    }
    
    /**
     * Insert absent attendance records for specified dates
     * 
     * @param mysqli $conn Database connection
     * @param int $employee_id Employee ID
     * @param array $dates Array of dates to create records for
     * @param string $shift Employee's shift schedule
     * @return int Number of records created
     */
    private static function insertAbsentRecords($conn, $employee_id, $dates, $shift = '') {
        if (empty($dates)) {
            return 0;
        }
        
        $created_count = 0;
        
        // Prepare the insert statement
        $insert_query = "INSERT INTO attendance (
                            EmployeeID, 
                            attendance_date, 
                            attendance_type, 
                            status, 
                            time_in, 
                            time_out, 
                            time_in_morning, 
                            time_out_morning, 
                            time_in_afternoon, 
                            time_out_afternoon, 
                            notes, 
                            overtime_hours, 
                            early_out_minutes, 
                            late_minutes, 
                            is_overtime, 
                            total_hours
                         ) VALUES (?, ?, 'absent', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Auto-generated absent record', 0.00, 0, 0, 0, 0.00)";
        
        $stmt = $conn->prepare($insert_query);
        if (!$stmt) {
            return 0;
        }
        
        foreach ($dates as $date) {
            $stmt->bind_param("is", $employee_id, $date);
            if ($stmt->execute()) {
                $created_count++;
            }
        }
        
        $stmt->close();
        return $created_count;
    }
    
    /**
     * Create absent records for multiple employees (bulk operation)
     * 
     * @param mysqli $conn Database connection
     * @param array $employees Array of employee data with keys: employee_id, date_hired, shift
     * @return array Summary of results
     */
    public static function createAbsentRecordsBulk($conn, $employees) {
        $results = [
            'total_processed' => 0,
            'total_created' => 0,
            'successful' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        foreach ($employees as $employee) {
            $result = self::createAbsentRecords(
                $conn, 
                $employee['employee_id'], 
                $employee['date_hired'], 
                $employee['shift'] ?? ''
            );
            
            $results['total_processed']++;
            $results['total_created'] += $result['records_created'];
            
            if ($result['success']) {
                $results['successful']++;
            } else {
                $results['failed']++;
            }
            
            $results['details'][] = [
                'employee_id' => $employee['employee_id'],
                'result' => $result
            ];
        }
        
        return $results;
    }
    
    /**
     * Update absent records for an existing employee when their hire date changes
     * 
     * @param mysqli $conn Database connection
     * @param int $employee_id Employee ID
     * @param string $old_date_hired Previous hire date
     * @param string $new_date_hired New hire date
     * @param string $shift Employee's shift schedule
     * @return array Result with success status and details
     */
    public static function updateAbsentRecordsForDateChange($conn, $employee_id, $old_date_hired, $new_date_hired, $shift = '') {
        try {
            // Get system attendance date range
            $system_range = self::getSystemAttendanceRange($conn);
            if (!$system_range) {
                return [
                    'success' => false,
                    'message' => 'Could not determine system attendance date range',
                    'records_created' => 0,
                    'records_removed' => 0
                ];
            }
            
            $system_start = $system_range['start_date'];
            $system_end = $system_range['end_date'];
            
            // Determine old and new effective start dates
            $old_effective_start = self::getEffectiveStartDate($old_date_hired, $system_start);
            $new_effective_start = self::getEffectiveStartDate($new_date_hired, $system_start);
            
            $records_created = 0;
            $records_removed = 0;
            
            // If new hire date is earlier, we might need to create more absent records
            if ($new_effective_start < $old_effective_start) {
                $additional_days = self::generateWorkingDays($new_effective_start, $old_effective_start);
                $existing_dates = self::getExistingAttendanceDates($conn, $employee_id, $additional_days);
                $dates_to_create = array_diff($additional_days, $existing_dates);
                $records_created = self::insertAbsentRecords($conn, $employee_id, $dates_to_create, $shift);
            }
            
            // If new hire date is later, we might need to remove some absent records
            if ($new_effective_start > $old_effective_start) {
                $days_to_remove = self::generateWorkingDays($old_effective_start, $new_effective_start);
                $records_removed = self::removeAbsentRecords($conn, $employee_id, $days_to_remove);
            }
            
            return [
                'success' => true,
                'message' => "Updated absent records: {$records_created} created, {$records_removed} removed",
                'records_created' => $records_created,
                'records_removed' => $records_removed
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error updating absent records: ' . $e->getMessage(),
                'records_created' => 0,
                'records_removed' => 0
            ];
        }
    }
    
    /**
     * Remove absent attendance records for specified dates
     * 
     * @param mysqli $conn Database connection
     * @param int $employee_id Employee ID
     * @param array $dates Array of dates to remove records for
     * @return int Number of records removed
     */
    private static function removeAbsentRecords($conn, $employee_id, $dates) {
        if (empty($dates)) {
            return 0;
        }
        
        $removed_count = 0;
        
        foreach ($dates as $date) {
            $query = "DELETE FROM attendance 
                      WHERE EmployeeID = ? 
                      AND DATE(attendance_date) = ? 
                      AND attendance_type = 'absent' 
                      AND time_in IS NULL 
                      AND time_out IS NULL";
            
            $stmt = $conn->prepare($query);
            if ($stmt) {
                $stmt->bind_param("is", $employee_id, $date);
                if ($stmt->execute()) {
                    $removed_count += $stmt->affected_rows;
                }
                $stmt->close();
            }
        }
        
        return $removed_count;
    }
}
?>
