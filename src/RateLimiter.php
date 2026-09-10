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

    public function __construct(string $storageDir, int $maxRequests = 60, int $windowSeconds = 60)
    {
        $this->file = rtrim($storageDir, '/\\') . '/ratelimit.json';
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Returns true if the request is allowed, false if rate limit exceeded.
     */
    public function allow(string $identifier): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $fp = @fopen($this->file, 'c+');
        if ($fp === false) {
            // Fail open if storage isn't writable — availability over strict limiting.
            return true;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
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
}
