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
 * Regex rule patterns without any GLPI dependency, so the import path never loads the CommonDropdown hierarchy.
 *
 * 04 P2: the stored value is the pattern body only; delimiter and flags are ours, never admin-controlled.
 */
final class Pattern
{
    public const TARGET_BODY  = 'body';
    public const TARGET_TITLE = 'title';

    public const MAX_LENGTH = 1000;

    /** \x01 is refused in the body, so it can never end the pattern early. */
    private const DELIMITER = "\x01";

    /** m: ^ and $ match at line starts/ends; u: UTF-8. Case-insensitive matching via inline (?i). */
    private const FLAGS = 'mu';

    public static function compile(string $body): string
    {
        return self::DELIMITER . $body . self::DELIMITER . self::FLAGS;
    }

    /**
     * Problem with a pattern body, or null when it compiles.
     *
     * @return array{0: string, 1: string}|null [code, detail]; codes: empty, too-long, invalid-chars, compile
     */
    public static function getProblem(string $body): ?array
    {
        if (trim($body) === '') {
            return ['empty', ''];
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            return ['too-long', (string) self::MAX_LENGTH];
        }
        if (str_contains($body, self::DELIMITER) || !mb_check_encoding($body, 'UTF-8')) {
            return ['invalid-chars', ''];
        }
        // Compile once on an empty subject. The compile warning is captured with our own temporary handler:
        // GLPI's ErrorHandler (src/Glpi/Error/ErrorHandler.php::handleError(), 11 and 12) returns true for
        // silenced errors, so error_get_last() stays empty inside GLPI (seen live on glpi-demo: "Internal error").
        $message = null;
        set_error_handler(static function (int $type, string $error) use (&$message): bool {
            $message = $error;
            return true;
        });
        try {
            $result = preg_match(self::compile($body), '');
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            $message ??= preg_last_error_msg();
            // "preg_match(): Compilation failed: missing closing parenthesis at offset 1" -> keep the reason only.
            return ['compile', preg_replace('/^preg_match\(\):\s*/', '', $message) ?? $message];
        }
        return null;
    }
}
