<?php
/**
 * Centralized Attendance Calculations
 * Provides accurate calculations for overtime hours and early out minutes
 */

class AttendanceCalculator {
    
    // Standard work hours configuration
    const WORK_START_TIME = '08:00:00';
    const WORK_END_TIME = '17:00:00';
    const LATE_CUTOFF_MINUTES = 0; // mark late immediately after scheduled start
    const OVERTIME_START_TIME = '17:00:00';
    const OVERTIME_GRACE_MINUTES_AFTER_END = 30; // overtime counts after this grace
    
    /**
     * Calculate accurate overtime hours and early out minutes for attendance records
     * 
     * @param array $attendance_records Array of attendance records
     * @return array Updated attendance records with accurate calculations
     */
    public static function calculateAttendanceMetrics($attendance_records) {
        foreach ($attendance_records as &$record) {
            // Resolve schedule and lunch windows from shift string if available
            $shift = isset($record['Shift']) ? (string)$record['Shift'] : '';
            [$schedStart, $schedEnd] = self::getShiftSchedule($shift);
            [$lunchStart, $lunchEnd] = self::getLunchWindow($shift);
            $attendanceDate = isset($record['attendance_date']) ? $record['attendance_date'] : null;

            // Determine effective in/out using split segments when provided
            $amIn = $record['time_in_morning'] ?? null;
            $amOut = $record['time_out_morning'] ?? null;
            $pmIn = $record['time_in_afternoon'] ?? null;
            $pmOut = $record['time_out_afternoon'] ?? null;

            $effectiveIn = $record['time_in'] ?? ($amIn ?: $pmIn);
            $effectiveOut = $record['time_out'] ?? ($pmOut ?: $amOut);

            // Detect halfday first (before calculating overtime)
            $hasMorningComplete = !empty($amIn) && !empty($amOut);
            $hasAfternoonComplete = !empty($pmIn) && !empty($pmOut);
            $hasMorningPartial = !empty($amIn) || !empty($amOut);
            $hasAfternoonPartial = !empty($pmIn) || !empty($pmOut);
            
            // Calculate total hours FIRST to detect halfday
            $segmentHours = self::calculateTotalHoursFromSegments(
                $attendanceDate,
                $amIn,
                $amOut,
                $pmIn,
                $pmOut,
                $schedStart,
                $schedEnd,
                $lunchStart,
                $lunchEnd
            );
            $record['total_hours'] = ($segmentHours !== null) ? $segmentHours : 0.00;
            
            // Detect if this is a halfday
            $isHalfday = false;
            if ($record['total_hours'] > 0 && $record['total_hours'] <= 4.0) {
                $isHalfday = true;
            } elseif (($hasMorningComplete && !$hasAfternoonPartial) || ($hasAfternoonComplete && !$hasMorningPartial)) {
                $isHalfday = true;
            }

            // Skip overtime calculation for halfdays - even if they work past overtime grace period
            if ($isHalfday) {
                $record['overtime_hours'] = 0.00;
                $record['nsd_ot_hours'] = 0.00;
                $record['is_on_nsdot'] = 0;
            } else {
                // CRITICAL FIX: Always recalculate overtime hours to ensure accuracy
                // Remove the condition that prevents recalculation when overtime_hours > 0
                $record['overtime_hours'] = self::calculateEnhancedOvertimeHours(
                    $effectiveIn,
                    $effectiveOut,
                    $amIn,
                    $amOut,
                    $pmIn,
                    $pmOut,
                    $schedEnd,
                    $attendanceDate,
                    $record
                );
                
                // Calculate NSD OT hours (10PM onwards) - NEW FEATURE
                $nsdBreakdown = self::calculateNSDOvertimeHours(
                    $effectiveIn,
                    $effectiveOut,
                    $amIn,
                    $amOut,
                    $pmIn,
                    $pmOut,
                    $schedEnd,
                    $attendanceDate
                );
                $record['nsd_ot_hours'] = $nsdBreakdown['nsd_hours'];
                $record['is_on_nsdot'] = $nsdBreakdown['has_nsd'] ? 1 : 0;
            }

            // Calculate early out minutes (prefer PM segment if present)
            $pmPreferredOut = $pmOut ?: $effectiveOut;
            $record['early_out_minutes'] = self::calculateEarlyOutMinutes(
                $pmPreferredOut,
                $schedEnd
            );
            
            // Calculate late minutes
            $record['late_minutes'] = self::calculateLateMinutes(
                $effectiveIn,
                $schedStart
            );

            // Early-in minutes (for info)
            $record['early_in_minutes'] = self::calculateEarlyInMinutes(
                $record['time_in'] ?? null,
                $schedStart
            );
            
            // Determine if overtime occurred
            $record['is_overtime'] = $record['overtime_hours'] > 0 ? 1 : 0;
            
            // If employee is on leave, set total hours to 0
            if (isset($record['is_on_leave']) && $record['is_on_leave'] == 1) {
                $record['total_hours'] = 0.00;
                $record['overtime_hours'] = 0.00;
                $record['is_overtime'] = 0;
                $record['late_minutes'] = 0;
                $record['early_out_minutes'] = 0;
                $record['nsd_ot_hours'] = 0.00;
                $record['is_on_nsdot'] = 0;
            }
            
            // Update status based on calculations (with total_hours available)
            $record['status'] = self::determineStatus(
                $effectiveIn,
                $effectiveOut,
                $record['late_minutes'],
                $record['early_out_minutes'],
                $schedEnd,
                $attendanceDate,
                $schedStart,
                $amIn,
                $amOut,
                $pmIn,
                $pmOut,
                $record['total_hours']
            );
        }
        
        return $attendance_records;
    }

