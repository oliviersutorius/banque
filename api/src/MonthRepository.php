<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Lecture et écriture des fichiers data/mois/YYYYMM.json.
 */
final class MonthRepository
{
    public function __construct(
        private readonly string $dir,
        private readonly JsonFile $json,
    ) {
    }

    public static function isValidMonth(string $month): bool
    {
        return preg_match('/^\d{4}(0[1-9]|1[0-2])$/', $month) === 1;
    }

    /**
     * @return list<string> mois disponibles, du plus ancien au plus récent
     */
    public function months(): array
    {
        $months = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $month = basename($file, '.json');
            if (self::isValidMonth($month)) {
                $months[] = $month;
            }
        }
        sort($months);

        return $months;
    }

    public function exists(string $month): bool
    {
        return is_file($this->path($month));
    }

    /**
     * @return list<array<string, mixed>> lignes du mois (vide si le fichier n'existe pas)
     */
    public function lines(string $month): array
    {
        $path = $this->path($month);
        if (!is_file($path)) {
            return [];
        }
        $lines = $this->json->read($path)['lignes'] ?? [];

        return is_array($lines) ? array_values($lines) : [];
    }

    /**
     * Enregistre les lignes triées par date croissante ; à date égale, l'ordre reçu est conservé.
     *
     * @param list<array<string, mixed>> $lines
     */
    public function save(string $month, array $lines): void
    {
        // usort est stable depuis PHP 8.0
        usort($lines, static fn (array $a, array $b): int
            => MonthLine::sortableDate((string) $a['date']) <=> MonthLine::sortableDate((string) $b['date']));

        $this->json->write($this->path($month), ['mois' => $month, 'lignes' => $lines]);
    }

    /**
     * Applique une règle aux lignes encore « À catégoriser » de tous les mois enregistrés.
     *
     * @return int nombre de lignes mises à jour
     */
    public function applyRuleToUncategorized(Rule $rule, LabelNormalizer $normalizer): int
    {
        $category = $rule->category2 === null
            ? $rule->category1
            : ['catégorie1' => $rule->category1, 'catégorie2' => $rule->category2];
        $total = 0;

        foreach ($this->months() as $month) {
            $lines = $this->lines($month);
            $changed = 0;
            foreach ($lines as $i => $line) {
                if (MonthLine::isUncategorized($line)
                    && $rule->matches($normalizer->normalize((string) $line['libelle']), MonthLine::direction($line))) {
                    $lines[$i]['catégorie'] = $category;
                    $changed++;
                }
            }
            if ($changed > 0) {
                $this->save($month, $lines);
                $total += $changed;
            }
        }

        return $total;
    }

    /**
     * Le nom du fichier n'est jamais construit à partir d'une saisie non contrôlée.
     */
    private function path(string $month): string
    {
        if (!self::isValidMonth($month)) {
            throw new InvalidArgumentException('Mois invalide : ' . $month);
        }

        return $this->dir . '/' . $month . '.json';
    }
}
