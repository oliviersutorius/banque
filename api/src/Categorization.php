<?php

declare(strict_types=1);

namespace Banque;

/**
 * Résultat de la catégorisation d'une ligne.
 */
final class Categorization
{
    /** Une règle à motif a été appliquée */
    public const SOURCE_RULE = 'règle';
    /** Aucune règle : catégorie déduite du type d'opération */
    public const SOURCE_OPERATION_TYPE = 'type';
    /** Ni règle ni type reconnu : « À catégoriser » */
    public const SOURCE_NONE = 'aucune';

    public function __construct(
        public readonly string $category1,
        /** Catégorie 2 pour une dépense, null pour un revenu */
        public readonly ?string $category2,
        public readonly string $source,
        public readonly ?Rule $rule = null,
    ) {
    }

    public function isUncategorized(): bool
    {
        return $this->category1 === DefaultData::UNCATEGORIZED;
    }

    /**
     * La ligne doit être mise en évidence à l'import : à catégoriser, ou règle « à confirmer ».
     */
    public function needsReview(): bool
    {
        return $this->isUncategorized() || ($this->rule !== null && $this->rule->toConfirm);
    }

    /**
     * @return string|array{catégorie1: string, catégorie2: string} la valeur du champ « catégorie » du JSON
     */
    public function toJsonValue(): string|array
    {
        return $this->category2 === null
            ? $this->category1
            : ['catégorie1' => $this->category1, 'catégorie2' => $this->category2];
    }
}
