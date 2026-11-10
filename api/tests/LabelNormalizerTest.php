<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\LabelNormalizer;

final class LabelNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}> libellé => [type, typeLabel, marchand]
     */
    private static function cases(): array
    {
        return [
            // Exemple du cahier des charges
            'Paiement par carte PAIEMENT PAR CARTE X9876 AUCHAN PEROLS 246709 10/01'
                => ['CARTE', 'PAIEMENT PAR CARTE', 'AUCHAN PEROLS'],
            // Lignes de exemple.csv
            'Paiement par carte PAIEMENT PAR CARTE X1234 34 MTP PUTEAUX 29/01'
                => ['CARTE', 'PAIEMENT PAR CARTE', '34 MTP PUTEAUX'],
            'Paiement par carte PAIEMENT PAR CARTE X1234 Spotify France PARIS 11/01'
                => ['CARTE', 'PAIEMENT PAR CARTE', 'SPOTIFY FRANCE PARIS'],
            'Paiement par carte PAIEMENT PAR CARTE X9876 loisir'
                => ['CARTE', 'PAIEMENT PAR CARTE', 'LOISIR'],
            'Virement en votre faveur VIREMENT EN VOTRE FAVEUR société X'
                => ['VIREMENT_RECU', 'VIREMENT EN VOTRE FAVEUR', 'SOCIETE X'],
            'Prélèvement PRELEVEMENT PREDICA CREDIT AGRICOLE ASSURANCE VIE PREDISSIME 9 SERIE 2 ECHEANCE 01/2026 - VERSEMENT REGULIER CONTRAT'
                => ['PRELEVEMENT', 'PRELEVEMENT', 'PREDICA CREDIT AGRICOLE ASSURANCE VIE PREDISSIME 9 SERIE 2 ECHEANCE VERSEMENT REGULIER CONTRAT'],
            'Règlement REGLEMENT ASSU. CNP PRET HABITAT 07/25'
                => ['REGLEMENT', 'REGLEMENT', 'ASSU. CNP PRET HABITAT'],
            'Virement émis VIREMENT EMIS WEB M. Martin'
                => ['VIREMENT_EMIS', 'VIREMENT EMIS', 'WEB M. MARTIN'],
            'Retrait au distributeur RETRAIT AU DISTRIBUTEUR X9876 LE GRAU DU ROI 07/07 08H31'
                => ['RETRAIT', 'RETRAIT AU DISTRIBUTEUR', 'LE GRAU DU ROI'],
            'Virement en votre faveur VIREMENT EN VOTRE FAVEUR MUTUELLE'
                => ['VIREMENT_RECU', 'VIREMENT EN VOTRE FAVEUR', 'MUTUELLE'],
            // Formes rencontrées dans les vrais exports (références de mandat, échéances JJ/MM/AA…)
            'Prélèvement PRELEVEMENT FREE MOBILE ++FM-19704059-1 FR07ZZZ591778 fmpmt-2344726118'
                => ['PRELEVEMENT', 'PRELEVEMENT', 'FREE MOBILE'],
            'Prélèvement PRELEVEMENT CA ASSURANCES RETRAITE PER ECHEANCE FR22ZZZ885B55'
                => ['PRELEVEMENT', 'PRELEVEMENT', 'CA ASSURANCES RETRAITE PER ECHEANCE'],
            'Remboursement de prêt REMBOURSEMENT DE PRET 00000042201 ECHEANCE 10/04/25'
                => ['REMBOURSEMENT_PRET', 'REMBOURSEMENT DE PRET', 'ECHEANCE'],
            'Virement reçu VIREMENT RECU Rétrocession intérets débiteurs'
                => ['VIREMENT_RECU', 'VIREMENT RECU', 'RETROCESSION INTERETS DEBITEURS'],
            'Cotisation COTISATION Offre Compte service'
                => ['COTISATION', 'COTISATION', 'OFFRE COMPTE SERVICE'],
            'Avoir AVOIR CARTE X9860 CASTORAMA 15/05'
                => ['AVOIR', 'AVOIR', 'CARTE CASTORAMA'],
            'Remise de chèque REMISE DE CHEQUE 1234567'
                => ['REMISE_CHEQUE', 'REMISE DE CHEQUE', ''],
            'Chèque CHEQUE 7654321'
                => ['CHEQUE', 'CHEQUE', ''],
            // Type inconnu : tout le libellé devient le détail
            'Opération mystère ABC 12/03'
                => ['AUTRE', '', 'OPERATION MYSTERE ABC'],
        ];
    }

    public function testNormaliseLesLibelles(): void
    {
        $normalizer = new LabelNormalizer();
        foreach (self::cases() as $libelle => [$type, $typeLabel, $merchant]) {
            $label = $normalizer->normalize($libelle);
            $this->assertSame([$type, $typeLabel, $merchant], [$label->type, $label->typeLabel, $label->merchant], $libelle);
        }
    }

    public function testTexteCompletPourLesRegles(): void
    {
        $label = (new LabelNormalizer())->normalize('Cotisation COTISATION Offre Compte service');

        $this->assertSame('COTISATION OFFRE COMPTE SERVICE', $label->text());
    }

    public function testLeTypeDoitEtreUnMotEntier(): void
    {
        // « FRAISIER » ne doit pas être lu comme le type « FRAIS »
        $label = (new LabelNormalizer())->normalize('FRAISIER DU MARCHE');

        $this->assertSame('AUTRE', $label->type);
        $this->assertSame('FRAISIER DU MARCHE', $label->merchant);
    }
}
