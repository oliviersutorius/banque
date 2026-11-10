<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Lecture et écriture de data/regles.json.
 */
final class RuleRepository
{
    public function __construct(
        private readonly string $path,
        private readonly JsonFile $json,
    ) {
    }

    /**
     * @return list<Rule>
     */
    public function all(): array
    {
        return Rule::listFromArray($this->json->read($this->path));
    }

    /**
     * @param list<Rule> $rules
     */
    public function saveAll(array $rules): void
    {
        $this->json->write($this->path, array_map(static fn (Rule $r): array => $r->toArray(), $rules));
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
     * Remplace la règle d'un index, en gardant son origine.
     *
     * @param array<mixed> $input
     * @throws LineConflictException si la règle de cet index n'a plus le motif attendu
     */
    public function update(int $index, string $expectedMotif, array $input, CategoryCatalog $catalog): Rule
    {
        $rules = $this->all();
        $current = $this->at($rules, $index, $expectedMotif);
        $rules[$index] = self::fromInput($input, $catalog, $current->origin);
        $this->saveAll($rules);

        return $rules[$index];
    }

    /**
     * @throws LineConflictException si la règle de cet index n'a plus le motif attendu
     */
    public function delete(int $index, string $expectedMotif): void
    {
        $rules = $this->all();
        $this->at($rules, $index, $expectedMotif);
        array_splice($rules, $index, 1);
        $this->saveAll($rules);
    }

    /**
     * @param list<Rule> $rules
     */
    private function at(array $rules, int $index, string $expectedMotif): Rule
    {
        $rule = $rules[$index] ?? throw new InvalidArgumentException('Règle introuvable : ' . $index);
        if ($rule->motif !== $expectedMotif) {
            throw new LineConflictException('Les règles ont changé : recharge la page');
        }

        return $rule;
    }

    /**
     * Ajoute une règle apprise. Une règle apprise ayant les mêmes conditions est remplacée.
     *
     * @return bool true si une règle existante a été remplacée
     */
    public function learn(Rule $rule): bool
    {
        $rules = $this->all();
        foreach ($rules as $i => $existing) {
            if ($existing->origin === Rule::ORIGIN_LEARNED && self::sameConditions($existing, $rule)) {
                $rules[$i] = $rule;
                $this->saveAll($rules);
                return true;
            }
        }
        $rules[] = $rule;
        $this->saveAll($rules);

        return false;
    }

    private static function sameConditions(Rule $a, Rule $b): bool
    {
        $words = static fn (Rule $r): array => array_map([Text::class, 'canonical'], [$r->motif, ...$r->and]);

        return $a->direction === $b->direction
            && $a->operationType === $b->operationType
            && $words($a) === $words($b);
    }
}
