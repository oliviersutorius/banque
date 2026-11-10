<?php

declare(strict_types=1);

namespace Banque;

/**
 * Catégories et règles initiales, écrites dans data/ au premier lancement.
 * Les tableaux ont exactement la forme des fichiers JSON.
 */
final class DefaultData
{
    public const UNCATEGORIZED = 'À catégoriser';
    public const UNCATEGORIZED_SUB = 'Non classé';
    public const THEMES = ['guimauve', 'sauge', 'nuit'];

    /**
     * @return array{dépenses: list<array{nom: string, icone: string, sous_catégories: list<string>}>, revenus: list<array{nom: string, icone: string}>}
     */
    public static function categories(): array
    {
        $expenses = [
            ['🏠', 'Logement', ['Loyer', 'Crédit immobilier', 'Charges & copropriété', 'Électricité & gaz', 'Eau', 'Assurance habitation', 'Travaux & entretien']],
            ['🛒', 'Alimentation', ['Courses', 'Restaurant', 'Boulangerie & snacks', 'Livraison de repas']],
            ['🚗', 'Transport', ['Carburant', 'Péage & parking', 'Transports en commun', 'Train & avion', 'Entretien véhicule', 'Assurance auto']],
            ['💊', 'Santé', ['Médecin', 'Pharmacie', 'Mutuelle', 'Optique & dentaire']],
            ['📱', 'Abonnements & télécom', ['Téléphone mobile', 'Internet', 'Streaming & musique', 'Logiciels & presse']],
            ['🎈', 'Loisirs & culture', ['Sorties', 'Sport', 'Voyages & vacances', 'Livres & jeux', 'Cadeaux']],
            ['🛍️', 'Shopping', ['Vêtements', 'Maison & déco', 'High-tech', 'Beauté']],
            ['👨‍👩‍👧', 'Famille', ['Garde & scolarité', 'Enfants', 'Animaux']],
            ['🏦', 'Banque & assurances', ['Frais bancaires', 'Assurance emprunteur', 'Autres assurances', 'Remboursement de prêt']],
            ['🧾', 'Impôts & taxes', ['Impôt sur le revenu', 'Taxe foncière', 'Autres taxes & amendes']],
            ['🐷', 'Épargne & virements', ['Assurance vie', 'Livrets', 'Virement vers un autre compte']],
            ['💶', "Retraits d'espèces", ['Distributeur']],
            ['❔', self::UNCATEGORIZED, [self::UNCATEGORIZED_SUB]],
        ];
        $incomes = [
            ['💼', 'Salaire'],
            ['🩺', 'Remboursements santé'],
            ['🤝', 'Allocations & aides'],
            ['🔁', 'Virements reçus'],
            ['📈', "Revenus d'épargne"],
            ['✨', 'Autres revenus'],
            ['❔', self::UNCATEGORIZED],
        ];

        return [
            'dépenses' => array_map(
                static fn (array $c): array => ['nom' => $c[1], 'icone' => $c[0], 'sous_catégories' => $c[2]],
                $expenses,
            ),
            'revenus' => array_map(static fn (array $c): array => ['nom' => $c[1], 'icone' => $c[0]], $incomes),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rules(): array
    {
        $rules = [];
        $expense = static function (array $motifs, string $cat1, string $cat2, array $extra = []) use (&$rules): void {
            foreach ($motifs as $motif) {
                $rules[] = ['motif' => $motif, 'type' => 'dépense', 'catégorie1' => $cat1, 'catégorie2' => $cat2]
                    + $extra + ['origine' => 'initiale'];
            }
        };
        $income = static function (array $motifs, string $category) use (&$rules): void {
            foreach ($motifs as $motif) {
                $rules[] = ['motif' => $motif, 'type' => 'revenu', 'catégorie' => $category, 'origine' => 'initiale'];
            }
        };

        $expense(['AUCHAN', 'CARREFOUR', 'LECLERC', 'INTERMARCHE', 'LIDL', 'ALDI', 'MONOPRIX', 'CASINO', 'SUPER U', 'FRANPRIX', 'PICARD'], 'Alimentation', 'Courses');
        $expense(['SPOTIFY', 'NETFLIX', 'DEEZER', 'DISNEY', 'CANAL'], 'Abonnements & télécom', 'Streaming & musique');
        $expense(['FREE MOBILE', 'ORANGE', 'SFR', 'BOUYGUES', 'SOSH'], 'Abonnements & télécom', 'Téléphone mobile');
        $expense(['PREDICA', 'ASSURANCE VIE'], 'Épargne & virements', 'Assurance vie');
        $expense(['ASSU. CNP'], 'Banque & assurances', 'Assurance emprunteur');
        $expense(['PRET HABITAT'], 'Banque & assurances', 'Assurance emprunteur', ['et' => ['ASSU']]);
        // COTISATION apparaît aussi dans les prélèvements d'assurance (« ECHEANCE COTISATION DU CONTRAT ») :
        // la règle est limitée aux opérations de type Cotisation
        $expense(['COTISATION'], 'Banque & assurances', 'Frais bancaires', ['type_operation' => 'COTISATION']);
        $expense(['FRAIS', 'AGIOS'], 'Banque & assurances', 'Frais bancaires');
        $expense(['DGFIP', 'IMPOT'], 'Impôts & taxes', 'Impôt sur le revenu');
        $expense(['EDF', 'ENGIE', 'TOTALENERGIES'], 'Logement', 'Électricité & gaz');
        $expense(['TOTAL', 'ESSO', 'BP', 'SHELL', 'AVIA'], 'Transport', 'Carburant', ['type_operation' => 'CARTE']);
        $expense(['AUCHAN CARBURANT'], 'Transport', 'Carburant');
        $expense(['SNCF', 'OUIGO', 'AIR FRANCE'], 'Transport', 'Train & avion');
        $expense(['PHARMACIE'], 'Santé', 'Pharmacie');
        $expense(['AMAZON'], 'Shopping', 'Maison & déco', ['a_confirmer' => true]);
        $income(['MUTUELLE', 'CPAM'], 'Remboursements santé');
        $income(['CAF'], 'Allocations & aides');

        return $rules;
    }

    /**
     * @return array{thème: string}
     */
    public static function settings(): array
    {
        return ['thème' => 'guimauve'];
    }
}
