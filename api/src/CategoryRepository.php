<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Table « categories » : arbre à deux niveaux.
 *
 * Une catégorie est désignée par son sens et son chemin : [catégorie1] ou [catégorie1, catégorie2]. Les opérations et
 * les règles pointent vers une « feuille » : sous-catégorie pour une dépense, catégorie 1 pour un revenu.
 */
final class CategoryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * L'arbre sous la forme de l'API (et de l'ancien categories.json), dans l'ordre des positions.
     *
     * @return array{dépenses: list<array{nom: string, icone: string, sous_catégories: list<string>}>, revenus: list<array{nom: string, icone: string}>}
     */
    public function tree(): array
    {
        $rows = $this->db->all('SELECT id, sens, parent_id, nom, icone FROM categories ORDER BY parent_id IS NOT NULL, position, id');
        $tree = ['dépenses' => [], 'revenus' => []];
        $expenseIndex = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] === null) {
                if ($row['sens'] === Direction::Expense->value) {
                    $expenseIndex[$row['id']] = count($tree['dépenses']);
                    $tree['dépenses'][] = ['nom' => (string) $row['nom'], 'icone' => (string) $row['icone'], 'sous_catégories' => []];
                } else {
                    $tree['revenus'][] = ['nom' => (string) $row['nom'], 'icone' => (string) $row['icone']];
                }
                continue;
            }
            $tree['dépenses'][$expenseIndex[$row['parent_id']]]['sous_catégories'][] = (string) $row['nom'];
        }

        return $tree;
    }

    public function catalog(): CategoryCatalog
    {
        return CategoryCatalog::fromArray($this->tree());
    }

    public function isEmpty(): bool
    {
        return $this->db->value('SELECT count(*) FROM categories') === 0;
    }

    /**
     * @param list<string> $path [catégorie1] ou [catégorie1, catégorie2]
     */
    public function id(Direction $direction, array $path): ?int
    {
        $id = count($path) === 1
            ? $this->db->value('SELECT id FROM categories WHERE sens = ? AND parent_id IS NULL AND nom = ?', [$direction->value, $path[0]])
            : $this->db->value(
                'SELECT c.id FROM categories c JOIN categories p ON p.id = c.parent_id WHERE c.sens = ? AND p.nom = ? AND c.nom = ?',
                [$direction->value, $path[0], $path[1] ?? ''],
            );

        return $id === null ? null : (int) $id;
    }

    /**
     * Identifiant de la feuille correspondant à une catégorie sous sa forme JSON.
     *
     * @param string|array{catégorie1: string, catégorie2: string} $category
     * @throws InvalidArgumentException si elle n'existe pas pour ce sens
     */
    public function leafId(Direction $direction, string|array $category): int
    {
        $path = is_array($category) ? [$category['catégorie1'], $category['catégorie2']] : [$category];
        if (count($path) !== ($direction === Direction::Expense ? 2 : 1)) {
            throw new InvalidArgumentException('Catégorie de forme incompatible avec le sens');
        }

        return $this->id($direction, $path) ?? throw new InvalidArgumentException('Catégorie inconnue : ' . implode(' › ', $path));
    }

    /**
     * Feuilles couvertes par un chemin : la feuille elle-même, ou toutes les sous-catégories d'une catégorie 1 de
     * dépense.
     *
     * @param list<string> $path
     * @return list<int>
     */
    public function leafIds(Direction $direction, array $path): array
    {
        $id = $this->id($direction, $path);
        if ($id === null) {
            return [];
        }
        if ($direction === Direction::Income || count($path) === 2) {
            return [$id];
        }

        return array_map('intval', array_column($this->db->all('SELECT id FROM categories WHERE parent_id = ?', [$id]), 'id'));
    }

    /**
     * @return list<string> noms des sous-catégories d'une catégorie 1 de dépense
     */
    public function subNames(int $parentId): array
    {
        return array_map('strval', array_column($this->db->all('SELECT nom FROM categories WHERE parent_id = ? ORDER BY position, id', [$parentId]), 'nom'));
    }

    /**
     * Identifiants des feuilles « À catégoriser » (dépense : « À catégoriser › Non classé », revenu : « À catégoriser »).
     *
     * @return list<int>
     */
    public function uncategorizedIds(): array
    {
        return array_values(array_filter([
            $this->id(Direction::Expense, [DefaultData::UNCATEGORIZED, DefaultData::UNCATEGORIZED_SUB]),
            $this->id(Direction::Income, [DefaultData::UNCATEGORIZED]),
        ], static fn (?int $id): bool => $id !== null));
    }

    /**
     * Ajoute une catégorie à la fin de son niveau.
     *
     * @return int son identifiant
     */
    public function insert(Direction $direction, ?int $parentId, string $name, ?string $icon): int
    {
        $position = (int) $this->db->value(
            'SELECT coalesce(max(position) + 1, 0) FROM categories WHERE sens = ? AND parent_id IS ?',
            [$direction->value, $parentId],
        );
        $protected = $name === DefaultData::UNCATEGORIZED
            || ($parentId !== null && $this->db->value('SELECT nom FROM categories WHERE id = ?', [$parentId]) === DefaultData::UNCATEGORIZED);
        $this->db->run(
            'INSERT INTO categories (sens, parent_id, nom, icone, position, protegee) VALUES (?, ?, ?, ?, ?, ?)',
            [$direction->value, $parentId, $name, $icon, $position, $protected ? 1 : 0],
        );

        return $this->db->lastId();
    }

    /**
     * Enregistre un arbre complet (base neuve : catégories par défaut, ou migration de l'ancien categories.json).
     *
     * @param array<mixed> $tree forme de tree()
     */
    public function insertTree(array $tree): void
    {
        CategoryCatalog::fromArray($tree);
        $this->db->transaction(function () use ($tree): void {
            foreach ($tree['dépenses'] as $category) {
                $parent = $this->insert(Direction::Expense, null, (string) $category['nom'], (string) ($category['icone'] ?? ''));
                foreach ($category['sous_catégories'] as $sub) {
                    $this->insert(Direction::Expense, $parent, (string) $sub, null);
                }
            }
            foreach ($tree['revenus'] as $category) {
                $this->insert(Direction::Income, null, (string) $category['nom'], (string) ($category['icone'] ?? ''));
            }
        });
    }

    public function rename(int $id, string $name): void
    {
        $this->db->run('UPDATE categories SET nom = ? WHERE id = ?', [$name, $id]);
    }

    public function changeIcon(int $id, string $icon): void
    {
        $this->db->run('UPDATE categories SET icone = ? WHERE id = ?', [$icon, $id]);
    }

    /**
     * Supprime une catégorie (et ses sous-catégories). Refusé par la base si une opération ou une règle l'utilise.
     */
    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM categories WHERE id = ?', [$id]);
    }
}
