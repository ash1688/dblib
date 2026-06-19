<?php

declare(strict_types=1);

namespace Dblib\Crypto;

/**
 * Authenticated encryption (AES-256-GCM) for per-student MySQL passwords.
 *
 * The 32-byte key lives in a file OUTSIDE the web root and OUTSIDE the database
 * (path comes from config 'crypto.key_file'). Generate it once with
 * `php cli/genkey.php`. Wire format: base64( iv[12] || tag[16] || ciphertext ).
 *
 * Threat model (see design review): this protects against database-dump leaks
 * and casual file access. A compromised PHP process can still decrypt, because
 * it must, to connect as the student — that is accepted for this deployment.
 */
final class CredentialCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    private string $key;

    public function __construct(string $keyFile)
    {
        if (!is_file($keyFile)) {
            throw new \RuntimeException(
                "Encryption key not found at {$keyFile}. Run: php cli/genkey.php"
            );
        }
        $raw = base64_decode(trim((string) file_get_contents($keyFile)), true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new \RuntimeException('Encryption key is malformed (need 32 bytes, base64-encoded).');
        }
        $this->key = $raw;
    }

    public function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Ciphertext is malformed.');
        }
        $iv         = substr($raw, 0, self::IV_LEN);
        $tag        = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed (wrong key or tampered data).');
        }
        return $plaintext;
    }
}
