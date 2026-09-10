<?php

declare(strict_types=1);

// Never leak errors/stack traces to the HTTP response.
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/vendor/autoload.php';

use MailRelay\MailRelay;

// Catch any fatal error that escapes the application (e.g. misconfiguration)
// and still return a generic, sanitized JSON error instead of an HTML trace.
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'Internal server error']);
    }
});

$storageDir = __DIR__ . '/storage';

$relay = new MailRelay($storageDir);
$result = $relay->handle();

http_response_code($result->status);
echo json_encode($result->body);
