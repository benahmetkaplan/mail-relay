<?php

declare(strict_types=1);

namespace MailRelay;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Result of a relay attempt, safe to serialize directly to the HTTP caller.
 */
final class RelayResult
{
    public function __construct(
        public readonly int $status,
        public readonly array $body
    ) {
    }
}

final class MailRelay
{
    private const MAX_BODY_BYTES = 200 * 1024; // 200 KB — generous for transactional email HTML.
    private const ALLOWED_EXTRA_HEADERS = ['In-Reply-To', 'References'];
    private const SMTP_TIMEOUT_SECONDS = 15;
    private const DEFAULT_RATE_LIMIT_MAX = 60;
    private const DEFAULT_RATE_LIMIT_WINDOW = 60;

    public function __construct(
        private readonly string $storageDir
    ) {
    }

    public function handle(): RelayResult
    {
        try {
            $this->enforceMethod();
            $this->enforceHttps();
            $this->enforceRateLimit();
            $this->enforceAuth();

            $payload = $this->parseBody();
            $this->sendMail($payload);

            return new RelayResult(200, ['ok' => true, 'messageId' => $this->lastMessageId ?? '']);
        } catch (HttpError $e) {
            if ($e->logMessage !== null) {
                $this->logError($e->logMessage);
            }
            return new RelayResult($e->status, ['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logError('Unhandled error: ' . $e->getMessage());
            return new RelayResult(502, ['ok' => false, 'error' => 'Email delivery failed']);
        }
    }

    private ?string $lastMessageId = null;

    private function enforceMethod(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method !== 'POST') {
            throw new HttpError(405, 'Method Not Allowed');
        }
    }

    private function enforceHttps(): void
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $forwardedSsl = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';

        $isHttps = $https
            || strtolower($forwardedProto) === 'https'
            || strtolower($forwardedSsl) === 'on';

        if (!$isHttps) {
            throw new HttpError(400, 'HTTPS required');
        }
    }

    private function enforceAuth(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            // Some shared-hosting SAPIs strip Authorization from $_SERVER; recover it.
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            throw new HttpError(401, 'Unauthorized');
        }

        $supplied = $matches[1];
        $expected = Config::require('MAIL_RELAY_SECRET');

