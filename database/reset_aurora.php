<?php
/**
 * Script para resetar o banco Aurora da AWS
 * Este script limpa todas as tabelas e recria o schema
 */

require_once __DIR__ . '/../config/database.php';

echo "=== RESETANDO BANCO AURORA DA AWS ===\n\n";

try {
    // Conectar ao banco
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
        ]
    );

    echo "✅ Conectado ao banco: " . DB_HOST . "\n";

    // Selecionar o banco
    $pdo->exec("USE " . DB_NAME);
    echo "✅ Usando banco: " . DB_NAME . "\n";

    // Iniciar transação
    $pdo->beginTransaction();

    // Listar todas as tabelas
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($tables)) {
        echo "\n🗑️  Removendo tabelas existentes...\n";

        // Desabilitar foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        // Dropar todas as tabelas
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
            echo "   - Removida: $table\n";
        }

        // Reabilitar foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        echo "✅ Todas as tabelas removidas!\n";
    } else {
        echo "ℹ️  Nenhuma tabela encontrada para remover.\n";
    }

    // Executar o schema.sql para recriar as tabelas
    echo "\n🔄 Executando schema.sql...\n";

    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Arquivo schema.sql não encontrado: $schemaFile");
    }

    $sql = file_get_contents($schemaFile);

    // Remover a linha CREATE DATABASE (já existe)
    $sql = preg_replace('/CREATE DATABASE.*;\s*/i', '', $sql);
    $sql = preg_replace('/USE.*;\s*/i', '', $sql);
    $sql = preg_replace('/START TRANSACTION;\s*/i', '', $sql);
    $sql = preg_replace('/COMMIT;\s*/i', '', $sql);
    $sql = preg_replace('/SET.*;\s*/i', '', $sql);
    $sql = preg_replace('/\/\*!\d+.*?\*\//s', '', $sql); // Remove comentários MySQL específicos

    // Split into statements
    $statements = array_filter(
        array_map('trim',
            explode(';', $sql)
        )
    );

    foreach($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
            echo "   + Executado: " . substr($statement, 0, 50) . "...\n";
        }
    }

    // Commit da transação
    $pdo->commit();

    echo "\n🎉 BANCO RESETADO COM SUCESSO!\n";
    echo "📊 Todas as tabelas foram recriadas.\n";
    echo "👤 Você pode adicionar usuários admin manualmente.\n";

} catch (PDOException $e) {
    // Rollback em caso de erro
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Exception $rollbackException) {
        // Ignorar erros de rollback
    }

    echo "\n❌ ERRO ao resetar banco: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "\n❌ ERRO geral: " . $e->getMessage() . "\n";
    exit(1);
}
