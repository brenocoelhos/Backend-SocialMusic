<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

/**
 * SocialMusic - Descobertas v2
 *
 * GET  -> devolve somente o cache válido da playlist personalizada.
 * POST -> gera/atualiza a playlist combinando sinais do próprio SocialMusic,
 *         histórico recente do Spotify (quando autorizado) e Last.fm.
 *
 * Objetivos desta versão:
 * - funcionar mesmo sem Spotify conectado;
 * - usar Last.fm para gêneros/nichos e similaridade entre faixas;
 * - evitar repetir músicas recentemente recomendadas;
 * - limitar chamadas externas com cache compartilhado;
 * - manter diversidade de artistas e fontes;
 * - não armazenar o histórico bruto do Spotify nem dados pessoais do Last.fm.
 */

const RECOMMENDATION_LIMIT = 12;
const RECOMMENDATION_CACHE_SECONDS = 43200; // 12h
const FORCE_COOLDOWN_SECONDS = 300;         // 5 min
const HISTORY_DAYS = 30;
const LASTFM_MAX_NETWORK_CALLS = 4;
const SPOTIFY_RESOLVE_MAX_NETWORK_CALLS = 6;

function responder(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizar(string $texto): string
{
    $texto = function_exists('mb_strtolower')
        ? mb_strtolower(trim($texto), 'UTF-8')
        : strtolower(trim($texto));

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    if ($ascii !== false) $texto = $ascii;

    $texto = preg_replace('/[^a-z0-9]+/i', ' ', $texto) ?? $texto;
    return trim(preg_replace('/\s+/', ' ', $texto) ?? $texto);
}

function artistaPrincipal(string $artista): string
{
    $partes = preg_split('/\s*,\s*|\s+feat\.?\s+|\s+ft\.?\s+/i', $artista) ?: [$artista];
    return trim((string)($partes[0] ?? $artista));
}

function similaridadeTexto(string $a, string $b): float
{
    $a = normalizar($a);
    $b = normalizar($b);
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    if (str_contains($a, $b) || str_contains($b, $a)) return 0.88;

    $max = max(strlen($a), strlen($b));
    if ($max === 0) return 1.0;
    $dist = levenshtein($a, $b);
    return max(0.0, 1.0 - ($dist / $max));
}


function garantirLista($valor): array
{
    if (!is_array($valor) || !$valor) return [];
    if (isset($valor['name'])) return [$valor];
    return $valor;
}

function contemVersaoEspecial(string $titulo): bool
{
    $n = normalizar($titulo);
    foreach (['live', 'remix', 'karaoke', 'sped up', 'slowed', 'acoustic', 'instrumental', 'cover', 'nightcore'] as $termo) {
        if (str_contains($n, normalizar($termo))) return true;
    }
    return false;
}

/* -------------------------------------------------------------------------- */
/* Spotify                                                                    */
/* -------------------------------------------------------------------------- */

function spotifyRequest(string $url, string $token, string $method = 'GET', ?array $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL não disponível no servidor.');
    }

    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        CURLOPT_TIMEOUT => 10,
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

    $expira = !empty($dados['spotify_token_expires']) ? strtotime((string)$dados['spotify_token_expires']) : 0;
    if ($expira > (time() + 60)) return (string)$dados['spotify_access_token'];

    if (empty($dados['spotify_refresh_token'])) return null;

    try {
        $novo = spotifyTokenRequest($clientId, $clientSecret, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $dados['spotify_refresh_token'],
        ]);

        $novoAccess = (string)$novo['access_token'];
        $novoRefresh = (string)($novo['refresh_token'] ?? $dados['spotify_refresh_token']);
        $novoExpira = date('Y-m-d H:i:s', time() + (int)($novo['expires_in'] ?? 3600));

        $upd = $pdo->prepare('UPDATE usuarios SET spotify_access_token = ?, spotify_refresh_token = ?, spotify_token_expires = ? WHERE id = ?');
        $upd->execute([$novoAccess, $novoRefresh, $novoExpira, $usuarioId]);
        return $novoAccess;
    } catch (Throwable $e) {
        error_log('descobertas.php refresh Spotify: ' . $e->getMessage());
        return null;
    }
}

