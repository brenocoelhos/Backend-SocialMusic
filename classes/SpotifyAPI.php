<?php
// classes/SpotifyAPI.php

class SpotifyAPI {
    private $clientId;
    private $clientSecret;
    private $accessToken;
    private $tokenExpiration;
    private $userAccessToken; // Token do usuário para acessar playlists privadas
    private $userTokenExpiration;

    public function __construct($clientId, $clientSecret, $userAccessToken = null, $userTokenExpiration = null) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->userAccessToken = $userAccessToken;
        $this->userTokenExpiration = $userTokenExpiration;
        $this->accessToken = $this->getAccessToken();
    }

    private function getAccessToken() {
        if ($this->accessToken && time() < $this->tokenExpiration) {
            return $this->accessToken;
        }

        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        
        if (isset($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            $this->tokenExpiration = time() + $data['expires_in'];
            return $this->accessToken;
        }
        if (!isset($data['access_token'])) {
        throw new Exception('Erro ao obter token do Spotify: ' . ($data['error_description'] ?? 'Credenciais inválidas'));
    }


        throw new Exception('Erro ao obter token do Spotify');
    }

    private function makeRequest($url, $useUserToken = false) {
        // Usar token do usuário se disponível e solicitado, senão usar token da aplicação
        $token = ($useUserToken && $this->isUserTokenValid()) ? 
                 $this->userAccessToken : 
                 $this->getAccessToken();
        
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Erro na requisição ao Spotify - HTTP $httpCode: $response");
        }

        return json_decode($response);
    }

    /**
     * Verifica se o token do usuário ainda é válido
     */
    private function isUserTokenValid() {
        return $this->userAccessToken && 
               ($this->userTokenExpiration === null || time() < $this->userTokenExpiration);
    }

    public function getPlaylistTracks($playlistId, $limit = 10, $useUserToken = false) {

        
        // Buscar as tracks diretamente
        $url = "https://api.spotify.com/v1/playlists/{$playlistId}/tracks?limit={$limit}&market=BR";
        $data = $this->makeRequest($url, $useUserToken);
        
        $tracks = [];
        foreach ($data['items'] as $item) {
            if (isset($item['track']) && $item['track']) {
                $track = $item['track'];
                $tracks[] = [
                    'id' => $track['id'],
                    'titulo' => $track['name'],
                    'artista' => $track['artists'][0]['name'],
                    'capa' => $track['album']['images'][0]['url'] ?? '',
                    'previewUrl' => $track['preview_url'],
                    'popularidade' => $track['popularity']
                ];
            }
        }
        

        return $tracks;
    }

    public function getMusicasPopulares($limit = 6) {
        try {
            // Primeiro, tentar a playlist específica "Top 50 - Brasil"
            $popularesPlaylistId = "37i9dQZEVXbMXbN3EUUhlg";
            
            try {

                
                // Tentar primeiro com token do usuário (se disponível)
                if ($this->isUserTokenValid()) {
                    try {
                        return $this->getPlaylistTracks($popularesPlaylistId, $limit, true);
                    } catch (Exception $e) {

                        // Continua para tentar com token da aplicação
                    }
                }
                
                // Tentar com token da aplicação
                return $this->getPlaylistTracks($popularesPlaylistId, $limit, false);
                
            } catch (Exception $e) {

                // Continua para métodos alternativos
            }
            
            // Fallback 1: Buscar nas charts/playlists do Brasil

            $url = "https://api.spotify.com/v1/browse/categories/charts/playlists?country=BR&limit=10";
            $data = $this->makeRequest($url);
            
            // Procurar por uma playlist que contenha "top" ou "viral" no nome
            if (isset($data['playlists']['items']) && !empty($data['playlists']['items'])) {
                foreach ($data['playlists']['items'] as $playlist) {
                    if (stripos($playlist['name'], 'top') !== false || 
                        stripos($playlist['name'], 'viral') !== false ||
                        stripos($playlist['name'], 'hits') !== false) {

                        return $this->getPlaylistTracks($playlist['id'], $limit);
                    }
                }
            }
            
            // Fallback 2: Featured playlists

            $url = "https://api.spotify.com/v1/browse/featured-playlists?country=BR&limit=1&timestamp=" . urlencode(date('Y-m-d\TH:i:s'));
            $data = $this->makeRequest($url);
            
            if (isset($data['playlists']['items'][0])) {
                $playlist = $data['playlists']['items'][0];

                return $this->getPlaylistTracks($playlist['id'], $limit);
            }
            
            throw new Exception("Nenhuma playlist adequada encontrada");
        } catch (Exception $e) {

            return $this->getTopMusicas($limit);
        }
    }

