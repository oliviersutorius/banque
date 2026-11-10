<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Lecture d'un export CSV du Crédit Agricole : Date;Libellé;Débit euros;Crédit euros
 *
 * - encodage UTF-8 (avec ou sans BOM) ou Windows-1252
 * - lignes avant l'en-tête ignorées, lignes vides et lignes de solde/totaux ignorées
 * - libellés entre guillemets pouvant s'étendre sur plusieurs lignes
 * - champs vides en fin de ligne tolérés (« ;65,64;; »)
 * - toute ligne qui ressemble à une opération mais est invalide est signalée, jamais ignorée
 */
final class CsvParser
{
    private const BOM = "\xEF\xBB\xBF";
    private const FIELD_COUNT = 4;

    /** Débuts de lignes de fin d'export (forme canonique) à ignorer */
    private const FOOTER_PREFIXES = ['SOLDE', 'TOTAL', 'ENCOURS', 'LISTE DES OPERATIONS', 'TELECHARGEMENT'];

    /**
     * @throws CsvFormatException si le fichier est vide ou si l'en-tête est introuvable
     */
    public function parse(string $content): ParseResult
    {
        $content = $this->toUtf8($content);

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new CsvFormatException('Impossible de lire le fichier en mémoire');
        }
        fwrite($stream, $content);
        rewind($stream);

        $lines = [];
        $rejected = [];
        $headerFound = false;
        $lineNumber = 1;

        try {
            while (true) {
                $start = (int) ftell($stream);
                $fields = fgetcsv($stream, null, ';', '"', '');
                if ($fields === false) {
                    break;
                }
                $raw = substr($content, $start, (int) ftell($stream) - $start);
                $recordLine = $lineNumber;
                $lineNumber += substr_count($raw, "\n");

                $fields = array_map(static fn (?string $f): string => trim((string) $f), $fields);

                if (!$headerFound) {
                    $headerFound = $this->isHeader($fields);
                    continue;
                }
                if ($this->isIgnorable($fields)) {
                    continue;
                }

                $line = $this->parseRecord($recordLine, $fields);
                if ($line instanceof ParsedLine) {
                    $lines[] = $line;
                } else {
                    $rejected[] = new RejectedLine($recordLine, Text::collapseSpaces($raw), $line);
                }
            }
        } finally {
            fclose($stream);
        }

        if (!$headerFound) {
            throw new CsvFormatException('En-tête « Date;Libellé;Débit euros;Crédit euros » introuvable');
        }

        return new ParseResult($lines, $rejected);
    }

    private function toUtf8(string $content): string
    {
        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, strlen(self::BOM));
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * @param list<string> $fields
     */
    private function isHeader(array $fields): bool
    {
        return count($fields) >= self::FIELD_COUNT
            && Text::canonical($fields[0]) === 'DATE'
            && str_starts_with(Text::canonical($fields[1]), 'LIBELLE');
    }

    /**
     * Lignes vides, en-tête répété et lignes de solde ou de totaux.
     *
     * @param list<string> $fields
     */
    private function isIgnorable(array $fields): bool
    {
        $text = Text::canonical(implode(' ', $fields));
        if ($text === '' || $this->isHeader($fields)) {
            return true;
        }
        foreach (self::FOOTER_PREFIXES as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $fields
     * @return ParsedLine|string l'opération lue, ou la raison du rejet
     */
    private function parseRecord(int $lineNumber, array $fields): ParsedLine|string
    {
        $extra = array_slice($fields, self::FIELD_COUNT);
        if (array_filter($extra, static fn (string $f): bool => $f !== '') !== []) {
            return 'champs en trop après la colonne « Crédit euros »';
        }
        [$date, $libelle, $debit, $credit] = array_pad($fields, self::FIELD_COUNT, '');

        if (!$this->isValidDate($date)) {
            return 'date invalide « ' . $date . ' » (format attendu JJ/MM/AAAA)';
        }
        $libelle = Text::collapseSpaces($libelle);
        if ($libelle === '') {
            return 'libellé vide';
        }
        if ($debit === '' && $credit === '') {
            return 'ni débit ni crédit';
        }
        if ($debit !== '' && $credit !== '') {
            return 'débit et crédit remplis tous les deux';
        }

        $direction = $debit !== '' ? Direction::Expense : Direction::Income;
        try {
            $cents = Amount::parse($debit !== '' ? $debit : $credit);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        if ($cents === 0) {
            return 'montant nul';
        }

        return new ParsedLine($lineNumber, $date, $libelle, $direction, $cents);
    }

    private function isValidDate(string $date): bool
    {
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m) === 1
            && checkdate((int) $m[2], (int) $m[1], (int) $m[3]);
    }
}
