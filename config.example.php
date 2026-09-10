<?php

/**
 * Fallback configuration for shared hosting without environment-variable support.
 *
 * 1. Copy this file to config.local.php (same directory, next to index.php).
 * 2. Fill in real values.
 * 3. NEVER commit config.local.php — it is already listed in .gitignore.
 *
 * Real environment variables (getenv), if present, always take priority
 * over this file — see src/Config.php.
 */

return [
    'SMTP_HOST' => 'smtp.example.com',
    'SMTP_PORT' => '587',
    'SMTP_SECURE' => 'false',
    'SMTP_USER' => 'noreply@example.com',
    'SMTP_PASSWORD' => 'your-smtp-password',

    'FROM_EMAIL' => 'noreply@example.com',
    'FROM_NAME' => 'Your App',

    'MAIL_RELAY_SECRET' => 'your-long-random-secret',
];
