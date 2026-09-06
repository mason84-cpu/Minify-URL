## Requirements
- PHP 8.2+
- PHP extensions:
    - PDO
    - PDO SQLite
- Apache with `mod_rewrite` enabled

## Setup steps
1. Place all project files (including .htaccess) a subfolder named 'urls' served by your local development environment.
2. Ensure 'mod_rewrite' is enabled and 'AllowOverride All' is set for the serving directory - this is required for the '.htaccess' rewrite rules to work. Without this, visiting a generated short URL will return a 404 even though the code is correct.
3. The SQLite database file (db_urls.db) is created automatically on first run - no manual database setup is required.

## How to run it
1. Navigate to http://localhost/urls/ (or whichever folder name you placed the code into)
2. Enter the destination URL (required) and expiry date and time (optional)
3. Once generated you will see the short URL (for example http://localhost/urls/abc12)
4. The system will detect and check for short URL, if a match is found the browser will be redirected to the destination URL
5. If short URL does not exist or has expired the system will show a 404 page