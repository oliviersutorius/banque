<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\CategoryRepository;
use Banque\Database;
use Banque\DefaultData;
use Banque\LegacyJsonMigration;
use Banque\OperationRepository;
use Banque\RuleRepository;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;

final class LegacyJsonMigrationTest extends TestCase
{
    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    private const COURSES = ['catégorie1' => 'Alimentation', 'catégorie2' => 'Courses'];

    private readonly TempDir $tmp;
    private readonly string $data;

    public function __construct()
    {
        $this->tmp = new TempDir();
        $this->data = $this->tmp->path . '/data';
        foreach (['', '/mois', '/sauvegardes', '/sessions', '/journal'] as $dir) {
            mkdir($this->data . $dir);
        }
        $rules = DefaultData::rules();
        $rules[] = ['motif' => 'ACME', 'type' => 'revenu', 'catégorie' => 'Salaire', 'origine' => 'apprise'];
        $rules[] = ['motif' => 'PRET', 'type' => 'dépense', 'catégorie1' => 'Banque & assurances', 'catégorie2' => 'Assurance emprunteur', 'et' => ['ASSU', 'HABITAT'], 'origine' => 'apprise'];
        $this->write('categories.json', DefaultData::categories());
        $this->write('regles.json', $rules);
        $this->write('parametres.json', ['thème' => 'nuit']);
        $this->write('mois/202601.json', ['mois' => '202601', 'lignes' => [
            ['date' => '05/01/2026', 'libelle' => 'AUCHAN', 'catégorie' => self::COURSES, 'dépense' => '2,67'],
            ['date' => '12/01/2026', 'libelle' => 'B', 'catégorie' => self::COURSES, 'dépense' => '10,00'],
            ['date' => '12/01/2026', 'libelle' => 'A', 'catégorie' => self::COURSES, 'dépense' => '10,00'],
            ['date' => '30/01/2026', 'libelle' => 'ACME', 'catégorie' => 'Salaire', 'revenu' => '2645,05'],
        ]]);
        $this->write('mois/202512.json', ['mois' => '202512', 'lignes' => [
            ['date' => '24/12/2025', 'libelle' => 'CADEAU', 'catégorie' => ['catégorie1' => 'À catégoriser', 'catégorie2' => 'Non classé'], 'dépense' => '1234,50'],
        ]]);
        $this->write('compte.json', ['identifiant' => 'olivier', 'mot_de_passe' => '$argon2id$v=19$m=1024,t=1,p=1$c2VsMTIzNDU2Nzg$abc', 'dernier_pas_totp' => 42, 'totp' => 'Y2hpZmZyw6k=', 'codes_secours' => ['h1', 'h2']]);
        $this->write('tentatives.json', ['ip:203.0.113.5' => ['echecs' => 2, 'dernier' => 1700000000, 'jusqu_a' => null]]);
        touch($this->data . '/categories.json.lock');
        file_put_contents($this->data . '/sauvegardes/regles-20261110.json', '[]');
        file_put_contents($this->data . '/sessions/sess_abc', 'x');
        file_put_contents($this->data . '/journal/connexions.log', "{\"evenement\":\"ancien\"}\n");
    }

    /**
     * @param array<mixed> $content
     */
    private function write(string $file, array $content): void
    {
        file_put_contents($this->data . '/' . $file, json_encode($content, self::FLAGS));
    }

    private function migrated(): array
    {
        $migration = new LegacyJsonMigration($this->data);
        $report = $migration->read();
        $db = self::memoryDatabase();
        $migration->import($db);

        return [$migration, $report, $db];
    }

    public function testRapportDeSimulation(): void
    {
        $report = (new LegacyJsonMigration($this->data))->read();
        $this->assertSame([], $report['anomalies']);
        $this->assertSame(5, $report['lignes']);
        $this->assertSame(['202512', '202601'], array_map('strval', array_keys($report['mois'])));
        $this->assertSame(['lignes' => 4, 'dépenses' => 2267, 'revenus' => 264505], $report['mois']['202601']);
        $this->assertSame(count(DefaultData::rules()) + 2, $report['règles']);
        $this->assertTrue($report['compte']);
        $this->assertSame(1, $report['tentatives']);
    }

