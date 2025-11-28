<?php
/**
 * Database Configuration and Connection
 * Uses PDO with prepared statements for security
 * 
 * IMPORTANT: For production environments, use environment variables
 * or a secure configuration method for sensitive credentials.
 * Never commit actual credentials to version control.
 */

// Use environment variables if available, fallback to defaults for development
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'attendance_system');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get database connection using PDO
 * @return PDO|null
 */
function getConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Execute a prepared statement and return results
 * @param string $sql
 * @param array $params
 * @return array|false
 */
function executeQuery($sql, $params = []) {
    $conn = getConnection();
    if ($conn === null) {
        return false;
    }
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Query execution failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Execute a prepared statement and return last insert ID
 * @param string $sql
 * @param array $params
 * @return int|false
 */
function executeInsert($sql, $params = []) {
    $conn = getConnection();
    if ($conn === null) {
        return false;
    }
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $conn->lastInsertId();
    } catch (PDOException $e) {
        error_log("Insert execution failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Execute a prepared statement and return affected rows
 * @param string $sql
 * @param array $params
 * @return int|false
 */
function executeUpdate($sql, $params = []) {
    $conn = getConnection();
    if ($conn === null) {
        return false;
    }
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Update execution failed: " . $e->getMessage());
        return false;
    }
}
