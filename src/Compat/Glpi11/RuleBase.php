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

namespace GlpiPlugin\Emailticketcleaner\Compat\Glpi11;

use CommonDropdown;
use GlpiPlugin\Emailticketcleaner\Settings;

/**
 * Property declarations for GLPI 11 (untyped, as in 11.0/bugfixes src/CommonDropdown.php).
 * Never reference this class directly: use Compat\RuleBase (PROJECT-REFERENCE §7 G1).
 */
abstract class RuleBase extends CommonDropdown
{
    public static $rightname = Settings::RIGHT;

    // Rules are technical (patterns), not translatable labels.
    public $can_be_translated = false;
}
