<?php

declare(strict_types=1);

namespace Banque;

/**
 * Journal des connexions (data/journal/connexions.log), une ligne JSON par évènement :
 * {"date":"2026-11-14T21:30:00+01:00","evenement":"connexion","ip":"203.0.113.5"}
 *
 * N'y écrire ni mot de passe, ni code, ni donnée bancaire. Rotation : logrotate sur le serveur.
 */
final class AuditLog
{
    public function __construct(private readonly string $file)
    {
    }

    /**
     * @param array<string, scalar|null> $details
     */
    public function record(string $event, string $ip, array $details = []): void
    {
        $line = json_encode(
            ['date' => date(DATE_ATOM), 'evenement' => $event, 'ip' => $ip] + $details,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('Journal des connexions inaccessible : ' . $line);
        }
    }
}
