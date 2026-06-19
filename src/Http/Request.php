<?php

declare(strict_types=1);

namespace Dblib\Http;

/**
 * Immutable snapshot of the incoming HTTP request.
 */
final class Request
{
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly string $basePath,
        /** @var array<string,mixed> */
        private readonly array $query,
        /** @var array<string,mixed> */
        private readonly array $post,
    ) {
    }

    public static function capture(): self
    {
        $script   = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $basePath = rtrim(str_replace('\\', '/', dirname($script)), '/');

        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Strip the app's base directory (e.g. /dblib) so routes are absolute.
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . ltrim($path, '/');

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $basePath,
            $_GET,
            $_POST,
        );
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }
}
