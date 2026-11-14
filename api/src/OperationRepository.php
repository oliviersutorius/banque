<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Table « operations ».
 *
 * Les lignes sont rendues sous la forme utilisée par l'API (et par les anciens fichiers mensuels), avec leur
 * identifiant : { id, date "JJ/MM/AAAA", libelle, catégorie (objet ou texte), "dépense" | "revenu" : "2,67" }.
 * Ordre : date croissante, puis ordre d'arrivée (id).
 */
final class OperationRepository
{
    private const SELECT = <<<'SQL'
        SELECT o.id, o.mois, o.date, o.libelle, o.sens, o.montant_centimes, c.nom AS categorie, p.nom AS parent
        FROM operations o
        JOIN categories c ON c.id = o.categorie_id
        LEFT JOIN categories p ON p.id = c.parent_id
        SQL;

    public function __construct(
        private readonly Database $db,
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        return $this->db->transaction($fn);
    }

    public static function isValidMonth(string $month): bool
    {
        return preg_match('/^\d{4}(0[1-9]|1[0-2])$/', $month) === 1;
    }

    /**
     * @return list<string> mois qui ont au moins une opération, du plus ancien au plus récent
     */
    public function months(): array
    {
        return array_map('strval', array_column($this->db->all('SELECT DISTINCT mois FROM operations ORDER BY mois'), 'mois'));
    }

    public function exists(string $month): bool
    {
        return $this->db->value('SELECT 1 FROM operations WHERE mois = ? LIMIT 1', [self::checkedMonth($month)]) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(string $month): array
    {
        return array_map(self::toLine(...), $this->db->all(self::SELECT . ' WHERE o.mois = ? ORDER BY o.date, o.id', [self::checkedMonth($month)]));
    }

    /**
     * Toutes les lignes d'une année en une requête.
     *
     * @return array<string, list<array<string, mixed>>> mois => lignes (seulement les mois qui en ont)
     */
    public function year(string $year): array
    {
        if (preg_match('/^\d{4}$/', $year) !== 1) {
            throw new InvalidArgumentException('Année invalide : ' . $year);
        }
        $rows = $this->db->all(self::SELECT . ' WHERE o.mois BETWEEN ? AND ? ORDER BY o.mois, o.date, o.id', [$year . '01', $year . '12']);

        return self::groupByMonth($rows);
    }

    /**
     * Lignes encore « À catégoriser », tous mois confondus.
     *
     * @return array<string, list<array<string, mixed>>> mois => lignes
     */
    public function uncategorized(): array
    {
        $ids = $this->categories->uncategorizedIds();
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all(
            self::SELECT . ' WHERE o.categorie_id IN (' . self::placeholders($ids) . ') ORDER BY o.mois, o.date, o.id',
            $ids,
        );

        return self::groupByMonth($rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->one(self::SELECT . ' WHERE o.id = ?', [$id]);

        return $row === null ? null : self::toLine($row);
    }

    /**
     * Ajoute des lignes (forme de l'API, sans id) dans l'ordre donné, en une transaction.
     *
     * @param list<array<string, mixed>> $lines
     * @return int nombre de lignes ajoutées
     */
    public function insertLines(array $lines): int
    {
        return $this->db->transaction(function () use ($lines): int {
            $statement = $this->db->pdo()->prepare(
                'INSERT INTO operations (date, libelle, sens, montant_centimes, categorie_id) VALUES (?, ?, ?, ?, ?)',
            );
            $leaves = [];
            foreach ($lines as $line) {
                $direction = MonthLine::direction($line);
                $category = $line['catégorie'];
                $key = $direction->value . "\x1F" . (is_array($category) ? implode("\x1F", $category) : $category);
                $leaves[$key] ??= $this->categories->leafId($direction, $category);
                $statement->execute([
                    self::isoDate((string) $line['date']),
                    (string) $line['libelle'],
                    $direction->value,
                    Amount::parse(MonthLine::amount($line)),
                    $leaves[$key],
                ]);
            }

            return count($lines);
        });
    }

    /**
     * Change la catégorie de lignes désignées par leur identifiant.
     *
     * @param list<int> $ids
     * @param string|array{catégorie1: string, catégorie2: string} $category déjà validée pour le sens
     * @throws HttpException 404 si une ligne n'existe plus
     * @throws InvalidArgumentException si une ligne n'a pas le sens de la catégorie
     */
    public function recategorize(array $ids, string|array $category, Direction $direction): void
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }
        $leaf = $this->categories->leafId($direction, $category);
        $this->db->transaction(function () use ($ids, $leaf, $direction): void {
            $rows = $this->db->all('SELECT id, sens FROM operations WHERE id IN (' . self::placeholders($ids) . ')', $ids);
            if (count($rows) !== count($ids)) {
                throw new HttpException(404, 'Opération introuvable : rechargez la page');
            }
            foreach ($rows as $row) {
                if ($row['sens'] !== $direction->value) {
                    throw new InvalidArgumentException('Opération ' . $row['id'] . ' : sens différent de la catégorie choisie');
                }
            }
            $this->db->run('UPDATE operations SET categorie_id = ? WHERE id IN (' . self::placeholders($ids) . ')', [$leaf, ...$ids]);
        });
    }

