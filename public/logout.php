<?php
require __DIR__ . '/../src/bootstrap.php';

use App\Auth;

Auth::logout();
header('Location: login.php');
exit;
