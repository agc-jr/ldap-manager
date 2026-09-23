<?php
/**
 * Instalador web, no modelo do WordPress: coleta os dados do banco, gera o
 * config/config.php, cria as tabelas, o administrador inicial e o primeiro
 * domínio LDAP.
 *
 * Recusa-se a rodar quando a instalação já está completa — um instalador
 * acessível em produção deixaria qualquer um recriar o administrador ou
 * apontar o sistema para um servidor LDAP hostil.
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Crypto;
use App\Database;
use App\Installer;
use App\Ldap\DomainRepository;
use App\Ldap\LdapConnection;

/*
 * O bloqueio não pode valer para quem está no meio do wizard: assim que a
 * etapa 2 cria o administrador, instalado() passa a responder true e a etapa 3
 * (primeiro domínio) ficaria inacessível — o instalador trancaria a si mesmo.
 *
 * A marca de wizard em andamento vive na sessão, então serve a quem começou a
 * instalação e não a um visitante qualquer. Se a sessão se perder no meio, o
 * caminho é entrar com o administrador já criado e cadastrar o domínio na tela
 * Domínios, que faz a mesma coisa.
 */
$emAndamento = !empty($_SESSION['instalacao_em_andamento']);

if (Installer::instalado() && !$emAndamento) {
    http_response_code(403);
    $titulo = 'Instalação já concluída';
    $mensagem = 'Este sistema já está instalado. Para reinstalar do zero, remova config/config.php '
        . 'e apague as tabelas do banco — o que apaga também os operadores e todo o histórico de auditoria.';
    require __DIR__ . '/includes/install_bloqueado.php';
    exit;
}

$etapa = (int) ($_GET['etapa'] ?? 1);
$erros = [];
$avisos = [];
$conteudoConfig = null;

