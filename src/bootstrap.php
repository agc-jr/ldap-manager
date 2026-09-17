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
