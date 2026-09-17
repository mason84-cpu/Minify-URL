## Code Structure / Layout

- Single-file design (superseded)
    - Given the original time constraints I went with a single file design, also easier to review. Since then the `UrlShortener` class was moved to `src/UrlShortener.php` with Composer/PSR-4 autoloading, so `index.php` is now just the HTTP bootstrap/view layer. See "Autoloading" below.

- Views/templating
    - If the code were split into multiple files, a 'views' folder would hold reusable templates, for example header/footer/layout etc.

- UrlShortener class and database connection
    - I deliberately separated the PDO connection from the UrlShortener class, injecting it via the constructor rather than creating it internally. This gives the class a single responsibility and makes it possible to swap in a different PDO connection (a real PDO for the app, an in-memory SQLite PDO for tests).

- Syntax for if statements
    - I used curly brackets syntax for logic inside the UrlShortener class but switched to if/endif in the HTML views to make opening/closing of each block easier to scan.

- JavaScript usage
    - I kept JavaScript minimal since the core functionality, form submission and redirection work correctly via standard HTTP requests without needing client side scripting. I only added a small copy to clipboard script that runs once a new short URL has been generated.

- Autoloading
    - `UrlShortener` and `RateLimitExceededException` now live under `src/` with Composer PSR-4 autoloading (`App\` namespace) instead of being declared inline in `index.php`. This was the natural next step once the class needed to be unit tested in isolation from the HTTP/superglobal-dependent routing code.

- Base path
    - Originally the short URL path was hardcoded to a `/urls/` prefix, requiring the app to be deployed into a subfolder of that name. This has since been simplified: the app now treats the site root as the form, and any non-empty request path as a short code to resolve, so it can be deployed at any base path without configuration.


## What I Would Do Differently With More Time

- Public/src structure
    - Had I had more time I would have moved the project to a public/src structure so sensitive files are protected by physical separation, rather than depending on htaccess/config files.

- Views/templating
    - I would extract the repeated HTML boilerplate (header/footer/layout) into reusable template files rather than duplicating it across the home page, stats page, and 404 page.


## What I Would Change If The Software/Tech Stack Wasn't Specified

- Database
    - Would use mysql database hosted on the server rather than a single file holding the whole database. Mainly because during writes the file is locked with SQLite so this would not scale well under traffic load.


## Security and Reliability Considerations

- str_shuffle() vs random_int()
    - I considered both str_shuffle() and random_int() for generating short codes. I chose random_int() since it draws from a cryptographically secure source, unlike str_shuffle() under the hood, which uses a predictable algorithm. With enough previously generated codes collected, future codes could potentially be predicted.

- Collision check
    - I opted to go with try/insert/catch/retry approach for collision because this way we check for dupes (unique short_code in db) and retry. It also solves race condition at the same time.

- SQLite
    - Added rule to htaccess denying direct access to .db file.

- XSS and special characters
    - htmlspecialchars() was used on every piece of dynamic data echoed into HTML, converting characters like < and > into safe HTML entities, this prevents XSS (Cross-Site Scripting).

- SQL injection
    - All queries are prepared statements with named bound parameters (for easier read) to prevent sql injection.

- Timezone
    - During debugging I noticed the inserted time was an hour out, added date_default_timezone_set() to the top of the page. (good old British summer time)
    - This resurfaced later: `created_at` columns were relying on SQLite's own `CURRENT_TIMESTAMP`, which is always UTC, while everything else in the app uses PHP's `date()` under `Europe/London`. It was harmless while `created_at` was purely informational, but once `creation_log.created_at` was compared against a PHP-computed window (for rate limiting) the mismatch meant the rate limiter never triggered — old rows always looked "in the future" relative to the (UTC) window boundary during BST. Fixed by inserting an explicit PHP-generated timestamp everywhere instead of trusting the SQL default.


## Custom Short Codes

- Explicit failure over silent substitution
    - Random codes retry automatically on collision (see "Collision check" above), but a custom code is a deliberate user choice, so if it's already taken I reject the request with a clear error rather than silently generating a different code the user didn't ask for.

- Allowed characters
    - Restricted to letters, numbers, hyphens and underscores (3-32 chars) via a whitelist regex. This avoids ambiguity with reserved characters — in particular a code can never end in `+`, which is reserved for the stats view (see below).


## Click Tracking / Stats

- `+` suffix convention
    - Borrowed the bit.ly convention of appending `+` to a short URL to view its stats (visit count, created/expiry dates, last accessed) instead of redirecting. This keeps the feature discoverable without adding a query parameter or a separate route prefix, consistent with the base-path-only routing used for the shortener itself.

- No authentication
    - Stats are public to anyone who knows (or guesses) the short code, same trust model as the redirect itself. Fine for a demo/portfolio project; a production version would gate this behind ownership (e.g. a session or API key tied to the creator).


## Rate Limiting

- Per-IP, sliding window, stored in SQLite
    - Link creation is throttled per IP address (default: 5 per 60 seconds) using a `creation_log` table rather than in-memory state, since PHP processes don't share memory between requests under Apache/mod_php. Old rows are pruned on each check so the table doesn't grow unbounded.
    - IP-based limiting is coarse (shared IPs/NAT, spoofable via `X-Forwarded-For` if ever placed behind a proxy without stripping it first) but proportionate for a portfolio project; a production deployment behind a load balancer would need to trust a specific forwarded-for header rather than raw `REMOTE_ADDR`.