        if (!hash_equals($expected, $supplied)) {
            throw new HttpError(401, 'Unauthorized');
        }
    }

    private function enforceRateLimit(): void
    {
        $identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $limiter = new RateLimiter(
            $this->storageDir,
            maxRequests: $this->positiveIntConfig('RATE_LIMIT_MAX', self::DEFAULT_RATE_LIMIT_MAX),
            windowSeconds: $this->positiveIntConfig('RATE_LIMIT_WINDOW', self::DEFAULT_RATE_LIMIT_WINDOW),
            logger: fn (string $message) => $this->logError($message)
        );
        if (!$limiter->allow($identifier)) {
            throw new HttpError(429, 'Too Many Requests');
        }
    }

    /**
     * Reads a positive-integer config value, falling back to $default if the
     * value is missing or not a positive integer (e.g. blank, negative, non-numeric).
     */
    private function positiveIntConfig(string $key, int $default): int
    {
        $raw = Config::get($key);
        if ($raw === null || $raw === '' || !ctype_digit($raw) || (int) $raw < 1) {
            return $default;
        }
        return (int) $raw;
    }

    private function parseBody(): array
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > self::MAX_BODY_BYTES) {
            throw new HttpError(400, 'Request body too large');
        }

        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($raw === false) {
            throw new HttpError(400, 'Unable to read request body');
        }
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new HttpError(400, 'Request body too large');
        }
        if (trim($raw) === '') {
            throw new HttpError(400, 'Empty request body');
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpError(400, 'Malformed JSON');
        }

        return $this->validatePayload($data);
    }

    private function validatePayload(array $data): array
    {
        $to = $data['to'] ?? null;
        if (!is_array($to) || !isset($to['email']) || !is_string($to['email'])) {
            throw new HttpError(400, 'Missing or invalid "to.email"');
        }

        $toEmail = trim($to['email']);
        if (!Validator::isValidEmail($toEmail)) {
            throw new HttpError(400, 'Invalid recipient email');
        }

        $toName = '';
        if (isset($to['name'])) {
            if (!is_string($to['name'])) {
                throw new HttpError(400, 'Invalid recipient name');
            }
            if (Validator::hasLineBreak($to['name'])) {
                throw new HttpError(400, 'Invalid characters in recipient name');
            }
            $toName = $to['name'];
        }

        $subject = $data['subject'] ?? null;
        if (!is_string($subject) || $subject === '') {
            throw new HttpError(400, 'Missing or invalid "subject"');
        }
        if (Validator::hasLineBreak($subject)) {
            throw new HttpError(400, 'Invalid characters in subject');
        }

        $html = $data['html'] ?? null;
        $text = $data['text'] ?? null;

        if (($html === null || $html === '') && ($text === null || $text === '')) {
            throw new HttpError(400, 'Either "html" or "text" body is required');
        }
        if ($html !== null && !is_string($html)) {
            throw new HttpError(400, 'Invalid "html" body');
        }
        if ($text !== null && !is_string($text)) {
            throw new HttpError(400, 'Invalid "text" body');
        }

        $extraHeaders = [];
        if (isset($data['headers'])) {
            if (!is_array($data['headers'])) {
                throw new HttpError(400, 'Invalid "headers"');
            }
            foreach ($data['headers'] as $name => $value) {
                if (!in_array($name, self::ALLOWED_EXTRA_HEADERS, true)) {
                    throw new HttpError(400, "Header not allowed: {$name}");
                }
                if (!is_string($value) || $value === '') {
                    continue;
                }
                if (Validator::hasLineBreak($value)) {
                    throw new HttpError(400, "Invalid characters in header: {$name}");
                }
                $extraHeaders[$name] = $value;
            }
        }

        return [
            'toEmail' => $toEmail,
            'toName' => $toName,
            'subject' => $subject,
            'html' => $html !== null ? (string) $html : null,
            'text' => $text !== null ? (string) $text : null,
            'headers' => $extraHeaders,
        ];
    }

    private function sendMail(array $payload): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = Config::require('SMTP_HOST');
            $mail->Port = (int) Config::require('SMTP_PORT');
            $mail->SMTPAuth = true;
            $mail->Username = Config::require('SMTP_USER');
            $mail->Password = Config::require('SMTP_PASSWORD');

            // SMTP_SECURE selects the PHPMailer encryption mode — it does NOT toggle
            // encryption on/off; STARTTLS is still a fully encrypted connection.
            //   SMTP_SECURE=false (default) -> ENCRYPTION_STARTTLS (typically port 587):
            //       connect in plaintext, then upgrade to TLS via the STARTTLS command.
            //   SMTP_SECURE=true            -> ENCRYPTION_SMTPS (typically port 465):
            //       implicit TLS from the first byte of the connection.
            $smtpSecure = Config::get('SMTP_SECURE', 'false');
            if (filter_var($smtpSecure, FILTER_VALIDATE_BOOLEAN)) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mail->SMTPAutoTLS = true;

            $mail->Timeout = self::SMTP_TIMEOUT_SECONDS;
            $mail->SMTPKeepAlive = false;

            // SMTP debug MUST stay disabled in production; never expose diagnostics.
            $mail->SMTPDebug = 0;
            $mail->Debugoutput = static function (): void {
                // Intentionally discarded.
            };

            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $fromEmail = Config::require('FROM_EMAIL');
            $fromName = Config::get('FROM_NAME', '');
            $mail->setFrom($fromEmail, $fromName);

            $mail->addAddress($payload['toEmail'], $payload['toName']);

            $mail->Subject = $payload['subject'];
            if ($payload['html'] !== null && $payload['html'] !== '') {
                $mail->isHTML(true);
                $mail->Body = $payload['html'];
                $mail->AltBody = $payload['text'] ?? '';
            } else {
                $mail->isHTML(false);
                $mail->Body = $payload['text'];
            }

            foreach ($payload['headers'] as $name => $value) {
                $mail->addCustomHeader($name, $value);
            }

            $mail->send();
            $this->lastMessageId = $mail->getLastMessageID();
        } catch (PHPMailerException $e) {
            // Log locally for operators; never leak PHPMailer diagnostics to the caller.
            $this->logError('SMTP send failed: ' . $e->getMessage());
            throw new HttpError(502, 'Email delivery failed');
        }
    }

    private function logError(string $message): void
    {
        $line = sprintf('[%s] %s%s', date('c'), $message, PHP_EOL);
        $logFile = rtrim($this->storageDir, '/\\') . '/error.log';
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

final class HttpError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly ?string $logMessage = null
    ) {
        parent::__construct($message);
    }
}
