-- Database schema for Employee Attendance and Leave Management System

-- Drop existing database (use with caution)
-- DROP DATABASE IF EXISTS employee_management;

-- Create database
CREATE DATABASE IF NOT EXISTS employee_management;
USE employee_management;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    department VARCHAR(50),
    position VARCHAR(50),
    role ENUM('employee', 'manager', 'admin') DEFAULT 'employee',
    join_date DATE,
    is_active BOOLEAN DEFAULT TRUE,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Attendance table
CREATE TABLE IF NOT EXISTS attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    check_in DATETIME NOT NULL,
    check_out DATETIME,
    work_hours DECIMAL(5,2),
    overtime_hours DECIMAL(5,2) DEFAULT 0,
    status ENUM('present', 'late', 'half-day', 'absent') DEFAULT 'present',
    ip_address VARCHAR(45),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Leave types table
CREATE TABLE IF NOT EXISTS leave_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    default_days INT DEFAULT 0,
    color_code VARCHAR(10) DEFAULT '#6c757d',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Leave balances table
CREATE TABLE IF NOT EXISTS leave_balances (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    year INT NOT NULL,
    allocated_days INT DEFAULT 0,
    used_days DECIMAL(5,1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE,
    UNIQUE KEY (user_id, leave_type_id, year)
);

-- Leave requests table
CREATE TABLE IF NOT EXISTS leave_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT NOT NULL,
    half_day BOOLEAN DEFAULT FALSE,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by INT,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Holidays table
CREATE TABLE IF NOT EXISTS holidays (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    date DATE NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (date, name)
);

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(30),
    related_id INT,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Activity logs table
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- System settings table
CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert initial data

-- Default admin user (password: admin123)
INSERT INTO users (employee_id, first_name, last_name, email, password, role)
VALUES ('ADMIN001', 'System', 'Administrator', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

-- Default leave types
INSERT INTO leave_types (name, description, default_days, color_code)
VALUES 
('Annual Leave', 'Regular paid time off for vacation or personal reasons', 20, '#28a745'),
('Sick Leave', 'Leave due to illness or medical appointments', 12, '#dc3545'),
('Casual Leave', 'Short-term leave for urgent personal matters', 7, '#fd7e14'),
('Maternity Leave', 'Leave granted to female employees for childbirth', 90, '#e83e8c'),
('Paternity Leave', 'Leave granted to male employees upon birth of their child', 7, '#6f42c1'),
('Unpaid Leave', 'Leave without pay for extended absences', 0, '#6c757d');

-- Default system settings
INSERT INTO settings (setting_key, setting_value, description)
VALUES 
('company_name', 'ACME Corporation', 'Name of the company'),
('working_days', '1,2,3,4,5', 'Working days (1=Monday, 7=Sunday)'),
('working_hours_start', '09:00:00', 'Start time of working hours'),
('working_hours_end', '17:00:00', 'End time of working hours'),
('late_threshold', '00:15:00', 'Time threshold to mark attendance as late'),
('fiscal_year_start', '01-01', 'Start date of fiscal year (MM-DD)'),
('leave_approval_required', '1', 'Whether leave requests require approval'),
('default_leave_days', '20', 'Default annual leave days for new employees'),
('overtime_multiplier', '1.5', 'Multiplier for overtime hours calculation'),
('system_timezone', 'UTC', 'System timezone');

-- Trigger to update leave balances when leave is approved
DELIMITER //
CREATE TRIGGER update_leave_balance AFTER UPDATE ON leave_requests
FOR EACH ROW
BEGIN
    IF NEW.status = 'approved' AND OLD.status != 'approved' THEN
        -- Calculate business days between start and end dates
        -- This is a simplified version; actual implementation would be more complex
        UPDATE leave_balances
        SET used_days = used_days + DATEDIFF(NEW.end_date, NEW.start_date) + 1 - (NEW.half_day * 0.5)
        WHERE user_id = NEW.user_id 
        AND leave_type_id = NEW.leave_type_id
        AND year = YEAR(NEW.start_date);
    END IF;
END //
DELIMITER ;

-- Create indexes for better performance
CREATE INDEX idx_attendance_user_date ON attendance(user_id, check_in);
CREATE INDEX idx_leave_requests_user ON leave_requests(user_id);
CREATE INDEX idx_leave_requests_status ON leave_requests(status);
CREATE INDEX idx_notifications_user_read ON notifications(user_id, is_read);