<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once 'header.php';
require_once 'conexao.php';

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

// Verifica se o utilizador está logado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Utilizador não autenticado.']);
    exit;
}
$utilizador_id = $_SESSION['usuario_id'];

// Verifica se um ficheiro foi enviado
if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhum ficheiro de foto enviado.']);
    exit;
}

$file = $_FILES['foto'];
$file_tmp_path = $file['tmp_name'];
$file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed_extensions = ['jpg', 'jpeg', 'png'];

if (!in_array($file_extension, $allowed_extensions)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Tipo de ficheiro inválido (apenas JPG, PNG).']);
    exit;
}

// Configuração do S3 (lê as Variáveis de Ambiente do Render)
$bucket_name = getenv('S3_BUCKET_NAME');
$s3 = new S3Client([
    'version' => 'latest',
    'region'  => getenv('AWS_REGION'),
    'credentials' => [
        'key'    => getenv('AWS_ACCESS_KEY_ID'),
        'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
    ]
]);

// Gera um nome de ficheiro único
$key = 'fotos_perfil/' . $utilizador_id . '_' . uniqid() . '.' . $file_extension;

try {
    // 1. Faz o upload para o S3
    $result = $s3->putObject([
        'Bucket'     => $bucket_name,
        'Key'        => $key,
        'SourceFile' => $file_tmp_path,
        'ACL'        => 'public-read' // Define o ficheiro como público
    ]);

    // 2. Pega no URL público do ficheiro
    $public_url = $result['ObjectURL'];

    // 3. Atualiza a tabela 'usuarios' com o novo URL
    $stmt = $pdo->prepare("UPDATE usuarios SET foto_perfil = ? WHERE id = ?");
    $stmt->execute([$public_url, $utilizador_id]);

    // 4. Retorna o novo URL para o Vue
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Foto atualizada!',
        'nova_url' => $public_url
    ]);

} catch (S3Exception $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao fazer upload para o S3.']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao atualizar a base de dados.']);
}
?>