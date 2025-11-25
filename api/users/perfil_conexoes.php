<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

$perfil_id = $_GET['id'] ?? null;
$tipo = $_GET['tipo'] ?? 'seguidores'; // 'seguidores' ou 'seguindo'

if (!$perfil_id) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'ID obrigatório.']);
    exit;
}

try {
    $sql = "";
    
    if ($tipo === 'seguindo') {
        // Quem este perfil segue (Eu sou o seguidor, quero ver os seguidos)
        // JOIN para pegar os dados do utilizador QUE ESTÁ SENDO SEGUIDO
        $sql = "SELECT u.id, u.nome, u.username, u.foto_perfil 
                FROM usuarios u 
                JOIN seguidores s ON u.id = s.seguido_id 
                WHERE s.seguidor_id = ?";
    } else {
        // Quem segue este perfil (Eu sou o seguido, quero ver os seguidores)
        // JOIN para pegar os dados do utilizador QUE É O SEGUIDOR
        $sql = "SELECT u.id, u.nome, u.username, u.foto_perfil 
                FROM usuarios u 
                JOIN seguidores s ON u.id = s.seguidor_id 
                WHERE s.seguido_id = ?";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$perfil_id]);
    $usuarios = $stmt->fetchAll();

    // Formata o avatar
    foreach ($usuarios as &$user) {
        if (empty($user['foto_perfil'])) {
            $user['foto_perfil'] = 'https://i.pravatar.cc/150?u=' . $user['id'];
        }
    }

    echo json_encode(['sucesso' => true, 'usuarios' => $usuarios]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao buscar conexões.']);
}
?>