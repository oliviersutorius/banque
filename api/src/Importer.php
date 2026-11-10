<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Import d'un CSV en deux temps.
 *
 * - preview : lecture et catégorisation, repérage des doublons ; rien n'est écrit
 * - confirm : écriture des lignes nouvelles dans les fichiers mensuels
 *
 * Les doublons (même date, libellé, sens et montant) se comptent par occurrences : si le CSV contient
 * deux fois la même ligne et le mois une seule, une seule est ajoutée. Les lignes déjà enregistrées
 * ne sont jamais modifiées.
 */
final class Importer
{
    public const MAX_LINES = 20000;

    public function __construct(
        private readonly CsvParser $parser,
        private readonly Categorizer $categorizer,
        private readonly LabelNormalizer $normalizer,
        private readonly MonthRepository $months,
        private readonly CategoryCatalog $catalog,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws CsvFormatException
     */
    public function preview(string $csv): array
    {
        $result = $this->parser->parse($csv);

        $byMonth = [];
        foreach ($result->lines as $id => $line) {
            $byMonth[$line->month()][$id] = $line;
        }
        ksort($byMonth);

        $months = [];
        $totals = ['nouvelles' => 0, 'doublons' => 0, 'à_revoir' => 0];
        $cents = ['dépense' => 0, 'revenu' => 0];
        foreach ($byMonth as $month => $lines) {
            $remaining = $this->occurrences($this->months->lines((string) $month));
            $items = [];
            foreach ($lines as $id => $line) {
                $item = $this->previewLine($id, $line);
                $key = MonthLine::key(MonthLine::build($line->date, $line->libelle, '', $line->direction, $item['montant']));
                $item['doublon'] = ($remaining[$key] ?? 0) > 0;
                if ($item['doublon']) {
                    $remaining[$key]--;
                    $totals['doublons']++;
                } else {
                    $totals['nouvelles']++;
                    $totals['à_revoir'] += $item['à_revoir'] ? 1 : 0;
                }
                $cents[$line->direction->value] += $line->cents;
                $items[] = $item;
            }
            $months[] = ['mois' => (string) $month, 'existant' => $this->months->exists((string) $month), 'lignes' => $items];
        }

        return [
            'résumé' => [
                'lignes' => count($result->lines),
                'mois' => array_map('strval', array_keys($byMonth)),
                'dépenses' => Amount::format($cents['dépense']),
                'revenus' => Amount::format($cents['revenu']),
                'erreurs' => count($result->rejected),
            ] + $totals,
            'mois' => $months,
            'erreurs' => array_map(
                static fn (RejectedLine $r): array => ['ligne' => $r->lineNumber, 'contenu' => $r->content, 'raison' => $r->reason],
                $result->rejected,
            ),
        ];
    }

    /**
     * Toutes les lignes du CSV (nouvelles et doublons) sont renvoyées par le front, avec leurs catégories
     * éventuellement corrigées : la fusion est refaite ici, ce qui rend la confirmation rejouable sans doublon.
     *
     * @param array<mixed> $payload { lignes: list<{date, libelle, sens, montant, catégorie}> }
     * @return array{ajoutées: int, mois: list<array{mois: string, ajoutées: int, doublons: int}>}
     * @throws InvalidArgumentException si une ligne est invalide (son numéro est indiqué)
     */
    public function confirm(array $payload): array
    {
        $input = $payload['lignes'] ?? null;
        if (!is_array($input) || $input === [] || count($input) > self::MAX_LINES) {
            throw new InvalidArgumentException('« lignes » doit contenir entre 1 et ' . self::MAX_LINES . ' lignes');
        }

        $byMonth = [];
        foreach (array_values($input) as $i => $item) {
            try {
                $line = MonthLine::fromInput(is_array($item) ? $item : [], $this->catalog);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('Ligne ' . ($i + 1) . ' : ' . $e->getMessage(), 0, $e);
            }
            $date = (string) $line['date'];
            $byMonth[substr($date, 6, 4) . substr($date, 3, 2)][] = $line;
        }
        ksort($byMonth);

        $report = [];
        $added = 0;
        foreach ($byMonth as $month => $lines) {
            $month = (string) $month;
            $existing = $this->months->lines($month);
            $remaining = $this->occurrences($existing);
            $new = [];
            foreach ($lines as $line) {
                $key = MonthLine::key($line);
                if (($remaining[$key] ?? 0) > 0) {
                    $remaining[$key]--;
                } else {
                    $new[] = $line;
                }
            }
            if ($new !== []) {
                $this->months->save($month, [...$existing, ...$new]);
            }
            $report[] = ['mois' => $month, 'ajoutées' => count($new), 'doublons' => count($lines) - count($new)];
            $added += count($new);
        }

        return ['ajoutées' => $added, 'mois' => $report];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewLine(int $id, ParsedLine $line): array
    {
        $categorization = $this->categorizer->categorize($line->libelle, $line->direction);
        $label = $this->normalizer->normalize($line->libelle);

        return [
            'id' => $id,
            'ligne' => $line->lineNumber,
            'date' => $line->date,
            'libelle' => $line->libelle,
            'sens' => $line->direction->value,
            'montant' => Amount::format($line->cents),
            'catégorie' => $categorization->toJsonValue(),
            'type_operation' => $label->type,
            'marchand' => $label->merchant,
            'source' => $categorization->source,
            'motif' => $categorization->rule?->motif,
            'à_revoir' => $categorization->needsReview(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, int> clé de doublon => nombre d'occurrences
     */
    private function occurrences(array $lines): array
    {
        $counts = [];
        foreach ($lines as $line) {
            $key = MonthLine::key($line);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