    /**
     * Applique une règle aux lignes encore « À catégoriser ». Seules ces lignes sont lues.
     *
     * @return int nombre de lignes mises à jour
     */
    public function applyRuleToUncategorized(Rule $rule, LabelNormalizer $normalizer): int
    {
        $ids = [];
        foreach ($this->uncategorized() as $lines) {
            foreach ($lines as $line) {
                if ($rule->matches($normalizer->normalize((string) $line['libelle']), MonthLine::direction($line))) {
                    $ids[] = (int) $line['id'];
                }
            }
        }
        if ($ids === []) {
            return 0;
        }
        $category = $rule->category2 === null
            ? $rule->category1
            : ['catégorie1' => $rule->category1, 'catégorie2' => $rule->category2];
        $this->recategorize($ids, $category, $rule->direction);

        return count($ids);
    }

    /**
     * @param list<int> $categoryIds
     */
    public function countInCategories(array $categoryIds): int
    {
        return $categoryIds === [] ? 0 : (int) $this->db->value(
            'SELECT count(*) FROM operations WHERE categorie_id IN (' . self::placeholders($categoryIds) . ')',
            $categoryIds,
        );
    }

    /**
     * @param list<int> $categoryIds
     * @return int nombre de lignes déplacées
     */
    public function moveCategories(array $categoryIds, int $target): int
    {
        return $categoryIds === [] ? 0 : $this->db->run(
            'UPDATE operations SET categorie_id = ? WHERE categorie_id IN (' . self::placeholders($categoryIds) . ')',
            [$target, ...$categoryIds],
        )->rowCount();
    }

    /**
     * « JJ/MM/AAAA » → « AAAA-MM-JJ »
     */
    public static function isoDate(string $date): string
    {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            throw new InvalidArgumentException('Date invalide : ' . $date);
        }

        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function toLine(array $row): array
    {
        $iso = (string) $row['date'];
        $direction = Direction::from((string) $row['sens']);
        $category = $direction === Direction::Expense
            ? ['catégorie1' => (string) $row['parent'], 'catégorie2' => (string) $row['categorie']]
            : (string) $row['categorie'];

        return ['id' => (int) $row['id']] + MonthLine::build(
            substr($iso, 8, 2) . '/' . substr($iso, 5, 2) . '/' . substr($iso, 0, 4),
            (string) $row['libelle'],
            $category,
            $direction,
            Amount::format((int) $row['montant_centimes']),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private static function groupByMonth(array $rows): array
    {
        $months = [];
        foreach ($rows as $row) {
            $months[(string) $row['mois']][] = self::toLine($row);
        }

        return $months;
    }

    private static function checkedMonth(string $month): string
    {
        if (!self::isValidMonth($month)) {
            throw new InvalidArgumentException('Mois invalide : ' . $month);
        }

        return $month;
    }

    /**
     * @param list<mixed> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
