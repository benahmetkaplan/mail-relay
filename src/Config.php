<?php

declare(strict_types=1);

namespace MailRelay;

final class Config
{
    private static ?array $values = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $v = self::$values[$key] ?? $default;
        return $v === null ? null : (string) $v;
    }

    public static function require(string $key): string
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            throw new \RuntimeException("Missing required configuration: {$key}");
        }
        return $v;
    }

    private static function load(): void
    {
        if (self::$values !== null) {
            return;
        }

        $values = [];

        // 0. .env file (project root), loaded without overwriting real env vars.
        //    Never committed to source control.
        $rootDir = dirname(__DIR__);
        if (is_file($rootDir . '/.env')) {
            // "Unsafe" here just means it also calls putenv(), so getenv() below sees it.
            \Dotenv\Dotenv::createUnsafeImmutable($rootDir)->safeLoad();
        }

        // 1. Real environment variables (works on most modern shared hosting / getenv),
        //    including any loaded from .env above.
        foreach (self::envKeys() as $key) {
            $v = getenv($key);
            if ($v !== false && $v !== '') {
                $values[$key] = $v;
            }
        }

        // 2. Local config file OUTSIDE the web root, for hosts without env var support.
        //    config.local.php must return an array and is NEVER committed to source control.
        $localFile = dirname(__DIR__) . '/config.local.php';
        if (is_file($localFile)) {
            /** @var mixed $local */
            $local = require $localFile;
            if (is_array($local)) {
                foreach ($local as $key => $value) {
                    if (!isset($values[$key]) && $value !== null && $value !== '') {
                        $values[$key] = (string) $value;
                    }
                }
            }
        }

        self::$values = $values;
    }

    private static function envKeys(): array
    {
        return [
            'SMTP_HOST',
            'SMTP_PORT',
            'SMTP_SECURE',
            'SMTP_USER',
            'SMTP_PASSWORD',
            'FROM_EMAIL',
            'FROM_NAME',
            'MAIL_RELAY_SECRET',
            'RATE_LIMIT_MAX',
            'RATE_LIMIT_WINDOW',
        ];
    }
}
