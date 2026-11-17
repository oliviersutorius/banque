<?php

declare(strict_types=1);

namespace Banque;

/**
 * Mutualisation des règles : repère les règles en double et les regroupements possibles, sans jamais changer la
 * catégorie que le moteur donne aux opérations enregistrées.
 *
 * - Doublon : une autre règle, de même origine, sens, catégorie et « à confirmer », plus générale (type d'opération
 *   identique ou absent, motifs contenus en mots entiers dans ceux de la règle), s'applique déjà partout où elle
 *   s'applique. Sa suppression est proposée si elle ne change aucune opération.
 * - Regroupement : plusieurs règles apprises, sans mots ET, de même sens, catégorie et type d'opération, qui commencent
 *   par les mêmes mots, remplacées par une règle sur ces mots communs. Il est seulement suggéré : un mot commun peut
 *   reconnaître d'autres marchands. Il n'est applicable que s'il ne change aucune opération.
 *
 * Chaque proposition a une clé stable : « d:<id> » pour un doublon, « r:<id>,<id>… » pour un regroupement.
 */
final class RuleConsolidation
{
    /** Mots trop génériques pour former à eux seuls le motif d'un regroupement */
    private const GENERIC_WORDS = [
        'SARL', 'SAS', 'SASU', 'EURL', 'SA', 'SC', 'SNC', 'CHEZ', 'LES', 'AUX', 'DES', 'DU', 'DE', 'LA', 'LE', 'AU',
        'MAISON', 'MAGASIN', 'BOUTIQUE', 'WEB', 'SUMUP', 'PAYPAL', 'NYX', 'LSP', 'UEP', 'PAIEMENT', 'CARTE', 'VIREMENT',
        'PRELEVEMENT', 'VIR', 'SEPA', 'INST', 'FACTURE',
    ];
    /** Longueur minimale du motif d'un regroupement */
    private const MIN_GROUP_MOTIF = 3;
    /** Nombre d'exemples d'opérations changées montrés par regroupement */
    private const EXAMPLES = 3;

    /** @var list<array{id: int, libelle: string, sens: Direction, label: NormalizedLabel}>|null */
    private ?array $operations = null;
    /** @var array{list<Rule>, array<int, string>}|null règles actuelles et catégorie de chaque opération avec elles */
    private ?array $current = null;

    public function __construct(
        private readonly Database $db,
        private readonly RuleRepository $rules,
        private readonly CategoryCatalog $catalog,
        private readonly LabelNormalizer $normalizer = new LabelNormalizer(),
        /** Dossier des sauvegardes faites avant d'appliquer ; null : pas de sauvegarde (tests) */
        private readonly ?string $backupsDir = null,
    ) {
    }

    /**
     * @return array{
     *     règles: int,
     *     doublons: list<array{clé: string, règle: array<string, mixed>, couverte_par: array<string, mixed>}>,
     *     regroupements: list<array{clé: string, motif: string, règles: list<array<string, mixed>>, nouvelle: array<string, mixed>, impact: int, exemples: list<array{libelle: string, avant: string, après: string}>}>
     * }
     */
    public function analyse(): array
    {
        [$rules, $baseline] = $this->current();

        $duplicates = [];
        foreach ($this->duplicates($rules) as [$rule, $cover]) {
            if ($this->changes($rules, $baseline, [(int) $rule->id], []) === []) {
                $duplicates[] = ['clé' => 'd:' . $rule->id, 'règle' => $rule->toArray(), 'couverte_par' => $cover->toArray()];
            }
        }

        $groups = [];
        foreach ($this->groups($rules) as [$members, $new]) {
            $ids = array_map(static fn (Rule $r): int => (int) $r->id, $members);
            $changes = $this->changes($rules, $baseline, $ids, [$new]);
            $groups[] = [
                'clé' => 'r:' . implode(',', $ids),
                'motif' => $new->motif,
                'règles' => array_map(static fn (Rule $r): array => $r->toArray(), $members),
                'nouvelle' => $new->toArray(),
                'impact' => count($changes),
                'exemples' => array_slice($changes, 0, self::EXAMPLES),
            ];
        }

        return ['règles' => count($rules), 'doublons' => $duplicates, 'regroupements' => $groups];
    }

