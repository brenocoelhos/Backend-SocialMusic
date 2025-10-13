<?php
// Spotify config: read from environment variables to avoid committing secrets
return [
    'client_id' => getenv('SPOTIFY_CLIENT_ID') ?: '',
    'client_secret' => getenv('SPOTIFY_CLIENT_SECRET') ?: ''
];