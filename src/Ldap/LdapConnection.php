<?php

declare(strict_types=1);

namespace App\Ldap;

use App\Config;
use RuntimeException;

/**
 * Encapsula a conexão com o Active Directory / Samba4 AD DC via ext-ldap.
 *
 * Notas importantes sobre Samba4 AD / Active Directory:
 *  - Alterar a senha (unicodePwd) só é permitido em conexão LDAPS (636) ou
 *    LDAP com STARTTLS. O AD recusa em texto puro por segurança.
 *  - unicodePwd precisa ser a senha entre aspas duplas, em UTF-16LE.
 *  - pwdLastSet = 0 força troca de senha no próximo logon.
 *  - userAccountControl controla se a conta está ativa/desativada (entre outras flags).
 */
final class LdapConnection
{
    /** @var \LDAP\Connection|resource Objeto de conexão em PHP 8.1+, resource em versões antigas */
    private mixed $conn;
    private string $baseDn;

    public function __construct()
    {
        $cfg = Config::get('ldap');

        if (!extension_loaded('ldap')) {
            throw new RuntimeException('A extensão php-ldap não está instalada/habilitada.');
        }

        $uri = sprintf('%s:%d', rtrim($cfg['host'], '/'), $cfg['port']);

        $conn = ldap_connect($uri);
        if ($conn === false) {
            throw new RuntimeException("Não foi possível iniciar conexão LDAP com {$uri}");
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

        if (($cfg['tls_verify'] ?? true) === false) {
            // Necessário em ambientes com certificado autoassinado/expirado.
            // O ideal a médio prazo é renovar o certificado do Samba e remover isto.
            putenv('LDAPTLS_REQCERT=never');
        }

        $bound = @ldap_bind($conn, $cfg['bind_dn'], $cfg['bind_password']);
        if (!$bound) {
            $err = ldap_error($conn);
            throw new RuntimeException("Falha ao autenticar no LDAP ({$cfg['bind_dn']}): {$err}");
        }

        $this->conn = $conn;
        $this->baseDn = $cfg['base_dn'];
    }

    public function raw(): mixed
    {
        return $this->conn;
    }

    public function baseDn(): string
    {
        return $this->baseDn;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $filter, array $attributes = [], ?string $baseDn = null): array
    {
        $result = @ldap_search($this->conn, $baseDn ?? $this->baseDn, $filter, $attributes);
        if ($result === false) {
            throw new RuntimeException('Busca LDAP falhou: ' . ldap_error($this->conn));
        }

        $entries = ldap_get_entries($this->conn, $result);
        unset($entries['count']);

        return array_map([self::class, 'normalizeEntry'], $entries);
    }

    private static function normalizeEntry(array $entry): array
    {
        $clean = ['dn' => $entry['dn'] ?? null];

        foreach ($entry as $key => $value) {
            if (is_int($key) || $key === 'dn' || $key === 'count') {
                continue;
            }
            if (is_array($value)) {
                unset($value['count']);
                $clean[$key] = count($value) === 1 ? $value[0] : $value;
            }
        }

        return $clean;
    }

    public function add(string $dn, array $entry): void
    {
        if (!@ldap_add($this->conn, $dn, $entry)) {
            throw new RuntimeException('Falha ao criar objeto LDAP: ' . ldap_error($this->conn));
        }
    }

    public function modify(string $dn, array $entry): void
    {
        if (!@ldap_mod_replace($this->conn, $dn, $entry)) {
            throw new RuntimeException('Falha ao modificar objeto LDAP: ' . ldap_error($this->conn));
        }
    }

    public function modifyAdd(string $dn, array $entry): void
    {
        if (!@ldap_mod_add($this->conn, $dn, $entry)) {
            throw new RuntimeException('Falha ao adicionar atributo LDAP: ' . ldap_error($this->conn));
        }
    }

    public function modifyDelete(string $dn, array $entry): void
    {
        if (!@ldap_mod_del($this->conn, $dn, $entry)) {
            throw new RuntimeException('Falha ao remover atributo LDAP: ' . ldap_error($this->conn));
        }
    }

    /**
     * Codifica a senha no formato exigido pelo atributo unicodePwd do AD.
     */
    public static function encodePassword(string $password): string
    {
        return mb_convert_encoding('"' . $password . '"', 'UTF-16LE', 'UTF-8');
    }

    public function close(): void
    {
        ldap_unbind($this->conn);
    }
}
