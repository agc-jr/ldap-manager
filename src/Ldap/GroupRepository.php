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

        return array_map([self::class, 'decorate'], $entries);
    }

    public function findByCn(string $cn): ?array
    {
        $entries = $this->ldap->search('(&(objectClass=group)(cn=' . ldap_escape($cn, '', LDAP_ESCAPE_FILTER) . '))', self::ATTRS);

        return isset($entries[0]) ? self::decorate($entries[0]) : null;
    }

    /**
     * O AD devolve 'member' ausente quando o grupo está vazio e uma string
     * quando há um único membro — normalizar aqui evita que cada chamador
     * repita esse tratamento (e é por isso que findByCn também decora: antes,
     * só all() o fazia, e member_count vinha nulo pelo outro caminho).
     */
    private static function decorate(array $entry): array
    {
        $members = $entry['member'] ?? [];
        $entry['members'] = is_array($members) ? $members : ($members !== '' ? [$members] : []);
        $entry['member_count'] = count($entry['members']);

        return $entry;
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
