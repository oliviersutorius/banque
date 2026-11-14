<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Modifications des catégories (ajout, icône, renommage, suppression avec remplacement).
 *
 * Les opérations et les règles pointent vers la catégorie par son identifiant : un renommage ne touche qu'une ligne
 * de la table « categories ». Une suppression déplace d'abord les opérations et règles concernées vers la catégorie
 * de remplacement.
 *
 * Une catégorie est désignée par son sens et son chemin : [catégorie1] ou [catégorie1, catégorie2].
 * « À catégoriser » (et sa sous-catégorie « Non classé ») ne peut être ni renommée ni supprimée.
 */
final class CategoryManager
{
    private const MAX_NAME_LENGTH = 60;

    public function __construct(
        private readonly Database $db,
        private readonly CategoryRepository $categories,
        private readonly OperationRepository $operations,
        private readonly RuleRepository $rules,
    ) {
    }

    /**
     * @return array{dépenses: list<array<string, mixed>>, revenus: list<array<string, mixed>>}
     */
    public function all(): array
    {
        return $this->categories->tree();
    }

    /**
     * Nombre de lignes enregistrées et de règles qui utilisent une catégorie (ou une de ses sous-catégories).
     *
     * @param list<string> $path
     * @return array{lignes: int, règles: int}
     */
    public function usage(Direction $direction, array $path): array
    {
        $leaves = $this->categories->leafIds($direction, $path);

        return ['lignes' => $this->operations->countInCategories($leaves), 'règles' => $this->rules->countInCategories($leaves)];
    }

    /**
     * Ajoute une catégorie 1 (dépense : avec sa première sous-catégorie) ou une catégorie 2.
     *
     * @param list<string> $path [catégorie1] ou [catégorie1, catégorie2]
     */
    public function add(Direction $direction, array $path, string $icon, ?string $firstSub = null): void
    {
        $name = self::validName(end($path) ?: '');

        $this->db->transaction(function () use ($direction, $path, $icon, $firstSub, $name): void {
            if ($direction === Direction::Income) {
                self::assertDepth($path, 1);
                if ($this->categories->id($direction, [$name]) !== null) {
                    throw new InvalidArgumentException('La catégorie « ' . $name . ' » existe déjà');
                }
                $this->categories->insert($direction, null, $name, self::validIcon($icon));
            } elseif (count($path) === 1) {
                if ($this->categories->id($direction, [$name]) !== null) {
                    throw new InvalidArgumentException('La catégorie « ' . $name . ' » existe déjà');
                }
                $sub = self::validName((string) $firstSub);
                $parent = $this->categories->insert($direction, null, $name, self::validIcon($icon));
                $this->categories->insert($direction, $parent, $sub, null);
            } else {
                self::assertDepth($path, 2);
                $parent = $this->categories->id($direction, [$path[0]]) ?? throw new InvalidArgumentException('Catégorie inconnue : ' . $path[0]);
                if (in_array($name, $this->categories->subNames($parent), true)) {
                    throw new InvalidArgumentException('La sous-catégorie « ' . $name . ' » existe déjà');
                }
                $this->categories->insert($direction, $parent, $name, null);
            }
        });
    }

    /**
     * @param list<string> $path catégorie 1 uniquement
     */
    public function changeIcon(Direction $direction, array $path, string $icon): void
    {
        self::assertDepth($path, 1);
        $id = $this->categories->id($direction, $path) ?? throw new InvalidArgumentException('Catégorie inconnue : ' . $path[0]);
        $this->categories->changeIcon($id, self::validIcon($icon));
    }

