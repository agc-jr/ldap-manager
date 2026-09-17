<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/includes/flash.php';

use App\Auth;
use App\Audit\AuditLogger;
use App\Ldap\LdapConnection;
use App\Ldap\UserRepository;

Auth::requireLogin();
$pageTitle = 'Usuários';
$appUser = Auth::user();

$ldapError = null;
$users = [];

try {
    $ldap = new LdapConnection();
    $repo = new UserRepository($ldap);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
        $sam       = trim($_POST['sam'] ?? '');
        $given     = trim($_POST['given_name'] ?? '');
        $sn        = trim($_POST['sn'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = (string) ($_POST['password'] ?? '');

        if ($sam === '' || $given === '' || $sn === '' || $password === '') {
            flash('error', 'Preencha usuário, nome, sobrenome e senha inicial.');
        } else {
            $dn = $repo->create($sam, $given, $sn, $password, $email ?: null);
            AuditLogger::log((int) $appUser['id'], $appUser['username'], 'user.create', 'ldap_user', $sam, [
                'dn' => $dn, 'email' => $email,
            ]);
            flash('success', "Usuário \"{$sam}\" criado. Ele deverá trocar a senha no primeiro login.");
        }
        header('Location: users.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
        $sam = trim($_POST['sam'] ?? '');
        $target = $repo->findBySamAccountName($sam);
        if ($target) {
            $newState = !$target['is_disabled'];
            $repo->setDisabled($target['dn'], $newState, (int) $target['userAccountControl']);
            AuditLogger::log((int) $appUser['id'], $appUser['username'], $newState ? 'user.disable' : 'user.enable', 'ldap_user', $sam);
            flash('success', $newState ? "Usuário \"{$sam}\" desativado." : "Usuário \"{$sam}\" reativado.");
        }
        header('Location: users.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
        $sam = trim($_POST['sam'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $target = $repo->findBySamAccountName($sam);
        if ($target && $newPassword !== '') {
            $repo->setPassword($target['dn'], $newPassword, true);
            AuditLogger::log((int) $appUser['id'], $appUser['username'], 'user.reset_password', 'ldap_user', $sam);
            flash('success', "Senha de \"{$sam}\" redefinida. Troca será exigida no próximo login.");
        }
        header('Location: users.php');
        exit;
    }

    $users = $repo->all();
    usort($users, fn($a, $b) => strcasecmp($a['sAMAccountName'] ?? '', $b['sAMAccountName'] ?? ''));
} catch (\Throwable $e) {
    $ldapError = $e->getMessage();
}

require __DIR__ . '/includes/layout_top.php';
?>

<div x-data="{ q: '', showCreate: false, resetTarget: null }">

  <?php if ($ldapError): ?>
    <div class="card p-6 border-rose-500/30 bg-rose-500/5">
      <p class="text-sm text-rose-300 font-medium">Erro ao consultar o LDAP</p>
      <p class="text-xs text-rose-400/80 mt-1"><?= htmlspecialchars($ldapError) ?></p>
    </div>
  <?php else: ?>

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
    <input type="text" x-model="q" placeholder="Buscar por usuário, nome ou e-mail..."
           class="w-full sm:w-80 rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400/50">
    <button @click="showCreate = true"
            class="rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 font-semibold px-4 py-2.5 text-sm hover:opacity-90 whitespace-nowrap">
      + Novo usuário
    </button>
  </div>

  <div class="card overflow-x-auto">
    <table class="data w-full text-sm">
      <thead>
        <tr class="text-left">
          <th class="px-5 py-3">Usuário</th>
          <th class="px-5 py-3">Nome</th>
          <th class="px-5 py-3">E-mail</th>
          <th class="px-5 py-3">Status</th>
          <th class="px-5 py-3">Grupos</th>
          <th class="px-5 py-3 text-right">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr x-show="q === '' || (<?= json_encode(strtolower(($u['sAMAccountName'] ?? '') . ' ' . ($u['displayName'] ?? '') . ' ' . ($u['mail'] ?? ''))) ?>).includes(q.toLowerCase())">
            <td class="px-5 py-3 font-medium"><?= htmlspecialchars($u['sAMAccountName'] ?? '') ?></td>
            <td class="px-5 py-3 text-slate-300"><?= htmlspecialchars($u['displayName'] ?? '') ?></td>
            <td class="px-5 py-3 text-slate-400"><?= htmlspecialchars($u['mail'] ?? '—') ?></td>
            <td class="px-5 py-3">
              <?php if ($u['is_disabled']): ?>
                <span class="badge bg-rose-500/10 text-rose-300">Desativado</span>
              <?php else: ?>
                <span class="badge bg-emerald-500/10 text-emerald-300">Ativo</span>
              <?php endif; ?>
              <?php if ($u['password_expired']): ?>
                <span class="badge bg-amber-500/10 text-amber-300">Aguarda troca de senha</span>
              <?php endif; ?>
            </td>
            <td class="px-5 py-3 text-slate-400 text-xs"><?= htmlspecialchars(implode(', ', $u['groups']) ?: '—') ?></td>
            <td class="px-5 py-3 text-right space-x-2 whitespace-nowrap">
              <button @click="resetTarget = <?= htmlspecialchars(json_encode($u['sAMAccountName']), ENT_QUOTES) ?>"
                      class="text-xs text-indigo-300 hover:text-indigo-200">Resetar senha</button>
              <form method="post" class="inline" onsubmit="return confirm('Confirma a alteração de status deste usuário?');">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="sam" value="<?= htmlspecialchars($u['sAMAccountName'] ?? '') ?>">
                <button type="submit" class="text-xs <?= $u['is_disabled'] ? 'text-emerald-300 hover:text-emerald-200' : 'text-rose-300 hover:text-rose-200' ?>">
                  <?= $u['is_disabled'] ? 'Reativar' : 'Desativar' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Modal: novo usuário -->
  <div x-show="showCreate" x-cloak class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4">
    <div class="card w-full max-w-md p-6" @click.outside="showCreate = false">
      <h2 class="text-base font-semibold mb-4">Novo usuário</h2>
      <form method="post" class="space-y-3">
        <input type="hidden" name="action" value="create">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Usuário (login)</label>
          <input name="sam" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs text-slate-400 mb-1">Nome</label>
            <input name="given_name" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs text-slate-400 mb-1">Sobrenome</label>
            <input name="sn" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
          </div>
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">E-mail (opcional)</label>
          <input type="email" name="email" class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Senha inicial</label>
          <input type="text" name="password" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
          <p class="text-[11px] text-slate-500 mt-1">O usuário será obrigado a trocar essa senha no primeiro login.</p>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="showCreate = false" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 text-sm font-semibold">Criar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: resetar senha -->
  <div x-show="resetTarget !== null" x-cloak class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4">
    <div class="card w-full max-w-sm p-6" @click.outside="resetTarget = null">
      <h2 class="text-base font-semibold mb-1">Resetar senha</h2>
      <p class="text-xs text-slate-500 mb-4">Usuário: <span x-text="resetTarget" class="text-slate-300"></span></p>
      <form method="post" class="space-y-3">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="sam" :value="resetTarget">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Nova senha</label>
          <input type="text" name="new_password" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="resetTarget = null" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 text-sm font-semibold">Redefinir</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
