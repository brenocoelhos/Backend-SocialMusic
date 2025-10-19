<?php
// classes/AuthManager.php
class AuthManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Gera um token de autenticação
     */
    private function generateToken() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Faz login e retorna token
     */
    public function login($usuario, $senha) {
        // Verificar credenciais
        $isEmail = filter_var($usuario, FILTER_VALIDATE_EMAIL);
        
        if ($isEmail) {
            $stmt = $this->pdo->prepare("SELECT id, email, username, senha_hash, perfil, nome, ativo FROM usuarios WHERE email = ?");
        } else {
            $stmt = $this->pdo->prepare("SELECT id, email, username, senha_hash, perfil, nome, ativo FROM usuarios WHERE username = ?");
        }
        
        $stmt->execute([$usuario]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($senha, $user['senha_hash'])) {
            return false;
        }
        
        if (!$user['ativo']) {
            return false;
        }
        
        // Gerar token
        $token = $this->generateToken();
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hora
        
        // Salvar token no banco
        $stmt = $this->pdo->prepare("
            INSERT INTO auth_tokens (user_id, token, expires_at, created_at) 
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)
        ");
        
        $stmt->execute([$user['id'], $token, $expires]);
        
        return [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'nome' => $user['nome'],
                'perfil' => $user['perfil']
            ]
        ];
    }
    
    /**
     * Verifica se o token é válido e retorna dados do usuário
     */
    public function verifyToken($token) {
        if (!$token) return false;
        
        $stmt = $this->pdo->prepare("
            SELECT u.id, u.email, u.username, u.nome, u.perfil, t.expires_at
            FROM auth_tokens t
            JOIN usuarios u ON t.user_id = u.id
            WHERE t.token = ? AND t.expires_at > NOW() AND u.ativo = 1
        ");
        
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        
        if ($result) {
            // Atualizar tempo de expiração
            $newExpires = date('Y-m-d H:i:s', time() + 3600);
            $updateStmt = $this->pdo->prepare("UPDATE auth_tokens SET expires_at = ? WHERE token = ?");
            $updateStmt->execute([$newExpires, $token]);
        }
        
        return $result;
    }
    
    /**
     * Remove token (logout)
     */
    public function logout($token) {
        $stmt = $this->pdo->prepare("DELETE FROM auth_tokens WHERE token = ?");
        return $stmt->execute([$token]);
    }
}
?>