// Os dados do banco atravessam as etapas pela sessão: o config.php só é
// gravado quando já sabemos que a conexão funciona.
$db = $_SESSION['instalacao_db'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ------------------------------------------------ etapa 1: banco de dados
    if ($acao === 'banco') {
        $db = [
            'host'     => trim($_POST['host'] ?? '127.0.0.1'),
            'port'     => (int) ($_POST['port'] ?? 3306),
            'database' => trim($_POST['database'] ?? ''),
            'username' => trim($_POST['username'] ?? ''),
            'password' => (string) ($_POST['password'] ?? ''),
        ];

        if ($db['database'] === '' || $db['username'] === '') {
            $erros[] = 'Informe o nome do banco e o usuário.';
        } else {
            $teste = Installer::testarBanco($db['host'], $db['port'], $db['database'], $db['username'], $db['password']);

            if (!$teste['ok']) {
                $erros[] = $teste['erro'];
            } else {
                try {
                    Installer::criarTabelas($db['host'], $db['port'], $db['database'], $db['username'], $db['password']);

                    $appKey = Crypto::gerarChave();
                    try {
                        Installer::gravarConfig($db, $appKey);
                    } catch (Throwable $e) {
                        // Diretório sem permissão de escrita: em vez de travar,
                        // mostramos o conteúdo para o usuário criar à mão.
                        $erros[] = $e->getMessage();
                        $conteudoConfig = Installer::montarConfig($db, $appKey);
                    }

                    if ($conteudoConfig === null) {
                        Config::recarregar();
                        Database::reconectar();
                        $_SESSION['instalacao_db'] = $db;
                        // A partir daqui instalado() responderá true assim que o
                        // admin for criado; esta marca mantém o wizard acessível
                        // para quem o começou.
                        $_SESSION['instalacao_em_andamento'] = true;
                        header('Location: install.php?etapa=2');
                        exit;
                    }
                } catch (Throwable $e) {
                    $erros[] = 'Falha ao criar as tabelas: ' . $e->getMessage();
                }
            }
        }
    }

    // ------------------------------------------------ etapa 2: administrador
    if ($acao === 'admin') {
        $usuario = trim($_POST['usuario'] ?? '');
        $nome    = trim($_POST['nome'] ?? '');
        $senha   = (string) ($_POST['senha'] ?? '');
        $confirmar = (string) ($_POST['confirmar'] ?? '');

        if ($usuario === '' || $senha === '') {
            $erros[] = 'Informe usuário e senha.';
        } elseif (mb_strlen($senha) < 10) {
            $erros[] = 'A senha do administrador precisa ter pelo menos 10 caracteres.';
        } elseif ($senha !== $confirmar) {
            $erros[] = 'A confirmação não confere com a senha.';
        } else {
            try {
                Installer::criarAdministrador($usuario, $senha, $nome);
                header('Location: install.php?etapa=3');
                exit;
            } catch (Throwable $e) {
                $erros[] = 'Não foi possível criar o administrador: ' . $e->getMessage();
            }
        }
    }

    // ------------------------------------------------ etapa 3: domínio LDAP
    if ($acao === 'dominio') {
        $dados = [
            'name'            => trim($_POST['name'] ?? ''),
            'host'            => trim($_POST['ldap_host'] ?? ''),
            'port'            => (int) ($_POST['ldap_port'] ?? 636),
            'tls_verify'      => !empty($_POST['tls_verify']),
            'base_dn'         => trim($_POST['base_dn'] ?? ''),
            'bind_dn'         => trim($_POST['bind_dn'] ?? ''),
            'bind_password'   => (string) ($_POST['bind_password'] ?? ''),
            'domain_upn'      => trim($_POST['domain_upn'] ?? ''),
            'domain_netbios'  => trim($_POST['domain_netbios'] ?? ''),
            'default_user_ou' => trim($_POST['default_user_ou'] ?? ''),
            'password_min_length' => (int) ($_POST['password_min_length'] ?? 7),
        ];

        $erros = DomainRepository::validar($dados);

        if ($erros === []) {
            // Testa a conexão antes de gravar: um domínio cadastrado que não
            // conecta só produziria erro na primeira tela depois do login.
            try {
                $conexao = new LdapConnection($dados);
                $conexao->close();
            } catch (Throwable $e) {
                $erros[] = 'Não foi possível conectar ao domínio: ' . $e->getMessage();
            }

            if ($erros === []) {
                try {
                    (new DomainRepository())->criar($dados);
                    // A marca de wizard só cai depois que a tela final for
                    // exibida; limpá-la aqui faria a própria etapa 4 dar 403.
                    unset($_SESSION['instalacao_db']);
                    header('Location: install.php?etapa=4');
                    exit;
                } catch (Throwable $e) {
                    $erros[] = 'Falha ao gravar o domínio: ' . $e->getMessage();
                }
            }
        }
    }
}

// Impede pular etapas pela URL.
if ($etapa >= 2 && !Config::existe()) {
    $etapa = 1;
}

$etapas = [
    1 => 'Banco de dados',
    2 => 'Administrador',
    3 => 'Domínio LDAP',
    4 => 'Pronto',
];

