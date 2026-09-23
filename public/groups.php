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
$ouDosGrupos = null;

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

    // Marca quem pode ser alterado antes de ordenar: os editáveis vêm primeiro,
    // porque são a minoria (2 de 39, aqui) e é neles que se trabalha. Deixá-los
    // em ordem alfabética junto com o resto obrigava a caçar na lista.
    $ouDosGrupos = $ldap->opcao('default_group_ou');

    foreach ($groups as &$grupo) {
        $grupo['editavel'] = (!is_string($ouDosGrupos) || $ouDosGrupos === '')
            ? true // sem OU configurada não há como saber; não prejulga
            : str_ends_with(mb_strtolower((string) ($grupo['dn'] ?? '')), mb_strtolower($ouDosGrupos));
    }
    unset($grupo);

    usort($groups, static function (array $a, array $b): int {
        // Editáveis primeiro; dentro de cada bloco, ordem alfabética.
        return ($b['editavel'] <=> $a['editavel'])
            ?: strcasecmp($a['cn'] ?? '', $b['cn'] ?? '');
    });

    $allUsers = $userRepo->all();
    usort($allUsers, fn($a, $b) => strcasecmp($a['sAMAccountName'] ?? '', $b['sAMAccountName'] ?? ''));

    // Lista para o seletor do modal. Um <select> nativo com 136 nomes é
    // impraticável: não filtra, não mostra quem já está no grupo e fica
    // minúsculo no celular.
    $usuariosParaEscolha = array_map(static function (array $u): array {
        $sam  = (string) ($u['sAMAccountName'] ?? '');
        $nome = (string) ($u['displayName'] ?? '');

        return [
            'sam'        => $sam,
            'nome'       => $nome,
            'dn'         => (string) ($u['dn'] ?? ''),
            'desativado' => (bool) ($u['is_disabled'] ?? false),
            'busca'      => mb_strtolower(trim($sam . ' ' . $nome), 'UTF-8'),
        ];
    }, $allUsers);
} catch (\Throwable $e) {
    $ldapError = $e->getMessage();
}

require __DIR__ . '/includes/layout_top.php';
?>

<?php
  // Quem já está em cada grupo, para o seletor marcar como "já é membro" em vez
  // de deixar adicionar de novo e receber um erro do diretório.
  $membrosPorGrupo = [];
  foreach ($groups as $g) {
      $membrosPorGrupo[$g['cn'] ?? ''] = array_values((array) ($g['members'] ?? []));
  }
