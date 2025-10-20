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
    // Carregar configuração do Last.fm
    require_once __DIR__ . '/../config/lastfm.php';
    require_once __DIR__ . '/../classes/LastFmAPI.php';
    
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 6;
    
    // Usar apenas Last.fm para músicas populares
    $lastfm = new LastFmAPI(LASTFM_API_KEY);
    $musicas = $lastfm->getTrendingTracks($limit);
    
    echo json_encode([
        'sucesso' => true,
        'tipo' => 'populares',
        'fonte' => 'Last.fm Global Charts',
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