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
    details         JSON NULL,                 -- payload livre (antes/depois, atributos alterados)
    ip_address      VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (app_user_id) REFERENCES app_users(id) ON DELETE SET NULL,
    INDEX idx_audit_created_at (created_at),
    INDEX idx_audit_target (target_type, target_id)
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
