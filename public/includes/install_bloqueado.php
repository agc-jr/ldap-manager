<?php
/**
 * Página mostrada quando o instalador é acessado com a instalação já feita.
 * Espera $titulo e $mensagem definidos por quem inclui.
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titulo ?? 'Instalação') ?> · AD Manager</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-950 text-slate-100 font-[Inter] antialiased min-h-screen flex items-center justify-center p-4">
  <div class="card p-6 max-w-md w-full">
    <h1 class="text-base font-semibold text-amber-300 mb-2"><?= htmlspecialchars($titulo ?? '') ?></h1>
    <p class="text-sm text-slate-300"><?= htmlspecialchars($mensagem ?? '') ?></p>
    <a href="login.php" class="mt-5 block text-center rounded-xl bg-white/5 border border-white/10 py-2.5 text-sm hover:bg-white/10">
      Ir para o login
    </a>
  </div>
</body>
</html>
