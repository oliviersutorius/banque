<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Liste des catégories existantes (contenu de categories.json).
 */
final class CategoryCatalog
{
    /**
     * @param array<string, list<string>> $expenses catégorie 1 => sous-catégories
     * @param list<string> $incomes
     */
    private function __construct(
        private readonly array $expenses,
        private readonly array $incomes,
    ) {
    }

    /**
     * @param array<mixed> $data contenu décodé de categories.json
     * @throws InvalidArgumentException si la structure est invalide
     */
    public static function fromArray(array $data): self
    {
        if (!is_array($data['dépenses'] ?? null) || !is_array($data['revenus'] ?? null)) {
            throw new InvalidArgumentException('categories.json : clés « dépenses » et « revenus » attendues');
        }

        $expenses = [];
        foreach ($data['dépenses'] as $category) {
            $name = is_array($category) ? ($category['nom'] ?? null) : null;
            $subs = is_array($category) ? ($category['sous_catégories'] ?? null) : null;
            if (!is_string($name) || !is_array($subs) || array_filter($subs, 'is_string') !== $subs) {
                throw new InvalidArgumentException('categories.json : catégorie de dépense invalide');
            }
            $expenses[$name] = array_values($subs);
        }

        $incomes = [];
        foreach ($data['revenus'] as $category) {
            $name = is_array($category) ? ($category['nom'] ?? null) : null;
            if (!is_string($name)) {
                throw new InvalidArgumentException('categories.json : catégorie de revenu invalide');
            }
            $incomes[] = $name;
        }

        return new self($expenses, $incomes);
    }

    public function hasExpense(string $category1, string $category2): bool
    {
        return in_array($category2, $this->expenses[$category1] ?? [], true);
    }

    public function hasIncome(string $category): bool
    {
        return in_array($category, $this->incomes, true);
    }
}
