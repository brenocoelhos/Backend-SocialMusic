<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

/**
 * Localiza um vídeo do YouTube para a música exibida no SocialMusic.
 *
 * Estratégia:
 * 1. Consulta o cache MySQL.
 * 2. Resultado encontrado fica válido por 29 dias.
 * 3. Resultado "não encontrado" fica em cache por 6 horas.
 * 4. Só quando necessário faz 1 search.list no YouTube.
 * 5. Valida os candidatos em uma única chamada videos.list e usa duração,
 *    título, artista e palavras-chave para escolher o melhor resultado.
 *
 * A chave fica SOMENTE no backend, na variável de ambiente YOUTUBE_API_KEY.
 */

function responder(int $statusHttp, array $payload): void
{
    http_response_code($statusHttp);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function texto_minusculo(string $texto): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($texto, 'UTF-8')
        : strtolower($texto);
}

function normalizar_texto(string $texto): string
{
    $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texto = texto_minusculo($texto);

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    if ($ascii !== false) {
        $texto = $ascii;
    }

    $texto = preg_replace('/[^a-z0-9]+/i', ' ', $texto) ?? $texto;
    $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;
    return trim($texto);
}

function contem(string $texto, string $trecho): bool
{
    if ($trecho === '') return false;
    return strpos($texto, $trecho) !== false;
}

function tokens_relevantes(string $texto): array
{
    $ignorados = [
        'a', 'o', 'e', 'de', 'da', 'do', 'das', 'dos', 'the', 'and', 'of', 'in',
        'feat', 'ft', 'featuring', 'with', 'official', 'audio', 'video'
    ];

    $tokens = array_filter(explode(' ', normalizar_texto($texto)), function ($token) use ($ignorados) {
        return strlen($token) >= 2 && !in_array($token, $ignorados, true);
    });

    return array_values(array_unique($tokens));
}

function duracao_iso_para_segundos(?string $iso): ?int
{
    if (!$iso) return null;

    try {
        $intervalo = new DateInterval($iso);
        $dias = $intervalo->days !== false ? $intervalo->days : 0;
        return ($dias * 86400)
            + ($intervalo->h * 3600)
            + ($intervalo->i * 60)
            + $intervalo->s;
    } catch (Exception $e) {
        return null;
    }
}

function video_bloqueado_no_brasil(array $video): bool
{
    $restricao = $video['contentDetails']['regionRestriction'] ?? null;
    if (!$restricao) return false;

    if (!empty($restricao['blocked']) && in_array('BR', $restricao['blocked'], true)) {
        return true;
    }

    if (!empty($restricao['allowed']) && !in_array('BR', $restricao['allowed'], true)) {
        return true;
    }

    return false;
}

function pontuar_video(array $video, string $trackName, string $artistName, ?int $duracaoAlvo): float
{
    $tituloOriginal = (string)($video['snippet']['title'] ?? '');
    $canalOriginal = (string)($video['snippet']['channelTitle'] ?? '');

    $titulo = normalizar_texto($tituloOriginal);
    $canal = normalizar_texto($canalOriginal);
    $faixa = normalizar_texto($trackName);
    $artista = normalizar_texto($artistName);

    $score = 0.0;

    // Correspondência forte do nome completo.
    if ($faixa !== '' && contem($titulo, $faixa)) $score += 10;
    if ($artista !== '' && contem($titulo, $artista)) $score += 7;
    if ($artista !== '' && contem($canal, $artista)) $score += 6;

    // Cobertura por palavras ajuda quando o YouTube reorganiza pontuação/parênteses.
    foreach (tokens_relevantes($trackName) as $token) {
        if (contem($titulo, $token)) $score += 1.6;
    }
    foreach (tokens_relevantes($artistName) as $token) {
        if (contem($titulo, $token) || contem($canal, $token)) $score += 1.2;
    }

    // Sinais comuns de upload oficial.
    if (contem($titulo, 'official audio')) $score += 5;
    if (contem($titulo, 'official video')) $score += 4;
    if (contem($titulo, 'official music video')) $score += 4;
    if (contem($canal, 'vevo')) $score += 4;
    if (preg_match('/\btopic\b/', $canal)) $score += 5;

    // Penaliza versões que normalmente não correspondem à gravação da página.
    $negativosFortes = ['cover', 'karaoke', 'reaction', 'reacts', 'nightcore'];
    foreach ($negativosFortes as $termo) {
        if (contem($titulo, $termo) && !contem($faixa, $termo)) $score -= 14;
    }

    $negativos = ['slowed', 'sped up', 'speed up', '8d audio', 'instrumental'];
    foreach ($negativos as $termo) {
        if (contem($titulo, $termo) && !contem($faixa, $termo)) $score -= 9;
    }

    if (contem($titulo, 'remix') && !contem($faixa, 'remix')) $score -= 7;
    if (contem($titulo, 'live') && !contem($faixa, 'live')) $score -= 5;
    if (contem($titulo, 'lyrics') || contem($titulo, 'lyric')) $score -= 2;

    // A duração do Spotify é um ótimo desempate para músicas com muitos uploads.
    $duracaoVideo = duracao_iso_para_segundos($video['contentDetails']['duration'] ?? null);
    if ($duracaoAlvo && $duracaoVideo) {
        $diferenca = abs($duracaoVideo - $duracaoAlvo);

        if ($diferenca <= 4) $score += 10;
        elseif ($diferenca <= 10) $score += 7;
        elseif ($diferenca <= 20) $score += 4;
        elseif ($diferenca <= 40) $score += 1;
        elseif ($diferenca >= 90) $score -= 10;
        else $score -= 3;
    }

    return $score;
}

