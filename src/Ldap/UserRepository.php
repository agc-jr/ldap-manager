<?php

declare(strict_types=1);

namespace App\Ldap;

use App\Config;
use RuntimeException;

/**
 * Operações de leitura/escrita sobre objetos "user" do AD.
 */
final class UserRepository
{
    private const ATTRS = [
        'cn', 'sAMAccountName', 'userPrincipalName', 'displayName', 'givenName', 'sn',
        'mail', 'userAccountControl', 'memberOf', 'distinguishedName',
        'whenCreated', 'pwdLastSet', 'lastLogonTimestamp',
    ];

    public function __construct(private LdapConnection $ldap)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $entries = $this->ldap->search(
            '(&(objectClass=user)(objectCategory=person))',
            self::ATTRS
        );

        return array_map([$this, 'decorate'], $entries);
    }

    public function findBySamAccountName(string $sam): ?array
    {
        $entries = $this->ldap->search(
            '(&(objectClass=user)(objectCategory=person)(sAMAccountName=' . self::escape($sam) . '))',
            self::ATTRS
        );

        return isset($entries[0]) ? $this->decorate($entries[0]) : null;
    }

    private function decorate(array $entry): array
    {
        $uac = (int) ($entry['userAccountControl'] ?? 0);
        $entry['is_disabled'] = UserAccountControl::isDisabled($uac);

        $memberOf = $entry['memberOf'] ?? [];
        $entry['groups'] = is_array($memberOf) ? array_map([self::class, 'cnFromDn'], $memberOf) : ($memberOf ? [self::cnFromDn($memberOf)] : []);

        $entry['password_expired'] = ($entry['pwdLastSet'] ?? '1') === '0';

        return $entry;
    }

    public static function cnFromDn(string $dn): string
    {
        if (preg_match('/^CN=([^,]+)/i', $dn, $m)) {
            return $m[1];
        }
        return $dn;
    }

    /**
     * Cria um novo usuário com senha inicial e força troca no primeiro logon.
     */
    public function create(string $samAccountName, string $givenName, string $sn, string $initialPassword, ?string $email = null): string
    {
        $cfg = Config::get('ldap');
        $ou = $cfg['default_user_ou'] ?? ('CN=Users,' . $cfg['base_dn']);
        $cn = trim($givenName . ' ' . $sn);
        $dn = "CN={$cn},{$ou}";

        $entry = [
            'objectClass'       => ['top', 'person', 'organizationalPerson', 'user'],
            'cn'                => $cn,
            'sAMAccountName'    => $samAccountName,
            'userPrincipalName' => $samAccountName . '@' . $cfg['domain_upn'],
            'givenName'         => $givenName,
            'sn'                => $sn,
            'displayName'       => $cn,
            // Cria já desabilitado; habilitamos depois de setar a senha (exigência comum do AD)
            'userAccountControl' => (string) UserAccountControl::withDisabled(UserAccountControl::NORMAL_ACCOUNT, true),
        ];

        if ($email !== null && $email !== '') {
            $entry['mail'] = $email;
        }

        $this->ldap->add($dn, $entry);

        $this->setPassword($dn, $initialPassword, forceChangeOnLogin: true);

        // Habilita a conta agora que a senha foi definida
        $this->ldap->modify($dn, [
            'userAccountControl' => (string) UserAccountControl::NORMAL_ACCOUNT,
        ]);

        return $dn;
    }

    /**
     * Define/reseta a senha. Requer LDAPS (a conexão já deve ter sido aberta em 636).
     */
    public function setPassword(string $dn, string $newPassword, bool $forceChangeOnLogin = true): void
    {
        $this->ldap->modify($dn, [
            'unicodePwd' => LdapConnection::encodePassword($newPassword),
        ]);

        $this->ldap->modify($dn, [
            'pwdLastSet' => $forceChangeOnLogin ? '0' : '-1',
        ]);
    }

    public function setDisabled(string $dn, bool $disabled, int $currentUac): void
    {
        // Nunca escrever um userAccountControl que nao veio do diretorio: gravar 0
        // (ou qualquer valor sem a flag NORMAL_ACCOUNT) deixa a conta em um estado
        // invalido no AD, dificil de reverter pela interface.
        if ($currentUac <= 0) {
            throw new RuntimeException(
                "userAccountControl atual nao foi lido do diretorio; abortando para nao corromper a conta {$dn}."
            );
        }

        $this->ldap->modify($dn, [
            'userAccountControl' => (string) UserAccountControl::withDisabled($currentUac, $disabled),
        ]);
    }

    private static function escape(string $value): string
    {
        return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
    }
}
