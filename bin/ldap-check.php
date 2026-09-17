<?php
/**
 * Diagnóstico da conexão com o AD — SOMENTE LEITURA. Não cria, altera nem
 * remove nada no diretório; pode ser rodado em produção com segurança.
 *
 * Uso:  php bin/ldap-check.php ["Nome do dominio"]
 *
 * Serve para validar, antes de usar a interface web, se:
 *   - a extensão ldap está habilitada;
 *   - a conta de serviço consegue fazer bind via LDAPS;
 *   - o base_dn configurado bate com o domínio real (lido do RootDSE);
 *   - os filtros LDAP encontram os usuários e grupos que existem de fato;
 *   - os atributos que a interface consome chegam preenchidos.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Ldap\DomainRepository;
use App\Ldap\GroupRepository;
use App\Ldap\LdapConnection;
use App\Ldap\UserRepository;

function titulo(string $texto): void
{
    echo "\n" . str_repeat('=', 66) . "\n {$texto}\n" . str_repeat('=', 66) . "\n";
}

function ok(string $m): void
{
    echo "  [ OK ]   {$m}\n";
}

function aviso(string $m): void
{
    echo "  [AVISO]  {$m}\n";
}

function erro(string $m): void
{
    echo "  [FALHA]  {$m}\n";
}

$falhas = 0;

// ---------------------------------------------------------------- ambiente
titulo('1. Ambiente PHP');

printf("  PHP %s\n", PHP_VERSION);

if (extension_loaded('ldap')) {
    ok('extensão ldap carregada');
} else {
    erro('extensão ldap NAO carregada - habilite php-ldap e reinicie o Apache');
    exit(1);
}

if (extension_loaded('pdo_mysql')) {
    ok('extensão pdo_mysql carregada');
} else {
    aviso('pdo_mysql ausente (necessária para login e auditoria)');
}

// ------------------------------------------------------------ configuração
titulo('2. Configuração');

/*
 * Os domínios ficam cadastrados no banco. Sem argumento, o diagnóstico roda
 * sobre o primeiro cadastrado; passe o nome para escolher outro:
 *   php bin/ldap-check.php "Administrativo"
 */
try {
    $dominios = (new DomainRepository())->todos(false);
} catch (Throwable $e) {
    erro('Não foi possível ler os domínios do banco: ' . $e->getMessage());
    erro('O sistema já foi instalado? Acesse public/install.php pelo navegador.');
    exit(1);
}

if ($dominios === []) {
    erro('Nenhum domínio cadastrado. Cadastre um na tela "Domínios" da interface.');
    exit(1);
}

$escolhido = $argv[1] ?? null;
$cfg = null;

foreach ($dominios as $d) {
    if ($escolhido === null || strcasecmp($d['name'], $escolhido) === 0) {
        $cfg = $d;
        break;
    }
}

if ($cfg === null) {
    erro("Domínio \"{$escolhido}\" não encontrado. Cadastrados: "
        . implode(', ', array_map(static fn ($d) => $d['name'], $dominios)));
    exit(1);
}

if (count($dominios) > 1 && $escolhido === null) {
    aviso('há ' . count($dominios) . ' domínios cadastrados; testando o primeiro. '
        . 'Passe o nome como argumento para testar outro.');
}

printf("  domínio ......... %s\n", $cfg['name']);
printf("  host ............ %s\n", $cfg['host']);
printf("  port ............ %d\n", $cfg['port']);
printf("  bind_dn ......... %s\n", $cfg['bind_dn']);
printf(
    "  bind_password ... %s\n",
    $cfg['bind_password'] === '' ? '(vazia)' : '(definida, ' . strlen($cfg['bind_password']) . ' caracteres)'
);
printf("  base_dn ......... %s\n", $cfg['base_dn']);
printf("  tls_verify ...... %s\n", var_export($cfg['tls_verify'] ?? true, true));

if ((int) $cfg['port'] !== 636 || !str_starts_with($cfg['host'], 'ldaps://')) {
    aviso('a conexão não é LDAPS - definir/resetar senha (unicodePwd) vai falhar. Use ldaps:// na porta 636.');
}

if (str_contains((string) $cfg["bind_password"], "TROQUE")) {
    erro('bind_password ainda está com o valor de exemplo');
    $falhas++;
}

// -------------------------------------------------------------- conexão
titulo('3. Conexão e autenticação (bind)');

$inicio = microtime(true);
try {
    $ldap = new LdapConnection($cfg);
    ok(sprintf('bind bem-sucedido em %.0f ms', (microtime(true) - $inicio) * 1000));
} catch (Throwable $e) {
    erro($e->getMessage());
    echo "\n  Dicas conforme a mensagem acima:\n";
    echo "   - \"Can't contact LDAP server\": firewall/rota até o host, ou certificado recusado.\n";
    echo "     Confirme tls_verify => false no config e teste no shell:\n";
    echo "       openssl s_client -connect HOST:636\n";
    echo "   - \"Invalid credentials\": usuário ou senha do bind errados. Com Samba AD o\n";
    echo "     formato mais confiável é UPN: usuario arroba dominio.\n";
    exit(1);
}

// -------------------------------------------------------------- RootDSE
titulo('4. RootDSE (identidade real do domínio)');

$root = @ldap_read($ldap->raw(), '', '(objectClass=*)', [
    'defaultNamingContext',
    'dnsHostName',
    'serverName',
]);

