<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\CategoryRepository;
use Banque\Database;
use Banque\DefaultData;
use Banque\Direction;
use Banque\HttpException;
use Banque\Rule;
use Banque\RuleConsolidation;
use Banque\RuleRepository;

final class RuleConsolidationTest extends TestCase
{
    private readonly Database $db;
    private readonly CategoryRepository $categories;
    private readonly RuleRepository $rules;

    public function __construct()
    {
        $this->db = self::memoryDatabase();
        DefaultData::seed($this->db);
        $this->categories = new CategoryRepository($this->db);
        $this->rules = new RuleRepository($this->db, $this->categories);
    }

    public function testUneRegleCouverteParUnMotifPlusCourtEstSupprimee(): void
    {
        $this->learn('ZARA', 'Shopping', 'Vêtements');
        $long = $this->learn('ZARA MONTPELLIER', 'Shopping', 'Vêtements');
        $this->operation('Paiement par carte X1234 ZARA MONTPELLIER 10/01');
        $before = count($this->rules->all());

        $analysis = $this->consolidation()->analyse();
        $this->assertSame(['d:' . $long], array_column($analysis['doublons'], 'clé'));
        $this->assertSame('ZARA', $analysis['doublons'][0]['couverte_par']['motif']);

        $result = $this->consolidation()->apply(['d:' . $long]);
        $this->assertSame(1, $result['supprimées']);
        $this->assertSame($before - 1, count($this->rules->all()));
        $this->assertSame([], $this->consolidation()->analyse()['doublons'], 'rien de plus à mutualiser');
    }

    public function testCategorieOuTypeDifferentNeSontPasDesDoublons(): void
    {
        // AUCHAN (Courses) et AUCHAN CARBURANT (Carburant) sont des règles initiales de catégories différentes
        $this->learn('SEPHORA', 'Shopping', 'Beauté', 'PRELEVEMENT');
        $this->learn('SEPHORA LATTES', 'Shopping', 'Beauté', 'CARTE');

        $this->assertSame([], $this->consolidation()->analyse()['doublons']);
    }

    public function testUnDoublonQuiChangeraitUneOperationNestPasPropose(): void
    {
        $this->learn('ZARA', 'Shopping', 'Vêtements');
        $this->learn('ZARA MONTPELLIER', 'Shopping', 'Vêtements');
        // Sans « ZARA MONTPELLIER », ce motif plus long que « ZARA » gagnerait
        $this->learn('MONTPELLIER', 'Loisirs & culture', 'Sorties');
        $this->operation('Paiement par carte X1234 ZARA MONTPELLIER 10/01');

        $this->assertSame([], $this->consolidation()->analyse()['doublons']);
    }

    public function testRegroupementSansImpactApplique(): void
    {
        $this->learn('MANGO MNG LYON', 'Shopping', 'Vêtements');
        $this->learn('MANGO.COM BARCELONA', 'Shopping', 'Vêtements');
        $this->operation('Paiement par carte X1234 MANGO MNG LYON 10/01');
        $this->operation('Paiement par carte X1234 MANGO.COM BARCELONA 11/01');
        $before = count($this->rules->all());

        $groups = $this->consolidation()->analyse()['regroupements'];
        $this->assertSame(1, count($groups));
        $this->assertSame('MANGO', $groups[0]['motif']);
        $this->assertSame(0, $groups[0]['impact']);

        $result = $this->consolidation()->apply([$groups[0]['clé']]);
        $this->assertSame(['supprimées' => 2, 'créées' => 1], ['supprimées' => $result['supprimées'], 'créées' => $result['créées']]);
        $this->assertSame($before - 1, count($this->rules->all()));
        $motifs = array_map(static fn (Rule $r): string => $r->motif, $this->rules->all());
        $this->assertTrue(in_array('MANGO', $motifs, true));
    }

    public function testRegroupementAvecImpactNonApplicable(): void
    {
        $this->learn('TAM VOIRIE', 'Transport', 'Transports en commun');
        $this->learn('TAM CT MONTP', 'Transport', 'Transports en commun');
        $this->operation('Paiement par carte X9860 TAM PARKING MONTPELL 17/04');

        $groups = $this->consolidation()->analyse()['regroupements'];
        $this->assertSame(1, count($groups));
        $this->assertSame(1, $groups[0]['impact']);
        $this->assertSame('Transport > Transports en commun', $groups[0]['exemples'][0]['après']);
        $this->assertThrows(HttpException::class, fn () => $this->consolidation()->apply([$groups[0]['clé']]));
    }

    public function testCleInconnueRefusee(): void
    {
        $count = count($this->rules->all());

        $this->assertThrows(HttpException::class, fn () => $this->consolidation()->apply(['d:999999']));
        $this->assertSame($count, count($this->rules->all()));
    }

    public function testMotGeneriqueNeFormePasDeRegroupement(): void
    {
        $this->learn('SARL CHEF JEAN', 'Alimentation', 'Restaurant');
        $this->learn('SARL CTAC AIGUES MOR', 'Alimentation', 'Restaurant');

        $this->assertSame([], $this->consolidation()->analyse()['regroupements']);
    }

    private function consolidation(): RuleConsolidation
    {
        return new RuleConsolidation($this->db, $this->rules, $this->categories->catalog());
    }

    private function learn(string $motif, string $category1, string $category2, string $type = 'CARTE'): int
    {
        $rule = RuleRepository::learnedFromInput(
            ['motif' => $motif, 'type' => 'dépense', 'catégorie1' => $category1, 'catégorie2' => $category2, 'type_operation' => $type],
            $this->categories->catalog(),
        );

        return (int) $this->rules->learn($rule)['règle']->id;
    }

    private function operation(string $libelle): void
    {
        $this->db->run(
            'INSERT INTO operations (date, libelle, sens, montant_centimes, categorie_id) VALUES (?, ?, ?, ?, ?)',
            ['2026-01-10', $libelle, 'dépense', 1000, $this->categories->leafId(Direction::Expense, ['catégorie1' => DefaultData::UNCATEGORIZED, 'catégorie2' => DefaultData::UNCATEGORIZED_SUB])],
        );
    }
}
