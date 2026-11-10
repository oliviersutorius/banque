<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\Categorization;
use Banque\CategoryCatalog;
use Banque\Categorizer;
use Banque\DefaultData;
use Banque\Direction;
use Banque\Rule;

final class CategorizerTest extends TestCase
{
    private static function defaultCategorizer(): Categorizer
    {
        return new Categorizer(
            Rule::listFromArray(DefaultData::rules()),
            CategoryCatalog::fromArray(DefaultData::categories()),
        );
    }

    /**
     * @return array{string, ?string}
     */
    private static function categorize(string $libelle, Direction $direction = Direction::Expense): array
    {
        $c = self::defaultCategorizer()->categorize($libelle, $direction);

        return [$c->category1, $c->category2];
    }

    // --- Règles initiales sur exemple.csv et les vrais libellés ---

    public function testReglesInitialesSurLesLignesDeLExemple(): void
    {
        $expected = [
            'Paiement par carte PAIEMENT PAR CARTE X9876 AUCHAN PEROLS 246709 10/01' => ['Alimentation', 'Courses'],
            'Paiement par carte PAIEMENT PAR CARTE X1234 Spotify France PARIS 11/01' => ['Abonnements & télécom', 'Streaming & musique'],
            'Prélèvement PRELEVEMENT PREDICA CREDIT AGRICOLE ASSURANCE VIE PREDISSIME 9 SERIE 2 ECHEANCE 01/2026 - VERSEMENT REGULIER CONTRAT'
                => ['Épargne & virements', 'Assurance vie'],
            'Règlement REGLEMENT ASSU. CNP PRET HABITAT 07/25' => ['Banque & assurances', 'Assurance emprunteur'],
            'Virement émis VIREMENT EMIS WEB M. Martin' => ['Épargne & virements', 'Virement vers un autre compte'],
            'Retrait au distributeur RETRAIT AU DISTRIBUTEUR X9876 LE GRAU DU ROI 07/07 08H31' => ["Retraits d'espèces", 'Distributeur'],
            'Paiement par carte PAIEMENT PAR CARTE X1234 34 MTP PUTEAUX 29/01' => ['À catégoriser', 'Non classé'],
            'Paiement par carte PAIEMENT PAR CARTE X9876 loisir' => ['À catégoriser', 'Non classé'],
        ];
        foreach ($expected as $libelle => $categories) {
            $this->assertSame($categories, self::categorize($libelle), $libelle);
        }
    }

    public function testRevenus(): void
    {
        $this->assertSame(['Remboursements santé', null], self::categorize('Virement en votre faveur VIREMENT EN VOTRE FAVEUR MUTUELLE', Direction::Income));
        $this->assertSame(['Allocations & aides', null], self::categorize('Virement en votre faveur VIREMENT EN VOTRE FAVEUR CAF DE L HERAULT', Direction::Income));
        // Le salaire n'a pas de règle initiale : virement reçu par défaut
        $this->assertSame(['Virements reçus', null], self::categorize('Virement en votre faveur VIREMENT EN VOTRE FAVEUR société X', Direction::Income));
        $this->assertSame(['À catégoriser', null], self::categorize('Avoir AVOIR CARTE X9860 CASTORAMA 15/05', Direction::Income));
    }

    public function testCotisationLimiteeAuTypeCotisation(): void
    {
        $this->assertSame(['Banque & assurances', 'Frais bancaires'], self::categorize('Cotisation COTISATION Offre Compte service'));
        $this->assertSame(
            ['À catégoriser', 'Non classé'],
            self::categorize('Prélèvement PRELEVEMENT CRCAM DU LANGUEDOC CREDIT AGRICOLE ASSURANCE AUTOMOBILE -ECHEANCE COTISATION DU CONTRAT PACIFICA'),
        );
    }

    public function testCarburantAuchanPlusSpecifiqueQueAuchan(): void
    {
        $this->assertSame(['Transport', 'Carburant'], self::categorize('Paiement par carte PAIEMENT PAR CARTE X9860 AUCHAN CARBURANT 299 27/01'));
    }