if ($root !== false) {
    $entradas = ldap_get_entries($ldap->raw(), $root);
    $defaultNc = $entradas[0]['defaultnamingcontext'][0] ?? null;

    printf("  defaultNamingContext ... %s\n", $defaultNc ?? '(não informado)');
    printf("  dnsHostName ............ %s\n", $entradas[0]['dnshostname'][0] ?? '(não informado)');

    if ($defaultNc !== null && strcasecmp($defaultNc, $cfg['base_dn']) !== 0) {
        erro("base_dn configurado ({$cfg['base_dn']}) é DIFERENTE do domínio real ({$defaultNc})");
        $falhas++;
    } elseif ($defaultNc !== null) {
        ok('base_dn confere com o domínio real');
    }
} else {
    aviso('não foi possível ler o RootDSE (não impede o resto)');
}

// -------------------------------------------------------------- usuários
titulo('5. Usuários (filtro usado pela interface)');

echo "  filtro: (&(objectClass=user)(objectCategory=person))\n\n";

try {
    $usuarios = (new UserRepository($ldap))->all();
} catch (Throwable $e) {
    erro($e->getMessage());
    exit(1);
}

$total = count($usuarios);

if ($total > 0) {
    ok("{$total} usuário(s) encontrado(s)");
} else {
    erro('nenhum usuário encontrado - o filtro ou o base_dn não batem com a estrutura real');
    $falhas++;
}

if ($total > 0 && $total % 1000 === 0) {
    aviso('total é múltiplo exato de 1000 - confirme se a paginação trouxe tudo mesmo');
}

if ($total > 0) {
    // Os atributos abaixo são os que a interface realmente consome. Se algum
    // vier vazio em TODOS os usuários, a tela correspondente fica em branco.
    $criticos = ['sAMAccountName', 'displayName', 'userAccountControl', 'pwdLastSet'];

    echo "\n  Preenchimento dos atributos que a interface usa:\n";
    foreach ($criticos as $attr) {
        $preenchidos = 0;
        foreach ($usuarios as $u) {
            if (isset($u[$attr]) && $u[$attr] !== '') {
                $preenchidos++;
            }
        }

        $linha = sprintf('    %-20s %d/%d', $attr, $preenchidos, $total);

        if ($preenchidos === 0) {
            echo $linha . "   <-- VAZIO EM TODOS (a tela vai aparecer em branco)\n";
            $falhas++;
        } elseif ($preenchidos < $total) {
            echo $linha . "   (parcial - normal para displayName e mail)\n";
        } else {
            echo $linha . "\n";
        }
    }

    $desativados = count(array_filter($usuarios, fn ($u) => $u['is_disabled'] ?? false));
    $trocarSenha = count(array_filter($usuarios, fn ($u) => $u['password_expired'] ?? false));

    printf("\n  ativos ...................... %d\n", $total - $desativados);
    printf("  desativados ................. %d\n", $desativados);
    printf("  aguardando troca de senha ... %d\n", $trocarSenha);

    echo "\n  Amostra (até 10):\n";
    printf("    %-22s %-28s %-9s %s\n", 'sAMAccountName', 'displayName', 'estado', 'grupos');

    foreach (array_slice($usuarios, 0, 10) as $u) {
        printf(
            "    %-22s %-28s %-9s %s\n",
            substr((string) ($u['sAMAccountName'] ?? '?'), 0, 22),
            substr((string) ($u['displayName'] ?? ''), 0, 28),
            ($u['is_disabled'] ?? false) ? 'desativ.' : 'ativo',
            implode(', ', array_slice($u['groups'] ?? [], 0, 3))
        );
    }

    echo "\n  Observação: este filtro inclui as contas internas do próprio domínio\n";
    echo "  (Administrator, Guest, krbtgt e contas de serviço). Se elas não devem\n";
    echo "  aparecer na interface, o caminho é restringir a busca a uma OU dedicada\n";
    echo "  em vez de filtrar por nome.\n";
}

// ---------------------------------------------------------------- grupos
titulo('6. Grupos');

try {
    $grupos = (new GroupRepository($ldap))->all();
} catch (Throwable $e) {
    erro($e->getMessage());
    $grupos = [];
    $falhas++;
}

$totalG = count($grupos);

if ($totalG > 0) {
    ok("{$totalG} grupo(s) encontrado(s)");

    usort($grupos, fn ($a, $b) => ($b['member_count'] ?? 0) <=> ($a['member_count'] ?? 0));

    echo "\n  Grupos com mais membros (até 15):\n";
    printf("    %-38s %s\n", 'cn', 'membros');

    foreach (array_slice($grupos, 0, 15) as $g) {
        printf("    %-38s %d\n", substr((string) ($g['cn'] ?? '?'), 0, 38), $g['member_count'] ?? 0);
    }

    echo "\n  Observação: o AD não guarda o grupo primário (normalmente \"Domain Users\")\n";
    echo "  no atributo member. Se \"Domain Users\" aparecer com poucos membros ou\n";
    echo "  nenhum, isso é esperado, não é erro.\n";
} else {
    erro('nenhum grupo encontrado');
}

// --------------------------------------------------------------- resumo
titulo('Resumo');

if ($falhas === 0) {
    echo "  Nenhum problema bloqueante encontrado: a leitura do diretório está\n";
    echo "  funcionando. As operações de ESCRITA (criar usuário, resetar senha,\n";
    echo "  mexer em grupo) não são testadas aqui - faça isso pela interface, com\n";
    echo "  um usuário de teste descartável.\n";
} else {
    echo "  {$falhas} problema(s) encontrado(s) - veja as linhas [FALHA] acima.\n";
}

$ldap->close();

exit($falhas === 0 ? 0 : 1);
