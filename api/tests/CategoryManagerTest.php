<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\CategoryCatalog;
use Banque\CategoryManager;
use Banque\CategoryRepository;
use Banque\Database;
use Banque\DefaultData;
use Banque\Direction;
use Banque\HttpException;
use Banque\MonthLine;
use Banque\OperationRepository;
use Banque\Rule;
use Banque\RuleRepository;
use InvalidArgumentException;

final class CategoryManagerTest extends TestCase
{
    private readonly Database $db;
    private readonly OperationRepository $months;
    private readonly RuleRepository $rules;
    private readonly CategoryManager $manager;

    public function __construct()
    {
        $this->db = self::memoryDatabase();
        DefaultData::seed($this->db);
        $categories = new CategoryRepository($this->db);
        $this->months = new OperationRepository($this->db, $categories);
        $this->rules = new RuleRepository($this->db, $categories);
        $this->manager = new CategoryManager($this->db, $categories, $this->months, $this->rules);

        $courses = ['catégorie1' => 'Alimentation', 'catégorie2' => 'Courses'];
        $this->months->insertLines([
            MonthLine::build('12/01/2026', 'AUCHAN', $courses, Direction::Expense, '2,67'),
            MonthLine::build('13/01/2026', 'RESTO', ['catégorie1' => 'Alimentation', 'catégorie2' => 'Restaurant'], Direction::Expense, '20,00'),
            MonthLine::build('30/01/2026', 'MUTUELLE', 'Remboursements santé', Direction::Income, '50,00'),
            MonthLine::build('12/02/2026', 'AUCHAN', $courses, Direction::Expense, '5,00'),
        ]);
    }

    public function testCompteLesLignesEtReglesUtilisees(): void
    {
        $this->assertSame(['lignes' => 3, 'règles' => 11], $this->manager->usage(Direction::Expense, ['Alimentation']));
        $this->assertSame(['lignes' => 2, 'règles' => 11], $this->manager->usage(Direction::Expense, ['Alimentation', 'Courses']));
        $this->assertSame(['lignes' => 1, 'règles' => 2], $this->manager->usage(Direction::Income, ['Remboursements santé']));
    }

    public function testRenommerUneCategorie1MetAJourMoisEtRegles(): void
    {
        $result = $this->manager->rename(Direction::Expense, ['Alimentation'], 'Nourriture');

        $this->assertSame(['lignes' => 3, 'règles' => 11], $result);
        $this->assertSame(['catégorie1' => 'Nourriture', 'catégorie2' => 'Restaurant'], $this->months->lines('202601')[1]['catégorie']);
        $this->assertSame(['catégorie1' => 'Nourriture', 'catégorie2' => 'Courses'], $this->months->lines('202602')[0]['catégorie']);
        $this->assertTrue($this->catalog()->hasExpense('Nourriture', 'Courses'));
        $this->assertTrue(!$this->catalog()->hasExpense('Alimentation', 'Courses'));
        $auchan = array_values(array_filter($this->rules->all(), static fn (Rule $r): bool => $r->motif === 'AUCHAN'))[0];
        $this->assertSame(['Nourriture', 'Courses'], [$auchan->category1, $auchan->category2]);
    }

    public function testRenommerUneCategorie2(): void
    {
        $result = $this->manager->rename(Direction::Expense, ['Alimentation', 'Courses'], 'Supermarché');

        $this->assertSame(['lignes' => 2, 'règles' => 11], $result);
        $this->assertSame('Supermarché', $this->months->lines('202601')[0]['catégorie']['catégorie2']);
        $this->assertSame('Restaurant', $this->months->lines('202601')[1]['catégorie']['catégorie2']);
    }

    public function testRenommerUnRevenu(): void
    {
        $this->manager->rename(Direction::Income, ['Remboursements santé'], 'Santé');

        $this->assertSame('Santé', $this->months->lines('202601')[2]['catégorie']);
        $this->assertTrue($this->catalog()->hasIncome('Santé'));
    }

