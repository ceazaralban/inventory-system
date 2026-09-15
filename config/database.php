<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $this->host     = getenv('DB_HOST') ?: "localhost";
        $this->db_name  = getenv('DB_NAME') ?: "inventory_system";
        $this->username = getenv('DB_USER') ?: "root";
        $this->password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            // If database does not exist (error 1049) and user is root, try to create it
            if ($exception->getCode() == 1049 || strpos($exception->getMessage(), 'Unknown database') !== false) {
                try {
                    $pdo = new PDO("mysql:host=" . $this->host, $this->username, $this->password);
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . $this->db_name . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $this->conn = new PDO(
                        "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                        $this->username,
                        $this->password
                    );
                    $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    return $this->conn;
                } catch(PDOException $e) {
                    // Continue to display error below
                }
            }

            die(
                "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; border: 1px solid #f5c6cb; background: #f8d7da; color: #721c24; border-radius: 6px;'>" .
                "<h3 style='margin-top: 0;'>Database Connection Error</h3>" .
                "<p>" . htmlspecialchars($exception->getMessage()) . "</p>" .
                "<p>Please ensure MySQL is running and the database <code>" . htmlspecialchars($this->db_name) . "</code> is imported using <code>database.sql</code>.</p>" .
                "</div>"
            );
        }
        return $this->conn;
    }
}
?>