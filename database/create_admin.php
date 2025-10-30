<?php
/**
 * Script para adicionar usuário admin ao banco Aurora
 */

require_once __DIR__ . '/../config/database.php';

echo "=== ADICIONANDO USUÁRIO ADMIN ===\n\n";

try {
    // Conectar ao banco
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
        ]
    );

    echo "✅ Conectado ao banco: " . DB_HOST . "\n";

    // Dados do admin
    $nome = 'Admin';
    $username = 'admin';
    $email = 'admin@socialmusic.com';
    $senha = 'admin123'; // Mude esta senha!
    $perfil = 'admin';
    $ativo = 1;

    // Gerar hash da senha
    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);

    // Verificar se já existe
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? OR username = ?");
    $stmt->execute([$email, $username]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo "⚠️  Usuário admin já existe! ID: " . $existing['id'] . "\n";
        echo "   Email: $email\n";
        echo "   Username: $username\n";
        echo "\n💡 Para alterar a senha, execute no banco:\n";
        echo "   UPDATE usuarios SET senha_hash = '" . $senha_hash . "' WHERE id = " . $existing['id'] . ";\n";
        exit(0);
    }

    // Inserir novo admin
    $stmt = $pdo->prepare("INSERT INTO usuarios (nome, username, email, senha_hash, perfil, ativo) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$nome, $username, $email, $senha_hash, $perfil, $ativo]);

    $adminId = $pdo->lastInsertId();

    echo "✅ Usuário admin criado com sucesso!\n";
    echo "   ID: $adminId\n";
    echo "   Nome: $nome\n";
    echo "   Email: $email\n";
    echo "   Username: $username\n";
    echo "   Senha: $senha (mude esta senha!)\n";
    echo "   Perfil: $perfil\n";
    echo "   Ativo: $ativo\n";

    echo "\n🔐 Hash da senha gerado: $senha_hash\n";

} catch (PDOException $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
