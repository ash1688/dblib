<?php

declare(strict_types=1);

namespace Dblib\Backup;

/**
 * Minimal in-memory tar (ustar) writer/reader, gzip-wrapped.
 *
 * Exists because the runtime has no zip extension (php:*-apache ships without
 * it and XAMPP builds vary), while zlib is everywhere. The archive only ever
 * holds a handful of regular files with short names, so the full ustar spec
 * (long names, links, sparse files) is deliberately not implemented.
 */
final class TarArchive
{
    private const BLOCK = 512;

    /** @var array<string,string> name => contents */
    private array $files = [];

    public function add(string $name, string $contents): void
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        if ($name === '' || strlen($name) > 100 || str_contains($name, '..')) {
            throw new \InvalidArgumentException("Bad archive entry name: {$name}");
        }
        $this->files[$name] = $contents;
    }

    /** @return array<string,string> */
    public function files(): array
    {
        return $this->files;
    }

    /** Serialise to gzip-compressed tar bytes. */
    public function toGzip(int $mtime = 0, int $level = 6): string
    {
        $mtime = $mtime ?: time();
        $out = '';
        foreach ($this->files as $name => $contents) {
            $out .= self::header($name, strlen($contents), $mtime) . $contents;
            $pad = (self::BLOCK - strlen($contents) % self::BLOCK) % self::BLOCK;
            $out .= str_repeat("\0", $pad);
        }
        $out .= str_repeat("\0", self::BLOCK * 2);
        return (string) gzencode($out, $level);
    }

    /**
     * Parse gzip-compressed tar bytes back into name => contents.
     *
     * @throws \RuntimeException on anything that isn't a plain ustar stream
     */
    public static function fromGzip(string $gz): self
    {
        $raw = @gzdecode($gz);
        if ($raw === false) {
            throw new \RuntimeException('Not a gzip archive.');
        }

        $archive = new self();
        $offset  = 0;
        $length  = strlen($raw);
        while ($offset + self::BLOCK <= $length) {
            $header = substr($raw, $offset, self::BLOCK);
            $offset += self::BLOCK;
            if (trim($header, "\0") === '') {
                break; // end-of-archive marker
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $type = substr($header, 156, 1);
            $data = substr($raw, $offset, $size);
            $offset += (int) (ceil($size / self::BLOCK) * self::BLOCK);
            if ($type === '0' || $type === "\0") {
                $archive->files[$name] = $data;
            }
        }
        return $archive;
    }

    private static function header(string $name, int $size, int $mtime): string
    {
        $h  = str_pad($name, 100, "\0");
        $h .= sprintf("%07o\0", 0644);          // mode
        $h .= sprintf("%07o\0", 0);             // uid
        $h .= sprintf("%07o\0", 0);             // gid
        $h .= sprintf("%011o\0", $size);        // size
        $h .= sprintf("%011o\0", $mtime);       // mtime
        $h .= '        ';                       // checksum placeholder (8 spaces)
        $h .= '0';                              // typeflag: regular file
        $h .= str_repeat("\0", 100);            // linkname
        $h .= "ustar\0" . '00';                 // magic + version
        $h .= str_pad('dblib', 32, "\0");       // uname
        $h .= str_pad('dblib', 32, "\0");       // gname
        $h .= sprintf("%07o\0", 0);             // devmajor
        $h .= sprintf("%07o\0", 0);             // devminor
        $h .= str_repeat("\0", 155);            // prefix
        $h  = str_pad($h, self::BLOCK, "\0");

        $sum = array_sum(array_map('ord', str_split($h)));
        return substr_replace($h, sprintf("%06o\0 ", $sum), 148, 8);
    }
}
