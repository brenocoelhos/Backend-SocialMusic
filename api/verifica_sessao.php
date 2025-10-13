<?php

// Inicia a sessão se ainda não estiver iniciada
if (session_status() === PHP_SESSION_NONE) {
    // Configurações de segurança da sessão
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');

    // Se estiver usando HTTPS, habilite isso:
    // ini_set('session.cookie_secure', 1);

    session_start();
}


//Verifica se o usuário está autenticado
function verificaAutenticacao()
{
    if (!isset($_SESSION['usuario']) || !isset($_SESSION['usuario_id'])) {
        header('Location: /api/sem_permissao.php?erro=sessao_invalida');
        exit;
    }

    // Verifica tempo de inatividade (30 minutos)
    if (isset($_SESSION['ultima_atividade'])) {
        $inatividade = time() - $_SESSION['ultima_atividade'];
        if ($inatividade > 1800) { // 30 minutos
            session_unset();
            session_destroy();
            header('Location: /api/sem_permissao.php?erro=sessao_expirada');
            exit;
        }
    }

    // Atualiza timestamp de última atividade
    $_SESSION['ultima_atividade'] = time();
}

/**
 * //Verifica se o usuário tem o perfil necessário
 * @param string|array $perfilRequerido - Perfil(s) necessário(s)
 */
function verificaPerfil($perfilRequerido)
{
    if (!isset($_SESSION['perfil'])) {
        header('Location: /api/sem_permissao.php?erro=perfil_indefinido');
        exit;
    }

    $perfisPermitidos = is_array($perfilRequerido) ? $perfilRequerido : [$perfilRequerido];

    if (!in_array($_SESSION['perfil'], $perfisPermitidos)) {
        header('Location: /api/sem_permissao.php?erro=sem_permissao&perfil_necessario=' . implode(',', $perfisPermitidos));
        exit;
    }
}

/**
 * //Obtém dados do usuário da sessão
 * @return array
 */
function getUsuarioSessao()
{
    return [
        'id' => $_SESSION['usuario_id'] ?? null,
        'email' => $_SESSION['usuario'] ?? null,
        'nome' => $_SESSION['nome'] ?? null,
        'perfil' => $_SESSION['perfil'] ?? null
    ];
}

// Verifica autenticação automaticamente ao incluir este arquivo
verificaAutenticacao();
?>