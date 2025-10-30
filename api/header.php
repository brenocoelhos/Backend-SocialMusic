<?php

header("Access-Control-Allow-Origin: http://localhost:3000"); 
header("Access-Control-Allow-Credentials: true"); // Permite cookies de sessão
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With");

// Responde a requisições OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204); // No Content
    exit;
}

// Define o tempo de vida da sessão (ex: 8 horas)
ini_set('session.gc_maxlifetime', 8 * 60 * 60); // 8 horas * 60 min * 60 seg
session_set_cookie_params(28800);

// Inicia a sessão para todas as requisições
session_start();

// Define que a saída será sempre JSON
header("Content-Type: application/json");
?>