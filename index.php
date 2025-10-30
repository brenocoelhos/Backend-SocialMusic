<?php
// Arquivo raiz do backend - SocialMusic API
header('Content-Type: application/json; charset=utf-8');

// Define CORS headers
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
header("Access-Control-Allow-Origin: " . $origin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Resposta de status da API
$response = [
    'status' => 'online',
    'message' => 'SocialMusic Backend API',
    'version' => '1.0.0',
    'timestamp' => date('Y-m-d H:i:s'),
    'endpoints' => [
        'authentication' => [
            '/api/login.php',
            '/api/cadastro.php',
            '/api/logout.php',
            '/api/auth.admin.php'
        ],
        'spotify' => [
            '/api/spotify_auth_owner.php',
            '/api/spotify_callback_owner.php',
            '/api/spotify_musicas.php',
            '/api/spotify_owner_info.php',
            '/api/spotify_user_auth.php'
        ],
        'search' => [
            '/api/search.php'
        ],
        'session' => [
            '/api/status_sessao.php',
            '/api/status_token.php',
            '/api/verifica_sessao.php'
        ]
    ]
];

http_response_code(200);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
