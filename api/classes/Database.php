<?php
/**
 * Database Helper Class
 * 
 * Provides database utility functions and query helpers
 */

require_once __DIR__ . '/../config.php';

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        global $pdo;
        if (!isset($pdo)) {
            // Fallback: create PDO connection directly
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } else {
            $this->pdo = $pdo;
        }
    }
    
    /**
     * Get singleton instance
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get PDO instance
     * @return PDO
     */
    public function getPDO() {
        return $this->pdo;
    }
    
    /**
     * Execute a query and return results
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return array Results
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                throw new Exception("Database query failed: " . $e->getMessage());
            }
            throw new Exception("Database query failed");
        }
    }
    
    /**
     * Execute a query and return single row
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return array|null Single row or null
     */
    public function queryOne($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                throw new Exception("Database query failed: " . $e->getMessage());
            }
            throw new Exception("Database query failed");
        }
    }
    
    /**
     * Execute a query and return single value
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return mixed Single value
     */
    public function queryValue($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchColumn();
            return $result;
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                throw new Exception("Database query failed: " . $e->getMessage());
            }
            throw new Exception("Database query failed");
        }
    }
    
    /**
     * Execute an INSERT, UPDATE, or DELETE query
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return int Number of affected rows
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                throw new Exception("Database execute failed: " . $e->getMessage());
            }
            throw new Exception("Database execute failed");
        }
    }
    
    /**
     * Insert a row and return the last insert ID
     * @param string $table Table name
     * @param array $data Data to insert (associative array)
     * @return int Last insert ID
     */
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($data);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                throw new Exception("Database insert failed: " . $e->getMessage());
            }
            throw new Exception("Database insert failed");
        }
    }
    
    /**
     * Update a row
     * @param string $table Table name
     * @param array $data Data to update (associative array)
     * @param string $where WHERE clause (with placeholders)
     * @param array $whereParams Parameters for WHERE clause
     * @return int Number of affected rows
     */
    public function update($table, $data, $where, $whereParams = []) {
        $set = [];
        $params = [];
        
        // Build SET clause with named parameters
        foreach ($data as $key => $value) {
            $set[] = "{$key} = :set_{$key}";
            $params[":set_{$key}"] = $value;
        }
        $setClause = implode(', ', $set);
        
        // Build WHERE clause with numbered parameters
        $wherePlaceholders = [];
        foreach ($whereParams as $index => $value) {
            $paramName = ":where_" . $index;
            $wherePlaceholders[] = $paramName;
            $params[$paramName] = $value;
        }
        
        // Replace ? placeholders in WHERE clause with named parameters
        $whereParts = explode('?', $where);
        $finalWhere = '';
        for ($i = 0; $i < count($whereParts); $i++) {
            $finalWhere .= $whereParts[$i];
            if ($i < count($wherePlaceholders)) {
                $finalWhere .= $wherePlaceholders[$i];
            }
        }
        
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$finalWhere}";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                throw new Exception("Database update failed: " . $e->getMessage());
            }
            throw new Exception("Database update failed");
        }
    }
    
    /**
     * Delete rows
     * @param string $table Table name
     * @param string $where WHERE clause (with placeholders)
     * @param array $params Parameters for WHERE clause
     * @return int Number of affected rows
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                throw new Exception("Database delete failed: " . $e->getMessage());
            }
            throw new Exception("Database delete failed");
        }
    }
    
    /**
     * Begin a transaction
     * @return bool
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Commit a transaction
     * @return bool
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Rollback a transaction
     * @return bool
     */
    public function rollback() {
        return $this->pdo->rollBack();
    }
    
    /**
     * Check if currently in a transaction
     * @return bool
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }
}

