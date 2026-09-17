<?php
/**
 * Cadastro dos domínios LDAP/AD. Restrito a administradores da ferramenta:
 * quem mexe aqui aponta o sistema para outro diretório e define com qual conta
 * ele se conecta.
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/includes/flash.php';

use App\Audit\AuditLogger;
use App\Auth;
use App\Ldap\DomainRepository;
use App\Ldap\LdapConnection;

Auth::requireAdmin();
$pageTitle = 'Domínios';
$appUser = Auth::user();

$repo = new DomainRepository();
$erros = [];

function camposDoFormulario(array $post): array
{
    return [
        'name'                => trim($post['name'] ?? ''),
        'host'                => trim($post['host'] ?? ''),
        'port'                => (int) ($post['port'] ?? 636),
        'tls_verify'          => !empty($post['tls_verify']),
        'base_dn'             => trim($post['base_dn'] ?? ''),
        'bind_dn'             => trim($post['bind_dn'] ?? ''),
        'bind_password'       => (string) ($post['bind_password'] ?? ''),
        'domain_upn'          => trim($post['domain_upn'] ?? ''),
        'domain_netbios'      => trim($post['domain_netbios'] ?? ''),
        'default_user_ou'     => trim($post['default_user_ou'] ?? ''),
        'default_group_ou'    => trim($post['default_group_ou'] ?? ''),
        'password_min_length' => (int) ($post['password_min_length'] ?? 7),
        'is_active'           => !empty($post['is_active']),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $id   = (int) ($_POST['id'] ?? 0);

    try {
        if ($acao === 'salvar') {
            $dados = camposDoFormulario($_POST);
            // Na edição, senha em branco significa "manter a atual".
            $erros = DomainRepository::validar($dados, $id === 0);

            if ($erros === []) {
                // Só testa a conexão quando há senha para testar; na edição sem
                // troca de senha, a validação real acontece no primeiro uso.
                if ($dados['bind_password'] !== '') {
                    try {
                        (new LdapConnection($dados))->close();
                    } catch (Throwable $e) {
                        $erros[] = 'Não foi possível conectar com estes dados: ' . $e->getMessage();
                    }
                }
            }

            if ($erros === []) {
                if ($id === 0) {
                    $novoId = $repo->criar($dados);
                    AuditLogger::log((int) $appUser['id'], $appUser['username'], 'domain.create', 'ldap_domain', $dados['name'], [
                        'id' => $novoId, 'host' => $dados['host'], 'base_dn' => $dados['base_dn'],
                    ]);
                    flash('success', "Domínio \"{$dados['name']}\" cadastrado.");
                } else {
                    $repo->atualizar($id, $dados);
                    AuditLogger::log((int) $appUser['id'], $appUser['username'], 'domain.update', 'ldap_domain', $dados['name'], [
                        'id' => $id, 'host' => $dados['host'], 'senha_alterada' => $dados['bind_password'] !== '',
                    ]);
                    flash('success', "Domínio \"{$dados['name']}\" atualizado.");
                }

                header('Location: domains.php');
                exit;
            }
        }

        if ($acao === 'excluir') {
            $dominio = $repo->encontrar($id);

            if ($dominio === null) {
                flash('error', 'Domínio não encontrado.');
            } elseif (trim($_POST['confirmacao'] ?? '') !== $dominio['name']) {
                flash('error', 'A confirmação não confere com o nome do domínio. Nada foi removido.');
            } else {
                $repo->remover($id);
                AuditLogger::log((int) $appUser['id'], $appUser['username'], 'domain.delete', 'ldap_domain', $dominio['name'], [
                    'id' => $id, 'host' => $dominio['host'],
                ]);
                flash('success', "Domínio \"{$dominio['name']}\" removido do cadastro. Nada foi alterado no diretório.");
            }

            header('Location: domains.php');
            exit;
        }

        if ($acao === 'testar') {
            $dominio = $repo->encontrar($id);

            if ($dominio === null) {
                flash('error', 'Domínio não encontrado.');
            } else {
                try {
                    $inicio = microtime(true);
                    (new LdapConnection($dominio))->close();
                    $ms = (int) ((microtime(true) - $inicio) * 1000);
                    flash('success', "Conexão com \"{$dominio['name']}\" funcionando ({$ms} ms).");
                } catch (Throwable $e) {
                    flash('error', "Falha ao conectar em \"{$dominio['name']}\": " . $e->getMessage());
                }
            }

            header('Location: domains.php');
            exit;
        }
    } catch (Throwable $e) {
        $erros[] = $e->getMessage();
    }
}

$dominios = $repo->todos(false);
$editando = null;

if (isset($_GET['editar'])) {
    $editando = $repo->encontrar((int) $_GET['editar']);
}

// Reexibe o que foi digitado quando a validação falha.
$form = $erros !== [] ? camposDoFormulario($_POST) : null;

require __DIR__ . '/includes/layout_top.php';
?>

<div x-data="{ mostrarForm: <?= ($editando !== null || $form !== null) ? 'true' : 'false' ?>, excluirAlvo: null, excluirConfirmacao: '' }">

  <?php if ($erros !== []): ?>
    <div class="rounded-xl px-4 py-3 mb-5 text-sm bg-rose-500/10 text-rose-200 ring-1 ring-rose-500/30">
      <?php foreach ($erros as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="flex items-center justify-between gap-3 mb-5">
    <p class="text-xs text-slate-500">
      <?= count($dominios) ?> domínio(s) cadastrado(s). Os dados de conexão ficam no banco; a senha da
      conta de serviço é gravada cifrada.
    </p>
    <?php if ($editando === null): ?>
      <button @click="mostrarForm = !mostrarForm"
              class="rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 font-semibold px-4 py-2.5 text-sm whitespace-nowrap">
        + Novo domínio
      </button>
    <?php endif; ?>
  </div>

  <?php if ($dominios !== []): ?>
    <div class="card overflow-x-auto mb-6">
      <table class="data w-full text-sm">
        <thead>
          <tr class="text-left">
            <th class="px-5 py-3">Nome</th>
            <th class="px-5 py-3">Servidor</th>
            <th class="px-5 py-3">Base DN</th>
            <th class="px-5 py-3">Situação</th>
            <th class="px-5 py-3 text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dominios as $d): ?>
            <tr>
              <td class="px-5 py-3 font-medium"><?= htmlspecialchars($d['name']) ?></td>
              <td class="px-5 py-3 text-slate-400 font-mono text-xs"><?= htmlspecialchars($d['host'] . ':' . $d['port']) ?></td>
              <td class="px-5 py-3 text-slate-400 font-mono text-xs"><?= htmlspecialchars($d['base_dn']) ?></td>
              <td class="px-5 py-3">
                <?php if ($d['is_active']): ?>
                  <span class="badge bg-emerald-500/10 text-emerald-300">Ativo</span>
                <?php else: ?>
                  <span class="badge bg-slate-500/10 text-slate-400">Inativo</span>
                <?php endif; ?>
                <?php if (!$d['tls_verify']): ?>
                  <span class="badge bg-amber-500/10 text-amber-300" title="A validação do certificado está desligada">TLS sem validação</span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-3 text-right space-x-2 whitespace-nowrap">
                <form method="post" class="inline">
                  <input type="hidden" name="acao" value="testar">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <button type="submit" class="text-xs text-slate-400 hover:text-slate-200">Testar</button>
                </form>
                <a href="domains.php?editar=<?= (int) $d['id'] ?>" class="text-xs text-indigo-300 hover:text-indigo-200">Editar</a>
                <button @click="excluirAlvo = <?= htmlspecialchars(json_encode(['id' => $d['id'], 'name' => $d['name']]), ENT_QUOTES) ?>; excluirConfirmacao = ''"
                        class="text-xs text-slate-500 hover:text-rose-300">Remover</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div x-show="mostrarForm" x-cloak class="card p-6">
    <h2 class="text-base font-semibold mb-1">
      <?= $editando !== null ? 'Editar domínio' : 'Novo domínio' ?>
    </h2>
    <p class="text-xs text-slate-500 mb-4">A conexão é testada antes de salvar.</p>

    <?php $v = $form ?? $editando ?? []; ?>
    <form method="post" class="space-y-4">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Nome de exibição</label>
          <input name="name" required value="<?= htmlspecialchars($v['name'] ?? '') ?>" placeholder="Administrativo"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Domínio (UPN)</label>
          <input name="domain_upn" required value="<?= htmlspecialchars($v['domain_upn'] ?? '') ?>" placeholder="exemplo.local"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">NetBIOS</label>
          <input name="domain_netbios" value="<?= htmlspecialchars($v['domain_netbios'] ?? '') ?>" placeholder="EXEMPLO"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="sm:col-span-2">
          <label class="block text-xs text-slate-400 mb-1">Servidor LDAP</label>
          <input name="host" required value="<?= htmlspecialchars($v['host'] ?? 'ldaps://') ?>" placeholder="ldaps://10.0.0.10"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Porta</label>
          <input name="port" type="number" required value="<?= (int) ($v['port'] ?? 636) ?>"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
      </div>

      <div>
        <label class="block text-xs text-slate-400 mb-1">Base DN</label>
        <input name="base_dn" required value="<?= htmlspecialchars($v['base_dn'] ?? '') ?>" placeholder="DC=exemplo,DC=local"
               class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Conta de conexão (bind)</label>
          <input name="bind_dn" required value="<?= htmlspecialchars($v['bind_dn'] ?? '') ?>" placeholder="svc-ldapmanager@exemplo.local"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">
            Senha da conta
            <?php if ($editando !== null): ?><span class="text-slate-600">(em branco = manter)</span><?php endif; ?>
          </label>
          <input name="bind_password" type="password" autocomplete="new-password" <?= $editando === null ? 'required' : '' ?>
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-slate-400 mb-1">OU padrão de usuários</label>
          <input name="default_user_ou" value="<?= htmlspecialchars($v['default_user_ou'] ?? '') ?>" placeholder="OU=usuarios,DC=exemplo,DC=local"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
          <p class="text-[11px] text-slate-500 mt-1">Onde novos usuários nascem e o limite do que pode ser excluído.</p>
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">OU padrão de grupos</label>
          <input name="default_group_ou" value="<?= htmlspecialchars($v['default_group_ou'] ?? '') ?>" placeholder="OU=grupos,DC=exemplo,DC=local"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Senha: mínimo de caracteres</label>
          <input name="password_min_length" type="number" min="1" value="<?= (int) ($v['password_min_length'] ?? 7) ?>"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <label class="flex items-center gap-2 text-xs text-slate-400 pb-2">
          <input type="checkbox" name="tls_verify" value="1" <?= !empty($v['tls_verify']) ? 'checked' : '' ?>
                 class="rounded border-white/20 bg-white/5">
          Validar certificado TLS
        </label>
        <label class="flex items-center gap-2 text-xs text-slate-400 pb-2">
          <input type="checkbox" name="is_active" value="1" <?= ($editando === null || !empty($v['is_active'])) ? 'checked' : '' ?>
                 class="rounded border-white/20 bg-white/5">
          Domínio ativo
        </label>
      </div>

      <div class="flex items-center gap-2 pt-1">
        <button type="submit" class="rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 font-semibold px-5 py-2.5 text-sm">
          <?= $editando !== null ? 'Salvar alterações' : 'Testar conexão e cadastrar' ?>
        </button>
        <?php if ($editando !== null): ?>
          <a href="domains.php" class="text-sm text-slate-400 hover:text-slate-200">Cancelar</a>
        <?php else: ?>
          <button type="button" @click="mostrarForm = false" class="text-sm text-slate-400 hover:text-slate-200">Cancelar</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Modal: remover domínio do cadastro -->
  <div x-show="excluirAlvo !== null" x-cloak class="fixed inset-0 z-30 flex items-center justify-center modal-overlay p-4">
    <div class="modal-panel w-full max-w-md p-6" @click.outside="excluirAlvo = null">
      <h2 class="text-base font-semibold mb-1 text-rose-300">Remover domínio do cadastro</h2>
      <p class="text-xs text-slate-400 mb-4" x-text="excluirAlvo?.name"></p>

      <div class="rounded-xl px-4 py-3 mb-4 text-xs bg-amber-500/10 text-amber-200 ring-1 ring-amber-500/30">
        <p>
          Isto remove apenas o cadastro daqui: nenhum usuário, grupo ou configuração é alterado
          no diretório. Os registros de auditoria já gravados permanecem.
        </p>
        <p class="mt-1.5">
          Se a intenção é só tirar o domínio de circulação, desmarque "Domínio ativo" na edição —
          assim o cadastro e a senha são preservados.
        </p>
      </div>

      <form method="post" class="space-y-3">
        <input type="hidden" name="acao" value="excluir">
        <input type="hidden" name="id" :value="excluirAlvo?.id">
        <div>
          <label class="block text-xs text-slate-400 mb-1">
            Para confirmar, digite <span class="text-slate-200 font-mono" x-text="excluirAlvo?.name"></span>
          </label>
          <input name="confirmacao" x-model="excluirConfirmacao" autocomplete="off"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="excluirAlvo = null" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancelar</button>
          <button type="submit" :disabled="excluirConfirmacao !== excluirAlvo?.name"
                  class="px-4 py-2 rounded-lg text-sm font-semibold bg-rose-500 text-white disabled:opacity-30 disabled:cursor-not-allowed">
            Remover
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
