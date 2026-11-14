<?php

declare(strict_types=1);

namespace Banque;

use FilesystemIterator;
use InvalidArgumentException;
use JsonException;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Migration de l'ancien stockage JSON (data/) vers la base SQLite, en trois temps (voir api/bin/migrer-json.php) :
 *
 * 1. read()    : lecture et validation de tous les fichiers, sans rien écrire ; rapport et anomalies ;
 * 2. import()  : copie dans la base en une transaction, puis compare() vérifie que la base contient exactement les
 *                mêmes données (catégories, règles, chaque ligne de chaque mois, paramètres, compte) ;
 * 3. archive() puis cleanup() : archive .tar.gz de data/, suppression des fichiers migrés, et data/ devient le
 *                dossier des journaux.
 */
final class LegacyJsonMigration
{
    /** Fichiers et dossiers migrés en base (supprimés par cleanup) */
    private const MIGRATED_FILES = ['categories.json', 'regles.json', 'parametres.json', 'compte.json', 'tentatives.json'];
    private const MIGRATED_DIRS = ['mois', 'sessions', 'sauvegardes'];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(private readonly string $source)
    {
    }

    /**
     * Lit et valide tout l'ancien dossier.
     *
     * @return array{catégories: int, sous_catégories: int, règles: int, mois: array<string, array{lignes: int, dépenses: int, revenus: int}>, lignes: int, compte: bool, tentatives: int, anomalies: list<string>}
     */
    public function read(): array
    {
        $anomalies = [];
        $categories = $this->json('categories.json', $anomalies) ?? ['dépenses' => [], 'revenus' => []];
        $catalog = null;
        try {
            $catalog = CategoryCatalog::fromArray($categories);
        } catch (InvalidArgumentException $e) {
            $anomalies[] = $e->getMessage();
        }

        $rules = [];
        try {
            $rules = Rule::listFromArray($this->json('regles.json', $anomalies) ?? []);
        } catch (InvalidArgumentException $e) {
            $anomalies[] = 'regles.json : ' . $e->getMessage();
        }
        foreach ($rules as $i => $rule) {
            $exists = $catalog !== null && ($rule->direction === Direction::Expense
                ? $catalog->hasExpense($rule->category1, (string) $rule->category2)
                : $catalog->hasIncome($rule->category1));
            if (!$exists) {
                $anomalies[] = sprintf('regles.json, règle n° %d (« %s ») : catégorie inconnue', $i + 1, $rule->motif);
            }
        }

        $months = [];
        foreach (glob($this->source . '/mois/*.json') ?: [] as $file) {
            $month = basename($file, '.json');
            if (!OperationRepository::isValidMonth($month)) {
                $anomalies[] = 'mois/' . basename($file) . ' : nom de fichier inattendu (ignoré)';
                continue;
            }
            $content = $this->json('mois/' . basename($file), $anomalies);
            $months[$month] = [];
            foreach (is_array($content['lignes'] ?? null) ? $content['lignes'] : [] as $i => $line) {
                try {
                    $months[$month][] = self::validLine($line, $month, $catalog);
                } catch (InvalidArgumentException $e) {
                    $anomalies[] = sprintf('mois/%s.json, ligne %d : %s', $month, $i + 1, $e->getMessage());
                }
            }
        }
        ksort($months);

        $settings = is_file($this->source . '/parametres.json') ? ($this->json('parametres.json', $anomalies) ?? []) : [];
        $account = is_file($this->source . '/compte.json') ? $this->json('compte.json', $anomalies) : null;
        if ($account !== null && (!is_string($account['identifiant'] ?? null) || !is_string($account['mot_de_passe'] ?? null))) {
            $anomalies[] = 'compte.json : identifiant ou mot de passe manquant';
        }
        $attempts = is_file($this->source . '/tentatives.json') ? ($this->json('tentatives.json', $anomalies) ?? []) : [];

        $this->data = [
            'categories' => $categories,
            'rules' => $rules,
            'months' => $months,
            'settings' => $settings,
            'account' => $account,
            'attempts' => $attempts,
            'anomalies' => $anomalies,
        ];

        $summary = [];
        foreach ($months as $month => $lines) {
            $summary[$month] = ['lignes' => count($lines)] + self::totals($lines);
        }

        return [
            'catégories' => count($categories['dépenses'] ?? []) + count($categories['revenus'] ?? []),
            'sous_catégories' => array_sum(array_map(static fn (mixed $c): int => is_array($c) ? count($c['sous_catégories'] ?? []) : 0, $categories['dépenses'] ?? [])),
            'règles' => count($rules),
            'mois' => $summary,
            'lignes' => array_sum(array_column($summary, 'lignes')),
            'compte' => $account !== null,
            'tentatives' => count($attempts),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Copie les données lues dans une base au schéma à jour et vide, en une seule transaction.
     *
     * @throws RuntimeException s'il reste des anomalies ou si la base n'est pas vide
     */
    public function import(Database $db): void
    {
        $data = $this->loaded();
        if ($data['anomalies'] !== []) {
            throw new RuntimeException('Migration impossible : ' . count($data['anomalies']) . ' anomalie(s) dans les fichiers');
        }
        $categories = new CategoryRepository($db);
        $db->transaction(function () use ($db, $data, $categories): void {
            if (!$categories->isEmpty() || $db->value('SELECT count(*) FROM operations') !== 0) {
                throw new RuntimeException('La base n\'est pas vide');
            }
            $categories->insertTree($data['categories']);
            (new RuleRepository($db, $categories))->insertAll($data['rules']);
            $operations = new OperationRepository($db, $categories);
            foreach ($data['months'] as $lines) {
                $operations->insertLines($lines);
            }
            (new SettingsRepository($db))->setAll($data['settings']);

            $account = $data['account'];
            if (is_array($account)) {
                $db->run(
                    'INSERT INTO compte (id, identifiant, mot_de_passe, totp, dernier_pas_totp) VALUES (1, ?, ?, ?, ?)',
                    [$account['identifiant'], $account['mot_de_passe'], is_string($account['totp'] ?? null) ? $account['totp'] : null, (int) ($account['dernier_pas_totp'] ?? 0)],
                );
                foreach (is_array($account['codes_secours'] ?? null) ? $account['codes_secours'] : [] as $hash) {
                    $db->run('INSERT INTO codes_secours (compte_id, empreinte) VALUES (1, ?)', [(string) $hash]);
                }
            }
            foreach ($data['attempts'] as $key => $entry) {
                if (is_array($entry)) {
                    $db->run(
                        'INSERT INTO tentatives (cle, echecs, dernier, jusqu_a) VALUES (?, ?, ?, ?)',
                        [(string) $key, (int) ($entry['echecs'] ?? 0), (int) ($entry['dernier'] ?? 0), is_int($entry['jusqu_a'] ?? null) ? $entry['jusqu_a'] : null],
                    );
                }
            }
        });
    }

    /**
     * Compare la base aux fichiers : rien ne doit manquer, rien ne doit différer.
     *
     * @return list<string> différences (vide si la base est fidèle)
     */
    public function compare(Database $db): array
    {
        $data = $this->loaded();
        $differences = [];
        $categories = new CategoryRepository($db);

        if ($categories->tree() !== self::normalizedTree($data['categories'])) {
            $differences[] = 'Catégories différentes';
        }

        $stored = array_map(static fn (Rule $r): array => array_diff_key($r->toArray(), ['id' => true]), (new RuleRepository($db, $categories))->all());
        $expected = array_map(static fn (Rule $r): array => $r->toArray(), $data['rules']);
        if ($stored !== $expected) {
            $differences[] = sprintf('Règles différentes (%d en base, %d dans regles.json)', count($stored), count($expected));
        }

        $operations = new OperationRepository($db, $categories);
        $storedMonths = $operations->months();
        $expectedMonths = array_map('strval', array_keys(array_filter($data['months'], static fn (array $l): bool => $l !== [])));
        if ($storedMonths !== $expectedMonths) {
            $differences[] = 'Mois différents : ' . implode(', ', array_merge(array_diff($expectedMonths, $storedMonths), array_diff($storedMonths, $expectedMonths)));
        }
        foreach ($data['months'] as $month => $lines) {
            $inBase = array_map(static fn (array $l): array => array_diff_key($l, ['id' => true]), $operations->lines((string) $month));
            if ($inBase !== $lines) {
                $base = self::totals($inBase);
                $files = self::totals($lines);
                $differences[] = sprintf(
                    'Mois %s différent : %d lignes en base (dépenses %s, revenus %s), %d dans le fichier (dépenses %s, revenus %s)',
                    $month,
                    count($inBase),
                    Amount::format($base['dépenses']),
                    Amount::format($base['revenus']),
                    count($lines),
                    Amount::format($files['dépenses']),
                    Amount::format($files['revenus']),
                );
            }
        }

        $settings = array_filter($data['settings'], 'is_string');
        ksort($settings);
        if ((new SettingsRepository($db))->all() !== $settings) {
            $differences[] = 'Paramètres différents';
        }

        $account = $data['account'];
        $row = $db->one('SELECT identifiant, mot_de_passe, totp, dernier_pas_totp FROM compte WHERE id = 1');
        $codes = (int) $db->value('SELECT count(*) FROM codes_secours');
        if ($account === null ? $row !== null : (
            $row === null
            || $row['identifiant'] !== $account['identifiant']
            || $row['mot_de_passe'] !== $account['mot_de_passe']
            || $row['totp'] !== ($account['totp'] ?? null)
            || $codes !== count(is_array($account['codes_secours'] ?? null) ? $account['codes_secours'] : [])
        )) {
            $differences[] = 'Compte différent';
        }

        return $differences;
    }

    /**
     * Archive .tar.gz de tout l'ancien dossier (sauvegardes et journal compris), relue pour vérification.
     *
     * @return string chemin de l'archive (0600)
     */
    public function archive(string $archiveDir): string
    {
        if (!is_dir($archiveDir) && !mkdir($archiveDir, 0700, true) && !is_dir($archiveDir)) {
            throw new RuntimeException('Impossible de créer ' . $archiveDir);
        }
        $base = $archiveDir . '/data-avant-migration-' . date('Ymd-His');
        $tar = new PharData($base . '.tar');
        $tar->buildFromDirectory($this->source);
        $tar->compress(Phar::GZ);
        unset($tar);
        unlink($base . '.tar');
        $archive = $base . '.tar.gz';
        chmod($archive, 0600);

        $archived = 0;
        foreach (new RecursiveIteratorIterator(new PharData($archive)) as $file) {
            $archived++;
        }
        $expected = iterator_count(self::files($this->source));
        if ($archived !== $expected) {
            throw new RuntimeException(sprintf('Archive incomplète : %d fichiers sur %d', $archived, $expected));
        }

        return $archive;
    }

    /**
     * Supprime les fichiers migrés en base, puis fait de l'ancien dossier le dossier des journaux : journal/connexions.log
     * devient <logDir>/connexions.log. Si <logDir> existe déjà (l'API a démarré entre-temps), l'ancien journal est placé
     * avant les lignes récentes. Les fichiers inconnus ne sont jamais supprimés : ils sont signalés.
     *
     * @return array{supprimés: list<string>, inconnus: list<string>, journal: string|null}
     */
    public function cleanup(string $logDir): array
    {
        $removed = [];
        foreach (self::MIGRATED_FILES as $name) {
            foreach ([$name, $name . '.lock'] as $file) {
                if (is_file($this->source . '/' . $file)) {
                    unlink($this->source . '/' . $file);
                    $removed[] = $file;
                }
            }
        }
        foreach (self::MIGRATED_DIRS as $dir) {
            if (is_dir($this->source . '/' . $dir)) {
                self::removeTree($this->source . '/' . $dir);
                $removed[] = $dir . '/';
            }
        }

        $journal = null;
        $oldLog = $this->source . '/journal/connexions.log';
        if (!is_dir($logDir) && !mkdir($logDir, 0700, true) && !is_dir($logDir)) {
            throw new RuntimeException('Impossible de créer ' . $logDir);
        }
        if (is_file($oldLog)) {
            $journal = $logDir . '/connexions.log';
            $recent = is_file($journal) ? (string) file_get_contents($journal) : '';
            if (file_put_contents($journal, (string) file_get_contents($oldLog) . $recent) === false) {
                throw new RuntimeException('Écriture impossible : ' . $journal);
            }
            chmod($journal, 0600);
            unlink($oldLog);
        }
        if (is_dir($this->source . '/journal') && (scandir($this->source . '/journal') ?: []) === ['.', '..']) {
            rmdir($this->source . '/journal');
        }

        $unknown = [];
        foreach (self::files($this->source) as $file) {
            $unknown[] = substr($file->getPathname(), strlen($this->source) + 1);
        }
        sort($unknown);
        if ($unknown === []) {
            self::removeTree($this->source);
        }

        return ['supprimés' => $removed, 'inconnus' => $unknown, 'journal' => $journal];
    }

    /**
     * @return array<string, mixed>
     */
    private function loaded(): array
    {
        return $this->data ?? throw new RuntimeException('Appeler read() d\'abord');
    }

    /**
     * @param list<string> $anomalies
     * @return array<mixed>|null
     */
    private function json(string $relative, array &$anomalies): ?array
    {
        $path = $this->source . '/' . $relative;
        $content = is_readable($path) ? file_get_contents($path) : false;
        if ($content === false) {
            $anomalies[] = $relative . ' : fichier absent ou illisible';
            return null;
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $anomalies[] = $relative . ' : JSON invalide (' . $e->getMessage() . ')';
            return null;
        }
        if (!is_array($data)) {
            $anomalies[] = $relative . ' : objet ou tableau JSON attendu';
            return null;
        }

        return $data;
    }

    /**
     * Contrôle une ligne d'un fichier mensuel et la remet sous la forme exacte que rendra la base (montant normalisé).
     *
     * @return array<string, mixed>
     */
    private static function validLine(mixed $line, string $month, ?CategoryCatalog $catalog): array
    {
        if (!is_array($line) || !is_string($line['date'] ?? null) || !is_string($line['libelle'] ?? null)) {
            throw new InvalidArgumentException('date ou libellé manquant');
        }
        $iso = OperationRepository::isoDate($line['date']);
        if (str_replace('-', '', substr($iso, 0, 7)) !== $month) {
            throw new InvalidArgumentException('date hors du mois du fichier');
        }
        if (trim($line['libelle']) === '') {
            throw new InvalidArgumentException('libellé vide');
        }
        $direction = MonthLine::direction($line);
        $amount = $line[$direction->value] ?? null;
        if (!is_string($amount)) {
            throw new InvalidArgumentException('montant manquant');
        }
        try {
            $cents = Amount::parse($amount);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('montant illisible : ' . $amount, 0, $e);
        }
        if ($catalog === null) {
            throw new InvalidArgumentException('catégories illisibles');
        }

        return MonthLine::build(
            $line['date'],
            $line['libelle'],
            MonthLine::category($line['catégorie'] ?? null, $direction, $catalog),
            $direction,
            Amount::format($cents),
        );
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{dépenses: int, revenus: int} totaux en centimes
     */
    private static function totals(array $lines): array
    {
        $totals = ['dépenses' => 0, 'revenus' => 0];
        foreach ($lines as $line) {
            $direction = MonthLine::direction($line);
            $totals[$direction === Direction::Expense ? 'dépenses' : 'revenus'] += Amount::parse(MonthLine::amount($line));
        }

        return $totals;
    }

    /**
     * L'arbre tel que la base le rend (icônes en texte, listes réindexées), pour comparer à l'ancien fichier.
     *
     * @param array<mixed> $tree
     * @return array<string, mixed>
     */
    private static function normalizedTree(array $tree): array
    {
        return [
            'dépenses' => array_map(static fn (array $c): array => [
                'nom' => (string) $c['nom'],
                'icone' => (string) ($c['icone'] ?? ''),
                'sous_catégories' => array_values(array_map('strval', $c['sous_catégories'])),
            ], array_values($tree['dépenses'])),
            'revenus' => array_map(static fn (array $c): array => [
                'nom' => (string) $c['nom'],
                'icone' => (string) ($c['icone'] ?? ''),
            ], array_values($tree['revenus'])),
        ];
    }

    /**
     * @return RecursiveIteratorIterator<RecursiveDirectoryIterator>
     */
    private static function files(string $dir): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    }

    private static function removeTree(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
