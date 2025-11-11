<?php
// Configuração CORS
require_once 'header.php';

require __DIR__ . '/../classes/SpotifyAPI.php';

try {
    // Pega as credenciais do Spotify de variáveis de ambiente
    $clientId = getenv('SPOTIFY_CLIENT_ID') ?: null;
    $clientSecret = getenv('SPOTIFY_CLIENT_SECRET') ?: null;

    //Verifica se as credenciais foram configuradas
    if (!$clientId || !$clientSecret) {
        http_response_code(500);
        echo json_encode(['error' => 'As credenciais da API do Spotify não foram configuradas.']);
        exit;
    }

    // Verifica se o parâmetro de busca 'q' foi enviado na URL
    if (!isset($_GET['q']) || empty(trim($_GET['q']))) {
        http_response_code(400);
        echo json_encode(['error' => 'Parâmetro de busca "q" é obrigatório.']);
        exit;
    }

    $searchQuery = $_GET['q'];

    // Instancia a classe da API
    $api = new SpotifyAPI($clientId, $clientSecret);

    // Realiza a busca
    $data = $api->searchTracks($searchQuery);

    if ($data === null || !isset($data->tracks->items)) {
        throw new Exception('Não foi possível buscar as músicas ou não há resultados. Verifique se as credenciais da API são válidas.');
    }

    $results = [];
    foreach ($data->tracks->items as $track) {

        // Nome dos artistas 
        $artists = [];
        foreach ($track->artists as $artist) {
            $artists[] = $artist->name;
        }
        $artistName = implode(', ', $artists);

        // Foto da música 
        $imageUrl = !empty($track->album->images) ? $track->album->images[1]->url : null;

        $results[] = [
            'id' => $track->id, 
            'track_name' => $track->name,
            'artist_name' => $artistName,
            'image_url' => $imageUrl,
            'spotify_url' => $track->external_urls->spotify,
            'duration_ms' => $track->duration_ms,
            'release_date' => $track->album->release_date,
            'popularity' => $track->popularity,
            'explicit' => $track->explicit,
            'album_name' => $track->album->name,
            'album_type' => $track->album->album_type
        ];
    }

    // Resultados formatados em JSON
    echo json_encode($results);

} catch (Exception $e) { 
    http_response_code(500);
    error_log("Erro em search.php: " . $e->getMessage());
    echo json_encode(['error' => 'Ocorreu um erro no servidor: ' . $e->getMessage()]);
}