    /**
     * Calculate total hours from AM/PM split segments when any segment exists.
     * Returns null if no segment information is present, so caller can fallback.
     */
    private static function calculateTotalHoursFromSegments($attendance_date, $am_in, $am_out, $pm_in, $pm_out, $sched_start = null, $sched_end = null, $lunch_start = null, $lunch_end = null) {
        $totalSeconds = 0;
        $hasAny = false;
        $toTs = function($date, $t) {
            if (!$t) return null;
            // accept HH:MM or HH:MM:SS or full datetime
            if (preg_match('/^\d{4}-\d{2}-\d{2} /', $t)) {
                return strtotime($t);
            }
            $timeNorm = (strlen($t) === 5) ? ($t . ':00') : $t;
            if ($date) {
                return strtotime($date . ' ' . $timeNorm);
            }
            return strtotime($timeNorm);
        };
        // Determine default boundaries based on shift
        $defaultAmIn  = $sched_start ?: '08:00:00';
        $defaultAmOut = $lunch_start ?: '12:00:00';
        $defaultPmIn  = $lunch_end ?: '13:00:00';
        $defaultPmOut = $sched_end ?: '17:00:00';

        // Morning segment (cap within default window)
        if ($am_in && $am_out) {
            $hasAny = true;
            $inTs = $toTs($attendance_date, $am_in);
            $outTs = $toTs($attendance_date, $am_out);
            $capStart = $toTs($attendance_date, $defaultAmIn);
            $capEnd   = $toTs($attendance_date, $defaultAmOut);
            if ($inTs !== null)  { $inTs  = max($inTs,  $capStart); }
            if ($outTs !== null) { $outTs = min($outTs, $capEnd); }
            if ($inTs !== null && $outTs !== null && $outTs > $inTs) {
                $totalSeconds += ($outTs - $inTs);
            }
        } elseif ($am_in || $am_out) {
            $hasAny = true;
        }
        // Afternoon segment (cap within default window)
        if ($pm_in && $pm_out) {
            $hasAny = true;
            $inTs = $toTs($attendance_date, $pm_in);
            $outTs = $toTs($attendance_date, $pm_out);
            $capStart = $toTs($attendance_date, $defaultPmIn);
            $capEnd   = $toTs($attendance_date, $defaultPmOut);
            if ($inTs !== null)  { $inTs  = max($inTs,  $capStart); }
            if ($outTs !== null) { $outTs = min($outTs, $capEnd); }
            if ($inTs !== null && $outTs !== null && $outTs > $inTs) {
                $totalSeconds += ($outTs - $inTs);
            }
        } elseif ($pm_in || $pm_out) {
            $hasAny = true;
        }
        if (!$hasAny) {
            return null;
        }
        return round(max(0, $totalSeconds) / 3600, 2);
    }
    
    /**
     * Calculate overtime hours based on time in and time out
     * 
     * @param string|null $time_in Time in (HH:MM:SS format)
     * @param string|null $time_out Time out (HH:MM:SS format)
     * @param string|null $scheduled_end Scheduled end time
     * @return float Overtime hours (decimal)
     */
    public static function calculateOvertimeHours($time_in, $time_out, $scheduled_end = null, $attendance_date = null) {
        if (!$time_in || !$time_out) {
            return 0.00;
        }
        
        // Anchor all timestamps to the attendance date to avoid cross-day drift
        $dateAnchor = $attendance_date ?: date('Y-m-d');
        $endStr = $scheduled_end ?: self::WORK_END_TIME;
        $work_end = strtotime($dateAnchor . ' ' . $endStr);
        // Apply 30-minute grace after scheduled end before counting OT
        $overtime_threshold = $work_end + (self::OVERTIME_GRACE_MINUTES_AFTER_END * 60);
        $time_in_ts = preg_match('/^\d{4}-\d{2}-\d{2} /', (string)$time_in)
            ? strtotime($time_in)
            : strtotime($dateAnchor . ' ' . ((strlen($time_in) === 5) ? ($time_in . ':00') : $time_in));
        $time_out_ts = preg_match('/^\d{4}-\d{2}-\d{2} /', (string)$time_out)
            ? strtotime($time_out)
            : strtotime($dateAnchor . ' ' . ((strlen($time_out) === 5) ? ($time_out . ':00') : $time_out));
        
        // If time out is before grace threshold, no overtime
        if ($time_out_ts <= $overtime_threshold) {
            return 0.00;
        }
        
        // Calculate overtime hours
        $overtime_seconds = $time_out_ts - $overtime_threshold;
        $overtime_hours = $overtime_seconds / 3600;
        
        // Round to 2 decimal places
        return round($overtime_hours, 2);
    }

    /**
     * Calculate overtime hours for split sessions (morning/afternoon)
     * This provides more accurate overtime calculation for employees with lunch breaks
     * 
     * @param string|null $am_in Morning time in
     * @param string|null $am_out Morning time out
     * @param string|null $pm_in Afternoon time in
     * @param string|null $pm_out Afternoon time out
     * @param string|null $scheduled_end Scheduled end time
     * @param string|null $attendance_date Attendance date for proper time calculation
     * @return float Total overtime hours
     */
    public static function calculateOvertimeHoursFromSegments($am_in, $am_out, $pm_in, $pm_out, $scheduled_end = null, $attendance_date = null) {
        $total_overtime = 0.00;
        // Anchor work end to attendance date to prevent cross-day drift
        $dateAnchor = $attendance_date ?: date('Y-m-d');
        $endStr = $scheduled_end ?: self::WORK_END_TIME;
        $work_end = strtotime($dateAnchor . ' ' . $endStr);
        $overtime_threshold = $work_end + (self::OVERTIME_GRACE_MINUTES_AFTER_END * 60);
        
        // Calculate overtime for morning session
        if ($am_in && $am_out) {
            $am_in_ts = $attendance_date ? strtotime($attendance_date . ' ' . $am_in) : strtotime($am_in);
            $am_out_ts = $attendance_date ? strtotime($attendance_date . ' ' . $am_out) : strtotime($am_out);
            
            // If morning session extends beyond work end time
            if ($am_out_ts > $overtime_threshold) {
                $overtime_seconds = $am_out_ts - $overtime_threshold;
                $total_overtime += $overtime_seconds / 3600;
            }
        }
        
        // Calculate overtime for afternoon session
        if ($pm_in && $pm_out) {
            $pm_in_ts = $attendance_date ? strtotime($attendance_date . ' ' . $pm_in) : strtotime($pm_in);
            $pm_out_ts = $attendance_date ? strtotime($attendance_date . ' ' . $pm_out) : strtotime($pm_out);
            
            // If afternoon session extends beyond work end time
            if ($pm_out_ts > $overtime_threshold) {
                $overtime_seconds = $pm_out_ts - $overtime_threshold;
                $total_overtime += $overtime_seconds / 3600;
            }
        }
        
        return round($total_overtime, 2);
    }

