# NSD OT Integration Summary

## Overview
This document outlines the changes made to integrate Night Shift Differential (NSD) overtime tracking into the attendance system.

## Database Changes

### New Columns Added to `attendance` Table:
1. **`nsd_ot_hours`** (DECIMAL(5,2)) - Total NSD overtime hours (10PM onwards)
2. **`is_on_nsdot`** (TINYINT(1)) - Flag indicating if employee worked NSD overtime (1=yes, 0=no)

### SQL Script:
```sql
-- Add NSD OT hours column
ALTER TABLE attendance 
ADD COLUMN nsd_ot_hours DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Total NSD overtime hours (10PM onwards)';

-- Add NSD OT flag column
ALTER TABLE attendance 
ADD COLUMN is_on_nsdot TINYINT(1) DEFAULT 0 COMMENT 'Flag indicating if employee worked NSD overtime (1=yes, 0=no)';

-- Add indexes for better performance
CREATE INDEX idx_attendance_nsdot ON attendance(is_on_nsdot);
CREATE INDEX idx_attendance_nsd_hours ON attendance(nsd_ot_hours);
```

## Attendance Calculation Logic Changes

### New Overtime Structure:
1. **Regular Overtime**: From shift end + 30min grace period to 10:00 PM
   - Rate: `hourly_rate × 1.25`
   - Example: Employee with 8:00-17:00 shift gets OT from 17:30 to 22:00

2. **NSD Overtime**: From 10:00 PM onwards
   - Rate: `hourly_rate × 1.25 × 0.10` (additional 10% premium)
   - Example: Employee working past 22:00 gets NSD OT

### Fingerprint Punch Logic:
- **Grace Period**: 30 minutes after shift end allows 2 punches
  1. First punch: Time out (end of regular shift)
  2. Second punch: Overtime start (beginning of OT period)

## Code Changes

### 1. attendance_calculations.php
- **New Method**: `calculateNSDOvertimeHours()` - Calculates NSD OT hours and flag
- **Updated Method**: `calculateAttendanceMetrics()` - Now includes NSD calculations
- **Updated Method**: `updateAttendanceRecord()` - Now updates NSD columns

### 2. Program.cs (ZKteco Integration)
- **New Method**: `CalculateAndUpdateNSDOT()` - Calculates and updates NSD OT in real-time
- **New Method**: `DeterminePunchColumnWithNSD()` - Enhanced punch determination with NSD tracking
- **New Method**: `ApplyPunchToColumnWithNSD()` - Applies punches with NSD calculation
- **New Method**: `ProcessAttendanceLogWithNSD()` - Processes attendance logs with NSD support

### 3. payroll_computations.php
- **Updated Method**: `calculateOTWithNSD()` - Now returns hours breakdown
- **Enhanced**: Returns both regular OT and NSD OT with hour counts

## Attendance State Changes

### Before NSD Integration:
```
attendance table:
- overtime_hours (total OT hours)
- is_overtime (OT flag)
- time_in_morning, time_out_morning
- time_in_afternoon, time_out_afternoon
- time_in, time_out
```

### After NSD Integration:
```
attendance table:
- overtime_hours (total OT hours)
- is_overtime (OT flag)
- nsd_ot_hours (NSD OT hours) ← NEW
- is_on_nsdot (NSD OT flag) ← NEW
- time_in_morning, time_out_morning
- time_in_afternoon, time_out_afternoon
- time_in, time_out
```

## Example Scenarios

### Scenario 1: Employee 2025005 (8:00-17:00 shift)
- **17:30**: First punch (time out) - End of regular shift
- **17:30**: Second punch (overtime start) - Beginning of OT
- **21:59**: Third punch (time out) - End of regular OT
- **Result**: 
  - `overtime_hours`: 4.5 hours (17:30-21:59)
  - `nsd_ot_hours`: 0.0 hours
  - `is_on_nsdot`: 0

### Scenario 2: Employee working past 10PM
- **17:30**: Overtime start
- **22:30**: Time out (past 10PM)
- **Result**:
  - `overtime_hours`: 5.0 hours (17:30-22:30)
  - `nsd_ot_hours`: 0.5 hours (22:00-22:30)
  - `is_on_nsdot`: 1

## Payroll Calculation Impact

### Regular OT Pay:
```
Regular OT Pay = hourly_rate × 1.25 × regular_ot_hours
```

### NSD OT Pay:
```
NSD OT Pay = hourly_rate × 1.25 × 0.10 × nsd_ot_hours
```

### Total OT Pay:
```
Total OT Pay = Regular OT Pay + NSD OT Pay
```

## Integration Points

1. **ZKteco Device**: Real-time fingerprint processing with NSD calculation
2. **Database**: New columns store NSD OT data
3. **Payroll System**: Uses NSD data for accurate pay calculations
4. **Attendance Reports**: Can now show NSD OT breakdown

## Testing Recommendations

1. Test with employees working past 10PM
2. Verify grace period punch handling (2 punches in 30min window)
3. Test different shift schedules (8-5, 8:30-5:30, 9-6, NSD)
4. Verify NSD calculations in payroll reports
5. Test edge cases (exactly 10PM punch, cross-midnight scenarios)

## Migration Notes

- Existing records will have `nsd_ot_hours = 0.00` and `is_on_nsdot = 0`
- Historical data can be recalculated using the new NSD calculation methods
- No breaking changes to existing functionality
- Backward compatible with current payroll calculations
