// Updated Program.cs with NSD OT tracking support
// This file contains the key changes needed for NSD OT tracking

// Add this method to the Program class in Program.cs

/// <summary>
/// Calculate and update NSD OT hours and flag for attendance record
/// </summary>
/// <param name="conn">Database connection</param>
/// <param name="employeeId">Employee ID</param>
/// <param name="dateString">Attendance date</param>
/// <param name="punchTime">Current punch time</param>
/// <param name="targetColumn">Target column being updated</param>
static void CalculateAndUpdateNSDOT(MySqlConnection conn, int employeeId, string dateString, TimeSpan punchTime, string targetColumn)
{
    try
    {
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Calculating NSD OT for Employee {employeeId} - Punch: {punchTime:hh\\:mm}, Column: {targetColumn}");
        
        // Get employee's shift configuration
        var shift = GetShiftConfig(conn, employeeId);
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} shift: {shift.Start:hh\\:mm} - {shift.End:hh\\:mm}");
        
        // NSD cutoff time is 22:00 (10:00 PM)
        TimeSpan nsdCutoffTime = new TimeSpan(22, 0, 0);
        
        // Get current attendance record
        using (var getRecordCmd = new MySqlCommand(
            "SELECT time_in_morning, time_out_morning, time_in_afternoon, time_out_afternoon, " +
            "time_in, time_out, nsd_ot_hours, is_on_nsdot " +
            "FROM attendance WHERE EmployeeID = @e AND attendance_date = @d", conn))
        {
            getRecordCmd.Parameters.AddWithValue("@e", employeeId);
            getRecordCmd.Parameters.AddWithValue("@d", dateString);
            
            using (var reader = getRecordCmd.ExecuteReader())
            {
                if (reader.Read())
                {
                    // Get existing punch times
                    TimeSpan? timeInMorning = reader.IsDBNull(0) ? null : (TimeSpan?)reader.GetTimeSpan(0);
                    TimeSpan? timeOutMorning = reader.IsDBNull(1) ? null : (TimeSpan?)reader.GetTimeSpan(1);
                    TimeSpan? timeInAfternoon = reader.IsDBNull(2) ? null : (TimeSpan?)reader.GetTimeSpan(2);
                    TimeSpan? timeOutAfternoon = reader.IsDBNull(3) ? null : (TimeSpan?)reader.GetTimeSpan(3);
                    TimeSpan? overallTimeIn = reader.IsDBNull(4) ? null : (TimeSpan?)reader.GetTimeSpan(4);
                    TimeSpan? overallTimeOut = reader.IsDBNull(5) ? null : (TimeSpan?)reader.GetTimeSpan(5);
                    
                    // Update the current punch time based on target column
                    switch (targetColumn)
                    {
                        case "time_in_morning":
                            timeInMorning = punchTime;
                            break;
                        case "time_out_morning":
                            timeOutMorning = punchTime;
                            break;
                        case "time_in_afternoon":
                            timeInAfternoon = punchTime;
                            break;
                        case "time_out_afternoon":
                            timeOutAfternoon = punchTime;
                            break;
                    }
                    
                    reader.Close();
                    
                    // Calculate NSD OT hours
                    double nsdHours = 0.0;
                    bool hasNSD = false;
                    
                    // Check morning session for NSD
                    if (timeInMorning.HasValue && timeOutMorning.HasValue)
                    {
                        if (timeOutMorning.Value > nsdCutoffTime)
                        {
                            TimeSpan nsdDuration = timeOutMorning.Value - nsdCutoffTime;
                            nsdHours += nsdDuration.TotalHours;
                            hasNSD = true;
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Morning NSD: {nsdDuration.TotalHours:F2} hours");
                        }
                    }
                    
                    // Check afternoon session for NSD
                    if (timeInAfternoon.HasValue && timeOutAfternoon.HasValue)
                    {
                        if (timeOutAfternoon.Value > nsdCutoffTime)
                        {
                            TimeSpan nsdDuration = timeOutAfternoon.Value - nsdCutoffTime;
                            nsdHours += nsdDuration.TotalHours;
                            hasNSD = true;
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Afternoon NSD: {nsdDuration.TotalHours:F2} hours");
                        }
                    }
                    
                    // Check overall session for NSD (fallback)
                    if (!timeInMorning.HasValue && !timeOutMorning.HasValue && 
                        !timeInAfternoon.HasValue && !timeOutAfternoon.HasValue &&
                        overallTimeIn.HasValue && overallTimeOut.HasValue)
                    {
                        if (overallTimeOut.Value > nsdCutoffTime)
                        {
                            TimeSpan nsdDuration = overallTimeOut.Value - nsdCutoffTime;
                            nsdHours = nsdDuration.TotalHours;
                            hasNSD = true;
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Overall NSD: {nsdDuration.TotalHours:F2} hours");
                        }
                    }
                    
                    // Update NSD columns in database
                    using (var updateNsdCmd = new MySqlCommand(
                        "UPDATE attendance SET nsd_ot_hours = @nsdHours, is_on_nsdot = @hasNSD " +
                        "WHERE EmployeeID = @e AND attendance_date = @d", conn))
                    {
                        updateNsdCmd.Parameters.AddWithValue("@nsdHours", Math.Round(nsdHours, 2));
                        updateNsdCmd.Parameters.AddWithValue("@hasNSD", hasNSD ? 1 : 0);
                        updateNsdCmd.Parameters.AddWithValue("@e", employeeId);
                        updateNsdCmd.Parameters.AddWithValue("@d", dateString);
                        
                        int nsdRowsUpdated = updateNsdCmd.ExecuteNonQuery();
                        if (nsdRowsUpdated > 0)
                        {
                            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] SUCCESS: Updated NSD OT - Hours: {nsdHours:F2}, Flag: {hasNSD}");
                        }
                    }
                }
            }
        }
    }
    catch (Exception ex)
    {
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Error calculating NSD OT for Employee {employeeId}: {ex.Message}");
    }
}

