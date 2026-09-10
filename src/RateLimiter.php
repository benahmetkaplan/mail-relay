<?php

declare(strict_types=1);

namespace MailRelay;

/**
 * Simple file-based fixed-window rate limiter. No Redis/DB required.
 * Suitable for a single backend client on shared hosting.
 */
final class RateLimiter
{
    private string $file;
    private int $windowSeconds;
    private int $maxRequests;

    /** @var (callable(string): void)|null */
    private $logger;

    /**
     * @param (callable(string): void)|null $logger Invoked with a short message when
     *        the storage file cannot be opened or locked. Pass null to log nothing.
     */
    public function __construct(
        string $storageDir,
        int $maxRequests = 60,
        int $windowSeconds = 60,
        ?callable $logger = null
    ) {
        $this->file = rtrim($storageDir, '/\\') . '/ratelimit.json';
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->logger = $logger;
    }

    /**
     * Returns true if the request is allowed, false if rate limit exceeded.
     *
     * Fails OPEN: if the storage file can't be opened or locked, the request
     * is allowed rather than rejected. This is deliberate — a storage/filesystem
     * problem should not take down mail delivery — but it does mean rate
     * limiting is silently disabled while the underlying problem persists.
     * Both failure cases are logged (never surfaced to the HTTP caller) so
     * operators can notice and fix them.
     */
    public function allow(string $identifier): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $fp = @fopen($this->file, 'c+');
        if ($fp === false) {
            $this->log('Rate limiter: failed to open storage file, failing open');
            return true;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                $this->log('Rate limiter: failed to lock storage file, failing open');
                return true;
            }

            $size = filesize($this->file);
            $raw = $size > 0 ? fread($fp, $size) : '';
            $data = $raw ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }

            $now = time();
            $windowStart = $now - $this->windowSeconds;

            // Prune expired entries and old identifiers to keep file small.
            foreach ($data as $key => $entry) {
                if (!isset($entry['windowStart']) || $entry['windowStart'] < $windowStart) {
                    unset($data[$key]);
                }
            }

            $entry = $data[$identifier] ?? ['windowStart' => $now, 'count' => 0];
            if ($entry['windowStart'] < $windowStart) {
                $entry = ['windowStart' => $now, 'count' => 0];
            }

            $allowed = $entry['count'] < $this->maxRequests;
            if ($allowed) {
                $entry['count']++;
            }
            $data[$identifier] = $entry;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);

            return $allowed;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
