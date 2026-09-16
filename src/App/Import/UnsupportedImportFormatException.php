<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

/**
 * Výjimka signalizující, že nahraný soubor nerozpoznal žádný nativní plugin.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   16.09.2026
 */
final class UnsupportedImportFormatException extends RuntimeException
{
}
