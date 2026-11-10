<?php

declare(strict_types=1);

namespace Banque;

/**
 * Applique les règles à une ligne :
 *
 * 1. les règles apprises passent avant les règles initiales
 * 2. à priorité égale, le motif le plus long gagne (puis l'ordre du fichier)
 * 3. une règle ne s'applique qu'au sens d'opération qui lui correspond
 * 4. une règle qui pointe vers une catégorie inexistante est ignorée : aucune catégorie n'est inventée
 *
 * Sans règle, la catégorie se déduit du type d'opération, sinon « À catégoriser ».
 */
final class Categorizer
{
    /** Type d'opération => [sens, catégorie 1, catégorie 2] */
    private const BY_OPERATION_TYPE = [
        'RETRAIT' => [Direction::Expense, "Retraits d'espèces", 'Distributeur'],
        'VIREMENT_EMIS' => [Direction::Expense, 'Épargne & virements', 'Virement vers un autre compte'],
        'VIREMENT_RECU' => [Direction::Income, 'Virements reçus', null],
    ];

    /** @var list<Rule> règles triées par priorité décroissante */
    private readonly array $rules;

    /**
     * @param list<Rule> $rules
     */
    public function __construct(
        array $rules,
        private readonly CategoryCatalog $catalog,
        private readonly LabelNormalizer $normalizer = new LabelNormalizer(),
    ) {
        $this->rules = self::sortByPriority($rules);
    }

    public function categorize(string $libelle, Direction $direction): Categorization
    {
        $label = $this->normalizer->normalize($libelle);

        foreach ($this->rules as $rule) {
            if ($rule->matches($label, $direction) && $this->exists($direction, $rule->category1, $rule->category2)) {
                return new Categorization($rule->category1, $rule->category2, Categorization::SOURCE_RULE, $rule);
            }
        }

        $byType = self::BY_OPERATION_TYPE[$label->type] ?? null;
        if ($byType !== null && $byType[0] === $direction && $this->exists($direction, $byType[1], $byType[2])) {
            return new Categorization($byType[1], $byType[2], Categorization::SOURCE_OPERATION_TYPE);
        }

        return new Categorization(
            DefaultData::UNCATEGORIZED,
            $direction === Direction::Expense ? DefaultData::UNCATEGORIZED_SUB : null,
            Categorization::SOURCE_NONE,
        );
    }

    /**
     * @param list<Rule> $rules
     * @return list<Rule>
     */
    private static function sortByPriority(array $rules): array
    {
        $indexed = array_map(null, $rules, array_keys($rules));
        usort($indexed, static function (array $a, array $b): int {
            /** @var array{Rule, int} $a */
            /** @var array{Rule, int} $b */
            return [$b[0]->origin === Rule::ORIGIN_LEARNED, $b[0]->specificity(), $a[1]]
                <=> [$a[0]->origin === Rule::ORIGIN_LEARNED, $a[0]->specificity(), $b[1]];
        });

        return array_map(static fn (array $pair): Rule => $pair[0], $indexed);
    }

    private function exists(Direction $direction, string $category1, ?string $category2): bool
    {
        return $direction === Direction::Expense
            ? $this->catalog->hasExpense($category1, (string) $category2)
            : $this->catalog->hasIncome($category1);
    }
}
