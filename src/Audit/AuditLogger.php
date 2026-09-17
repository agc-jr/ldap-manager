<?php

declare(strict_types=1);

namespace App\Audit;

use App\Database;

/**
 * Registra toda ação relevante feita através da ferramenta.
 * Chamado explicitamente por cada operação de escrita (criar usuário,
 * resetar senha, adicionar/remover de grupo, ativar/desativar, etc).
 */
final class AuditLogger
{
    public static function log(
        ?int $appUserId,
        string $appUsername,
        string $action,
        string $targetType,
        string $targetId,
        array $details = []
    ): void {
        $pdo = Database::connection();

        // O domínio vem da sessão, e não como parâmetro: assim nenhuma chamada
        // existente precisa mudar, e nenhuma corre o risco de esquecer de
        // informá-lo. Com vários domínios cadastrados, um registro sem essa
        // informação seria ambíguo.
        [$domainId, $domainNome] = \App\Ldap\ActiveDomain::paraAuditoria();

        $stmt = $pdo->prepare(
            'INSERT INTO audit_log
                (app_user_id, app_username, action, target_type, target_id, ldap_domain_id, ldap_domain, details, ip_address)
             VALUES
                (:app_user_id, :app_username, :action, :target_type, :target_id, :ldap_domain_id, :ldap_domain, :details, :ip_address)'
        );

        $stmt->execute([
            'app_user_id'    => $appUserId,
            'app_username'   => $appUsername,
            'action'         => $action,
            'target_type'    => $targetType,
            'target_id'      => $targetId,
            'ldap_domain_id' => $domainId,
            'ldap_domain'    => $domainNome,
            'details'        => json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    public static function recent(int $limit = 20): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT * FROM audit_log ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
