<?php
/**
 * Busca letras no LRCLIB sem persistir o conteúdo no banco de dados.
 *
 * Fluxo:
 * 1) tenta /api/get com título, artista, álbum e duração;
 * 2) valida se o resultado realmente parece ser a mesma faixa;
 * 3) se necessário, usa /api/search e escolhe o candidato mais próximo;
 * 4) devolve apenas a resposta desta requisição. Nenhuma letra é salva localmente.
 */

require_once __DIR__ . '/../core/header.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'sucesso' => false,
        'encontrada' => false,
        'mensagem' => 'Método não permitido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function lyricsResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cleanInput($value, int $maxLength = 255): string
{
    $value = trim((string)($value ?? ''));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function normalizeMatchText(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim(preg_replace('/\\s+/', ' ', $value));
}

function similarityRatio(string $a, string $b): float
{
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;

    $max = max(strlen($a), strlen($b));
    if ($max === 0) return 1.0;

    return max(0.0, 1.0 - (levenshtein($a, $b) / $max));
}

function qualifierPenalty(string $candidateTitle, string $requestedTitle): int
{
    $candidate = normalizeMatchText($candidateTitle);
    $requested = normalizeMatchText($requestedTitle);

    $qualifiers = [
        'live', 'remix', 'acoustic', 'karaoke', 'cover', 'instrumental',
        'sped up', 'slowed', 'nightcore', 'reverb', 'edit', 'version'
    ];

    $penalty = 0;
    foreach ($qualifiers as $qualifier) {
        if (strpos($candidate, $qualifier) !== false && strpos($requested, $qualifier) === false) {
            $penalty += 18;
        }
    }
    return $penalty;
}

function scoreCandidate(array $candidate, string $trackName, string $artistName, string $albumName, ?int $durationSeconds): int
{
    $score = 0;

    $requestedTrack = normalizeMatchText($trackName);
    $candidateTrack = normalizeMatchText((string)($candidate['trackName'] ?? $candidate['name'] ?? ''));
    $requestedArtist = normalizeMatchText($artistName);
    $candidateArtist = normalizeMatchText((string)($candidate['artistName'] ?? ''));
    $requestedAlbum = normalizeMatchText($albumName);
    $candidateAlbum = normalizeMatchText((string)($candidate['albumName'] ?? ''));

    if ($requestedTrack !== '' && $candidateTrack !== '') {
        if ($requestedTrack === $candidateTrack) {
            $score += 55;
        } elseif (strpos($candidateTrack, $requestedTrack) !== false || strpos($requestedTrack, $candidateTrack) !== false) {
            $score += 35;
        } else {
            $ratio = similarityRatio($requestedTrack, $candidateTrack);
            if ($ratio >= 0.85) $score += 28;
            elseif ($ratio >= 0.72) $score += 16;
        }
    }

    if ($requestedArtist !== '' && $candidateArtist !== '') {
        if ($requestedArtist === $candidateArtist) {
            $score += 40;
        } elseif (strpos($candidateArtist, $requestedArtist) !== false || strpos($requestedArtist, $candidateArtist) !== false) {
            $score += 26;
        } else {
            $ratio = similarityRatio($requestedArtist, $candidateArtist);
            if ($ratio >= 0.82) $score += 20;
            elseif ($ratio >= 0.68) $score += 10;
        }
    }

    if ($requestedAlbum !== '' && $candidateAlbum !== '') {
        if ($requestedAlbum === $candidateAlbum) $score += 12;
        elseif (strpos($candidateAlbum, $requestedAlbum) !== false || strpos($requestedAlbum, $candidateAlbum) !== false) $score += 6;
    }

    if ($durationSeconds !== null && isset($candidate['duration']) && is_numeric($candidate['duration'])) {
        $diff = abs((float)$candidate['duration'] - $durationSeconds);
        if ($diff <= 2.5) $score += 38;
        elseif ($diff <= 5) $score += 24;
        elseif ($diff <= 10) $score += 10;
        elseif ($diff > 20) $score -= 28;
    }

    $score -= qualifierPenalty((string)($candidate['trackName'] ?? $candidate['name'] ?? ''), $trackName);

    return $score;
}

function lrclibGet(string $endpoint, array $params): array
{
    $url = 'https://lrclib.net' . $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $headers = [
        'Accept: application/json',
        'User-Agent: SocialMusic/1.0 (+https://socialmusic.vercel.app)',
        'Lrclib-Client: SocialMusic/1.0'
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 9,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'data' => null, 'error' => $error ?: 'Falha ao conectar ao LRCLIB.'];
        }

        $decoded = json_decode($body, true);
        return ['status' => $status, 'data' => $decoded, 'error' => null];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 9,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
        ]
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\\/\\S+\\s+(\\d{3})/', $line, $matches)) {
            $status = (int)$matches[1];
            break;
        }
    }

    if ($body === false) {
        return ['status' => $status, 'data' => null, 'error' => 'Falha ao conectar ao LRCLIB.'];
    }

    return ['status' => $status, 'data' => json_decode($body, true), 'error' => null];
}

