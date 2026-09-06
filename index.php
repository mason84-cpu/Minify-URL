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

class UrlShortener {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->initTable();
    }

    private function initTable(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS urls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            long_url TEXT NOT NULL,
            short_code TEXT UNIQUE NOT NULL,
            expires_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function createShortUrl(string $longUrl, ?string $expiresAt): string {
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

        // generate a random short code
        $shortCode = $this->generateRandomShortCode();

        // try to insert into db, if it fails due to unique constraint, generate a new short code and try again
        try {
            $params = [
                ':long_url' => $longUrl,
                ':short_code' => $shortCode,
                ':expires_at' => $expiresAt
            ];
            $sql = "INSERT INTO urls (long_url, short_code, expires_at) VALUES (:long_url, :short_code, :expires_at)";
            $query = $this->db->prepare($sql);
            $query->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // unique constraint violation
                return $this->createShortUrl($longUrl, $expiresAt); // retry with a new short code
            }
            throw $e; // rethrow other database errors
        }

        return "//" . ($_SERVER['HTTP_HOST'] ?? "localhost") . "/urls/" . $shortCode; //returns //localhost/urls/abcd12
    }

    public function resolveUrl(string $shortCode): string {
        $params = [':short_code' => $shortCode];
        $sql = "SELECT long_url, expires_at 
                FROM urls 
                WHERE short_code = :short_code";
        $query = $this->db->prepare($sql);
        $query->execute($params);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['expires_at'] === null || strtotime($row['expires_at']) > time()) {
                return $row['long_url'];
            }
        }
        throw new InvalidArgumentException("URL not found or expired.");
    }

    private function generateRandomShortCode(int $length = 5): string {
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

//initialise database and UrlShortener class
$db = new PDO('sqlite:' . __DIR__ . '/db_urls.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// testing expiry date - this would never be in production code, just for testing purposes
// $db->exec("UPDATE urls SET expires_at = '2020-01-01 00:00:00' WHERE short_code = 's4pX6'");
// exit("Updated expiry date.");

$shortener = new UrlShortener($db);
// handle URL resolution
$requestPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

if (str_starts_with($requestPath, 'urls/')) {
    $shortCode = substr($requestPath, strlen('urls/'));
    try {
        $longUrl = $shortener->resolveUrl($shortCode);
        if ($longUrl) {
            header("Location: $longUrl");
            exit;
        }
    } catch (InvalidArgumentException $e) {
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
                        <p class="text-slate-600">' . htmlspecialchars($e->getMessage()) . '</p>
                    </div>
                </body>
                </html>';
        exit;     
    }
}

// Handle form submission
$shortUrl = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $longUrl = $_POST['url'] ?? '';
    $expiresAt = trim($_POST['expires_at'] ?? '');
    $expiresAt = $expiresAt !== '' ? $expiresAt : null;

    try {
        $shortUrl = $shortener->createShortUrl($longUrl, $expiresAt);
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
                <button id="copy-btn" class="w-full md:w-auto px-6 py-2.5 bg-blue-600 text-white font-medium rounded-lg shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 text-sm transition-colors">
                    Copy URL
                </button>
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