    public function testCarburantUniquementParCarte(): void
    {
        $this->assertSame(['Transport', 'Carburant'], self::categorize('Paiement par carte PAIEMENT PAR CARTE X9860 TOTAL MARSEILLE 12/02'));
        $this->assertSame(['À catégoriser', 'Non classé'], self::categorize('Prélèvement PRELEVEMENT BP FRANCE ABONNEMENT'));
    }

    public function testTotalEnergiesEstDeLElectricite(): void
    {
        $this->assertSame(['Logement', 'Électricité & gaz'], self::categorize('Prélèvement PRELEVEMENT TotalEnergies Electricite et Gaz France'));
    }

    public function testPretHabitatExigeAussiAssu(): void
    {
        $this->assertSame(['Banque & assurances', 'Assurance emprunteur'], self::categorize('Prélèvement PRELEVEMENT ASSU PRET HABITAT MACIF'));
        $this->assertSame(['À catégoriser', 'Non classé'], self::categorize('Prélèvement PRELEVEMENT PRET HABITAT ECHEANCE'));
    }

    public function testMotifsParMotsEntiers(): void
    {
        // « BP » ne doit pas trouver « BPCE », « CAF » ne doit pas trouver « CAFE », « ORANGE » pas « ORANGERIE »
        $this->assertSame(['À catégoriser', 'Non classé'], self::categorize('Paiement par carte PAIEMENT PAR CARTE X9860 BPCE LEASE 12/02'));
        $this->assertSame(['À catégoriser', 'Non classé'], self::categorize('Paiement par carte PAIEMENT PAR CARTE X9860 L ORANGERIE 12/02'));
        $this->assertSame(['Virements reçus', null], self::categorize('Virement en votre faveur VIREMENT EN VOTRE FAVEUR CAFE DU PORT', Direction::Income));
        // Ponctuation autour du motif : « UEP*SUPER U » contient bien « SUPER U »
        $this->assertSame(['Alimentation', 'Courses'], self::categorize('Paiement par carte PAIEMENT PAR CARTE X9860 UEP*SUPER U LE GRAU 03/02'));
    }

    public function testAmazonEstAConfirmer(): void
    {
        $c = self::defaultCategorizer()->categorize('Paiement par carte PAIEMENT PAR CARTE X9860 AMAZON PAYMENTS 12/02', Direction::Expense);

        $this->assertSame(['Shopping', 'Maison & déco'], [$c->category1, $c->category2]);
        $this->assertTrue($c->needsReview());
    }

    // --- Moteur de règles : priorités ---

    public function testRegleAppriseAvantRegleInitiale(): void
    {
        $categorizer = self::with([
            self::rule('AUCHAN PEROLS', 'Alimentation', 'Courses', Rule::ORIGIN_INITIAL),
            self::rule('AUCHAN', 'Shopping', 'Maison & déco', Rule::ORIGIN_LEARNED),
        ]);

        $c = $categorizer->categorize('Paiement par carte PAIEMENT PAR CARTE X9876 AUCHAN PEROLS 10/01', Direction::Expense);

        $this->assertSame('Shopping', $c->category1, 'apprise avant initiale, même avec un motif plus court');
        $this->assertSame(Categorization::SOURCE_RULE, $c->source);
    }

    public function testAPrioriteEgaleLeMotifLePlusLongGagne(): void
    {
        $categorizer = self::with([
            self::rule('AUCHAN', 'Alimentation', 'Courses', Rule::ORIGIN_LEARNED),
            self::rule('AUCHAN DRIVE', 'Shopping', 'Maison & déco', Rule::ORIGIN_LEARNED),
        ]);

        $this->assertSame('Shopping', $categorizer->categorize('Paiement par carte PAIEMENT PAR CARTE AUCHAN DRIVE 10/01', Direction::Expense)->category1);
        $this->assertSame('Alimentation', $categorizer->categorize('Paiement par carte PAIEMENT PAR CARTE AUCHAN PEROLS 10/01', Direction::Expense)->category1);
    }

