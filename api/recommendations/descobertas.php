<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

/**
 * Descobertas personalizadas do SocialMusic.
 *
 * GET  -> devolve apenas o cache válido, sem chamar Spotify.
 * POST -> gera a playlist (ou reaproveita cache), cruzando:
 *         - gêneros escolhidos no perfil;
 *         - avaliações do usuário;
 *         - usuários com avaliações parecidas;
 *         - faixas bem avaliadas pela comunidade;
 *         - histórico recente do Spotify, quando disponível.
 *
 * O resultado fica em cache por 12h. Nenhum histórico bruto do Spotify é salvo.
 */

function responder(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizar(string $texto): string
{
    $texto = function_exists('mb_strtolower') ? mb_strtolower(trim($texto), 'UTF-8') : strtolower(trim($texto));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    if ($ascii !== false) $texto = $ascii;
    $texto = preg_replace('/[^a-z0-9]+/i', ' ', $texto) ?? $texto;
    return trim(preg_replace('/\s+/', ' ', $texto) ?? $texto);
}

function spotifyRequest(string $url, string $token, string $method = 'GET', ?array $body = null, bool $formEncoded = false): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL não disponível no servidor.');
    }

    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 9,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($body !== null) {
        if ($formEncoded) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Falha de conexão com Spotify: ' . $curlError);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) $json = [];

    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = $json['error']['message'] ?? $json['error_description'] ?? ('HTTP ' . $httpCode);
        throw new RuntimeException('Spotify: ' . $msg, $httpCode);
    }

    return $json;
}

function spotifyTokenRequest(string $clientId, string $clientSecret, array $form): array
{
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($form, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 9,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException('Falha ao obter token Spotify: ' . $curlError);
    $json = json_decode($raw, true);
    if (!is_array($json)) $json = [];
    if ($httpCode < 200 || $httpCode >= 300 || empty($json['access_token'])) {
        $msg = $json['error_description'] ?? $json['error'] ?? ('HTTP ' . $httpCode);
        throw new RuntimeException('Spotify token: ' . $msg, $httpCode);
    }
    return $json;
}

function tokenSpotifyUsuario(PDO $pdo, int $usuarioId, string $clientId, string $clientSecret): ?string
{
    $stmt = $pdo->prepare('SELECT spotify_access_token, spotify_refresh_token, spotify_token_expires FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$usuarioId]);
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dados || empty($dados['spotify_access_token'])) return null;

    $expira = !empty($dados['spotify_token_expires']) ? strtotime($dados['spotify_token_expires']) : 0;
    if ($expira > (time() + 60)) return $dados['spotify_access_token'];

    if (empty($dados['spotify_refresh_token'])) return null;

    try {
        $novo = spotifyTokenRequest($clientId, $clientSecret, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $dados['spotify_refresh_token'],
        ]);

        $novoAccess = $novo['access_token'];
        $novoRefresh = $novo['refresh_token'] ?? $dados['spotify_refresh_token'];
        $novoExpira = date('Y-m-d H:i:s', time() + (int)($novo['expires_in'] ?? 3600));

        $upd = $pdo->prepare('UPDATE usuarios SET spotify_access_token = ?, spotify_refresh_token = ?, spotify_token_expires = ? WHERE id = ?');
        $upd->execute([$novoAccess, $novoRefresh, $novoExpira, $usuarioId]);
        return $novoAccess;
    } catch (Throwable $e) {
        error_log('descobertas.php refresh Spotify: ' . $e->getMessage());
        return null;
    }
}

function tokenSpotifyApp(string $clientId, string $clientSecret): string
{
    $dados = spotifyTokenRequest($clientId, $clientSecret, ['grant_type' => 'client_credentials']);
    return $dados['access_token'];
}

