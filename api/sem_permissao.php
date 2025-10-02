<?php
/**
 * Página de Acesso Negado
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$erro = $_GET['erro'] ?? 'desconhecido';
$perfil_necessario = $_GET['perfil_necessario'] ?? '';

$mensagens = [
    'sessao_invalida' => 'Sessão inválida. Faça login novamente.',
    'sessao_expirada' => 'Sua sessão expirou. Faça login novamente.',
    'perfil_indefinido' => 'Perfil de usuário não definido.',
    'sem_permissao' => 'Você não tem permissão para acessar este recurso.',
    'desconhecido' => 'Acesso negado.'
];

$mensagem = $mensagens[$erro] ?? $mensagens['desconhecido'];

if ($erro === 'sem_permissao' && !empty($perfil_necessario)) {
    $mensagem .= " Perfil necessário: " . htmlspecialchars($perfil_necessario);
}

http_response_code(403);
echo json_encode([
    'sucesso' => false,
    'erro' => $erro,
    'mensagem' => $mensagem,
    'requer_login' => in_array($erro, ['sessao_invalida', 'sessao_expirada'])
]);
?>