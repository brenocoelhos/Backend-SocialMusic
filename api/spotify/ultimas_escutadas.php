<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../../classes/SpotifyAPI.php';

// Verifica se está logado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não logado.']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$limit = $_GET['limit'] ?? 10;

try {
    // 1. Buscar tokens do usuário no banco
    $stmt = $pdo->prepare("SELECT spotify_access_token, spotify_refresh_token, spotify_token_expires FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $userTokens = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userTokens || empty($userTokens['spotify_access_token'])) {
        throw new Exception("Usuário não conectou o Spotify.");
    }

    // Carregar credenciais do .env
    $clientId = getenv('SPOTIFY_CLIENT_ID');
    $clientSecret = getenv('SPOTIFY_CLIENT_SECRET');
    
    // Instanciar API
    $spotifyApi = new SpotifyAPI(
        $clientId, 
        $clientSecret, 
        $userTokens['spotify_access_token'], 
        strtotime($userTokens['spotify_token_expires'])
    );

    // 2. Verificar se o token expirou
    if (time() >= strtotime($userTokens['spotify_token_expires'])) {
        if (empty($userTokens['spotify_refresh_token'])) {
            throw new Exception("Token expirado e sem refresh token. Faça login novamente.");
        }

        // Tentar renovar
        $newData = $spotifyApi->refreshUserAccessToken($userTokens['spotify_refresh_token']);
        
        if (isset($newData['access_token'])) {
            // Salvar novo token no banco
            $newAccess = $newData['access_token'];
            $newExpires = date('Y-m-d H:i:s', time() + $newData['expires_in']);
            
            $upd = $pdo->prepare("UPDATE usuarios SET spotify_access_token = ?, spotify_token_expires = ? WHERE id = ?");
            $upd->execute([$newAccess, $newExpires, $usuario_id]);
            
            // Atualizar a instância da API com o novo token
            $spotifyApi = new SpotifyAPI($clientId, $clientSecret, $newAccess, strtotime($newExpires));
        } else {
            throw new Exception("Falha ao renovar token do Spotify.");
        }
    }

    // 3. Buscar as músicas
    $musicas = $spotifyApi->getUserRecentlyPlayed($limit);

    echo json_encode([
        'sucesso' => true,
        'musicas' => $musicas
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
}
?>