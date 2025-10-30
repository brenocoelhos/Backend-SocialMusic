<?php
/**
 * Script para tornar um usuário admin
 * Acesse: https://backend-socialmusic.onrender.com/database/tornar_admin.php?email=SEU_EMAIL
 */

require_once __DIR__ . '/../config/database.php';

$email = $_GET['email'] ?? '';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Tornar Admin - SocialMusic</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        h1 { color: #4ec9b0; }
        .success { color: #4ec9b0; padding: 15px; background: #1e3a1e; border-radius: 5px; }
        .error { color: #f48771; padding: 15px; background: #3a1e1e; border-radius: 5px; }
        .form { background: #2d2d2d; padding: 20px; border-radius: 10px; margin-top: 20px; }
        input { padding: 10px; width: 300px; background: #3e3e3e; border: 1px solid #4ec9b0; color: #d4d4d4; }
        button { padding: 10px 20px; background: #4ec9b0; color: #1e1e1e; border: none; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>
<h1>👑 Tornar Usuário Admin</h1>";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    if (!empty($email)) {
        // Verificar se o usuário existe
        $stmt = $pdo->prepare("SELECT id, nome, email, perfil FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario) {
            if ($usuario['perfil'] === 'admin') {
                echo "<div class='success'>";
                echo "✅ O usuário <strong>{$usuario['nome']}</strong> ({$usuario['email']}) JÁ É ADMIN!";
                echo "</div>";
            } else {
                // Tornar admin
                $stmt = $pdo->prepare("UPDATE usuarios SET perfil = 'admin' WHERE email = ?");
                $stmt->execute([$email]);
                
                echo "<div class='success'>";
                echo "✅ SUCESSO! O usuário <strong>{$usuario['nome']}</strong> agora é ADMIN!<br><br>";
                echo "📧 Email: {$usuario['email']}<br>";
                echo "👑 Perfil: ADMIN";
                echo "</div>";
            }
        } else {
            echo "<div class='error'>";
            echo "❌ Usuário não encontrado com o email: <strong>{$email}</strong>";
            echo "</div>";
        }
    }
    
    // Formulário
    echo "<div class='form'>";
    echo "<h3>Digite o email do usuário para torná-lo admin:</h3>";
    echo "<form method='GET'>";
    echo "<input type='email' name='email' placeholder='email@exemplo.com' required>";
    echo "<button type='submit'>👑 Tornar Admin</button>";
    echo "</form>";
    echo "</div>";
    
    // Listar usuários atuais
    echo "<h2>📋 Usuários Cadastrados:</h2>";
    $stmt = $pdo->query("SELECT id, nome, email, perfil FROM usuarios ORDER BY id");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table style='width: 100%; background: #2d2d2d; margin-top: 10px;'>";
    echo "<tr style='background: #4ec9b0; color: #1e1e1e;'>
            <th style='padding: 10px;'>ID</th>
            <th style='padding: 10px;'>Nome</th>
            <th style='padding: 10px;'>Email</th>
            <th style='padding: 10px;'>Perfil</th>
          </tr>";
    
    foreach ($usuarios as $u) {
        $icon = $u['perfil'] === 'admin' ? '👑' : '👤';
        echo "<tr style='border-bottom: 1px solid #3e3e3e;'>";
        echo "<td style='padding: 10px;'>{$u['id']}</td>";
        echo "<td style='padding: 10px;'>{$u['nome']}</td>";
        echo "<td style='padding: 10px;'>{$u['email']}</td>";
        echo "<td style='padding: 10px;'>{$icon} " . strtoupper($u['perfil']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Erro: " . $e->getMessage() . "</div>";
}

echo "</body></html>";
?>
