<?php

declare(strict_types=1);

namespace App;

/**
 * Autenticação dos usuários DA FERRAMENTA (tabela app_users),
 * não confundir com autenticação no LDAP/AD do domínio.
 */
final class Auth
{
    public static function attempt(string $username, string $password): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM app_users WHERE username = :u AND is_active = 1 LIMIT 1');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        // Rehash automático se o custo do bcrypt/argon mudou
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
            $rehash = $pdo->prepare('UPDATE app_users SET password_hash = :h WHERE id = :id');
            $rehash->execute(['h' => password_hash($password, PASSWORD_BCRYPT), 'id' => $user['id']]);
        }

        $touch = $pdo->prepare('UPDATE app_users SET last_login_at = NOW() WHERE id = :id');
        $touch->execute(['id' => $user['id']]);

        return $user;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['must_change_password'] = ((int) ($user['must_change_password'] ?? 0)) === 1;
        $_SESSION['last_activity'] = time();
    }

    /**
     * Troca a senha do usuário logado e encerra a exigência de troca.
     */
    public static function changePassword(int $userId, string $novaSenha): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE app_users SET password_hash = :h, must_change_password = 0 WHERE id = :id'
        );
        $stmt->execute([
            'h'  => password_hash($novaSenha, PASSWORD_BCRYPT),
            'id' => $userId,
        ]);

        $_SESSION['must_change_password'] = false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie('PHPSESSID', '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        $timeoutMinutes = (int) Config::get('app.session_timeout_minutes', 30);
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeoutMinutes * 60) {
            self::logout();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    public static function requireLogin(bool $permitirTelaDeTroca = false): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }

        // Enquanto a senha provisória não for trocada, a única tela acessível é a
        // de troca de senha — do contrário a senha inicial valeria para sempre.
        if (!$permitirTelaDeTroca && !empty($_SESSION['must_change_password'])) {
            header('Location: change_password.php');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo 'Acesso restrito a administradores.';
            exit;
        }
    }

    public static function user(): array
    {
        return [
            'id'        => $_SESSION['user_id'] ?? null,
            'username'  => $_SESSION['username'] ?? null,
            'full_name' => $_SESSION['full_name'] ?? null,
            'role'      => $_SESSION['role'] ?? null,
        ];
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION['role'] ?? '') === 'admin';
    }
}
