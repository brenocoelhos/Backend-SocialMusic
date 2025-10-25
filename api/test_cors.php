<?php
// Arquivo de teste CORS e configuração
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Test 1: CORS básico
$origin = $_SERVER['HTTP_ORIGIN'] ?? 'não definido';
$allowed = [
    'http://localhost:3000',
    'http://localhost:5173',
    'https://socialmusic.vercel.app'
];

if (in_array($origin, $allowed)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Test 2: Verificar se os arquivos de config existem
$tests = [
    'cors_file_exists' => file_exists(__DIR__ . '/../config/cors.php'),
    'database_file_exists' => file_exists(__DIR__ . '/../config/database.php'),
    'origin' => $origin,
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                || $_SERVER['SERVER_PORT'] == 443
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
    'php_version' => phpversion(),
    'extensions' => [
        'pdo' => extension_loaded('pdo'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'curl' => extension_loaded('curl'),
        'session' => extension_loaded('session')
    ]
];

echo json_encode($tests, JSON_PRETTY_PRINT);
