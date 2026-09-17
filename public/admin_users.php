<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/includes/flash.php';

use App\Auth;
use App\Database;

Auth::requireAdmin();
$pageTitle = 'Operadores da ferramenta';
$me = Auth::user();
$pdo = Database::connection();

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
            flash('success', "Operador \"{$username}\" criado.");
        } catch (\PDOException $e) {
            flash('error', 'Não foi possível criar: usuário já existe?');
        }
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

require __DIR__ . '/includes/layout_top.php';
?>

<div x-data="{ showCreate: false }">
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
            <td class="px-5 py-3">
              <?php if ($u['is_active']): ?>
                <span class="badge bg-emerald-500/10 text-emerald-300">Ativo</span>
              <?php else: ?>
                <span class="badge bg-rose-500/10 text-rose-300">Inativo</span>
              <?php endif; ?>
            </td>
            <td class="px-5 py-3 text-slate-500 text-xs"><?= $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : 'nunca' ?></td>
            <td class="px-5 py-3 text-right">
              <form method="post" onsubmit="return confirm('Confirma a alteração de status?');">
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

  <div x-show="showCreate" x-cloak class="fixed inset-0 z-30 flex items-center justify-center bg-black/60 p-4">
    <div class="card w-full max-w-md p-6" @click.outside="showCreate = false">
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
          <select name="role" class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
            <option value="operator">Operador (só gerencia LDAP)</option>
            <option value="admin">Administrador (gerencia operadores)</option>
          </select>
        </div>
        <div>
          <label class="block text-xs text-slate-400 mb-1">Senha inicial</label>
          <input type="text" name="password" required class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="showCreate = false" class="px-4 py-2 text-sm text-slate-400 hover:text-white">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 text-sm font-semibold">Criar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