    /**
     * Applique les propositions choisies, si elles sont toujours valables et ne changent, ensemble, aucune opération.
     *
     * @param list<string> $keys clés de l'analyse
     * @return array{supprimées: int, créées: int, sauvegarde: string|null}
     * @throws HttpException 409 si une proposition n'est plus valable ou si l'ensemble changerait des opérations
     */
    public function apply(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return ['supprimées' => 0, 'créées' => 0, 'sauvegarde' => null];
        }

        $analysis = $this->analyse();
        $available = [];
        foreach ($analysis['doublons'] as $duplicate) {
            $available[$duplicate['clé']] = ['ids' => [(int) $duplicate['règle']['id']], 'new' => null];
        }
        foreach ($analysis['regroupements'] as $group) {
            if ($group['impact'] === 0) {
                $available[$group['clé']] = [
                    'ids' => array_map(static fn (array $r): int => (int) $r['id'], $group['règles']),
                    'new' => $group['nouvelle'],
                ];
            }
        }
        $unknown = array_diff($keys, array_keys($available));
        if ($unknown !== []) {
            throw new HttpException(409, 'Les règles ont changé depuis l\'analyse : relancez la mutualisation');
        }

        $ids = [];
        $news = [];
        foreach ($keys as $key) {
            $ids = [...$ids, ...$available[$key]['ids']];
            if ($available[$key]['new'] !== null) {
                $news[] = RuleRepository::learnedFromInput($available[$key]['new'], $this->catalog);
            }
        }
        $ids = array_values(array_unique($ids));

        [$rules, $baseline] = $this->current();
        if ($this->changes($rules, $baseline, $ids, $news) !== []) {
            throw new HttpException(409, 'Appliquées ensemble, ces propositions changeraient la catégorie d\'opérations : choisissez-en moins');
        }

        $backup = $this->backupsDir !== null && $this->db->path() !== Database::MEMORY ? $this->db->backup($this->backupsDir) : null;
        $this->db->transaction(function () use ($ids, $news): void {
            foreach ($ids as $id) {
                $this->rules->delete($id);
            }
            foreach ($news as $rule) {
                $this->rules->learn($rule);
            }
        });
        $this->current = null;

