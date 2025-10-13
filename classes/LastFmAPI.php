<?php
// classes/LastFmAPI.php

class LastFmAPI {
    private $apiKey;
    private $baseUrl = 'http://ws.audioscrobbler.com/2.0/';

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function getTrendingTracks($limit = 6) {
        try {
            error_log("Getting global top tracks from Last.fm");
            
            // Get global charts
            $url = $this->baseUrl . '?' . http_build_query([
                'method' => 'chart.getTopTracks',
                'api_key' => $this->apiKey,
                'format' => 'json',
                'limit' => $limit,
                'page' => 1
            ]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            error_log("Last.fm Response Code: " . $httpCode);
            
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Erro na requisição ao Last.fm - HTTP " . $httpCode);
            }

            $data = json_decode($response, true);
            
            if (!isset($data['tracks']['track']) || empty($data['tracks']['track'])) {
                throw new Exception("Nenhuma track encontrada no Last.fm");
            }

            $tracks = [];
            foreach ($data['tracks']['track'] as $index => $track) {
                // Only get the first $limit tracks
                if ($index >= $limit) {
                    break;
                }

                // Try to get album cover from iTunes API
                $imageUrl = $this->getItunesAlbumCover($track['name'], $track['artist']['name']);
                
                // If iTunes doesn't have it, use Last.fm as fallback
                if (!$imageUrl && !empty($track['image'])) {
                    foreach (array_reverse($track['image']) as $img) {
                        if (!empty($img['#text']) && $img['#text'] !== '') {
                            $imageUrl = $img['#text'];
                            break;
                        }
                    }
                }

                $trackData = [
                    'titulo' => $track['name'],
                    'artista' => $track['artist']['name'],
                    'rank' => $index + 1,
                    'popularidade' => isset($track['listeners']) ? (int)$track['listeners'] : 0
                ];
                
                // Add image if found
                if ($imageUrl) {
                    $trackData['capa'] = $imageUrl;
                }
                
                $tracks[] = $trackData;
            }

            error_log("Tracks encontradas no Last.fm: " . count($tracks));
            return $tracks;

        } catch (Exception $e) {
            error_log("Erro ao buscar trending tracks no Last.fm: " . $e->getMessage());
            throw $e;
        }
    }

    private function getItunesAlbumCover($trackName, $artistName) {
        try {
            // Clean and prepare search query
            $query = urlencode($artistName . ' ' . $trackName);
            
            // iTunes Search API URL
            $url = "https://itunes.apple.com/search?term={$query}&entity=song&limit=5&media=music";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'SocialMusic/1.0');
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                error_log("iTunes API erro HTTP: {$httpCode} para '{$trackName}' - '{$artistName}'");
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (!isset($data['results']) || empty($data['results'])) {
                error_log("iTunes API: Nenhum resultado para '{$trackName}' - '{$artistName}'");
                return null;
            }
            
            // Look for the best match
            foreach ($data['results'] as $result) {
                if (isset($result['artworkUrl100'])) {
                    // Get high quality version (600x600 instead of 100x100)
                    $imageUrl = str_replace('100x100bb.jpg', '600x600bb.jpg', $result['artworkUrl100']);
                    
                    error_log("iTunes: Imagem encontrada para '{$trackName}' - '{$artistName}': {$imageUrl}");
                    return $imageUrl;
                }
            }
            
            error_log("iTunes API: Nenhuma artwork encontrada para '{$trackName}' - '{$artistName}'");
            return null;
            
        } catch (Exception $e) {
            error_log("Erro na busca iTunes: " . $e->getMessage());
            return null;
        }
    }
}