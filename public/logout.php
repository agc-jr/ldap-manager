<?php
require __DIR__ . '/../src/bootstrap.php';

use App\Audit\AuditLogger;
use App\Auth;

// Registrado antes de destruir a sessão, enquanto ainda sabemos quem era.
if (Auth::check()) {
    $appUser = Auth::user();
    AuditLogger::log(
        (int) $appUser['id'],
        (string) $appUser['username'],
        'auth.logout',
        'app_user',
        (string) $appUser['username']
    );
}

Auth::logout();
header('Location: login.php');
exit;
