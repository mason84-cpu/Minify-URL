## Code Structure / Layout

- Single-file design
    - Given the time constraints I went with a single file design, also easier to review.

- Views/templating
    - If the code were split into multiple files, a 'views' folder would hold reusable templates, for example header/footer/layout etc.

- UrlShortener class and database connection
    - I deliberately separated the PDO connection from the UrlShortener class, injecting it via the constructor rather than creating it internally. This gives the class a single responsibility and makes it possible to swap in a different PDO connection.

- Syntax for if statements
    - I used curly brackets syntax for logic inside the UrlShortener class but switched to if/endif in the HTML views to make opening/closing of each block easier to scan.

- JavaScript usage
    - I kept JavaScript minimal since the core functionality, form submission and redirection work correctly via standard HTTP requests without needing client side scripting. I only added a small copy to clipboard script that runs once a new short URL has been generated.


## What I Would Do Differently With More Time

- Public/src structure
    - Had I had more time I would have moved the project to a public/src structure so sensitive files are protected by physical separation, rather than depending on htaccess/config files.

- Views/templating
    - I would extract the repeated HTML boilerplate (header/footer/layout) into reusable template files rather than duplicating it across the home page and 404 page.

- Autoloading
    - Since I kept everything in a single file, there were no separate class files to autoload. If I split the code into multiple files, I would use composer with PSR-4 autoloading rather than manual require statements.

- Base path
    - The /urls/ path is currently hardcoded, with more time I'd make the base path configurable rather than fixed.


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
