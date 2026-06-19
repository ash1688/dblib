<?php

declare(strict_types=1);

namespace Dblib\Http;

/**
 * Response helpers. Controllers return one of these from their actions.
 */
final class Response
{
    private function __construct(
        private readonly int $status,
        private readonly string $body,
        /** @var array<string,string> */
        private readonly array $headers,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    /** A file download (Content-Disposition: attachment). */
    public static function download(string $body, string $filename, string $contentType = 'text/csv; charset=utf-8'): self
    {
        // Keep the filename header-safe; callers pass sandbox table names.
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';
        return new self(200, $body, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
