<?php
// ============================================================
// WhatsFlow Pro - Database Layer (PDO Singleton)
// ============================================================

class Database {
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Everything (PHP date(), NOW(), TIMESTAMP columns) is UTC; the browser converts to the viewer's local time.
            $this->pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            error_log('DB Connection failed: ' . $e->getMessage());
            die(json_encode(['error' => 'Database connection failed']));
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPDO(): PDO {
        return $this->pdo;
    }

    // Convenience: fetch single row
    public function row(string $sql, array $params = []): ?array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    // Convenience: fetch all rows
    public function all(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // Convenience: execute (INSERT/UPDATE/DELETE)
    public function run(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // Convenience: insert and get last ID
    public function insert(string $sql, array $params = []): string {
        $this->run($sql, $params);
        return $this->pdo->lastInsertId();
    }

    // Count rows
    public function count(string $sql, array $params = []): int {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}

// Global helper
function db(): Database {
    return Database::getInstance();
}
