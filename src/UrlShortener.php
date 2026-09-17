<?php

namespace App;

use InvalidArgumentException;
use PDO;
use PDOException;

class UrlShortener
{
    private const CUSTOM_CODE_PATTERN = '/^[A-Za-z0-9_-]{3,32}$/';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->initTables();
    }

    private function initTables(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS urls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            long_url TEXT NOT NULL,
            short_code TEXT UNIQUE NOT NULL,
            expires_at DATETIME NULL,
            visits INTEGER NOT NULL DEFAULT 0,
            last_accessed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // used by enforceRateLimit() to throttle short URL creation per IP
        $this->db->exec("CREATE TABLE IF NOT EXISTS creation_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function createShortUrl(string $longUrl, ?string $expiresAt, ?string $customCode = null): string
    {
        // check valid URL
        if (!filter_var($longUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("$longUrl is not a valid URL.");
        }

        // check if expiresAt is valid format
        if ($expiresAt !== null && !strtotime($expiresAt)) {
            throw new InvalidArgumentException("Invalid expiration date format.");
        }

        // check expiresAt is in the future
        if ($expiresAt !== null && strtotime($expiresAt) < time()) {
            throw new InvalidArgumentException("Expiration date must be in the future.");
        }

        // normalise date format to 'Y-m-d H:i:s' for database storage
        if ($expiresAt !== null) {
            $expiresAt = date('Y-m-d H:i:s', strtotime($expiresAt));
        }

        if ($customCode !== null && $customCode !== '') {
            if (!preg_match(self::CUSTOM_CODE_PATTERN, $customCode)) {
                throw new InvalidArgumentException("Custom code must be 3-32 characters and contain only letters, numbers, hyphens and underscores.");
            }
            $shortCode = $customCode;
        } else {
            $shortCode = $this->generateRandomShortCode();
        }

        // try to insert into db, if it fails due to unique constraint, generate a new short code and try again
        try {
            $params = [
                ':long_url' => $longUrl,
                ':short_code' => $shortCode,
                ':expires_at' => $expiresAt,
                // set explicitly rather than relying on SQLite's CURRENT_TIMESTAMP default, which is UTC
                // and would otherwise disagree with the app's Europe/London timestamps elsewhere
                ':created_at' => date('Y-m-d H:i:s')
            ];
            $sql = "INSERT INTO urls (long_url, short_code, expires_at, created_at) VALUES (:long_url, :short_code, :expires_at, :created_at)";
            $query = $this->db->prepare($sql);
            $query->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // unique constraint violation
                if ($customCode !== null && $customCode !== '') {
                    // a custom code was explicitly requested, so fail loudly rather than silently substituting one
                    throw new InvalidArgumentException("That custom code is already taken.");
                }
                return $this->createShortUrl($longUrl, $expiresAt, null); // retry with a new random code
            }
            throw $e; // rethrow other database errors
        }

        return "//" . ($_SERVER['HTTP_HOST'] ?? "localhost") . "/" . $shortCode; //returns //localhost/abcd12
    }

    public function resolveUrl(string $shortCode): string
    {
        $row = $this->findByCode($shortCode);

        if ($row === null || $this->isExpired($row)) {
            throw new InvalidArgumentException("URL not found or expired.");
        }

        $update = $this->db->prepare("UPDATE urls SET visits = visits + 1, last_accessed_at = :now WHERE short_code = :short_code");
        $update->execute([':now' => date('Y-m-d H:i:s'), ':short_code' => $shortCode]);

        return $row['long_url'];
    }

    /**
     * @return array{long_url: string, short_code: string, created_at: string, expires_at: ?string, visits: int, last_accessed_at: ?string, expired: bool}
     */
    public function getStats(string $shortCode): array
    {
        $row = $this->findByCode($shortCode);

        if ($row === null) {
            throw new InvalidArgumentException("Short code not found.");
        }

        return [
            'long_url' => $row['long_url'],
            'short_code' => $row['short_code'],
            'created_at' => $row['created_at'],
            'expires_at' => $row['expires_at'],
            'visits' => (int) $row['visits'],
            'last_accessed_at' => $row['last_accessed_at'],
            'expired' => $this->isExpired($row),
        ];
    }

    /**
     * Throttles link creation per IP address using a rolling time window.
     *
     * @throws RateLimitExceededException if $ip has created $maxAttempts or more links within $windowSeconds
     */
    public function enforceRateLimit(string $ip, int $maxAttempts = 5, int $windowSeconds = 60): void
    {
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        // prune expired entries so the log doesn't grow unbounded
        $this->db->prepare("DELETE FROM creation_log WHERE created_at < :window_start")
            ->execute([':window_start' => $windowStart]);

        $count = $this->db->prepare("SELECT COUNT(*) FROM creation_log WHERE ip = :ip AND created_at >= :window_start");
        $count->execute([':ip' => $ip, ':window_start' => $windowStart]);

        if ((int) $count->fetchColumn() >= $maxAttempts) {
            throw new RateLimitExceededException("Too many links created recently. Please wait a minute and try again.");
        }

        // insert an explicit PHP-generated timestamp rather than relying on SQLite's CURRENT_TIMESTAMP,
        // which is UTC and would otherwise be compared against $windowStart's local (Europe/London) time
        $this->db->prepare("INSERT INTO creation_log (ip, created_at) VALUES (:ip, :now)")
            ->execute([':ip' => $ip, ':now' => date('Y-m-d H:i:s')]);
    }

    private function findByCode(string $shortCode): ?array
    {
        $query = $this->db->prepare("SELECT * FROM urls WHERE short_code = :short_code");
        $query->execute([':short_code' => $shortCode]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function isExpired(array $row): bool
    {
        return $row['expires_at'] !== null && strtotime($row['expires_at']) <= time();
    }

    private function generateRandomShortCode(int $length = 5): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $charLen = strlen($chars);
        $shortCode = '';

        for ($i = 0; $i < $length; $i++) {
            // random_int() is cryptographically secure
            $randomIndex = random_int(0, $charLen - 1);
            $shortCode .= $chars[$randomIndex];
        }

        return $shortCode;
    }
}
