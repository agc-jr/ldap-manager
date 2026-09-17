<?php

declare(strict_types=1);

namespace App\Ldap;

/**
 * Operações de leitura/escrita sobre objetos "group" do AD.
 */
final class GroupRepository
{
    private const ATTRS = ['cn', 'distinguishedName', 'member', 'description', 'groupType'];

    public function __construct(private LdapConnection $ldap)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $entries = $this->ldap->search('(objectClass=group)', self::ATTRS);

        return array_map(function (array $entry) {
            $members = $entry['member'] ?? [];
            $entry['members'] = is_array($members) ? $members : ($members ? [$members] : []);
            $entry['member_count'] = count($entry['members']);
            return $entry;
        }, $entries);
    }

    public function findByCn(string $cn): ?array
    {
        $entries = $this->ldap->search('(&(objectClass=group)(cn=' . ldap_escape($cn, '', LDAP_ESCAPE_FILTER) . '))', self::ATTRS);
        return $entries[0] ?? null;
    }

    public function addMember(string $groupDn, string $userDn): void
    {
        $this->ldap->modifyAdd($groupDn, ['member' => $userDn]);
    }

    public function removeMember(string $groupDn, string $userDn): void
    {
        $this->ldap->modifyDelete($groupDn, ['member' => $userDn]);
    }
}
