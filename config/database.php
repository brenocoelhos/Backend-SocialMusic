<?php<?php

/**/**

 * Database configuration * Configuração do Banco de Dados

 * Uses environment variables for production deployment */

 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');

// Database connection constantsdefine('DB_PORT', getenv('DB_PORT') ?: '3306');

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');define('DB_NAME', getenv('DB_NAME') ?: 'sistema_auth');

define('DB_PORT', getenv('DB_PORT') ?: '3306');define('DB_USER', getenv('DB_USER') ?: 'root');

define('DB_NAME', getenv('DB_NAME') ?: 'socialmusic');define('DB_PASS', getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '');

define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

define('DB_USER', getenv('DB_USER') ?: 'root');

class Database {

// Support both DB_PASS and DB_PASSWORD environment variables    private static $instance = null;

$pass = getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: '';    private $conn;

define('DB_PASS', $pass);    

?>    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch(PDOException $e) {
            // Log do erro real para debug
            error_log("Erro de conexão DB: " . $e->getMessage());
            error_log("DSN usado: " . $dsn);
            error_log("User usado: " . DB_USER);
            
            // Em produção, mostrar erro detalhado temporariamente para debug
            throw new Exception("Erro ao conectar ao banco de dados: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    // Previne clonagem
    private function __clone() {}
    
    // Previne deserialização
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
?>
