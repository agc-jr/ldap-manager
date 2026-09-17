<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Apoio à instalação pela web.
 *
 * A configuração do banco não pode morar no banco — é preciso conectar antes
 * de ler qualquer coisa. Por isso o instalador **gera** config/config.php, do
 * mesmo jeito que o WordPress gera o wp-config.php. Nesse arquivo ficam apenas
 * a conexão e a chave da aplicação; os domínios LDAP vão para o banco.
 */
final class Installer
{
    public static function caminhoConfig(): string
    {
        return dirname(__DIR__) . '/config/config.php';
    }

    /**
     * A instalação está concluída quando existe config E existe ao menos um
     * administrador. Só o arquivo não basta: se o processo parar no meio, o
     * instalador precisa poder continuar de onde estava.
     */
    public static function instalado(): bool
    {
        if (!is_file(self::caminhoConfig())) {
            return false;
        }

        try {
            $pdo = Database::connection();
            $existe = $pdo->query("SELECT 1 FROM app_users WHERE role = 'admin' AND is_active = 1 LIMIT 1")->fetchColumn();

            return $existe !== false;
        } catch (\Throwable $e) {
            // Config aponta para um banco inacessível ou sem as tabelas: a
            // instalação não está completa.
            return false;
        }
    }

    /**
     * Testa a conexão antes de gravar qualquer coisa, para o erro aparecer no
     * formulário e não numa tela branca depois.
     *
     * @return array{ok: bool, erro: string, servidor: string}
     */
    public static function testarBanco(string $host, int $porta, string $banco, string $usuario, string $senha): array
    {
        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$porta};charset=utf8mb4",
                $usuario,
                $senha,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );

            $versao = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

            // O banco pode ainda não existir: o instalador o cria adiante, desde
            // que o usuário informado tenha permissão para isso.
            $existe = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $existe->execute([$banco]);

            if ($existe->fetchColumn() === false) {
                try {
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$banco}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                } catch (PDOException $e) {
                    return [
                        'ok' => false,
                        'erro' => "O banco \"{$banco}\" não existe e o usuário informado não tem permissão para criá-lo. "
                            . 'Crie o banco manualmente ou use um usuário com permissão de CREATE.',
                        'servidor' => $versao,
                    ];
                }
            }

            return ['ok' => true, 'erro' => '', 'servidor' => $versao];
        } catch (PDOException $e) {
            return ['ok' => false, 'erro' => self::traduzirErroDeBanco($e), 'servidor' => ''];
        }
    }

    /**
     * Cria as tabelas a partir de sql/schema.sql.
     */
    public static function criarTabelas(string $host, int $porta, string $banco, string $usuario, string $senha): void
    {
        $sql = file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Não foi possível ler sql/schema.sql.');
        }

        // O arquivo cria e seleciona o banco pelo nome padrão; aqui o nome vem
        // do formulário, então essas duas linhas saem.
        $sql = preg_replace('/^\s*CREATE DATABASE .*?;\s*$/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*USE .*?;\s*$/mi', '', $sql) ?? $sql;

        $pdo = new PDO(
            "mysql:host={$host};port={$porta};dbname={$banco};charset=utf8mb4",
            $usuario,
            $senha,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec($sql);
    }

    /**
     * Grava config/config.php. A app_key é gerada aqui e nunca muda depois:
     * ela decifra as senhas dos domínios guardadas no banco.
     */
    public static function gravarConfig(array $db, string $appKey): void
    {
        $caminho = self::caminhoConfig();
        $diretorio = dirname($caminho);

        if (!is_dir($diretorio) && !@mkdir($diretorio, 0755, true)) {
            throw new RuntimeException("Não foi possível criar o diretório {$diretorio}.");
        }

        if (!is_writable($diretorio)) {
            throw new RuntimeException(
                "O diretório {$diretorio} não tem permissão de escrita. Ajuste as permissões e tente de novo, "
                . 'ou crie config/config.php manualmente a partir de config/config.example.php.'
            );
        }

        $conteudo = self::montarConfig($db, $appKey);

        if (@file_put_contents($caminho, $conteudo) === false) {
            throw new RuntimeException("Não foi possível gravar {$caminho}.");
        }

        @chmod($caminho, 0640);
    }

    /**
     * Conteúdo do config.php, também oferecido para colar à mão quando o
     * diretório não é gravável pelo servidor web.
     */
    public static function montarConfig(array $db, string $appKey): string
    {
        $v = static fn (string $s): string => var_export($s, true);

        return <<<PHP
        <?php
        /**
         * Gerado pelo instalador do AD Manager Web.
         *
         * Guarda apenas a conexão com o banco e a chave da aplicação: os
         * domínios LDAP ficam cadastrados no próprio banco, pela interface.
         *
         * NÃO versione este arquivo e NÃO perca a app_key — é ela que decifra
         * as senhas das contas de serviço guardadas no banco. Se um backup do
         * banco for restaurado com outra app_key, os domínios precisarão ser
         * recadastrados.
         */
        return [
            'db' => [
                'host'     => {$v($db['host'])},
                'port'     => {$db['port']},
                'database' => {$v($db['database'])},
                'username' => {$v($db['username'])},
                'password' => {$v($db['password'])},
                'charset'  => 'utf8mb4',
            ],

            'app' => [
                'name'     => 'AD Manager Web',
                'app_key'  => {$v($appKey)},
                'session_timeout_minutes' => 30,
                // Faixas de IP autorizadas (defesa extra, além do bloqueio no
                // servidor web). Vazio = não filtra aqui.
                'allowed_ip_ranges' => [],
            ],
        ];

        PHP;
    }

    public static function criarAdministrador(string $usuario, string $senha, string $nomeCompleto): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO app_users (username, full_name, password_hash, role, must_change_password)
             VALUES (:u, :f, :p, "admin", 0)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = "admin", is_active = 1'
        );

        $stmt->execute([
            'u' => $usuario,
            'f' => $nomeCompleto !== '' ? $nomeCompleto : $usuario,
            'p' => password_hash($senha, PASSWORD_BCRYPT),
        ]);
    }

    /**
     * Mensagens do PDO são técnicas demais para quem está instalando.
     */
    private static function traduzirErroDeBanco(PDOException $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'Access denied')) {
            return 'Usuário ou senha do banco incorretos.';
        }
        if (str_contains($msg, 'Unknown database')) {
            return 'O banco informado não existe e não pôde ser criado.';
        }
        if (str_contains($msg, 'Connection refused') || str_contains($msg, "Can't connect")) {
            return 'Não foi possível conectar ao servidor de banco no endereço e porta informados. '
                . 'Verifique se o serviço está no ar e se aceita conexões desta máquina.';
        }
        if (str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Unknown MySQL server host')) {
            return 'O endereço do servidor de banco não foi encontrado.';
        }

        return 'Falha ao conectar: ' . $msg;
    }
}
