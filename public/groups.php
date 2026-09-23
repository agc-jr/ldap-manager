<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/includes/flash.php';

use App\Auth;
use App\Audit\AuditLogger;
use App\Ldap\ActiveDomain;
use App\Ldap\UserRepository;
use App\Ldap\GroupRepository;

Auth::requireLogin();
$pageTitle = 'Grupos';
$appUser = Auth::user();

$ldapError = null;
$groups = [];
$allUsers = [];

try {
    $ldap = ActiveDomain::conectar();
    $groupRepo = new GroupRepository($ldap);
    $userRepo = new UserRepository($ldap);

    // Cada ação trata o próprio erro e volta com a mensagem. Antes, um grupo
    // não encontrado fazia o if falhar em silêncio — a tela recarregava sem
    // dizer nada — e uma recusa do diretório virava "Erro ao consultar o LDAP",
    // que descreve o problema errado.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = $_POST['action'] ?? '';

        try {
            if ($acao === 'add_member') {
                $groupCn = trim($_POST['group_cn'] ?? '');
                $sam     = trim($_POST['sam'] ?? '');
                $group   = $groupRepo->findByCn($groupCn);
                $user    = $userRepo->findBySamAccountName($sam);

                if (!$group) {
                    flash('error', "Grupo \"{$groupCn}\" não foi encontrado no diretório.");
                } elseif (!$user) {
                    flash('error', "Usuário \"{$sam}\" não foi encontrado no diretório.");
                } else {
                    $groupRepo->addMember($group['dn'], $user['dn']);
                    AuditLogger::log((int) $appUser['id'], $appUser['username'], 'group.add_member', 'ldap_group', $groupCn, ['user' => $sam]);
                    flash('success', "\"{$sam}\" adicionado ao grupo \"{$groupCn}\".");
                }
            }

            if ($acao === 'remove_member') {
                $groupCn  = trim($_POST['group_cn'] ?? '');
                $memberDn = trim($_POST['member_dn'] ?? '');
                $group    = $groupRepo->findByCn($groupCn);

                if (!$group) {
                    flash('error', "Grupo \"{$groupCn}\" não foi encontrado no diretório.");
                } elseif ($memberDn === '') {
                    flash('error', 'Membro não informado.');
                } else {
                    $groupRepo->removeMember($group['dn'], $memberDn);
                    AuditLogger::log((int) $appUser['id'], $appUser['username'], 'group.remove_member', 'ldap_group', $groupCn, ['member_dn' => $memberDn]);
                    flash('success', "Membro removido do grupo \"{$groupCn}\".");
                }
            }
        } catch (\Throwable $e) {
            flash('error', GroupRepository::explicarFalha($e, trim($_POST['group_cn'] ?? ''), $ldap));
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
      <p class="text-sm text-rose-300 font-medium"><?= ActiveDomain::temAlgum() ? 'Erro ao consultar o LDAP' : 'Nenhum domínio disponível' ?></p>
      <p class="text-xs text-rose-400/80 mt-1"><?= htmlspecialchars($ldapError) ?></p>
    </div>
  <?php else: ?>

  <?php
    // Grupos fora da OU delegada existem no diretório e aparecem aqui, mas o
    // AD recusa alterá-los — de propósito. Marcar isso na tela evita descobrir
    // por tentativa e erro, que foi o que aconteceu antes desta mudança.
    $ouGrupos = $ldap->opcao('default_group_ou');

    $gerenciavel = static function (array $g) use ($ouGrupos): bool {
        if (!is_string($ouGrupos) || $ouGrupos === '') {
            return true; // sem OU configurada, não há como saber: não prejulga
        }
        return str_ends_with(mb_strtolower((string) ($g['dn'] ?? '')), mb_strtolower($ouGrupos));
    };

    $totalGerenciaveis = count(array_filter($groups, $gerenciavel));
  ?>

  <?php if (is_string($ouGrupos) && $ouGrupos !== '' && $totalGerenciaveis < count($groups)): ?>
    <p class="text-xs text-slate-500 mb-4">
      <?= $totalGerenciaveis ?> de <?= count($groups) ?> grupos podem ser alterados por aqui.
      Os demais são internos do domínio e ficam somente leitura, para que ninguém
      se promova a administrador pela ferramenta.
    </p>
  <?php elseif (!is_string($ouGrupos) || $ouGrupos === ''): ?>
    <div class="card p-4 mb-4 border-amber-500/30 bg-amber-500/5">
      <p class="text-xs text-amber-300">
        Nenhuma <strong>OU padrão de grupos</strong> está configurada para este domínio. Sem ela,
        adicionar ou remover membros vai falhar em todos os grupos. Configure em
        <a href="domains.php" class="underline">Domínios</a>.
      </p>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php foreach ($groups as $g): $cn = $g['cn'] ?? ''; $editavel = $gerenciavel($g); ?>
      <div class="card p-5 <?= $editavel ? '' : 'opacity-60' ?>">
        <div class="flex items-center justify-between mb-3">
          <div>
            <p class="font-medium">
              <?= htmlspecialchars($cn) ?>
              <?php if (!$editavel): ?>
                <span class="badge bg-slate-500/10 text-slate-400 ml-1"
                      title="Fora da unidade organizacional delegada — o diretório recusa alterações">somente leitura</span>
              <?php endif; ?>
            </p>
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
              <?php if ($editavel): ?>
                <form method="post" onsubmit="return confirm('Remover este membro do grupo?');">
                  <input type="hidden" name="action" value="remove_member">
                  <input type="hidden" name="group_cn" value="<?= htmlspecialchars($cn) ?>">
                  <input type="hidden" name="member_dn" value="<?= htmlspecialchars($memberDn) ?>">
                  <button type="submit" class="text-rose-300 hover:text-rose-200">remover</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>

          <?php if ($editavel): ?>
            <button @click="addTo = <?= json_encode($cn) ?>" class="mt-2 text-xs text-cyan-300 hover:text-cyan-200">+ adicionar membro</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Modal: adicionar membro -->
  <div x-show="addTo !== null" x-cloak class="fixed inset-0 z-30 flex items-center justify-center modal-overlay p-4">
    <div class="modal-panel w-full max-w-sm p-6" @click.outside="addTo = null">
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
