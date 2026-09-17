<?php
/** @var string $pageTitle */
use App\Auth;
$user = Auth::user();

/*
 * O menu é montado uma vez e usado em dois lugares: a barra lateral fixa do
 * desktop e a gaveta do celular. Antes ele vivia dentro do <aside>, que some
 * abaixo de md — no celular a navegação simplesmente não existia, nem o botão
 * de sair.
 */
$nav = [
    'dashboard.php' => 'Dashboard',
    'users.php'     => 'Usuários',
    'groups.php'    => 'Grupos',
];

if (Auth::isAdmin()) {
    $nav['admin_users.php'] = 'Operadores';
    $nav['domains.php']     = 'Domínios';
}

$current = basename($_SERVER['SCRIPT_NAME']);

/** Um item do menu, nos dois contextos. */
function itemDoMenu(string $href, string $label, bool $ativo): string
{
    $classes = $ativo
        ? 'bg-gradient-to-r from-indigo-500/20 to-cyan-400/10 text-white ring-1 ring-inset ring-indigo-400/30'
        : 'text-slate-400 hover:text-white hover:bg-white/5';

    return '<a href="' . htmlspecialchars($href) . '"'
        . ' class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition ' . $classes . '">'
        . '<span class="inline-block h-2 w-2 rounded-full ' . ($ativo ? 'bg-cyan-400' : 'bg-slate-600') . '"></span>'
        . htmlspecialchars($label)
        . '</a>';
}

// Seletor de domínio: com um só cadastrado vira rótulo, porque não há escolha
// a oferecer. Calculado aqui para servir ao cabeçalho e à gaveta.
$dominiosDisponiveis = [];
$dominioAtivo = null;

