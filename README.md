# Minify URL

A minimal PHP URL shortener. Paste a long URL, optionally give it an expiry date or a custom code, and get back a short link that redirects to the original URL until it expires.

## Features

- Shortens any valid URL to a random 5-character code, or a custom code of your choosing
- Optional expiry date/time — expired links return a 404 instead of redirecting
- Click tracking — append `+` to any short URL to see visit count, status, and timestamps
- Per-IP rate limiting on link creation
- Cryptographically secure short code generation (`random_int()`)
- Collision-safe: retries automatically if a generated code already exists
- No runtime dependencies — just PHP and SQLite (Composer/PHPUnit are dev-only, for tests)

## Requirements

- PHP 8.2+
- PHP extensions:
  - PDO
  - PDO SQLite
- Apache with `mod_rewrite` enabled
- [Composer](https://getcomposer.org/) (only needed to run the test suite)

## Setup

1. Place all project files (including `.htaccess`) in a directory served by Apache.
2. Ensure `mod_rewrite` is enabled and `AllowOverride All` is set for that directory — this is required for the `.htaccess` rewrite rules to work. Without it, visiting a generated short URL will 404 even though the code is correct.
3. Run `composer install` to generate the autoloader (and pull in PHPUnit if you want to run the tests).
4. The SQLite database file (`db_urls.db`) is created automatically on first run — no manual database setup is required.

## Usage

1. Navigate to the site root, e.g. `http://localhost/`.
2. Enter the destination URL (required), a custom short code (optional), and an expiry date/time (optional).
3. You'll be shown a short URL, e.g. `http://localhost/abc12`.
4. Visiting the short URL redirects to the destination if the code exists and hasn't expired.
5. Appending a `+` to the short URL (e.g. `http://localhost/abc12+`) shows click stats instead of redirecting.
6. If the code doesn't exist or has expired, a 404 page is shown instead.

## Running tests

```
composer install
vendor/bin/phpunit
```

## Notes

See [DECISIONS.md](DECISIONS.md) for design decisions, trade-offs, and security considerations.
