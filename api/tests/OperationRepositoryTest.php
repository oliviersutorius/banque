<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\CategoryRepository;
use Banque\Database;
use Banque\DefaultData;
use Banque\Direction;
use Banque\HttpException;
use Banque\LabelNormalizer;
use Banque\MonthLine;
use Banque\OperationRepository;
use Banque\Rule;
use InvalidArgumentException;

final class OperationRepositoryTest extends TestCase
{
    private const COURSES = ['catégorie1' => 'Alimentation', 'catégorie2' => 'Courses'];
    private const NON_CLASSE = ['catégorie1' => 'À catégoriser', 'catégorie2' => 'Non classé'];

    private readonly Database $db;
    private readonly OperationRepository $operations;

    public function __construct()
    {
        $this->db = self::memoryDatabase();
        DefaultData::seed($this->db);
        $this->operations = new OperationRepository($this->db, new CategoryRepository($this->db));
        $this->operations->insertLines([
            MonthLine::build('20/01/2026', 'AUCHAN LATTES', self::NON_CLASSE, Direction::Expense, '10,00'),
            MonthLine::build('12/01/2026', 'AUCHAN PEROLS', self::NON_CLASSE, Direction::Expense, '2,67'),
            MonthLine::build('30/01/2026', 'SALAIRE', 'Virements reçus', Direction::Income, '2645,05'),
            MonthLine::build('12/01/2026', 'BOULANGERIE', self::NON_CLASSE, Direction::Expense, '1 234,50'),
        ]);
    }

    /**
     * @return list<int>
     */
    private function ids(string $month): array
    {
        return array_column($this->operations->lines($month), 'id');
    }

    public function testRendLesLignesSousLaFormeDeLApiTrieesParDatePuisArrivee(): void
    {
        $lines = $this->operations->lines('202601');
        $this->assertSame(['AUCHAN PEROLS', 'BOULANGERIE', 'AUCHAN LATTES', 'SALAIRE'], array_column($lines, 'libelle'));
        $this->assertSame(['id' => $lines[0]['id'], 'date' => '12/01/2026', 'libelle' => 'AUCHAN PEROLS', 'catégorie' => self::NON_CLASSE, 'dépense' => '2,67'], $lines[0]);
        $this->assertSame('1234,50', $lines[1]['dépense'], 'montant normalisé');
        $this->assertSame(['id' => $lines[3]['id'], 'date' => '30/01/2026', 'libelle' => 'SALAIRE', 'catégorie' => 'Virements reçus', 'revenu' => '2645,05'], $lines[3]);
        $this->assertSame(264505, $this->db->value("SELECT montant_centimes FROM operations WHERE libelle = 'SALAIRE'"), 'centimes entiers');
    }

    public function testMoisEtAnnees(): void
    {
        $this->operations->insertLines([MonthLine::build('03/07/2025', 'X', self::COURSES, Direction::Expense, '1,00')]);
        $this->assertSame(['202507', '202601'], $this->operations->months());
        $this->assertTrue($this->operations->exists('202507'));
        $this->assertTrue(!$this->operations->exists('202508'));
        $this->assertSame([], $this->operations->lines('202508'));
        $this->assertSame(['202601'], array_map('strval', array_keys($this->operations->year('2026'))));
        $this->assertSame(4, count($this->operations->year('2026')['202601']));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->operations->lines('202613'));
    }

    public function testRecategoriseUneLigneEtSesSimilairesEnUneFois(): void
    {
        [$perols, , $lattes, $salaire] = $this->ids('202601');
        $this->operations->recategorize([$perols, $lattes], self::COURSES, Direction::Expense);

        $lines = $this->operations->lines('202601');
        $this->assertSame(self::COURSES, $lines[0]['catégorie']);
        $this->assertSame(self::NON_CLASSE, $lines[1]['catégorie']);
        $this->assertSame(self::COURSES, $lines[2]['catégorie']);
        $this->assertSame('Virements reçus', $this->operations->find($salaire)['catégorie'] ?? null);
    }

    public function testRefuseUnIdentifiantInconnuOuUnSensDifferentSansRienEcrire(): void
    {
        [$perols, , , $salaire] = $this->ids('202601');
        $this->assertThrows(HttpException::class, fn () => $this->operations->recategorize([$perols, 999], self::COURSES, Direction::Expense));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->operations->recategorize([$perols, $salaire], self::COURSES, Direction::Expense));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->operations->recategorize([$perols], ['catégorie1' => 'Alimentation', 'catégorie2' => 'Inconnue'], Direction::Expense));
        $this->assertSame(self::NON_CLASSE, $this->operations->lines('202601')[0]['catégorie']);
    }

    public function testLignesACategoriserEtApplicationDUneRegle(): void
    {
        $this->operations->insertLines([MonthLine::build('02/02/2026', 'AUCHAN NIMES', self::NON_CLASSE, Direction::Expense, '3,00')]);
        $this->assertSame(['202601' => 3, '202602' => 1], array_map('count', $this->operations->uncategorized()));

        $rule = new Rule('AUCHAN', Direction::Expense, 'Alimentation', 'Courses', Rule::ORIGIN_LEARNED);
        $this->assertSame(3, $this->operations->applyRuleToUncategorized($rule, new LabelNormalizer()));
        $this->assertSame(['202601' => 1], array_map('count', $this->operations->uncategorized()));
        $this->assertSame('BOULANGERIE', $this->operations->uncategorized()['202601'][0]['libelle']);
    }
}