function tokenSpotifyApp(string $clientId, string $clientSecret): ?string
{
    try {
        $dados = spotifyTokenRequest($clientId, $clientSecret, ['grant_type' => 'client_credentials']);
        return (string)$dados['access_token'];
    } catch (Throwable $e) {
        error_log('descobertas.php app token Spotify: ' . $e->getMessage());
        return null;
    }
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

function buscarSpotify(string $token, string $query, int $limit = 10, int $offset = 0): array
{
    $params = http_build_query([
        'q' => $query,
        'type' => 'track',
        'market' => 'BR',
        'limit' => max(1, min(10, $limit)),
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

/* -------------------------------------------------------------------------- */
/* Cache Last.fm                                                              */
/* -------------------------------------------------------------------------- */

function cacheExternoLer(PDO $pdo, string $cacheKey): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT payload, expira_em FROM recomendacoes_api_cache WHERE cache_key = ? LIMIT 1');
        $stmt->execute([$cacheKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || strtotime((string)$row['expira_em']) <= time()) return null;

        $data = json_decode((string)$row['payload'], true);
        return is_array($data) ? $data : null;
    } catch (Throwable $e) {
        // Se a migration ainda não tiver sido executada, o recomendador continua funcionando.
        error_log('descobertas.php cache externo read: ' . $e->getMessage());
        return null;
    }
}

function cacheExternoSalvar(PDO $pdo, string $cacheKey, array $payload, int $ttlSeconds): void
{
    try {
        $expira = date('Y-m-d H:i:s', time() + max(300, $ttlSeconds));
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare(
            'INSERT INTO recomendacoes_api_cache (cache_key, payload, criado_em, expira_em)
             VALUES (?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), criado_em = NOW(), expira_em = VALUES(expira_em)'
        );
        $stmt->execute([$cacheKey, $json, $expira]);
    } catch (Throwable $e) {
        error_log('descobertas.php cache externo write: ' . $e->getMessage());
    }
}

function lastfmTag(string $genero): string
{
    $map = [
        'classica' => 'classical',
        'eletronica' => 'electronic',
        'r b' => 'r&b',
        'hip hop' => 'hip-hop',
        'k pop' => 'k-pop',
        'k r b' => 'k-r&b',
        'j pop' => 'j-pop',
        'j rock' => 'j-rock',
        'lo fi' => 'lo-fi',
        'world music' => 'world',
        'drum and bass' => 'drum and bass',
        'funk carioca' => 'funk carioca',
        'baile funk' => 'baile funk',
        'mpb' => 'mpb',
        'forro' => 'forró',
        'axe' => 'axé',
    ];

    $n = normalizar($genero);
    return $map[$n] ?? trim($genero);
}

function lastfmRequestCached(
    PDO $pdo,
    string $apiKey,
    string $method,
    array $params,
    string $cacheKey,
    int $ttlSeconds,
    int &$networkBudget,
    array &$stats
): ?array {
    $cached = cacheExternoLer($pdo, $cacheKey);
    if ($cached !== null) {
        $stats['recomendacoes_api_cache_hits']++;
        return $cached;
    }

    if ($networkBudget <= 0 || $apiKey === '') return null;
    $networkBudget--;
    $stats['lastfm_network_calls']++;

    $query = array_merge([
        'method' => $method,
        'api_key' => $apiKey,
        'format' => 'json',
    ], $params);

    $url = 'https://ws.audioscrobbler.com/2.0/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $userAgent = trim((string)getenv('LASTFM_USER_AGENT'));
    if ($userAgent === '') {
        $userAgent = 'SocialMusic/2.0 (https://socialmusic.vercel.app)';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 9,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: ' . $userAgent,
        ],
    ]);

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        error_log('descobertas.php Last.fm ' . $method . ': HTTP ' . $status . ' ' . $curlError);
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || isset($json['error'])) {
        $msg = is_array($json) ? ($json['message'] ?? 'erro desconhecido') : 'JSON inválido';
        error_log('descobertas.php Last.fm ' . $method . ': ' . $msg);
        return null;
    }

    cacheExternoSalvar($pdo, $cacheKey, $json, $ttlSeconds);
    return $json;
}

/* -------------------------------------------------------------------------- */
/* Resolução Last.fm -> Spotify                                               */
/* -------------------------------------------------------------------------- */

function trackLocal(PDO $pdo, string $trackName, string $artistName): ?array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT spotify_id, titulo, artista, capa_url
             FROM musicas
             WHERE titulo = ? AND artista LIKE ?
             LIMIT 5'
        );
        $stmt->execute([$trackName, '%' . artistaPrincipal($artistName) . '%']);

        $melhor = null;
        $melhorScore = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (empty($row['spotify_id'])) continue;
            $score = (similaridadeTexto($trackName, (string)$row['titulo']) * 0.65)
                + (similaridadeTexto(artistaPrincipal($artistName), artistaPrincipal((string)$row['artista'])) * 0.35);
            if ($score > $melhorScore) {
                $melhorScore = $score;
                $melhor = $row;
            }
        }

        if ($melhor && $melhorScore >= 0.80) {
            return [
                'spotify_id' => (string)$melhor['spotify_id'],
                'track_name' => (string)$melhor['titulo'],
                'artist_name' => (string)$melhor['artista'],
                'image_url' => $melhor['capa_url'] ?? null,
                'spotify_url' => 'https://open.spotify.com/track/' . $melhor['spotify_id'],
                'duration_ms' => null,
                'release_date' => null,
                'popularity' => null,
                'explicit' => false,
                'album_name' => null,
                'album_type' => null,
            ];
        }
    } catch (Throwable $e) {
        error_log('descobertas.php local resolve: ' . $e->getMessage());
    }

    return null;
}

