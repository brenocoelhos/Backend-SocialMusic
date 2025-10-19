<?php
// classes/SpotifyOwnerAuth.php - Gerencia autenticação OAuth do dono

class SpotifyOwnerAuth {
    private $tokenFile;
    private $clientId;
    private $clientSecret;

    public function __construct() {
        // Carregar .env
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    list($key, $value) = explode('=', $line, 2);
                    $_ENV[trim($key)] = trim(trim($value, '"'));
                    putenv(trim($key) . '=' . trim($value));
                }
            }
        }
        
        $this->clientId = $_ENV['SPOTIFY_CLIENT_ID'] ?? '';
        $this->clientSecret = $_ENV['SPOTIFY_CLIENT_SECRET'] ?? '';
        $this->tokenFile = __DIR__ . '/../temp/spotify_owner_tokens.json';
        
        // Criar diretório temp se não existir
        if (!is_dir(dirname($this->tokenFile))) {
            mkdir(dirname($this->tokenFile), 0755, true);
        }
    }

    /**
     * Verifica se o dono está autenticado
     */
    public function isAuthenticated() {
        if (!file_exists($this->tokenFile)) {
            return false;
        }
        
        $tokens = json_decode(file_get_contents($this->tokenFile), true);
        
        if (!$tokens || !isset($tokens['access_token'])) {
            return false;
        }
        
        // Verificar se o token não expirou
        if (time() >= $tokens['expires_at']) {
            // Tentar renovar com refresh token
            if (isset($tokens['refresh_token'])) {
                return $this->refreshToken();
            }
            return false;
        }
        
        return true;
    }

    /**
     * Obtém o token de acesso válido
     */
    public function getAccessToken() {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        $tokens = json_decode(file_get_contents($this->tokenFile), true);
        return $tokens['access_token'];
    }

    /**
     * Obtém informações dos tokens
     */
    public function getTokenInfo() {
        if (!file_exists($this->tokenFile)) {
            return null;
        }
        
        return json_decode(file_get_contents($this->tokenFile), true);
    }

    /**
     * Renova o token usando refresh token
     */
    public function refreshToken() {
        if (!file_exists($this->tokenFile)) {
            return false;
        }
        
        $tokens = json_decode(file_get_contents($this->tokenFile), true);
        
        if (!isset($tokens['refresh_token'])) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $newTokenData = json_decode($response, true);

        if (isset($newTokenData['access_token'])) {
            // Atualizar tokens
            $tokens['access_token'] = $newTokenData['access_token'];
            $tokens['expires_at'] = time() + ($newTokenData['expires_in'] ?? 3600);
            
            if (isset($newTokenData['refresh_token'])) {
                $tokens['refresh_token'] = $newTokenData['refresh_token'];
            }
            
            $tokens['refreshed_at'] = date('Y-m-d H:i:s');
            
            file_put_contents($this->tokenFile, json_encode($tokens, JSON_PRETTY_PRINT));
            
            return true;
        }
        
        return false;
    }

    /**
     * Faz uma requisição autenticada para a API do Spotify
     */
    public function makeAuthenticatedRequest($url) {
        $token = $this->getAccessToken();
        
        if (!$token) {
            throw new Exception('Token de acesso não disponível. Faça a autenticação primeiro.');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401) {
            // Token expirado, tentar renovar
            if ($this->refreshToken()) {
                return $this->makeAuthenticatedRequest($url); // Tentar novamente
            }
            throw new Exception('Token expirado e não foi possível renovar');
        }

        if ($httpCode !== 200) {
            throw new Exception("Erro na requisição: HTTP $httpCode - $response");
        }

        return json_decode($response, true);
    }

    /**
     * Obtém playlists do usuário autenticado
     */
    public function getUserPlaylists($limit = 20) {
        return $this->makeAuthenticatedRequest("https://api.spotify.com/v1/me/playlists?limit=$limit");
    }

    /**
     * Obtém top tracks do usuário
     */
    public function getUserTopTracks($limit = 20, $time_range = 'medium_term') {
        return $this->makeAuthenticatedRequest("https://api.spotify.com/v1/me/top/tracks?limit=$limit&time_range=$time_range");
    }

    /**
     * Obtém informações do usuário
     */
    public function getUserProfile() {
        return $this->makeAuthenticatedRequest("https://api.spotify.com/v1/me");
    }

    /**
     * Remove a autenticação (logout)
     */
    public function logout() {
        if (file_exists($this->tokenFile)) {
            unlink($this->tokenFile);
        }
        
        $stateFile = __DIR__ . '/../temp/spotify_owner_state.txt';
        if (file_exists($stateFile)) {
            unlink($stateFile);
        }
        
        return true;
    }
}
?>