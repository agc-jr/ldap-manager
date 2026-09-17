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
