<?php
require_once 'header.php';
require_once 'conexao.php';

$query = $_GET['q'] ?? '';

// Caso a busca for muito curta, não irá trazer nada
if (strlen($query)< 2) {
    echo json_encode(['sucesso' => true, 'usuarios' => []]);
    exit;
}

// Buscar em qualquer parte do texto
$searchTerm = '%' . $query . '%';

// Busca tanto por nome quanto por username 
try {
    $sql = "
        SELECT
            id, nome, username, foto_perfil AS avatar
        FROM usuarios
        WHERE (nome LIKE :term OR username LIKE :term)
        LIMIT 10";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':term' => $searchTerm]);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['sucesso' => true, 'usuarios' => $usuarios]);

} catch (Exception $e) { 
    http_response_code(500);
    error_log("Erro em buscar_membros.php: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'error' => 'Ocorreu um erro no servidor: ' . $e->getMessage()]);
}
?>