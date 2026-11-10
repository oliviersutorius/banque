<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Modifications de categories.json, répercutées dans tous les fichiers mensuels et dans les règles.
 *
 * Une catégorie est désignée par son sens et son chemin : [catégorie1] ou [catégorie1, catégorie2].
 * « À catégoriser » (et sa sous-catégorie « Non classé ») ne peut être ni renommée ni supprimée.
 */
final class CategoryManager
{
    private const MAX_NAME_LENGTH = 60;

    public function __construct(
        private readonly string $path,
        private readonly JsonFile $json,
        private readonly MonthRepository $months,
        private readonly RuleRepository $rules,
    ) {
    }

    /**
     * @return array{dépenses: list<array<string, mixed>>, revenus: list<array<string, mixed>>}
     */
    public function all(): array
    {
        /** @var array{dépenses: list<array<string, mixed>>, revenus: list<array<string, mixed>>} */
        return $this->json->read($this->path);
    }

    /**
     * Nombre de lignes enregistrées et de règles qui utilisent une catégorie (ou une de ses sous-catégories).
     *
     * @param list<string> $path
     * @return array{lignes: int, règles: int}
     */
    public function usage(Direction $direction, array $path): array
    {
        $lines = 0;
        foreach ($this->months->months() as $month) {
            foreach ($this->months->lines($month) as $line) {
                if (MonthLine::direction($line) === $direction && self::lineMatches($line, $path)) {
                    $lines++;
                }
            }
        }
        $rules = count(array_filter($this->rules->all(), static fn (Rule $r): bool => self::ruleMatches($r, $direction, $path)));

        return ['lignes' => $lines, 'règles' => $rules];
    }

    /**
     * Ajoute une catégorie 1 (dépense : avec sa première sous-catégorie) ou une catégorie 2.
     *
     * @param list<string> $path [catégorie1] ou [catégorie1, catégorie2]
     */
    public function add(Direction $direction, array $path, string $icon, ?string $firstSub = null): void
    {
        $data = $this->all();
        $name = self::validName(end($path) ?: '');

        if ($direction === Direction::Income) {
            self::assertDepth($path, 1);
            if ($this->find($data, $direction, $name) !== null) {
                throw new InvalidArgumentException('La catégorie « ' . $name . ' » existe déjà');
            }
            $data['revenus'][] = ['nom' => $name, 'icone' => self::validIcon($icon)];
        } elseif (count($path) === 1) {
            if ($this->find($data, $direction, $name) !== null) {
                throw new InvalidArgumentException('La catégorie « ' . $name . ' » existe déjà');
            }
            $data['dépenses'][] = ['nom' => $name, 'icone' => self::validIcon($icon), 'sous_catégories' => [self::validName((string) $firstSub)]];
        } else {
            self::assertDepth($path, 2);
            $i = $this->find($data, $direction, $path[0]) ?? throw new InvalidArgumentException('Catégorie inconnue : ' . $path[0]);
            if (in_array($name, $data['dépenses'][$i]['sous_catégories'], true)) {
                throw new InvalidArgumentException('La sous-catégorie « ' . $name . ' » existe déjà');
            }
            $data['dépenses'][$i]['sous_catégories'][] = $name;
        }
        $this->json->write($this->path, $data);
    }

    /**
     * @param list<string> $path catégorie 1 uniquement
     */
    public function changeIcon(Direction $direction, array $path, string $icon): void
    {
        self::assertDepth($path, 1);
        $data = $this->all();
        $key = self::key($direction);
        $i = $this->find($data, $direction, $path[0]) ?? throw new InvalidArgumentException('Catégorie inconnue : ' . $path[0]);
        $data[$key][$i]['icone'] = self::validIcon($icon);
        $this->json->write($this->path, $data);
    }

