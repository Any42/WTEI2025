-- SQL script to add NSD OT tracking columns to attendance table
-- Run this script to add the new columns for NSD OT tracking

-- Add NSD OT hours column (total counts of NSD overtime hours)
ALTER TABLE attendance 
ADD COLUMN nsd_ot_hours DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Total NSD overtime hours (10PM onwards)';

-- Add NSD OT flag column (check if employee is on NSD OT)
ALTER TABLE attendance 
ADD COLUMN is_on_nsdot TINYINT(1) DEFAULT 0 COMMENT 'Flag indicating if employee worked NSD overtime (1=yes, 0=no)';

-- Add index for better query performance
CREATE INDEX idx_attendance_nsdot ON attendance(is_on_nsdot);
CREATE INDEX idx_attendance_nsd_hours ON attendance(nsd_ot_hours);

-- Update existing records to have default values
UPDATE attendance SET nsd_ot_hours = 0.00, is_on_nsdot = 0 WHERE nsd_ot_hours IS NULL OR is_on_nsdot IS NULL;

-- Verify the columns were added successfully
DESCRIBE attendance;
