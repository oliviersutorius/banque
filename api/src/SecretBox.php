<?php

declare(strict_types=1);

namespace Banque;

use RuntimeException;

/**
 * Chiffrement authentifié (libsodium, XSalsa20-Poly1305) des secrets stockés dans data/, comme le secret TOTP.
 *
 * La clé est rangée hors de data/ : quelqu'un qui récupère data/ (sauvegarde, copie) ne peut pas lire le secret.
 * Elle vient de BANQUE_CLE (base64) ou du fichier désigné par BANQUE_CLE_FICHIER ; voir api/bin/cle.php.
 */
final class SecretBox
{
    private function __construct(#[\SensitiveParameter] private readonly string $key)
    {
    }

    /**
     * @throws RuntimeException si la clé n'est pas du base64 de 32 octets
     */
    public static function fromBase64(#[\SensitiveParameter] string $encoded): self
    {
        $key = base64_decode(trim($encoded), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Clé de chiffrement invalide : 32 octets en base64 attendus (php api/bin/cle.php)');
        }

        return new self($key);
    }

    /**
     * @param string|false $keyVariable valeur de BANQUE_CLE (false si absente)
     * @param string|false $keyFile valeur de BANQUE_CLE_FICHIER (false si absente)
     * @return self|null null si aucune clé n'est configurée
     * @throws RuntimeException si le fichier de clé est illisible ou la clé invalide
     */
    public static function fromSources(#[\SensitiveParameter] string|false $keyVariable, string|false $keyFile): ?self
    {
        if (is_string($keyVariable) && $keyVariable !== '') {
            return self::fromBase64($keyVariable);
        }
        if (is_string($keyFile) && $keyFile !== '') {
            $content = is_readable($keyFile) ? file_get_contents($keyFile) : false;
            if ($content === false) {
                throw new RuntimeException('Fichier de clé illisible : ' . $keyFile);
            }

            return self::fromBase64($content);
        }

        return null;
    }

    public static function fromEnvironment(): ?self
    {
        return self::fromSources(getenv('BANQUE_CLE'), getenv('BANQUE_CLE_FICHIER'));
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    /**
     * @return string nonce + texte chiffré, en base64
     */
    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /**
     * @throws RuntimeException si le message a été modifié ou chiffré avec une autre clé
     */
    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('Secret chiffré illisible');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if ($plain === false) {
            throw new RuntimeException('Déchiffrement impossible : mauvaise clé ou secret modifié');
        }

        return $plain;
    }
}
