<?php

namespace App\Tests;

use App\RateLimitExceededException;
use App\UrlShortener;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class UrlShortenerTest extends TestCase
{
    private PDO $db;
    private UrlShortener $shortener;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->shortener = new UrlShortener($this->db);
    }

    public function testCreateShortUrlRejectsInvalidUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->shortener->createShortUrl('not-a-url', null);
    }

    public function testCreateShortUrlRejectsInvalidExpiryFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->shortener->createShortUrl('https://example.com', 'not-a-date');
    }

    public function testCreateShortUrlRejectsPastExpiry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->shortener->createShortUrl('https://example.com', '2000-01-01 00:00:00');
    }

    public function testCreateAndResolveShortUrl(): void
    {
        $shortUrl = $this->shortener->createShortUrl('https://example.com', null);

        $this->assertSame('https://example.com', $this->shortener->resolveUrl($this->extractCode($shortUrl)));
    }

    public function testResolveUnknownCodeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->shortener->resolveUrl('doesnotexist');
    }

    public function testExpiredUrlIsNotResolved(): void
    {
        $shortUrl = $this->shortener->createShortUrl('https://example.com', '+1 year');
        $code = $this->extractCode($shortUrl);

        // backdate the expiry directly rather than sleeping in the test
        $this->db->prepare("UPDATE urls SET expires_at = '2000-01-01 00:00:00' WHERE short_code = :code")
            ->execute([':code' => $code]);

        $this->expectException(InvalidArgumentException::class);
        $this->shortener->resolveUrl($code);
    }

    public function testCustomCodeIsUsedVerbatim(): void
    {
        $shortUrl = $this->shortener->createShortUrl('https://example.com', null, 'my-code');

        $this->assertStringEndsWith('/my-code', $shortUrl);
    }

    public function testDuplicateCustomCodeIsRejected(): void
    {
        $this->shortener->createShortUrl('https://example.com', null, 'taken');

        $this->expectException(InvalidArgumentException::class);
        $this->shortener->createShortUrl('https://example.org', null, 'taken');
    }

    public function testInvalidCustomCodeFormatIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->shortener->createShortUrl('https://example.com', null, 'a b!');
    }

    public function testVisitsAreCountedAndReflectedInStats(): void
    {
        $shortUrl = $this->shortener->createShortUrl('https://example.com', null, 'counted');
        $code = $this->extractCode($shortUrl);

        $this->shortener->resolveUrl($code);
        $this->shortener->resolveUrl($code);

        $stats = $this->shortener->getStats($code);
        $this->assertSame(2, $stats['visits']);
        $this->assertFalse($stats['expired']);
    }

    public function testGetStatsForUnknownCodeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->shortener->getStats('doesnotexist');
    }

    public function testRateLimitIsEnforcedAfterMaxAttempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->shortener->enforceRateLimit('127.0.0.1', 5, 60);
        }

        $this->expectException(RateLimitExceededException::class);
        $this->shortener->enforceRateLimit('127.0.0.1', 5, 60);
    }

    public function testRateLimitIsTrackedPerIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->shortener->enforceRateLimit('127.0.0.1', 5, 60);
        }

        // a different IP has its own limit, so this must not throw
        $this->shortener->enforceRateLimit('10.0.0.1', 5, 60);
        $this->addToAssertionCount(1);
    }

    private function extractCode(string $shortUrl): string
    {
        $parts = explode('/', rtrim($shortUrl, '/'));

        return end($parts);
    }
}
