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

namespace GlpiPlugin\Emailticketcleaner\Compat;

use GlpiPlugin\Emailticketcleaner\Compat;

/*
 * Autoload entry for GlpiPlugin\Emailticketcleaner\Compat\RuleBase.
 *
 * Rule must redeclare $rightname (and $can_be_translated). GLPI 12 types these properties, GLPI 11 does not,
 * and PHP refuses a redeclaration whose typedness differs from the parent (PROJECT-REFERENCE §7 G1).
 * So the property declarations live in two tiny classes and the matching one is aliased here.
 * Only the selected variant is ever loaded; loading the other one would fatal.
 */
if (Compat::hasTypedCoreProperties()) {
    class_alias(Glpi12\RuleBase::class, RuleBase::class);
} else {
    class_alias(Glpi11\RuleBase::class, RuleBase::class);
}
