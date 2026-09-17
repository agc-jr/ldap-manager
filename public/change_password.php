<?php
/**
 * Troca de senha do usuário DA FERRAMENTA (tabela app_users).
 * Não confundir com o reset de senha de usuários do AD, feito em users.php.
 *
 * Esta tela é obrigatória enquanto must_change_password estiver ligado: é o
 * que impede uma senha provisória de virar permanente.
 */
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/includes/flash.php';

use App\Audit\AuditLogger;
use App\Auth;

// true = esta é justamente a tela de troca, não pode redirecionar para si mesma
Auth::requireLogin(true);

$appUser = Auth::user();
$obrigatoria = !empty($_SESSION['must_change_password']);
$erro = null;

const MIN_SENHA = 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $atual      = (string) ($_POST['senha_atual'] ?? '');
    $nova       = (string) ($_POST['nova_senha'] ?? '');
    $confirmada = (string) ($_POST['confirmar_senha'] ?? '');

    if (Auth::attempt((string) $appUser['username'], $atual) === null) {
        $erro = 'A senha atual está incorreta.';
        usleep(300000);
    } elseif (mb_strlen($nova) < MIN_SENHA) {
        $erro = 'A nova senha precisa ter pelo menos ' . MIN_SENHA . ' caracteres.';
    } elseif ($nova !== $confirmada) {
        $erro = 'A confirmação não confere com a nova senha.';
    } elseif ($nova === $atual) {
        $erro = 'A nova senha precisa ser diferente da atual.';
    } else {
        Auth::changePassword((int) $appUser['id'], $nova);

        AuditLogger::log(
            (int) $appUser['id'],
            (string) $appUser['username'],
            'auth.password_changed',
            'app_user',
            (string) $appUser['username']
        );

        flash('success', 'Senha alterada com sucesso.');
        header('Location: dashboard.php');
        exit;
    }
}

$pageTitle = 'Trocar senha';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="max-w-md">
  <?php if ($obrigatoria): ?>
    <div class="card p-4 mb-6 border-amber-500/30 bg-amber-500/5">
      <p class="text-sm text-amber-300 font-medium">Defina uma nova senha para continuar</p>
      <p class="text-xs text-amber-400/80 mt-1">
        Você entrou com uma senha provisória. Enquanto ela não for trocada, as demais
        telas ficam bloqueadas.
      </p>
    </div>
  <?php endif; ?>

  <form method="post" class="card p-6 space-y-4">
    <?php if ($erro): ?>
      <div class="rounded-xl px-4 py-3 text-sm bg-rose-500/10 text-rose-300 ring-1 ring-rose-500/30">
        <?= htmlspecialchars($erro) ?>
      </div>
    <?php endif; ?>

    <div>
      <label class="block text-xs font-medium text-slate-400 mb-1.5">Senha atual</label>
      <input type="password" name="senha_atual" required autofocus autocomplete="current-password"
             class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400/50">
    </div>

    <div>
      <label class="block text-xs font-medium text-slate-400 mb-1.5">Nova senha</label>
      <input type="password" name="nova_senha" required minlength="<?= MIN_SENHA ?>" autocomplete="new-password"
             class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400/50">
      <p class="text-xs text-slate-600 mt-1.5">Mínimo de <?= MIN_SENHA ?> caracteres.</p>
    </div>

    <div>
      <label class="block text-xs font-medium text-slate-400 mb-1.5">Confirmar nova senha</label>
      <input type="password" name="confirmar_senha" required minlength="<?= MIN_SENHA ?>" autocomplete="new-password"
             class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400/50">
    </div>

    <div class="flex items-center gap-3 pt-1">
      <button type="submit"
              class="rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 font-semibold px-5 py-2.5 text-sm hover:opacity-90 transition">
        Salvar nova senha
      </button>
      <?php if (!$obrigatoria): ?>
        <a href="dashboard.php" class="text-sm text-slate-400 hover:text-slate-200">Cancelar</a>
      <?php else: ?>
        <a href="logout.php" class="text-sm text-slate-500 hover:text-slate-300">Sair</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
