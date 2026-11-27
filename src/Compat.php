<?php

/**
 * -------------------------------------------------------------------------
 * Email Ticket Cleaner plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 OMNICOM s.r.o.
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/OMNICOM-Digital/emailticketcleaner
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Emailticketcleaner;

use CommonDropdown;
use ReflectionProperty;

/**
 * The only place that knows about GLPI 11 vs GLPI 12 differences (context/02).
 *
 * Prefer feature detection over version numbers.
 */
final class Compat
{
    private static ?bool $typed_core_properties = null;

    /**
     * Whether core declares typed properties on its base classes.
     *
     * 11.0/bugfixes src/CommonDropdown.php: `public static $rightname = 'dropdown';`
     * 12.0/bugfixes src/CommonDropdown.php: `public static string $rightname = 'dropdown';`
     * (PROJECT-REFERENCE §7 G1)
     */
    public static function hasTypedCoreProperties(): bool
    {
        return self::$typed_core_properties ??= (new ReflectionProperty(CommonDropdown::class, 'rightname'))->hasType();
    }
}