// Chegou à tela final: encerra o wizard. A partir do próximo acesso, o
// instalador volta a se recusar a rodar.
if ($etapa >= 4) {
    unset($_SESSION['instalacao_em_andamento']);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Instalação · AD Manager</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-950 text-slate-100 font-[Inter] antialiased min-h-screen py-10">

<div class="max-w-2xl mx-auto px-4">

  <div class="text-center mb-8">
    <div class="mx-auto h-12 w-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-cyan-400 flex items-center justify-center font-bold text-slate-950 text-lg">AD</div>
    <h1 class="mt-4 text-xl font-semibold tracking-tight">Instalação do AD Manager Web</h1>
  </div>

  <ol class="flex items-center justify-center gap-2 mb-8 text-xs">
    <?php foreach ($etapas as $n => $rotulo): ?>
      <li class="flex items-center gap-2">
        <span class="h-6 w-6 rounded-full flex items-center justify-center font-semibold
                     <?= $n < $etapa ? 'bg-emerald-500/20 text-emerald-300' : ($n === $etapa ? 'bg-indigo-500 text-slate-950' : 'bg-white/5 text-slate-600') ?>">
          <?= $n < $etapa ? '&check;' : $n ?>
        </span>
        <span class="<?= $n === $etapa ? 'text-slate-200' : 'text-slate-600' ?>"><?= htmlspecialchars($rotulo) ?></span>
        <?php if ($n < count($etapas)): ?><span class="text-slate-700">›</span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if ($erros !== []): ?>
    <div class="rounded-xl px-4 py-3 mb-5 text-sm bg-rose-500/10 text-rose-200 ring-1 ring-rose-500/30">
      <?php foreach ($erros as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($conteudoConfig !== null): ?>
    <div class="card p-6 mb-5">
      <p class="text-sm text-amber-300 font-medium mb-2">Crie o arquivo manualmente</p>
      <p class="text-xs text-slate-400 mb-3">
        As tabelas foram criadas, mas não foi possível gravar <code class="text-slate-300">config/config.php</code>.
        Crie o arquivo com exatamente este conteúdo e recarregue a página.
      </p>
      <textarea readonly rows="22"
                class="w-full rounded-lg bg-slate-950 border border-white/10 px-3 py-2 text-xs font-mono text-slate-300"><?= htmlspecialchars($conteudoConfig) ?></textarea>
    </div>
  <?php endif; ?>

  <?php if ($etapa === 1): ?>
    <form method="post" class="card p-6 space-y-4">
      <input type="hidden" name="acao" value="banco">
      <div>
        <h2 class="text-base font-semibold">Banco de dados</h2>
        <p class="text-xs text-slate-500 mt-1">
          Use um banco dedicado a esta ferramenta. Se ele ainda não existir, tentaremos criá-lo.
        </p>
      </div>

      <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label class="block text-xs text-slate-400 mb-1">Servidor</label>
          <input name="host" value="<?= htmlspecialchars($db['host'] ?? '127.0.0.1') ?>" required
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Porta</label>
          <input name="port" type="number" value="<?= (int) ($db['port'] ?? 3306) ?>" required
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
      </div>

      <div>
        <label class="block text-xs text-slate-400 mb-1">Nome do banco</label>
        <input name="database" value="<?= htmlspecialchars($db['database'] ?? 'ldap_manager') ?>" required
               class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Usuário</label>
          <input name="username" value="<?= htmlspecialchars($db['username'] ?? '') ?>" required autocomplete="off"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Senha</label>
          <input name="password" type="password" autocomplete="new-password"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
      </div>

      <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 font-semibold py-2.5 text-sm">
        Testar conexão e continuar
      </button>
    </form>

  <?php elseif ($etapa === 2): ?>
    <form method="post" action="install.php?etapa=2" class="card p-6 space-y-4">
      <input type="hidden" name="acao" value="admin">
      <div>
        <h2 class="text-base font-semibold">Administrador da ferramenta</h2>
        <p class="text-xs text-slate-500 mt-1">
          Esta conta é da ferramenta, não do domínio. Com ela você cadastra os demais operadores.
        </p>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Usuário</label>
          <input name="usuario" required autocomplete="off"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Nome completo</label>
          <input name="nome" class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Senha</label>
          <input name="senha" type="password" required minlength="10" autocomplete="new-password"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Confirmar senha</label>
          <input name="confirmar" type="password" required minlength="10" autocomplete="new-password"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
      </div>
      <p class="text-[11px] text-slate-500">Mínimo de 10 caracteres.</p>

      <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 font-semibold py-2.5 text-sm">
        Criar administrador e continuar
      </button>
    </form>

  <?php elseif ($etapa === 3): ?>
    <form method="post" action="install.php?etapa=3" class="card p-6 space-y-4">
      <input type="hidden" name="acao" value="dominio">
      <div>
        <h2 class="text-base font-semibold">Primeiro domínio LDAP</h2>
        <p class="text-xs text-slate-500 mt-1">
          A conexão é testada antes de salvar. Outros domínios podem ser cadastrados depois, pela interface.
        </p>
      </div>

      <div>
        <label class="block text-xs text-slate-400 mb-1">Nome de exibição</label>
        <input name="name" required placeholder="Administrativo" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
               class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        <p class="text-[11px] text-slate-500 mt-1">Como este domínio aparece no seletor da interface.</p>
      </div>

      <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label class="block text-xs text-slate-400 mb-1">Servidor LDAP</label>
          <input name="ldap_host" required placeholder="ldaps://10.0.0.10" value="<?= htmlspecialchars($_POST['ldap_host'] ?? 'ldaps://') ?>"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Porta</label>
          <input name="ldap_port" type="number" value="<?= (int) ($_POST['ldap_port'] ?? 636) ?>" required
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Domínio (UPN)</label>
          <input name="domain_upn" required placeholder="exemplo.local" value="<?= htmlspecialchars($_POST['domain_upn'] ?? '') ?>"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">NetBIOS (opcional)</label>
          <input name="domain_netbios" placeholder="EXEMPLO" value="<?= htmlspecialchars($_POST['domain_netbios'] ?? '') ?>"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        </div>
      </div>

      <div>
        <label class="block text-xs text-slate-400 mb-1">Base DN</label>
        <input name="base_dn" required placeholder="DC=exemplo,DC=local" value="<?= htmlspecialchars($_POST['base_dn'] ?? '') ?>"
               class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Conta de conexão (bind)</label>
          <input name="bind_dn" required placeholder="svc-ldapmanager@exemplo.local" value="<?= htmlspecialchars($_POST['bind_dn'] ?? '') ?>"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Senha da conta</label>
          <input name="bind_password" type="password" required autocomplete="new-password"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
      </div>

      <div>
        <label class="block text-xs text-slate-400 mb-1">OU padrão para novos usuários (opcional)</label>
        <input name="default_user_ou" placeholder="OU=usuarios,DC=exemplo,DC=local" value="<?= htmlspecialchars($_POST['default_user_ou'] ?? '') ?>"
               class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        <p class="text-[11px] text-slate-500 mt-1">
          Também delimita o que a ferramenta pode excluir. Em branco, usa CN=Users do Base DN.
        </p>
      </div>

      <div class="grid grid-cols-2 gap-3 items-end">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Tamanho mínimo de senha</label>
          <input name="password_min_length" type="number" min="1" value="<?= (int) ($_POST['password_min_length'] ?? 7) ?>"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <label class="flex items-center gap-2 text-xs text-slate-400 pb-2">
          <input type="checkbox" name="tls_verify" value="1" <?= !empty($_POST['tls_verify']) ? 'checked' : '' ?>
                 class="rounded border-white/20 bg-white/5">
          Validar certificado TLS
        </label>
      </div>
      <p class="text-[11px] text-slate-500">
        Deixe a validação desligada se o servidor usa certificado autoassinado ou vencido, o que é
        comum em domínios internos. Ligue depois de instalar um certificado válido.
      </p>

      <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 font-semibold py-2.5 text-sm">
        Testar conexão e concluir
      </button>
    </form>

  <?php else: ?>
    <div class="card p-6 space-y-4">
      <h2 class="text-base font-semibold text-emerald-300">Instalação concluída</h2>
      <p class="text-sm text-slate-300">
        O sistema está pronto. Entre com o administrador que você acabou de criar.
      </p>

      <div class="rounded-xl px-4 py-3 text-xs bg-amber-500/10 text-amber-200 ring-1 ring-amber-500/30 space-y-1.5">
        <p class="font-medium">Dois cuidados antes de usar em produção</p>
        <p>
          Apague <code>public/install.php</code> do servidor. Ele já se recusa a rodar com a
          instalação feita, mas remover é mais seguro.
        </p>
        <p>
          Guarde <code>config/config.php</code> junto com os backups do banco: sem a chave que
          está nele, as senhas dos domínios não podem ser lidas de volta.
        </p>
      </div>

      <a href="login.php" class="block text-center rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 font-semibold py-2.5 text-sm">
        Ir para o login
      </a>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