    public function testRefuseUnNomDejaPrisEtACategoriser(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->rename(Direction::Expense, ['Alimentation'], 'Transport'));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->rename(Direction::Expense, ['Alimentation', 'Courses'], 'Restaurant'));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->rename(Direction::Expense, ['À catégoriser'], 'Divers'));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->delete(Direction::Income, ['À catégoriser'], ['Salaire']));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->delete(Direction::Expense, ['À catégoriser', 'Non classé'], null));
    }

    public function testSupprimerUneCategorieUtiliseeExigeUnRemplacement(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->delete(Direction::Expense, ['Alimentation', 'Courses'], null));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->delete(Direction::Expense, ['Alimentation', 'Courses'], ['Alimentation', 'Courses']));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->delete(Direction::Expense, ['Alimentation'], ['Alimentation', 'Restaurant']));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->delete(Direction::Expense, ['Alimentation'], ['Inconnue', 'X']));
        $this->assertTrue($this->catalog()->hasExpense('Alimentation', 'Courses'), 'rien n\'a changé');

        $result = $this->manager->delete(Direction::Expense, ['Alimentation'], ['Shopping', 'Maison & déco']);

        $this->assertSame(['lignes' => 3, 'règles' => 11], $result);
        $this->assertSame(['catégorie1' => 'Shopping', 'catégorie2' => 'Maison & déco'], $this->months->lines('202601')[1]['catégorie']);
        $this->assertTrue(!$this->catalog()->hasExpense('Alimentation', 'Courses'));
    }

    public function testSupprimerUneCategorieInutiliseeSansRemplacement(): void
    {
        $result = $this->manager->delete(Direction::Expense, ['Famille', 'Animaux'], null);

        $this->assertSame(['lignes' => 0, 'règles' => 0], $result);
        $this->assertTrue(!$this->catalog()->hasExpense('Famille', 'Animaux'));
        $this->assertTrue($this->catalog()->hasExpense('Famille', 'Enfants'));
    }

    public function testNePasSupprimerLaDerniereSousCategorie(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->delete(Direction::Expense, ["Retraits d'espèces", 'Distributeur'], null));
    }

    public function testAjouterEtChangerLIcone(): void
    {
        $this->manager->add(Direction::Expense, ['Animaux'], '🐶', 'Vétérinaire');
        $this->manager->add(Direction::Expense, ['Animaux', 'Croquettes'], '');
        $this->manager->add(Direction::Income, ['Loyers perçus'], '🏘️');
        $this->manager->changeIcon(Direction::Expense, ['Alimentation'], '🥕');

        $this->assertTrue($this->catalog()->hasExpense('Animaux', 'Croquettes'));
        $this->assertTrue($this->catalog()->hasIncome('Loyers perçus'));
        $this->assertSame('🥕', $this->manager->all()['dépenses'][1]['icone']);
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->add(Direction::Expense, ['Animaux'], '🐱', 'X'));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->add(Direction::Expense, ['Divers'], '🎁', ''));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->manager->add(Direction::Income, ['Bonus'], ''));
    }

    public function testModifierEtSupprimerUneRegleParIdentifiant(): void
    {
        $catalog = $this->catalog();
        $first = $this->rules->all()[0];
        $this->assertSame('AUCHAN', $first->motif);
        $rule = $this->rules->update((int) $first->id, ['motif' => 'AUCHAN', 'type' => 'dépense', 'catégorie1' => 'Shopping', 'catégorie2' => 'Beauté', 'a_confirmer' => true, 'et' => ['DRIVE']], $catalog);

        $this->assertSame(Rule::ORIGIN_INITIAL, $rule->origin, 'l\'origine est conservée');
        $this->assertSame(true, $rule->toConfirm);
        $this->assertSame($first->id, $rule->id, 'même identifiant');
        $this->assertSame(['DRIVE'], $this->rules->find((int) $first->id)?->and);
        $this->assertSame('AUCHAN', $this->rules->all()[0]->motif, 'même place');
        $this->assertThrows(HttpException::class, fn () => $this->rules->update(9999, ['motif' => 'X', 'type' => 'revenu', 'catégorie' => 'Salaire'], $catalog));

        $count = count($this->rules->all());
        $this->assertThrows(HttpException::class, fn () => $this->rules->delete(9999));
        $this->rules->delete((int) $first->id);
        $this->assertSame($count - 1, count($this->rules->all()));
        $this->assertSame('CARREFOUR', $this->rules->all()[0]->motif);
        $this->assertSame(0, $this->db->value('SELECT count(*) FROM regle_mots WHERE regle_id = ?', [$first->id]), 'mots supprimés');
    }

    public function testRenommerNeModifieQueLaCategorie(): void
    {
        $before = $this->db->all('SELECT id, categorie_id FROM operations ORDER BY id');
        $this->manager->rename(Direction::Expense, ['Alimentation'], 'Nourriture');
        $this->assertSame($before, $this->db->all('SELECT id, categorie_id FROM operations ORDER BY id'), 'opérations inchangées');
    }

    private function catalog(): CategoryCatalog
    {
        return CategoryCatalog::fromArray($this->manager->all());
    }
}