        return ['supprimées' => count($ids), 'créées' => count($news), 'sauvegarde' => $backup];
    }

    /**
     * @param list<Rule> $rules
     * @return list<array{Rule, Rule}> [règle en double, règle qui la couvre]
     */
    private function duplicates(array $rules): array
    {
        $found = [];
        foreach ($rules as $rule) {
            foreach ($rules as $cover) {
                if ($cover->id !== $rule->id && self::covers($cover, $rule) && (!self::covers($rule, $cover) || (int) $cover->id < (int) $rule->id)) {
                    $found[] = [$rule, $cover];
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * $cover s'applique (avec la même catégorie) partout où $rule s'applique.
     */
    private static function covers(Rule $cover, Rule $rule): bool
    {
        if (
            $cover->origin !== $rule->origin
            || $cover->direction !== $rule->direction
            || $cover->category1 !== $rule->category1
            || $cover->category2 !== $rule->category2
            || $cover->toConfirm !== $rule->toConfirm
            || ($cover->operationType !== null && $cover->operationType !== $rule->operationType)
        ) {
            return false;
        }
        $texts = array_map([Text::class, 'canonical'], [$rule->motif, ...$rule->and]);
        foreach ([$cover->motif, ...$cover->and] as $motif) {
            $found = array_filter($texts, static fn (string $text): bool => Rule::containsWords($text, $motif));
            if ($found === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Rule> $rules
     * @return list<array{list<Rule>, Rule}> [règles remplacées, nouvelle règle]
     */
    private function groups(array $rules): array
    {
        $buckets = [];
        foreach ($rules as $rule) {
            if ($rule->origin !== Rule::ORIGIN_LEARNED || $rule->and !== [] || $rule->toConfirm) {
                continue;
            }
            $words = self::words($rule->motif);
            $key = implode('|', [$rule->direction->value, $rule->category1, (string) $rule->category2, (string) $rule->operationType, $words[0] ?? '']);
            $buckets[$key][] = $rule;
        }

        $groups = [];
        foreach ($buckets as $members) {
            if (count($members) < 2) {
                continue;
            }
            $prefix = self::words($members[0]->motif);
            foreach ($members as $member) {
                $prefix = self::commonPrefix($prefix, self::words($member->motif));
            }
            $motif = implode(' ', $prefix);
            if (mb_strlen($motif) < self::MIN_GROUP_MOTIF || array_diff($prefix, self::GENERIC_WORDS) === [] || ctype_digit(str_replace(' ', '', $motif))) {
                continue;
            }
            // Le motif commun doit reconnaître chaque règle remplacée ; s'il est déjà le motif de l'une d'elles, c'est un doublon
            $covered = array_filter($members, static fn (Rule $r): bool => Rule::containsWords(Text::canonical($r->motif), $motif) && Text::canonical($r->motif) !== $motif);
            if (count($covered) !== count($members)) {
                continue;
            }
            $first = $members[0];
            $groups[] = [$members, new Rule($motif, $first->direction, $first->category1, $first->category2, Rule::ORIGIN_LEARNED, [], $first->operationType)];
        }

        return $groups;
    }

    /**
     * Mots entiers d'un motif, sous forme canonique (« MANGO.COM BARCELONA » → MANGO, COM, BARCELONA).
     *
     * @return list<string>
     */
    private static function words(string $motif): array
    {
        return preg_split('/[^\p{L}\d]+/u', Text::canonical($motif), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private static function commonPrefix(array $a, array $b): array
    {
        $prefix = [];
        foreach ($a as $i => $word) {
            if (($b[$i] ?? null) !== $word) {
                break;
            }
            $prefix[] = $word;
        }

        return $prefix;
    }

    /**
     * Opérations dont la catégorie change quand on retire les règles $removed et qu'on ajoute $added.
     *
     * @param list<Rule> $rules
     * @param array<int, string> $baseline catégorie actuelle de chaque opération (par identifiant)
     * @param list<int> $removed
     * @param list<Rule> $added
     * @return list<array{libelle: string, avant: string, après: string}>
     */
    private function changes(array $rules, array $baseline, array $removed, array $added): array
    {
        $removedRules = array_values(array_filter($rules, static fn (Rule $r): bool => in_array($r->id, $removed, true)));
        $involved = [...$removedRules, ...$added];
        $categorizer = new Categorizer(
            [...array_values(array_filter($rules, static fn (Rule $r): bool => !in_array($r->id, $removed, true))), ...$added],
            $this->catalog,
            $this->normalizer,
        );

        $changes = [];
        foreach ($this->operations() as $operation) {
            // Seules les opérations reconnues par une règle retirée ou ajoutée peuvent changer
            $concerned = array_filter($involved, static fn (Rule $r): bool => $r->matches($operation['label'], $operation['sens']));
            if ($concerned === []) {
                continue;
            }
            $after = self::describe($categorizer->categorize($operation['libelle'], $operation['sens']));
            if ($after !== $baseline[$operation['id']]) {
                $changes[] = ['libelle' => $operation['libelle'], 'avant' => $baseline[$operation['id']], 'après' => $after];
            }
        }

        return $changes;
    }

    /**
     * Catégoriser toutes les opérations est l'étape coûteuse : elle n'est faite qu'une fois tant que les règles ne
     * changent pas.
     *
     * @return array{list<Rule>, array<int, string>}
     */
    private function current(): array
    {
        $rules = $this->rules->all();
        if ($this->current === null || $this->current[0] != $rules) {
            $this->current = [$rules, $this->categorizeAll($rules)];
        }

        return $this->current;
    }

    /**
     * @param list<Rule> $rules
     * @return array<int, string>
     */
    private function categorizeAll(array $rules): array
    {
        $categorizer = new Categorizer($rules, $this->catalog, $this->normalizer);
        $result = [];
        foreach ($this->operations() as $operation) {
            $result[$operation['id']] = self::describe($categorizer->categorize($operation['libelle'], $operation['sens']));
        }

        return $result;
    }

    private static function describe(Categorization $c): string
    {
        return $c->category2 === null ? $c->category1 : $c->category1 . ' > ' . $c->category2;
    }

    /**
     * @return list<array{id: int, libelle: string, sens: Direction, label: NormalizedLabel}>
     */
    private function operations(): array
    {
        return $this->operations ??= array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'libelle' => (string) $row['libelle'],
            'sens' => Direction::from((string) $row['sens']),
            'label' => $this->normalizer->normalize((string) $row['libelle']),
        ], $this->db->all('SELECT id, libelle, sens FROM operations ORDER BY id'));
    }
}
