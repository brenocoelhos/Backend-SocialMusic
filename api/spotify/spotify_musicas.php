<?php
require_once __DIR__ . '/../core/header.php';
// Configs e Classes sobem 2 níveis (../../)
require_once __DIR__ . '/../../config/lastfm.php';
require_once __DIR__ . '/../../classes/LastFmAPI.php';
require_once __DIR__ . '/../../classes/SpotifyAPI.php';

try {
    // Pega as credenciais 
    $clientId = getenv('SPOTIFY_CLIENT_ID');
    $clientSecret = getenv('SPOTIFY_CLIENT_SECRET');
    $apiKeyLastFm = LASTFM_API_KEY; 

    if (!$clientId || !$clientSecret) {
        throw new Exception('Credenciais da API do Spotify não configuradas no .env.');
    }
    if (!$apiKeyLastFm) {
        throw new Exception('Credencial da API do Last.fm não configurada no .env.');
    }

    // Instancia as APIs
    $spotifyApi = new SpotifyAPI($clientId, $clientSecret);
    $lastfm = new LastFmAPI($apiKeyLastFm);
    
    // Busca as músicas do Last.fm
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 6;
    $trendingTracks = $lastfm->getTrendingTracks($limit);
    
    // "Enriquece" os dados com a API do Spotify
    $enrichedResults = [];
    foreach ($trendingTracks as $track) {
        // Busca no Spotify usando "Título Artista"
        $searchData = $spotifyApi->searchTracks($track['titulo'] . ' ' . $track['artista'], 1);
        
        if ($searchData && !empty($searchData->tracks->items)) {
            $spotifyTrack = $searchData->tracks->items[0];
            
            $artistName = $spotifyTrack->artists[0]->name ?? $track['artista'];
            
            // Monta o objeto completo que o Avaliacao.vue precisa
            $enrichedResults[] = [
                'id' => $spotifyTrack->id, 
                'titulo' => $spotifyTrack->name,
                'artista' => $artistName,
                'capa' => $spotifyTrack->album->images[0]->url ?? $track['capa'],
                'duration_ms' => $spotifyTrack->duration_ms,
                'release_date' => $spotifyTrack->album->release_date,
                'popularity' => $spotifyTrack->popularity,
                'explicit' => $spotifyTrack->explicit,
                'album_name' => $spotifyTrack->album->name,
                'album_type' => $spotifyTrack->album->album_type,
                'spotify_url' => $spotifyTrack->external_urls->spotify
            ];
        }
        // Se não encontrar no Spotify, a música é simplesmente ignorada da lista.
    }
    
    echo json_encode([
        'sucesso' => true,
        'tipo' => 'populares',
        'fonte' => 'Last.fm + Spotify',
        'musicas' => $enrichedResults
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>