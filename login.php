<?php
// Initialize session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
 
// Check if user is already logged in, if yes redirect to dashboard
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: dashboard.php");
    exit;
}
 
// Include database connection
require_once "config/database.php";
 
// Define variables and initialize with empty values
$employee_id = $password = "";
$employee_id_err = $password_err = $login_err = "";
 
// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
 
    // Check if employee_id is empty
    if(empty(trim($_POST["employee_id"]))){
        $employee_id_err = "Please enter your employee ID.";
    } else{
        $employee_id = trim($_POST["employee_id"]);
    }
    
    // Check if password is empty
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter your password.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    // Validate credentials
    if(empty($employee_id_err) && empty($password_err)){
        // Prepare a select statement
        $sql = "SELECT id, employee_id, first_name, last_name, password, role FROM users WHERE employee_id = ?";
        
        if($stmt = mysqli_prepare($conn, $sql)){
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "s", $param_employee_id);
            
            // Set parameters
            $param_employee_id = $employee_id;
            
            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                // Store result
                mysqli_stmt_store_result($stmt);
                
                // Check if employee ID exists, if yes then verify password
                if(mysqli_stmt_num_rows($stmt) == 1){                    
                    // Bind result variables
                    mysqli_stmt_bind_result($stmt, $id, $employee_id, $first_name, $last_name, $hashed_password, $role);
                    if(mysqli_stmt_fetch($stmt)){
                        if(password_verify($password, $hashed_password)){
                            // Password is correct, no need to start session again as it's already started at the top
                            
                            // Store data in session variables
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $id;
                            $_SESSION["employee_id"] = $employee_id;
                            $_SESSION["name"] = $first_name . " " . $last_name;
                            $_SESSION["role"] = $role;
                            
                            // Log login time
                            $log_sql = "INSERT INTO login_logs (user_id, login_time, ip_address) VALUES (?, NOW(), ?)";
                            if($log_stmt = mysqli_prepare($conn, $log_sql)){
                                mysqli_stmt_bind_param($log_stmt, "is", $id, $_SERVER['REMOTE_ADDR']);
                                mysqli_stmt_execute($log_stmt);
                                mysqli_stmt_close($log_stmt);
                            }
                            
                            // Redirect user to dashboard page
                            header("location: dashboard.php");
                        } else{
                            // Password is not valid, display a generic error message
                            $login_err = "Invalid employee ID or password.";
                        }
                    }
                } else{
                    // Employee ID doesn't exist, display a generic error message
                    $login_err = "Invalid employee ID or password.";
                }
            } else{
                $login_err = "Oops! Something went wrong. Please try again later.";
            }

            // Close statement
            mysqli_stmt_close($stmt);
        }
    }
    
    // Close connection
    mysqli_close($conn);
}
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Employee Attendance & Leave Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card login-card shadow-sm mt-5">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <i class="fas fa-user-clock fa-3x text-primary mb-3"></i>
                            <h2 class="system-name">EmpManage</h2>
                            <p class="text-muted">Employee Attendance & Leave Management System</p>
                        </div>

                        <?php 
                        if(!empty($login_err)){
                            echo '<div class="alert alert-danger">' . $login_err . '</div>';
                        }        
                        ?>

                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                            <div class="mb-3">
                                <label for="employee_id" class="form-label">Employee ID</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                                    <input type="text" name="employee_id" id="employee_id" class="form-control <?php echo (!empty($employee_id_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $employee_id; ?>" placeholder="Enter your employee ID">
                                </div>
                                <span class="invalid-feedback"><?php echo $employee_id_err; ?></span>
                            </div>    
                            <div class="mb-4">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" placeholder="Enter your password">
                                </div>
                                <span class="invalid-feedback"><?php echo $password_err; ?></span>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">Login</button>
                            </div>
                            <div class="mt-3 text-center">
                                <a href="forgot_password.php" class="text-decoration-none">Forgot Password?</a>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="text-center mt-3 text-muted">
                    <small>&copy; <?php echo date('Y'); ?> EmpManage. All rights reserved.</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>