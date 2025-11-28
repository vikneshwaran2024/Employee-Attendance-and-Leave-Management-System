-- SQL Script to create login_logs table
USE employee_management;

CREATE TABLE IF NOT EXISTS login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    login_time DATETIME NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    status VARCHAR(20) DEFAULT 'success',
    user_agent TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id)
);