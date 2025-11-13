<?php
require_once 'header.php'; // Session_start()
require_once 'conexao.php'; // conexão banco de dados
require_once __DIR__ . '/../classes/SpotifyAPI.php';


$usuario_id = $_SESSION['usuario_id'] ?? null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 3; // Número de avaliações principais a retornar

try{
    $clientId = getenv('SPOTIFY_CLIENT_ID');
    $clientSecret = getenv('SPOTIFY_CLIENT_SECRET');

    if (!$clientId || !$clientSecret) {
        throw new Exception('Credenciais do Spotify não configuradas.');
    }
    $spotifyApi = new SpotifyAPI($clientId, $clientSecret);

    $sql = "
        SELECT 
            a.id, a.nota, a.titulo, a.comentario,
            DATE_FORMAT(a.data_criacao, '%Y-%m-%dT%H:%i:%sZ') AS data_criacao,
            u.id AS usuario_id, 
            u.nome AS usuario_nome, 
            u.foto_perfil AS usuario_avatar,
            m.titulo AS musica_titulo,
            m.artista AS musica_artista,
            m.capa_url AS musica_capa,
            m.spotify_id as musica_spotify_id,
            (SELECT COUNT(*) FROM curtidas_avaliacoes ca WHERE ca.avaliacao_id = a.id) AS total_curtidas,
            (EXISTS(SELECT 1 FROM curtidas_avaliacoes cl WHERE cl.avaliacao_id = a.id AND cl.usuario_id = :usuario_logado_id_curtidas)) AS usuario_curtiu,
            (EXISTS(SELECT 1 FROM seguidores s WHERE s.seguido_id = u.id AND s.seguidor_id = :usuario_logado_id_seguidores)) AS is_following
        FROM avaliacoes a
        JOIN usuarios u ON a.usuario_id = u.id
        JOIN musicas m ON a.musica_id = m.id
        ORDER BY total_curtidas DESC, a.data_criacao DESC
        LIMIT :limit";

        $stmt = $pdo->prepare($sql);

        if ($usuario_id === null) {
            $stmt->bindValue(':usuario_logado_id_curtidas', null, PDO::PARAM_NULL);
            $stmt->bindValue(':usuario_logado_id_seguidores', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':usuario_logado_id_curtidas', $usuario_id, PDO::PARAM_INT);
            $stmt->bindValue(':usuario_logado_id_seguidores', $usuario_id, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    $stmt->execute();
    $avaliacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatar os dados
    $avaliacoes_formatadas = [];
    foreach ($avaliacoes as $row) {
        // Dados básicos            
        $musica_formatada = [
            'id' => $row['musica_spotify_id'],
            'titulo' => $row['musica_titulo'],
            'artista' => $row['musica_artista'],
            'capa' => $row['musica_capa'] ?? 'https://via.placeholder.com/150',
            // Valores padrão
            'spotify_url' => null,
            'duration_ms' => null,
            'release_date' => null,
            'popularity' => null,
            'explicit' => false,
            'album_name' => null,
            'album_type' => null
        ];

            // Tenta buscar os dados completos no Spotify
            if (!empty($row['musica_spotify_id'])) {
                try {
                    $spotifyTrack = $spotifyApi->getTrackById($row['musica_spotify_id']);

                    if ($spotifyTrack) {
                        // Conseguiu buscar, soobrescreve o objeto com dados ricos
                        $musica_formatada = [
                            'id' => $spotifyTrack->id,
                            'titulo' => $spotifyTrack->name,
                            'artista' => $spotifyTrack->artists[0]->name,
                            'capa' => $spotifyTrack->album->images[0]->url ?? $row['musica_capa'],
                            'spotify_url' => $spotifyTrack->external_urls->spotify,
                            'duration_ms' => $spotifyTrack->duration_ms,
                            'release_date' => $spotifyTrack->album->release_date,
                            'popularity' => $spotifyTrack->popularity,
                            'explicit' => $spotifyTrack->explicit,
                            'album_name' => $spotifyTrack->album->name,
                            'album_type' => $spotifyTrack->album->album_type
                        ];
                    }
                } catch (Exception $e) {
                    error_log("Falha em principais_avaliacoes.php ao buscar getTrackById para " . $row['musica_spotify_id'] . ": " . $e->getMessage());
                    // Se falhar, $musica_formatada fica com apenas os dados básicos
                } 
            }
            // adiciona a avaliação e usuario ao array principal
            $avaliacoes_formatadas[] = [
                'id' => $row['id'],
                'musica' => $musica_formatada, 
                'nota' => (float)$row['nota'],
                'titulo' => $row['titulo'],
                'comentario' => $row['comentario'],
                'data_criacao' => $row['data_criacao'], 
                'likes' => (int)$row['total_curtidas'],
                'usuario_curtiu' => (bool)$row['usuario_curtiu'],
                'is_following' => (bool)$row['is_following'],
                'usuario' => [
                    'id' => $row['usuario_id'],
                    'nome' => $row['usuario_nome'],
                    'avatar' => $row['usuario_avatar']
                ]
            ];
        }

    echo json_encode(['sucesso' => true, 'avaliacoes' => $avaliacoes_formatadas]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Erro ao buscar principais avaliações: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno do servidor: ' . $e->getMessage()]);
}
?>