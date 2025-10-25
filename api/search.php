<?php
// Adicionado para exibir erros durante o desenvolvimento
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Headers de resposta
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require __DIR__ . '/../classes/SpotifyAPI.php';

try {
    // Carregar .env
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim(trim($value, '"'));
            }
        }
    }

    $clientId = $_ENV['SPOTIFY_CLIENT_ID'] ?? null;
    $clientSecret = $_ENV['SPOTIFY_CLIENT_SECRET'] ?? null;

    //Verifica se as credenciais carregaram
    if (!$clientId || !$clientSecret) {
        http_response_code(500);
        echo json_encode(['error' => 'As credenciais da API do Spotify não foram configuradas no arquivo .env.']);
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
            'track_name' => $track->name,
            'artist_name' => $artistName,
            'image_url' => $imageUrl,
            'spotify_url' => $track->external_urls->spotify
        ];
    }

    // Resultados formatados em JSON
    echo json_encode($results);

} catch (Exception $e) { 
    http_response_code(500);
    error_log("Erro em search.php: " . $e->getMessage());
    echo json_encode(['error' => 'Ocorreu um erro no servidor: ' . $e->getMessage()]);
}
