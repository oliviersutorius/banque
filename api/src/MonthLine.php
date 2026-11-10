<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Une ligne d'un fichier mensuel, sous sa forme JSON :
 * { "date", "libelle", "catégorie", "dépense" | "revenu" }
 */
final class MonthLine
{
    /**
     * @param string|array{catégorie1: string, catégorie2: string} $category
     * @return array<string, mixed>
     */
    public static function build(string $date, string $libelle, string|array $category, Direction $direction, string $amount): array
    {
        return ['date' => $date, 'libelle' => $libelle, 'catégorie' => $category, $direction->value => $amount];
    }

    /**
     * @param array<mixed> $line
     */
    public static function direction(array $line): Direction
    {
        return array_key_exists(Direction::Expense->value, $line) ? Direction::Expense : Direction::Income;
    }

    /**
     * @param array<mixed> $line
     */
    public static function amount(array $line): string
    {
        return (string) $line[self::direction($line)->value];
    }

    /**
     * Clé de doublon : même date, même libellé, même sens et même montant.
     *
     * @param array<mixed> $line
     */
    public static function key(array $line): string
    {
        return implode("\x1F", [$line['date'], $line['libelle'], self::direction($line)->value, self::amount($line)]);
    }

    /**
     * @param array<mixed> $line
     */
    public static function isUncategorized(array $line): bool
    {
        $category = $line['catégorie'] ?? null;

        return (is_array($category) ? ($category['catégorie1'] ?? null) : $category) === DefaultData::UNCATEGORIZED;
    }

    /**
     * Date DD/MM/YYYY → clé triable YYYYMMDD.
     */
    public static function sortableDate(string $date): string
    {
        return substr($date, 6, 4) . substr($date, 3, 2) . substr($date, 0, 2);
    }

    /**
     * Contrôle une ligne envoyée par le front et la remet sous sa forme JSON.
     *
     * @param array<mixed> $input { date, libelle, sens, montant, catégorie }
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    public static function fromInput(array $input, CategoryCatalog $catalog): array
    {
        $date = $input['date'] ?? null;
        if (!is_string($date) || preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            throw new InvalidArgumentException('date invalide');
        }
        $libelle = $input['libelle'] ?? null;
        if (!is_string($libelle) || trim($libelle) === '' || mb_strlen($libelle) > 1000) {
            throw new InvalidArgumentException('libellé invalide');
        }
        $direction = Direction::tryFrom(is_string($input['sens'] ?? null) ? $input['sens'] : '');
        if ($direction === null) {
            throw new InvalidArgumentException('sens invalide');
        }
        $amount = $input['montant'] ?? null;
        if (!is_string($amount) || preg_match('/^\d{1,9},\d{2}$/', $amount) !== 1 || $amount === '0,00') {
            throw new InvalidArgumentException('montant invalide');
        }

        return self::build($date, $libelle, self::category($input['catégorie'] ?? null, $direction, $catalog), $direction, $amount);
    }

    /**
     * Contrôle qu'une catégorie a la bonne forme pour le sens et qu'elle existe.
     *
     * @return string|array{catégorie1: string, catégorie2: string}
     * @throws InvalidArgumentException
     */
    public static function category(mixed $category, Direction $direction, CategoryCatalog $catalog): string|array
    {
        if ($direction === Direction::Expense) {
            $cat1 = is_array($category) ? ($category['catégorie1'] ?? null) : null;
            $cat2 = is_array($category) ? ($category['catégorie2'] ?? null) : null;
            if (!is_string($cat1) || !is_string($cat2) || !$catalog->hasExpense($cat1, $cat2)) {
                throw new InvalidArgumentException('catégorie de dépense inconnue');
            }
            return ['catégorie1' => $cat1, 'catégorie2' => $cat2];
        }
        if (!is_string($category) || !$catalog->hasIncome($category)) {
            throw new InvalidArgumentException('catégorie de revenu inconnue');
        }

        return $category;
    }
}
