<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

// Verifica se o usuário está logado
if(!isset($_SESSION['usuario_id'])) {
    // Se não estiver logado, não pode escrever avaliação
    echo json_encode(['existe' => false]);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

// Pegar o spotify_id da URL
$spotify_id = $_GET['spotify_id'] ?? null;
if (!$spotify_id) {
    http_response_code(400);
    echo json_encode(['erro' => 'spotify_id é obrigatório.']);
    exit;
}

try{
    // Encontrar o ID local da música
    $stmt_musica = $pdo->prepare("SELECT id FROM musicas WHERE spotify_id = ?");
    $stmt_musica->execute([$spotify_id]);
    $musica = $stmt_musica->fetch();

    if (!$musica) {
        // Música não existe, logo não há avaliação
        echo json_encode(['existe' => false]);
        exit;
    }

    $musica_id_local = $musica['id'];

    // Verificar se o usuário já avaliou essa música
    $stmt_review = $pdo->prepare("SELECT id, nota, titulo, comentario FROM avaliacoes WHERE usuario_id = ? AND musica_id = ?");
    $stmt_review->execute([$usuario_id, $musica_id_local]);
    $avaliacao = $stmt_review->fetch(PDO::FETCH_ASSOC);

    if ($avaliacao) {
        // Avaliação existe
        echo json_encode([
            'existe' => true,
            'avaliacao' => $avaliacao]);
    } else {
        // Avaliação não existe
        echo json_encode(['existe' => false]);
    }
}
catch (Exception $e) {
    http_response_code(500);
    error_log("Erro ao verificar_avaliação.php: " . $e->getMessage());
    echo json_encode(['erro' => 'Erro interno do servidor. ']);
    exit;
}
