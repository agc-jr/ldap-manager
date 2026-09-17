<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Conexão PDO única (singleton) com o banco local da ferramenta.
 */
final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $cfg = Config::get('db');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['database'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        try {
            self::$instance = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // Nunca expor credenciais/DSN em produção; log interno apenas.
            error_log('[ldap-manager] Falha ao conectar ao banco: ' . $e->getMessage());
            throw new \RuntimeException('Não foi possível conectar ao banco de dados local.');
        }

        return self::$instance;
    }
}
