<?php
/**
 * dblib bootstrap: PSR-4-ish autoloader, config, session.
 * No Composer dependency — keeps deployment to a plain XAMPP htdocs copy.
 */

declare(strict_types=1);

define('DBLIB_ROOT', __DIR__);
define('DBLIB_SRC', __DIR__ . DIRECTORY_SEPARATOR . 'src');

// --- Autoloader: Dblib\Foo\Bar  ->  src/Foo/Bar.php ---------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'Dblib\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = DBLIB_SRC . DIRECTORY_SEPARATOR
          . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// --- Config ------------------------------------------------------------------
$configFile = __DIR__ . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit("Missing config/config.php — copy config/config.example.php and edit it.\n");
}
\Dblib\Support\Config::load(require $configFile);

// --- Errors: in dev, show; in prod, log + a generic page (no stack traces) ----
$dblibDebug = (bool) \Dblib\Support\Config::get('app.debug', true);
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', $dblibDebug ? '1' : '0');

if (!$dblibDebug) {
    // Prod: never leak stack traces / schema details to the browser.
    set_exception_handler(static function (\Throwable $e): void {
        error_log('dblib uncaught: ' . $e);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<h1>Something went wrong</h1>'
            . '<p>Please try again. If it keeps happening, let your teacher know.</p>';
    });
}
