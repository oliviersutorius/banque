<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\Direction;
use Banque\LabelNormalizer;
use Banque\Rule;
use InvalidArgumentException;

final class RuleTest extends TestCase
{
    public function testLitUneRegleDeDepenseEtDeRevenu(): void
    {
        $expense = Rule::fromArray(['motif' => 'AUCHAN', 'type' => 'dépense', 'catégorie1' => 'Alimentation', 'catégorie2' => 'Courses', 'origine' => 'initiale']);
        $income = Rule::fromArray(['motif' => 'MUTUELLE', 'type' => 'revenu', 'catégorie' => 'Remboursements santé', 'origine' => 'apprise']);

        $this->assertSame(Direction::Expense, $expense->direction);
        $this->assertSame('Courses', $expense->category2);
        $this->assertSame(null, $income->category2);
        $this->assertSame(Rule::ORIGIN_LEARNED, $income->origin);
    }

    public function testRefuseLesReglesMalFormees(): void
    {
        $invalid = [
            ['motif' => '', 'type' => 'dépense', 'catégorie1' => 'A', 'catégorie2' => 'B', 'origine' => 'initiale'],
            ['motif' => 'X', 'type' => 'achat', 'catégorie1' => 'A', 'catégorie2' => 'B', 'origine' => 'initiale'],
            ['motif' => 'X', 'type' => 'dépense', 'catégorie1' => 'A', 'origine' => 'initiale'],
            ['motif' => 'X', 'type' => 'revenu', 'catégorie' => 'A', 'origine' => 'inventée'],
            ['motif' => 'X', 'type' => 'revenu', 'catégorie' => 'A', 'origine' => 'apprise', 'et' => 'ASSU'],
        ];
        foreach ($invalid as $data) {
            $this->assertThrows(InvalidArgumentException::class, static fn () => Rule::fromArray($data));
        }
    }

    public function testListeIndiqueLeNumeroDeLaRegleFautive(): void
    {
        try {
            Rule::listFromArray([
                ['motif' => 'OK', 'type' => 'revenu', 'catégorie' => 'A', 'origine' => 'apprise'],
                ['motif' => 'KO', 'type' => 'revenu', 'origine' => 'apprise'],
            ]);
            $this->assertTrue(false, 'une exception était attendue');
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(str_starts_with($e->getMessage(), 'Règle n° 2'), $e->getMessage());
        }
    }

    public function testLeMotifEstInsensibleALaCasseEtAuxAccents(): void
    {
        $rule = new Rule('électricité', Direction::Expense, 'Logement', 'Électricité & gaz', Rule::ORIGIN_LEARNED);

        $this->assertSame(11, $rule->specificity());
        $this->assertTrue($rule->matches((new LabelNormalizer())->normalize('Prélèvement PRELEVEMENT ELECTRICITE DE FRANCE'), Direction::Expense));
    }
}
