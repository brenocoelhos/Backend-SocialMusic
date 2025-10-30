<?php
/**
 * Header para todas as requisições da API
 * CORS é gerenciado em config/cors.php
 */

// Incluir configuração de CORS
require_once __DIR__ . '/../config/cors.php';

// Define o tempo de vida da sessão (ex: 8 horas)
ini_set('session.gc_maxlifetime', 8 * 60 * 60); // 8 horas * 60 min * 60 seg
session_set_cookie_params([
    'lifetime' => 28800,
    'path' => '/',
    'domain' => '',
    'secure' => true,      // Somente HTTPS
    'httponly' => true,    // Não acessível via JavaScript
    'samesite' => 'None'   // Permite cross-origin
]);

// Inicia a sessão para todas as requisições
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define que a saída será sempre JSON
header("Content-Type: application/json");
?>