?>
<div x-data="telaDeGrupos(
       <?= htmlspecialchars(json_encode($usuariosParaEscolha ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>,
       <?= htmlspecialchars(json_encode($membrosPorGrupo, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>
     )">

  <?php if ($ldapError): ?>
    <div class="card p-6 border-rose-500/30 bg-rose-500/5">
      <p class="text-sm text-rose-300 font-medium"><?= ActiveDomain::temAlgum() ? 'Erro ao consultar o LDAP' : 'Nenhum domínio disponível' ?></p>
      <p class="text-xs text-rose-400/80 mt-1"><?= htmlspecialchars($ldapError) ?></p>
    </div>
  <?php else: ?>

  <?php
    // A marcação de editável e a ordenação acontecem junto com a busca, acima.
    $ouGrupos = $ouDosGrupos;
    $totalGerenciaveis = count(array_filter($groups, static fn (array $g): bool => !empty($g['editavel'])));
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
    <?php foreach ($groups as $g): $cn = $g['cn'] ?? ''; $editavel = !empty($g["editavel"]); ?>
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
          <button @click="open = (open === <?= htmlspecialchars(json_encode($cn), ENT_QUOTES) ?> ? null : <?= htmlspecialchars(json_encode($cn), ENT_QUOTES) ?>)"
                  class="text-xs text-indigo-300 hover:text-indigo-200">
            <span x-text="open === <?= htmlspecialchars(json_encode($cn), ENT_QUOTES) ?> ? 'Fechar' : 'Ver membros'"></span>
          </button>
        </div>

        <div x-show="open === <?= htmlspecialchars(json_encode($cn), ENT_QUOTES) ?>" x-cloak class="border-t border-white/5 pt-3 mt-1 space-y-2">
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
            <button @click="abrirAdicionar(<?= htmlspecialchars(json_encode($cn), ENT_QUOTES) ?>)" class="mt-2 text-xs text-cyan-300 hover:text-cyan-200">+ adicionar membro</button>
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

          <!-- O valor enviado é o login; a lista abaixo só serve para escolhê-lo -->
          <input type="hidden" name="sam" :value="escolhido ? escolhido.sam : ''" required>

          <input type="text" x-model="filtro" placeholder="Digite o nome ou o login..."
                 autocapitalize="none" autocorrect="off" spellcheck="false"
                 @focus="escolhido = null"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">

          <template x-if="escolhido">
            <p class="mt-2 text-xs text-emerald-300">
              Selecionado: <span class="font-medium" x-text="escolhido.nome || escolhido.sam"></span>
              <span class="text-slate-500" x-text="'(' + escolhido.sam + ')'"></span>
            </p>
          </template>

          <div x-show="!escolhido" class="mt-2 max-h-56 overflow-y-auto rounded-lg border border-white/10 divide-y divide-white/5">
            <template x-for="u in candidatos" :key="u.sam">
              <button type="button" @click="escolhido = u; filtro = ''"
                      class="w-full text-left px-3 py-2 hover:bg-white/5 flex items-baseline gap-2"
                      :disabled="u.jaEstaNoGrupo"
                      :class="u.jaEstaNoGrupo ? 'opacity-40 cursor-not-allowed' : ''">
                <span class="text-sm" x-text="u.nome || u.sam"></span>
                <span class="text-xs text-slate-500" x-text="u.sam"></span>
                <template x-if="u.jaEstaNoGrupo">
                  <span class="text-xs text-slate-500 ml-auto">já é membro</span>
                </template>
                <template x-if="u.desativado && !u.jaEstaNoGrupo">
                  <span class="text-xs text-rose-400/70 ml-auto">desativado</span>
                </template>
              </button>
            </template>

            <template x-if="candidatos.length === 0">
              <p class="px-3 py-3 text-xs text-slate-500">Nenhum usuário encontrado.</p>
            </template>
          </div>

          <p x-show="!escolhido" class="mt-1 text-[11px] text-slate-500">
            <span x-text="candidatos.length"></span> de <span x-text="usuarios.length"></span> usuários
          </p>
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

<script>
function telaDeGrupos(usuarios, membrosPorGrupo) {
  // "José" e "jose" devem se encontrar: NFD separa a letra do acento e o range
  // ̀-ͯ remove só os acentos.
  const semAcento = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '');

  return {
    open: null,
    addTo: null,
    usuarios: usuarios.map(u => ({ ...u, buscaNorm: semAcento(u.busca) })),
    membrosPorGrupo: membrosPorGrupo,
    filtro: '',
    escolhido: null,

    abrirAdicionar(grupo) {
      this.addTo = grupo;
      // Zerados a cada abertura: o que foi digitado para um grupo não pode
      // sobrar ao abrir outro.
      this.filtro = '';
      this.escolhido = null;
    },

    get candidatos() {
      const membros = this.membrosPorGrupo[this.addTo] || [];
      const termo = semAcento(this.filtro.trim().toLowerCase());
      const termos = termo === '' ? [] : termo.split(/\s+/);

      return this.usuarios
        .filter(u => termos.every(t => u.buscaNorm.includes(t)))
        .map(u => ({ ...u, jaEstaNoGrupo: membros.includes(u.dn) }))
        // Quem já é membro vai para o fim: continua visível, mas não atrapalha.
        .sort((a, b) => (a.jaEstaNoGrupo - b.jaEstaNoGrupo))
        // Sem limite a lista de 136 nomes deixa o modal lento ao abrir; quem
        // procura alguém específico digita e a lista encurta.
        .slice(0, 50);
    },
  };
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
