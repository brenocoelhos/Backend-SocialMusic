<?php
$host = 'localhost';
$db_name = 'socialmusic'; // <-- MUDE AQUI
$username = 'root'; // <-- MUDE AQUI
$password = ''; // <-- MUDE AQUI

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Em produção, não exiba o erro detalhado
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de conexão com o banco de dados.']);
    exit;
}
?>