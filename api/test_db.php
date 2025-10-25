<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Carregar .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim(trim($value, '"'));
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'environment' => [],
    'database' => [],
    'tables' => []
];

// 1. Verificar variáveis de ambiente
$result['environment'] = [
    'DB_HOST' => getenv('DB_HOST') ?: 'não definido',
    'DB_PORT' => getenv('DB_PORT') ?: 'não definido',
    'DB_NAME' => getenv('DB_NAME') ?: 'não definido',
    'DB_USER' => getenv('DB_USER') ?: 'não definido',
    'DB_PASSWORD' => getenv('DB_PASS') ? '***definida***' : 'não definido',
    'DB_CHARSET' => getenv('DB_CHARSET') ?: 'não definido'
];

// 2. Testar conexão com banco
try {
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '3306';
    $dbname = getenv('DB_NAME') ?: 'sistema_auth';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';
    
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    $result['database']['status'] = '✅ Conectado com sucesso!';
    $result['database']['connection_string'] = "mysql:host={$host};port={$port};dbname={$dbname}";
    
    // 3. Listar tabelas
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        $result['tables']['status'] = '⚠️ Banco conectado mas sem tabelas';
        $result['tables']['list'] = [];
    } else {
        $result['tables']['status'] = '✅ Tabelas encontradas';
        $result['tables']['count'] = count($tables);
        $result['tables']['list'] = $tables;
        
        // 4. Contar registros da tabela usuarios (se existir)
        if (in_array('usuarios', $tables)) {
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
            $count = $stmt->fetch();
            $result['tables']['usuarios_count'] = $count['total'];
            
            // 5. Verificar estrutura da tabela
            $stmt = $pdo->query("DESCRIBE usuarios");
            $result['tables']['usuarios_structure'] = $stmt->fetchAll();
        }
    }
    
    $result['success'] = true;
    
} catch (PDOException $e) {
    $result['database']['status'] = '❌ Erro de conexão';
    $result['database']['error'] = $e->getMessage();
    $result['database']['error_code'] = $e->getCode();
    $result['success'] = false;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
