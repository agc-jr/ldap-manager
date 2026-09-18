<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/includes/flash.php';

use App\Auth;
use App\Audit\AuditLogger;
use App\Ldap\ActiveDomain;
use App\Ldap\UserRepository;

Auth::requireLogin();
$pageTitle = 'Usuários';
$appUser = Auth::user();

$ldapError = null;
$users = [];

try {
    $ldap = ActiveDomain::conectar();
    $repo = new UserRepository($ldap);

    // Cada ação cuida do próprio erro e volta para a lista com a mensagem. Sem
    // isso, uma senha recusada ao criar usuário aparecia como "Erro ao consultar
    // o LDAP" — que descreve o problema errado e esconde a causa real.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = $_POST['action'] ?? '';

        try {
            if ($acao === 'create') {
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
            }

            if ($acao === 'toggle') {
                $sam = trim($_POST['sam'] ?? '');
                $target = $repo->findBySamAccountName($sam);
                if ($target) {
                    $newState = !$target['is_disabled'];
                    $repo->setDisabled($target['dn'], $newState, (int) $target['userAccountControl']);
                    AuditLogger::log((int) $appUser['id'], $appUser['username'], $newState ? 'user.disable' : 'user.enable', 'ldap_user', $sam);
                    flash('success', $newState ? "Usuário \"{$sam}\" desativado." : "Usuário \"{$sam}\" reativado.");
                } else {
                    flash('error', "Usuário \"{$sam}\" não foi encontrado no diretório.");
                }
            }

            if ($acao === 'delete') {
                $sam       = trim($_POST['sam'] ?? '');
                $confirmou = trim($_POST['confirmacao'] ?? '');

                if (!Auth::isAdmin()) {
                    // A tela não mostra o botão para operador, mas a checagem
                    // precisa existir aqui: esconder na interface não protege
                    // contra um POST montado à mão.
                    flash('error', 'Apenas administradores da ferramenta podem excluir contas do domínio.');
                } elseif (strcasecmp($confirmou, $sam) !== 0) {
                    flash('error', 'A confirmação não confere com o nome de usuário. Nada foi excluído.');
                } else {
                    $target = $repo->findBySamAccountName($sam);

                    if (!$target) {
                        flash('error', "Usuário \"{$sam}\" não foi encontrado no diretório.");
                    } else {
                        // Copiado antes de apagar, porque depois não há de onde
                        // tirar; mas só vai para a auditoria se a exclusão der
                        // certo, senão ficaria registrado algo que não ocorreu.
                        $retrato = [
                            'dn'          => $target['dn'] ?? null,
                            'displayName' => $target['displayName'] ?? null,
                            'mail'        => $target['mail'] ?? null,
                            'grupos'      => $target['groups'] ?? [],
                            'estava'      => ($target['is_disabled'] ?? false) ? 'desativada' : 'ativa',
                        ];

                        $repo->delete((string) $target['dn']);

                        AuditLogger::log((int) $appUser['id'], $appUser['username'], 'user.delete', 'ldap_user', $sam, $retrato);
                        flash('success', "Usuário \"{$sam}\" foi excluído do domínio. A exclusão é definitiva.");
                    }
                }
            }

            if ($acao === 'reset_password') {
                $sam = trim($_POST['sam'] ?? '');
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $target = $repo->findBySamAccountName($sam);

                if (!$target) {
                    flash('error', "Usuário \"{$sam}\" não foi encontrado no diretório.");
                } elseif ($newPassword === '') {
                    flash('error', 'Informe a nova senha.');
                } else {
                    $repo->setPassword(
                        $target['dn'],
                        $newPassword,
                        true,
                        (string) ($target['sAMAccountName'] ?? ''),
                        (string) ($target['displayName'] ?? '')
                    );
                    AuditLogger::log((int) $appUser['id'], $appUser['username'], 'user.reset_password', 'ldap_user', $sam);
                    flash('success', "Senha de \"{$sam}\" redefinida. Troca será exigida no próximo login.");
                }
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        header('Location: users.php');
        exit;
    }

    $users = $repo->all();
    usort($users, fn($a, $b) => strcasecmp($a['sAMAccountName'] ?? '', $b['sAMAccountName'] ?? ''));
} catch (\Throwable $e) {
    $ldapError = $e->getMessage();
}

/**
 * A tabela é montada no navegador a partir deste array, para que busca e
 * paginação funcionem sem ida ao servidor. O campo "busca" concentra o texto
 * pesquisável já em minúsculas (mb_strtolower, porque strtolower não entende
 * os acentos do UTF-8); a remoção dos acentos em si é feita no JS.
 */
$usuariosParaJs = array_map(static function (array $u): array {
    $sam   = (string) ($u['sAMAccountName'] ?? '');
    $nome  = (string) ($u['displayName'] ?? '');
    $email = (string) ($u['mail'] ?? '');

    return [
        'sam'        => $sam,
        'nome'       => $nome,
        'email'      => $email,
        'desativado' => (bool) ($u['is_disabled'] ?? false),
        'trocaSenha' => (bool) ($u['password_expired'] ?? false),
        'grupos'     => implode(', ', $u['groups'] ?? []),
        'busca'      => mb_strtolower(trim($sam . ' ' . $nome . ' ' . $email), 'UTF-8'),
    ];
}, $users);

require __DIR__ . '/includes/layout_top.php';
?>

<div x-data="tabelaUsuarios(<?= htmlspecialchars(json_encode($usuariosParaJs, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)">

  <?php if ($ldapError): ?>
    <div class="card p-6 border-rose-500/30 bg-rose-500/5">
      <p class="text-sm text-rose-300 font-medium"><?= ActiveDomain::temAlgum() ? 'Erro ao consultar o LDAP' : 'Nenhum domínio disponível' ?></p>
      <p class="text-xs text-rose-400/80 mt-1"><?= htmlspecialchars($ldapError) ?></p>
    </div>
  <?php else: ?>

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
    <div class="relative w-full sm:w-96">
      <input type="text" x-model="q" placeholder="Buscar por usuário, nome ou e-mail..."
             class="w-full rounded-xl bg-white/5 border border-white/10 pl-4 pr-9 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400/50">
      <button x-show="q !== ''" x-cloak @click="q = ''" type="button"
              title="Limpar busca"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-200 text-sm">&times;</button>
    </div>
    <button @click="showCreate = true"
            class="rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 font-semibold px-4 py-2.5 text-sm hover:opacity-90 whitespace-nowrap">
      + Novo usuário
    </button>
  </div>

  <div class="flex flex-wrap items-center justify-between gap-3 mb-3 text-xs text-slate-500">
    <p>
      <span x-text="filtrados.length"></span>
      <span x-text="filtrados.length === 1 ? 'usuário' : 'usuários'"></span>
      <template x-if="q !== ''">
        <span>encontrado(s) de <span x-text="usuarios.length"></span></span>
      </template>
    </p>
    <label class="flex items-center gap-2">
      Por página:
      <select x-model.number="porPagina"
              class="rounded-lg bg-white/5 border border-white/10 px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-400/50">
        <option :value="25">25</option>
        <option :value="50">50</option>
        <option :value="100">100</option>
        <option :value="usuarios.length">Todos</option>
      </select>
    </label>
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
        <template x-for="u in visiveis" :key="u.sam">
          <tr>
            <td class="px-5 py-3 font-medium" x-text="u.sam"></td>
            <td class="px-5 py-3 text-slate-300" x-text="u.nome"></td>
            <td class="px-5 py-3 text-slate-400" x-text="u.email || '—'"></td>
            <td class="px-5 py-3">
              <span class="badge"
                    :class="u.desativado ? 'bg-rose-500/10 text-rose-300' : 'bg-emerald-500/10 text-emerald-300'"
                    x-text="u.desativado ? 'Desativado' : 'Ativo'"></span>
              <template x-if="u.trocaSenha">
                <span class="badge bg-amber-500/10 text-amber-300">Aguarda troca de senha</span>
              </template>
            </td>
            <td class="px-5 py-3 text-slate-400 text-xs" x-text="u.grupos || '—'"></td>
            <td class="px-5 py-3 text-right space-x-2 whitespace-nowrap">
              <button @click="resetTarget = u.sam"
                      class="text-xs text-indigo-300 hover:text-indigo-200">Resetar senha</button>
              <form method="post" class="inline" onsubmit="return confirm('Confirma a alteração de status deste usuário?');">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="sam" :value="u.sam">
                <button type="submit" class="text-xs"
                        :class="u.desativado ? 'text-emerald-300 hover:text-emerald-200' : 'text-rose-300 hover:text-rose-200'"
                        x-text="u.desativado ? 'Reativar' : 'Desativar'"></button>
              </form>
              <?php if (Auth::isAdmin()): ?>
                <button @click="abrirExclusao(u)"
                        class="text-xs text-slate-500 hover:text-rose-300">Excluir</button>
              <?php endif; ?>
            </td>
          </tr>
        </template>
        <template x-if="filtrados.length === 0">
          <tr>
            <td colspan="6" class="px-5 py-10 text-center text-sm text-slate-500">
              Nenhum usuário encontrado para <span class="text-slate-300" x-text="'“' + q + '”'"></span>.
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>

  <!-- Paginação -->
  <div x-show="totalPaginas > 1" x-cloak class="flex flex-wrap items-center justify-between gap-3 mt-4">
    <p class="text-xs text-slate-500">
      Mostrando <span x-text="inicioVisivel"></span>–<span x-text="fimVisivel"></span>
      de <span x-text="filtrados.length"></span>
    </p>

    <div class="flex items-center gap-1">
      <button @click="irPara(pagina - 1)" :disabled="pagina === 1"
              class="px-3 py-1.5 rounded-lg text-xs bg-white/5 border border-white/10 disabled:opacity-30 disabled:cursor-not-allowed hover:bg-white/10">
        Anterior
      </button>

      <template x-for="p in paginasVisiveis" :key="p">
        <button @click="p !== '…' && irPara(p)"
                :disabled="p === '…'"
                class="px-3 py-1.5 rounded-lg text-xs border"
                :class="p === pagina
                  ? 'bg-indigo-500/20 border-indigo-400/40 text-indigo-200 font-semibold'
                  : (p === '…' ? 'border-transparent text-slate-600 cursor-default' : 'bg-white/5 border-white/10 hover:bg-white/10')"
                x-text="p"></button>
      </template>

      <button @click="irPara(pagina + 1)" :disabled="pagina === totalPaginas"
              class="px-3 py-1.5 rounded-lg text-xs bg-white/5 border border-white/10 disabled:opacity-30 disabled:cursor-not-allowed hover:bg-white/10">
        Próxima
      </button>
    </div>
  </div>
  <?php endif; ?>

  <!-- Modal: novo usuário -->
  <div x-show="showCreate" x-cloak class="fixed inset-0 z-30 flex items-center justify-center modal-overlay p-4">
    <div class="modal-panel w-full max-w-md p-6" @click.outside="showCreate = false">
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
          <div class="campo-com-botao">
            <input type="text" id="senha_novo_usuario" name="password" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
            <button type="button" class="botao-gerar" @click="gerarSenha('senha_novo_usuario')">gerar</button>
          </div>
          <p class="text-[11px] text-slate-500 mt-1">O usuário será obrigado a trocar essa senha no primeiro login.</p>
          <p class="text-[11px] text-amber-400/70 mt-1"><?= htmlspecialchars(\App\Ldap\PasswordPolicy::descricao()) ?></p>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="showCreate = false" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 text-sm font-semibold">Criar</button>
        </div>
      </form>
    </div>
  </div>

<?php if (Auth::isAdmin()): ?>
  <!-- Modal: excluir usuário (irreversível, por isso exige digitar o login) -->
  <div x-show="excluirAlvo !== null" x-cloak class="fixed inset-0 z-30 flex items-center justify-center modal-overlay p-4">
    <div class="modal-panel w-full max-w-md p-6" @click.outside="fecharExclusao()">
      <h2 class="text-base font-semibold mb-1 text-rose-300">Excluir usuário do domínio</h2>
      <p class="text-xs text-slate-400 mb-4">
        <span x-text="excluirAlvo?.nome || excluirAlvo?.sam" class="text-slate-200"></span>
        <span class="text-slate-500" x-text="'(' + (excluirAlvo?.sam || '') + ')'"></span>
      </p>

      <div class="rounded-xl px-4 py-3 mb-4 text-xs bg-rose-500/10 text-rose-200 ring-1 ring-rose-500/30 space-y-1.5">
        <p class="font-medium">Esta ação não tem volta.</p>
        <p class="text-rose-200/80">
          O identificador da conta (SID) é apagado junto. Criar depois um usuário com o mesmo
          nome não devolve as permissões, os grupos nem o acesso aos arquivos dela.
        </p>
        <p class="text-rose-200/80">
          Se a intenção é apenas tirar o acesso de alguém que saiu, <strong>Desativar</strong> faz
          isso na hora e pode ser desfeito.
        </p>
      </div>

      <template x-if="(excluirAlvo?.grupos || '') !== ''">
        <p class="text-xs text-amber-300/90 mb-3">
          Está nos grupos: <span x-text="excluirAlvo?.grupos"></span>
        </p>
      </template>

      <form method="post" class="space-y-3">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="sam" :value="excluirAlvo?.sam">
        <div>
          <label class="block text-xs text-slate-400 mb-1">
            Para confirmar, digite <span class="text-slate-200 font-mono" x-text="excluirAlvo?.sam"></span>
          </label>
          <input type="text" name="confirmacao" x-model="excluirConfirmacao" autocomplete="off"
                 autocapitalize="none" autocorrect="off" spellcheck="false"
                 class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm font-mono">
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="fecharExclusao()" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancelar</button>
          <button type="submit" :disabled="excluirConfirmacao.trim().toLowerCase() !== (excluirAlvo?.sam || '').toLowerCase()"
                  class="px-4 py-2 rounded-lg text-sm font-semibold bg-rose-500 text-white disabled:opacity-30 disabled:cursor-not-allowed">
            Excluir definitivamente
          </button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

  <!-- Modal: resetar senha -->
  <div x-show="resetTarget !== null" x-cloak class="fixed inset-0 z-30 flex items-center justify-center modal-overlay p-4">
    <div class="modal-panel w-full max-w-sm p-6" @click.outside="resetTarget = null">
      <h2 class="text-base font-semibold mb-1">Resetar senha</h2>
      <p class="text-xs text-slate-500 mb-4">Usuário: <span x-text="resetTarget" class="text-slate-300"></span></p>
      <form method="post" class="space-y-3">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="sam" :value="resetTarget">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Nova senha</label>
          <div class="campo-com-botao">
            <input type="text" id="senha_reset" name="new_password" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
            <button type="button" class="botao-gerar" @click="gerarSenha('senha_reset')">gerar</button>
          </div>
          <p class="text-[11px] text-amber-400/70 mt-1"><?= htmlspecialchars(\App\Ldap\PasswordPolicy::descricao()) ?></p>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="resetTarget = null" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 text-sm font-semibold">Redefinir</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function tabelaUsuarios(usuarios) {
  // "José" e "jose" devem se encontrar: NFD separa a letra do acento e o
  // range ̀-ͯ remove só os acentos, preservando o resto.
  const semAcento = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '');

  return {
    usuarios: usuarios.map(u => ({ ...u, buscaNorm: semAcento(u.busca) })),
    q: '',
    pagina: 1,
    porPagina: 25,
    showCreate: false,
    resetTarget: null,
    excluirAlvo: null,
    excluirConfirmacao: '',

    abrirExclusao(u) {
      this.excluirAlvo = u;
      // Zerado a cada abertura: o texto digitado para uma conta nunca pode
      // valer como confirmação para outra.
      this.excluirConfirmacao = '';
    },

    fecharExclusao() {
      this.excluirAlvo = null;
      this.excluirConfirmacao = '';
    },

    get filtrados() {
      const termo = semAcento(this.q.trim().toLowerCase());
      if (termo === '') return this.usuarios;
      // Cada palavra digitada precisa aparecer: "ana silva" acha "Ana Silva"
      // mesmo que o sobrenome venha antes do nome no displayName.
      const termos = termo.split(/\s+/);
      return this.usuarios.filter(u => termos.every(t => u.buscaNorm.includes(t)));
    },

    get totalPaginas() {
      return Math.max(1, Math.ceil(this.filtrados.length / this.porPagina));
    },

    // Se o filtro encolher a lista, a página atual pode passar do fim; usar a
    // página efetiva evita uma tabela vazia mesmo que o $watch não tenha
    // corrido ainda.
    get paginaEfetiva() {
      return Math.min(Math.max(1, this.pagina), this.totalPaginas);
    },

    get visiveis() {
      const inicio = (this.paginaEfetiva - 1) * this.porPagina;
      return this.filtrados.slice(inicio, inicio + this.porPagina);
    },

    get inicioVisivel() {
      return this.filtrados.length === 0 ? 0 : (this.paginaEfetiva - 1) * this.porPagina + 1;
    },

    get fimVisivel() {
      return Math.min(this.paginaEfetiva * this.porPagina, this.filtrados.length);
    },

    // Mostra no máximo 7 botões: primeira, última, a atual e suas vizinhas,
    // com reticências no lugar do que foi omitido.
    get paginasVisiveis() {
      const total = this.totalPaginas;
      if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);

      const atual = this.paginaEfetiva;
      const paginas = [1];

      let ini = Math.max(2, atual - 1);
      let fim = Math.min(total - 1, atual + 1);
      if (atual <= 3) fim = 4;
      if (atual >= total - 2) ini = total - 3;

      if (ini > 2) paginas.push('…');
      for (let p = ini; p <= fim; p++) paginas.push(p);
      if (fim < total - 1) paginas.push('…');

      paginas.push(total);
      return paginas;
    },

    irPara(p) {
      this.pagina = Math.min(Math.max(1, p), this.totalPaginas);
    },

    init() {
      // Digitar uma busca nova precisa levar de volta à primeira página, senão
      // o resultado pode cair fora do intervalo atual e a tabela parece vazia.
      this.$watch('q', () => { this.pagina = 1; });
      this.$watch('porPagina', () => { this.pagina = 1; });
    },
  };
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
