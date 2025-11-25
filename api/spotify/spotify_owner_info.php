<?php
// api/spotify_owner_info.php - API para obter informações do dono autenticado

require_once __DIR__ . '/../core/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../classes/SpotifyOwnerAuth.php';

try {
    $ownerAuth = new SpotifyOwnerAuth();
    
    $action = $_GET['action'] ?? 'status';
    
    switch ($action) {
        case 'status':
            $tokenInfo = $ownerAuth->getTokenInfo();
            echo json_encode([
                'autenticado' => $ownerAuth->isAuthenticated(),
                'token_info' => $tokenInfo,
                'expira_em' => $tokenInfo ? ($tokenInfo['expires_at'] - time()) : 0
            ]);
            break;
            
        case 'profile':
            if (!$ownerAuth->isAuthenticated()) {
                throw new Exception('Não autenticado');
            }
            
            $profile = $ownerAuth->getUserProfile();
            echo json_encode([
                'sucesso' => true,
                'profile' => $profile
            ]);
            break;
            
        case 'playlists':
            if (!$ownerAuth->isAuthenticated()) {
                throw new Exception('Não autenticado');
            }
            
            $limit = $_GET['limit'] ?? 20;
            $playlists = $ownerAuth->getUserPlaylists($limit);
            echo json_encode([
                'sucesso' => true,
                'playlists' => $playlists
            ]);
            break;
            
        case 'top_tracks':
            if (!$ownerAuth->isAuthenticated()) {
                throw new Exception('Não autenticado');
            }
            
            $limit = $_GET['limit'] ?? 20;
            $time_range = $_GET['time_range'] ?? 'medium_term';
            $tracks = $ownerAuth->getUserTopTracks($limit, $time_range);
            echo json_encode([
                'sucesso' => true,
                'tracks' => $tracks
            ]);
            break;
            
        case 'logout':
            $ownerAuth->logout();
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Logout realizado com sucesso'
            ]);
            break;
            
        default:
            throw new Exception('Ação inválida');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => $e->getMessage()
    ]);
}
?>