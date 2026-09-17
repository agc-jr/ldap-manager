<?php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/includes/flash.php';

use App\Auth;

if (Auth::check()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $user = $username !== '' && $password !== '' ? Auth::attempt($username, $password) : null;

    if ($user) {
        Auth::login($user);
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Usuário ou senha inválidos.';
    usleep(300000); // pequena defesa contra brute-force por timing
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · AD Manager</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-950 text-slate-100 font-[Inter] antialiased min-h-screen flex items-center justify-center relative overflow-hidden">

  <div class="pointer-events-none absolute inset-0 opacity-40">
    <div class="absolute -top-32 -left-32 h-96 w-96 rounded-full bg-indigo-600/30 blur-3xl"></div>
    <div class="absolute -bottom-32 -right-32 h-96 w-96 rounded-full bg-cyan-500/20 blur-3xl"></div>
  </div>

  <div class="relative w-full max-w-sm mx-4">
    <div class="text-center mb-8">
      <div class="mx-auto h-12 w-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-cyan-400 flex items-center justify-center font-bold text-slate-950 text-lg">AD</div>
      <h1 class="mt-4 text-xl font-semibold tracking-tight">AD Manager Web</h1>
      <p class="text-sm text-slate-500 mt-1">Gestão de usuários e grupos do domínio</p>
    </div>

    <form method="post" class="card p-6 space-y-4">
      <?php if ($error): ?>
        <div class="rounded-xl px-4 py-3 text-sm bg-rose-500/10 text-rose-300 ring-1 ring-rose-500/30">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <div>
        <label class="block text-xs font-medium text-slate-400 mb-1.5">Usuário</label>
        <input type="text" name="username" autofocus required
               class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400/50">
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-400 mb-1.5">Senha</label>
        <input type="password" name="password" required
               class="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400/50">
      </div>

      <button type="submit"
              class="w-full rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 text-slate-950 font-semibold py-2.5 text-sm hover:opacity-90 transition">
        Entrar
      </button>
    </form>

    <p class="text-center text-xs text-slate-600 mt-6">Acesso restrito à rede interna</p>
  </div>
</body>
</html>
