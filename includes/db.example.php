<?php
// ============================================================
//  NYMIX — includes/db.php
//  true  = XAMPP / localhost
//  false = live hosting (fill in the hosting block below)
// ============================================================
$isLocal = true;

if ($isLocal) {
    define('DB_HOST', 'CHANGE_ME');
    define('DB_USER', 'CHANGE_ME');
    define('DB_PASS', 'CHANGE_ME');
    define('DB_NAME', 'CHANGE_ME');
    define('DB_PORT', 3306);
} else {
    define('DB_HOST', 'CHANGE_ME');
    define('DB_USER', 'CHANGE_ME');
    define('DB_PASS', 'CHANGE_ME');
    define('DB_NAME', 'CHANGE_ME');
    define('DB_PORT', 3306);
}

// MySQLi
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) {
    die('MySQLi connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// PDO
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('PDO connection failed: ' . $e->getMessage());
        }
    }
    return $pdo;
}