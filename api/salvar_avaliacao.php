<?php
require_once 'header.php'; // Garante que session_start() seja chamado
require_once 'conexao.php'; // Puxa a variável $pdo conexão com o banco

// Verifica se o usuário está logado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); // Não autorizado
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado.']);
    exit;
}

// Pega o ID permanente do usuário que está na sessão
$usuario_id = $_SESSION['usuario_id'];

// Pega os dados enviados pelo Vue
$dados = json_decode(file_get_contents("php://input"));

if (!$dados) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhum dado recebido.']);
    exit;
}

// Atribui os dados do Vue a variáveis
$spotify_id = $dados->spotify_id ?? null;
$nota = $dados->nota ?? 0;
$titulo_review = $dados->titulo ?? '';
$comentario = $dados->comentario ?? '';

// Dados da música (para salvar na tabela 'musicas' se não existir)
$track_name = $dados->track_name ?? 'Desconhecido';
$artist_name = $dados->artist_name ?? 'Desconhecido';
$image_url = $dados->image_url ?? '';

// Validação básica
if (empty($spotify_id) || $nota == 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'ID da música e nota são obrigatórios.']);
    exit;
}

// Lógica Principal: Transação "Get or Create"
try {
    // Inicia uma transação, pois faremos múltiplas operações
    $pdo->beginTransaction();

    // Procura a música na sua tabela 'musicas'
    $stmt = $pdo->prepare("SELECT id FROM musicas WHERE spotify_id = ?");
    $stmt->execute([$spotify_id]);
    $musica = $stmt->fetch();

    $musica_id_local = null;

    if ($musica) {
        // Se a música JÁ EXISTE, apenas pegamos o ID local dela
        $musica_id_local = $musica['id'];
    } else {
        // Se a música NÃO EXISTE, inserimos ela na tabela 'musicas'
        $stmt_insert_musica = $pdo->prepare(
            "INSERT INTO musicas (spotify_id, titulo, artista, capa_url) VALUES (?, ?, ?, ?)"
        );
        $stmt_insert_musica->execute([$spotify_id, $track_name, $artist_name, $image_url]);
        
        // Pegamos o ID local que acabou de ser gerado
        $musica_id_local = $pdo->lastInsertId();
    }

    // Agora, inserimos a AVALIAÇÃO
    $stmt_review = $pdo->prepare(
        "
        INSERT INTO avaliacoes (usuario_id, musica_id, nota, titulo, comentario, data_criacao) 
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            nota = VALUES(nota),
            titulo = VALUES(titulo),
            comentario = VALUES(comentario),
            data_criacao = NOW()" 
// A linha acima atualiza a data para a da última edição
);

$stmt_review->execute([
    $usuario_id,
    $musica_id_local,
    $nota,
    $titulo_review,
    $comentario    ]);

    // Se tudo deu certo, confirma as mudanças no banco
    $pdo->commit();
    
    http_response_code(201); // Criado
    echo json_encode(['sucesso' => true, 'mensagem' => 'Avaliação salva com sucesso!']);

} catch (Exception $e) {
    // Se algo deu errado, desfaz tudo
    $pdo->rollBack();
    http_response_code(500);
    error_log("Erro ao salvar avaliação: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno do servidor: ' . $e->getMessage()]);
}
?>