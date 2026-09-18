<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/includes/flash.php';

use App\Audit\AuditLogger;
use App\Auth;
use App\Database;
use App\Ldap\DomainRepository;

Auth::requireAdmin();
$pageTitle = 'Operadores da ferramenta';
$me = Auth::user();
$pdo = Database::connection();

$dominioRepo = new DomainRepository();
$dominios = $dominioRepo->todos(false);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role     = in_array($_POST['role'] ?? '', ['admin', 'operator'], true) ? $_POST['role'] : 'operator';
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $fullName === '' || $password === '') {
        flash('error', 'Preencha usuário, nome e senha.');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO app_users (username, full_name, email, password_hash, role, must_change_password)
             VALUES (:u, :f, :e, :p, :r, 1)'
        );
        try {
            $stmt->execute([
                'u' => $username, 'f' => $fullName, 'e' => $email ?: null,
                'p' => password_hash($password, PASSWORD_BCRYPT), 'r' => $role,
            ]);
            // Vincula os domínios escolhidos. Sem isso o operador entra e não
            // enxerga nada — o admin não precisa de vínculo, vê todos.
            $novoId = (int) $pdo->lastInsertId();
            if ($role === 'operator') {
                $dominioRepo->definirDominiosDoUsuario($novoId, array_map('intval', (array) ($_POST['dominios'] ?? [])));
            }

            flash('success', "Operador \"{$username}\" criado.");
        } catch (\PDOException $e) {
            flash('error', 'Não foi possível criar: usuário já existe?');
        }
    }
    header('Location: admin_users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dominios') {
    $id = (int) ($_POST['id'] ?? 0);
    $escolhidos = array_map('intval', (array) ($_POST['dominios'] ?? []));

    $alvo = $pdo->prepare('SELECT username, role FROM app_users WHERE id = :id');
    $alvo->execute(['id' => $id]);
    $dadosAlvo = $alvo->fetch();

    if (!$dadosAlvo) {
        flash('error', 'Operador não encontrado.');
    } elseif ($dadosAlvo['role'] === 'admin') {
        // Admin enxerga tudo por definição; gravar vínculo aqui daria a falsa
        // impressão de que a lista o restringe.
        flash('error', 'Administradores acessam todos os domínios; não há o que restringir.');
    } else {
        $dominioRepo->definirDominiosDoUsuario($id, $escolhidos);
        AuditLogger::log((int) $me['id'], $me['username'], 'operator.domains', 'app_user', $dadosAlvo['username'], [
            'dominios' => $escolhidos,
        ]);
        flash('success', "Domínios de \"{$dadosAlvo['username']}\" atualizados.");
    }

    header('Location: admin_users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_senha') {
    $id    = (int) ($_POST['id'] ?? 0);
    $nova  = (string) ($_POST['nova_senha'] ?? '');

    $alvo = $pdo->prepare('SELECT username FROM app_users WHERE id = :id');
    $alvo->execute(['id' => $id]);
    $dadosAlvo = $alvo->fetch();

    if (!$dadosAlvo) {
        flash('error', 'Operador não encontrado.');
    } elseif (mb_strlen($nova) < 10) {
        flash('error', 'A senha precisa ter pelo menos 10 caracteres.');
    } else {
        // must_change_password volta a 1: quem recebe a senha por telefone ou
        // bilhete precisa trocá-la no primeiro acesso, senão a senha que o
        // administrador conhece continua valendo.
        $pdo->prepare(
            'UPDATE app_users SET password_hash = :h, must_change_password = 1 WHERE id = :id'
        )->execute(['h' => password_hash($nova, PASSWORD_BCRYPT), 'id' => $id]);

        AuditLogger::log((int) $me['id'], $me['username'], 'operator.reset_password', 'app_user', $dadosAlvo['username']);
        flash('success', "Senha de \"{$dadosAlvo['username']}\" redefinida. A troca será exigida no próximo acesso.");
    }

    header('Location: admin_users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id !== (int) $me['id']) {
        $pdo->prepare('UPDATE app_users SET is_active = NOT is_active WHERE id = :id')->execute(['id' => $id]);
        flash('success', 'Status atualizado.');
    } else {
        flash('error', 'Você não pode desativar sua própria conta.');
    }
    header('Location: admin_users.php');
    exit;
}

$appUsers = $pdo->query('SELECT * FROM app_users ORDER BY username')->fetchAll();

// Quais domínios cada operador já enxerga, para marcar as caixas.
$dominiosPorUsuario = [];
foreach ($appUsers as $u) {
    if ($u['role'] !== 'admin') {
        $dominiosPorUsuario[(int) $u['id']] = $dominioRepo->idsDoUsuario((int) $u['id']);
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<div x-data="{
       showCreate: false,
       papelNovo: 'operator',
       dominiosAlvo: null,
       dominiosMarcados: [],
       senhaAlvo: null,
       editarDominios(id, nome, ids) {
         this.dominiosAlvo = { id, nome };
         // Cópia: mexer nas caixas não deve alterar a tabela por trás do modal
         // antes de salvar.
         this.dominiosMarcados = [...ids];
       },
     }">
  <div class="flex justify-between items-center mb-5">
    <p class="text-sm text-slate-500">Contas que podem acessar esta ferramenta (não confundir com contas do domínio)</p>
    <button @click="showCreate = true"
            class="rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 font-semibold px-4 py-2.5 text-sm hover:opacity-90 whitespace-nowrap">
      + Novo operador
    </button>
  </div>

  <div class="card overflow-x-auto">
    <table class="data w-full text-sm">
      <thead>
        <tr class="text-left">
          <th class="px-5 py-3">Usuário</th>
          <th class="px-5 py-3">Nome</th>
          <th class="px-5 py-3">Papel</th>
          <th class="px-5 py-3">Domínios</th>
          <th class="px-5 py-3">Status</th>
          <th class="px-5 py-3">Último login</th>
          <th class="px-5 py-3 text-right">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($appUsers as $u): ?>
          <tr>
            <td class="px-5 py-3 font-medium"><?= htmlspecialchars($u['username']) ?></td>
            <td class="px-5 py-3 text-slate-300"><?= htmlspecialchars($u['full_name']) ?></td>
            <td class="px-5 py-3 capitalize text-slate-400"><?= htmlspecialchars($u['role']) ?></td>
            <td class="px-5 py-3 text-xs">
              <?php if ($u['role'] === 'admin'): ?>
                <span class="text-slate-500">todos</span>
              <?php else:
                $ids = $dominiosPorUsuario[(int) $u['id']] ?? [];
                $nomes = array_values(array_map(
                    fn ($d) => $d['name'],
                    array_filter($dominios, fn ($d) => in_array($d['id'], $ids, true))
                ));
              ?>
                <?php if ($nomes === []): ?>
                  <span class="text-amber-400/80" title="Este operador não enxerga domínio nenhum">nenhum</span>
                <?php else: ?>
                  <span class="text-slate-400"><?= htmlspecialchars(implode(', ', $nomes)) ?></span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="px-5 py-3">
              <?php if ($u['is_active']): ?>
                <span class="badge bg-emerald-500/10 text-emerald-300">Ativo</span>
              <?php else: ?>
                <span class="badge bg-rose-500/10 text-rose-300">Inativo</span>
              <?php endif; ?>
            </td>
            <td class="px-5 py-3 text-slate-500 text-xs"><?= $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : 'nunca' ?></td>
            <td class="px-5 py-3 text-right space-x-2 whitespace-nowrap">
              <?php if ($u['role'] !== 'admin'): ?>
                <button @click="editarDominios(<?= (int) $u['id'] ?>, <?= htmlspecialchars(json_encode($u['username']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($dominiosPorUsuario[(int) $u['id']] ?? []), ENT_QUOTES) ?>)"
                        class="text-xs text-indigo-300 hover:text-indigo-200">Domínios</button>
              <?php endif; ?>
              <?php if ((int) $u['id'] === (int) $me['id']): ?>
                <a href="change_password.php" class="text-xs text-indigo-300 hover:text-indigo-200">Trocar minha senha</a>
              <?php else: ?>
                <button @click="senhaAlvo = <?= htmlspecialchars(json_encode(['id' => (int) $u['id'], 'nome' => $u['username']]), ENT_QUOTES) ?>"
                        class="text-xs text-indigo-300 hover:text-indigo-200">Resetar senha</button>
              <?php endif; ?>
              <form method="post" class="inline" onsubmit="return confirm('Confirma a alteração de status?');">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="text-xs <?= $u['is_active'] ? 'text-rose-300 hover:text-rose-200' : 'text-emerald-300 hover:text-emerald-200' ?>">
                  <?= $u['is_active'] ? 'Desativar' : 'Reativar' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div x-show="showCreate" x-cloak class="fixed inset-0 z-30 flex items-center justify-center modal-overlay p-4">
    <div class="modal-panel w-full max-w-md p-6" @click.outside="showCreate = false">
      <h2 class="text-base font-semibold mb-4">Novo operador</h2>
      <form method="post" class="space-y-3">
        <input type="hidden" name="action" value="create">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Usuário (login na ferramenta)</label>
          <input name="username" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Nome completo</label>
          <input name="full_name" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">E-mail (opcional)</label>
          <input type="email" name="email" class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Papel</label>
          <select name="role" x-model="papelNovo" class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
            <option value="operator">Operador (só gerencia LDAP)</option>
            <option value="admin">Administrador (gerencia operadores)</option>
          </select>
        </div>

        <!-- Só faz sentido escolher domínios para operador: admin vê todos -->
        <div x-show="papelNovo === 'operator'" x-cloak>
          <label class="block text-xs text-slate-400 mb-1">Domínios que este operador poderá gerenciar</label>
          <?php if ($dominios === []): ?>
            <p class="text-xs text-amber-400/80">
              Nenhum domínio cadastrado ainda. Cadastre em "Domínios" e depois volte aqui para liberar o acesso.
            </p>
          <?php else: ?>
            <div class="space-y-1.5 rounded-lg bg-white/5 border border-white/10 px-3 py-2 max-h-40 overflow-y-auto">
              <?php foreach ($dominios as $d): ?>
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" name="dominios[]" value="<?= (int) $d['id'] ?>"
                         class="rounded border-white/20 bg-white/5">
                  <span><?= htmlspecialchars($d['name']) ?></span>
                  <span class="text-xs text-slate-500"><?= htmlspecialchars((string) $d['domain_upn']) ?></span>
                  <?php if (!$d['is_active']): ?><span class="text-xs text-slate-600">(inativo)</span><?php endif; ?>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="text-[11px] text-slate-500 mt-1">
              Sem nenhum marcado, o operador entra mas não enxerga domínio algum.
            </p>
          <?php endif; ?>
        </div>

        <div>
          <label class="block text-xs text-slate-400 mb-1">Senha inicial</label>
          <div class="campo-com-botao">
            <input type="text" id="senha_novo_operador" name="password" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
            <button type="button" class="botao-gerar" @click="gerarSenha('senha_novo_operador')">gerar</button>
          </div>
          <p class="text-[11px] text-slate-500 mt-1">Será exigida a troca no primeiro acesso.</p>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="showCreate = false" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 text-sm font-semibold">Criar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: resetar a senha de um colega -->
  <div x-show="senhaAlvo !== null" x-cloak class="fixed inset-0 z-30 flex items-center justify-center modal-overlay p-4">
    <div class="modal-panel w-full max-w-sm p-6" @click.outside="senhaAlvo = null">
      <h2 class="text-base font-semibold mb-1">Resetar senha de acesso</h2>
      <p class="text-xs text-slate-500 mb-4">
        Operador: <span class="text-slate-300" x-text="senhaAlvo?.nome"></span>
      </p>

      <div class="rounded-xl px-4 py-3 mb-4 text-xs bg-slate-500/10 text-slate-300 ring-1 ring-white/10">
        Esta é a senha de acesso <strong>à ferramenta</strong>, não a do domínio.
        Para redefinir a senha de um usuário do AD, use a tela Usuários.
      </div>

      <form method="post" class="space-y-3">
        <input type="hidden" name="action" value="reset_senha">
        <input type="hidden" name="id" :value="senhaAlvo?.id">
        <div>
          <label class="block text-xs text-slate-400 mb-1">Nova senha</label>
          <div class="campo-com-botao">
            <input type="text" id="senha_reset_operador" name="nova_senha" required minlength="10"
                   autocomplete="new-password"
                   class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
            <button type="button" class="botao-gerar" @click="gerarSenha('senha_reset_operador')">gerar</button>
          </div>
          <p class="text-[11px] text-slate-500 mt-1">
            Mínimo de 10 caracteres. Ele será obrigado a trocá-la no próximo acesso.
          </p>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="senhaAlvo = null" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 text-sm font-semibold">Redefinir</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: domínios de um operador -->
  <div x-show="dominiosAlvo !== null" x-cloak class="fixed inset-0 z-30 flex items-center justify-center modal-overlay p-4">
    <div class="modal-panel w-full max-w-md p-6" @click.outside="dominiosAlvo = null">
      <h2 class="text-base font-semibold mb-1">Domínios do operador</h2>
      <p class="text-xs text-slate-500 mb-4" x-text="dominiosAlvo?.nome"></p>

      <form method="post" class="space-y-3">
        <input type="hidden" name="action" value="dominios">
        <input type="hidden" name="id" :value="dominiosAlvo?.id">

        <?php if ($dominios === []): ?>
          <p class="text-xs text-amber-400/80">Nenhum domínio cadastrado ainda.</p>
        <?php else: ?>
          <div class="space-y-1.5 rounded-lg bg-white/5 border border-white/10 px-3 py-2 max-h-56 overflow-y-auto">
            <?php foreach ($dominios as $d): ?>
              <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="dominios[]" value="<?= (int) $d['id'] ?>"
                       x-model.number="dominiosMarcados"
                       class="rounded border-white/20 bg-white/5">
                <span><?= htmlspecialchars($d['name']) ?></span>
                <span class="text-xs text-slate-500"><?= htmlspecialchars((string) $d['domain_upn']) ?></span>
                <?php if (!$d['is_active']): ?><span class="text-xs text-slate-600">(inativo)</span><?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="text-[11px] text-slate-500">
            Tirar um domínio vale na hora: se o operador estiver usando esse domínio, a próxima
            página que ele abrir já não o alcança.
          </p>
        <?php endif; ?>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="dominiosAlvo = null" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 text-sm font-semibold">Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
