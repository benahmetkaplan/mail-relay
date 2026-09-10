<?php

declare(strict_types=1);

namespace MailRelay;

final class Validator
{
    /**
     * Reject any value containing CR or LF (header/injection protection).
     */
    public static function hasLineBreak(string $value): bool
    {
        return (bool) preg_match('/[\r\n]/', $value);
    }

    public static function isValidEmail(string $email): bool
    {
        if (self::hasLineBreak($email)) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
