<?php
require_once __DIR__ . '/guard.php'; // Já inclui header, conexão e verifica o admin

$resposta = [
    'sucesso' => true,
    'stats' => [],
    'users' => [],
    'activities' => [],
    'reports' => []
];

try {
    // 1. Estatísticas do painel
    $stats = [];
    $stats['totalUsers'] = (int)$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    $stats['totalSongs'] = (int)$pdo->query("SELECT COUNT(*) FROM musicas")->fetchColumn();
    $stats['totalReviews'] = (int)$pdo->query("SELECT COUNT(*) FROM avaliacoes")->fetchColumn();

    // Mantido para não quebrar qualquer outra tela que ainda use este campo.
    $stats['totalComments'] = $stats['totalReviews'];

    // Novo contador usado pelo card de denúncias no Admin.vue.
    $stats['totalReports'] = (int)$pdo
        ->query("SELECT COUNT(*) FROM denuncias_avaliacoes WHERE status = 'pendente'")
        ->fetchColumn();

    $resposta['stats'] = $stats;

    // 2. Usuários recentes
    $stmt_users = $pdo->query(
        "SELECT id, nome, email, ativo
         FROM usuarios
         ORDER BY id DESC
         LIMIT 10"
    );

    $users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as &$user) {
        $user['ativo'] = (bool)$user['ativo'];
    }
    unset($user);

    $resposta['users'] = $users;

    // 3. Denúncias pendentes para moderação
    $sql_reports = "
        SELECT
            d.id,
            d.avaliacao_id,
            d.motivo,
            d.descricao,
            d.status,
            d.data_criacao,
            denunciante.id AS denunciante_id,
            denunciante.nome AS denunciante_nome,
            autor.id AS autor_id,
            autor.nome AS autor_nome,
            a.titulo AS avaliacao_titulo,
            a.comentario,
            m.titulo AS musica_titulo,
            m.artista AS musica_artista,
            (
                SELECT COUNT(*)
                FROM denuncias_avaliacoes d2
                WHERE d2.avaliacao_id = a.id
                  AND d2.status = 'pendente'
            ) AS total_denuncias_avaliacao
        FROM denuncias_avaliacoes d
        JOIN avaliacoes a ON a.id = d.avaliacao_id
        JOIN usuarios denunciante ON denunciante.id = d.usuario_id
        JOIN usuarios autor ON autor.id = a.usuario_id
        JOIN musicas m ON m.id = a.musica_id
        WHERE d.status = 'pendente'
        ORDER BY d.data_criacao DESC
        LIMIT 50
    ";

    $stmt_reports = $pdo->query($sql_reports);
    $reports = $stmt_reports->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reports as &$report) {
        $report['id'] = (int)$report['id'];
        $report['avaliacao_id'] = (int)$report['avaliacao_id'];
        $report['denunciante_id'] = (int)$report['denunciante_id'];
        $report['autor_id'] = (int)$report['autor_id'];
        $report['total_denuncias_avaliacao'] = (int)$report['total_denuncias_avaliacao'];
    }
    unset($report);

    $resposta['reports'] = $reports;

    // 4. Atividade recente: cadastros, avaliações e denúncias
    $sql_activities = "
        (SELECT
            'new_user' AS tipo,
            nome AS titulo,
            'Criou uma conta no sistema' AS descricao,
            data_criacao AS data
         FROM usuarios)

        UNION ALL

        (SELECT
            'new_review' AS tipo,
            u.nome AS titulo,
            CONCAT('Avaliou a música ', m.titulo, ' - ', m.artista) AS descricao,
            a.data_criacao AS data
         FROM avaliacoes a
         JOIN usuarios u ON a.usuario_id = u.id
         JOIN musicas m ON a.musica_id = m.id)

        UNION ALL

        (SELECT
            'new_report' AS tipo,
            denunciante.nome AS titulo,
            CONCAT(
                'Denunciou uma avaliação de ',
                autor.nome,
                ' na música ',
                m.titulo
            ) AS descricao,
            d.data_criacao AS data
         FROM denuncias_avaliacoes d
         JOIN avaliacoes a ON a.id = d.avaliacao_id
         JOIN usuarios denunciante ON denunciante.id = d.usuario_id
         JOIN usuarios autor ON autor.id = a.usuario_id
         JOIN musicas m ON m.id = a.musica_id)

        ORDER BY data DESC
        LIMIT 10
    ";

    $stmt_activities = $pdo->query($sql_activities);
    $resposta['activities'] = $stmt_activities->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($resposta);

} catch (PDOException $e) {
    http_response_code(500);
    error_log('Erro ao carregar dashboard: ' . $e->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao carregar dashboard.'
    ]);
}
?>