function makeSuccessPayload(array $candidate): array
{
    return [
        'sucesso' => true,
        'encontrada' => true,
        'instrumental' => (bool)($candidate['instrumental'] ?? false),
        'letra' => trim((string)($candidate['plainLyrics'] ?? '')),
        'letra_sincronizada' => trim((string)($candidate['syncedLyrics'] ?? '')),
        'fonte' => 'LRCLIB',
        'fonte_url' => 'https://lrclib.net',
        'correspondencia' => [
            'track_name' => $candidate['trackName'] ?? $candidate['name'] ?? null,
            'artist_name' => $candidate['artistName'] ?? null,
            'album_name' => $candidate['albumName'] ?? null,
            'duration' => $candidate['duration'] ?? null,
        ]
    ];
}

$trackName = cleanInput($_GET['track_name'] ?? '', 255);
$artistName = cleanInput($_GET['artist_name'] ?? '', 255);
$albumName = cleanInput($_GET['album_name'] ?? '', 255);
$durationMsRaw = $_GET['duration_ms'] ?? null;
$durationSeconds = null;

if ($durationMsRaw !== null && is_numeric($durationMsRaw)) {
    $durationMs = max(0, min((int)$durationMsRaw, 7200000));
    if ($durationMs > 0) $durationSeconds = (int)round($durationMs / 1000);
}

if ($trackName === '' || $artistName === '') {
    lyricsResponse([
        'sucesso' => false,
        'encontrada' => false,
        'mensagem' => 'Nome da música e artista são obrigatórios.'
    ], 400);
}

$bestCandidate = null;
$bestScore = -999;

// Busca exata do LRCLIB. Album e duração ajudam a diferenciar versões da mesma música.
if ($albumName !== '' && $durationSeconds !== null) {
    $exact = lrclibGet('/api/get', [
        'track_name' => $trackName,
        'artist_name' => $artistName,
        'album_name' => $albumName,
        'duration' => $durationSeconds,
    ]);

    if ($exact['status'] === 200 && is_array($exact['data'])) {
        $score = scoreCandidate($exact['data'], $trackName, $artistName, $albumName, $durationSeconds);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestCandidate = $exact['data'];
        }

        // Uma correspondência forte dispensa uma segunda chamada.
        if ($score >= 110) {
            lyricsResponse(makeSuccessPayload($exact['data']));
        }
    }
}

// Fallback: pesquisa vários candidatos e escolhe o mais próximo.
$searchParams = [
    'track_name' => $trackName,
    'artist_name' => $artistName,
];
if ($albumName !== '') $searchParams['album_name'] = $albumName;

$search = lrclibGet('/api/search', $searchParams);

if ($search['status'] === 200 && is_array($search['data'])) {
    foreach ($search['data'] as $candidate) {
        if (!is_array($candidate)) continue;
        $score = scoreCandidate($candidate, $trackName, $artistName, $albumName, $durationSeconds);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestCandidate = $candidate;
        }
    }
}

// Exige uma correspondência suficientemente forte. É melhor não mostrar nada
// do que associar a letra de uma versão errada da música.
if ($bestCandidate !== null && $bestScore >= 78) {
    lyricsResponse(makeSuccessPayload($bestCandidate));
}

if (($search['status'] ?? 0) === 429) {
    lyricsResponse([
        'sucesso' => false,
        'encontrada' => false,
        'mensagem' => 'O serviço de letras está temporariamente ocupado. Tente novamente em instantes.'
    ], 503);
}

if (($search['status'] ?? 0) >= 500 || ($search['status'] ?? 0) === 0) {
    lyricsResponse([
        'sucesso' => false,
        'encontrada' => false,
        'mensagem' => 'O serviço de letras está indisponível no momento.'
    ], 503);
}

lyricsResponse([
    'sucesso' => true,
    'encontrada' => false,
    'mensagem' => 'Letra não disponível para esta música.'
]);