function formatarTrackSpotify(array $track): ?array
{
    $id = trim((string)($track['id'] ?? ''));
    $nome = trim((string)($track['name'] ?? ''));
    $artistas = [];
    foreach (($track['artists'] ?? []) as $artist) {
        if (!empty($artist['name'])) $artistas[] = $artist['name'];
    }
    $artista = implode(', ', $artistas);
    if ($id === '' || $nome === '' || $artista === '') return null;

    $images = $track['album']['images'] ?? [];
    $imagem = $images[1]['url'] ?? $images[0]['url'] ?? null;

    return [
        'spotify_id' => $id,
        'track_name' => $nome,
        'artist_name' => $artista,
        'image_url' => $imagem,
        'spotify_url' => $track['external_urls']['spotify'] ?? ('https://open.spotify.com/track/' . $id),
        'duration_ms' => isset($track['duration_ms']) ? (int)$track['duration_ms'] : null,
        'release_date' => $track['album']['release_date'] ?? null,
        'popularity' => isset($track['popularity']) ? (int)$track['popularity'] : null,
        'explicit' => (bool)($track['explicit'] ?? false),
        'album_name' => $track['album']['name'] ?? null,
        'album_type' => $track['album']['album_type'] ?? null,
    ];
}

function adicionarCandidato(array &$pool, array $track, float $score, string $motivo, string $fonte): void
{
    $id = (string)($track['spotify_id'] ?? '');
    if ($id === '') return;

    if (!isset($pool[$id])) {
        $pool[$id] = $track + [
            '_score' => 0.0,
            '_motivos' => [],
            '_fontes' => [],
        ];
    }

    $pool[$id]['_score'] += $score;
    if (!in_array($motivo, $pool[$id]['_motivos'], true)) $pool[$id]['_motivos'][] = $motivo;
    if (!in_array($fonte, $pool[$id]['_fontes'], true)) $pool[$id]['_fontes'][] = $fonte;

    foreach (['track_name','artist_name','image_url','spotify_url','duration_ms','release_date','popularity','explicit','album_name','album_type'] as $campo) {
        if (empty($pool[$id][$campo]) && !empty($track[$campo])) $pool[$id][$campo] = $track[$campo];
    }
}

function generoSpotify(string $genero): string
{
    $map = [
        'pop' => 'pop',
        'rock' => 'rock',
        'hip hop' => 'hip-hop',
        'rap' => 'rap',
        'r b' => 'r&b',
        'country' => 'country',
        'eletronica' => 'electronic',
        'jazz' => 'jazz',
        'classica' => 'classical',
        'funk' => 'funk',
        'sertanejo' => 'sertanejo',
        'pagode' => 'pagode',
        'samba' => 'samba',
        'indie' => 'indie',
        'metal' => 'metal',
        'reggae' => 'reggae',
        'soul' => 'soul',
        'blues' => 'blues',
        'k pop' => 'k-pop',
        'mpb' => 'mpb',
    ];
    $n = normalizar($genero);
    return $map[$n] ?? $genero;
}

function buscarSpotify(string $token, string $query, int $offset): array
{
    $params = http_build_query([
        'q' => $query,
        'type' => 'track',
        'market' => 'BR',
        'limit' => 10,
        'offset' => max(0, min(1000, $offset)),
    ], '', '&', PHP_QUERY_RFC3986);

    try {
        $resp = spotifyRequest('https://api.spotify.com/v1/search?' . $params, $token);
        return $resp['tracks']['items'] ?? [];
    } catch (Throwable $e) {
        error_log('descobertas.php search Spotify [' . $query . ']: ' . $e->getMessage());
        return [];
    }
}

