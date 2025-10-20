<?php
// config/lastfm.php

// Carregar .env se existir
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            putenv($key . '=' . $value);
        }
    }
}

define('LASTFM_API_KEY', getenv('LASTFM_API_KEY') ?: '');