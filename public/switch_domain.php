<?php
/**
 * Troca o domínio ativo da sessão. A permissão é conferida em ActiveDomain,
 * então um id enviado à mão para um domínio sem acesso é simplesmente
 * recusado.
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/includes/flash.php';

use App\Ldap\ActiveDomain;
use App\Auth;

Auth::requireLogin();

$origem = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
// Só aceita voltar para dentro da própria aplicação.
$destino = (is_string($origem) && str_contains($origem, '/')) ? basename(parse_url($origem, PHP_URL_PATH) ?: '') : '';
$destino = preg_match('/^[a-z_]+\.php$/', $destino) ? $destino : 'dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['ldap_domain_id'] ?? 0);

    if (!ActiveDomain::escolher($id)) {
        flash('error', 'Você não tem acesso a esse domínio.');
    }
}

header('Location: ' . $destino);
exit;