function resolverLastfmNoSpotify(
    PDO $pdo,
    string $trackName,
    string $artistName,
    ?string $catalogToken,
    int &$spotifyBudget,
    array &$stats
): ?array {
    $normKey = normalizar($artistName) . '|' . normalizar($trackName);
    $cacheKey = 'spotify_resolve:' . hash('sha256', $normKey);

    $cached = cacheExternoLer($pdo, $cacheKey);
    if ($cached !== null) {
        $stats['spotify_resolve_cache_hits']++;
        return !empty($cached['track']) && is_array($cached['track']) ? $cached['track'] : null;
    }

    $local = trackLocal($pdo, $trackName, $artistName);
    if ($local) {
        cacheExternoSalvar($pdo, $cacheKey, ['track' => $local], 30 * 86400);
        $stats['lastfm_resolvidos_local']++;
        return $local;
    }

    if (!$catalogToken || $spotifyBudget <= 0) return null;

    $spotifyBudget--;
    $stats['spotify_resolve_network_calls']++;

    $query = 'track:"' . str_replace('"', '', $trackName) . '" artist:"' . str_replace('"', '', artistaPrincipal($artistName)) . '"';
    $items = buscarSpotify($catalogToken, $query, 5, 0);

    $melhor = null;
    $melhorScore = 0.0;
    $origSpecial = contemVersaoEspecial($trackName);

    foreach ($items as $raw) {
        $track = formatarTrackSpotify($raw);
        if (!$track) continue;

        $titleScore = similaridadeTexto($trackName, (string)$track['track_name']);
        $artistScore = similaridadeTexto(artistaPrincipal($artistName), artistaPrincipal((string)$track['artist_name']));
        $score = ($titleScore * 0.68) + ($artistScore * 0.32);

        if (!$origSpecial && contemVersaoEspecial((string)$track['track_name'])) {
            $score -= 0.18;
        }

        if ($score > $melhorScore) {
            $melhorScore = $score;
            $melhor = $track;
        }
    }

    if ($melhor && $melhorScore >= 0.72) {
        cacheExternoSalvar($pdo, $cacheKey, ['track' => $melhor], 30 * 86400);
        $stats['lastfm_resolvidos_spotify']++;
        return $melhor;
    }

    // Cache negativo curto para não repetir uma busca que acabou de falhar.
    cacheExternoSalvar($pdo, $cacheKey, ['track' => null], 6 * 3600);
    return null;
}

/* -------------------------------------------------------------------------- */
/* Pool e ranking                                                             */
/* -------------------------------------------------------------------------- */

function adicionarCandidato(
    array &$pool,
    array $track,
    float $score,
    string $motivo,
    string $fonte,
    string $categoria = 'core'
): void {
    $id = trim((string)($track['spotify_id'] ?? ''));
    if ($id === '') return;

    if (!isset($pool[$id])) {
        $pool[$id] = $track + [
            '_score' => 0.0,
            '_fontes' => [],
            '_sinais' => [],
            '_categorias' => [],
        ];
    }

    $pool[$id]['_score'] += $score;
    $pool[$id]['_fontes'][$fonte] = true;
    $pool[$id]['_categorias'][$categoria] = true;
    $pool[$id]['_sinais'][] = [
        'score' => $score,
        'motivo' => $motivo,
        'fonte' => $fonte,
        'categoria' => $categoria,
    ];

    foreach (['track_name', 'artist_name', 'image_url', 'spotify_url', 'duration_ms', 'release_date', 'popularity', 'explicit', 'album_name', 'album_type'] as $campo) {
        if (empty($pool[$id][$campo]) && !empty($track[$campo])) {
            $pool[$id][$campo] = $track[$campo];
        }
    }
}

function motivoFinal(array $c): string
{
    $sinais = $c['_sinais'] ?? [];
    if (!$sinais) return 'Escolhida para ampliar suas descobertas';

    usort($sinais, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $top = (string)($sinais[0]['motivo'] ?? 'Escolhida para ampliar suas descobertas');

    $fontes = array_keys($c['_fontes'] ?? []);
    if (count($fontes) >= 3) {
        return 'Combina com vários sinais do seu gosto';
    }

    return $top;
}

function cacheValido(PDO $pdo, int $usuarioId): ?array
{
    $stmt = $pdo->prepare('SELECT payload, gerado_em, expira_em FROM recomendacoes_cache WHERE usuario_id = ? LIMIT 1');
    $stmt->execute([$usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $payload = json_decode((string)$row['payload'], true);
    if (!is_array($payload)) return null;

    $payload['_gerado_em_db'] = $row['gerado_em'];
    $payload['_expira_em_db'] = $row['expira_em'];
    $versaoCompativel = (($payload['contexto']['algoritmo'] ?? null) === 'v2');
    $payload['_valido'] = $versaoCompativel && strtotime((string)$row['expira_em']) > time();
    return $payload;
}

function salvarCache(PDO $pdo, int $usuarioId, array $payload): void
{
    $expira = date('Y-m-d H:i:s', time() + RECOMMENDATION_CACHE_SECONDS);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $pdo->prepare(
        'INSERT INTO recomendacoes_cache (usuario_id, payload, gerado_em, expira_em)
         VALUES (?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), gerado_em = NOW(), expira_em = VALUES(expira_em)'
    );
    $stmt->execute([$usuarioId, $json, $expira]);
}

function historicoRecomendacoes(PDO $pdo, int $usuarioId): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT spotify_id, recomendada_em
             FROM recomendacoes_historico
             WHERE usuario_id = ? AND recomendada_em >= DATE_SUB(NOW(), INTERVAL ' . HISTORY_DAYS . ' DAY)'
        );
        $stmt->execute([$usuarioId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string)$row['spotify_id']] = strtotime((string)$row['recomendada_em']) ?: 0;
        }
        return $map;
    } catch (Throwable $e) {
        error_log('descobertas.php histórico read: ' . $e->getMessage());
        return [];
    }
}

