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

/**
 * Reads active rules for the cleaner. Kept apart from Rule so the hook never loads the CommonDropdown
 * class hierarchy during a mail import.
 *
 * DBmysql::request() with array criteria is unchanged on 11 and 12 (query()/queryOrDie() are removed in 12,
 * PROJECT-REFERENCE §7 G3).
 */
final class RuleRepository
{
    public const TABLE = 'glpi_plugin_emailticketcleaner_rules';

    /** @var list<array{id: int, pattern: string, target: string}>|null per-request cache */
    private static ?array $cache = null;

    /**
     * @return list<array{id: int, pattern: string, target: string}>
     */
    public static function getActiveRules(): array
    {
        global $DB;

        if (self::$cache !== null) {
            return self::$cache;
        }
        $rules = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'pattern', 'target'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['is_active' => 1],
                'ORDER'  => ['ranking ASC', 'id ASC'],
            ]) as $row
        ) {
            $rules[] = [
                'id'      => (int) $row['id'],
                'pattern' => (string) $row['pattern'],
                'target'  => (string) $row['target'],
            ];
        }
        return self::$cache = $rules;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
