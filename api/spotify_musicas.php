<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Carregar .env
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }
    
    $tipo = $_GET['tipo'] ?? 'populares';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 6;
    
    if ($tipo === 'populares') {
        // Use Last.fm for populares
        require_once '../classes/LastFmAPI.php';
        $apiKey = $_ENV['LASTFM_API_KEY'] ?? '';
        
        if (empty($apiKey)) {
            throw new Exception("API Key do Last.fm não encontrada no .env");
        }
        
        $lastfm = new LastFmAPI($apiKey);
        $musicas = $lastfm->getTrendingTracks($limit);
        
    } else {
        // Use Spotify for other types
        require_once '../classes/SpotifyAPI.php';
        $config = include '../config/spotify.php';
        $spotify = new SpotifyAPI($config['client_id'], $config['client_secret']);
        
        switch ($tipo) {
            case 'top':
                $musicas = $spotify->getTopMusicas($limit);
                break;
            case 'brasil':
                $musicas = $spotify->getTopBrasil($limit);
                break;
            default:
                throw new Exception('Tipo de requisição inválido');
        }
    }
    
    echo json_encode([
        'sucesso' => true,
        'tipo' => $tipo,
        'fonte' => $tipo === 'populares' ? 'Last.fm' : 'Spotify',
        'musicas' => $musicas
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>