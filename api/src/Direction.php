<?php

declare(strict_types=1);

namespace Banque;

/**
 * Sens d'une opération. La valeur est aussi le nom du champ montant dans le JSON.
 */
enum Direction: string
{
    case Expense = 'dépense';
    case Income = 'revenu';
}
