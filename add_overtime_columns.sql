-- Add overtime columns to attendance table
-- This script adds support for 5th+ fingerprint punches

-- Add overtime time columns
ALTER TABLE attendance 
ADD COLUMN overtime_time_in TIME NULL COMMENT '5th punch - overtime time in',
ADD COLUMN overtime_time_out TIME NULL COMMENT '6th punch - overtime time out';

-- Add indexes for better performance
CREATE INDEX idx_overtime_time_in ON attendance(overtime_time_in);
CREATE INDEX idx_overtime_time_out ON attendance(overtime_time_out);

-- Add composite index for overtime queries
CREATE INDEX idx_employee_date_overtime ON attendance(EmployeeID, attendance_date, overtime_time_in, overtime_time_out);

-- Update existing records to ensure compatibility
UPDATE attendance 
SET overtime_time_in = NULL, 
    overtime_time_out = NULL 
WHERE overtime_time_in IS NULL AND overtime_time_out IS NULL;

-- Show the updated table structure
DESCRIBE attendance;