function youtube_get(string $url, int $timeout = 8): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL do PHP não está disponível no servidor.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'SocialMusic/1.0',
    ]);

    $body = curl_exec($ch);
    $erroCurl = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Falha de conexão com o YouTube: ' . $erroCurl);
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Resposta inválida da API do YouTube.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $mensagem = $json['error']['message'] ?? ('HTTP ' . $httpCode);
        throw new RuntimeException('YouTube API: ' . $mensagem);
    }

    return $json;
}

function salvar_cache(
    PDO $pdo,
    string $spotifyId,
    string $trackName,
    string $artistName,
    ?string $youtubeId,
    ?string $youtubeTitulo,
    ?string $youtubeCanal,
    ?int $duracaoSegundos,
    string $status
): void {
    $sql = "
        INSERT INTO youtube_cache
            (spotify_id, titulo, artista, youtube_id, youtube_titulo, youtube_canal, duracao_segundos, status, atualizado_em)
        VALUES
            (:spotify_id, :titulo, :artista, :youtube_id, :youtube_titulo, :youtube_canal, :duracao, :status, NOW())
        ON DUPLICATE KEY UPDATE
            titulo = VALUES(titulo),
            artista = VALUES(artista),
            youtube_id = VALUES(youtube_id),
            youtube_titulo = VALUES(youtube_titulo),
            youtube_canal = VALUES(youtube_canal),
            duracao_segundos = VALUES(duracao_segundos),
            status = VALUES(status),
            atualizado_em = NOW()
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':spotify_id' => $spotifyId,
        ':titulo' => $trackName,
        ':artista' => $artistName,
        ':youtube_id' => $youtubeId,
        ':youtube_titulo' => $youtubeTitulo,
        ':youtube_canal' => $youtubeCanal,
        ':duracao' => $duracaoSegundos,
        ':status' => $status,
    ]);
}

$spotifyId = trim((string)($_GET['spotify_id'] ?? ''));
$trackName = trim((string)($_GET['track_name'] ?? ''));
$artistName = trim((string)($_GET['artist_name'] ?? ''));
$durationMs = isset($_GET['duration_ms']) && is_numeric($_GET['duration_ms'])
    ? (int)$_GET['duration_ms']
    : null;
$duracaoAlvo = ($durationMs && $durationMs > 0) ? (int)round($durationMs / 1000) : null;

if (!$spotifyId || !preg_match('/^[A-Za-z0-9]{10,100}$/', $spotifyId)) {
    responder(400, ['sucesso' => false, 'encontrado' => false, 'mensagem' => 'spotify_id inválido.']);
}

if ($trackName === '' || $artistName === '') {
    responder(400, ['sucesso' => false, 'encontrado' => false, 'mensagem' => 'Nome da música e artista são obrigatórios.']);
}

// Limites simples contra parâmetros gigantes enviados manualmente ao endpoint.
if (strlen($trackName) > 255 || strlen($artistName) > 255) {
    responder(400, ['sucesso' => false, 'encontrado' => false, 'mensagem' => 'Dados da música inválidos.']);
}

