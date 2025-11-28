<?php
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

// Decodifica o JSON vindo do App.vue
$dados = json_decode(file_get_contents("php://input"), true);

// Verificar se é um cadastro via Spotify
$origem = $dados['origem'] ?? 'normal';
$isSpotifySignup = ($origem === 'spotify');

// Validação dos campos obrigatórios
if (!$dados || empty($dados['nome']) || empty($dados['email'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['sucesso' => false, 'mensagem' => 'Nome e email são obrigatórios.']);
    exit;
}

// Para cadastro via Spotify, senha pode não ser obrigatória inicialmente
if (!$isSpotifySignup && empty($dados['senha'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['sucesso' => false, 'mensagem' => 'Senha é obrigatória para cadastro normal.']);
    exit;
}

// Pega os dados
$nome = $dados['nome'];
$email = $dados['email'];
$username = $dados['username'] ?? $dados['email']; // Usa email como username se não fornecido
$spotify_id = $dados['spotifyId'] ?? null;
$spotify_conectado = $isSpotifySignup ? 1 : 0;

// Hash da senha (se fornecida)
$senha_hash = !empty($dados['senha']) ? password_hash($dados['senha'], PASSWORD_DEFAULT) : null;

// Tokens do Spotify
$spotify_access_token = $dados['accessToken'] ?? null;
$spotify_refresh_token = $dados['refreshToken'] ?? null;
$spotify_token_expires = null;

if ($spotify_access_token) {
    // Define a expiração para 1 hora a partir de agora
    $spotify_token_expires = date('Y-m-d H:i:s', time() + 3600);
}

// Define perfil automaticamente baseado no domínio do email
// Emails com @socialmusic.com são automaticamente admin
if (str_ends_with($email, '@socialmusic.com')) {
    $perfil = 'admin';
} else {
    $perfil = 'user';
}

$ativo = 1;       // Padrão (já que adicionamos essa coluna para o Admin.vue)

// 1. Verifica se o email já existe
$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    http_response_code(409); // Conflict
    echo json_encode(['sucesso' => false, 'mensagem' => 'Este email já está cadastrado.']);
    exit;
}

// 1.1. Se for cadastro via Spotify, verifica se o spotify_id já existe
if ($isSpotifySignup && $spotify_id) {
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE spotify_id = ?");
    $stmt->execute([$spotify_id]);
    if ($stmt->fetch()) {
        http_response_code(409); // Conflict
        echo json_encode(['sucesso' => false, 'mensagem' => 'Esta conta do Spotify já está vinculada a outro usuário.']);
        exit;
    }
}

// 2. Verifica se o username já existe
$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
$stmt->execute([$username]);
if ($stmt->fetch()) {
    http_response_code(409); // Conflict
    echo json_encode(['sucesso' => false, 'mensagem' => 'Este username já está em uso.']);
    exit;
}

// 3. Insere o novo usuário
try {
    if ($isSpotifySignup) {
        // Query específica para Spotify com Tokens
        $sql = "INSERT INTO usuarios (
                    nome, username, email, senha_hash, perfil, ativo, 
                    spotify_id, spotify_conectado, 
                    spotify_access_token, spotify_refresh_token, spotify_token_expires
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $params = [
            $nome, $username, $email, $senha_hash, $perfil, $ativo, 
            $spotify_id, $spotify_conectado,
            $spotify_access_token, $spotify_refresh_token, $spotify_token_expires
        ];
    } else {
        // Cadastro normal
        $sql = "INSERT INTO usuarios (nome, username, email, senha_hash, perfil, ativo, spotify_conectado) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $params = [$nome, $username, $email, $senha_hash, $perfil, $ativo, $spotify_conectado];
    }

    $stmt->execute($params);
    
    http_response_code(201); 
    echo json_encode(['sucesso' => true, 'mensagem' => 'Usuário cadastrado com sucesso!']);

} catch (PDOException $e) {
    // Se algo der errado no INSERT (ex: coluna faltando)
    http_response_code(500);
    error_log("Erro cadastro.php: " . $e->getMessage());
    echo json_encode([
        'sucesso' => false, 
        'mensagem' => 'Erro interno do servidor ao cadastrar usuário.',
        'error_debug' => $e->getMessage() // Mensagem para depuração
    ]);
}
?>