    /**
     * Enhanced overtime calculation that considers both single session and split sessions
     * 
     * @param string|null $time_in Overall time in
     * @param string|null $time_out Overall time out
     * @param string|null $am_in Morning time in
     * @param string|null $am_out Morning time out
     * @param string|null $pm_in Afternoon time in
     * @param string|null $pm_out Afternoon time out
     * @param string|null $scheduled_end Scheduled end time
     * @param string|null $attendance_date Attendance date
     * @return float Total overtime hours
     */
    public static function calculateEnhancedOvertimeHours($time_in, $time_out, $am_in, $am_out, $pm_in, $pm_out, $scheduled_end = null, $attendance_date = null, $record = null) {
        // Check for overtime columns first (5th+ punches)
        $overtime_time_in = $record['overtime_time_in'] ?? null;
        $overtime_time_out = $record['overtime_time_out'] ?? null;
        
        // If we have overtime columns, use them for calculation
        if ($overtime_time_in && $overtime_time_out) {
            return self::calculateOvertimeFromOvertimeColumns($overtime_time_in, $overtime_time_out, $scheduled_end);
        }
        
        // If only overtime_time_in (5th punch only), cut at 10PM/NSD time
        if ($overtime_time_in && !$overtime_time_out) {
            return self::calculateOvertimeWithCutoff($overtime_time_in, $scheduled_end);
        }
        
        // If we have split sessions, use the more accurate calculation
        if (($am_in || $am_out) || ($pm_in || $pm_out)) {
            return self::calculateOvertimeHoursFromSegments($am_in, $am_out, $pm_in, $pm_out, $scheduled_end, $attendance_date);
        }
        
        // Fallback to standard calculation for single session
        return self::calculateOvertimeHours($time_in, $time_out, $scheduled_end, $attendance_date);
    }

    /**
     * Calculate overtime from overtime columns (5th+ punches)
     * 
     * @param string $overtime_time_in Overtime time in
     * @param string $overtime_time_out Overtime time out
     * @param string|null $scheduled_end Scheduled end time
     * @return float Overtime hours
     */
    private static function calculateOvertimeFromOvertimeColumns($overtime_time_in, $overtime_time_out, $scheduled_end = null) {
        $inTime = DateTime::createFromFormat('H:i:s', $overtime_time_in);
        $outTime = DateTime::createFromFormat('H:i:s', $overtime_time_out);
        
        if (!$inTime || !$outTime) {
            return 0.0;
        }
        
        // Get scheduled end time
        $schedEnd = $scheduled_end ? DateTime::createFromFormat('H:i:s', $scheduled_end) : null;
        if (!$schedEnd) {
            $schedEnd = DateTime::createFromFormat('H:i:s', '17:30:00'); // Default 5:30 PM
        }
        
        // Calculate overtime hours from overtime columns
        $overtimeStart = max($inTime, $schedEnd);
        $overtimeDuration = $outTime->diff($overtimeStart);
        $overtimeHours = $overtimeDuration->h + ($overtimeDuration->i / 60.0) + ($overtimeDuration->s / 3600.0);
        
        return round($overtimeHours, 2);
    }

    /**
     * Calculate overtime with 10PM/NSD cutoff (5th punch only)
     * 
     * @param string $overtime_time_in Overtime time in
     * @param string|null $scheduled_end Scheduled end time
     * @return float Overtime hours
     */
    private static function calculateOvertimeWithCutoff($overtime_time_in, $scheduled_end = null) {
        $inTime = DateTime::createFromFormat('H:i:s', $overtime_time_in);
        
        if (!$inTime) {
            return 0.0;
        }
        
        // Get scheduled end time
        $schedEnd = $scheduled_end ? DateTime::createFromFormat('H:i:s', $scheduled_end) : null;
        if (!$schedEnd) {
            $schedEnd = DateTime::createFromFormat('H:i:s', '17:30:00'); // Default 5:30 PM
        }
        
        // NSD cutoff is 10:00 PM (22:00)
        $nsdCutoff = DateTime::createFromFormat('H:i:s', '22:00:00');
        
        // Calculate overtime hours with cutoff
        $overtimeStart = max($inTime, $schedEnd);
        $overtimeEnd = min($inTime->add(new DateInterval('PT8H')), $nsdCutoff); // Max 8 hours or until 10PM
        
        $overtimeDuration = $overtimeEnd->diff($overtimeStart);
        $overtimeHours = $overtimeDuration->h + ($overtimeDuration->i / 60.0) + ($overtimeDuration->s / 3600.0);
        
        return round($overtimeHours, 2);
    }

