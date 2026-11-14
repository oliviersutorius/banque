<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\Database;
use PDOException;

final class SchemaTest extends TestCase
{
    private readonly Database $db;
    private int $alimentation;
    private int $courses;
    private int $salaire;

    public function __construct()
    {
        $this->db = self::memoryDatabase();
        $this->db->run("INSERT INTO categories (sens, nom, icone, position) VALUES ('dépense', 'Alimentation', '🛒', 0)");
        $this->alimentation = $this->db->lastId();
        $this->db->run("INSERT INTO categories (sens, parent_id, nom, position) VALUES ('dépense', ?, 'Courses', 0)", [$this->alimentation]);
        $this->courses = $this->db->lastId();
        $this->db->run("INSERT INTO categories (sens, nom, icone, position) VALUES ('revenu', 'Salaire', '💼', 0)");
        $this->salaire = $this->db->lastId();
    }

    private function operation(string $sens, int $category, string $date = '2026-01-12', int $amount = 267): void
    {
        $this->db->run('INSERT INTO operations (date, libelle, sens, montant_centimes, categorie_id) VALUES (?, ?, ?, ?, ?)', [$date, 'AUCHAN', $sens, $amount, $category]);
    }

    private function rejected(callable $fn, string $message): void
    {
        $this->assertThrows(PDOException::class, $fn);
        $this->assertTrue(true, $message);
    }

    public function testMoisCalculeDepuisLaDate(): void
    {
        $this->operation('dépense', $this->courses, '2026-01-31');
        $this->assertSame('202601', $this->db->value('SELECT mois FROM operations'));
        $this->db->run("UPDATE operations SET date = '2025-12-02'");
        $this->assertSame('202512', $this->db->value('SELECT mois FROM operations'));
    }

    public function testUneOperationViseUneFeuilleDuMemeSens(): void
    {
        $this->operation('dépense', $this->courses);
        $this->operation('revenu', $this->salaire);
        $this->rejected(fn () => $this->operation('dépense', $this->alimentation), 'dépense sur une catégorie 1');
        $this->rejected(fn () => $this->operation('dépense', $this->salaire), 'dépense sur un revenu');
        $this->rejected(fn () => $this->operation('revenu', $this->courses), 'revenu sur une dépense');
        $this->rejected(fn () => $this->db->run('UPDATE operations SET categorie_id = ? WHERE sens = ?', [$this->salaire, 'dépense']), 'mise à jour vérifiée');
        $this->assertSame(2, $this->db->value('SELECT count(*) FROM operations'));
    }

    public function testUneRegleViseUneFeuilleDuMemeSens(): void
    {
        $this->db->run("INSERT INTO regles (motif, sens, categorie_id, origine) VALUES ('AUCHAN', 'dépense', ?, 'initiale')", [$this->courses]);
        $this->rejected(fn () => $this->db->run("INSERT INTO regles (motif, sens, categorie_id, origine) VALUES ('X', 'revenu', ?, 'apprise')", [$this->courses]), 'revenu sur dépense');
        $this->rejected(fn () => $this->db->run("INSERT INTO regles (motif, sens, categorie_id, origine) VALUES ('X', 'dépense', ?, 'autre')", [$this->courses]), 'origine inconnue');
    }

    public function testContraintesDesColonnes(): void
    {
        $this->rejected(fn () => $this->operation('dépense', $this->courses, '12/01/2026'), 'date au format ISO');
        $this->rejected(fn () => $this->operation('dépense', $this->courses, '2026-01-12', -1), 'montant positif');
        $this->rejected(fn () => $this->operation('autre', $this->courses), 'sens connu');
        $this->rejected(fn () => $this->db->run("INSERT INTO operations (date, libelle, sens, montant_centimes, categorie_id) VALUES ('2026-01-01', 'X', 'dépense', '2,67', ?)", [$this->courses]), 'table STRICT : montant entier');
        $this->rejected(fn () => $this->db->run("INSERT INTO compte (id, identifiant, mot_de_passe) VALUES (2, 'x', 'y')"), 'un seul compte');
    }