function cacheValido(PDO $pdo, int $usuarioId): ?array
{
    $stmt = $pdo->prepare('SELECT payload, gerado_em, expira_em FROM recomendacoes_cache WHERE usuario_id = ? LIMIT 1');
    $stmt->execute([$usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $payload = json_decode($row['payload'], true);
    if (!is_array($payload)) return null;

    $payload['_gerado_em_db'] = $row['gerado_em'];
    $payload['_expira_em_db'] = $row['expira_em'];
    $payload['_valido'] = strtotime($row['expira_em']) > time();
    return $payload;
}

function salvarCache(PDO $pdo, int $usuarioId, array $payload): void
{
    $expira = date('Y-m-d H:i:s', time() + (12 * 60 * 60));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare("\n        INSERT INTO recomendacoes_cache (usuario_id, payload, gerado_em, expira_em)\n        VALUES (?, ?, NOW(), ?)\n        ON DUPLICATE KEY UPDATE payload = VALUES(payload), gerado_em = NOW(), expira_em = VALUES(expira_em)\n    ");
    $stmt->execute([$usuarioId, $json, $expira]);
}

if (!isset($_SESSION['usuario_id'])) {
    responder(401, ['sucesso' => false, 'mensagem' => 'Faça login para gerar suas descobertas.']);
}

$usuarioId = (int)$_SESSION['usuario_id'];
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$cache = cacheValido($pdo, $usuarioId);

if ($method === 'GET') {
    if ($cache && !empty($cache['_valido'])) {
        unset($cache['_valido'], $cache['_gerado_em_db'], $cache['_expira_em_db']);
        $cache['cache'] = true;
        responder(200, $cache);
    }

    responder(200, [
        'sucesso' => true,
        'tem_playlist' => false,
        'playlist' => [],
        'cache' => false,
    ]);
}

if ($method !== 'POST') responder(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$forcar = !empty($input['forcar']);

if ($cache && !empty($cache['_valido']) && !$forcar) {
    unset($cache['_valido'], $cache['_gerado_em_db'], $cache['_expira_em_db']);
    $cache['cache'] = true;
    responder(200, $cache);
}

if ($cache && $forcar && !empty($cache['_gerado_em_db'])) {
    $idade = time() - (strtotime($cache['_gerado_em_db']) ?: 0);
    if ($idade < 300) {
        unset($cache['_valido'], $cache['_gerado_em_db'], $cache['_expira_em_db']);
        $cache['cache'] = true;
        $cache['mensagem'] = 'Sua seleção acabou de ser atualizada. Tente novamente em alguns minutos.';
        $cache['cooldown'] = true;
        responder(200, $cache);
    }
}

try {
    $stmt = $pdo->prepare('SELECT generos, spotify_conectado FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$usuarioId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) responder(404, ['sucesso' => false, 'mensagem' => 'Usuário não encontrado.']);

    $generos = array_values(array_filter(array_map('trim', explode(',', (string)($usuario['generos'] ?? '')))));

    // Avaliações próprias: servem para exclusão e afinidade por artista.
    $stmt = $pdo->prepare("\n        SELECT a.nota, m.spotify_id, m.titulo, m.artista, m.capa_url\n        FROM avaliacoes a\n        JOIN musicas m ON m.id = a.musica_id\n        WHERE a.usuario_id = ?\n    ");
    $stmt->execute([$usuarioId]);
    $avaliacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $idsConhecidos = [];
    $artistasAfinidade = [];
    $artistasRejeitados = [];
    foreach ($avaliacoes as $a) {
        if (!empty($a['spotify_id'])) $idsConhecidos[$a['spotify_id']] = true;
        $nota = (float)$a['nota'];
        if ($nota >= 4.0 && !empty($a['artista'])) {
            $key = normalizar($a['artista']);
            $artistasAfinidade[$key] = ($artistasAfinidade[$key] ?? ['nome' => $a['artista'], 'peso' => 0.0]);
            $artistasAfinidade[$key]['peso'] += max(0.5, $nota - 3.5) * 2.0;
        } elseif ($nota <= 2.0 && !empty($a['artista'])) {
            $key = normalizar($a['artista']);
            $artistasRejeitados[$key] = ($artistasRejeitados[$key] ?? 0.0) + max(0.5, 2.5 - $nota);
        }
    }

    $clientId = trim((string)getenv('SPOTIFY_CLIENT_ID'));
    $clientSecret = trim((string)getenv('SPOTIFY_CLIENT_SECRET'));
    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('Credenciais do Spotify não configuradas no servidor.');
    }

    $tokenUsuario = tokenSpotifyUsuario($pdo, $usuarioId, $clientId, $clientSecret);
    $spotifyRecentesUsado = false;
    $recentIds = [];
    $recentArtists = [];

    // Histórico recente é opcional. Falhar aqui NÃO impede recomendações.
    if ($tokenUsuario) {
        try {
            $recent = spotifyRequest('https://api.spotify.com/v1/me/player/recently-played?limit=25', $tokenUsuario);
            foreach (($recent['items'] ?? []) as $item) {
                $track = $item['track'] ?? null;
                if (!$track || empty($track['id'])) continue;
                $recentIds[$track['id']] = true;
                foreach (($track['artists'] ?? []) as $artist) {
                    if (empty($artist['name'])) continue;
                    $key = normalizar($artist['name']);
                    $recentArtists[$key] = ($recentArtists[$key] ?? ['nome' => $artist['name'], 'peso' => 0.0]);
                    $recentArtists[$key]['peso'] += 0.8;
                }
            }
            $spotifyRecentesUsado = !empty($recentIds);
        } catch (Throwable $e) {
            error_log('descobertas.php recently played opcional: ' . $e->getMessage());
        }
    }

    foreach ($recentArtists as $key => $info) {
        if (!isset($artistasAfinidade[$key])) $artistasAfinidade[$key] = $info;
        else $artistasAfinidade[$key]['peso'] += $info['peso'];
    }

    // Exclui tanto músicas já avaliadas quanto recém-ouvidas.
    $excluirIds = $idsConhecidos + $recentIds;

    $pool = [];

    // 1) Colaborativo: usuários que gostaram de músicas parecidas.
    $stmt = $pdo->prepare("\n        SELECT other_a.usuario_id, COUNT(*) AS em_comum,\n               AVG(1 - (ABS(self_a.nota - other_a.nota) / 4.0)) AS afinidade\n        FROM avaliacoes self_a\n        JOIN avaliacoes other_a ON other_a.musica_id = self_a.musica_id\n        WHERE self_a.usuario_id = ?\n          AND other_a.usuario_id <> ?\n          AND self_a.nota >= 3.5\n          AND other_a.nota >= 3.5\n        GROUP BY other_a.usuario_id\n        ORDER BY em_comum DESC, afinidade DESC\n        LIMIT 20\n    ");
    $stmt->execute([$usuarioId, $usuarioId]);
    $similares = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $similaridade = [];
    foreach ($similares as $s) {
        $similaridade[(int)$s['usuario_id']] = ((float)$s['afinidade']) * (1 + min(3, (int)$s['em_comum']) * 0.25);
    }

    if ($similaridade) {
        $idsUsuarios = array_keys($similaridade);
        $placeholders = implode(',', array_fill(0, count($idsUsuarios), '?'));
        $sql = "\n            SELECT a.usuario_id, a.nota, m.spotify_id, m.titulo, m.artista, m.capa_url\n            FROM avaliacoes a\n            JOIN musicas m ON m.id = a.musica_id\n            WHERE a.usuario_id IN ($placeholders)\n              AND a.nota >= 4.0\n        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($idsUsuarios);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (string)$row['spotify_id'];
            if ($id === '' || isset($excluirIds[$id])) continue;
            $sim = $similaridade[(int)$row['usuario_id']] ?? 0.5;
            $score = 7.5 + ($sim * 3.0) + (((float)$row['nota'] - 4.0) * 2.0);
            adicionarCandidato($pool, [
                'spotify_id' => $id,
                'track_name' => $row['titulo'],
                'artist_name' => $row['artista'],
                'image_url' => $row['capa_url'],
                'spotify_url' => 'https://open.spotify.com/track/' . $id,
                'duration_ms' => null,
                'release_date' => null,
                'popularity' => null,
                'explicit' => false,
                'album_name' => null,
                'album_type' => null,
            ], $score, 'Pessoas com avaliações parecidas gostaram', 'social');
        }
    }

    // 2) Comunidade: fallback social barato.
    $stmt = $pdo->query("\n        SELECT m.spotify_id, m.titulo, m.artista, m.capa_url, AVG(a.nota) AS media, COUNT(*) AS qtd\n        FROM avaliacoes a\n        JOIN musicas m ON m.id = a.musica_id\n        GROUP BY m.id, m.spotify_id, m.titulo, m.artista, m.capa_url\n        HAVING AVG(a.nota) >= 4.0\n        ORDER BY qtd DESC, media DESC\n        LIMIT 30\n    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string)$row['spotify_id'];
        if ($id === '' || isset($excluirIds[$id])) continue;
        $score = 4.5 + (((float)$row['media'] - 4.0) * 2.0) + min(2.0, ((int)$row['qtd']) * 0.25);
        adicionarCandidato($pool, [
            'spotify_id' => $id,
            'track_name' => $row['titulo'],
            'artist_name' => $row['artista'],
            'image_url' => $row['capa_url'],
            'spotify_url' => 'https://open.spotify.com/track/' . $id,
            'duration_ms' => null,
            'release_date' => null,
            'popularity' => null,
            'explicit' => false,
            'album_name' => null,
            'album_type' => null,
        ], $score, 'Bem avaliada pela comunidade', 'comunidade');
    }

    // Token para busca de catálogo: reutiliza o token do usuário quando possível.
    $catalogToken = $tokenUsuario ?: tokenSpotifyApp($clientId, $clientSecret);

    // Escolhe um gênero e um artista de afinidade, mantendo no máximo 2 search calls.
    $queries = [];
    if ($generos) {
        $indice = abs(crc32($usuarioId . '|' . date('Y-m-d'))) % count($generos);
        $generoEscolhido = $generos[$indice];
        $queries[] = [
            'q' => 'genre:"' . generoSpotify($generoEscolhido) . '"',
            'tipo' => 'genero',
            'rotulo' => $generoEscolhido,
        ];
    }

    if ($artistasAfinidade) {
        uasort($artistasAfinidade, fn($a, $b) => $b['peso'] <=> $a['peso']);
        $favorito = reset($artistasAfinidade);
        if ($favorito && !empty($favorito['nome'])) {
            $queries[] = [
                'q' => 'artist:"' . str_replace('"', '', $favorito['nome']) . '"',
                'tipo' => 'artista',
                'rotulo' => $favorito['nome'],
            ];
        }
    }

    if (count($queries) < 2 && count($generos) > 1) {
        $g2 = $generos[(($indice ?? 0) + 1) % count($generos)];
        $queries[] = [
            'q' => 'genre:"' . generoSpotify($g2) . '"',
            'tipo' => 'genero',
            'rotulo' => $g2,
        ];
    }

    $queries = array_slice($queries, 0, 2);

    foreach ($queries as $qIndex => $query) {
        $seed = abs(crc32($usuarioId . '|' . date('Y-m-d') . '|' . $query['q']));
        // Gêneros têm catálogo amplo; artistas usam offset baixo para não cair em página vazia.
        $offset = $query['tipo'] === 'genero' ? (($seed % 8) * 10) : (($seed % 2) * 5);
        $items = buscarSpotify($catalogToken, $query['q'], $offset);

        foreach ($items as $trackRaw) {
            $track = formatarTrackSpotify($trackRaw);
            if (!$track) continue;
            $id = $track['spotify_id'];
            if (isset($excluirIds[$id])) continue;

            $score = $query['tipo'] === 'genero' ? 6.2 : 6.8;
            $motivo = $query['tipo'] === 'genero'
                ? 'Combina com seu gosto por ' . $query['rotulo']
                : 'Uma faixa diferente de ' . $query['rotulo'];

            // Pequenos boosts, sem deixar um único artista dominar.
            $artistKey = normalizar(explode(',', $track['artist_name'])[0]);
            if (isset($artistasAfinidade[$artistKey])) {
                $score += min(2.0, $artistasAfinidade[$artistKey]['peso'] * 0.25);
            }

            adicionarCandidato($pool, $track, $score, $motivo, $query['tipo']);
        }
    }

    $temSinalPessoal = !empty($generos) || !empty($avaliacoes) || $spotifyRecentesUsado;
    if (!$temSinalPessoal) {
        responder(200, [
            'sucesso' => true,
            'tem_playlist' => false,
            'playlist' => [],
            'precisa_contexto' => true,
            'mensagem' => 'Escolha gêneros no perfil ou avalie algumas músicas para o SocialMusic entender melhor seu gosto.',
        ]);
    }

    // Score extra quando o mesmo candidato veio de múltiplos sinais e ajuste por artistas
    // que o usuário costuma avaliar muito bem ou muito mal.
    foreach ($pool as $id => &$c) {
        $c['_score'] += max(0, count($c['_fontes']) - 1) * 1.4;

        $artistKey = normalizar(explode(',', (string)($c['artist_name'] ?? ''))[0]);
        if (isset($artistasAfinidade[$artistKey])) {
            $c['_score'] += min(1.8, $artistasAfinidade[$artistKey]['peso'] * 0.18);
        }
        if (isset($artistasRejeitados[$artistKey])) {
            $c['_score'] -= min(5.0, 2.0 + ($artistasRejeitados[$artistKey] * 1.2));
        }

        $c['_score'] += (abs(crc32($usuarioId . '|' . date('Y-m-d-H') . '|' . $id)) % 100) / 250.0;
    }
    unset($c);

    uasort($pool, fn($a, $b) => $b['_score'] <=> $a['_score']);

    $idsAnteriores = [];
    if ($cache && !empty($cache['playlist']) && is_array($cache['playlist'])) {
        foreach ($cache['playlist'] as $antiga) {
            if (!empty($antiga['spotify_id'])) $idsAnteriores[$antiga['spotify_id']] = true;
        }
    }

    $selecionadas = [];
    $porArtista = [];
    $adicionarFinal = function(array $c) use (&$selecionadas, &$porArtista): bool {
        if (count($selecionadas) >= 12) return false;
        $artistKey = normalizar(explode(',', (string)$c['artist_name'])[0]);
        if (($porArtista[$artistKey] ?? 0) >= 2) return false;

        $motivo = $c['_motivos'][0] ?? 'Escolhida para ampliar suas descobertas';
        if (count($c['_fontes']) >= 2) $motivo = 'Combina com vários sinais do seu gosto';

        unset($c['_score'], $c['_motivos'], $c['_fontes']);
        $c['motivo'] = $motivo;
        $selecionadas[] = $c;
        $porArtista[$artistKey] = ($porArtista[$artistKey] ?? 0) + 1;
        return true;
    };

    // Ao forçar atualização, tenta primeiro músicas que não estavam na seleção anterior.
    if ($forcar && $idsAnteriores) {
        foreach ($pool as $id => $c) {
            if (isset($idsAnteriores[$id])) continue;
            $adicionarFinal($c);
            if (count($selecionadas) >= 12) break;
        }
    }

    // Completa com o ranking normal.
    if (count($selecionadas) < 12) {
        $ja = array_column($selecionadas, null, 'spotify_id');
        foreach ($pool as $id => $c) {
            if (isset($ja[$id])) continue;
            if ($adicionarFinal($c)) $ja[$id] = true;
            if (count($selecionadas) >= 12) break;
        }
    }

    if (!$selecionadas) {
        responder(200, [
            'sucesso' => true,
            'tem_playlist' => false,
            'playlist' => [],
            'mensagem' => 'Ainda não há dados suficientes para montar uma boa seleção. Avalie mais algumas músicas ou escolha seus gêneros preferidos.',
        ]);
    }

    $payload = [
        'sucesso' => true,
        'tem_playlist' => true,
        'playlist' => array_values($selecionadas),
        'gerado_em' => date(DATE_ATOM),
        'expira_em' => date(DATE_ATOM, time() + (12 * 60 * 60)),
        'cache' => false,
        'contexto' => [
            'generos' => array_values($generos),
            'avaliacoes_consideradas' => count($avaliacoes),
            'spotify_recentes_usado' => $spotifyRecentesUsado,
            'usuarios_parecidos' => count($similares),
        ],
    ];

    salvarCache($pdo, $usuarioId, $payload);
    responder(200, $payload);

} catch (Throwable $e) {
    error_log('descobertas.php: ' . $e->getMessage());
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível gerar suas descobertas agora. Tente novamente em instantes.',
    ]);
}
