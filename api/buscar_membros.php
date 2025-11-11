<?php
require_once 'header.php';
require_once 'conexao.php';

$query = $_GET['q'] ?? '';

// Sanitizar entrada: remover caracteres especiais perigosos, manter alfanuméricos, espaços e alguns caracteres comuns
$query = preg_replace('/[^a-zA-Z0-9áéíóúãõçñ\s\-_.]/u', '', $query);
$query = trim($query);

// Caso a busca for muito curta, não irá trazer nada
if (strlen($query) < 2) {
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
    
    // Usar bindValue para parâmetros nomeados duplicados (evita problemas com drivers PDO)
    $stmt->bindValue(':term', $searchTerm, PDO::PARAM_STR);
    
    $stmt->execute();
    $err = $stmt->errorInfo();
    if ($stmt->errorCode() !== '00000') {
        error_log('buscar_membros.php - Erro stmt: ' . implode(' | ', $err));
        throw new Exception('Erro na busca de usuários');
    }
    
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['sucesso' => true, 'usuarios' => $usuarios]);

} catch (Exception $e) { 
    http_response_code(500);
    error_log("Erro em buscar_membros.php: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'error' => 'Ocorreu um erro no servidor: ' . $e->getMessage()]);
}
?>