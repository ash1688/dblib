<?php
/**
 * Generate the credential-encryption key. Run once:
 *   php cli/genkey.php
 *
 * Writes a 32-byte key (base64) to config 'crypto.key_file'. Refuses to
 * overwrite an existing key (that would orphan every stored password).
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Dblib\Support\Config;

$keyFile = (string) Config::get('crypto.key_file');
$dir = dirname($keyFile);

if (is_file($keyFile)) {
    fwrite(STDERR, "Key already exists at {$keyFile} — refusing to overwrite.\n");
    exit(1);
}

if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Could not create key directory {$dir}.\n");
    exit(1);
}

$key = base64_encode(random_bytes(32));
if (file_put_contents($keyFile, $key) === false) {
    fwrite(STDERR, "Could not write key to {$keyFile}.\n");
    exit(1);
}

echo "Wrote new 32-byte key to {$keyFile}\n";
echo "Keep this safe and OUT of the web root. Losing it makes stored passwords unrecoverable.\n";
