<?php
require __DIR__ . '/../src/bootstrap.php';

use App\Auth;

header('Location: ' . (Auth::check() ? 'dashboard.php' : 'login.php'));
exit;
