<?php

declare(strict_types=1);

namespace App\Ldap;

/**
 * Validação da senha antes de mandá-la ao diretório.
 *
 * O AD/Samba responde a uma senha recusada apenas com "Constraint violation",
 * sem dizer qual regra falhou — mensagem inútil para quem está na tela. Pior:
 * na criação de usuário a recusa acontece depois que a conta já foi criada,
 * deixando-a desativada pelo caminho. Validar aqui evita os dois problemas.
 *
 * As regras espelham a política padrão do Active Directory:
 *  - comprimento mínimo (configurável no domínio; 7 é o padrão);
 *  - complexidade: ao menos 3 das 4 categorias (maiúscula, minúscula,
 *    dígito, símbolo);
 *  - a senha não pode conter o login nem partes do nome do usuário.
 *
 * A política real vive no domínio e pode ser consultada com
 * `samba-tool domain passwordsettings show`. Se a do seu domínio for mais
 * exigente, ajuste 'password_min_length' no config.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH_PADRAO = 7;
    private const CATEGORIAS_EXIGIDAS = 3;

    /**
     * @return array<int, string> Lista de problemas; vazia se a senha serve.
     */
    public static function validar(string $senha, string $login = '', string $nomeCompleto = ''): array
    {
        $problemas = [];
        $minimo = self::comprimentoMinimo();

        if (mb_strlen($senha) < $minimo) {
            $problemas[] = "precisa ter pelo menos {$minimo} caracteres";
        }

        $categorias = self::contarCategorias($senha);
        if ($categorias < self::CATEGORIAS_EXIGIDAS) {
            $problemas[] = 'precisa combinar pelo menos ' . self::CATEGORIAS_EXIGIDAS
                . ' destes quatro tipos: letra maiúscula, letra minúscula, número e símbolo'
                . " (a senha informada tem {$categorias})";
        }

        if ($login !== '' && mb_stripos($senha, $login) !== false) {
            $problemas[] = 'não pode conter o nome de usuário';
        }

        // O AD rejeita a senha que contenha qualquer parte do nome com 3+ letras.
        foreach (preg_split('/[\s,.\-_]+/', $nomeCompleto) ?: [] as $parte) {
            if (mb_strlen($parte) >= 3 && mb_stripos($senha, $parte) !== false) {
                $problemas[] = "não pode conter partes do nome do usuário (\"{$parte}\")";
                break;
            }
        }

        return $problemas;
    }

    /**
     * Mensagem pronta para a tela, juntando os problemas encontrados.
     */
    public static function mensagemDeErro(array $problemas): string
    {
        if ($problemas === []) {
            return '';
        }

        return 'A senha não atende à política do domínio: ' . implode('; ', $problemas) . '.';
    }

    /**
     * Texto curto para orientar quem está preenchendo o formulário.
     */
    public static function descricao(): string
    {
        return 'Mínimo de ' . self::comprimentoMinimo() . ' caracteres, combinando pelo menos '
            . self::CATEGORIAS_EXIGIDAS . ' tipos entre maiúscula, minúscula, número e símbolo. '
            . 'Não pode conter o nome do usuário.';
    }

    public static function comprimentoMinimo(): int
    {
        $config = \App\Config::get('ldap.password_min_length');

        return is_int($config) && $config > 0 ? $config : self::MIN_LENGTH_PADRAO;
    }

    private static function contarCategorias(string $senha): int
    {
        $categorias = 0;
        $categorias += preg_match('/\p{Lu}/u', $senha) ? 1 : 0;   // maiúscula
        $categorias += preg_match('/\p{Ll}/u', $senha) ? 1 : 0;   // minúscula
        $categorias += preg_match('/\d/u', $senha) ? 1 : 0;       // dígito
        $categorias += preg_match('/[^\p{L}\d]/u', $senha) ? 1 : 0; // símbolo

        return $categorias;
    }
}