    /**
     * Renomme une catégorie : les opérations et les règles suivent d'elles-mêmes.
     *
     * @param list<string> $path
     * @return array{lignes: int, règles: int} éléments concernés par le renommage
     */
    public function rename(Direction $direction, array $path, string $newName): array
    {
        $newName = self::validName($newName);
        $this->assertModifiable($path);
        if (count($path) === 2) {
            self::assertDepth($path, 2);
        } else {
            self::assertDepth($path, 1);
        }

        return $this->db->transaction(function () use ($direction, $path, $newName): array {
            $parent = $this->categories->id($direction, [$path[0]]) ?? throw new InvalidArgumentException('Catégorie inconnue : ' . $path[0]);
            if (count($path) === 1) {
                if ($newName !== $path[0] && $this->categories->id($direction, [$newName]) !== null) {
                    throw new InvalidArgumentException('La catégorie « ' . $newName . ' » existe déjà');
                }
                $this->categories->rename($parent, $newName);
                $target = [$newName];
            } else {
                $id = $this->categories->id($direction, $path) ?? throw new InvalidArgumentException('Sous-catégorie inconnue : ' . $path[1]);
                if ($newName !== $path[1] && in_array($newName, $this->categories->subNames($parent), true)) {
                    throw new InvalidArgumentException('La sous-catégorie « ' . $newName . ' » existe déjà');
                }
                $this->categories->rename($id, $newName);
                $target = [$path[0], $newName];
            }

            return $this->usage($direction, $target);
        });
    }

    /**
     * Supprime une catégorie. Si des lignes ou des règles l'utilisent, elles passent dans $replacement.
     *
     * @param list<string> $path
     * @param list<string>|null $replacement catégorie existante : [revenu] ou [catégorie1, catégorie2]
     * @return array{lignes: int, règles: int} éléments déplacés
     */
    public function delete(Direction $direction, array $path, ?array $replacement): array
    {
        $this->assertModifiable($path);

        return $this->db->transaction(function () use ($direction, $path, $replacement): array {
            $parent = $this->categories->id($direction, [$path[0]]) ?? throw new InvalidArgumentException('Catégorie inconnue : ' . $path[0]);
            $id = $parent;
            if (count($path) === 2) {
                $id = $this->categories->id($direction, $path) ?? throw new InvalidArgumentException('Sous-catégorie inconnue : ' . $path[1]);
                if (count($this->categories->subNames($parent)) === 1) {
                    throw new InvalidArgumentException('C\'est la dernière sous-catégorie : supprime plutôt « ' . $path[0] . ' »');
                }
            }

            $leaves = $this->categories->leafIds($direction, $path);
            $usage = $this->usage($direction, $path);
            $result = $usage;
            if ($usage['lignes'] + $usage['règles'] > 0) {
                if ($replacement === null) {
                    throw new InvalidArgumentException('Catégorie utilisée : choisis une catégorie de remplacement');
                }
                $expected = $direction === Direction::Expense ? 2 : 1;
                $target = count($replacement) === $expected ? $this->categories->id($direction, $replacement) : null;
                $inside = $replacement[0] === $path[0] && (count($path) === 1 || ($replacement[1] ?? null) === $path[1]);
                if ($target === null || $inside) {
                    throw new InvalidArgumentException('Catégorie de remplacement invalide');
                }
                $result = [
                    'lignes' => $this->operations->moveCategories($leaves, $target),
                    'règles' => $this->rules->moveCategories($leaves, $target),
                ];
            }
            $this->categories->delete($id);

            return $result;
        });
    }

    /**
     * @param list<string> $path
     */
    private function assertModifiable(array $path): void
    {
        if (($path[0] ?? '') === DefaultData::UNCATEGORIZED) {
            throw new InvalidArgumentException('« À catégoriser » ne peut être ni renommée ni supprimée');
        }
    }

    /**
     * @param list<string> $path
     */
    private static function assertDepth(array $path, int $depth): void
    {
        if (count($path) !== $depth) {
            throw new InvalidArgumentException('Chemin de catégorie invalide');
        }
    }

    private static function validName(string $name): string
    {
        $name = Text::collapseSpaces($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('Nom de catégorie vide ou trop long (' . self::MAX_NAME_LENGTH . ' caractères maximum)');
        }

        return $name;
    }

    private static function validIcon(string $icon): string
    {
        $icon = trim($icon);
        if ($icon === '' || mb_strlen($icon) > 8) {
            throw new InvalidArgumentException('Icône invalide : un emoji est attendu');
        }

        return $icon;
    }
}
