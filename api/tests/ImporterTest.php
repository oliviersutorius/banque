<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\CategoryCatalog;
use Banque\Categorizer;
use Banque\CsvParser;
use Banque\DefaultData;
use Banque\Importer;
use Banque\JsonFile;
use Banque\LabelNormalizer;
use Banque\MonthRepository;
use Banque\Rule;
use InvalidArgumentException;

final class ImporterTest extends TestCase
{
    private const HEADER = "Date;Libellé;Débit euros;Crédit euros\n";
    private const CAFE = '10/03/2026;"Paiement par carte PAIEMENT PAR CARTE X1234 CAFE DU PORT 09/03";2,5;';

    private readonly TempDir $tmp;
    private readonly MonthRepository $months;
    private readonly Importer $importer;

    public function __construct()
    {
        $this->tmp = new TempDir();
        mkdir($this->tmp->path . '/mois');
        mkdir($this->tmp->path . '/sauvegardes');
        $this->months = new MonthRepository($this->tmp->path . '/mois', new JsonFile($this->tmp->path . '/sauvegardes'));
        $catalog = CategoryCatalog::fromArray(DefaultData::categories());
        $normalizer = new LabelNormalizer();
        $this->importer = new Importer(
            new CsvParser(),
            new Categorizer(Rule::listFromArray(DefaultData::rules()), $catalog, $normalizer),
            $normalizer,
            $this->months,
            $catalog,
        );
    }

    public function testPrevisualisationNEcritRien(): void
    {
        $preview = $this->importer->preview(self::HEADER . self::CAFE . "\n");

        $this->assertSame(1, $preview['résumé']['nouvelles']);
        $this->assertSame([], $this->months->months());
    }

    public function testResumeEtLignesDeLaPrevisualisation(): void
    {
        $csv = self::HEADER
            . "30/01/2026;\"Paiement par carte PAIEMENT PAR CARTE X9876 AUCHAN PEROLS 246709 10/01\";2,67;\n"
            . "30/01/2026;\"Virement en votre faveur VIREMENT EN VOTRE FAVEUR société X\";;2645,05\n"
            . "02/07/2025;\"Paiement par carte PAIEMENT PAR CARTE X9876 loisir\";69,9;;\n"
            . "31/02/2026;\"Date impossible\";5;\n";

        $preview = $this->importer->preview($csv);

        $this->assertSame(['202507', '202601'], $preview['résumé']['mois'], 'mois triés');
        $this->assertSame(3, $preview['résumé']['lignes']);
        $this->assertSame('72,57', $preview['résumé']['dépenses']);
        $this->assertSame('2645,05', $preview['résumé']['revenus']);
        $this->assertSame(1, $preview['résumé']['erreurs']);
        $this->assertSame(1, $preview['résumé']['à_revoir']);
        $this->assertSame(5, $preview['erreurs'][0]['ligne']);

        $auchan = $preview['mois'][1]['lignes'][0];
        $this->assertSame(['catégorie1' => 'Alimentation', 'catégorie2' => 'Courses'], $auchan['catégorie']);
        $this->assertSame('AUCHAN PEROLS', $auchan['marchand']);
        $this->assertSame('CARTE', $auchan['type_operation']);
        $this->assertSame('AUCHAN', $auchan['motif']);
        $this->assertSame('2,67', $auchan['montant']);
        $this->assertSame(false, $auchan['à_revoir']);
        $this->assertSame(true, $preview['mois'][0]['lignes'][0]['à_revoir'], 'loisir est à catégoriser');
    }

    public function testConfirmationEcritLesMoisTriesParDate(): void
    {
        $csv = self::HEADER
            . "30/01/2026;\"B le 30\";1;\n"
            . "12/01/2026;\"A le 12\";1;\n"
            . "30/01/2026;\"C le 30\";1;\n"
            . "02/07/2025;\"D juillet\";1;\n";

        $result = $this->confirmAll($csv);

        $this->assertSame(4, $result['ajoutées']);
        $this->assertSame(['202507', '202601'], $this->months->months());
        $this->assertSame(
            ['A le 12', 'B le 30', 'C le 30'],
            array_column($this->months->lines('202601'), 'libelle'),
            'tri par date, ordre du CSV conservé à date égale',
        );
        $line = $this->months->lines('202601')[0];
        $this->assertSame(['date', 'libelle', 'catégorie', 'dépense'], array_keys($line));
        $this->assertSame('1,00', $line['dépense']);
    }

    public function testReimporterLeMemeFichierNAjouteRien(): void
    {
        $csv = self::HEADER . self::CAFE . "\n" . "11/03/2026;\"Virement en votre faveur SALAIRE\";;2 640,31\n";
        $this->confirmAll($csv);

        $preview = $this->importer->preview($csv);
        $this->assertSame(0, $preview['résumé']['nouvelles']);
        $this->assertSame(2, $preview['résumé']['doublons']);

        $result = $this->confirmAll($csv);
        $this->assertSame(0, $result['ajoutées']);
        $this->assertSame(2, count($this->months->lines('202603')));
    }

