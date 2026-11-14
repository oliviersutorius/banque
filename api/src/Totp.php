<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Codes à usage unique basés sur le temps (TOTP, RFC 6238) : HMAC-SHA1, 6 chiffres, pas de 30 secondes.
 *
 * Compatible avec Aegis, Google Authenticator, 1Password, FreeOTP… Le secret est échangé en base32 (RFC 4648).
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    /** Pas acceptés avant et après le pas courant (décalage d'horloge du téléphone) */
    public const WINDOW = 1;
    private const SECRET_BYTES = 20;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * @return string secret aléatoire, en binaire (20 octets)
     */
    public static function generateSecret(): string
    {
        return random_bytes(self::SECRET_BYTES);
    }

    public static function step(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    public static function code(#[\SensitiveParameter] string $secret, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** $digits), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Vérifie un code saisi. Un pas déjà utilisé (inférieur ou égal à $lastStep) est refusé : un code intercepté
     * ne peut pas resservir.
     *
     * @return int|null le pas correspondant au code, à mémoriser comme nouveau $lastStep ; null si le code est faux
     */
    public static function verify(#[\SensitiveParameter] string $secret, string $code, int $time, int $lastStep): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $current = self::step($time);
        $found = null;
        // Toute la fenêtre est parcourue, sans sortie anticipée, pour un temps de réponse constant
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if (hash_equals(self::code($secret, $step), $code) && $step > $lastStep && $found === null) {
                $found = $step;
            }
        }

        return $found;
    }

    /**
     * Adresse à transformer en QR code pour l'application d'authentification.
     */
    public static function uri(#[\SensitiveParameter] string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            self::base32Encode($secret),
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $binary === '' ? '' : $out;
    }

    /**
     * Accepte minuscules, espaces et « = » de remplissage.
     *
     * @throws InvalidArgumentException si un caractère n'appartient pas à l'alphabet base32
     */
    public static function base32Decode(string $text): string
    {
        $text = strtoupper((string) preg_replace('/[\s=]+/', '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                throw new InvalidArgumentException('Caractère base32 invalide : ' . $char);
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $text === '' ? '' : $out;
    }
}
