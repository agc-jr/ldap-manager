<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Cifra os segredos que ficam no banco — hoje, a senha do bind LDAP.
 *
 * A chave não mora no banco: ela fica em config/config.php, gerado pelo
 * instalador e mantido fora da raiz web. Assim, obter só o banco (backup
 * vazado, phpMyAdmin exposto, SQL injection) não basta para ler as senhas,
 * nem obter só o arquivo.
 *
 * Usa AES-256-GCM, que além de cifrar autentica: adulterar o texto cifrado
 * faz a decifragem falhar em vez de devolver lixo silenciosamente.
 *
 * ATENÇÃO: perder a app_key torna as senhas guardadas ilegíveis para sempre —
 * os domínios precisam ser recadastrados. Quem faz backup do banco deve
 * guardar o config.php junto.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIXO = 'enc:v1:';

    public static function encrypt(string $textoPuro): string
    {
        if ($textoPuro === '') {
            return '';
        }

        $chave = self::chave();
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER) ?: 12);
        $tag = '';

        $cifrado = openssl_encrypt($textoPuro, self::CIPHER, $chave, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cifrado === false) {
            throw new RuntimeException('Não foi possível cifrar o valor.');
        }

        // prefixo + iv + tag + conteúdo, tudo em base64 para caber num campo texto
        return self::PREFIXO . base64_encode($iv . $tag . $cifrado);
    }

    public static function decrypt(string $valorGuardado): string
    {
        if ($valorGuardado === '') {
            return '';
        }

        // Tolera valor em texto puro: bancos criados antes da cifragem, ou uma
        // senha colocada à mão direto na tabela durante uma emergência.
        if (!str_starts_with($valorGuardado, self::PREFIXO)) {
            return $valorGuardado;
        }

        $bruto = base64_decode(substr($valorGuardado, strlen(self::PREFIXO)), true);
        if ($bruto === false) {
            throw new RuntimeException('Valor cifrado corrompido (base64 inválido).');
        }

        $tamanhoIv = openssl_cipher_iv_length(self::CIPHER) ?: 12;
        $iv = substr($bruto, 0, $tamanhoIv);
        $tag = substr($bruto, $tamanhoIv, 16);
        $cifrado = substr($bruto, $tamanhoIv + 16);

        $puro = openssl_decrypt($cifrado, self::CIPHER, self::chave(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($puro === false) {
            throw new RuntimeException(
                'Não foi possível decifrar o valor. A app_key em config/config.php provavelmente '
                . 'não é a mesma usada para gravá-lo — nesse caso, recadastre a senha do domínio.'
            );
        }

        return $puro;
    }

    /**
     * Indica se o valor já está cifrado, para não cifrar duas vezes ao salvar.
     */
    public static function estaCifrado(string $valor): bool
    {
        return str_starts_with($valor, self::PREFIXO);
    }

    public static function gerarChave(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function chave(): string
    {
        $chaveHex = Config::get('app.app_key');

        if (!is_string($chaveHex) || $chaveHex === '') {
            throw new RuntimeException(
                'A chave da aplicação (app.app_key) não está definida em config/config.php. '
                . 'Ela é necessária para ler as senhas guardadas no banco.'
            );
        }

        // A chave é guardada em hexadecimal; aqui voltamos aos 32 bytes.
        $binaria = @hex2bin($chaveHex);
        if ($binaria === false || strlen($binaria) !== 32) {
            throw new RuntimeException(
                'A app_key em config/config.php é inválida: deve ser uma sequência hexadecimal de 64 caracteres. '
                . 'Gere uma nova com: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        return $binaria;
    }
}