public function getTopMusicas($limit = 10) {
    try {

        
        // Tentar primeiro o Last.fm
        try {

            require_once __DIR__ . '/LastFmAPI.php';
            $lastFmConfig = require __DIR__ . '/../config/lastfm.php';
            $lastFm = new LastFmAPI($lastFmConfig['api_key']);
            $tracks = $lastFm->getTrendingTracks($limit);
            
            if (!empty($tracks)) {

                
                // Tentar enriquecer com preview_urls do Spotify
                foreach ($tracks as &$track) {
                    try {
                        $searchUrl = "https://api.spotify.com/v1/search?q=" . urlencode($track['titulo'] . " " . $track['artista']) . "&type=track&limit=1";
                        $searchData = $this->makeRequest($searchUrl);
                        
                        if (isset($searchData['tracks']['items'][0])) {
                            $spotifyTrack = $searchData['tracks']['items'][0];
                            $track['previewUrl'] = $spotifyTrack['preview_url'];
                            $track['capa'] = $spotifyTrack['album']['images'][0]['url'] ?? $track['capa'];
                        }
                    } catch (Exception $e) {

                    }
                }
                
                return $tracks;
            }
        } catch (Exception $e) {

        }
        
        // Se Last.fm falhar, tentar new releases do Spotify

        $url = "https://api.spotify.com/v1/browse/new-releases?country=BR&limit={$limit}";
        $data = $this->makeRequest($url);
        
        if (!isset($data['albums']) || empty($data['albums']['items'])) {
            throw new Exception("Nenhum new release encontrado");
        }
        
        $tracks = [];
        foreach ($data['albums']['items'] as $album) {
            // Buscar a primeira track de cada álbum

            $albumTracksUrl = "https://api.spotify.com/v1/albums/{$album['id']}/tracks?limit=1";
            try {
                $trackData = $this->makeRequest($albumTracksUrl);
                $previewUrl = !empty($trackData['items'][0]['preview_url']) ? $trackData['items'][0]['preview_url'] : null;
                
                $tracks[] = [
                    'id' => $album['id'],
                    'titulo' => $album['name'],
                    'artista' => $album['artists'][0]['name'],
                    'capa' => $album['images'][0]['url'] ?? '',
                    'previewUrl' => $previewUrl,
                    'popularidade' => $album['popularity'] ?? 90
                ];

            } catch (Exception $e) {

                continue;
            }
        }
        
        if (empty($tracks)) {
            throw new Exception("Não foi possível obter nenhuma track dos new releases");
        }
        
        return $tracks;
    } catch (Exception $e) {

        
        // Último recurso: new releases
        $url = "https://api.spotify.com/v1/browse/new-releases?limit={$limit}&country=BR";
        $data = $this->makeRequest($url);
        
        $tracks = [];
        foreach ($data['albums']['items'] as $album) {
            // Busca as tracks do álbum para obter preview_url
            $albumTracksUrl = "https://api.spotify.com/v1/albums/{$album['id']}/tracks?limit=1";
            try {
                $trackData = $this->makeRequest($albumTracksUrl);
                $previewUrl = !empty($trackData['items'][0]['preview_url']) ? $trackData['items'][0]['preview_url'] : null;
            } catch (Exception $e) {

                $previewUrl = null;
            }

            // Usa coalesce para valores padrão seguros
            $tracks[] = [
                'id' => $album['id'] ?? '',
                'titulo' => $album['name'] ?? 'Título Desconhecido',
                'artista' => $album['artists'][0]['name'] ?? 'Artista Desconhecido',
                'capa' => $album['images'][0]['url'] ?? '',
                'previewUrl' => $previewUrl,
                'popularidade' => $album['popularity'] ?? 90
            ];
        }
        
        return $tracks;
    }
}

 /**
     * Busca por músicas na API do Spotify.
     *
     * @param string $query O termo que será buscado.
     * @param int $limit O número máximo de resultados.
     * @return array|null Uma lista de músicas ou null em caso de erro.
     */
    public function searchTracks($query, $limit = 10)
    {
        if (!$this->accessToken) {
            return null; // Não foi possível obter o token
        }

        $query = urlencode($query);
        $url = "https://api.spotify.com/v1/search?q={$query}&type=track&limit={$limit}";

        $options = [
            'http' => [
                'header' => "Authorization: Bearer " . $this->accessToken,
                'method' => 'GET',
            ],
        ];

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            // Lidar com o erro
            return null;
        }

        return json_decode($result);
    }

        // Busca uma faixa específica pelo ID
    public function getTrackById($trackId)
    {
        if (!$this->accessToken) {
            return null; // Não foi possível obter o token
        }
        $url = "https://api.spotify.com/v1/tracks/{$trackId}?market=BR";

        try {
            return $this->makeRequest($url);
        } catch (Exception $e) {
            error_log("Erro ao buscar track por ID: " . $e->getMessage());
            return null; 
        }
    }


/**
     * Busca as últimas músicas escutadas pelo usuário
     */
    public function getUserRecentlyPlayed($limit = 10) {
        if (!$this->isUserTokenValid()) {
            throw new Exception("Token de usuário inválido ou expirado.");
        }

        $url = "https://api.spotify.com/v1/me/player/recently-played?limit=" . $limit;
        
        // Usa o makeRequest passando true para usar o token do usuário
        $data = $this->makeRequest($url, true); 
        
        // Formatar retorno
        $tracks = [];
        if (isset($data->items)) {
            foreach ($data->items as $item) {
                $track = $item->track;
                $tracks[] = [
                    'played_at' => $item->played_at,
                    'id' => $track->id,
                    'titulo' => $track->name,
                    'artista' => $track->artists[0]->name,
                    'capa' => $track->album->images[0]->url ?? '',
                    'previewUrl' => $track->preview_url,
                    'spotify_url' => $track->external_urls->spotify
                ];
            }
        }
        return $tracks;
    }

    /**
     * Renova o token de acesso do usuário usando o refresh token
     */
    public function refreshUserAccessToken($refreshToken) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
}