function salvarHistorico(PDO $pdo, int $usuarioId, array $playlist): void
{
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO recomendacoes_historico (usuario_id, spotify_id, recomendada_em)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE recomendada_em = NOW()'
        );

        foreach ($playlist as $item) {
            $id = trim((string)($item['spotify_id'] ?? ''));
            if ($id !== '') $stmt->execute([$usuarioId, $id]);
        }

        // Limpeza oportunista barata.
        $pdo->exec('DELETE FROM recomendacoes_historico WHERE recomendada_em < DATE_SUB(NOW(), INTERVAL 45 DAY)');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('descobertas.php histórico write: ' . $e->getMessage());
    }
}

function penalidadeHistorico(?int $timestamp): float
{
    if (!$timestamp) return 0.0;
    $dias = (time() - $timestamp) / 86400;
    if ($dias <= 3) return 10.0;
    if ($dias <= 7) return 6.5;
    if ($dias <= 14) return 3.5;
    if ($dias <= 30) return 1.5;
    return 0.0;
}

/* -------------------------------------------------------------------------- */
/* Autenticação e cache                                                       */
/* -------------------------------------------------------------------------- */

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

if ($method !== 'POST') {
    responder(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$forcar = !empty($input['forcar']);

if ($cache && !empty($cache['_valido']) && !$forcar) {
    unset($cache['_valido'], $cache['_gerado_em_db'], $cache['_expira_em_db']);
    $cache['cache'] = true;
    responder(200, $cache);
}

if ($cache && $forcar && !empty($cache['_gerado_em_db'])) {
    $idade = time() - (strtotime((string)$cache['_gerado_em_db']) ?: 0);
    if ($idade < FORCE_COOLDOWN_SECONDS) {
        unset($cache['_valido'], $cache['_gerado_em_db'], $cache['_expira_em_db']);
        $cache['cache'] = true;
        $cache['mensagem'] = 'Sua seleção acabou de ser atualizada. Tente novamente em alguns minutos.';
        $cache['cooldown'] = true;
        responder(200, $cache);
    }
}

/* -------------------------------------------------------------------------- */
/* Geração                                                                    */
/* -------------------------------------------------------------------------- */

try {
    $stats = [
        'lastfm_network_calls' => 0,
        'recomendacoes_api_cache_hits' => 0,
        'spotify_resolve_network_calls' => 0,
        'spotify_resolve_cache_hits' => 0,
        'lastfm_resolvidos_local' => 0,
        'lastfm_resolvidos_spotify' => 0,
    ];

    $stmt = $pdo->prepare('SELECT generos, spotify_conectado FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$usuarioId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) responder(404, ['sucesso' => false, 'mensagem' => 'Usuário não encontrado.']);

    $generos = array_values(array_filter(array_map('trim', explode(',', (string)($usuario['generos'] ?? '')))));

    /* Avaliações do próprio usuário ---------------------------------------- */
    $stmt = $pdo->prepare(
        'SELECT a.nota, m.spotify_id, m.titulo, m.artista, m.capa_url
         FROM avaliacoes a
         JOIN musicas m ON m.id = a.musica_id
         WHERE a.usuario_id = ?
         ORDER BY a.data_criacao DESC'
    );
    $stmt->execute([$usuarioId]);
    $avaliacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $idsConhecidos = [];
    $artistasAfinidade = [];
    $artistasRejeitados = [];
    $seedTracks = [];

    foreach ($avaliacoes as $a) {
        $id = trim((string)($a['spotify_id'] ?? ''));
        if ($id !== '') $idsConhecidos[$id] = true;

        $nota = (float)$a['nota'];
        $artista = trim((string)($a['artista'] ?? ''));
        $artistKey = normalizar(artistaPrincipal($artista));

        if ($nota >= 4.0 && $artistKey !== '') {
            if (!isset($artistasAfinidade[$artistKey])) {
                $artistasAfinidade[$artistKey] = ['nome' => artistaPrincipal($artista), 'peso' => 0.0];
            }
            $artistasAfinidade[$artistKey]['peso'] += 1.0 + (($nota - 4.0) * 2.5);
        }

        if ($nota <= 2.0 && $artistKey !== '') {
            $artistasRejeitados[$artistKey] = ($artistasRejeitados[$artistKey] ?? 0.0) + (2.5 - $nota);
        }

        if ($nota >= 4.25 && !empty($a['titulo']) && !empty($a['artista'])) {
            $seedTracks[] = [
                'track' => (string)$a['titulo'],
                'artist' => artistaPrincipal((string)$a['artista']),
                'nota' => $nota,
                'origem' => 'avaliacao',
            ];
        }
    }

    usort($seedTracks, fn($a, $b) => $b['nota'] <=> $a['nota']);

    /* Spotify opcional ------------------------------------------------------ */
    $clientId = trim((string)getenv('SPOTIFY_CLIENT_ID'));
    $clientSecret = trim((string)getenv('SPOTIFY_CLIENT_SECRET'));
    $tokenUsuario = null;
    $catalogToken = null;

    if ($clientId !== '' && $clientSecret !== '') {
        $tokenUsuario = tokenSpotifyUsuario($pdo, $usuarioId, $clientId, $clientSecret);
        $catalogToken = $tokenUsuario ?: tokenSpotifyApp($clientId, $clientSecret);
    }

    $spotifyRecentesUsado = false;
    $recentIds = [];
    $recentArtists = [];
    $recentTrackSignals = [];

    if ($tokenUsuario) {
        try {
            $recent = spotifyRequest('https://api.spotify.com/v1/me/player/recently-played?limit=30', $tokenUsuario);
            foreach (($recent['items'] ?? []) as $item) {
                $track = $item['track'] ?? null;
                if (!$track || empty($track['id'])) continue;

                $recentId = (string)$track['id'];
                $recentIds[$recentId] = true;

                $recentName = trim((string)($track['name'] ?? ''));
                $recentArtistName = trim((string)($track['artists'][0]['name'] ?? ''));
                if ($recentName !== '' && $recentArtistName !== '') {
                    if (!isset($recentTrackSignals[$recentId])) {
                        $recentTrackSignals[$recentId] = [
                            'track' => $recentName,
                            'artist' => artistaPrincipal($recentArtistName),
                            'count' => 0,
                        ];
                    }
                    $recentTrackSignals[$recentId]['count']++;
                }

                foreach (($track['artists'] ?? []) as $artist) {
                    if (empty($artist['name'])) continue;
                    $key = normalizar(artistaPrincipal((string)$artist['name']));
                    if ($key === '') continue;
                    if (!isset($recentArtists[$key])) {
                        $recentArtists[$key] = ['nome' => artistaPrincipal((string)$artist['name']), 'peso' => 0.0];
                    }
                    $recentArtists[$key]['peso'] += 0.35;
                }
            }
            $spotifyRecentesUsado = !empty($recentIds);
        } catch (Throwable $e) {
            error_log('descobertas.php recently played opcional: ' . $e->getMessage());
        }
    }

    foreach ($recentArtists as $key => $info) {
        if (!isset($artistasAfinidade[$key])) $artistasAfinidade[$key] = $info;
        else $artistasAfinidade[$key]['peso'] += min(1.5, $info['peso']);
    }

    // Repetir uma faixa no histórico recente é um sinal um pouco mais forte do que
    // simplesmente ouvi-la uma vez. Usamos no máximo uma dessas faixas como seed
    // fraca quando faltam avaliações fortes, sem armazenar o histórico bruto.
    if (count($seedTracks) < 2 && $recentTrackSignals) {
        uasort($recentTrackSignals, fn($a, $b) => $b['count'] <=> $a['count']);
        foreach ($recentTrackSignals as $signal) {
            if ((int)$signal['count'] < 2) break;
            $seedTracks[] = [
                'track' => (string)$signal['track'],
                'artist' => (string)$signal['artist'],
                'nota' => 4.25,
                'origem' => 'spotify_recent',
            ];
            break;
        }
        usort($seedTracks, fn($a, $b) => $b['nota'] <=> $a['nota']);
    }

    $excluirIds = $idsConhecidos + $recentIds;
    $historico = historicoRecomendacoes($pdo, $usuarioId);
    $pool = [];

    /* 1) Collaborative filtering melhorado -------------------------------- */
    $stmt = $pdo->prepare(
        'SELECT other_a.usuario_id,
                COUNT(*) AS em_comum,
                AVG(1 - LEAST(ABS(self_a.nota - other_a.nota) / 4.0, 1)) AS afinidade
         FROM avaliacoes self_a
         JOIN avaliacoes other_a ON other_a.musica_id = self_a.musica_id
         WHERE self_a.usuario_id = ?
           AND other_a.usuario_id <> ?
         GROUP BY other_a.usuario_id
         HAVING COUNT(*) >= 1
         ORDER BY (afinidade * LN(1 + COUNT(*))) DESC
         LIMIT 30'
    );
    $stmt->execute([$usuarioId, $usuarioId]);
    $similares = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $similaridade = [];
    foreach ($similares as $s) {
        $afinidade = (float)$s['afinidade'];
        $comum = (int)$s['em_comum'];
        if ($afinidade < 0.52) continue;
        $similaridade[(int)$s['usuario_id']] = [
            'score' => $afinidade * (1 + min(5, $comum) * 0.18),
            'comum' => $comum,
        ];
    }

    if ($similaridade) {
        $idsUsuarios = array_keys($similaridade);
        $placeholders = implode(',', array_fill(0, count($idsUsuarios), '?'));
        $stmt = $pdo->prepare(
            "SELECT a.usuario_id, a.nota, m.spotify_id, m.titulo, m.artista, m.capa_url
             FROM avaliacoes a
             JOIN musicas m ON m.id = a.musica_id
             WHERE a.usuario_id IN ($placeholders)
               AND a.nota >= 4.0"
        );
        $stmt->execute($idsUsuarios);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = trim((string)$row['spotify_id']);
            if ($id === '' || isset($excluirIds[$id])) continue;

            $sim = $similaridade[(int)$row['usuario_id']] ?? ['score' => 0.5, 'comum' => 1];
            $score = 6.8 + ($sim['score'] * 3.2) + min(1.5, max(0.0, ((float)$row['nota'] - 4.0) * 2.0));

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
            ], $score, 'Pessoas com gosto parecido gostaram', 'social', 'core');
        }
    }

    /* 2) Comunidade --------------------------------------------------------- */
    $stmt = $pdo->query(
        'SELECT m.spotify_id, m.titulo, m.artista, m.capa_url,
                AVG(a.nota) AS media, COUNT(*) AS qtd
         FROM avaliacoes a
         JOIN musicas m ON m.id = a.musica_id
         GROUP BY m.id, m.spotify_id, m.titulo, m.artista, m.capa_url
         HAVING AVG(a.nota) >= 4.0
         ORDER BY qtd DESC, media DESC
         LIMIT 40'
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = trim((string)$row['spotify_id']);
        if ($id === '' || isset($excluirIds[$id])) continue;

        $score = 4.0 + (((float)$row['media'] - 4.0) * 2.0) + min(2.0, ((int)$row['qtd']) * 0.20);
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
        ], $score, 'Bem avaliada pela comunidade', 'comunidade', 'social');
    }

    /* 3) Last.fm ------------------------------------------------------------ */
    $lastfmApiKey = trim((string)getenv('LASTFM_API_KEY'));
    $lastfmBudget = LASTFM_MAX_NETWORK_CALLS;
    $spotifyResolveBudget = SPOTIFY_RESOLVE_MAX_NETWORK_CALLS;
    $lastfmRawCandidates = [];

    $addLastfmRaw = function(string $track, string $artist, float $score, string $motivo, string $categoria, string $fonteMeta = '') use (&$lastfmRawCandidates): void {
        $track = trim($track);
        $artist = trim($artist);
        if ($track === '' || $artist === '') return;

        $key = normalizar($artist) . '|' . normalizar($track);
        if ($key === '|') return;

        if (!isset($lastfmRawCandidates[$key])) {
            $lastfmRawCandidates[$key] = [
                'track' => $track,
                'artist' => $artist,
                'score' => 0.0,
                'sinais' => [],
                'categorias' => [],
            ];
        }

        $lastfmRawCandidates[$key]['score'] += $score;
        $lastfmRawCandidates[$key]['sinais'][] = [
            'score' => $score,
            'motivo' => $motivo,
            'categoria' => $categoria,
            'meta' => $fonteMeta,
        ];
        $lastfmRawCandidates[$key]['categorias'][$categoria] = true;
    };

    $geracaoSeed = $forcar
        ? ($usuarioId . '|' . date('Y-m-d-H') . '|' . intdiv((int)date('i'), 5))
        : ($usuarioId . '|' . date('Y-m-d'));

    $generosEscolhidos = [];
    if ($generos) {
        $indice = abs(crc32($geracaoSeed . '|genre')) % count($generos);
        $generosEscolhidos[] = $generos[$indice];
        if (count($generos) > 1) {
            $generosEscolhidos[] = $generos[($indice + 1 + (abs(crc32($geracaoSeed . '|g2')) % (count($generos) - 1))) % count($generos)];
            $generosEscolhidos = array_values(array_unique($generosEscolhidos));
        }
    }

    if ($lastfmApiKey !== '') {
        // 3A) Top tracks de até dois gêneros escolhidos no perfil.
        foreach (array_slice($generosEscolhidos, 0, 2) as $gIndex => $genero) {
            $tag = lastfmTag($genero);
            $cacheKey = 'lastfm:tag_top:' . hash('sha256', normalizar($tag));
            $resp = lastfmRequestCached(
                $pdo,
                $lastfmApiKey,
                'tag.getTopTracks',
                ['tag' => $tag, 'limit' => 16, 'page' => 1],
                $cacheKey,
                24 * 3600,
                $lastfmBudget,
                $stats
            );

            $tracks = garantirLista($resp['tracks']['track'] ?? []);
            foreach ($tracks as $idx => $lfmTrack) {
                $name = trim((string)($lfmTrack['name'] ?? ''));
                $artist = trim((string)($lfmTrack['artist']['name'] ?? ''));
                if ($name === '' || $artist === '') continue;

                $rank = (int)($lfmTrack['@attr']['rank'] ?? ($idx + 1));
                $rankBonus = max(0.0, 2.0 - (($rank - 1) * 0.10));
                $addLastfmRaw(
                    $name,
                    $artist,
                    7.0 + $rankBonus,
                    'Combina com seu gosto por ' . $genero,
                    'genre',
                    $genero
                );
            }
        }

        // 3B) Similaridade por faixa: até duas sementes muito bem avaliadas.
        $seedUsadas = array_slice($seedTracks, 0, 2);
        foreach ($seedUsadas as $seed) {
            $cacheKey = 'lastfm:track_similar:' . hash('sha256', normalizar($seed['artist']) . '|' . normalizar($seed['track']));
            $resp = lastfmRequestCached(
                $pdo,
                $lastfmApiKey,
                'track.getSimilar',
                [
                    'artist' => $seed['artist'],
                    'track' => $seed['track'],
                    'autocorrect' => 1,
                    'limit' => 16,
                ],
                $cacheKey,
                7 * 86400,
                $lastfmBudget,
                $stats
            );

            $tracks = garantirLista($resp['similartracks']['track'] ?? []);
            foreach ($tracks as $lfmTrack) {
                $name = trim((string)($lfmTrack['name'] ?? ''));
                $artist = trim((string)($lfmTrack['artist']['name'] ?? ''));
                if ($name === '' || $artist === '') continue;

                $match = max(0.0, min(1.0, (float)($lfmTrack['match'] ?? 0.0)));
                $seedSpotifyRecent = (($seed['origem'] ?? 'avaliacao') === 'spotify_recent');
                $score = $seedSpotifyRecent
                    ? (6.8 + ($match * 3.6))
                    : (8.0 + ($match * 4.5) + max(0.0, ((float)$seed['nota'] - 4.25) * 1.5));
                $motivoSeed = $seedSpotifyRecent
                    ? 'Parecida com algo que você ouviu recentemente'
                    : ('Parecida com ' . $seed['track'] . ', que você avaliou muito bem');

                $addLastfmRaw(
                    $name,
                    $artist,
                    $score,
                    $motivoSeed,
                    'similar',
                    $seed['track']
                );
            }
        }

        // 3C) Usuário novo / sem seed forte: explora uma tag relacionada.
        if (!$seedTracks && !empty($generosEscolhidos) && $lastfmBudget >= 2) {
            $principal = $generosEscolhidos[0];
            $tag = lastfmTag($principal);
            $similarResp = lastfmRequestCached(
                $pdo,
                $lastfmApiKey,
                'tag.getSimilar',
                ['tag' => $tag],
                'lastfm:tag_similar:' . hash('sha256', normalizar($tag)),
                7 * 86400,
                $lastfmBudget,
                $stats
            );

            $relatedTags = garantirLista($similarResp['similartags']['tag'] ?? []);
            $related = null;
            foreach ($relatedTags as $item) {
                $candidate = trim((string)($item['name'] ?? ''));
                if ($candidate === '' || normalizar($candidate) === normalizar($tag)) continue;
                $related = $candidate;
                break;
            }

            if ($related && $lastfmBudget > 0) {
                $relatedResp = lastfmRequestCached(
                    $pdo,
                    $lastfmApiKey,
                    'tag.getTopTracks',
                    ['tag' => $related, 'limit' => 14, 'page' => 1],
                    'lastfm:tag_top:' . hash('sha256', normalizar($related)),
                    24 * 3600,
                    $lastfmBudget,
                    $stats
                );

                foreach (garantirLista($relatedResp['tracks']['track'] ?? []) as $idx => $lfmTrack) {
                    $name = trim((string)($lfmTrack['name'] ?? ''));
                    $artist = trim((string)($lfmTrack['artist']['name'] ?? ''));
                    if ($name === '' || $artist === '') continue;

                    $rank = (int)($lfmTrack['@attr']['rank'] ?? ($idx + 1));
                    $rankBonus = max(0.0, 1.4 - (($rank - 1) * 0.08));
                    $addLastfmRaw(
                        $name,
                        $artist,
                        5.6 + $rankBonus,
                        'Uma descoberta próxima de ' . $principal,
                        'explore',
                        $related
                    );
                }
            }
        }
    }

    // Resolve somente os melhores candidatos do Last.fm para Spotify.
    uasort($lastfmRawCandidates, fn($a, $b) => $b['score'] <=> $a['score']);
    $lastfmResolvidos = 0;

    foreach ($lastfmRawCandidates as $rawCandidate) {
        if ($lastfmResolvidos >= 10) break;

        $track = resolverLastfmNoSpotify(
            $pdo,
            (string)$rawCandidate['track'],
            (string)$rawCandidate['artist'],
            $catalogToken,
            $spotifyResolveBudget,
            $stats
        );

        if (!$track || empty($track['spotify_id'])) continue;
        $id = (string)$track['spotify_id'];
        if (isset($excluirIds[$id])) continue;

        usort($rawCandidate['sinais'], fn($a, $b) => $b['score'] <=> $a['score']);
        $sinal = $rawCandidate['sinais'][0] ?? null;
        $motivo = $sinal['motivo'] ?? 'Descoberta encontrada pelo Last.fm';
        $categoria = $sinal['categoria'] ?? 'genre';

        adicionarCandidato(
            $pool,
            $track,
            (float)$rawCandidate['score'],
            $motivo,
            'lastfm',
            $categoria
        );
        $lastfmResolvidos++;
    }

    /* 4) Uma busca direta por artista favorito ----------------------------- */
    if ($catalogToken && $artistasAfinidade) {
        uasort($artistasAfinidade, fn($a, $b) => $b['peso'] <=> $a['peso']);
        $favorito = reset($artistasAfinidade);
        if ($favorito && !empty($favorito['nome'])) {
            $query = 'artist:"' . str_replace('"', '', (string)$favorito['nome']) . '"';
            $offset = (abs(crc32($geracaoSeed . '|artist|' . $favorito['nome'])) % 2) * 5;
            foreach (buscarSpotify($catalogToken, $query, 10, $offset) as $rawTrack) {
                $track = formatarTrackSpotify($rawTrack);
                if (!$track || isset($excluirIds[$track['spotify_id']])) continue;
                adicionarCandidato(
                    $pool,
                    $track,
                    6.2,
                    'Uma faixa diferente de ' . $favorito['nome'],
                    'spotify',
                    'artist'
                );
            }
        }
    }

    /* Sem contexto ---------------------------------------------------------- */
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

    /* Ajustes finais de score ---------------------------------------------- */
    $idsAnteriores = [];
    if ($cache && !empty($cache['playlist']) && is_array($cache['playlist'])) {
        foreach ($cache['playlist'] as $antiga) {
            if (!empty($antiga['spotify_id'])) $idsAnteriores[(string)$antiga['spotify_id']] = true;
        }
    }

    foreach ($pool as $id => &$c) {
        // Vários sinais independentes aumentam confiança.
        $c['_score'] += max(0, count($c['_fontes']) - 1) * 1.5;

        $artistKey = normalizar(artistaPrincipal((string)($c['artist_name'] ?? '')));
        if (isset($artistasAfinidade[$artistKey])) {
            $c['_score'] += min(2.5, $artistasAfinidade[$artistKey]['peso'] * 0.28);
        }

        if (isset($artistasRejeitados[$artistKey])) {
            $c['_score'] -= min(8.0, 3.0 + ($artistasRejeitados[$artistKey] * 1.8));
        }

        // Evita repetir o que foi recomendado nos últimos 30 dias.
        $c['_score'] -= penalidadeHistorico($historico[$id] ?? null);

        // Ao pedir novas sugestões, a seleção anterior recebe penalidade forte.
        if ($forcar && isset($idsAnteriores[$id])) {
            $c['_score'] -= 12.0;
        }

        // Leve bônus de novidade de artista.
        if ($artistKey !== '' && !isset($artistasAfinidade[$artistKey])) {
            $c['_score'] += 0.55;
        }

        // Desempate estável por usuário/janela de geração.
        $c['_score'] += (abs(crc32($geracaoSeed . '|' . $id)) % 100) / 300.0;
    }
    unset($c);

    /* Seleção diversificada (greedy) --------------------------------------- */
    $selecionadas = [];
    $selecionadasIds = [];
    $porArtista = [];
    $porFonte = [];
    $temExplore = false;

    while (count($selecionadas) < RECOMMENDATION_LIMIT) {
        $melhorId = null;
        $melhorAjustado = -INF;

        foreach ($pool as $id => $c) {
            if (isset($selecionadasIds[$id])) continue;

            $artistKey = normalizar(artistaPrincipal((string)($c['artist_name'] ?? '')));
            if (($porArtista[$artistKey] ?? 0) >= 2) continue;

            $sinais = $c['_sinais'] ?? [];
            usort($sinais, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $fontePrincipal = (string)($sinais[0]['fonte'] ?? 'outros');
            $categorias = array_keys($c['_categorias'] ?? []);

            $ajustado = (float)$c['_score'];
            $ajustado -= ($porArtista[$artistKey] ?? 0) * 4.5;
            $ajustado -= ($porFonte[$fontePrincipal] ?? 0) * 0.55;

            if (($porFonte[$fontePrincipal] ?? 0) === 0) $ajustado += 0.45;
            if (!$temExplore && in_array('explore', $categorias, true) && count($selecionadas) >= 6) {
                $ajustado += 1.6;
            }

            if ($ajustado > $melhorAjustado) {
                $melhorAjustado = $ajustado;
                $melhorId = $id;
            }
        }

        if ($melhorId === null) break;

        $c = $pool[$melhorId];
        $artistKey = normalizar(artistaPrincipal((string)($c['artist_name'] ?? '')));
        $sinais = $c['_sinais'] ?? [];
        usort($sinais, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $fontePrincipal = (string)($sinais[0]['fonte'] ?? 'outros');
        $categorias = array_keys($c['_categorias'] ?? []);

        $c['motivo'] = motivoFinal($c);
        $c['origem_recomendacao'] = $fontePrincipal;
        unset($c['_score'], $c['_fontes'], $c['_sinais'], $c['_categorias']);

        $selecionadas[] = $c;
        $selecionadasIds[$melhorId] = true;
        $porArtista[$artistKey] = ($porArtista[$artistKey] ?? 0) + 1;
        $porFonte[$fontePrincipal] = ($porFonte[$fontePrincipal] ?? 0) + 1;
        if (in_array('explore', $categorias, true)) $temExplore = true;
    }

    if (!$selecionadas) {
        responder(200, [
            'sucesso' => true,
            'tem_playlist' => false,
            'playlist' => [],
            'mensagem' => 'Ainda não há dados suficientes para montar uma boa seleção. Avalie mais músicas ou escolha seus gêneros preferidos.',
        ]);
    }

    $payload = [
        'sucesso' => true,
        'tem_playlist' => true,
        'playlist' => array_values($selecionadas),
        'gerado_em' => date(DATE_ATOM),
        'expira_em' => date(DATE_ATOM, time() + RECOMMENDATION_CACHE_SECONDS),
        'cache' => false,
        'contexto' => [
            'algoritmo' => 'v2',
            'generos' => array_values($generos),
            'generos_usados_nesta_geracao' => array_values($generosEscolhidos),
            'avaliacoes_consideradas' => count($avaliacoes),
            'spotify_recentes_usado' => $spotifyRecentesUsado,
            'usuarios_parecidos' => count($similaridade),
            'lastfm_usado' => $lastfmResolvidos > 0,
            'lastfm_candidatos_resolvidos' => $lastfmResolvidos,
            'historico_30_dias_usado' => !empty($historico),
            'fontes_na_playlist' => array_keys($porFonte),
        ],
        // Métricas técnicas úteis em desenvolvimento/apresentação, sem dados pessoais.
        'diagnostico' => $stats,
    ];

    salvarCache($pdo, $usuarioId, $payload);
    salvarHistorico($pdo, $usuarioId, $selecionadas);
    responder(200, $payload);

} catch (Throwable $e) {
    error_log('descobertas.php v2: ' . $e->getMessage());
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível gerar suas descobertas agora. Tente novamente em instantes.',
    ]);
}
