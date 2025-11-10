<?php
require_once 'header.php'; // Garante que session_start() seja chamado
require_once 'conexao.php'; // Puxa a variável $pdo conexão com o banco

try {
    $clientId = getenv('SPOTIFY_CLIENT_ID');
    $clientSecret = getenv('SPOTIFY_CLIENT_SECRET');
    if (!$clientId || !$clientSecret) {
        throw new Exception('Credenciais da API do Spotify não configuradas no .env.');
    }

    $spotify_id = $_GET['id'] ?? null;
    if (!$spotify_id) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'mensagem' => 'ID da música é obrigatório.']);
        exit;
    }

    $api = new SpotifyAPI($clientId, $clientSecret);
    $trackData = $api->getTrackById($spotify_id);

    if ($trackData) {
        echo json_encode(['sucesso' => true, 'track' => $trackData]);
    } else {
        throw new Exception('Música não encontrada no Spotify');
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log("Erro em detalhes_musica.php: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno do servidor: ' . $e->getMessage()]);
}
?>