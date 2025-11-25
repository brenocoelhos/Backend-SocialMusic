<?php
require_once 'guard.php'; // Já inclui header, conexao e verifica o admin

$resposta = [
    'sucesso' => true,
    'stats' => [],
    'users' => [],
    'activities' => [] 
];

try {
    // 1. Buscar Estatísticas (Igual a antes)
    $stats = [];
    $stats['totalUsers'] = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    $stats['totalSongs'] = $pdo->query("SELECT COUNT(*) FROM musicas")->fetchColumn();
    $stats['totalReviews'] = $pdo->query("SELECT COUNT(*) FROM avaliacoes")->fetchColumn();
    $stats['totalComments'] = $stats['totalReviews']; 
    $resposta['stats'] = $stats;

    // 2. Buscar Usuários Recentes (Igual a antes)
    $stmt_users = $pdo->query("SELECT id, nome, email, ativo FROM usuarios ORDER BY id DESC LIMIT 10");
    $users = $stmt_users->fetchAll();
    foreach ($users as &$user) {
        $user['ativo'] = (bool)$user['ativo'];
    }
    $resposta['users'] = $users;

    // 3. BUSCAR ÚLTIMAS ATIVIDADES (NOVO!)
    // Usamos UNION para juntar Avaliações e Cadastros numa só lista
    $sql_activities = "
        (SELECT 
            'new_user' as tipo,
            nome as titulo,
            'Criou uma conta no sistema' as descricao,
            data_criacao as data
         FROM usuarios)
        
        UNION
        
        (SELECT 
            'new_review' as tipo,
            u.nome as titulo,
            CONCAT('Avaliou a música ', m.titulo, ' - ', m.artista) as descricao,
            a.data_criacao as data
         FROM avaliacoes a
         JOIN usuarios u ON a.usuario_id = u.id
         JOIN musicas m ON a.musica_id = m.id)
         
        ORDER BY data DESC
        LIMIT 10
    ";

    $stmt_activities = $pdo->query($sql_activities);
    $resposta['activities'] = $stmt_activities->fetchAll();

    echo json_encode($resposta);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao carregar dashboard.', 'error' => $e->getMessage()]);
}
?>