<?php

declare(strict_types=1);

namespace Banque;

/**
 * Table « parametres » (clé → valeur). Seul réglage aujourd'hui : « thème ».
 */
final class SettingsRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $settings = [];
        foreach ($this->db->all('SELECT cle, valeur FROM parametres ORDER BY cle') as $row) {
            $settings[(string) $row['cle']] = (string) $row['valeur'];
        }

        return $settings;
    }

    public function set(string $key, string $value): void
    {
        $this->db->run('INSERT INTO parametres (cle, valeur) VALUES (?, ?) ON CONFLICT (cle) DO UPDATE SET valeur = excluded.valeur', [$key, $value]);
    }

    /**
     * @param array<mixed> $settings
     */
    public function setAll(array $settings): void
    {
        $this->db->transaction(function () use ($settings): void {
            foreach ($settings as $key => $value) {
                if (is_string($value)) {
                    $this->set((string) $key, $value);
                }
            }
        });
    }
}