    /**
     * Calculate NSD (Night Shift Differential) overtime hours (10PM onwards)
     * 
     * @param string|null $time_in Overall time in
     * @param string|null $time_out Overall time out
     * @param string|null $am_in Morning time in
     * @param string|null $am_out Morning time out
     * @param string|null $pm_in Afternoon time in
     * @param string|null $pm_out Afternoon time out
     * @param string|null $scheduled_end Scheduled end time
     * @param string|null $attendance_date Attendance date
     * @return array NSD breakdown with hours and flag
     */
    public static function calculateNSDOvertimeHours($time_in, $time_out, $am_in, $am_out, $pm_in, $pm_out, $scheduled_end = null, $attendance_date = null) {
        $nsdCutoffTime = '22:00:00'; // 10:00 PM
        $totalNsdHours = 0.00;
        $hasNsd = false;
        
        // Anchor to attendance date to prevent cross-day issues
        $dateAnchor = $attendance_date ?: date('Y-m-d');
        $nsdCutoffTs = strtotime($dateAnchor . ' ' . $nsdCutoffTime);
        
        // If we have split sessions, check both morning and afternoon
        if (($am_in || $am_out) || ($pm_in || $pm_out)) {
            // Check morning session for NSD
            if ($am_in && $am_out) {
                $amOutTs = $attendance_date ? strtotime($attendance_date . ' ' . $am_out) : strtotime($am_out);
                if ($amOutTs > $nsdCutoffTs) {
                    $nsdSeconds = $amOutTs - $nsdCutoffTs;
                    $totalNsdHours += $nsdSeconds / 3600;
                    $hasNsd = true;
                }
            }
            
            // Check afternoon session for NSD
            if ($pm_in && $pm_out) {
                $pmOutTs = $attendance_date ? strtotime($attendance_date . ' ' . $pm_out) : strtotime($pm_out);
                if ($pmOutTs > $nsdCutoffTs) {
                    $nsdSeconds = $pmOutTs - $nsdCutoffTs;
                    $totalNsdHours += $nsdSeconds / 3600;
                    $hasNsd = true;
                }
            }
        } else {
            // Single session - check overall time out
            if ($time_in && $time_out) {
                $timeOutTs = preg_match('/^\d{4}-\d{2}-\d{2} /', (string)$time_out)
                    ? strtotime($time_out)
                    : strtotime($dateAnchor . ' ' . ((strlen($time_out) === 5) ? ($time_out . ':00') : $time_out));
                
                if ($timeOutTs > $nsdCutoffTs) {
                    $nsdSeconds = $timeOutTs - $nsdCutoffTs;
                    $totalNsdHours = $nsdSeconds / 3600;
                    $hasNsd = true;
                }
            }
        }
        
        return [
            'nsd_hours' => round($totalNsdHours, 2),
            'has_nsd' => $hasNsd
        ];
    }
    
    /**
     * Calculate early out minutes
     * 
     * @param string|null $time_out Time out (HH:MM:SS format)
     * @return int Early out minutes
     */
    public static function calculateEarlyOutMinutes($time_out, $scheduled_end = null) {
        if (!$time_out) {
            return 0;
        }
        
        $work_end = $scheduled_end ? strtotime($scheduled_end) : strtotime(self::WORK_END_TIME);
        $time_out_ts = strtotime($time_out);
        
        // If time out is after work end time, no early out
        if ($time_out_ts >= $work_end) {
            return 0;
        }
        
        // Calculate early out minutes
        $early_out_seconds = $work_end - $time_out_ts;
        $early_out_minutes = $early_out_seconds / 60;
        
        return (int) $early_out_minutes;
    }
    
    /**
     * Calculate late minutes
     * 
     * @param string|null $time_in Time in (HH:MM:SS format)
     * @return int Late minutes
     */
    public static function calculateLateMinutes($time_in, $scheduled_start = null) {
        if (!$time_in) {
            return 0;
        }
        
        // If no scheduled start provided, return 0 (don't assume default time)
        if (!$scheduled_start) {
            return 0;
        }
        
        $work_start = strtotime($scheduled_start);
        $late_cutoff = $work_start + (self::LATE_CUTOFF_MINUTES * 60);
        $time_in_ts = strtotime($time_in);
        
        // If time in is before late cutoff, not late
        if ($time_in_ts <= $late_cutoff) {
            return 0;
        }
        
        // Calculate late minutes
        $late_seconds = $time_in_ts - $late_cutoff;
        $late_minutes = $late_seconds / 60;
        
        return (int) $late_minutes;
    }
    
    /**
     * Determine attendance status based on calculations
     * 
     * @param string|null $time_in Time in
     * @param string|null $time_out Time out
     * @param int $late_minutes Late minutes
     * @param int $early_out_minutes Early out minutes
     * @param string|null $scheduled_start Scheduled start time
     * @param string|null $scheduled_end Scheduled end time
     * @param string|null $attendance_date Attendance date
     * @return string Status (present, late, early, absent, half_day, early_in, on_time)
     */
    public static function determineStatus($time_in, $time_out, $late_minutes, $early_out_minutes, $scheduled_end = null, $attendance_date = null, $scheduled_start = null, $am_in = null, $am_out = null, $pm_in = null, $pm_out = null, $total_hours = 0) {
        if (!$time_in) {
            return 'absent';
        }
        
        // PRIORITY 1: Check for half day FIRST (takes priority over ALL other statuses)
        // Halfday conditions (in order of priority):
        // 1. Total hours <= 4 hours (regardless of other statuses)
        // 2. Only morning session (morning in AND morning out, no afternoon)
        // 3. Only afternoon session (afternoon in AND afternoon out, no morning)
        
        $hasMorningComplete = !empty($am_in) && !empty($am_out);
        $hasAfternoonComplete = !empty($pm_in) && !empty($pm_out);
        $hasMorningPartial = !empty($am_in) || !empty($am_out);
        $hasAfternoonPartial = !empty($pm_in) || !empty($pm_out);
        
        // Condition 1: Total hours <= 4 hours (overrides all other statuses)
        if ($total_hours > 0 && $total_hours <= 4.0) {
            return 'halfday';
        }
        
        // Condition 2: Only morning session (complete morning, no afternoon)
        if ($hasMorningComplete && !$hasAfternoonPartial) {
            return 'halfday';
        }
        
        // Condition 3: Only afternoon session (complete afternoon, no morning)
        if ($hasAfternoonComplete && !$hasMorningPartial) {
            return 'halfday';
        }
        
        // PRIORITY 2: Check for early arrival (12am to before shift start)
        if ($scheduled_start) {
            $work_start = strtotime($scheduled_start);
            $time_in_ts = strtotime($time_in);
            if ($time_in_ts < $work_start) {
                return 'early_in'; // Early arrival (12am to before shift start)
            }
        }
        
        // PRIORITY 3: Check for late arrival (beyond shift start - no grace period for status)
        if ($scheduled_start) {
            $work_start = strtotime($scheduled_start);
            $time_in_ts = strtotime($time_in);
            if ($time_in_ts > $work_start) {
                return 'late';
            }
        }
        
        // PRIORITY 4: If arrived exactly at scheduled time
        if ($scheduled_start) {
            $work_start = strtotime($scheduled_start);
            $time_in_ts = strtotime($time_in);
            if ($time_in_ts == $work_start) {
                return 'on_time';
            }
        }
        
        return 'present';
    }
    
