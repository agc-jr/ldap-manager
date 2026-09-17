<?php
/** @var string $pageTitle */
use App\Auth;
$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'AD Manager') ?> · AD Manager</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-950 text-slate-100 font-[Inter] antialiased">
<div class="min-h-screen flex">
  <?php if ($user['id']): ?>
  <aside class="w-64 shrink-0 bg-slate-900/70 border-r border-white/5 backdrop-blur-xl hidden md:flex flex-col">
    <div class="h-16 flex items-center gap-2 px-6 border-b border-white/5">
      <div class="h-8 w-8 rounded-lg bg-gradient-to-br from-indigo-500 to-cyan-400 flex items-center justify-center font-bold text-slate-950">AD</div>
      <span class="font-semibold tracking-tight">Manager</span>
    </div>
    <nav class="flex-1 px-3 py-6 space-y-1 text-sm">
      <?php
        $nav = [
          'dashboard.php' => ['Dashboard', 'grid'],
          'users.php'     => ['Usuários', 'users'],
          'groups.php'    => ['Grupos', 'shield'],
        ];
        if (Auth::isAdmin()) { $nav['admin_users.php'] = ['Operadores', 'lock']; }
        $current = basename($_SERVER['SCRIPT_NAME']);
      ?>
      <?php foreach ($nav as $href => [$label, $icon]): $active = $current === $href; ?>
        <a href="<?= $href ?>"
           class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition
                  <?= $active ? 'bg-gradient-to-r from-indigo-500/20 to-cyan-400/10 text-white ring-1 ring-inset ring-indigo-400/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
          <span class="inline-block h-2 w-2 rounded-full <?= $active ? 'bg-cyan-400' : 'bg-slate-600' ?>"></span>
          <?= htmlspecialchars($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="p-4 border-t border-white/5 text-xs text-slate-500">
      <p class="font-medium text-slate-300"><?= htmlspecialchars($user['full_name'] ?? '') ?></p>
      <p class="capitalize"><?= htmlspecialchars($user['role'] ?? '') ?></p>
      <a href="logout.php" class="mt-3 inline-block text-rose-400 hover:text-rose-300">Sair →</a>
    </div>
  </aside>
  <?php endif; ?>

  <main class="flex-1 min-w-0">
    <?php if ($user['id']): ?>
    <header class="h-16 border-b border-white/5 flex items-center justify-between px-6 bg-slate-950/60 backdrop-blur sticky top-0 z-10">
      <h1 class="text-lg font-semibold tracking-tight"><?= htmlspecialchars($pageTitle ?? '') ?></h1>
      <div class="text-xs text-slate-500">exemplo.local</div>
    </header>
    <?php endif; ?>
    <div class="p-6 max-w-7xl mx-auto">
      <?php if (!empty($_SESSION['flash'])): ?>
        <div class="mb-6 rounded-xl px-4 py-3 text-sm ring-1
                    <?= $_SESSION['flash']['type'] === 'error'
                        ? 'bg-rose-500/10 text-rose-300 ring-rose-500/30'
                        : 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/30' ?>">
          <?= htmlspecialchars($_SESSION['flash']['message']) ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
      <?php endif; ?>