    public function testNomsUniquesParNiveau(): void
    {
        $this->rejected(fn () => $this->db->run("INSERT INTO categories (sens, nom, position) VALUES ('dépense', 'Alimentation', 5)"), 'catégorie 1 en double');
        $this->rejected(fn () => $this->db->run("INSERT INTO categories (sens, parent_id, nom, position) VALUES ('dépense', ?, 'Courses', 5)", [$this->alimentation]), 'sous-catégorie en double');
        // Même nom dans un autre sens ou sous un autre parent : accepté
        $this->db->run("INSERT INTO categories (sens, nom, position) VALUES ('revenu', 'Alimentation', 5)");
        $this->db->run("INSERT INTO categories (sens, nom, position) VALUES ('dépense', 'Maison', 5)");
        $this->db->run("INSERT INTO categories (sens, parent_id, nom, position) VALUES ('dépense', ?, 'Courses', 0)", [$this->db->lastId()]);
        $this->assertSame(6, $this->db->value('SELECT count(*) FROM categories'));
    }

    public function testSousCategorieSeulementSousUneDepenseDePremierNiveau(): void
    {
        $this->rejected(fn () => $this->db->run("INSERT INTO categories (sens, parent_id, nom, position) VALUES ('revenu', ?, 'Primes', 0)", [$this->salaire]), 'pas de sous-catégorie de revenu');
        $this->rejected(fn () => $this->db->run("INSERT INTO categories (sens, parent_id, nom, position) VALUES ('dépense', ?, 'Bio', 0)", [$this->courses]), 'pas de 3e niveau');
        $this->rejected(fn () => $this->db->run('UPDATE categories SET parent_id = NULL WHERE id = ?', [$this->courses]), 'hiérarchie figée');
    }

    public function testSuppressionRestreinteEtCascade(): void
    {
        $this->operation('dépense', $this->courses);
        $this->rejected(fn () => $this->db->run('DELETE FROM categories WHERE id = ?', [$this->alimentation]), 'catégorie utilisée');
        $this->db->run('DELETE FROM operations');
        $this->db->run('DELETE FROM categories WHERE id = ?', [$this->alimentation]);
        $this->assertSame(0, $this->db->value('SELECT count(*) FROM categories WHERE id = ?', [$this->courses]), 'sous-catégories supprimées avec le parent');

        $this->db->run("INSERT INTO regles (motif, sens, categorie_id, origine) VALUES ('PAIE', 'revenu', ?, 'initiale')", [$this->salaire]);
        $rule = $this->db->lastId();
        $this->db->run("INSERT INTO regle_mots (regle_id, position, mot) VALUES (?, 0, 'MENSUELLE')", [$rule]);
        $this->db->run('DELETE FROM regles WHERE id = ?', [$rule]);
        $this->assertSame(0, $this->db->value('SELECT count(*) FROM regle_mots'), 'mots supprimés avec la règle');
    }

    public function testLesRequetesFrequentesUtilisentUnIndex(): void
    {
        $plans = [
            "SELECT * FROM operations WHERE mois = '202601' ORDER BY date, id" => 'operations_mois_date',
            "SELECT * FROM operations WHERE mois BETWEEN '202601' AND '202612' ORDER BY mois, date, id" => 'operations_mois_date',
            'SELECT count(*) FROM operations WHERE categorie_id = 3' => 'operations_categorie',
            'SELECT * FROM sessions WHERE expire_le < 100' => 'sessions_expiration',
        ];
        foreach ($plans as $sql => $index) {
            $plan = implode(' | ', array_column($this->db->all('EXPLAIN QUERY PLAN ' . $sql), 'detail'));
            $this->assertTrue(str_contains($plan, $index), $sql . ' → ' . $plan);
        }
    }
}