    /**
     * Calculate total hours worked
     * 
     * @param string|null $time_in Time in
     * @param string|null $time_out Time out
     * @return float Total hours worked
     */
    public static function calculateTotalHours($time_in, $time_out) {
        if (!$time_in || !$time_out) {
            return 0.00;
        }
        
        $time_in_ts = strtotime($time_in);
        $time_out_ts = strtotime($time_out);
        
        $total_seconds = $time_out_ts - $time_in_ts;
        $total_hours = $total_seconds / 3600;
        
        return round($total_hours, 2);
    }

    // Removed overall in/out lunch-deduction helper to enforce segment-only total hours

    /**
     * Calculate early-in minutes (arrived before schedule start)
     */
    public static function calculateEarlyInMinutes($time_in, $scheduled_start = null) {
        if (!$time_in) { return 0; }
        
        // If no scheduled start provided, return 0 (don't assume default time)
        if (!$scheduled_start) {
            return 0;
        }
        
        $work_start = strtotime($scheduled_start);
        $time_in_ts = strtotime($time_in);
        if ($time_in_ts >= $work_start) { return 0; }
        return (int)(($work_start - $time_in_ts) / 60);
    }

    /**
     * Check if attendance record represents a half day
     * 
     * @param string|null $am_in Morning time in
     * @param string|null $am_out Morning time out
     * @param string|null $pm_in Afternoon time in
     * @param string|null $pm_out Afternoon time out
     * @return bool True if half day
     */
    public static function isHalfDay($am_in, $am_out, $pm_in, $pm_out) {
        $hasMorningComplete = !empty($am_in) && !empty($am_out);
        $hasAfternoonComplete = !empty($pm_in) && !empty($pm_out);
        $hasMorningPartial = !empty($am_in) || !empty($am_out);
        $hasAfternoonPartial = !empty($pm_in) || !empty($pm_out);
        
        // Half day if has complete morning session but no afternoon session, or vice versa
        return ($hasMorningComplete && !$hasAfternoonPartial) || ($hasAfternoonComplete && !$hasMorningPartial);
    }

    /**
     * Determine half day strictly by fingerprint scan pairs.
     * Half day when exactly one session (AM or PM) has BOTH in and out,
     * and the other session has no scans. Applies to all shifts.
     */
    public static function isHalfDayByScans($record) {
        $amIn = $record['time_in_morning'] ?? null;
        $amOut = $record['time_out_morning'] ?? null;
        $pmIn = $record['time_in_afternoon'] ?? null;
        $pmOut = $record['time_out_afternoon'] ?? null;

        $hasAmPair = !empty($amIn) && !empty($amOut);
        $hasPmPair = !empty($pmIn) && !empty($pmOut);
        $hasAnyAm = !empty($amIn) || !empty($amOut);
        $hasAnyPm = !empty($pmIn) || !empty($pmOut);

        // Exactly one complete pair and zero scans in the other session
        if ($hasAmPair && !$hasAnyPm) { return true; }
        if ($hasPmPair && !$hasAnyAm) { return true; }

        return false;
    }

    /**
     * Infer half day when only overall in/out exist and both fall entirely inside AM or PM window
     */
    private static function isHalfDayByOverall($attendance_date, $time_in, $time_out, $sched_start, $sched_end, $lunch_start, $lunch_end) {
        if (!$time_in || !$time_out) { return false; }
        // Require known lunch window; if not provided, assume 12-13
        $ls = $lunch_start ?: '12:00:00';
        $le = $lunch_end ?: '13:00:00';
        $ss = $sched_start ?: self::WORK_START_TIME;
        $se = $sched_end ?: self::WORK_END_TIME;

        $toTs = function($t) use ($attendance_date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} /', $t)) { return strtotime($t); }
            $tNorm = (strlen($t) === 5) ? ($t . ':00') : $t;
            return $attendance_date ? strtotime($attendance_date . ' ' . $tNorm) : strtotime($tNorm);
        };
        $inTs = $toTs($time_in);
        $outTs = $toTs($time_out);
        if (!$inTs || !$outTs || $outTs <= $inTs) { return false; }

        $amStart = $toTs($ss);
        $amEnd = $toTs($ls);
        $pmStart = $toTs($le);
        $pmEnd = $toTs($se);