    /**
     * Renomme une catégorie partout : categories.json, fichiers mensuels et règles.
     *
     * @param list<string> $path
     * @return array{lignes: int, règles: int} éléments mis à jour
     */
    public function rename(Direction $direction, array $path, string $newName): array
    {
        $data = $this->all();
        $newName = self::validName($newName);
        $this->assertModifiable($path);
        $key = self::key($direction);
        $i = $this->find($data, $direction, $path[0]) ?? throw new InvalidArgumentException('Catégorie inconnue : ' . $path[0]);

        if (count($path) === 1) {
            if ($newName !== $path[0] && $this->find($data, $direction, $newName) !== null) {
                throw new InvalidArgumentException('La catégorie « ' . $newName . ' » existe déjà');
            }
            $data[$key][$i]['nom'] = $newName;
            $target = [$newName];
        } else {
            self::assertDepth($path, 2);
            $subs = $data[$key][$i]['sous_catégories'];
            $j = array_search($path[1], $subs, true);
            if ($j === false) {
                throw new InvalidArgumentException('Sous-catégorie inconnue : ' . $path[1]);
            }
            if ($newName !== $path[1] && in_array($newName, $subs, true)) {
                throw new InvalidArgumentException('La sous-catégorie « ' . $newName . ' » existe déjà');
            }
            $data[$key][$i]['sous_catégories'][$j] = $newName;
            $target = [$path[0], $newName];
        }

        // Une catégorie 1 renommée garde ses sous-catégories : seul le premier niveau change
        $result = $this->replaceEverywhere($direction, $path, $target, keepSub: count($path) === 1);
        $this->json->write($this->path, $data);

        return $result;
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
        $data = $this->all();
        $this->assertModifiable($path);
        $key = self::key($direction);
        $i = $this->find($data, $direction, $path[0]) ?? throw new InvalidArgumentException('Catégorie inconnue : ' . $path[0]);

        if (count($path) === 2) {
            $subs = $data[$key][$i]['sous_catégories'];
            if (!in_array($path[1], $subs, true)) {
                throw new InvalidArgumentException('Sous-catégorie inconnue : ' . $path[1]);
            }
            if (count($subs) === 1) {
                throw new InvalidArgumentException('C\'est la dernière sous-catégorie : supprime plutôt « ' . $path[0] . ' »');
            }
        }

        $usage = $this->usage($direction, $path);
        if ($usage['lignes'] + $usage['règles'] > 0) {
            if ($replacement === null) {
                throw new InvalidArgumentException('Catégorie utilisée : choisis une catégorie de remplacement');
            }
            $catalog = CategoryCatalog::fromArray($data);
            $exists = $direction === Direction::Expense
                ? count($replacement) === 2 && $catalog->hasExpense($replacement[0], $replacement[1])
                : count($replacement) === 1 && $catalog->hasIncome($replacement[0]);
            $inside = $replacement[0] === $path[0] && (count($path) === 1 || ($replacement[1] ?? null) === $path[1]);
            if (!$exists || $inside) {
                throw new InvalidArgumentException('Catégorie de remplacement invalide');
            }
            $result = $this->replaceEverywhere($direction, $path, $replacement, keepSub: false);
        } else {
            $result = $usage;
        }

        if (count($path) === 1) {
            array_splice($data[$key], $i, 1);
        } else {
            $data[$key][$i]['sous_catégories'] = array_values(array_filter(
                $data[$key][$i]['sous_catégories'],
                static fn (string $s): bool => $s !== $path[1],
            ));
        }
        $this->json->write($this->path, $data);

        return $result;
    }

    /**
     * @param list<string> $from
     * @param list<string> $to
     * @return array{lignes: int, règles: int}
     */
    private function replaceEverywhere(Direction $direction, array $from, array $to, bool $keepSub): array
    {
        $build = static function (?string $currentSub) use ($direction, $to, $keepSub): string|array {
            if ($direction === Direction::Income) {
                return $to[0];
            }
            return ['catégorie1' => $to[0], 'catégorie2' => $keepSub ? (string) $currentSub : $to[1]];
        };

        $lines = 0;
        foreach ($this->months->months() as $month) {
            $monthLines = $this->months->lines($month);
            $changed = false;
            foreach ($monthLines as $k => $line) {
                if (MonthLine::direction($line) === $direction && self::lineMatches($line, $from)) {
                    $category = $line['catégorie'];
                    $monthLines[$k]['catégorie'] = $build(is_array($category) ? $category['catégorie2'] : null);
                    $changed = true;
                    $lines++;
                }
            }
            if ($changed) {
                $this->months->save($month, $monthLines);
            }
        }

        $rules = $this->rules->all();
        $count = 0;
        foreach ($rules as $k => $rule) {
            if (self::ruleMatches($rule, $direction, $from)) {
                $category = $build($rule->category2);
                $rules[$k] = new Rule(
                    $rule->motif,
                    $rule->direction,
                    is_array($category) ? $category['catégorie1'] : $category,
                    is_array($category) ? $category['catégorie2'] : null,
                    $rule->origin,
                    $rule->and,
                    $rule->operationType,
                    $rule->toConfirm,
                );
                $count++;
            }
        }
        if ($count > 0) {
            $this->rules->saveAll($rules);
        }

        return ['lignes' => $lines, 'règles' => $count];
    }

    /**
     * @param array<mixed> $line
     * @param list<string> $path
     */
    private static function lineMatches(array $line, array $path): bool
    {
        $category = $line['catégorie'] ?? null;
        if (!is_array($category)) {
            return $category === $path[0];
        }

        return ($category['catégorie1'] ?? null) === $path[0]
            && (count($path) === 1 || ($category['catégorie2'] ?? null) === $path[1]);
    }

    /**
     * @param list<string> $path
     */
    private static function ruleMatches(Rule $rule, Direction $direction, array $path): bool
    {
        return $rule->direction === $direction
            && $rule->category1 === $path[0]
            && (count($path) === 1 || $rule->category2 === $path[1]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function find(array $data, Direction $direction, string $name): ?int
    {
        foreach ($data[self::key($direction)] as $i => $category) {
            if ($category['nom'] === $name) {
                return $i;
            }
        }

        return null;
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

    private static function key(Direction $direction): string
    {
        return $direction === Direction::Expense ? 'dépenses' : 'revenus';
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
