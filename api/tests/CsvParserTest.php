<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\Amount;
use Banque\CsvFormatException;
use Banque\CsvParser;
use Banque\Direction;
use Banque\ParsedLine;
use Banque\ParseResult;

final class CsvParserTest extends TestCase
{
    private const HEADER = "Date;Libellé;Débit euros;Crédit euros\n";

    // --- exemple.csv (non versionné : ces tests sont ignorés s'il est absent) ---

    public function testExempleContientDixOperationsSansRejet(): void
    {
        $result = $this->parseExample();

        $this->assertSame(10, count($result->lines));
        $this->assertSame([], $result->rejected);
    }

    public function testExempleRepartitionParMoisEtParSens(): void
    {
        $counts = [];
        foreach ($this->parseExample()->lines as $line) {
            $key = $line->month() . ' ' . $line->direction->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        $this->assertSame([
            '202507 dépense' => 3,
            '202507 revenu' => 1,
            '202601 dépense' => 5,
            '202601 revenu' => 1,
        ], $counts);
    }

    public function testExempleMontantsNormalises(): void
    {
        $lines = $this->parseExample()->lines;

        $this->assertSame('25,00', Amount::format($lines[0]->cents));
        $this->assertSame('2645,05', Amount::format($lines[1]->cents));
        $this->assertSame(Direction::Income, $lines[1]->direction);
        $this->assertSame('69,90', Amount::format($lines[2]->cents));
        $this->assertSame('2,67', Amount::format($lines[5]->cents));
    }

    public function testExemplePointVirguleFinalTolere(): void
    {
        $line = $this->parseExample()->lines[6];

        $this->assertSame(8, $line->lineNumber);
        $this->assertSame('02/07/2025', $line->date);
        $this->assertSame('Règlement REGLEMENT ASSU. CNP PRET HABITAT 07/25', $line->libelle);
        $this->assertSame(Direction::Expense, $line->direction);
        $this->assertSame('65,64', Amount::format($line->cents));
    }

    public function testExempleConserveOrdreEtLibelle(): void
    {
        $lines = $this->parseExample()->lines;

        $this->assertSame(2, $lines[0]->lineNumber);
        $this->assertSame('Paiement par carte PAIEMENT PAR CARTE X9876 AUCHAN PEROLS 246709 10/01', $lines[5]->libelle);
        $this->assertSame('Virement en votre faveur VIREMENT EN VOTRE FAVEUR MUTUELLE', $lines[9]->libelle);
    }

    // --- Robustesse, sur des données inventées ---

    public function testDecodeWindows1252(): void
    {
        $csv = mb_convert_encoding(self::HEADER . "05/03/2026;\"Prélèvement PRELEVEMENT Électricité\";42,10;\n", 'Windows-1252', 'UTF-8');

        $result = (new CsvParser())->parse($csv);

        $this->assertSame('Prélèvement PRELEVEMENT Électricité', $result->lines[0]->libelle);
    }

    public function testIgnoreLeBomEtLesFinsDeLigneWindows(): void
    {
        $csv = "\xEF\xBB\xBF" . str_replace("\n", "\r\n", self::HEADER . "05/03/2026;\"Frais\";1;\n");

        $result = (new CsvParser())->parse($csv);

        $this->assertSame(1, count($result->lines));
        $this->assertSame('Frais', $result->lines[0]->libelle);
    }

    public function testIgnoreLesLignesAvantEnTeteEtLesLignesDeFin(): void
    {
        $csv = "Téléchargement du 29/04/2026;\n\nM. DUPONT\nCompte de Dépôt carte n° 0000;\n"
            . "Solde au 29/04/2026 1 531,91 €\n;Encours sur 0 carte(s); -0,00 €\n\n"
            . "Liste des opérations du compte entre le 01/03/2026 et le 31/03/2026;\n\n"
            . "Date;Libellé;Débit euros;Crédit euros;\n"
            . "09/03/2026;\"Virement en votre faveur VIREMENT EN VOTRE FAVEUR M. DUPONT\";;300,00;\n\n\n"
            . "Solde au 31/03/2026 1 831,91 €\nTotal des opérations;1 000,00;300,00\n";

        $result = (new CsvParser())->parse($csv);

        $this->assertSame(1, count($result->lines));
        $this->assertSame(11, $result->lines[0]->lineNumber);
        $this->assertSame([], $result->rejected);
    }

    public function testLibelleSurPlusieursLignes(): void
    {
        $csv = self::HEADER
            . "30/01/2026;\"Prélèvement\nPRELEVEMENT FREE MOBILE  \n\n++FM-19704059-1\nFR07ZZZ591778\nfmpmt-2344726118\";31,98;\n"
            . "30/01/2026;\"Paiement par carte\nPAIEMENT PAR CARTE X9860 AUCHAN PEROLS 246709 29/01  \n\n\n\n\";16,4;\n";

        $result = (new CsvParser())->parse($csv);

        $this->assertSame(
            'Prélèvement PRELEVEMENT FREE MOBILE ++FM-19704059-1 FR07ZZZ591778 fmpmt-2344726118',
            $result->lines[0]->libelle,
        );
        $this->assertSame('Paiement par carte PAIEMENT PAR CARTE X9860 AUCHAN PEROLS 246709 29/01', $result->lines[1]->libelle);
        $this->assertSame(2, $result->lines[0]->lineNumber);
        $this->assertSame(8, $result->lines[1]->lineNumber, 'les numéros de ligne tiennent compte des libellés multi-lignes');
        $this->assertSame(1640, $result->lines[1]->cents);
    }

    public function testMontantAvecSeparateurDeMilliers(): void
    {
        $result = (new CsvParser())->parse(self::HEADER . "10/03/2026;\"Virement en votre faveur SALAIRE\";;2 640,31;\n");

        $this->assertSame(264031, $result->lines[0]->cents);
    }

    public function testSignaleLesLignesInvalides(): void
    {
        $csv = self::HEADER
            . "01/03/2026;\"Sans montant\";;\n"
            . "02/03/2026;\"Deux montants\";10,00;20,00\n"
            . "31/02/2026;\"Date impossible\";5;\n"
            . "2026-03-04;\"Mauvais format\";5;\n"
            . "05/03/2026;\"Montant illisible\";12.50;\n"
            . "06/03/2026;\"Montant nul\";0,00;\n"
            . "07/03/2026;\"Champ en trop\";5;;surprise\n"
            . "Ligne inattendue au milieu\n"
            . "08/03/2026;\"Valide\";5;\n";

        $result = (new CsvParser())->parse($csv);

        $this->assertSame(['Valide'], array_map(static fn (ParsedLine $l): string => $l->libelle, $result->lines));
        $this->assertSame(8, count($result->rejected));
        $this->assertSame(2, $result->rejected[0]->lineNumber);
        $this->assertSame('01/03/2026;"Sans montant";;', $result->rejected[0]->content);
        $this->assertSame('ni débit ni crédit', $result->rejected[0]->reason);
        $this->assertSame('débit et crédit remplis tous les deux', $result->rejected[1]->reason);
        $this->assertTrue(str_starts_with($result->rejected[2]->reason, 'date invalide'));
        $this->assertTrue(str_starts_with($result->rejected[3]->reason, 'date invalide'));
        $this->assertTrue(str_starts_with($result->rejected[4]->reason, 'Montant invalide'));
        $this->assertSame('montant nul', $result->rejected[5]->reason);
        $this->assertSame(9, $result->rejected[7]->lineNumber);
    }

    public function testFichierSansEnTeteEstRefuse(): void
    {
        $this->assertThrows(CsvFormatException::class, static fn () => (new CsvParser())->parse("01/03/2026;\"x\";5;\n"));
        $this->assertThrows(CsvFormatException::class, static fn () => (new CsvParser())->parse(''));
    }

    // --- Vrais exports (dossier csv/, non versionné) ---

    public function testLesExportsReelsSeLisentSansRejet(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/csv/*.csv') ?: [];
        if ($files === []) {
            $this->markSkipped('aucun fichier dans csv/');
        }
        foreach ($files as $file) {
            $result = (new CsvParser())->parse((string) file_get_contents($file));
            $name = basename($file);
            $this->assertTrue(count($result->lines) > 0, $name . ' : aucune opération lue');
            $this->assertSame([], $result->rejected, $name . ' : lignes rejetées');
            foreach ($result->lines as $line) {
                $this->assertSame(basename($file, '.csv'), $line->month(), $name . ' ligne ' . $line->lineNumber);
            }
        }
    }

    private function parseExample(): ParseResult
    {
        $file = dirname(__DIR__, 2) . '/exemple.csv';
        if (!is_file($file)) {
            $this->markSkipped('exemple.csv absent');
        }

        return (new CsvParser())->parse((string) file_get_contents($file));
    }
}
