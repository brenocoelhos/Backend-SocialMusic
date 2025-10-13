<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=sistema_auth', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "🔄 Iniciando migração da tabela usuarios...\n\n";
    
    // 1. Fazer backup dos dados existentes
    echo "1. Fazendo backup dos dados existentes...\n";
    $stmt = $pdo->query("SELECT * FROM usuarios");
    $dadosExistentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "   Encontrados " . count($dadosExistentes) . " usuários para migrar.\n\n";
    
    // 2. Renomear tabela atual para backup
    echo "2. Criando backup da tabela atual...\n";
    $pdo->exec("DROP TABLE IF EXISTS usuarios_backup");
    $pdo->exec("CREATE TABLE usuarios_backup AS SELECT * FROM usuarios");
    echo "   ✅ Backup criado como 'usuarios_backup'\n\n";
    
    // 3. Remover tabela atual
    echo "3. Removendo tabela atual...\n";
    $pdo->exec("DROP TABLE usuarios");
    echo "   ✅ Tabela antiga removida\n\n";
    
    // 4. Criar nova tabela com a estrutura atualizada
    echo "4. Criando nova estrutura da tabela...\n";
    $novaTabela = "
        CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            senha_hash VARCHAR(255) NOT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            perfil ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            nome VARCHAR(150) NOT NULL,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_perfil (perfil)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($novaTabela);
    echo "   ✅ Nova estrutura criada com sucesso!\n\n";
    
    // 5. Migrar dados existentes
    if (!empty($dadosExistentes)) {
        echo "5. Migrando dados existentes...\n";
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (id, email, senha_hash, username, perfil, nome, ativo, criado_em) 
            VALUES (?, ?, ?, ?, ?, ?, TRUE, ?)
        ");
        
        foreach ($dadosExistentes as $usuario) {
            // Ajustar os dados para a nova estrutura
            $username = substr($usuario['username'], 0, 50); // Truncar se necessário
            $nome = substr($usuario['nome'], 0, 150); // Truncar se necessário
            $criadoEm = $usuario['created_at'] ?? date('Y-m-d H:i:s');
            
            $stmt->execute([
                $usuario['id'],
                $usuario['email'],
                $usuario['senha_hash'],
                $username,
                $usuario['perfil'],
                $nome,
                $criadoEm
            ]);
        }
        
        echo "   ✅ " . count($dadosExistentes) . " usuários migrados com sucesso!\n\n";
    }
    
    // 6. Verificar nova estrutura
    echo "6. Verificando nova estrutura:\n";
    echo "   ============================\n";
    $stmt = $pdo->query('DESCRIBE usuarios');
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   " . sprintf("%-18s %-35s %-8s\n", $row['Field'], $row['Type'], $row['Null']);
    }
    
    // 7. Verificar dados migrados
    echo "\n7. Verificando dados migrados:\n";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $total = $stmt->fetch()['total'];
    echo "   Total de usuários na nova tabela: $total\n";
    
    if ($total > 0) {
        echo "\n   Exemplo de usuário migrado:\n";
        $stmt = $pdo->query("SELECT * FROM usuarios LIMIT 1");
        $exemplo = $stmt->fetch(PDO::FETCH_ASSOC);
        foreach ($exemplo as $campo => $valor) {
            echo "   $campo: $valor\n";
        }
    }
    
    echo "\n🎉 Migração concluída com sucesso!\n";
    echo "💡 A tabela antiga foi salva como 'usuarios_backup' por segurança.\n";
    echo "💡 Você pode removê-la depois de confirmar que tudo está funcionando.\n";
    
} catch(Exception $e) {
    echo "❌ Erro durante a migração: " . $e->getMessage() . "\n";
    echo "💡 Os dados originais estão seguros na tabela 'usuarios_backup'.\n";
}
?>