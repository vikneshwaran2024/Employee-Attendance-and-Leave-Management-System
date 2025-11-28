-- SQL Script to add contact_details column to leave_requests table
USE employee_management;

-- Add the contact_details column if it doesn't exist yet
ALTER TABLE leave_requests 
ADD COLUMN IF NOT EXISTS contact_details TEXT NULL
COMMENT 'Contact information provided by employee during leave';

-- If your MySQL version doesn't support "IF NOT EXISTS" for columns, use this instead:
-- First check if the column exists
-- SET @columnExists = 0;
-- SELECT COUNT(*) INTO @columnExists 
-- FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_SCHEMA = 'employee_management'
-- AND TABLE_NAME = 'leave_requests' 
-- AND COLUMN_NAME = 'contact_details';

-- IF @columnExists = 0 THEN
--     ALTER TABLE leave_requests ADD COLUMN contact_details TEXT NULL COMMENT 'Contact information provided by employee during leave';
-- END IF;