<?php
require_once __DIR__ . '/../../vendor/autoload.php'; 
require_once __DIR__ . '/../core/header.php';
require_once __DIR__ . '/../core/conexao.php';

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); exit;
}
$utilizador_id = $_SESSION['usuario_id'];

// Configuração do S3
$bucket_name = getenv('S3_BUCKET_NAME');
$s3 = new S3Client([
    'version' => 'latest',
    'region'  => getenv('AWS_REGION'),
    'credentials' => [
        'key'    => getenv('AWS_ACCESS_KEY_ID'),
        'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
    ]
]);

try {
    // 1. Descobrir qual é o ficheiro atual
    $stmt_get = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id = ?");
    $stmt_get->execute([$utilizador_id]);
    $url_antiga = $stmt_get->fetchColumn();

    if (!empty($url_antiga)) {
        // 2. Apagar o ficheiro antigo do S3
 
        $key_antiga = 'fotos_perfil/' . basename($url_antiga);
        
        $s3->deleteObject([
            'Bucket' => $bucket_name,
            'Key'    => $key_antiga
        ]);
    }

    // 3. Definir 'foto_perfil' como NULL na BD
    $stmt_set_null = $pdo->prepare("UPDATE usuarios SET foto_perfil = NULL WHERE id = ?");
    $stmt_set_null->execute([$utilizador_id]);

    // 4. Gerar um novo URL padrão
    $default_avatar = 'https://i.pravatar.cc/150?u=' . $utilizador_id;

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Foto removida.',
        'nova_url' => $default_avatar
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao remover a foto.']);
}
?>