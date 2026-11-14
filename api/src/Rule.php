<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Une règle de catégorisation (une ligne de la table « regles » et ses mots ET).
 *
 * Elle s'applique quand le libellé normalisé contient le motif, et chacun des mots de « et »,
 * sous forme de mots entiers, pour le bon sens d'opération et, si précisé, le bon type d'opération.
 */
final class Rule
{
    public const ORIGIN_INITIAL = 'initiale';
    public const ORIGIN_LEARNED = 'apprise';

    /**
     * @param list<string> $and motifs supplémentaires, tous requis
     */
    public function __construct(
        public readonly string $motif,
        public readonly Direction $direction,
        public readonly string $category1,
        /** Catégorie 2, uniquement pour une dépense */
        public readonly ?string $category2,
        public readonly string $origin,
        public readonly array $and = [],
        /** Code du type d'opération requis (CARTE, PRELEVEMENT…), ou null pour tous */
        public readonly ?string $operationType = null,
        /** La ligne catégorisée par cette règle doit être confirmée à l'import */
        public readonly bool $toConfirm = false,
        /** Identifiant en base, null pour une règle pas encore enregistrée */
        public readonly ?int $id = null,
    ) {
        if (Text::canonical($motif) === '') {
            throw new InvalidArgumentException('Le motif d\'une règle ne peut pas être vide');
        }
        if (($direction === Direction::Expense) !== ($category2 !== null)) {
            throw new InvalidArgumentException('Une règle de dépense a 2 niveaux de catégorie, une règle de revenu 1 seul');
        }
        if (!in_array($origin, [self::ORIGIN_INITIAL, self::ORIGIN_LEARNED], true)) {
            throw new InvalidArgumentException('Origine de règle inconnue : ' . $origin);
        }
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidArgumentException si la règle est mal formée
     */
    public static function fromArray(array $data): self
    {
        $direction = Direction::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '');
        if ($direction === null) {
            throw new InvalidArgumentException('Règle : « type » doit valoir « dépense » ou « revenu »');
        }
        $string = static function (string $key) use ($data): string {
            if (!is_string($data[$key] ?? null)) {
                throw new InvalidArgumentException('Règle : champ « ' . $key . ' » manquant');
            }
            return $data[$key];
        };
        $and = $data['et'] ?? [];
        if (!is_array($and) || array_filter($and, 'is_string') !== $and) {
            throw new InvalidArgumentException('Règle : « et » doit être une liste de motifs');
        }
        $operationType = $data['type_operation'] ?? null;
        if ($operationType !== null && !is_string($operationType)) {
            throw new InvalidArgumentException('Règle : « type_operation » doit être un texte');
        }

        return new self(
            $string('motif'),
            $direction,
            $direction === Direction::Expense ? $string('catégorie1') : $string('catégorie'),
            $direction === Direction::Expense ? $string('catégorie2') : null,
            $string('origine'),
            array_values($and),
            $operationType,
            ($data['a_confirmer'] ?? false) === true,
        );
    }

    /**
     * @param array<mixed> $data contenu décodé de regles.json
     * @return list<Rule>
     * @throws InvalidArgumentException en indiquant le numéro de la règle fautive
     */
    public static function listFromArray(array $data): array
    {
        $rules = [];
        foreach (array_values($data) as $i => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Règle n° ' . ($i + 1) . ' : objet attendu');
            }
            try {
                $rules[] = self::fromArray($item);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('Règle n° ' . ($i + 1) . ' : ' . $e->getMessage(), 0, $e);
            }
        }

        return $rules;
    }

    public function withId(int $id): self
    {
        return new self($this->motif, $this->direction, $this->category1, $this->category2, $this->origin, $this->and, $this->operationType, $this->toConfirm, $id);
    }

    /**
     * @return array<string, mixed> forme de l'API (et de l'ancien regles.json), avec « id » si la règle est enregistrée
     */
    public function toArray(): array
    {
        $data = $this->id === null ? [] : ['id' => $this->id];
        $data += ['motif' => $this->motif, 'type' => $this->direction->value];
        if ($this->direction === Direction::Expense) {
            $data['catégorie1'] = $this->category1;
            $data['catégorie2'] = $this->category2;
        } else {
            $data['catégorie'] = $this->category1;
        }
        if ($this->and !== []) {
            $data['et'] = $this->and;
        }
        if ($this->operationType !== null) {
            $data['type_operation'] = $this->operationType;
        }
        if ($this->toConfirm) {
            $data['a_confirmer'] = true;
        }
        $data['origine'] = $this->origin;

        return $data;
    }

    public function matches(NormalizedLabel $label, Direction $direction): bool
    {
        if ($direction !== $this->direction) {
            return false;
        }
        if ($this->operationType !== null && $this->operationType !== $label->type) {
            return false;
        }
        $text = $label->text();
        foreach ([$this->motif, ...$this->and] as $motif) {
            if (!self::containsWords($text, $motif)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Plus le motif est long, plus la règle est spécifique.
     */
    public function specificity(): int
    {
        return array_sum(array_map(
            static fn (string $m): int => mb_strlen(Text::canonical($m)),
            [$this->motif, ...$this->and],
        ));
    }

    /**
     * Le motif doit apparaître comme une suite de mots entiers : « BP » trouve « BP », pas « BPCE ».
     */
    private static function containsWords(string $text, string $motif): bool
    {
        $pattern = '/(?<![\p{L}\d])' . preg_quote(Text::canonical($motif), '/') . '(?![\p{L}\d])/u';

        return preg_match($pattern, $text) === 1;
    }
}