/// <summary>
/// Enhanced punch determination with NSD OT tracking
/// </summary>
static string DeterminePunchColumnWithNSD(MySqlConnection conn, int employeeId, DateTime punchTime)
{
    var shift = GetShiftConfig(conn, employeeId);
    var ts = punchTime.TimeOfDay;
    string dateString = punchTime.ToString("yyyy-MM-dd");

    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Determining punch column with NSD tracking - Shift: {shift.Start:hh\\:mm}-{shift.End:hh\\:mm}, Punch: {ts:hh\\:mm}");

    // Check what's already in the database
    var existing = GetExistingPunches(conn, employeeId, dateString);

    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Existing punches - AM In: {existing.time_in_morning ?? "None"}, AM Out: {existing.time_out_morning ?? "None"}, PM In: {existing.time_in_afternoon ?? "None"}, PM Out: {existing.time_out_afternoon ?? "None"}");

    // PRIORITY 1: Time-based logic for first punch of the day
    if (string.IsNullOrEmpty(existing.time_in_morning) && string.IsNullOrEmpty(existing.time_out_morning) && 
        string.IsNullOrEmpty(existing.time_in_afternoon) && string.IsNullOrEmpty(existing.time_out_afternoon))
    {
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] No existing punches - assigning to time_in_morning");
        return "time_in_morning";
    }

    // PRIORITY 2: Check for exact time matches to avoid duplicates
    if (!string.IsNullOrEmpty(existing.time_in_morning))
    {
        if (TimeSpan.TryParse(existing.time_in_morning, out TimeSpan existingTime) && 
            Math.Abs((ts - existingTime).TotalMinutes) < 2)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Duplicate time detected for time_in_morning - skipping");
            return null;
        }
    }
    if (!string.IsNullOrEmpty(existing.time_out_morning))
    {
        if (TimeSpan.TryParse(existing.time_out_morning, out TimeSpan existingTime) && 
            Math.Abs((ts - existingTime).TotalMinutes) < 2)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Duplicate time detected for time_out_morning - skipping");
            return null;
        }
    }
    if (!string.IsNullOrEmpty(existing.time_in_afternoon))
    {
        if (TimeSpan.TryParse(existing.time_in_afternoon, out TimeSpan existingTime) && 
            Math.Abs((ts - existingTime).TotalMinutes) < 2)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Duplicate time detected for time_in_afternoon - skipping");
            return null;
        }
    }
    if (!string.IsNullOrEmpty(existing.time_out_afternoon))
    {
        if (TimeSpan.TryParse(existing.time_out_afternoon, out TimeSpan existingTime) && 
            Math.Abs((ts - existingTime).TotalMinutes) < 2)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Duplicate time detected for time_out_afternoon - skipping");
            return null;
        }
    }

    // PRIORITY 3: Sequential logic based on what's missing (database-driven)
    if (string.IsNullOrEmpty(existing.time_in_morning))
    {
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Missing time_in_morning - assigning");
        return "time_in_morning";
    }
    if (string.IsNullOrEmpty(existing.time_out_morning))
    {
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Missing time_out_morning - assigning");
        return "time_out_morning";
    }
    if (string.IsNullOrEmpty(existing.time_in_afternoon))
    {
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Missing time_in_afternoon - assigning");
        return "time_in_afternoon";
    }
    if (string.IsNullOrEmpty(existing.time_out_afternoon))
    {
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Missing time_out_afternoon - assigning");
        return "time_out_afternoon";
    }

    // All columns filled - this is an extra punch (overtime)
    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] All punch columns filled - treating as overtime for Employee {employeeId}");
    return "time_out_afternoon";
}

