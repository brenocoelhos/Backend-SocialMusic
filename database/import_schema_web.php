<?php
/**
 * Script WEB para importar schema.sql para o Aurora da AWS
 * Acesse via navegador: https://backend-socialmusic.onrender.com/database/import_schema_web.php
 */

require_once __DIR__ . '/../config/database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Importar Schema SQL</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        h1 { color: #4ec9b0; }
        .success { color: #4ec9b0; }
        .error { color: #f48771; }
        .info { color: #569cd6; }
        pre { background: #2d2d2d; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>";

echo "<h1>🔄 IMPORTANDO SCHEMA SQL PARA AURORA</h1>";
echo "<pre>";

try {
    // Conectar ao MySQL sem especificar database
    echo "<span class='info'>📡 Conectando ao Aurora...</span>\n";
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
    echo "<span class='success'>✅ Conectado ao banco: " . DB_HOST . "</span>\n\n";

    // Criar database se não existir
    echo "<span class='info'>📦 Criando/verificando database...</span>\n";
    $dbName = DB_NAME;
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    echo "<span class='success'>✅ Database '{$dbName}' pronto!</span>\n\n";

    // Usar o database
    $pdo->exec("USE `{$dbName}`");

    // Ler o arquivo schema.sql
    echo "<span class='info'>📄 Lendo arquivo schema.sql...</span>\n";
    $sqlFile = __DIR__ . '/schema.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Arquivo schema.sql não encontrado em: {$sqlFile}");
    }
    
    $sql = file_get_contents($sqlFile);
    echo "<span class='success'>✅ Arquivo lido com sucesso!</span>\n\n";

    // Remover comentários e dividir em statements
    echo "<span class='info'>⚙️ Processando SQL...</span>\n";
    
    // Remove comentários SQL (-- e #)
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/#.*$/m', '', $sql);
    
    // Remove comentários /* */
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Dividir em statements individuais
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   !preg_match('/^(SET|START TRANSACTION|COMMIT)/i', $stmt);
        }
    );

    echo "<span class='success'>✅ " . count($statements) . " comandos SQL encontrados</span>\n\n";

    // Desabilitar verificação de chaves estrangeiras temporariamente
    echo "<span class='info'>🔓 Desabilitando verificação de foreign keys...</span>\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Executar cada statement
    $executed = 0;
    $errors = 0;
    
    foreach ($statements as $index => $statement) {
        try {
            // Detectar tipo de comando
            if (preg_match('/^CREATE TABLE/i', $statement)) {
                preg_match('/CREATE TABLE `?(\w+)`?/i', $statement, $matches);
                $tableName = $matches[1] ?? 'desconhecida';
                echo "<span class='info'>📋 Criando tabela '{$tableName}'...</span>\n";
            } elseif (preg_match('/^ALTER TABLE/i', $statement)) {
                preg_match('/ALTER TABLE `?(\w+)`?/i', $statement, $matches);
                $tableName = $matches[1] ?? 'desconhecida';
                echo "<span class='info'>🔧 Modificando tabela '{$tableName}'...</span>\n";
            } elseif (preg_match('/^INSERT INTO/i', $statement)) {
                preg_match('/INSERT INTO `?(\w+)`?/i', $statement, $matches);
                $tableName = $matches[1] ?? 'desconhecida';
                echo "<span class='info'>📝 Inserindo dados em '{$tableName}'...</span>\n";
            }
            
            $pdo->exec($statement);
            $executed++;
            
        } catch (PDOException $e) {
            $errors++;
            echo "<span class='error'>❌ Erro no statement #" . ($index + 1) . ": " . $e->getMessage() . "</span>\n";
            // Continua executando os próximos statements
        }
    }
    
    // Reabilitar verificação de chaves estrangeiras
    echo "\n<span class='info'>🔒 Reabilitando verificação de foreign keys...</span>\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "\n<h2 class='success'>✅ IMPORTAÇÃO CONCLUÍDA!</h2>";
    echo "<span class='success'>Total de comandos executados: {$executed}</span>\n";
    if ($errors > 0) {
        echo "<span class='error'>Total de erros: {$errors}</span>\n";
    }
    
    // Verificar tabelas criadas
    echo "\n<span class='info'>📊 Verificando tabelas criadas...</span>\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<span class='success'>✅ Tabelas encontradas:</span>\n";
    foreach ($tables as $table) {
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM `{$table}`");
        $count = $countStmt->fetch()['total'];
        echo "   - {$table} ({$count} registros)\n";
    }

} catch (Exception $e) {
    echo "\n<span class='error'>❌ ERRO: " . $e->getMessage() . "</span>\n";
    echo "<span class='error'>Stack trace:</span>\n";
    echo "<span class='error'>" . $e->getTraceAsString() . "</span>\n";
}

echo "</pre>";
echo "</body></html>";
?>
