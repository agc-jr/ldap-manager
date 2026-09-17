-- Schema do banco local da ferramenta (usuários da ferramenta + auditoria)
-- Este banco é independente do AD: guarda apenas quem PODE acessar a ferramenta
-- e o histórico do que foi feito através dela.

CREATE DATABASE IF NOT EXISTS ldap_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ldap_manager;

-- Usuários que podem logar na ferramenta (não confundir com usuários do AD)
CREATE TABLE IF NOT EXISTS app_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(100) NOT NULL UNIQUE,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin', 'operator') NOT NULL DEFAULT 'operator',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Log de auditoria: toda ação relevante feita através da ferramenta
CREATE TABLE IF NOT EXISTS audit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_user_id     INT UNSIGNED NULL,
    app_username    VARCHAR(100) NOT NULL,     -- guardado também em texto, sobrevive à exclusão do app_user
    action          VARCHAR(60) NOT NULL,      -- ex: user.create, user.disable, group.add_member
    target_type     VARCHAR(40) NOT NULL,      -- ex: ldap_user, ldap_group
    target_id       VARCHAR(255) NOT NULL,     -- sAMAccountName ou DN do alvo
    -- Em qual domínio a ação aconteceu. Guardado também pelo nome, em texto,
    -- para o registro continuar legível se o domínio for removido do cadastro.
    ldap_domain_id  INT UNSIGNED NULL,
    ldap_domain     VARCHAR(100) NULL,
    details         JSON NULL,                 -- payload livre (antes/depois, atributos alterados)
    ip_address      VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (app_user_id) REFERENCES app_users(id) ON DELETE SET NULL,
    INDEX idx_audit_created_at (created_at),
    INDEX idx_audit_target (target_type, target_id),
    INDEX idx_audit_domain (ldap_domain_id)
) ENGINE=InnoDB;

-- Domínios LDAP/AD gerenciados pela ferramenta.
-- Ficam no banco (e não em arquivo) para que novos domínios possam ser
-- cadastrados pela interface, sem editar PHP no servidor.
CREATE TABLE IF NOT EXISTS ldap_domains (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(100) NOT NULL UNIQUE,  -- rótulo exibido na interface, ex: "Administrativo"
    host             VARCHAR(255) NOT NULL,         -- ex: ldaps://10.0.0.10
    port             SMALLINT UNSIGNED NOT NULL DEFAULT 636,
    tls_verify       TINYINT(1) NOT NULL DEFAULT 0, -- 0 em ambientes com certificado autoassinado
    base_dn          VARCHAR(255) NOT NULL,         -- ex: DC=exemplo,DC=local
    bind_dn          VARCHAR(255) NOT NULL,         -- conta de serviço, formato UPN
    -- Cifrada com AES-256-GCM; a chave vive em config/config.php, nunca aqui.
    bind_password    TEXT NOT NULL,
    domain_upn       VARCHAR(255) NOT NULL,         -- ex: exemplo.local
    domain_netbios   VARCHAR(100) NULL,             -- ex: EXEMPLO
    default_user_ou  VARCHAR(255) NULL,             -- onde novos usuários são criados
    default_group_ou VARCHAR(255) NULL,
    password_min_length TINYINT UNSIGNED NOT NULL DEFAULT 7,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Quais domínios cada operador enxerga. Admin vê todos e não precisa de linha
-- aqui; a tabela existe para o caso que motivou o projeto: um estagiário
-- cuidando de um domínio sem alcançar os demais.
CREATE TABLE IF NOT EXISTS app_user_domains (
    app_user_id    INT UNSIGNED NOT NULL,
    ldap_domain_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (app_user_id, ldap_domain_id),
    FOREIGN KEY (app_user_id) REFERENCES app_users(id) ON DELETE CASCADE,
    FOREIGN KEY (ldap_domain_id) REFERENCES ldap_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Sessões ativas (opcional, permite "derrubar" sessões de um usuário da ferramenta)
CREATE TABLE IF NOT EXISTS app_sessions (
    id              VARCHAR(128) PRIMARY KEY,
    app_user_id     INT UNSIGNED NOT NULL,
    ip_address      VARCHAR(45) NULL,
    user_agent      VARCHAR(255) NULL,
    last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (app_user_id) REFERENCES app_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Usuário admin inicial de exemplo (senha: "TROQUE_NO_PRIMEIRO_ACESSO", já em hash bcrypt)
-- Gere seu próprio hash com: php -r "echo password_hash('sua_senha', PASSWORD_BCRYPT);"
-- INSERT INTO app_users (username, full_name, password_hash, role, must_change_password)
-- VALUES ('admin', 'Administrador', '$2y$10$SUBSTITUA_PELO_SEU_HASH_AQUI', 'admin', 1);
