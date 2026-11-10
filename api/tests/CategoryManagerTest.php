<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\CategoryCatalog;
use Banque\CategoryManager;
use Banque\DefaultData;
use Banque\Direction;
use Banque\JsonFile;
use Banque\LineConflictException;
use Banque\MonthLine;
use Banque\MonthRepository;
use Banque\Rule;
use Banque\RuleRepository;
use InvalidArgumentException;

final class CategoryManagerTest extends TestCase
{
    private readonly TempDir $tmp;
    private readonly MonthRepository $months;
    private readonly RuleRepository $rules;
    private readonly CategoryManager $manager;
    private readonly JsonFile $json;

    public function __construct()
    {
        $this->tmp = new TempDir();
        mkdir($this->tmp->path . '/mois');
        mkdir($this->tmp->path . '/sauvegardes');
        $this->json = new JsonFile($this->tmp->path . '/sauvegardes');
        $this->json->write($this->tmp->path . '/categories.json', DefaultData::categories());
        $this->months = new MonthRepository($this->tmp->path . '/mois', $this->json);
        $this->rules = new RuleRepository($this->tmp->path . '/regles.json', $this->json);
        $this->rules->saveAll(Rule::listFromArray(DefaultData::rules()));
        $this->manager = new CategoryManager($this->tmp->path . '/categories.json', $this->json, $this->months, $this->rules);

        $courses = ['catégorie1' => 'Alimentation', 'catégorie2' => 'Courses'];
        $this->months->save('202601', [
            MonthLine::build('12/01/2026', 'AUCHAN', $courses, Direction::Expense, '2,67'),
            MonthLine::build('13/01/2026', 'RESTO', ['catégorie1' => 'Alimentation', 'catégorie2' => 'Restaurant'], Direction::Expense, '20,00'),
            MonthLine::build('30/01/2026', 'MUTUELLE', 'Remboursements santé', Direction::Income, '50,00'),
        ]);
        $this->months->save('202602', [
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

    public function testModifierEtSupprimerUneRegleParIndex(): void
    {
        $catalog = $this->catalog();
        $rule = $this->rules->update(0, 'AUCHAN', ['motif' => 'AUCHAN', 'type' => 'dépense', 'catégorie1' => 'Shopping', 'catégorie2' => 'Beauté', 'a_confirmer' => true], $catalog);

        $this->assertSame(Rule::ORIGIN_INITIAL, $rule->origin, 'l\'origine est conservée');
        $this->assertSame(true, $rule->toConfirm);
        $this->assertThrows(LineConflictException::class, fn () => $this->rules->update(0, 'CARREFOUR', ['motif' => 'X', 'type' => 'revenu', 'catégorie' => 'Salaire'], $catalog));

        $count = count($this->rules->all());
        $this->assertThrows(LineConflictException::class, fn () => $this->rules->delete(0, 'LIDL'));
        $this->rules->delete(0, 'AUCHAN');
        $this->assertSame($count - 1, count($this->rules->all()));
        $this->assertSame('CARREFOUR', $this->rules->all()[0]->motif);
    }

    private function catalog(): CategoryCatalog
    {
        return CategoryCatalog::fromArray($this->manager->all());
    }
}