try {
    $stmtCache = $pdo->prepare("SELECT * FROM youtube_cache WHERE spotify_id = :spotify_id LIMIT 1");
    $stmtCache->execute([':spotify_id' => $spotifyId]);
    $cache = $stmtCache->fetch(PDO::FETCH_ASSOC);

    if ($cache) {
        $atualizado = strtotime($cache['atualizado_em'] ?? '') ?: 0;
        $idade = time() - $atualizado;

        // Vídeo encontrado: reutiliza por 29 dias.
        if ($cache['status'] === 'encontrado' && !empty($cache['youtube_id']) && $idade < (29 * 24 * 60 * 60)) {
            responder(200, [
                'sucesso' => true,
                'encontrado' => true,
                'youtube_id' => $cache['youtube_id'],
                'youtube_titulo' => $cache['youtube_titulo'],
                'youtube_canal' => $cache['youtube_canal'],
                'fonte' => 'cache',
            ]);
        }

        // Busca sem resultado: espera 6h antes de gastar outra search.list.
        if ($cache['status'] === 'nao_encontrado' && $idade < (6 * 60 * 60)) {
            responder(200, [
                'sucesso' => true,
                'encontrado' => false,
                'mensagem' => 'Não encontramos um vídeo adequado para esta música.',
                'fonte' => 'cache',
            ]);
        }
    }

    $apiKey = trim((string)getenv('YOUTUBE_API_KEY'));
    if ($apiKey === '') {
        // Se houver um cache antigo, ele é melhor do que deixar o player quebrado
        // por uma configuração temporariamente ausente no servidor.
        if ($cache && $cache['status'] === 'encontrado' && !empty($cache['youtube_id'])) {
            responder(200, [
                'sucesso' => true,
                'encontrado' => true,
                'youtube_id' => $cache['youtube_id'],
                'youtube_titulo' => $cache['youtube_titulo'],
                'youtube_canal' => $cache['youtube_canal'],
                'fonte' => 'cache_expirado',
            ]);
        }

        error_log('buscar_video.php: YOUTUBE_API_KEY não configurada.');
        responder(503, [
            'sucesso' => false,
            'encontrado' => false,
            'mensagem' => 'Player do YouTube ainda não foi configurado no servidor.',
        ]);
    }

    // Uma única busca por música nova. maxResults=10 melhora a seleção sem consumir
    // chamadas extras do bucket search.list.
    $consulta = trim($artistName . ' ' . $trackName . ' official audio');
    $searchParams = http_build_query([
        'part' => 'snippet',
        'q' => $consulta,
        'type' => 'video',
        'maxResults' => 10,
        'videoEmbeddable' => 'true',
        'videoSyndicated' => 'true',
        'regionCode' => 'BR',
        'key' => $apiKey,
    ], '', '&', PHP_QUERY_RFC3986);

    $search = youtube_get('https://www.googleapis.com/youtube/v3/search?' . $searchParams);
    $ids = [];
    foreach (($search['items'] ?? []) as $item) {
        $id = $item['id']['videoId'] ?? null;
        if ($id && preg_match('/^[A-Za-z0-9_-]{6,32}$/', $id)) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));

    if (!$ids) {
        salvar_cache($pdo, $spotifyId, $trackName, $artistName, null, null, null, null, 'nao_encontrado');
        responder(200, [
            'sucesso' => true,
            'encontrado' => false,
            'mensagem' => 'Não encontramos um vídeo adequado para esta música.',
            'fonte' => 'youtube',
        ]);
    }

    // Uma única videos.list valida todos os candidatos e fornece a duração.
    $videosParams = http_build_query([
        'part' => 'snippet,contentDetails,status',
        'id' => implode(',', $ids),
        'key' => $apiKey,
    ], '', '&', PHP_QUERY_RFC3986);

    $videosResponse = youtube_get('https://www.googleapis.com/youtube/v3/videos?' . $videosParams);

    $melhor = null;
    $melhorScore = -INF;

    foreach (($videosResponse['items'] ?? []) as $video) {
        if (($video['status']['embeddable'] ?? false) !== true) continue;
        if (($video['status']['privacyStatus'] ?? '') !== 'public') continue;
        if (($video['status']['uploadStatus'] ?? '') !== 'processed') continue;
        if (video_bloqueado_no_brasil($video)) continue;

        $score = pontuar_video($video, $trackName, $artistName, $duracaoAlvo);
        if ($score > $melhorScore) {
            $melhorScore = $score;
            $melhor = $video;
        }
    }

    // Evita mostrar um vídeo claramente sem relação só porque foi o "menos ruim".
    if (!$melhor || $melhorScore < 8) {
        salvar_cache($pdo, $spotifyId, $trackName, $artistName, null, null, null, null, 'nao_encontrado');
        responder(200, [
            'sucesso' => true,
            'encontrado' => false,
            'mensagem' => 'Não encontramos um vídeo confiável para esta música.',
            'fonte' => 'youtube',
        ]);
    }

    $youtubeId = (string)$melhor['id'];
    $youtubeTitulo = (string)($melhor['snippet']['title'] ?? '');
    $youtubeCanal = (string)($melhor['snippet']['channelTitle'] ?? '');
    $duracaoVideo = duracao_iso_para_segundos($melhor['contentDetails']['duration'] ?? null);

    salvar_cache(
        $pdo,
        $spotifyId,
        $trackName,
        $artistName,
        $youtubeId,
        $youtubeTitulo ?: null,
        $youtubeCanal ?: null,
        $duracaoVideo,
        'encontrado'
    );

    responder(200, [
        'sucesso' => true,
        'encontrado' => true,
        'youtube_id' => $youtubeId,
        'youtube_titulo' => $youtubeTitulo,
        'youtube_canal' => $youtubeCanal,
        'fonte' => 'youtube',
    ]);

} catch (Throwable $e) {
    error_log('buscar_video.php: ' . $e->getMessage());

    // Se a atualização falhar, um cache antigo ainda é uma experiência melhor.
    if (isset($cache) && $cache && $cache['status'] === 'encontrado' && !empty($cache['youtube_id'])) {
        responder(200, [
            'sucesso' => true,
            'encontrado' => true,
            'youtube_id' => $cache['youtube_id'],
            'youtube_titulo' => $cache['youtube_titulo'],
            'youtube_canal' => $cache['youtube_canal'],
            'fonte' => 'cache_expirado',
        ]);
    }

    responder(502, [
        'sucesso' => false,
        'encontrado' => false,
        'mensagem' => 'Não foi possível carregar o player do YouTube agora.',
    ]);
}
