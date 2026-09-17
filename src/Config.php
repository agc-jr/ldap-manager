<?php

declare(strict_types=1);

namespace App;

/**
 * Carregador simples de configuração. Lê config/config.php (fora do git)
 * e falha de forma clara se o arquivo ainda não existir.
 */
final class Config
{
    private static ?array $data = null;

    /**
     * Existe configuração gravada? O instalador precisa saber disso sem
     * provocar a exceção de load().
     */
    public static function existe(): bool
    {
        return is_file(dirname(__DIR__) . '/config/config.php');
    }

    /**
     * Descarta o que já foi lido. Necessário logo após o instalador gravar o
     * config.php, para que a mesma requisição passe a enxergá-lo.
     */
    public static function recarregar(): void
    {
        self::$data = null;
    }

    public static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = dirname(__DIR__) . '/config/config.php';

        if (!is_file($path)) {
            throw new \RuntimeException(
                "Arquivo de configuração não encontrado em {$path}. " .
                "Copie config/config.example.php para config/config.php e preencha os valores."
            );
        }

        self::$data = require $path;

        return self::$data;
    }

    public static function get(string $dotPath, mixed $default = null): mixed
    {
        $data = self::load();
        $segments = explode('.', $dotPath);

        foreach ($segments as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }
            $data = $data[$segment];
        }

        return $data;
    }
}
