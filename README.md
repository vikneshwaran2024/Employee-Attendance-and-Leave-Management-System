# Employee Attendance and Leave Management System

A comprehensive web-based Employee Attendance and Leave Management System built with PHP, MySQL, HTML, CSS, JavaScript, and Bootstrap. This system provides secure authentication with role-based access control for employees, managers, and administrators.

## 🚀 Features

### Core Features
- **Secure Authentication** - Login system with hashed passwords (bcrypt) and CSRF protection
- **Role-Based Access Control** - Three user roles: Employee, Manager, and Admin
- **Real-Time Check-In/Check-Out** - Clock in and out with automatic timestamp recording
- **Automatic Work Hour Calculation** - Auto-compute regular hours and overtime
- **Leave Management** - Submit, track, and manage leave requests with approval workflow
- **Responsive Dashboard** - Role-specific dashboards with key metrics and statistics
- **Holiday Management** - Admin can manage company holidays
- **Attendance Reports** - Detailed attendance statistics and exportable reports
- **Notifications** - In-app notifications for leave approvals/rejections

### Security Features
- Password hashing using PHP's `password_hash()` with bcrypt
- Prepared statements for all database queries (SQL injection prevention)
- CSRF token protection on all forms
- Session management with regeneration
- Input sanitization and validation
- XSS prevention with `htmlspecialchars()`

## 📋 Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- Modern web browser

## 🛠️ Installation

### 1. Clone the Repository
```bash
git clone https://github.com/your-username/Employee-Attendance-and-Leave-Management-System.git
cd Employee-Attendance-and-Leave-Management-System
```

### 2. Database Setup
```bash
# Create database and import schema
mysql -u root -p < database/schema.sql
```

### 3. Configure Database Connection
Edit `config/database.php` and update the following constants:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'attendance_system');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
```

### 4. Configure Web Server
Point your web server's document root to the project directory, or place the files in your existing web root.

### 5. Access the Application
Open your browser and navigate to:
```
http://localhost/
```

## 🔐 Default Login Credentials

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@company.com | password |

> ⚠️ **Important:** Change the default admin password immediately after first login!

## 📁 Project Structure

```
├── api/                    # API endpoints
│   └── attendance.php      # Check-in/out API
├── assets/                 # Static assets (images)
├── config/                 # Configuration files
│   └── database.php        # Database connection
├── css/                    # Stylesheets
│   └── style.css           # Custom styles
├── database/               # Database files
│   └── schema.sql          # Database schema
├── includes/               # PHP includes
│   ├── auth.php            # Authentication functions
│   ├── functions.php       # Helper functions
│   ├── header.php          # Page header
│   └── footer.php          # Page footer
├── js/                     # JavaScript files
│   └── app.js              # Main JavaScript
├── index.php               # Entry point
├── login.php               # Login page
├── logout.php              # Logout handler
├── dashboard.php           # Main dashboard
├── attendance.php          # Attendance records
├── leave.php               # Leave requests
├── leave-approval.php      # Leave approvals (Manager/Admin)
├── employees.php           # Employee management (Admin)
├── departments.php         # Department management (Admin)
├── holidays.php            # Holiday management (Admin)
├── reports.php             # Reports (Manager/Admin)
├── settings.php            # System settings (Admin)
├── profile.php             # User profile
├── notifications.php       # Notifications
└── unauthorized.php        # Access denied page
```

## 👥 User Roles & Permissions

### Employee
- Check-in and check-out
- View own attendance history
- Submit leave requests
- View leave balance
- Update profile

### Manager
- All Employee permissions
- Approve/reject leave requests (own department)
- View team attendance reports
- Access department statistics

### Admin
- All Manager permissions
- Manage employees (CRUD)
- Manage departments
- Manage holidays
- Configure system settings
- Access all reports

## 📊 Database Schema

The system uses the following main tables:
- `users` - Employee information and authentication
- `departments` - Company departments
- `attendance` - Daily attendance records
- `leave_types` - Types of leave (Annual, Sick, etc.)
- `leave_requests` - Leave applications
- `leave_balance` - Leave balance per employee per year
- `holidays` - Company holidays
- `settings` - System configuration
- `notifications` - User notifications
- `activity_logs` - System activity logs

## 🔧 Configuration

System settings can be configured through the admin panel:
- Work start/end times
- Standard work hours per day
- Overtime threshold
- Late threshold (minutes)
- Company name
- Timezone

## 📱 Responsive Design

The system is fully responsive and works on:
- Desktop computers
- Tablets
- Mobile phones

## 🛡️ Security Considerations

1. **Password Security**: All passwords are hashed using bcrypt
2. **SQL Injection**: All database queries use prepared statements
3. **XSS Prevention**: All output is properly escaped
4. **CSRF Protection**: All forms include CSRF tokens
5. **Session Security**: Sessions are regenerated on login

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🤝 Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📧 Support

For support, please open an issue in the GitHub repository.
