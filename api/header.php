<?php
/**
 * Header para todas as requisições da API
 * CORS é gerenciado em config/cors.php
 */

// Incluir configuração de CORS
require_once __DIR__ . '/../config/cors.php';

// Define o tempo de vida da sessão (TESTE: 1 Minuto)
ini_set('session.gc_maxlifetime', 60); // 60 segundos
session_set_cookie_params([
    'lifetime' => 60, // 60 segundos
    'path' => '/',
    'domain' => '',
    'secure' => true,      // Somente HTTPS
    'httponly' => true,    // Não acessível via JavaScript
    'samesite' => 'None'   // Permite cross-origin
]);

// Inicia a sessão para todas as requisições
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define que a saída será sempre JSON
header("Content-Type: application/json");
?>