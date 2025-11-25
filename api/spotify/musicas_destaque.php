<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../../classes/SpotifyAPI.php';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 6; // Número de músicas com maiores notas a retornar

// Buscar as músicas com as maiores médias de notas
try {
    $clientId = getenv('SPOTIFY_CLIENT_ID');
    $clientSecret = getenv('SPOTIFY_CLIENT_SECRET');
    if (!$clientId || !$clientSecret) {
        throw new Exception('Credenciais do Spotify não configuradas.');
    }
    $spotifyApi = new SpotifyAPI($clientId, $clientSecret);

    $sql = "
        SELECT
            m.spotify_id AS id,
            m.titulo,
            m.artista,
            m.capa_url,
            AVG(a.nota) AS media_nota,
            COUNT(a.id) AS total_avaliacoes
        FROM musicas m
        JOIN avaliacoes a ON m.id = a.musica_id
        GROUP BY m.id, m.titulo, m.artista, m.capa_url
        ORDER BY media_nota DESC
        LIMIT :limit";

    $stmt = $pdo->prepare($sql);

    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();

    $musicas_locais = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $enrichedResults = [];
        foreach ($musicas_locais as $musica) {
            // Busca os detalhes completos usando o ID
            $spotifyTrack = $spotifyApi->getTrackById($musica['id']);
            
            if ($spotifyTrack) {
                $enrichedResults[] = [
                    'id' => $spotifyTrack->id,
                    'titulo' => $spotifyTrack->name,
                    'artista' => $spotifyTrack->artists[0]->name,
                    'capa_url' => $spotifyTrack->album->images[0]->url ?? $musica['capa_url'],
                    'media_nota' => round((float)$musica['media_nota'], 1),
                    
                    // Dados extras para a página de avaliação
                    'duration_ms' => $spotifyTrack->duration_ms,
                    'release_date' => $spotifyTrack->album->release_date,
                    'popularity' => $spotifyTrack->popularity,
                    'explicit' => $spotifyTrack->explicit,
                    'album_name' => $spotifyTrack->album->name,
                    'album_type' => $spotifyTrack->album->album_type,
                    'spotify_url' => $spotifyTrack->external_urls->spotify
            ];
        }
    }

    echo json_encode(['sucesso' => true, 'musicas' => $enrichedResults]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Erro em musicas_destaque.php: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno do servidor: ' . $e->getMessage()]);
}

