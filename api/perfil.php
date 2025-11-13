<?php
require_once 'header.php';
require_once 'conexao.php';
require_once __DIR__ . '/../classes/SpotifyAPI.php';

// Antigo código do pedro não deixava usuários não logados acessarem o perfil de outros usuários
// AJUSTADO 

$utilizador_logado_id = $_SESSION['usuario_id'] ?? null;
$perfil_id_param = $_GET['id'] ?? null;

// Determina qual perfil carregar
$perfil_id = $perfil_id_param ? (int)$perfil_id_param : $utilizador_logado_id;

// BLOQUEAR APENAS SE:
// O usuário NÃO está logado E NÃO forneceu um ID de perfil na URL
// (ou seja, tentou acessar a própria página de perfil sem estar logado)
if (!$perfil_id) { 
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Acesso negado. Faça login ou especifique um ID de perfil.']);
    exit;
}

try {
    $clientId = getenv('SPOTIFY_CLIENT_ID');
    $clientSecret = getenv('SPOTIFY_CLIENT_SECRET');
    if (!$clientId || !$clientSecret) {
        throw new Exception('Credenciais do Spotify não configuradas.');
    }
    $spotifyApi = new SpotifyAPI($clientId, $clientSecret);

    // 1. Buscar os dados do perfil (lendo 'foto_perfil' e 'generos')
    $stmt_perfil = $pdo->prepare("SELECT id, nome, email, username, foto_perfil, generos FROM usuarios WHERE id = :perfil_id");
    $stmt_perfil->execute([':perfil_id' => $perfil_id]);
    $perfil = $stmt_perfil->fetch(PDO::FETCH_ASSOC);

    if (!$perfil) {
        http_response_code(404);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Perfil não encontrado.']);
        exit;
    }
    
    // Tirei a lógiga do avatar, pois irei tratar no front - Isa

    // 2. Verificar o estado "Seguir"
    $is_self = ($utilizador_logado_id == $perfil_id && $utilizador_logado_id !== null);    
    $is_following = false;
    if (!$is_self && $utilizador_logado_id) {
        $stmt_follow = $pdo->prepare("SELECT 1 FROM seguidores WHERE seguidor_id = :logado_id AND seguido_id = :perfil_id");
        $stmt_follow->execute([
            ':logado_id' => $utilizador_logado_id, 
            ':perfil_id' => $perfil_id
        ]); 
        $is_following = (bool) $stmt_follow->fetchColumn();
    }

    // 3. Contar Seguidores e Seguindo
    $stmt_following = $pdo->prepare("SELECT COUNT(*) FROM seguidores WHERE seguidor_id = :perfil_id");
    $stmt_following->execute([':perfil_id' => $perfil_id]);
    $perfil['following_count'] = $stmt_following->fetchColumn();

    $stmt_followers = $pdo->prepare("SELECT COUNT(*) FROM seguidores WHERE seguido_id = :perfil_id");
    $stmt_followers->execute([':perfil_id' => $perfil_id]);
    $perfil['followers_count'] = $stmt_followers->fetchColumn();

    
    $sql_avaliacoes = "
        SELECT 
            a.id, a.nota, a.titulo, a.comentario,
            m.titulo as musica_titulo, 
            m.artista as musica_artista, 
            m.capa_url as musica_capa,
            m.spotify_id as musica_spotify_id,
            (SELECT COUNT(*) FROM curtidas_avaliacoes ca WHERE ca.avaliacao_id = a.id) AS total_curtidas,
            (EXISTS(SELECT 1 FROM curtidas_avaliacoes cl WHERE cl.avaliacao_id = a.id AND cl.usuario_id = :usuario_logado_id)) AS usuario_curtiu
        FROM avaliacoes a
        LEFT JOIN musicas m ON a.musica_id = m.id
        WHERE a.usuario_id = :perfil_id
        ORDER BY a.data_criacao DESC
    ";

    $stmt_avaliacoes = $pdo->prepare($sql_avaliacoes);

    $stmt_avaliacoes->bindValue(':perfil_id', $perfil_id, PDO::PARAM_INT);
    if ($utilizador_logado_id === null) {
        $stmt_avaliacoes->bindValue(':usuario_logado_id', null, PDO::PARAM_NULL);
    } else {
        $stmt_avaliacoes->bindValue(':usuario_logado_id', $utilizador_logado_id, PDO::PARAM_INT);
    }

    $stmt_avaliacoes->execute();
    $avaliacoes_raw = $stmt_avaliacoes->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatamos os dados
    $musica_formatada = [];

    foreach ($avaliacoes_raw as $row) {
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
                    error_log("Falha em perfil.php ao buscar getTrackById para " . $row['musica_spotify_id'] . ": " . $e->getMessage());
                    // Se falhar, $musica_formatada fica com apenas os dados básicos
                } 
            }
            // adiciona a avaliação ao array principal
            $avaliacoes_formatadas[] = [
                'id' => $row['id'],
                'musica' => $musica_formatada, 
                'nota' => (float)$row['nota'],
                'titulo' => $row['titulo'],
                'comentario' => $row['comentario'],
                'likes' => (int)$row['total_curtidas'],
                'usuario_curtiu' => (bool)$row['usuario_curtiu']
            ];
        }

    // 6. Enviar a resposta completa (COM a lista de avaliações)
    echo json_encode([
        'sucesso' => true,
        'perfil' => $perfil,
        'avaliacoes' => $avaliacoes_formatadas, 
        'is_self' => $is_self,
        'is_following' => $is_following
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Erro em perfil.php: " . $e->getMessage()); 
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de servidor.', 'error' => $e->getMessage()]);
}
?>