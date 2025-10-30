<?php
/**
 * Script para visualizar usuários do banco
 * Acesse: https://backend-socialmusic.onrender.com/database/ver_usuarios.php
 */

require_once __DIR__ . '/../config/database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Usuários - SocialMusic</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        h1 { color: #4ec9b0; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: #2d2d2d; }
        th { background: #4ec9b0; color: #1e1e1e; padding: 10px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #3e3e3e; }
        tr:hover { background: #3e3e3e; }
        .admin { color: #f48771; font-weight: bold; }
        .user { color: #569cd6; }
        .info { color: #569cd6; margin-bottom: 20px; }
    </style>
</head>
<body>";

echo "<h1>👥 Usuários do Sistema</h1>";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Buscar todos os usuários
    $stmt = $pdo->query("SELECT id, nome, username, email, perfil, ativo FROM usuarios ORDER BY id DESC");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div class='info'>";
    echo "📊 Total de usuários: <strong>" . count($usuarios) . "</strong><br>";
    echo "🔗 Banco: <strong>" . DB_HOST . "</strong><br>";
    echo "💾 Database: <strong>" . DB_NAME . "</strong>";
    echo "</div>";
    
    if (count($usuarios) > 0) {
        echo "<table>";
        echo "<tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Username</th>
                <th>Email</th>
                <th>Perfil</th>
                <th>Status</th>
              </tr>";
        
        foreach ($usuarios as $user) {
            $perfilClass = $user['perfil'] === 'admin' ? 'admin' : 'user';
            $perfilIcon = $user['perfil'] === 'admin' ? '👑' : '👤';
            $statusIcon = $user['ativo'] ? '✅' : '❌';
            
            echo "<tr>";
            echo "<td><strong>#{$user['id']}</strong></td>";
            echo "<td>{$user['nome']}</td>";
            echo "<td>{$user['username']}</td>";
            echo "<td>{$user['email']}</td>";
            echo "<td class='{$perfilClass}'>{$perfilIcon} " . strtoupper($user['perfil']) . "</td>";
            echo "<td>{$statusIcon} " . ($user['ativo'] ? 'Ativo' : 'Inativo') . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    } else {
        echo "<p>Nenhum usuário encontrado.</p>";
    }
    
    // Estatísticas
    echo "<br><h2>📊 Estatísticas</h2>";
    
    $stats = [
        'admins' => $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'")->fetchColumn(),
        'users' => $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'user'")->fetchColumn(),
        'ativos' => $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1")->fetchColumn(),
        'musicas' => $pdo->query("SELECT COUNT(*) FROM musicas")->fetchColumn(),
        'avaliacoes' => $pdo->query("SELECT COUNT(*) FROM avaliacoes")->fetchColumn(),
    ];
    
    echo "<table>";
    echo "<tr><th>Métrica</th><th>Valor</th></tr>";
    echo "<tr><td>👑 Administradores</td><td><strong>{$stats['admins']}</strong></td></tr>";
    echo "<tr><td>👤 Usuários normais</td><td><strong>{$stats['users']}</strong></td></tr>";
    echo "<tr><td>✅ Usuários ativos</td><td><strong>{$stats['ativos']}</strong></td></tr>";
    echo "<tr><td>🎵 Músicas cadastradas</td><td><strong>{$stats['musicas']}</strong></td></tr>";
    echo "<tr><td>⭐ Avaliações</td><td><strong>{$stats['avaliacoes']}</strong></td></tr>";
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: #f48771;'>❌ Erro: " . $e->getMessage() . "</p>";
}

echo "</body></html>";
?>
