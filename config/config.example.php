<?php
/**
 * Copie este arquivo para config/config.php e preencha com os valores reais
 * do seu ambiente. config/config.php NUNCA deve ser versionado (veja .gitignore).
 */
return [

    // ---------------------------------------------------------------
    // Conexão com o Active Directory / Samba4 AD DC
    // ---------------------------------------------------------------
    'ldap' => [
        // Host do controlador de domínio (Samba AD DC, Windows AD, etc.)
        'host' => 'ldaps://127.0.0.1',

        // 636 = LDAPS (obrigatório para escrita de senha / unicodePwd)
        // 389 = LDAP sem criptografia (uso apenas para leitura, se necessário)
        'port' => 636,

        // Em ambientes internos com certificado autoassinado/expirado,
        // normalmente é necessário desabilitar a validação estrita do certificado.
        // Isso é feito via /etc/ldap/ldap.conf no servidor (TLS_REQCERT never)
        // ou setando LDAPTLS_REQCERT=never no ambiente do PHP-FPM/Apache.
        'tls_verify' => false,

        // Conta de serviço com permissão de escrita em usuários/grupos.
        // Formato UPN é o mais confiável com Samba AD: usuario@dominio
        'bind_dn'       => 'svc-ldapmanager@exemplo.local',
        'bind_password' => 'TROQUE_ESTA_SENHA',

        // Base DN do domínio (ajuste para o seu domínio)
        'base_dn' => 'DC=exemplo,DC=local',

        // OU padrão para criação de novos usuários (opcional).
        // Se null, usa o container padrão "CN=Users,<base_dn>"
        'default_user_ou' => null,

        // Nome de domínio NetBIOS/UPN usado para sugerir o login (ex: EXEMPLO\\usuario)
        'domain_netbios' => 'EXEMPLO',
        'domain_upn'     => 'exemplo.local',
    ],

    // ---------------------------------------------------------------
    // Banco de dados local (usuários da própria ferramenta + auditoria)
    // Recomenda-se um banco dedicado, separado dos bancos do WordPress.
    // ---------------------------------------------------------------
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'ldap_manager',
        'username' => 'ldap_manager',
        'password' => 'TROQUE_ESTA_SENHA',
        'charset'  => 'utf8mb4',
    ],

    // ---------------------------------------------------------------
    // Aplicação
    // ---------------------------------------------------------------
    'app' => [
        'name'          => 'AD Manager Web',
        // Gere uma chave aleatória: php -r "echo bin2hex(random_bytes(32));"
        'session_secret' => 'TROQUE_ESTA_CHAVE_ALEATORIA',
        // Minutos de inatividade até expirar a sessão
        'session_timeout_minutes' => 30,
        // Faixas de IP autorizadas a acessar a ferramenta (defesa em profundidade,
        // além do bloqueio feito no VirtualHost do Apache). Deixe vazio para não filtrar aqui.
        'allowed_ip_ranges' => [
            // '10.0.0.0/8',
        ],
    ],
];
