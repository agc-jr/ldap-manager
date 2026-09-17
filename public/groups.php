<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/includes/flash.php';

use App\Auth;
use App\Audit\AuditLogger;
use App\Ldap\LdapConnection;
use App\Ldap\UserRepository;
use App\Ldap\GroupRepository;

Auth::requireLogin();
$pageTitle = 'Grupos';
$appUser = Auth::user();

$ldapError = null;
$groups = [];
$allUsers = [];

try {
    $ldap = new LdapConnection();
    $groupRepo = new GroupRepository($ldap);
    $userRepo = new UserRepository($ldap);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_member') {
        $groupCn = trim($_POST['group_cn'] ?? '');
        $sam = trim($_POST['sam'] ?? '');
        $group = $groupRepo->findByCn($groupCn);
        $user = $userRepo->findBySamAccountName($sam);
        if ($group && $user) {
            $groupRepo->addMember($group['dn'], $user['dn']);
            AuditLogger::log((int) $appUser['id'], $appUser['username'], 'group.add_member', 'ldap_group', $groupCn, ['user' => $sam]);
            flash('success', "\"{$sam}\" adicionado ao grupo \"{$groupCn}\".");
        }
        header('Location: groups.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_member') {
        $groupCn = trim($_POST['group_cn'] ?? '');
        $memberDn = trim($_POST['member_dn'] ?? '');
        $group = $groupRepo->findByCn($groupCn);
        if ($group) {
            $groupRepo->removeMember($group['dn'], $memberDn);
            AuditLogger::log((int) $appUser['id'], $appUser['username'], 'group.remove_member', 'ldap_group', $groupCn, ['member_dn' => $memberDn]);
            flash('success', "Membro removido do grupo \"{$groupCn}\".");
        }
        header('Location: groups.php');
        exit;
    }

    $groups = $groupRepo->all();
    usort($groups, fn($a, $b) => strcasecmp($a['cn'] ?? '', $b['cn'] ?? ''));

    $allUsers = $userRepo->all();
    usort($allUsers, fn($a, $b) => strcasecmp($a['sAMAccountName'] ?? '', $b['sAMAccountName'] ?? ''));
} catch (\Throwable $e) {
    $ldapError = $e->getMessage();
}

require __DIR__ . '/includes/layout_top.php';
?>

<div x-data="{ open: null, addTo: null }">

  <?php if ($ldapError): ?>
    <div class="card p-6 border-rose-500/30 bg-rose-500/5">
      <p class="text-sm text-rose-300 font-medium">Erro ao consultar o LDAP</p>
      <p class="text-xs text-rose-400/80 mt-1"><?= htmlspecialchars($ldapError) ?></p>
    </div>
  <?php else: ?>

  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php foreach ($groups as $g): $cn = $g['cn'] ?? ''; ?>
      <div class="card p-5">
        <div class="flex items-center justify-between mb-3">
          <div>
            <p class="font-medium"><?= htmlspecialchars($cn) ?></p>
            <p class="text-xs text-slate-500"><?= $g['member_count'] ?> membro(s)</p>
          </div>
          <button @click="open = (open === <?= json_encode($cn) ?> ? null : <?= json_encode($cn) ?>)"
                  class="text-xs text-indigo-300 hover:text-indigo-200">
            <span x-text="open === <?= json_encode($cn) ?> ? 'Fechar' : 'Ver membros'"></span>
          </button>
        </div>

        <div x-show="open === <?= json_encode($cn) ?>" x-cloak class="border-t border-white/5 pt-3 mt-1 space-y-2">
          <?php if (empty($g['members'])): ?>
            <p class="text-xs text-slate-500">Nenhum membro.</p>
          <?php else: foreach ($g['members'] as $memberDn): ?>
            <div class="flex items-center justify-between text-xs">
              <span class="text-slate-300 truncate" title="<?= htmlspecialchars($memberDn) ?>">
                <?= htmlspecialchars(\App\Ldap\UserRepository::cnFromDn($memberDn)) ?>
              </span>
              <form method="post" onsubmit="return confirm('Remover este membro do grupo?');">
                <input type="hidden" name="action" value="remove_member">
                <input type="hidden" name="group_cn" value="<?= htmlspecialchars($cn) ?>">
                <input type="hidden" name="member_dn" value="<?= htmlspecialchars($memberDn) ?>">
                <button type="submit" class="text-rose-300 hover:text-rose-200">remover</button>
              </form>
            </div>
          <?php endforeach; endif; ?>

          <button @click="addTo = <?= json_encode($cn) ?>" class="mt-2 text-xs text-cyan-300 hover:text-cyan-200">+ adicionar membro</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Modal: adicionar membro -->
  <div x-show="addTo !== null" x-cloak class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4">
    <div class="card w-full max-w-sm p-6" @click.outside="addTo = null">
      <h2 class="text-base font-semibold mb-1">Adicionar membro</h2>
      <p class="text-xs text-slate-500 mb-4">Grupo: <span x-text="addTo" class="text-slate-300"></span></p>
      <form method="post" class="space-y-3">
        <input type="hidden" name="action" value="add_member">
        <input type="hidden" name="group_cn" :value="addTo">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Usuário</label>
          <select name="sam" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
            <?php foreach ($allUsers as $u): ?>
              <option value="<?= htmlspecialchars($u['sAMAccountName'] ?? '') ?>">
                <?= htmlspecialchars(($u['sAMAccountName'] ?? '') . ' — ' . ($u['displayName'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="addTo = null" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 text-sm font-semibold">Adicionar</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
