<?php

/**
 * URL Shortener Application
 * An application that allows users to shorten URLs and optionally set an expiration date for the shortened URL.
 * @author Mason Chan
 * @version 1.0
 */

error_reporting(E_ALL); // remove this line in production
ini_set('display_errors', '1'); // remove this line in production
date_default_timezone_set('Europe/London');

require __DIR__ . '/vendor/autoload.php';

use App\RateLimitExceededException;
use App\UrlShortener;

function render404(string $message): never {
    http_response_code(404);
    echo '<!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>404 Not Found</title>
                <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
            </head>
            <body class="bg-slate-100 flex items-center justify-center h-screen">
                <div class="bg-white p-8 rounded-xl shadow-md text-center">
                    <h1 class="text-2xl font-bold text-red-600 mb-2">404</h1>
                    <p class="text-slate-600">' . htmlspecialchars($message) . '</p>
                </div>
            </body>
            </html>';
    exit;
}

//initialise database and UrlShortener class
$db = new PDO('sqlite:' . __DIR__ . '/db_urls.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$shortener = new UrlShortener($db);
// base path (empty) shows the generator form; anything else is treated as a short code to resolve.
// a trailing '+' (bit.ly-style) shows click stats for the code instead of redirecting.
$requestPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

if ($requestPath !== '') {
    if (str_ends_with($requestPath, '+')) {
        $shortCode = substr($requestPath, 0, -1);
        try {
            $stats = $shortener->getStats($shortCode);
        } catch (InvalidArgumentException $e) {
            render404($e->getMessage());
        }

        ?>
        <!DOCTYPE html>
        <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Stats for <?php echo htmlspecialchars($stats['short_code']); ?></title>
                <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
            </head>
            <body class="bg-slate-100 flex items-center justify-center h-screen">
                <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-lg">
                    <h1 class="text-2xl font-bold text-indigo-600 mb-4">Stats for /<?php echo htmlspecialchars($stats['short_code']); ?></h1>
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-gray-500">Destination</dt>
                            <dd class="text-slate-800 break-all"><?php echo htmlspecialchars($stats['long_url']); ?></dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Status</dt>
                            <dd class="<?php echo $stats['expired'] ? 'text-red-600' : 'text-green-600'; ?>"><?php echo $stats['expired'] ? 'Expired' : 'Active'; ?></dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Visits</dt>
                            <dd class="text-slate-800"><?php echo (int) $stats['visits']; ?></dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Created</dt>
                            <dd class="text-slate-800"><?php echo htmlspecialchars($stats['created_at']); ?></dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Expires</dt>
                            <dd class="text-slate-800"><?php echo htmlspecialchars($stats['expires_at'] ?? 'Never'); ?></dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Last accessed</dt>
                            <dd class="text-slate-800"><?php echo htmlspecialchars($stats['last_accessed_at'] ?? 'Never'); ?></dd>
                        </div>
                    </dl>
                    <a href="/" class="inline-block mt-6 text-sm text-blue-600 underline">&larr; Create another link</a>
                </div>
            </body>
        </html>
        <?php
        exit;
    }

    $shortCode = $requestPath;
    try {
        $longUrl = $shortener->resolveUrl($shortCode);
        header("Location: $longUrl");
        exit;
    } catch (InvalidArgumentException $e) {
        render404($e->getMessage());
    }
}

// Handle form submission
$shortUrl = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $longUrl = $_POST['url'] ?? '';
    $expiresAt = trim($_POST['expires_at'] ?? '');
    $expiresAt = $expiresAt !== '' ? $expiresAt : null;
    $customCode = trim($_POST['custom_code'] ?? '');
    $customCode = $customCode !== '' ? $customCode : null;

    try {
        $shortener->enforceRateLimit($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $shortUrl = $shortener->createShortUrl($longUrl, $expiresAt, $customCode);
    } catch (RateLimitExceededException $e) {
        http_response_code(429);
        $errorMessage = $e->getMessage();
    } catch (InvalidArgumentException $e) {
        $errorMessage = $e->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mason Chan - URL Shortener</title>

        <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    </head>
    <body class="bg-slate-100 flex items-center justify-center h-screen">

        <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-3xl">
            <h1 class="text-2xl font-bold text-indigo-600 mb-4">URL Shortener</h1>

            <?php if ($errorMessage): ?>
                <!-- error message block -->
                <p class="text-red-600 mb-4"><?php echo htmlspecialchars($errorMessage); ?></p>
            <?php endif; ?>

            <?php if ($shortUrl): ?>
                <!-- success message block, showing $shortUrl -->
                <p class="text-green-600 mb-4">
                    Your short URL is: <a href="<?php echo htmlspecialchars($shortUrl); ?>" class="text-blue-600 underline"><?php echo htmlspecialchars($shortUrl); ?></a>
                </p>
                <div class="flex items-center gap-4">
                    <button id="copy-btn" class="px-6 py-2.5 bg-blue-600 text-white font-medium rounded-lg shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 text-sm transition-colors">
                        Copy URL
                    </button>
                    <a href="<?php echo htmlspecialchars($shortUrl); ?>+" class="text-sm text-slate-500 underline">View stats</a>
                </div>
                <script>
                    document.getElementById('copy-btn').addEventListener('click', function() {
                        navigator.clipboard.writeText('<?php echo htmlspecialchars($shortUrl); ?>');
                    });
                </script>
            <?php else: ?>
                <form method="POST" class="flex flex-col md:flex-row items-end gap-4 w-full">
                    <div class="w-full md:flex-[2]">
                        <label for="url-input" class="block text-sm font-medium text-gray-700 mb-1">URL</label>
                        <input type="url" id="url-input" name="url" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" placeholder="https://www.google.com" required>
                    </div>

                    <div class="w-full md:flex-1">
                        <label for="custom-code" class="block text-sm font-medium text-gray-700 mb-1">Custom Code (optional)</label>
                        <input type="text" id="custom-code" name="custom_code" pattern="[A-Za-z0-9_-]{3,32}" maxlength="32" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" placeholder="my-link">
                    </div>

                    <div class="w-full md:flex-1">
                        <label for="expires-at" class="block text-sm font-medium text-gray-700 mb-1">Expiry Date and Time</label>
                        <input type="datetime-local" id="expires-at" name="expires_at" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" placeholder="yyyy-mm-dd hh:mm:ss">
                    </div>

                    <div class="w-full md:w-auto">
                        <button type="submit" class="w-full md:w-auto px-6 py-2.5 bg-blue-600 text-white font-medium rounded-lg shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 text-sm transition-colors">
                            Submit
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

    </body>

</html>
