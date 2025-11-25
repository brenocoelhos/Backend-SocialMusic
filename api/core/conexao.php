<?php
/**
 * Arquivo de conexão - usa config/database.php
 */
require_once __DIR__ . '/../../config/database.php';

// Obter conexão do singleton
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de conexão com o banco de dados.']);
    error_log("Erro conexao.php: " . $e->getMessage());
    exit;
}
?>