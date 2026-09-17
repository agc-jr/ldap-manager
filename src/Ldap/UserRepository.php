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
        // Vem do cadastro do domínio, não mais de config/config.php: a mesma
        // instalação pode atender vários domínios, cada um com sua OU e UPN.
        $ou = $this->ldap->opcao('default_user_ou', 'CN=Users,' . $this->ldap->baseDn());
        $upn = $this->ldap->opcao('domain_upn', '');
        $cn = trim($givenName . ' ' . $sn);
        $dn = "CN={$cn},{$ou}";

        $entry = [
            'objectClass'       => ['top', 'person', 'organizationalPerson', 'user'],
            'cn'                => $cn,
            'sAMAccountName'    => $samAccountName,
            'userPrincipalName' => $samAccountName . '@' . $upn,
            'givenName'         => $givenName,
            'sn'                => $sn,
            'displayName'       => $cn,
            // Cria já desabilitado; habilitamos depois de setar a senha (exigência comum do AD)
            'userAccountControl' => (string) UserAccountControl::withDisabled(UserAccountControl::NORMAL_ACCOUNT, true),
        ];

        if ($email !== null && $email !== '') {
            $entry['mail'] = $email;
        }

        // Validar antes de tocar no diretório: se a senha for recusada depois do
        // add, a conta já existe e fica desativada (ela nasce desabilitada de
        // propósito, e só é habilitada após a senha entrar).
        $problemas = PasswordPolicy::validar($initialPassword, $samAccountName, $cn, (int) $this->ldap->opcao('password_min_length', PasswordPolicy::MIN_LENGTH_PADRAO));
        if ($problemas !== []) {
            throw new RuntimeException(PasswordPolicy::mensagemDeErro($problemas));
        }

        $this->ldap->add($dn, $entry);

        // A partir daqui a conta existe. Qualquer falha desfaz a criação, senão
        // sobra no diretório uma conta desativada e sem senha utilizável.
        try {
            $this->setPassword($dn, $initialPassword, forceChangeOnLogin: true);

            // Habilita a conta agora que a senha foi definida
            $this->ldap->modify($dn, [
                'userAccountControl' => (string) UserAccountControl::NORMAL_ACCOUNT,
            ]);
        } catch (RuntimeException $e) {
            try {
                $this->ldap->delete($dn);
            } catch (RuntimeException $falhaAoDesfazer) {
                throw new RuntimeException(
                    $e->getMessage() . ' A conta "' . $samAccountName . '" chegou a ser criada e não pôde ser'
                    . ' removida automaticamente; ela está desativada no diretório e precisa ser tratada à mão.',
                    0,
                    $e
                );
            }

            throw $e;
        }

        return $dn;
    }

    /**
     * Define/reseta a senha. Requer LDAPS (a conexão já deve ter sido aberta em 636).
     *
     * @param string $login        usado só para validar a senha contra a política
     * @param string $nomeCompleto idem
     */
    public function setPassword(
        string $dn,
        string $newPassword,
        bool $forceChangeOnLogin = true,
        string $login = '',
        string $nomeCompleto = ''
    ): void {
        $problemas = PasswordPolicy::validar($newPassword, $login, $nomeCompleto, (int) $this->ldap->opcao('password_min_length', PasswordPolicy::MIN_LENGTH_PADRAO));
        if ($problemas !== []) {
            throw new RuntimeException(PasswordPolicy::mensagemDeErro($problemas));
        }

        try {
            $this->ldap->modify($dn, [
                'unicodePwd' => LdapConnection::encodePassword($newPassword),
            ]);
        } catch (RuntimeException $e) {
            // O AD responde só "Constraint violation" quando recusa a senha, sem
            // dizer o motivo. Se chegou aqui, a validação local passou e a regra
            // violada é do domínio — normalmente o histórico de senhas.
            if (stripos($e->getMessage(), 'Constraint violation') !== false) {
                throw new RuntimeException(
                    'O domínio recusou a senha. Ela atende às regras básicas, então o motivo mais provável é '
                    . 'o histórico: o Active Directory guarda as últimas senhas da conta e não aceita repetir '
                    . 'nenhuma delas. Tente uma senha que essa conta nunca tenha usado.',
                    0,
                    $e
                );
            }
            throw $e;
        }

        $this->ldap->modify($dn, [
            'pwdLastSet' => $forceChangeOnLogin ? '0' : '-1',
        ]);
    }

    /**
     * Remove o usuário do diretório. Irreversível: o Active Directory só
     * guarda objetos excluídos se a lixeira estiver habilitada, o que não é o
     * padrão no Samba. O SID se perde junto, então recriar a conta com o mesmo
     * nome não recupera permissões, participação em grupos nem acesso a
     * arquivos. Desativar costuma ser a operação certa.
     */
    public function delete(string $dn): void
    {
        $ou = $this->ldap->opcao('default_user_ou');

        // Fora da OU delegada o diretório recusaria com "Insufficient access",
        // mensagem que não explica nada a quem está na tela. Contas
        // administrativas e de sistema ficam propositalmente fora do alcance.
        if (is_string($ou) && $ou !== '' && !str_ends_with(mb_strtolower($dn), mb_strtolower($ou))) {
            throw new RuntimeException(
                'Esta conta está fora da unidade organizacional gerenciada por esta ferramenta e não pode '
                . 'ser removida por aqui. Contas administrativas e de sistema ficam de fora de propósito.'
            );
        }

        $this->ldap->delete($dn);
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
