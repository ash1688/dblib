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

// --- Errors: in dev, show; everywhere, log -----------------------------------
error_reporting(E_ALL);
ini_set('display_errors', \Dblib\Support\Config::get('app.debug', true) ? '1' : '0');
