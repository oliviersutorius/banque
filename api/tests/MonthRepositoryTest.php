<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\Direction;
use Banque\JsonFile;
use Banque\LineConflictException;
use Banque\MonthLine;
use Banque\MonthRepository;
use InvalidArgumentException;

final class MonthRepositoryTest extends TestCase
{
    private const COURSES = ['catégorie1' => 'Alimentation', 'catégorie2' => 'Courses'];
    private const NON_CLASSE = ['catégorie1' => 'À catégoriser', 'catégorie2' => 'Non classé'];

    private readonly TempDir $tmp;
    private readonly MonthRepository $months;

    public function __construct()
    {
        $this->tmp = new TempDir();
        mkdir($this->tmp->path . '/mois');
        mkdir($this->tmp->path . '/sauvegardes');
        $this->months = new MonthRepository($this->tmp->path . '/mois', new JsonFile($this->tmp->path . '/sauvegardes'));
        $this->months->save('202601', [
            MonthLine::build('12/01/2026', 'AUCHAN PEROLS', self::NON_CLASSE, Direction::Expense, '2,67'),
            MonthLine::build('20/01/2026', 'AUCHAN LATTES', self::NON_CLASSE, Direction::Expense, '10,00'),
            MonthLine::build('30/01/2026', 'SALAIRE', 'Virements reçus', Direction::Income, '2645,05'),
        ]);
    }

    public function testListeLesMoisDisponibles(): void
    {
        $this->months->save('202507', []);
        file_put_contents($this->tmp->path . '/mois/notes.json', '{}');

        $this->assertSame(['202507', '202601'], $this->months->months());
    }

    public function testRecategoriseUneLigneEtSesSimilairesEnUneEcriture(): void
    {
        $lines = $this->months->recategorize('202601', [
            ['index' => 0, 'date' => '12/01/2026', 'libelle' => 'AUCHAN PEROLS'],
            ['index' => 1, 'date' => '20/01/2026', 'libelle' => 'AUCHAN LATTES'],
        ], self::COURSES, Direction::Expense);

        $this->assertSame(self::COURSES, $lines[0]['catégorie']);
        $this->assertSame(self::COURSES, $lines[1]['catégorie']);
        $this->assertSame('Virements reçus', $lines[2]['catégorie']);
        $this->assertSame(1, count(glob($this->tmp->path . '/sauvegardes/202601-*.json') ?: []), 'une seule sauvegarde');
    }

    public function testRefuseSiLaLigneAChangeSansRienEcrire(): void
    {
        $this->assertThrows(LineConflictException::class, fn () => $this->months->recategorize('202601', [
            ['index' => 0, 'date' => '12/01/2026', 'libelle' => 'AUCHAN PEROLS'],
            ['index' => 1, 'date' => '20/01/2026', 'libelle' => 'AUTRE LIBELLE'],
        ], self::COURSES, Direction::Expense));

        $this->assertSame(self::NON_CLASSE, $this->months->lines('202601')[0]['catégorie']);
    }

    public function testRefuseUnIndexInconnuOuUnSensDifferent(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->months->recategorize('202601', [
            ['index' => 9, 'date' => '12/01/2026', 'libelle' => 'AUCHAN PEROLS'],
        ], self::COURSES, Direction::Expense));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->months->recategorize('202601', [
            ['index' => 2, 'date' => '30/01/2026', 'libelle' => 'SALAIRE'],
        ], self::COURSES, Direction::Expense));
    }
}
