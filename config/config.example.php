<?php
/**
 * Modelo de config/config.php.
 *
 * Normalmente você NÃO precisa criar este arquivo à mão: o instalador
 * (public/install.php) o gera na primeira etapa. Ele só é útil quando o
 * diretório config/ não tem permissão de escrita para o servidor web — nesse
 * caso o instalador mostra o conteúdo pronto para você colar aqui.
 *
 * Note o que NÃO está aqui: os domínios LDAP. Eles ficam cadastrados no banco
 * e são gerenciados pela tela "Domínios", para que acrescentar um domínio não
 * exija editar PHP no servidor. A conexão com o banco precisa ficar em
 * arquivo pelo motivo óbvio: é ela que permite chegar ao banco.
 *
 * config/config.php NUNCA deve ser versionado (veja .gitignore).
 */
return [

    // ---------------------------------------------------------------
    // Banco de dados da ferramenta (operadores, domínios e auditoria).
    // Use um banco dedicado, separado de outras aplicações do servidor.
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
        'name' => 'AD Manager Web',

        // Chave que cifra as senhas das contas de serviço guardadas no banco.
        // 64 caracteres hexadecimais. Gere com:
        //   php -r "echo bin2hex(random_bytes(32));"
        //
        // ATENÇÃO: se esta chave se perder ou mudar, as senhas dos domínios já
        // cadastrados tornam-se ilegíveis e precisarão ser digitadas de novo.
        // Guarde este arquivo junto com os backups do banco.
        'app_key' => 'TROQUE_POR_UMA_CHAVE_ALEATORIA_DE_64_HEX',

        // Minutos de inatividade até a sessão expirar
        'session_timeout_minutes' => 30,

        // Faixas de IP autorizadas a acessar a ferramenta (defesa em
        // profundidade, além do bloqueio feito no servidor web).
        // Deixe vazio para não filtrar aqui.
        'allowed_ip_ranges' => [
            // '10.0.0.0/8',
        ],
    ],
];
