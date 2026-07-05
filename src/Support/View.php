<?php

declare(strict_types=1);

namespace Dblib\Support;

/**
 * Plain-PHP templating. render() returns HTML; templates live in /templates and
 * receive $data keys as local variables plus $basePath for building URLs.
 */
final class View
{
    public static function render(string $template, array $data = []): string
    {
        $file = DBLIB_ROOT . '/templates/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        $data['basePath'] ??= '';
        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    /** Escape for HTML output. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * URL for a file under /assets with its mtime as a cache-buster, so
     * browsers pick up changed JS/CSS immediately after a deploy instead of
     * serving a heuristically-cached copy.
     */
    public static function asset(string $basePath, string $path): string
    {
        $file = DBLIB_ROOT . '/assets/' . ltrim($path, '/');
        $version = is_file($file) ? (string) filemtime($file) : '0';
        return self::e($basePath . '/assets/' . ltrim($path, '/') . '?v=' . $version);
    }
}