    public function testDoublonsCompteesParOccurrences(): void
    {
        // Le mois contient un café ; le CSV en contient deux le même jour : un seul est ajouté
        $this->confirmAll(self::HEADER . self::CAFE . "\n");

        $csv = self::HEADER . self::CAFE . "\n" . self::CAFE . "\n";
        $preview = $this->importer->preview($csv);
        $this->assertSame([true, false], array_column($preview['mois'][0]['lignes'], 'doublon'));

        $result = $this->confirmAll($csv);
        $this->assertSame(1, $result['ajoutées']);
        $this->assertSame(['mois' => '202603', 'ajoutées' => 1, 'doublons' => 1], $result['mois'][0]);
        $this->assertSame(2, count($this->months->lines('202603')));

        // Un troisième import du même CSV n'ajoute plus rien
        $this->assertSame(0, $this->confirmAll($csv)['ajoutées']);
    }

    public function testUnMontantOuUnSensDifferentNEstPasUnDoublon(): void
    {
        $this->confirmAll(self::HEADER . self::CAFE . "\n");

        $result = $this->confirmAll(self::HEADER
            . str_replace('2,5;', '2,6;', self::CAFE) . "\n"
            . str_replace('2,5;', ';2,5', self::CAFE) . "\n");

        $this->assertSame(2, $result['ajoutées']);
    }

    public function testLesCategoriesEnregistreesNeSontJamaisEcrasees(): void
    {
        $this->confirmAll(self::HEADER . self::CAFE . "\n", ['catégorie1' => 'Alimentation', 'catégorie2' => 'Restaurant']);

        $this->confirmAll(self::HEADER . self::CAFE . "\n", ['catégorie1' => 'Loisirs & culture', 'catégorie2' => 'Sorties']);

        $this->assertSame(
            ['catégorie1' => 'Alimentation', 'catégorie2' => 'Restaurant'],
            $this->months->lines('202603')[0]['catégorie'],
        );
    }

    public function testConfirmationRefuseUneCategorieInconnue(): void
    {
        $preview = $this->importer->preview(self::HEADER . self::CAFE . "\n");
        $line = $preview['mois'][0]['lignes'][0];
        $line['catégorie'] = ['catégorie1' => 'Inventée', 'catégorie2' => 'Nulle part'];

        $this->assertThrows(InvalidArgumentException::class, fn () => $this->importer->confirm(['lignes' => [$line]]));

        $line['catégorie'] = 'Salaire';
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->importer->confirm(['lignes' => [$line]]));
        $this->assertSame([], $this->months->months(), 'rien n\'est écrit en cas d\'erreur');
    }

    public function testConfirmationRefuseLesLignesMalFormees(): void
    {
        $valid = ['date' => '10/03/2026', 'libelle' => 'X', 'sens' => 'dépense', 'montant' => '2,50',
            'catégorie' => ['catégorie1' => 'À catégoriser', 'catégorie2' => 'Non classé']];
        $invalid = [
            ['date' => '2026-03-10'] + $valid,
            ['date' => '31/02/2026'] + $valid,
            ['montant' => '2,5'] + $valid,
            ['montant' => '0,00'] + $valid,
            ['sens' => 'achat'] + $valid,
            ['libelle' => '  '] + $valid,
        ];
        foreach ($invalid as $line) {
            $this->assertThrows(InvalidArgumentException::class, fn () => $this->importer->confirm(['lignes' => [$line]]));
        }
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->importer->confirm(['lignes' => []]));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->importer->confirm([]));
    }

    public function testExempleCompletEnDeuxImports(): void
    {
        $file = dirname(__DIR__, 2) . '/exemple.csv';
        if (!is_file($file)) {
            $this->markSkipped('exemple.csv absent');
        }
        $result = $this->confirmAll((string) file_get_contents($file));

        $this->assertSame(10, $result['ajoutées']);
        $this->assertSame(6, count($this->months->lines('202601')));
        $this->assertSame(4, count($this->months->lines('202507')));
        $first = $this->months->lines('202507')[0];
        $this->assertSame('02/07/2025', $first['date']);
        $this->assertSame('65,64', $first['dépense']);
    }

    /**
     * Prévisualise puis confirme toutes les lignes, comme le ferait le front.
     *
     * @param array<string, string>|string|null $category catégorie imposée à toutes les lignes
     * @return array<string, mixed>
     */
    private function confirmAll(string $csv, array|string|null $category = null): array
    {
        $lines = [];
        foreach ($this->importer->preview($csv)['mois'] as $month) {
            foreach ($month['lignes'] as $line) {
                if ($category !== null) {
                    $line['catégorie'] = $category;
                }
                $lines[] = $line;
            }
        }

        return $this->importer->confirm(['lignes' => $lines]);
    }
}
