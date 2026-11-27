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

use Throwable;
use Toolbox;

/**
 * Import log in files/_log/emailticketcleaner.log.
 *
 * 04 P4: metadata only — event, item type, sizes, feature names, error class. Never content, subjects,
 * addresses or attachment names. Callers pass scalars only; strings are reduced to a safe charset.
 */
final class Log
{
    /**
     * @param array<string, int|string> $data
     */
    public static function write(string $type, string $event, array $data = []): void
    {
        $parts = ['type=' . $type, 'event=' . $event, 'glpi=' . (defined('GLPI_VERSION') ? GLPI_VERSION : '?')];
        foreach ($data as $key => $value) {
            $value   = is_int($value) ? (string) $value : (preg_replace('/[^A-Za-z0-9_.,:\\\\-]/', '', (string) $value) ?? '');
            $parts[] = $key . '=' . substr($value, 0, 200);
        }
        try {
            // $force = true: written even when "log in files" is off; $output = false: never echoed by cron.
            Toolbox::logInFile('emailticketcleaner', implode(' ', $parts) . "\n", true, false);
        } catch (Throwable $e) {
            // Logging must never break an import.
        }
    }
}