/// <summary>
/// Enhanced ApplyPunchToColumn with NSD OT calculation
/// </summary>
static void ApplyPunchToColumnWithNSD(MySqlConnection conn, int employeeId, DateTime punchTime, string targetColumn)
{
    string dateString = punchTime.ToString("yyyy-MM-dd");
    string timeString = punchTime.ToString("HH:mm:ss");

    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Applying punch with NSD tracking - Employee {employeeId}, Date: {dateString}, Time: {timeString}, Column: {targetColumn}");

    // Ensure attendance record exists
    using (var ensureCmd = new MySqlCommand(
        "INSERT INTO attendance (EmployeeID, attendance_date, attendance_type) " +
        "SELECT @e, @d, 'present' FROM DUAL " +
        "WHERE NOT EXISTS (SELECT 1 FROM attendance WHERE EmployeeID=@e AND attendance_date=@d)",
        conn))
    {
        ensureCmd.Parameters.AddWithValue("@e", employeeId);
        ensureCmd.Parameters.AddWithValue("@d", dateString);
        int rowsInserted = ensureCmd.ExecuteNonQuery();
        if (rowsInserted > 0)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Created new attendance record for Employee {employeeId} on {dateString}");
        }
    }

    // Update the specific column using TimeSpan value
    using (var updateCmd = new MySqlCommand(
        $"UPDATE attendance SET {targetColumn} = @t WHERE EmployeeID = @e AND attendance_date = @d",
        conn))
    {
        updateCmd.Parameters.AddWithValue("@t", punchTime.TimeOfDay);
        updateCmd.Parameters.AddWithValue("@e", employeeId);
        updateCmd.Parameters.AddWithValue("@d", dateString);
        int rowsUpdated = updateCmd.ExecuteNonQuery();

        if (rowsUpdated > 0)
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] SUCCESS: Updated {targetColumn} for Employee {employeeId}: {timeString}");
            
            // Calculate and update NSD OT
            CalculateAndUpdateNSDOT(conn, employeeId, dateString, punchTime.TimeOfDay, targetColumn);
            
            // Calculate and set status (early/late) when morning time-in is recorded
            if (targetColumn == "time_in_morning")
            {
                CalculateAndSetStatus(conn, employeeId, dateString, punchTime.TimeOfDay);
            }
            
            // Update overall time_in/time_out fields when appropriate
            UpdateOverallTimeFields(conn, employeeId, dateString, targetColumn, punchTime.TimeOfDay);
            
            // Update currently at work status for main time_in/time_out
            UpdateCurrentlyAtWorkStatus(conn, employeeId, dateString, targetColumn, timeString);

            // Display updated status if it's today's attendance
            if (punchTime.Date == DateTime.Today)
            {
                DisplayCurrentlyAtWork();
            }
        }
        else
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] No rows updated for Employee {employeeId}");
        }
    }
}

// Update the ProcessAttendanceLog method to use the new NSD-aware functions
static void ProcessAttendanceLogWithNSD(MySqlConnection conn, string enrollNo, DateTime deviceLogTime)
{
    // Always use device-provided timestamp for recording
    DateTime punchTime = deviceLogTime;

    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Device scan time used: {punchTime:yyyy-MM-dd HH:mm:ss}");

    if (string.IsNullOrWhiteSpace(enrollNo))
    {
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Empty employee ID from device. Skipping log.");
        return;
    }

    string cleanedEnrollNo = System.Text.RegularExpressions.Regex.Replace(enrollNo, @"[^0-9]", "");

    if (string.IsNullOrWhiteSpace(cleanedEnrollNo) || !int.TryParse(cleanedEnrollNo, out int employeeId))
    {
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Invalid employee ID format: '{enrollNo}'. Skipping log.");
        return;
    }

    string targetColumn = DeterminePunchColumnWithNSD(conn, employeeId, punchTime);
    
    // If DeterminePunchColumnWithNSD returns null, it means this is a duplicate that should be skipped
    if (targetColumn == null)
    {
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Skipping duplicate punch for Employee {employeeId} at {punchTime:HH:mm:ss}");
        return;
    }
    
    string action = targetColumn.Contains("in") ? "TIME IN" : "TIME OUT";
    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Fingerprint detected for Employee {employeeId} (Action: {action}, Column: {targetColumn})");

    try
    {
        string employeeName = GetEmployeeName(conn, employeeId);
        if (string.IsNullOrEmpty(employeeName))
        {
            Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Employee {employeeId} not found in database. Skipping log.");
            return;
        }

        ApplyPunchToColumnWithNSD(conn, employeeId, punchTime, targetColumn);

        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] {action} recorded for Employee {employeeId} ({employeeName})");
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Time (device): {punchTime:HH:mm:ss}");

    }
    catch (Exception ex)
    {
        Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Database error for Employee {employeeId}: {ex.Message}");
    }
}
