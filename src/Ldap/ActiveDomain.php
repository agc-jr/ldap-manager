<?php

declare(strict_types=1);

namespace App\Ldap;

use App\Auth;
use RuntimeException;

/**
 * Resolve com qual domínio o usuário está trabalhando agora.
 *
 * A escolha vive na sessão, mas nunca é aceita sem conferir: a cada requisição
 * o domínio guardado é revalidado contra as permissões atuais. Sem isso, um
 * operador que perdeu acesso a um domínio continuaria operando nele enquanto a
 * sessão durasse.
 */
final class ActiveDomain
{
    private const CHAVE_SESSAO = 'ldap_domain_id';

    /**
     * Domínios que o usuário logado pode operar.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function disponiveis(): array
    {
        $u = Auth::user();

        return (new DomainRepository())->paraUsuario((int) $u['id'], Auth::isAdmin());
    }

    /**
     * Domínio em uso. Cai no primeiro disponível quando não há escolha feita,
     * ou quando a escolha anterior deixou de valer.
     *
     * @return array<string, mixed>|null null se o usuário não tem nenhum domínio
     */
    public static function atual(): ?array
    {
        $disponiveis = self::disponiveis();
        if ($disponiveis === []) {
            return null;
        }

        $escolhido = $_SESSION[self::CHAVE_SESSAO] ?? null;

        if ($escolhido !== null) {
            foreach ($disponiveis as $d) {
                if ($d['id'] === (int) $escolhido) {
                    // Renova o nome guardado: ele pode ter sido editado.
                    $_SESSION['ldap_domain_nome'] = $d['name'] ?? null;

                    return $d;
                }
            }
            // A escolha guardada não vale mais (domínio removido, desativado ou
            // acesso revogado): silenciosamente volta para o primeiro válido.
        }

        $primeiro = $disponiveis[0];
        self::lembrar($primeiro);

        return $primeiro;
    }

    /**
     * Guarda id e nome na sessão. O nome fica junto para a auditoria poder
     * registrar em qual domínio a ação ocorreu sem consultar o banco de novo a
     * cada evento.
     *
     * @param array<string, mixed> $dominio
     */
    private static function lembrar(array $dominio): void
    {
        $_SESSION[self::CHAVE_SESSAO] = $dominio['id'];
        $_SESSION['ldap_domain_nome'] = $dominio['name'] ?? null;
    }

    /**
     * @return array{0: int|null, 1: string|null} id e nome do domínio em uso
     */
    public static function paraAuditoria(): array
    {
        return [
            isset($_SESSION[self::CHAVE_SESSAO]) ? (int) $_SESSION[self::CHAVE_SESSAO] : null,
            $_SESSION['ldap_domain_nome'] ?? null,
        ];
    }

    /**
     * Troca o domínio ativo, recusando id que o usuário não possa operar.
     */
    public static function escolher(int $domainId): bool
    {
        $u = Auth::user();

        if (!(new DomainRepository())->usuarioPodeUsar((int) $u['id'], Auth::isAdmin(), $domainId)) {
            return false;
        }

        $dominio = (new DomainRepository())->encontrar($domainId);
        if ($dominio === null) {
            return false;
        }

        self::lembrar($dominio);

        return true;
    }

    /**
     * Há algum domínio que este usuário possa operar? Usado pelas telas para
     * distinguir "não tenho acesso" de "a conexão falhou" — são problemas
     * diferentes e levam a ações diferentes.
     */
    public static function temAlgum(): bool
    {
        try {
            return self::atual() !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Abre a conexão com o domínio ativo.
     */
    public static function conectar(): LdapConnection
    {
        $dominio = self::atual();

        if ($dominio === null) {
            throw new RuntimeException(
                Auth::isAdmin()
                    ? 'Nenhum domínio LDAP cadastrado. Cadastre um em "Domínios" para começar.'
                    : 'Você não tem acesso a nenhum domínio. Peça a um administrador da ferramenta para liberar.'
            );
        }

        return new LdapConnection($dominio);
    }
}
