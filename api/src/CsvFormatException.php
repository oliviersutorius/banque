<?php

declare(strict_types=1);

namespace Banque;

use RuntimeException;

/**
 * Le fichier entier est illisible (pas d'en-tête, encodage inconnu…).
 */
final class CsvFormatException extends RuntimeException
{
}