    public function testAEgaliteCompleteLOrdreDuFichierGagne(): void
    {
        $categorizer = self::with([
            self::rule('LIDL', 'Alimentation', 'Courses', Rule::ORIGIN_LEARNED),
            self::rule('LIDL', 'Shopping', 'Maison & déco', Rule::ORIGIN_LEARNED),
        ]);

        $this->assertSame('Alimentation', $categorizer->categorize('LIDL', Direction::Expense)->category1);
    }

    public function testUneRegleNeSAppliqueQuASonSens(): void
    {
        $categorizer = self::with([
            new Rule('MUTUELLE', Direction::Income, 'Remboursements santé', null, Rule::ORIGIN_INITIAL),
        ]);

        $c = $categorizer->categorize('Prélèvement PRELEVEMENT MUTUELLE GENERALE', Direction::Expense);

        $this->assertSame([DefaultData::UNCATEGORIZED, DefaultData::UNCATEGORIZED_SUB], [$c->category1, $c->category2]);
        $this->assertSame(Categorization::SOURCE_NONE, $c->source);
    }

    public function testUneCategorieInexistanteNEstJamaisInventee(): void
    {
        $categorizer = self::with([
            self::rule('AUCHAN DRIVE', 'Courses en ligne', 'Drive', Rule::ORIGIN_LEARNED),
            self::rule('AUCHAN', 'Alimentation', 'Courses', Rule::ORIGIN_INITIAL),
        ]);

        // La règle vers une catégorie absente du catalogue est ignorée : la suivante s'applique
        $this->assertSame('Alimentation', $categorizer->categorize('AUCHAN DRIVE', Direction::Expense)->category1);
    }

    public function testTypeOperationEnDernierRecours(): void
    {
        $categorizer = self::with([]);

        $c = $categorizer->categorize('Virement émis VIREMENT EMIS WEB M. Martin', Direction::Expense);
        $this->assertSame(['Épargne & virements', 'Virement vers un autre compte'], [$c->category1, $c->category2]);
        $this->assertSame(Categorization::SOURCE_OPERATION_TYPE, $c->source);

        // Un virement « en votre faveur » au débit (cas anormal) n'est pas classé en revenu
        $this->assertSame(DefaultData::UNCATEGORIZED, $categorizer->categorize('Virement en votre faveur X', Direction::Expense)->category1);
    }

    public function testValeurJsonDeLaCategorie(): void
    {
        $categorizer = self::defaultCategorizer();

        $this->assertSame(
            ['catégorie1' => 'Alimentation', 'catégorie2' => 'Courses'],
            $categorizer->categorize('AUCHAN', Direction::Expense)->toJsonValue(),
        );
        $this->assertSame('Remboursements santé', $categorizer->categorize('CPAM', Direction::Income)->toJsonValue());
    }

    // --- Données initiales ---

    public function testToutesLesReglesInitialesVisentUneCategorieExistante(): void
    {
        $catalog = CategoryCatalog::fromArray(DefaultData::categories());
        foreach (Rule::listFromArray(DefaultData::rules()) as $rule) {
            $exists = $rule->direction === Direction::Expense
                ? $catalog->hasExpense($rule->category1, (string) $rule->category2)
                : $catalog->hasIncome($rule->category1);
            $this->assertTrue($exists, 'catégorie inconnue pour le motif ' . $rule->motif);
            $this->assertSame(Rule::ORIGIN_INITIAL, $rule->origin);
        }
    }

    public function testLesReglesSeRelisentALIdentique(): void
    {
        $data = DefaultData::rules();

        $this->assertSame($data, array_map(static fn (Rule $r): array => $r->toArray(), Rule::listFromArray($data)));
    }

    /**
     * @param list<Rule> $rules
     */
    private static function with(array $rules): Categorizer
    {
        return new Categorizer($rules, CategoryCatalog::fromArray(DefaultData::categories()));
    }

    private static function rule(string $motif, string $cat1, string $cat2, string $origin): Rule
    {
        return new Rule($motif, Direction::Expense, $cat1, $cat2, $origin);
    }
}
