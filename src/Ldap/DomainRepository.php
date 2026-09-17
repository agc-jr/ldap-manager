<?php

declare(strict_types=1);

namespace App\Ldap;

use App\Crypto;
use App\Database;
use PDO;
use RuntimeException;

/**
 * Domínios LDAP/AD cadastrados, guardados no banco.
 *
 * Antes essa configuração vivia em config/config.php, o que obrigava a editar
 * PHP no servidor para acrescentar um domínio. No banco, o cadastro é feito
 * pela interface — e a ferramenta deixa de estar presa a um único domínio.
 *
 * A senha do bind é cifrada na entrada e decifrada na saída, de modo que quem
 * usa esta classe nunca lida com a cifragem, e o texto puro não circula pelo
 * resto do sistema.
 */
final class DomainRepository
{
    private const CAMPOS = 'id, name, host, port, tls_verify, base_dn, bind_dn, bind_password,
                            domain_upn, domain_netbios, default_user_ou, default_group_ou,
                            password_min_length, is_active';

    /** @return array<int, array<string, mixed>> */
    public function todos(bool $apenasAtivos = true): array
    {
        $sql = 'SELECT ' . self::CAMPOS . ' FROM ldap_domains';
        if ($apenasAtivos) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name';

        $linhas = Database::connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'preparar'], $linhas);
    }

    /**
     * Domínios que um usuário da ferramenta pode operar. Admin vê todos;
     * operador vê apenas os vinculados a ele.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paraUsuario(int $appUserId, bool $ehAdmin): array
    {
        if ($ehAdmin) {
            return $this->todos();
        }

        $stmt = Database::connection()->prepare(
            'SELECT ' . self::CAMPOS . ' FROM ldap_domains d
             INNER JOIN app_user_domains ud ON ud.ldap_domain_id = d.id
             WHERE ud.app_user_id = :uid AND d.is_active = 1
             ORDER BY d.name'
        );
        $stmt->execute(['uid' => $appUserId]);

        return array_map([$this, 'preparar'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function encontrar(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::CAMPOS . ' FROM ldap_domains WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);

        return $linha ? $this->preparar($linha) : null;
    }

    /**
     * Verifica se o usuário pode operar o domínio, antes de qualquer conexão.
     */
    public function usuarioPodeUsar(int $appUserId, bool $ehAdmin, int $domainId): bool
    {
        if ($ehAdmin) {
            return $this->encontrar($domainId) !== null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM app_user_domains ud
             INNER JOIN ldap_domains d ON d.id = ud.ldap_domain_id
             WHERE ud.app_user_id = :uid AND ud.ldap_domain_id = :did AND d.is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['uid' => $appUserId, 'did' => $domainId]);

        return $stmt->fetchColumn() !== false;
    }

    public function criar(array $dados): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO ldap_domains
                (name, host, port, tls_verify, base_dn, bind_dn, bind_password, domain_upn,
                 domain_netbios, default_user_ou, default_group_ou, password_min_length, is_active)
             VALUES
                (:name, :host, :port, :tls_verify, :base_dn, :bind_dn, :bind_password, :domain_upn,
                 :domain_netbios, :default_user_ou, :default_group_ou, :password_min_length, :is_active)'
        );
        $stmt->execute($this->parametros($dados));

        return (int) $pdo->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $parametros = $this->parametros($dados);
        $parametros['id'] = $id;

        // Senha em branco na edição significa "manter a atual" — evita que o
        // formulário apague a senha só porque o campo não foi preenchido.
        $mantemSenha = ($dados['bind_password'] ?? '') === '';
        if ($mantemSenha) {
            unset($parametros['bind_password']);
        }

        $sql = 'UPDATE ldap_domains SET
                    name = :name, host = :host, port = :port, tls_verify = :tls_verify,
                    base_dn = :base_dn, bind_dn = :bind_dn, domain_upn = :domain_upn,
                    domain_netbios = :domain_netbios, default_user_ou = :default_user_ou,
                    default_group_ou = :default_group_ou,
                    password_min_length = :password_min_length, is_active = :is_active'
             . ($mantemSenha ? '' : ', bind_password = :bind_password')
             . ' WHERE id = :id';

        Database::connection()->prepare($sql)->execute($parametros);
    }

    public function remover(int $id): void
    {
        Database::connection()->prepare('DELETE FROM ldap_domains WHERE id = :id')->execute(['id' => $id]);
    }

    public function quantos(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM ldap_domains')->fetchColumn();
    }

    /**
     * Domínios liberados para um operador, por id.
     *
     * @return array<int, int>
     */
    public function idsDoUsuario(int $appUserId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ldap_domain_id FROM app_user_domains WHERE app_user_id = :uid'
        );
        $stmt->execute(['uid' => $appUserId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Substitui os vínculos de um operador pelos ids informados.
     *
     * @param array<int, int> $domainIds
     */
    public function definirDominiosDoUsuario(int $appUserId, array $domainIds): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM app_user_domains WHERE app_user_id = :uid')
                ->execute(['uid' => $appUserId]);

            $inserir = $pdo->prepare(
                'INSERT INTO app_user_domains (app_user_id, ldap_domain_id) VALUES (:uid, :did)'
            );
            foreach (array_unique(array_map('intval', $domainIds)) as $did) {
                $inserir->execute(['uid' => $appUserId, 'did' => $did]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Converte a linha do banco no formato que a camada LDAP espera, já com a
     * senha decifrada.
     */
    private function preparar(array $linha): array
    {
        $linha['id']         = (int) $linha['id'];
        $linha['port']       = (int) $linha['port'];
        $linha['tls_verify'] = (bool) $linha['tls_verify'];
        $linha['is_active']  = (bool) $linha['is_active'];
        $linha['password_min_length'] = (int) $linha['password_min_length'];
        $linha['bind_password'] = Crypto::decrypt((string) $linha['bind_password']);

        return $linha;
    }

    private function parametros(array $d): array
    {
        $senha = (string) ($d['bind_password'] ?? '');

        return [
            'name'                => trim((string) ($d['name'] ?? '')),
            'host'                => trim((string) ($d['host'] ?? '')),
            'port'                => (int) ($d['port'] ?? 636),
            'tls_verify'          => !empty($d['tls_verify']) ? 1 : 0,
            'base_dn'             => trim((string) ($d['base_dn'] ?? '')),
            'bind_dn'             => trim((string) ($d['bind_dn'] ?? '')),
            'bind_password'       => Crypto::estaCifrado($senha) ? $senha : Crypto::encrypt($senha),
            'domain_upn'          => trim((string) ($d['domain_upn'] ?? '')),
            'domain_netbios'      => trim((string) ($d['domain_netbios'] ?? '')) ?: null,
            'default_user_ou'     => trim((string) ($d['default_user_ou'] ?? '')) ?: null,
            'default_group_ou'    => trim((string) ($d['default_group_ou'] ?? '')) ?: null,
            'password_min_length' => max(1, (int) ($d['password_min_length'] ?? 7)),
            'is_active'           => isset($d['is_active']) && !$d['is_active'] ? 0 : 1,
        ];
    }

    /**
     * Valida o cadastro antes de gravar, para o erro aparecer no formulário e
     * não como falha de conexão depois.
     *
     * @return array<int, string>
     */
    public static function validar(array $d, bool $exigeSenha = true): array
    {
        $erros = [];

        foreach ([
            'name'       => 'Nome do domínio',
            'host'       => 'Endereço do servidor',
            'base_dn'    => 'Base DN',
            'bind_dn'    => 'Usuário de conexão (bind DN)',
            'domain_upn' => 'Domínio (UPN)',
        ] as $campo => $rotulo) {
            if (trim((string) ($d[$campo] ?? '')) === '') {
                $erros[] = "{$rotulo} é obrigatório.";
            }
        }

        if ($exigeSenha && trim((string) ($d['bind_password'] ?? '')) === '') {
            $erros[] = 'Senha do usuário de conexão é obrigatória.';
        }

        $host = trim((string) ($d['host'] ?? ''));
        if ($host !== '' && !preg_match('#^ldaps?://#i', $host)) {
            $erros[] = 'O endereço do servidor deve começar com ldaps:// ou ldap://.';
        }

        $porta = (int) ($d['port'] ?? 0);
        if ($porta < 1 || $porta > 65535) {
            $erros[] = 'Porta inválida.';
        }

        // Escrita de senha no AD exige canal cifrado; avisar aqui evita que o
        // operador descubra isso só quando um reset falhar.
        if (stripos($host, 'ldap://') === 0) {
            $erros[] = 'Com ldap:// (sem TLS) o Active Directory recusa definir e trocar senhas. '
                . 'Use ldaps:// na porta 636.';
        }

        return $erros;
    }
}
