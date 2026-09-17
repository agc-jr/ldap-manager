<?php
require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Audit\AuditLogger;
use App\Ldap\ActiveDomain;
use App\Ldap\UserRepository;
use App\Ldap\GroupRepository;

Auth::requireLogin();

$pageTitle = 'Dashboard';

$stats = [
    'total' => 0, 'active' => 0, 'disabled' => 0,
    'password_expired' => 0, 'stale_30' => 0,
];
$groupCounts = [];
$recentAudit = [];
$ldapError = null;

try {
    $ldap = ActiveDomain::conectar();
    $users = (new UserRepository($ldap))->all();
    $groups = (new GroupRepository($ldap))->all();

    $stats['total'] = count($users);

    foreach ($users as $u) {
        if ($u['is_disabled']) { $stats['disabled']++; } else { $stats['active']++; }
        if ($u['password_expired']) { $stats['password_expired']++; }

        foreach ($u['groups'] as $g) {
            $groupCounts[$g] = ($groupCounts[$g] ?? 0) + 1;
        }
    }

    arsort($groupCounts);
    $groupCounts = array_slice($groupCounts, 0, 8, true);

    $recentAudit = AuditLogger::recent(8);
} catch (\Throwable $e) {
    $ldapError = $e->getMessage();
}

require __DIR__ . '/includes/layout_top.php';
?>

<?php if ($ldapError): ?>
  <div class="card p-6 mb-6 border-rose-500/30 bg-rose-500/5">
    <p class="text-sm text-rose-300 font-medium">Não foi possível conectar ao domínio <?= htmlspecialchars(\App\Config::get('ldap.domain_upn', '')) ?></p>
    <p class="text-xs text-rose-400/80 mt-1"><?= htmlspecialchars($ldapError) ?></p>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="card p-5">
    <p class="text-xs text-slate-500 mb-1">Usuários no domínio</p>
    <p class="text-3xl font-semibold"><?= $stats['total'] ?></p>
  </div>
  <div class="card p-5">
    <p class="text-xs text-slate-500 mb-1">Contas ativas</p>
    <p class="text-3xl font-semibold text-emerald-400"><?= $stats['active'] ?></p>
  </div>
  <div class="card p-5">
    <p class="text-xs text-slate-500 mb-1">Contas desativadas</p>
    <p class="text-3xl font-semibold text-rose-400"><?= $stats['disabled'] ?></p>
  </div>
  <div class="card p-5">
    <p class="text-xs text-slate-500 mb-1">Aguardando troca de senha</p>
    <p class="text-3xl font-semibold text-amber-400"><?= $stats['password_expired'] ?></p>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="card p-6 lg:col-span-1">
    <p class="text-sm font-medium mb-4">Usuários por grupo</p>
    <canvas id="chartGroups" height="220"></canvas>
  </div>

  <div class="card p-6 lg:col-span-1">
    <p class="text-sm font-medium mb-4">Ativos vs. desativados</p>
    <canvas id="chartStatus" height="220"></canvas>
  </div>

  <div class="card p-6 lg:col-span-1">
    <p class="text-sm font-medium mb-4">Atividade recente</p>
    <?php if (empty($recentAudit)): ?>
      <p class="text-sm text-slate-500">Nenhuma ação registrada ainda.</p>
    <?php else: ?>
      <ul class="space-y-3 text-sm">
        <?php foreach ($recentAudit as $log): ?>
          <li class="flex items-start gap-3">
            <span class="mt-1.5 h-1.5 w-1.5 rounded-full bg-cyan-400 shrink-0"></span>
            <div>
              <p class="text-slate-200"><span class="font-medium"><?= htmlspecialchars($log['app_username']) ?></span>
                 · <?= htmlspecialchars($log['action']) ?></p>
              <p class="text-xs text-slate-500"><?= htmlspecialchars($log['target_id']) ?> — <?= date('d/m H:i', strtotime($log['created_at'])) ?></p>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<script>
const groupLabels = <?= json_encode(array_keys($groupCounts)) ?>;
const groupValues = <?= json_encode(array_values($groupCounts)) ?>;

new Chart(document.getElementById('chartGroups'), {
  type: 'bar',
  data: {
    labels: groupLabels,
    datasets: [{ data: groupValues, backgroundColor: '#22d3ee', borderRadius: 6 }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: {
      x: { ticks: { color: '#94a3b8' }, grid: { display: false } },
      y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,.06)' } }
    }
  }
});

new Chart(document.getElementById('chartStatus'), {
  type: 'doughnut',
  data: {
    labels: ['Ativos', 'Desativados'],
    datasets: [{ data: [<?= $stats['active'] ?>, <?= $stats['disabled'] ?>], backgroundColor: ['#34d399', '#fb7185'] }]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { color: '#cbd5e1' } } }
  }
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
