<?php

declare(strict_types=1);

namespace App\Ldap;

/**
 * Flags mais comuns do atributo userAccountControl do Active Directory.
 * Referência: https://learn.microsoft.com/pt-br/troubleshoot/windows-server/identity/useraccountcontrol-manipulate-account-properties
 */
final class UserAccountControl
{
    public const NORMAL_ACCOUNT = 512;
    public const ACCOUNTDISABLE = 2;
    public const DONT_EXPIRE_PASSWORD = 65536;

    public static function isDisabled(int $uac): bool
    {
        return ($uac & self::ACCOUNTDISABLE) === self::ACCOUNTDISABLE;
    }

    public static function withDisabled(int $uac, bool $disabled): int
    {
        return $disabled
            ? ($uac | self::ACCOUNTDISABLE)
            : ($uac & ~self::ACCOUNTDISABLE);
    }
}