    public function testMigrationFideleEtVerifiee(): void
    {
        [$migration, , $db] = $this->migrated();
        $this->assertSame([], $migration->compare($db));

        $categories = new CategoryRepository($db);
        $operations = new OperationRepository($db, $categories);
        $this->assertSame(['AUCHAN', 'B', 'A', 'ACME'], array_column($operations->lines('202601'), 'libelle'), 'ordre du fichier conservé');
        $this->assertSame('1234,50', $operations->lines('202512')[0]['dépense']);
        $rules = (new RuleRepository($db, $categories))->all();
        $this->assertSame(['ASSU', 'HABITAT'], end($rules)->and);
        $this->assertSame('nuit', $db->value("SELECT valeur FROM parametres WHERE cle = 'thème'"));
        $this->assertSame(['olivier', 42, 'Y2hpZmZyw6k='], array_values($db->one('SELECT identifiant, dernier_pas_totp, totp FROM compte') ?? []));
        $this->assertSame(2, $db->value('SELECT count(*) FROM codes_secours'));
        $this->assertSame(2, $db->value("SELECT echecs FROM tentatives WHERE cle = 'ip:203.0.113.5'"));
    }

    public function testLaComparaisonDetecteUneDifference(): void
    {
        [$migration, , $db] = $this->migrated();
        $db->run("UPDATE operations SET montant_centimes = 1 WHERE libelle = 'B'");
        $db->run("UPDATE parametres SET valeur = 'sauge'");
        $differences = $migration->compare($db);
        $this->assertSame(2, count($differences));
        $this->assertTrue(str_starts_with($differences[0], 'Mois 202601 différent'), $differences[0]);
    }

    public function testAnomaliesBloquantes(): void
    {
        $this->write('mois/202602.json', ['mois' => '202602', 'lignes' => [
            ['date' => '01/02/2026', 'libelle' => 'X', 'catégorie' => ['catégorie1' => 'Inconnue', 'catégorie2' => 'Y'], 'dépense' => '1,00'],
            ['date' => '02/02/2026', 'libelle' => 'Y', 'catégorie' => self::COURSES, 'dépense' => 'abc'],
            ['date' => '03/03/2026', 'libelle' => 'Z', 'catégorie' => self::COURSES, 'dépense' => '1,00'],
        ]]);
        $migration = new LegacyJsonMigration($this->data);
        $report = $migration->read();
        $this->assertSame(3, count($report['anomalies']));
        $this->assertTrue(str_contains($report['anomalies'][2], 'hors du mois'), $report['anomalies'][2]);
        $db = self::memoryDatabase();
        $this->assertThrows(RuntimeException::class, static fn () => $migration->import($db));
        $this->assertSame(0, $db->value('SELECT count(*) FROM categories'), 'rien n\'est écrit');
    }

    public function testRefuseUneBaseDejaRemplie(): void
    {
        $migration = new LegacyJsonMigration($this->data);
        $migration->read();
        $db = self::memoryDatabase();
        DefaultData::seed($db);
        $this->assertThrows(RuntimeException::class, static fn () => $migration->import($db));
    }

    public function testArchivePuisNettoyage(): void
    {
        $migration = new LegacyJsonMigration($this->data);
        $archive = $migration->archive($this->tmp->path . '/base/archives');
        $this->assertSame('600', decoct(fileperms($archive) & 0777));
        $names = [];
        foreach (new RecursiveIteratorIterator(new PharData($archive)) as $file) {
            $names[] = substr($file->getPathname(), strlen('phar://' . $archive) + 1);
        }
        sort($names);
        $this->assertSame(['categories.json', 'categories.json.lock', 'compte.json', 'journal/connexions.log', 'mois/202512.json', 'mois/202601.json', 'parametres.json', 'regles.json', 'sauvegardes/regles-20261110.json', 'sessions/sess_abc', 'tentatives.json'], $names);

        // L'API a déjà démarré : log/ contient des lignes récentes
        mkdir($this->tmp->path . '/log');
        file_put_contents($this->tmp->path . '/log/connexions.log', "{\"evenement\":\"recent\"}\n");
        $result = $migration->cleanup($this->tmp->path . '/log');

        $this->assertSame([], $result['inconnus']);
        $this->assertTrue(!is_dir($this->data), 'data/ supprimé');
        $this->assertSame("{\"evenement\":\"ancien\"}\n{\"evenement\":\"recent\"}\n", file_get_contents($this->tmp->path . '/log/connexions.log'));
    }

    public function testUnFichierInconnuNEstPasSupprime(): void
    {
        file_put_contents($this->data . '/notes.txt', 'à garder');
        $result = (new LegacyJsonMigration($this->data))->cleanup($this->tmp->path . '/log');
        $this->assertSame(['notes.txt'], $result['inconnus']);
        $this->assertSame('à garder', file_get_contents($this->data . '/notes.txt'));
        $this->assertTrue(!is_dir($this->data . '/mois') && !is_file($this->data . '/categories.json'), 'fichiers migrés supprimés');
        $this->assertTrue(is_file($this->tmp->path . '/log/connexions.log'));
    }
}
