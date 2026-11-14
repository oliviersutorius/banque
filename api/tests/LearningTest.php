<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\CategoryCatalog;
use Banque\DefaultData;
use Banque\Direction;
use Banque\CategoryRepository;
use Banque\Database;
use Banque\LabelNormalizer;
use Banque\MonthLine;
use Banque\OperationRepository;
use Banque\Rule;
use Banque\RuleRepository;
use InvalidArgumentException;

final class LearningTest extends TestCase
{
    private readonly Database $db;
    private readonly CategoryRepository $categories;

    public function __construct()
    {
        $this->db = self::memoryDatabase();
        DefaultData::seed($this->db);
        $this->categories = new CategoryRepository($this->db);
    }

    public function testRegleAppriseDepuisUneSaisie(): void
    {
        $rule = RuleRepository::learnedFromInput(
            ['motif' => 'AUCHAN', 'type' => 'dépense', 'catégorie1' => 'Alimentation', 'catégorie2' => 'Courses', 'origine' => 'initiale', 'a_confirmer' => true],
            self::catalog(),
        );

        $this->assertSame(Rule::ORIGIN_LEARNED, $rule->origin, 'l\'origine est toujours « apprise »');
        $this->assertSame(false, $rule->toConfirm);
    }

    public function testRefuseUneRegleVersUneCategorieInconnueOuUnTypeInconnu(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => RuleRepository::learnedFromInput(
            ['motif' => 'X', 'type' => 'revenu', 'catégorie' => 'Loterie'],
            self::catalog(),
        ));
        $this->assertThrows(InvalidArgumentException::class, static fn () => RuleRepository::learnedFromInput(
            ['motif' => 'X', 'type' => 'revenu', 'catégorie' => 'Salaire', 'type_operation' => 'TELEPORTATION'],
            self::catalog(),
        ));
    }

    public function testUneRegleAppriseIdentiqueEstRemplacee(): void
    {
        $repository = new RuleRepository($this->db, $this->categories);
        $count = count($repository->all());

        $first = $repository->learn(self::salary('ACELYS', 'Salaire'));
        $this->assertSame(false, $first['remplacée']);
        $second = $repository->learn(self::salary('acelys', 'Autres revenus'));
        $this->assertSame(true, $second['remplacée']);
        $this->assertSame($first['règle']->id, $second['règle']->id, 'même identifiant');

        $rules = $repository->all();
        $this->assertSame($count + 1, count($rules));
        $this->assertSame('Autres revenus', $rules[$count]->category1);
    }

    public function testAppliqueUneRegleAuxMoisDejaEnregistresSeulementSiACategoriser(): void
    {
        $months = new OperationRepository($this->db, $this->categories);
        $uncategorized = 'À catégoriser';
        $months->insertLines([
            MonthLine::build('30/01/2026', 'Virement en votre faveur VIREMENT EN VOTRE FAVEUR ACELYS SERVICES', $uncategorized, Direction::Income, '2645,05'),
            MonthLine::build('15/01/2026', 'Virement en votre faveur VIREMENT EN VOTRE FAVEUR ACELYS PRIME', 'Autres revenus', Direction::Income, '100,00'),
            MonthLine::build('16/01/2026', 'Paiement par carte ACELYS CAFE', ['catégorie1' => $uncategorized, 'catégorie2' => 'Non classé'], Direction::Expense, '3,00'),
            MonthLine::build('27/02/2026', 'Virement en votre faveur VIREMENT EN VOTRE FAVEUR ACELYS SERVICES', $uncategorized, Direction::Income, '2645,05'),
        ]);

        $updated = $months->applyRuleToUncategorized(self::salary('ACELYS', 'Salaire'), new LabelNormalizer());

        $this->assertSame(2, $updated);
        $january = $months->lines('202601');
        $this->assertSame('Autres revenus', $january[0]['catégorie'], 'une ligne déjà catégorisée ne change pas');
        $this->assertSame('Salaire', $january[2]['catégorie'], 'le 30/01 est trié en dernier');
        $this->assertSame($uncategorized, $january[1]['catégorie']['catégorie1'], 'une dépense n\'est pas touchée par une règle de revenu');
        $this->assertSame('Salaire', $months->lines('202602')[0]['catégorie']);
    }

    public function testMoisInvalideRefuse(): void
    {
        $months = new OperationRepository($this->db, $this->categories);

        foreach (['202613', '2026', '../x', '202600'] as $month) {
            $this->assertThrows(InvalidArgumentException::class, static fn () => $months->lines($month));
        }
    }

    private static function catalog(): CategoryCatalog
    {
        return CategoryCatalog::fromArray(DefaultData::categories());
    }

    private static function salary(string $motif, string $category): Rule
    {
        return RuleRepository::learnedFromInput(['motif' => $motif, 'type' => 'revenu', 'catégorie' => $category], self::catalog());
    }
}
