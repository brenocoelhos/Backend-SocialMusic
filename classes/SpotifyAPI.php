<?php
// classes/SpotifyAPI.php

class SpotifyAPI {
    private $clientId;
    private $clientSecret;
    private $accessToken;
    private $tokenExpiration;

    public function __construct($clientId, $clientSecret) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->accessToken = $this->getAccessToken();

    }

    private function getAccessToken() {
        if ($this->accessToken && time() < $this->tokenExpiration) {
            return $this->accessToken;
        }

        $ch = curl_init();
        
        // Log das credenciais (apenas primeiros caracteres por segurança)
        error_log("Client ID (primeiros 5 caracteres): " . substr($this->clientId, 0, 5));
        error_log("Client Secret (primeiros 5 caracteres): " . substr($this->clientSecret, 0, 5));
        
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
        error_log("Erro Token Spotify: " . print_r($data, true));
        throw new Exception('Erro ao obter token do Spotify: ' . ($data['error_description'] ?? 'Credenciais inválidas'));
    }


        throw new Exception('Erro ao obter token do Spotify');
    }

    private function makeRequest($url) {
        $token = $this->getAccessToken();
        
        $ch = curl_init();
        error_log("Fazendo requisição para URL: " . $url);
        
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
        
        // Log detalhado da requisição
        error_log("Spotify Request URL: $url");
        error_log("Spotify Response Code: $httpCode");
        error_log("Spotify Response: $response");
        if ($error) error_log("Spotify cURL Error: $error");
        
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Erro na requisição ao Spotify - HTTP $httpCode: $response");
        }

        return json_decode($response, true);
    }

    public function getPlaylistTracks($playlistId, $limit = 10) {
        error_log("Buscando tracks da playlist ID: " . $playlistId);
        
        // Buscar as tracks diretamente
        $url = "https://api.spotify.com/v1/playlists/{$playlistId}/tracks?limit={$limit}&market=BR";
        $data = $this->makeRequest($url);
        
        $tracks = [];
        foreach ($data['items'] as $item) {
            if (isset($item['track'])) {
                $track = $item['track'];
                error_log("Processando track: " . $track['name'] . " por " . $track['artists'][0]['name']);
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
        
        error_log("Total de tracks encontradas: " . count($tracks));
        return $tracks;
    }

    public function getMusicasPopulares($limit = 6) {
        try {
            error_log("Iniciando getMusicasPopulares()");
            
            // Buscar primeiro as charts/playlists do Brasil
            $url = "https://api.spotify.com/v1/browse/categories/charts/playlists?country=BR&limit=10";
            $data = $this->makeRequest($url);
            error_log("Charts playlists response: " . print_r($data, true));
            
            // Procurar por uma playlist que contenha "top" ou "viral" no nome
            if (isset($data['playlists']['items']) && !empty($data['playlists']['items'])) {
                foreach ($data['playlists']['items'] as $playlist) {
                    if (stripos($playlist['name'], 'top') !== false || 
                        stripos($playlist['name'], 'viral') !== false ||
                        stripos($playlist['name'], 'hits') !== false) {
                        error_log("Playlist encontrada: " . $playlist['name']);
                        return $this->getPlaylistTracks($playlist['id'], $limit);
                    }
                }
            }
            
            // Se não encontrar nas charts, tentar featured playlists
            $url = "https://api.spotify.com/v1/browse/featured-playlists?country=BR&limit=1&timestamp=" . urlencode(date('Y-m-d\TH:i:s'));
            $data = $this->makeRequest($url);
            
            if (isset($data['playlists']['items'][0])) {
                $playlist = $data['playlists']['items'][0];
                error_log("Usando featured playlist: " . $playlist['name']);
                return $this->getPlaylistTracks($playlist['id'], $limit);
            }
            
            throw new Exception("Nenhuma playlist adequada encontrada");
        } catch (Exception $e) {
            error_log("Erro ao buscar músicas populares: " . $e->getMessage());
            return $this->getTopMusicas($limit);
        }
    }

public function getTopMusicas($limit = 10) {
    try {
        error_log("Iniciando getTopMusicas()");
        
        // Tentar primeiro o Last.fm
        try {
            error_log("Tentando buscar trending tracks do Last.fm");
            $lastFmConfig = require __DIR__ . '/../config/lastfm.php';
            $lastFm = new LastFmAPI($lastFmConfig['api_key']);
            $tracks = $lastFm->getTrendingTracks($limit);
            
            if (!empty($tracks)) {
                error_log("Tracks encontradas no Last.fm com sucesso");
                
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
                        error_log("Erro ao enriquecer track com dados do Spotify: " . $e->getMessage());
                    }
                }
                
                return $tracks;
            }
        } catch (Exception $e) {
            error_log("Erro ao buscar do Last.fm: " . $e->getMessage());
        }
        
        // Se Last.fm falhar, tentar new releases do Spotify
        error_log("Last.fm falhou, tentando new releases do Spotify");
        $url = "https://api.spotify.com/v1/browse/new-releases?country=BR&limit={$limit}";
        $data = $this->makeRequest($url);
        
        if (!isset($data['albums']) || empty($data['albums']['items'])) {
            throw new Exception("Nenhum new release encontrado");
        }
        
        $tracks = [];
        foreach ($data['albums']['items'] as $album) {
            // Buscar a primeira track de cada álbum
            error_log("Buscando primeira track do álbum: " . $album['name']);
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
                error_log("Track adicionada: {$album['name']} por {$album['artists'][0]['name']}");
            } catch (Exception $e) {
                error_log("Erro ao buscar track do álbum {$album['name']}: " . $e->getMessage());
                continue;
            }
        }
        
        if (empty($tracks)) {
            throw new Exception("Não foi possível obter nenhuma track dos new releases");
        }
        
        return $tracks;
    } catch (Exception $e) {
        error_log("Erro ao buscar top músicas, usando new releases como último recurso: " . $e->getMessage());
        
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
                error_log("Erro ao buscar preview da track: " . $e->getMessage());
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

}