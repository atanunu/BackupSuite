<?php
namespace Backup;

/**
 * Security helpers
 * ----------------
 * • CSRF token / check
 * • TOTP (RFC-6238) 30-second window
 * • AES-256-GCM file encrypt / decrypt  (libsodium)
 */
class Security
{
    /* =========================================================
     *  CSRF
     * ========================================================= */
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function check(?string $token): void
    {
        if (!hash_equals($_SESSION['csrf'] ?? '', $token ?? '')) {
            die('CSRF');
        }
    }

    /* =========================================================
     *  TOTP  (base32 decode + 30 s code)
     * ========================================================= */
    private static function base32Decode(string $b32): string
    {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper($b32);
        $bits = '';

        foreach (str_split($b32) as $c) {
            if ($c === '=')
                break;
            $bits .= str_pad(base_convert(strpos($map, $c), 10, 2), 5, '0', STR_PAD_LEFT);
        }
        $pad = strlen($bits) % 8;
        if ($pad)
            $bits = substr($bits, 0, -$pad);   // ditch extra bits

        return pack('H*', str_pad(base_convert($bits, 2, 16), strlen($bits) / 4, '0', STR_PAD_LEFT));
    }

    private static function otp(string $key, int $t): string
    {
        $msg = pack('N*', 0) . pack('N*', $t);
        $hash = hash_hmac('sha1', $msg, $key, true);
        $off = ord(substr($hash, -1)) & 0x0F;

        $bin = (
            ((ord($hash[$off]) & 0x7f) << 24) |
            ((ord($hash[$off + 1]) & 0xff) << 16) |
            ((ord($hash[$off + 2]) & 0xff) << 8) |
            (ord($hash[$off + 3]) & 0xff)
        );
        return str_pad($bin % 1000000, 6, '0', STR_PAD_LEFT);
    }

    /** Return true if `$code` matches the secret (±1 time-step). */
    public static function totpVerify(string $secret, string $code, int $window = 1): bool
    {
        $key = self::base32Decode($secret);
        $now = (int) floor(time() / 30);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::otp($key, $now + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /* =========================================================
     *  AES-256-GCM  (libsodium)
     * ========================================================= */
    private static function key(): string
    {
        $cfg = \Backup\Bootstrap::cfg('security')['encryption']['key'];
        $raw = base64_decode($cfg);
        if ($raw === false || strlen($raw) !== 32) {
            die('Bad 32-byte encryption key');
        }
        return $raw;
    }

    /** Encrypt an in-memory string, return raw binary (IV + cipher). */
    public static function encrypt(string $plain): string
    {
        $k = self::key();
        $iv = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $c = sodium_crypto_aead_aes256gcm_encrypt($plain, $iv, $iv, $k);
        return $iv . $c;
    }

    /** Decrypt a raw binary buffer (IV + cipher). */
    public static function decrypt(string $buf): string
    {
        $k = self::key();
        $iv = substr($buf, 0, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $c = substr($buf, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        return sodium_crypto_aead_aes256gcm_decrypt($c, $iv, $iv, $k);
    }

    /** Encrypt a file on disk in-place → `$dst` */
    public static function encryptFile(string $src, string $dst): void
    {
        file_put_contents($dst, self::encrypt(file_get_contents($src)));
    }

    /** Decrypt an *encrypted* file on disk and return plaintext. */
    public static function decryptFile(string $encPath): string
    {
        return self::decrypt(file_get_contents($encPath));
    }
}
