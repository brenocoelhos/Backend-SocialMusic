<?php
// Arquivo raiz do backend - SocialMusic API

// Incluir configuração de CORS centralizada
require_once __DIR__ . '/config/cors.php';

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
            '/api/spotify_user_auth.php',
            '/api/spotify_user_callback.php'
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
