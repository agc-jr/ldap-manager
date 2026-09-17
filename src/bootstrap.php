<?php

declare(strict_types=1);

// Autoloader simples PSR-4-like (App\ -> src/), sem depender de Composer instalado no servidor.
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// Sessão segura: cookie HttpOnly, SameSite estrito, e (idealmente) Secure sob HTTPS.
$cookieSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $cookieSecure,
    'httponly' => true,
    'samesite' => 'Strict',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', '0'); // nunca mostrar erros na tela em produção
ini_set('log_errors', '1');

date_default_timezone_set('America/Sao_Paulo');

/*
 * Recém-clonado, sem config/config.php, qualquer página levaria a um erro 500
 * na primeira consulta ao banco. Quem acabou de clonar o repositório vai ao
 * instalador, não a uma tela branca.
 *
 * Não vale para a linha de comando (os scripts em bin/ dão a própria mensagem)
 * nem para o próprio instalador, que existe justamente para essa situação.
 */
if (PHP_SAPI !== 'cli' && !App\Config::existe()) {
    $scriptAtual = basename($_SERVER['SCRIPT_NAME'] ?? '');

    if ($scriptAtual !== 'install.php') {
        header('Location: install.php');
        exit;
    }
}
