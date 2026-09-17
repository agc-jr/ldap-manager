<?php
/**
 * Leva o domínio configurado em config/config.php (formato antigo, de quando a
 * ferramenta atendia um só domínio) para a tabela ldap_domains.
 *
 * Rode uma vez, depois de aplicar as tabelas novas:
 *   php bin/migrar-dominio.php
 *
 * É seguro rodar de novo: se já houver um domínio com o mesmo nome, nada é
 * duplicado. A senha do bind é cifrada na gravação.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Ldap\DomainRepository;
use App\Ldap\LdapConnection;

$cfg = Config::get('ldap');

if (!is_array($cfg) || ($cfg['host'] ?? '') === '') {
    echo "Nada a migrar: config/config.php não tem um bloco 'ldap'.\n";
    echo "Cadastre o domínio pela interface, em Domínios.\n";
    exit(0);
}

$repo = new DomainRepository();

// Nome de exibição: o config antigo não tem esse campo, então usamos o NetBIOS
// ou o próprio domínio.
$nome = (string) ($cfg['domain_netbios'] ?? '') ?: (string) ($cfg['domain_upn'] ?? 'Domínio principal');

foreach ($repo->todos(false) as $existente) {
    if (strcasecmp($existente['name'], $nome) === 0) {
        echo "O domínio \"{$nome}\" já está cadastrado (id {$existente['id']}). Nada a fazer.\n";
        exit(0);
    }
}

$dados = [
    'name'                => $nome,
    'host'                => (string) $cfg['host'],
    'port'                => (int) ($cfg['port'] ?? 636),
    'tls_verify'          => (bool) ($cfg['tls_verify'] ?? false),
    'base_dn'             => (string) ($cfg['base_dn'] ?? ''),
    'bind_dn'             => (string) ($cfg['bind_dn'] ?? ''),
    'bind_password'       => (string) ($cfg['bind_password'] ?? ''),
    'domain_upn'          => (string) ($cfg['domain_upn'] ?? ''),
    'domain_netbios'      => (string) ($cfg['domain_netbios'] ?? ''),
    'default_user_ou'     => (string) ($cfg['default_user_ou'] ?? ''),
    'default_group_ou'    => (string) ($cfg['default_group_ou'] ?? ''),
    'password_min_length' => (int) ($cfg['password_min_length'] ?? 7),
    'is_active'           => true,
];

$erros = DomainRepository::validar($dados);
if ($erros !== []) {
    echo "A configuração atual não passa na validação:\n";
    foreach ($erros as $e) {
        echo "  - {$e}\n";
    }
    exit(1);
}

echo "Testando a conexão antes de gravar...\n";
try {
    (new LdapConnection($dados))->close();
    echo "  conexão OK\n";
} catch (Throwable $e) {
    echo "  FALHOU: " . $e->getMessage() . "\n";
    echo "  Migração abortada — corrija o config.php ou cadastre pela interface.\n";
    exit(1);
}

$id = $repo->criar($dados);

echo "\nDomínio \"{$nome}\" migrado para o banco (id {$id}).\n";
echo "A senha do bind foi gravada cifrada.\n\n";
echo "Agora você pode remover o bloco 'ldap' de config/config.php — ele deixou de ser usado.\n";
echo "Mantenha 'db' e 'app' (a app_key é o que decifra a senha gravada).\n";
