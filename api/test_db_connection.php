                                                                <?php
// Teste de conexão com banco de dados
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$result = [
    'env_vars' => [
        'DB_HOST' => getenv('DB_HOST') ?: 'não definido (usando localhost)',
        'DB_PORT' => getenv('DB_PORT') ?: 'não definido (usando 3306)',
        'DB_NAME' => getenv('DB_NAME') ?: 'não definido (usando sistema_auth)',
        'DB_USER' => getenv('DB_USER') ?: 'não definido (usando root)',
        'DB_PASS' => getenv('DB_PASS') ? '***definido***' : 'não definido (vazio)',
    ],
    'connection_test' => null,
    'error' => null
];

// Tenta conectar
try {
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '3306';
    $dbname = getenv('DB_NAME') ?: 'sistema_auth';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    $result['connection_test'] = 'SUCCESS - Conectado ao banco!';
    
    // Testa uma query simples
    $stmt = $pdo->query("SELECT DATABASE() as db, NOW() as now");
    $result['query_test'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $result['connection_test'] = 'FAILED';
    $result['error'] = $e->getMessage();
    $result['error_code'] = $e->getCode();
}

echo json_encode($result, JSON_PRETTY_PRINT);