        $fullyInMorning = ($inTs >= $amStart && $outTs <= $amEnd);
        $fullyInAfternoon = ($inTs >= $pmStart && $outTs <= $pmEnd);
        return $fullyInMorning || $fullyInAfternoon;
    }

    /**
     * Compute hours within a given window
     */
    private static function computeHoursWithinWindow($attendance_date, $time_in, $time_out, $win_start, $win_end) {
        if (!$time_in || !$time_out || !$win_start || !$win_end) { return 0; }
        $toTs = function($t) use ($attendance_date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} /', $t)) { return strtotime($t); }
            $tNorm = (strlen($t) === 5) ? ($t . ':00') : $t;
            return $attendance_date ? strtotime($attendance_date . ' ' . $tNorm) : strtotime($tNorm);
        };
        $inTs = $toTs($time_in);
        $outTs = $toTs($time_out);
        $ws = $toTs($win_start);
        $we = $toTs($win_end);
        if (!$inTs || !$outTs || !$ws || !$we) { return 0; }
        $start = max($inTs, $ws);
        $end = min($outTs, $we);
        if ($end <= $start) { return 0; }
        return ($end - $start) / 3600;
    }

    /**
     * Validate overtime calculation for accuracy
     * This function performs additional checks to ensure overtime is calculated correctly
     * 
     * @param array $record Attendance record
     * @param string|null $scheduled_end Scheduled end time
     * @return array Validation results with corrected overtime if needed
     */
    public static function validateOvertimeCalculation($record, $scheduled_end = null) {
        $am_in = $record['time_in_morning'] ?? null;
        $am_out = $record['time_out_morning'] ?? null;
        $pm_in = $record['time_in_afternoon'] ?? null;
        $pm_out = $record['time_out_afternoon'] ?? null;
        $time_in = $record['time_in'] ?? null;
        $time_out = $record['time_out'] ?? null;
        $attendance_date = $record['attendance_date'] ?? null;
        
        // Recalculate overtime using enhanced method
        $corrected_overtime = self::calculateEnhancedOvertimeHours(
            $time_in,
            $time_out,
            $am_in,
            $am_out,
            $pm_in,
            $pm_out,
            $scheduled_end,
            $attendance_date
        );
        
        // Check if overtime calculation needs correction
        $current_overtime = (float)($record['overtime_hours'] ?? 0);
        $needs_correction = abs($current_overtime - $corrected_overtime) > 0.01; // Allow 0.01 hour tolerance
        
        return [
            'needs_correction' => $needs_correction,
            'current_overtime' => $current_overtime,
            'corrected_overtime' => $corrected_overtime,
            'is_overtime' => $corrected_overtime > 0 ? 1 : 0
        ];
    }

    /**
     * Get detailed overtime breakdown for reporting
     * 
     * @param array $record Attendance record
     * @param string|null $scheduled_end Scheduled end time
     * @return array Detailed overtime information
     */
    public static function getOvertimeBreakdown($record, $scheduled_end = null) {
        $am_in = $record['time_in_morning'] ?? null;
        $am_out = $record['time_out_morning'] ?? null;
        $pm_in = $record['time_in_afternoon'] ?? null;
        $pm_out = $record['time_out_afternoon'] ?? null;
        $attendance_date = $record['attendance_date'] ?? null;
        
        $dateAnchor = $attendance_date ?: date('Y-m-d');
        $endStr = $scheduled_end ?: self::WORK_END_TIME;
        $work_end = strtotime($dateAnchor . ' ' . $endStr);
        $overtime_threshold = $work_end + (self::OVERTIME_GRACE_MINUTES_AFTER_END * 60);
        
        $breakdown = [
            'total_overtime' => 0.00,
            'morning_overtime' => 0.00,
            'afternoon_overtime' => 0.00,
            'has_morning_ot' => false,
            'has_afternoon_ot' => false,
            'work_end_time' => date('H:i:s', $work_end),
            'overtime_threshold' => date('H:i:s', $overtime_threshold)
        ];
        
        // Calculate morning overtime
        if ($am_in && $am_out) {
            $am_out_ts = $attendance_date ? strtotime($attendance_date . ' ' . $am_out) : strtotime($am_out);
            if ($am_out_ts > $overtime_threshold) {
                $breakdown['morning_overtime'] = round(($am_out_ts - $overtime_threshold) / 3600, 2);
                $breakdown['has_morning_ot'] = true;
            }
        }
        
        // Calculate afternoon overtime
        if ($pm_in && $pm_out) {
            $pm_out_ts = $attendance_date ? strtotime($attendance_date . ' ' . $pm_out) : strtotime($pm_out);
            if ($pm_out_ts > $overtime_threshold) {
                $breakdown['afternoon_overtime'] = round(($pm_out_ts - $overtime_threshold) / 3600, 2);
                $breakdown['has_afternoon_ot'] = true;
            }
        }
        
        $breakdown['total_overtime'] = $breakdown['morning_overtime'] + $breakdown['afternoon_overtime'];
        
        return $breakdown;
    }

    /**
     * Map shift string to schedule
     */
    private static function getShiftSchedule($shift) {
        $shift = trim($shift);
        
        // Handle specific shift patterns
        switch ($shift) {
            case '08:00-17:00':
            case '08:00-17:00pb':
            case '8-5pb':
                return ['08:00:00', '17:00:00'];
            case '08:30-17:30':
            case '8:30-5:30pm':
            case '8:30-17:30':
                return ['08:30:00', '17:30:00'];
            case '09:00-18:00':
            case '9am-6pm':
                return ['09:00:00', '18:00:00'];
            case '22:00-06:00':
            case 'NSD':
            case 'nsd':
            case 'Night':
            case 'night':
                return ['22:00:00', '06:00:00']; // 10PM to 6AM (next day)
        }
        
        // Handle generic patterns like "8:30-17:30", "8-5", "9-6"
        if (preg_match('/(\d{1,2}:\d{2})-(\d{1,2}:\d{2})/', $shift, $matches)) {
            $startTime = $matches[1];
            $endTime = $matches[2];
            
            // Ensure proper format
            $startTime = strlen($startTime) === 4 ? '0' . $startTime : $startTime;
            $endTime = strlen($endTime) === 4 ? '0' . $endTime : $endTime;
            
            return [$startTime . ':00', $endTime . ':00'];
        }
        
        // Handle patterns like "8-5", "9-6"
        if (preg_match('/(\d{1,2})-(\d{1,2})/', $shift, $matches)) {
            $startHour = intval($matches[1]);
            $endHour = intval($matches[2]);
            
            // Convert to 24-hour format if needed
            if ($endHour < 12 && $endHour < 8) {
                $endHour += 12;
            }
            
            return [
                sprintf('%02d:00:00', $startHour),
                sprintf('%02d:00:00', $endHour)
            ];
        }
        
        // Default fallback
        return [self::WORK_START_TIME, self::WORK_END_TIME];
    }

    /**
     * Lunch window based on shift
     */
    private static function getLunchWindow($shift) {
        switch ($shift) {
            case '08:00-17:00':
            case '08:00-17:00pb':
            case '8-5pb':
                return ['12:00:00', '13:00:00'];
            case '08:30-17:30':
            case '8:30-5:30pm':
                return ['12:30:00', '13:30:00'];
            case '09:00-18:00':
            case '9am-6pm':
                return ['13:00:00', '14:00:00'];
            case '22:00-06:00':
            case 'NSD':
            case 'nsd':
            case 'Night':
            case 'night':
                return ['02:00:00', '03:00:00']; // 2AM to 3AM (next day)
            default:
                return [null, null];
        }
    }

    /**
     * Get lunch break split times for proper time classification
     * First half = timeout morning (going to lunch)
     * Second half = timein afternoon (returning from lunch)
     */
    private static function getLunchBreakSplit($shift) {
        switch ($shift) {
            case '08:00-17:00':
            case '08:00-17:00pb':
            case '8-5pb':
                return [
                    'timeout_morning_start' => '12:00:00',
                    'timeout_morning_end' => '12:30:00',
                    'timein_afternoon_start' => '12:30:00',
                    'timein_afternoon_end' => '13:00:00'
                ];
            case '08:30-17:30':
            case '8:30-5:30pm':
                return [
                    'timeout_morning_start' => '12:30:00',
                    'timeout_morning_end' => '13:00:00',
                    'timein_afternoon_start' => '13:00:00',
                    'timein_afternoon_end' => '13:30:00'
                ];
            case '09:00-18:00':
            case '9am-6pm':
                return [
                    'timeout_morning_start' => '13:00:00',
                    'timeout_morning_end' => '13:30:00',
                    'timein_afternoon_start' => '13:30:00',
                    'timein_afternoon_end' => '14:00:00'
                ];
            case '22:00-06:00':
            case 'NSD':
            case 'nsd':
            case 'Night':
            case 'night':
                return [
                    'timeout_morning_start' => '02:00:00',
                    'timeout_morning_end' => '02:30:00',
                    'timein_afternoon_start' => '02:30:00',
                    'timein_afternoon_end' => '03:00:00'
                ];
            default:
                return [
                    'timeout_morning_start' => '12:00:00',
                    'timeout_morning_end' => '12:30:00',
                    'timein_afternoon_start' => '12:30:00',
                    'timein_afternoon_end' => '13:00:00'
                ];
        }
    }

    /**
     * Get the next sequential column for time entry based on existing attendance record
     * Sequential logic: 1st=time_in_morning, 2nd=time_out_morning, 3rd=time_in_afternoon, 4th=time_out_afternoon
     * 
     * @param mysqli $conn Database connection
     * @param int $employeeId Employee ID
     * @param string $attendanceDate Attendance date (Y-m-d format)
     * @return string The target field name (time_in_morning, time_out_morning, time_in_afternoon, time_out_afternoon)
     */
    public static function getNextSequentialColumn($conn, $employeeId, $attendanceDate) {
        $query = "SELECT time_in_morning, time_out_morning, time_in_afternoon, time_out_afternoon 
                  FROM attendance 
                  WHERE EmployeeID = ? AND attendance_date = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $employeeId, $attendanceDate);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $hasTimeInMorning = !empty($row['time_in_morning']);
            $hasTimeOutMorning = !empty($row['time_out_morning']);
            $hasTimeInAfternoon = !empty($row['time_in_afternoon']);
            $hasTimeOutAfternoon = !empty($row['time_out_afternoon']);
            
            // Sequential logic: 1st=time_in_morning, 2nd=time_out_morning, 3rd=time_in_afternoon, 4th=time_out_afternoon
            if (!$hasTimeInMorning) {
                return 'time_in_morning';
            } elseif (!$hasTimeOutMorning) {
                return 'time_out_morning';
            } elseif (!$hasTimeInAfternoon) {
                return 'time_in_afternoon';
            } elseif (!$hasTimeOutAfternoon) {
                return 'time_out_afternoon';
            } else {
                return 'time_out_afternoon'; // All slots filled, default to last one
            }
        }
        
        // If no record found, start with first scan
        return 'time_in_morning';
    }

    /**
     * Classify a time entry based on sequential scan order (legacy method for backward compatibility)
     * This now uses sequential logic instead of time-based classification
     * 
     * @param string $time Time in HH:MM:SS format
     * @param string $shift Employee shift
     * @param bool $isIn Whether this is a time-in (true) or time-out (false)
     * @return string The target field name (time_in_morning, time_out_morning, time_in_afternoon, time_out_afternoon)
     */
    public static function classifyTimeEntry($time, $shift, $isIn) {
        // This method is now deprecated in favor of getNextSequentialColumn
        // But keeping it for backward compatibility
        if (!$time) {
            return '';
        }

        // For backward compatibility, we'll use a simple time-based approach
        // but in practice, the C# biometric handler should use getNextSequentialColumn
        $timeTs = strtotime($time);
        $morningCutoff = strtotime('12:00:00');
        
        if ($isIn) {
            return $timeTs < $morningCutoff ? 'time_in_morning' : 'time_in_afternoon';
        } else {
            return $timeTs < $morningCutoff ? 'time_out_morning' : 'time_out_afternoon';
        }
    }
    
    /**
     * Update attendance record in database with accurate calculations
     * 
     * @param mysqli $conn Database connection
     * @param int $attendance_id Attendance record ID
     * @param array $record Updated record with calculations
     * @return bool Success status
     */
    public static function updateAttendanceRecord($conn, $attendance_id, $record) {
        $query = "UPDATE attendance SET 
                  overtime_hours = ?, 
                  early_out_minutes = ?, 
                  late_minutes = ?, 
                  is_overtime = ?, 
                  status = ?,
                  total_hours = ?,
                  nsd_ot_hours = ?,
                  is_on_nsdot = ?
                  WHERE id = ?";
        
        $stmt = $conn->prepare($query);
        // Provide safe defaults when keys are missing
        $otHours = isset($record['overtime_hours']) ? (float)$record['overtime_hours'] : 0.00;
        $earlyOut = isset($record['early_out_minutes']) ? (int)$record['early_out_minutes'] : 0;
        $lateMins = isset($record['late_minutes']) ? (int)$record['late_minutes'] : 0;
        $isOt = isset($record['is_overtime']) ? (int)$record['is_overtime'] : 0;
        // Map status to DB-supported enum values only (late/early), otherwise NULL
        $status = isset($record['status']) ? self::mapStatusForDB($record['status']) : null;
        $totalHours = isset($record['total_hours']) ? (float)$record['total_hours'] : 0.00;
        $nsdHours = isset($record['nsd_ot_hours']) ? (float)$record['nsd_ot_hours'] : 0.00;
        $isNsdOt = isset($record['is_on_nsdot']) ? (int)$record['is_on_nsdot'] : 0;
        $stmt->bind_param("diiisddii", 
            $otHours,
            $earlyOut,
            $lateMins,
            $isOt,
            $status,
            $totalHours,
            $nsdHours,
            $isNsdOt,
            $attendance_id
        );
        
        return $stmt->execute();
    }

    /**
     * Map calculated status to database-supported enum: late/early/halfday/early_in/on_time or NULL
     */
    private static function mapStatusForDB($status) {
        if (in_array($status, ['late', 'early', 'halfday', 'early_in', 'on_time'])) {
            return $status;
        }
        return null; // DB enum doesn't support other values
    }
    
    /**
     * Recalculate all attendance records for a specific date range
     * 
     * @param mysqli $conn Database connection
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @return int Number of records updated
     */
    public static function recalculateDateRange($conn, $start_date, $end_date) {
        $query = "SELECT a.*, e.Shift FROM attendance a 
                  JOIN empuser e ON a.EmployeeID = e.EmployeeID
                  WHERE a.attendance_date BETWEEN ? AND ? 
                  AND a.time_in IS NOT NULL";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $updated_count = 0;
        while ($record = $result->fetch_assoc()) {
            $updated_record = self::calculateAttendanceMetrics([$record])[0];
            
            if (self::updateAttendanceRecord($conn, $record['id'], $updated_record)) {
                $updated_count++;
            }
        }
        
        return $updated_count;
    }

    /**
     * Recalculate overtime for all records with enhanced accuracy
     * 
     * @param mysqli $conn Database connection
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @return array Summary of recalculation results
     */
    public static function recalculateOvertimeAccurately($conn, $start_date, $end_date) {
        $query = "SELECT a.*, e.Shift FROM attendance a 
                  JOIN empuser e ON a.EmployeeID = e.EmployeeID
                  WHERE a.attendance_date BETWEEN ? AND ? 
                  AND a.attendance_type = 'present'";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $summary = [
            'total_records' => 0,
            'updated_records' => 0,
            'overtime_corrected' => 0,
            'overtime_added' => 0,
            'overtime_removed' => 0
        ];
        
        while ($record = $result->fetch_assoc()) {
            $summary['total_records']++;
            
            // Get shift schedule
            [$schedStart, $schedEnd] = self::getShiftSchedule($record['Shift'] ?? '');
            
            // Recompute full metrics (overtime, late/early, total_hours)
            $recomputed = self::calculateAttendanceMetrics([$record])[0];

            // Validate current overtime calculation
            $validation = self::validateOvertimeCalculation($recomputed, $schedEnd);
            
            if ($validation['needs_correction']) {
                $old_overtime = $validation['current_overtime'];
                $new_overtime = $validation['corrected_overtime'];
                
                // Update the record with corrected overtime and total_hours
                $update_query = "UPDATE attendance SET 
                                overtime_hours = ?, 
                                is_overtime = ?,
                                total_hours = ?
                                WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $newIsOt = $validation['is_overtime'];
                $newTh = isset($recomputed['total_hours']) ? $recomputed['total_hours'] : 0.00;
                $update_stmt->bind_param("didi", 
                    $new_overtime,
                    $newIsOt,
                    $newTh,
                    $record['id']
                );
                
                if ($update_stmt->execute()) {
                    $summary['updated_records']++;
                    $summary['overtime_corrected']++;
                    
                    if ($old_overtime == 0 && $new_overtime > 0) {
                        $summary['overtime_added']++;
                    } elseif ($old_overtime > 0 && $new_overtime == 0) {
                        $summary['overtime_removed']++;
                    }
                }
                $update_stmt->close();
            }
            else {
                // Even if OT unchanged, make sure total_hours is current
                $update_totals = $conn->prepare("UPDATE attendance SET total_hours = ? WHERE id = ?");
                $th = $recomputed['total_hours'] ?? 0.00;
                $update_totals->bind_param("di", $th, $record['id']);
                if ($update_totals->execute()) {
                    $summary['updated_records']++;
                }
                $update_totals->close();
            }
        }
        
        return $summary;
    }

    /**
     * Force recalculation of overtime for all employees on a specific date
     * This fixes the issue where overtime calculations were incorrect due to punch overwrites
     * 
     * @param mysqli $conn Database connection
     * @param string $date Date in Y-m-d format
     * @return array Summary of recalculation results
     */
    public static function forceRecalculateOvertimeForDate($conn, $date) {
        $summary = [
            'total_records' => 0,
            'updated_records' => 0,
            'overtime_corrected' => 0,
            'errors' => []
        ];
        
        try {
            // Get all attendance records for the date
            $query = "SELECT a.*, e.Shift FROM attendance a 
                      JOIN empuser e ON a.EmployeeID = e.EmployeeID
                      WHERE a.attendance_date = ? 
                      AND a.time_in IS NOT NULL";
            
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                $summary['errors'][] = "Failed to prepare query";
                return $summary;
            }
            
            $stmt->bind_param("s", $date);
            $stmt->execute();
            $result = $stmt->get_result();
            $records = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            $summary['total_records'] = count($records);
            
            foreach ($records as $record) {
                try {
                    // Recalculate attendance metrics
                    $updatedRecords = self::calculateAttendanceMetrics([$record]);
                    $updatedRecord = $updatedRecords[0];
                    
                    // Update database with corrected values
                    if (self::updateAttendanceRecord($conn, $record['id'], $updatedRecord)) {
                        $summary['updated_records']++;
                        
                        // Check if overtime was corrected
                        $oldOvertime = floatval($record['overtime_hours'] ?? 0);
                        $newOvertime = floatval($updatedRecord['overtime_hours'] ?? 0);
                        
                        if (abs($oldOvertime - $newOvertime) > 0.01) {
                            $summary['overtime_corrected']++;
                            echo "Employee {$record['EmployeeID']}: OT corrected from {$oldOvertime}h to {$newOvertime}h\n";
                        }
                    } else {
                        $summary['errors'][] = "Failed to update record ID: " . $record['id'];
                    }
                } catch (Exception $e) {
                    $summary['errors'][] = "Error processing record ID {$record['id']}: " . $e->getMessage();
                }
            }
            
        } catch (Exception $e) {
            $summary['errors'][] = "Database error: " . $e->getMessage();
        }
        
        return $summary;
    }
}
?>
