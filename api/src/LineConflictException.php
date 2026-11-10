<?php

declare(strict_types=1);

namespace Banque;

use RuntimeException;

/**
 * La ligne visée par son index ne correspond plus (le mois a changé depuis son affichage).
 */
final class LineConflictException extends RuntimeException
{
}
