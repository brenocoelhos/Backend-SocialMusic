<?php
require_once 'guard.php'; // Já inclui header, conexao e verifica o admin

$resposta = [
    'sucesso' => true,
    'stats' => [],
    'users' => []
];

// 1. Buscar Estatísticas
$stats = [];

// Contar utilizadores
$stmt_users_count = $pdo->query("SELECT COUNT(*) as totalUsers FROM usuarios");
$stats['totalUsers'] = $stmt_users_count->fetchColumn();

// --- MUDANÇA AQUI ---
// Contar músicas
$stmt_songs_count = $pdo->query("SELECT COUNT(*) as totalSongs FROM musicas");
$stats['totalSongs'] = $stmt_songs_count->fetchColumn();

// Contar avaliações (que também são os comentários)
$stmt_reviews_count = $pdo->query("SELECT COUNT(*) as totalReviews FROM avaliacoes");
$stats['totalReviews'] = $stmt_reviews_count->fetchColumn();
$stats['totalComments'] = $stats['totalReviews']; // Assumindo que uma avaliação = um comentário
// --- FIM DA MUDANÇA ---

$resposta['stats'] = $stats;


// 2. Buscar Utilizadores Recentes (limitado a 20)
$stmt_users = $pdo->query("SELECT id, nome, email, ativo FROM usuarios ORDER BY id DESC LIMIT 20");
$resposta['users'] = $stmt_users->fetchAll();

// Converte 'ativo' de 0/1 para true/false (que o Vue espera)
foreach ($resposta['users'] as &$user) {
    $user['ativo'] = (bool)$user['ativo'];
}

echo json_encode($resposta);
?>