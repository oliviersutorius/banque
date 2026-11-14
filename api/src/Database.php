<?php

declare(strict_types=1);

namespace Banque;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Base SQLite de l'application : connexion, transactions, migrations du schéma et sauvegardes.
 *
 * Réglages : clés étrangères actives, journal WAL (les lectures continuent pendant une écriture), attente de 5 s si
 * la base est occupée, synchronous=NORMAL (sûr en WAL). La version du schéma est dans PRAGMA user_version ; les
 * fichiers api/schema/NNN_nom.sql sont appliqués dans l'ordre, chacun dans une transaction.
 */
final class Database
{
    public const MEMORY = ':memory:';
    private const BACKUPS_KEPT = 20;

    private readonly PDO $pdo;
    private int $depth = 0;

    public function __construct(private readonly string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== self::MEMORY) {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        }
    }

    /**
     * Ouvre la base de l'application : crée les dossiers, applique les migrations du schéma en attente (après une
     * sauvegarde si la base contient déjà des données) et installe les données par défaut dans une base neuve.
     *
     * @throws HttpException 503 si les anciens fichiers JSON de data/ n'ont pas encore été migrés
     */
    public static function open(Paths $paths): self
    {
        if ($paths->migrationPending()) {
            throw new HttpException(503, 'Migration nécessaire : les données sont encore dans data/. Lancer php api/bin/migrer-json.php');
        }
        $paths->ensureDirectories();
        $db = new self($paths->database);
        $db->migrate($paths->schemaDir(), static function () use ($db, $paths): void {
            if ($db->schemaVersion() > 0) {
                $db->backup($paths->backupsDir());
            }
        });
        DefaultData::seed($db);

        return $db;
    }

    public function __destruct()
    {
        try {
            $this->pdo->exec('PRAGMA optimize');
        } catch (Throwable $e) {
            error_log('[banque] PRAGMA optimize : ' . $e->getMessage());
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(
                is_int($key) ? $key + 1 : $key,
                $value,
                match (true) {
                    is_int($value), is_bool($value) => PDO::PARAM_INT,
                    $value === null => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR,
                },
            );
        }
        $statement->execute();

        return $statement;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Exécute $fn dans une transaction « immédiate » : le verrou d'écriture est pris dès le début, deux requêtes ne
     * peuvent donc pas lire puis modifier les mêmes données en même temps. Les appels imbriqués rejoignent la
     * transaction en cours.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn();
            } finally {
                $this->depth--;
            }
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->depth = 1;
        try {
            $result = $fn();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    public function schemaVersion(): int
    {
        return (int) $this->value('PRAGMA user_version');
    }

    /**
     * Applique les fichiers NNN_nom.sql de $schemaDir dont le numéro dépasse la version actuelle.
     *
     * @param callable(): void|null $beforeChange appelé une fois avant la première migration (sauvegarde)
     * @return int nombre de migrations appliquées
     */
    public function migrate(string $schemaDir, ?callable $beforeChange = null): int
    {
        $pending = [];
        foreach (glob($schemaDir . '/*.sql') ?: [] as $file) {
            if (preg_match('/^(\d+)_[\w-]+\.sql$/', basename($file), $m) === 1 && (int) $m[1] > $this->schemaVersion()) {
                $pending[(int) $m[1]] = $file;
            }
        }
        ksort($pending);
        if ($pending !== [] && $beforeChange !== null) {
            $beforeChange();
        }
        foreach ($pending as $version => $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Lecture impossible : ' . basename($file));
            }
            $this->transaction(function () use ($sql, $version): void {
                $this->pdo->exec($sql);
                $this->pdo->exec('PRAGMA user_version = ' . $version);
            });
        }

        return count($pending);
    }

    /**
     * Copie cohérente de la base (VACUUM INTO), même pendant une utilisation ; garde les $kept plus récentes.
     *
     * @return string chemin de la sauvegarde
     */
    public function backup(string $dir, int $kept = self::BACKUPS_KEPT): string
    {
        $target = $dir . '/banque-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.sqlite';
        $this->run('VACUUM INTO ?', [$target]);

        $backups = glob($dir . '/banque-*.sqlite') ?: [];
        sort($backups);
        foreach (array_slice($backups, 0, max(0, count($backups) - $kept)) as $old) {
            unlink($old);
        }

        return $target;
    }

    /**
     * Sauvegarde si aucune n'a encore été faite aujourd'hui (appelée avant une écriture).
     *
     * @return string|null chemin de la sauvegarde créée, null si celle du jour existe déjà
     */
    public function backupOncePerDay(string $dir): ?string
    {
        if ($this->path === self::MEMORY || (glob($dir . '/banque-' . date('Ymd') . '-*.sqlite') ?: []) !== []) {
            return null;
        }

        return $this->backup($dir);
    }
}