if ($user['id']) {
    try {
        $dominiosDisponiveis = \App\Ldap\ActiveDomain::disponiveis();
        $dominioAtivo = \App\Ldap\ActiveDomain::atual();
    } catch (\Throwable $e) {
        // Banco indisponível ou instalação incompleta: o cabeçalho não é lugar
        // de mostrar esse erro, a própria página já o mostrará.
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= htmlspecialchars($pageTitle ?? 'AD Manager') ?> · AD Manager</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-950 text-slate-100 font-[Inter] antialiased">
<div class="min-h-screen flex" x-data="{ menuAberto: false }" @keydown.escape.window="menuAberto = false">

  <?php if ($user['id']): ?>
  <!-- Barra lateral: só a partir de md -->
  <aside class="w-64 shrink-0 bg-slate-900/70 border-r border-white/5 backdrop-blur-xl hidden md:flex flex-col">
    <div class="h-16 flex items-center gap-2 px-6 border-b border-white/5">
      <div class="h-8 w-8 rounded-lg bg-gradient-to-br from-indigo-500 to-cyan-400 flex items-center justify-center font-bold text-slate-950">AD</div>
      <span class="font-semibold tracking-tight">Manager</span>
    </div>
    <nav class="flex-1 px-3 py-6 space-y-1 text-sm">
      <?php foreach ($nav as $href => $label): ?>
        <?= itemDoMenu($href, $label, $current === $href) ?>
      <?php endforeach; ?>
    </nav>
    <div class="p-4 border-t border-white/5 text-xs text-slate-500">
      <p class="font-medium text-slate-300"><?= htmlspecialchars($user['full_name'] ?? '') ?></p>
      <p class="capitalize"><?= htmlspecialchars($user['role'] ?? '') ?></p>
      <a href="change_password.php" class="mt-3 mr-3 inline-block text-slate-400 hover:text-slate-200">Trocar senha</a>
      <a href="logout.php" class="mt-3 inline-block text-rose-400 hover:text-rose-300">Sair →</a>
    </div>
  </aside>

  <!-- Gaveta do celular -->
  <div x-show="menuAberto" x-cloak class="fixed inset-0 z-40 md:hidden">
    <div class="absolute inset-0 modal-overlay" @click="menuAberto = false"></div>

    <aside class="absolute inset-y-0 left-0 w-72 max-w-[85%] bg-slate-900 border-r border-white/10 flex flex-col shadow-2xl"
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="-translate-x-full"
           x-transition:enter-end="translate-x-0"
           x-transition:leave="transition ease-in duration-150"
           x-transition:leave-start="translate-x-0"
           x-transition:leave-end="-translate-x-full">
      <div class="h-16 flex items-center justify-between px-5 border-b border-white/5">
        <div class="flex items-center gap-2">
          <div class="h-8 w-8 rounded-lg bg-gradient-to-br from-indigo-500 to-cyan-400 flex items-center justify-center font-bold text-slate-950">AD</div>
          <span class="font-semibold tracking-tight">Manager</span>
        </div>
        <button @click="menuAberto = false" aria-label="Fechar menu"
                class="text-slate-400 hover:text-white text-2xl leading-none px-2">&times;</button>
      </div>

      <nav class="flex-1 px-3 py-5 space-y-1 text-sm overflow-y-auto">
        <?php foreach ($nav as $href => $label): ?>
          <?= itemDoMenu($href, $label, $current === $href) ?>
        <?php endforeach; ?>

        <?php if (count($dominiosDisponiveis) > 1): ?>
          <div class="pt-4 mt-4 border-t border-white/5">
            <p class="px-3 pb-2 text-xs text-slate-500">Domínio</p>
            <form method="post" action="switch_domain.php" class="px-3">
              <select name="ldap_domain_id" onchange="this.form.submit()"
                      class="w-full rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400/50">
                <?php foreach ($dominiosDisponiveis as $d): ?>
                  <option value="<?= (int) $d['id'] ?>" <?= ($dominioAtivo && $d['id'] === $dominioAtivo['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
        <?php endif; ?>
      </nav>

      <div class="p-4 border-t border-white/5 text-xs text-slate-500">
        <p class="font-medium text-slate-300"><?= htmlspecialchars($user['full_name'] ?? '') ?></p>
        <p class="capitalize"><?= htmlspecialchars($user['role'] ?? '') ?></p>
        <a href="change_password.php" class="mt-3 mr-3 inline-block text-slate-400 hover:text-slate-200">Trocar senha</a>
        <a href="logout.php" class="mt-3 inline-block text-rose-400 hover:text-rose-300">Sair →</a>
      </div>
    </aside>
  </div>
  <?php endif; ?>

  <main class="flex-1 min-w-0">
    <?php if ($user['id']): ?>
    <header class="h-16 border-b border-white/5 flex items-center justify-between gap-3 px-4 sm:px-6 bg-slate-950/60 backdrop-blur sticky top-0 z-20">
      <div class="flex items-center gap-3 min-w-0">
        <button @click="menuAberto = true" aria-label="Abrir menu"
                class="md:hidden text-slate-300 hover:text-white shrink-0 -ml-1 p-1">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
        <h1 class="text-base sm:text-lg font-semibold tracking-tight truncate"><?= htmlspecialchars($pageTitle ?? '') ?></h1>
      </div>

      <?php if (count($dominiosDisponiveis) > 1): ?>
        <!-- No celular a troca de domínio fica na gaveta, para não espremer o cabeçalho -->
        <form method="post" action="switch_domain.php" class="hidden sm:flex items-center gap-2 shrink-0">
          <span class="text-xs text-slate-500 hidden md:inline">Domínio:</span>
          <select name="ldap_domain_id" onchange="this.form.submit()"
                  class="rounded-lg bg-white/5 border border-white/10 px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-400/50">
            <?php foreach ($dominiosDisponiveis as $d): ?>
              <option value="<?= (int) $d['id'] ?>" <?= ($dominioAtivo && $d['id'] === $dominioAtivo['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php elseif ($dominioAtivo !== null): ?>
        <div class="text-xs text-slate-500 truncate shrink-0" title="<?= htmlspecialchars($dominioAtivo['host']) ?>">
          <?= htmlspecialchars($dominioAtivo['name']) ?>
          <span class="text-slate-600 hidden sm:inline">· <?= htmlspecialchars((string) $dominioAtivo['domain_upn']) ?></span>
        </div>
      <?php else: ?>
        <div class="text-xs text-amber-400/80 shrink-0">Sem domínio</div>
      <?php endif; ?>
    </header>
    <?php endif; ?>
    <div class="p-4 sm:p-6 max-w-7xl mx-auto">
      <?php if (!empty($_SESSION['flash'])): ?>
        <div class="mb-6 rounded-xl px-4 py-3 text-sm ring-1
                    <?= $_SESSION['flash']['type'] === 'error'
                        ? 'bg-rose-500/10 text-rose-300 ring-rose-500/30'
                        : 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/30' ?>">
          <?= htmlspecialchars($_SESSION['flash']['message']) ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
      <?php endif; ?>

