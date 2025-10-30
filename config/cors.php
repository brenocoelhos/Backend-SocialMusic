<?php
/**
 * Configuração CORS centralizada
 * Permite requisições do frontend em produção e desenvolvimento
 */

// Lista de origens permitidas
$allowedOrigins = [
    'http://localhost:3000',
    'http://localhost:5173',
    'http://localhost:8080',
    'https://socialmusic.vercel.app'
];

// Pegar origem da requisição
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Verificar se a origem está na lista permitida
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
} else {
    // Para outras origens, permitir mas sem credentials
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header('Content-Type: application/json; charset=utf-8');

// Responder a requisições OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
?>
