<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Tables « regles » et « regle_mots ». Une règle est désignée par son identifiant ; l'ordre des identifiants départage
 * deux règles de même priorité.
 */
final class RuleRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * @return list<Rule> dans l'ordre des identifiants
     */
    public function all(): array
    {
        $words = [];
        foreach ($this->db->all('SELECT regle_id, mot FROM regle_mots ORDER BY regle_id, position') as $row) {
            $words[(int) $row['regle_id']][] = (string) $row['mot'];
        }
        $rows = $this->db->all(<<<'SQL'
            SELECT r.id, r.motif, r.sens, r.origine, r.type_operation, r.a_confirmer, c.nom AS categorie, p.nom AS parent
            FROM regles r
            JOIN categories c ON c.id = r.categorie_id
            LEFT JOIN categories p ON p.id = c.parent_id
            ORDER BY r.id
            SQL);

        return array_map(static function (array $row) use ($words): Rule {
            $direction = Direction::from((string) $row['sens']);

            return new Rule(
                (string) $row['motif'],
                $direction,
                $direction === Direction::Expense ? (string) $row['parent'] : (string) $row['categorie'],
                $direction === Direction::Expense ? (string) $row['categorie'] : null,
                (string) $row['origine'],
                $words[(int) $row['id']] ?? [],
                $row['type_operation'] === null ? null : (string) $row['type_operation'],
                $row['a_confirmer'] === 1,
                (int) $row['id'],
            );
        }, $rows);
    }

    public function find(int $id): ?Rule
    {
        foreach ($this->all() as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return Rule la règle enregistrée, avec son identifiant
     */
    public function insert(Rule $rule): Rule
    {
        return $this->db->transaction(function () use ($rule): Rule {
            $this->db->run(
                'INSERT INTO regles (motif, sens, categorie_id, origine, type_operation, a_confirmer) VALUES (?, ?, ?, ?, ?, ?)',
                [$rule->motif, $rule->direction->value, $this->leaf($rule), $rule->origin, $rule->operationType, $rule->toConfirm ? 1 : 0],
            );
            $saved = $rule->withId($this->db->lastId());
            $this->saveWords($saved);

            return $saved;
        });
    }

    /**
     * @param list<Rule> $rules
     */
    public function insertAll(array $rules): void
    {
        $this->db->transaction(function () use ($rules): void {
            foreach ($rules as $rule) {
                $this->insert($rule);
            }
        });
    }

    /**
     * @param list<int> $categoryIds
     */
    public function countInCategories(array $categoryIds): int
    {
        return $categoryIds === [] ? 0 : (int) $this->db->value(
            'SELECT count(*) FROM regles WHERE categorie_id IN (' . implode(', ', array_fill(0, count($categoryIds), '?')) . ')',
            $categoryIds,
        );
    }

    /**
     * @param list<int> $categoryIds
     * @return int nombre de règles déplacées
     */
    public function moveCategories(array $categoryIds, int $target): int
    {
        return $categoryIds === [] ? 0 : $this->db->run(
            'UPDATE regles SET categorie_id = ? WHERE categorie_id IN (' . implode(', ', array_fill(0, count($categoryIds), '?')) . ')',
            [$target, ...$categoryIds],
        )->rowCount();
    }

    /**
     * Construit une règle apprise à partir d'une saisie et vérifie que sa catégorie existe.
     *
     * @param array<mixed> $input { motif, type, catégorie1 + catégorie2 | catégorie, et?, type_operation? }
     * @throws InvalidArgumentException
     */
    public static function learnedFromInput(array $input, CategoryCatalog $catalog): Rule
    {
        return self::fromInput(['a_confirmer' => false] + $input, $catalog, Rule::ORIGIN_LEARNED);
    }

    /**
     * Construit une règle à partir d'une saisie, avec l'origine imposée, et vérifie sa catégorie.
     *
     * @param array<mixed> $input { motif, type, catégorie1 + catégorie2 | catégorie, et?, type_operation?, a_confirmer? }
     * @throws InvalidArgumentException
     */
    public static function fromInput(array $input, CategoryCatalog $catalog, string $origin): Rule
    {
        $rule = Rule::fromArray(['origine' => $origin] + $input);
        $exists = $rule->direction === Direction::Expense
            ? $catalog->hasExpense($rule->category1, (string) $rule->category2)
            : $catalog->hasIncome($rule->category1);
        if (!$exists) {
            throw new InvalidArgumentException('La catégorie de la règle n\'existe pas');
        }
        if (mb_strlen($rule->motif) > 200 || count($rule->and) > 5) {
            throw new InvalidArgumentException('Motif trop long');
        }
        if ($rule->operationType !== null && !in_array($rule->operationType, LabelNormalizer::typeCodes(), true)) {
            throw new InvalidArgumentException('Type d\'opération inconnu : ' . $rule->operationType);
        }
        $and = array_values(array_filter($rule->and, static fn (string $m): bool => Text::canonical($m) !== ''));

        return new Rule(
            $rule->motif,
            $rule->direction,
            $rule->category1,
            $rule->category2,
            $origin,
            $and,
            $rule->operationType,
            $rule->toConfirm,
        );
    }

    /**
     * Remplace une règle, en gardant son identifiant et son origine.
     *
     * @param array<mixed> $input
     * @throws HttpException 404 si la règle n'existe plus
     */
    public function update(int $id, array $input, CategoryCatalog $catalog): Rule
    {
        $current = $this->find($id) ?? throw new HttpException(404, 'Règle introuvable : rechargez la page');
        $rule = self::fromInput($input, $catalog, $current->origin)->withId($id);
        $this->replace($rule);

        return $rule;
    }

    /**
     * @throws HttpException 404 si la règle n'existe plus
     */
    public function delete(int $id): void
    {
        if ($this->db->run('DELETE FROM regles WHERE id = ?', [$id])->rowCount() === 0) {
            throw new HttpException(404, 'Règle introuvable : rechargez la page');
        }
    }

    /**
     * Ajoute une règle apprise. Une règle apprise ayant les mêmes conditions est remplacée (elle garde son
     * identifiant, donc sa place).
     *
     * @return array{règle: Rule, remplacée: bool}
     */
    public function learn(Rule $rule): array
    {
        return $this->db->transaction(function () use ($rule): array {
            foreach ($this->all() as $existing) {
                if ($existing->origin === Rule::ORIGIN_LEARNED && self::sameConditions($existing, $rule)) {
                    $saved = $rule->withId((int) $existing->id);
                    $this->replace($saved);

                    return ['règle' => $saved, 'remplacée' => true];
                }
            }

            return ['règle' => $this->insert($rule), 'remplacée' => false];
        });
    }

    private function replace(Rule $rule): void
    {
        $this->db->transaction(function () use ($rule): void {
            $this->db->run(
                'UPDATE regles SET motif = ?, sens = ?, categorie_id = ?, origine = ?, type_operation = ?, a_confirmer = ? WHERE id = ?',
                [$rule->motif, $rule->direction->value, $this->leaf($rule), $rule->origin, $rule->operationType, $rule->toConfirm ? 1 : 0, $rule->id],
            );
            $this->db->run('DELETE FROM regle_mots WHERE regle_id = ?', [$rule->id]);
            $this->saveWords($rule);
        });
    }

    private function saveWords(Rule $rule): void
    {
        foreach ($rule->and as $position => $word) {
            $this->db->run('INSERT INTO regle_mots (regle_id, position, mot) VALUES (?, ?, ?)', [$rule->id, $position, $word]);
        }
    }

    private function leaf(Rule $rule): int
    {
        return $this->categories->leafId(
            $rule->direction,
            $rule->category2 === null ? $rule->category1 : ['catégorie1' => $rule->category1, 'catégorie2' => $rule->category2],
        );
    }

    private static function sameConditions(Rule $a, Rule $b): bool
    {
        $words = static fn (Rule $r): array => array_map([Text::class, 'canonical'], [$r->motif, ...$r->and]);

        return $a->direction === $b->direction
            && $a->operationType === $b->operationType
            && $words($a) === $words($b);
    }
}
