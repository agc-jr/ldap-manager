<?php
/**
 * Cria (ou reseta a senha d)o primeiro usuário admin da ferramenta.
 * Uso: php bin/create-admin.php <username> <senha> ["Nome completo"]
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Database;

if ($argc < 3) {
    fwrite(STDERR, "Uso: php bin/create-admin.php <username> <senha> [\"Nome completo\"]\n");
    exit(1);
}

$username = $argv[1];
$password = $argv[2];
$fullName = $argv[3] ?? $username;

$pdo = Database::connection();
$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare(
    'INSERT INTO app_users (username, full_name, password_hash, role, must_change_password)
     VALUES (:u, :f, :p, "admin", 1)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = "admin", is_active = 1'
);
$stmt->execute(['u' => $username, 'f' => $fullName, 'p' => $hash]);

echo "Usuário admin \"{$username}\" criado/atualizado com sucesso.\n";
