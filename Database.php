<?php
require_once __DIR__ . '/config.php';

class Database
{
    private static $instance = null;
    private $connection = null;

    private function __construct()
    {
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Lazy connection establishment
     */
    public function getConnection()
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        error_log("[Database] Initializing connection to " . DB_HOST);
        $maxRetries = 3;
        $retryDelay = 1000000; // 1 second
        $lastException = null;

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET . ";connect_timeout=60";

                error_log("[Database] Attempt " . ($i + 1) . " - Connecting to: " . DB_HOST . ":" . DB_PORT);

                $this->connection = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_TIMEOUT => 90, // Increased for significant Railway latency
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false, // Temporarily disabled for CLI debugging
                ]);

                error_log("[Database] Connection established successfully!");
                return $this->connection;
            } catch (PDOException $e) {
                $lastException = $e;
                error_log("[Database] Attempt " . ($i + 1) . " failed: " . $e->getMessage());
                if ($i < $maxRetries - 1) {
                    error_log("[Database] Retrying in 1 second...");
                    usleep($retryDelay);
                }
            }
        }

        // Log final failure
        error_log("[Database] All connection attempts failed. Last error: " . ($lastException ? $lastException->getMessage() : 'Unknown'));

        // Return error JSON instead of hanging
        http_response_code(503);
        header('Content-Type: application/json');
        die(json_encode([
            'status' => 'error',
            'error' => 'DATABASE_CONNECTION_ERROR',
            'message' => 'Unable to connect to Railway database. Please check your credentials and try again.',
            'details' => $lastException ? $lastException->getMessage() : 'Unknown connection timeout',
            'host' => DB_HOST,
            'port' => DB_PORT,
            'database' => DB_NAME
        ]));
    }

    /**
     * Proxy all PDO methods to the connection (LAZY)
     */
    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->getConnection(), $name], $arguments);
    }

    private function __clone()
    {
    }
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}
