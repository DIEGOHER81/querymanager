<?php

namespace Services;

class EncryptionService
{
    private static ?string $key = null;
    private const METHOD = 'aes-256-cbc';

    private static function getKey(): string
    {
        if (self::$key !== null) return self::$key;

        // Priority: environment variable > file
        $envKey = ENCRYPTION_KEY_ENV;
        if ($envKey && strlen($envKey) > 0) {
            self::$key = base64_decode($envKey);
            return self::$key;
        }

        $keyFile = ENCRYPTION_KEY_FILE;

        if (!file_exists($keyFile)) {
            $key = base64_encode(random_bytes(32));
            file_put_contents($keyFile, $key);
            chmod($keyFile, 0600);
        }

        self::$key = base64_decode(file_get_contents($keyFile));
        return self::$key;
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();
        $iv = random_bytes(openssl_cipher_iv_length(self::METHOD));
        $encrypted = openssl_encrypt($plaintext, self::METHOD, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt(string $ciphertext): string
    {
        $key = self::getKey();
        $data = base64_decode($ciphertext);
        $ivLength = openssl_cipher_iv_length(self::METHOD);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        $decrypted = openssl_decrypt($encrypted, self::METHOD, $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new \RuntimeException('Error al descifrar los datos');
        }

        return $decrypted;
    }
}
