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
    /**
     * O AD/Samba corta qualquer busca em MaxPageSize (1000 por padrão) e não
     * avisa: a resposta simplesmente vem truncada. Por isso toda busca é
     * paginada com o controle LDAP_CONTROL_PAGEDRESULTS.
     */
    private const PAGE_SIZE = 500;

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

        if (($cfg['tls_verify'] ?? true) === false) {
            // Necessário em ambientes com certificado autoassinado/expirado.
            // Precisa vir ANTES do ldap_connect: a libldap lê esta variável ao
            // montar o contexto TLS da conexão, não na hora do bind.
            // O ideal a médio prazo é renovar o certificado do Samba e remover isto.
            putenv('LDAPTLS_REQCERT=never');
        }

        $conn = ldap_connect($uri);
        if ($conn === false) {
            throw new RuntimeException("Não foi possível iniciar conexão LDAP com {$uri}");
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

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
     * Busca paginada. As chaves do retorno vêm no mesmo case em que o atributo
     * foi pedido em $attributes (ver normalizeEntry).
     *
     * @param array<int, string> $attributes
     * @return array<int, array<string, mixed>>
     */
    public function search(string $filter, array $attributes = [], ?string $baseDn = null): array
    {
        // ldap_get_entries() devolve todo nome de atributo em minúsculas.
        // Este mapa restaura o case canônico que o chamador pediu, para que o
        // resto do código possa usar $entry['sAMAccountName'] e não
        // $entry['samaccountname'].
        $canonical = [];
        foreach ($attributes as $attr) {
            $canonical[strtolower($attr)] = $attr;
        }

        $base = $baseDn ?? $this->baseDn;
        $cookie = '';
        $all = [];

        do {
            $controls = [[
                'oid'   => LDAP_CONTROL_PAGEDRESULTS,
                'value' => ['size' => self::PAGE_SIZE, 'cookie' => $cookie],
            ]];

            $result = @ldap_search(
                $this->conn,
                $base,
                $filter,
                $attributes,
                0,
                0,
                0,
                LDAP_DEREF_NEVER,
                $controls
            );

            if ($result === false) {
                throw new RuntimeException('Busca LDAP falhou: ' . ldap_error($this->conn));
            }

            $entries = ldap_get_entries($this->conn, $result);
            unset($entries['count']);

            foreach ($entries as $entry) {
                if (is_array($entry)) {
                    $all[] = self::normalizeEntry($entry, $canonical);
                }
            }

            $cookie = '';
            $respControls = [];
            if (@ldap_parse_result($this->conn, $result, $errcode, $matchedDn, $errMsg, $referrals, $respControls)) {
                $cookie = $respControls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '';
            }
        } while ($cookie !== '' && $cookie !== null);

        return $all;
    }

    /**
     * @param array<string, string> $canonical mapa nome_minusculo => NomeCanonico
     */
    private static function normalizeEntry(array $entry, array $canonical = []): array
    {
        $clean = ['dn' => $entry['dn'] ?? null];

        foreach ($entry as $key => $value) {
            if (is_int($key) || $key === 'dn' || $key === 'count') {
                continue;
            }
            if (is_array($value)) {
                unset($value['count']);
                $name = $canonical[strtolower((string) $key)] ?? $key;
                $clean[$name] = count($value) === 1 ? $value[0] : $value;
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
