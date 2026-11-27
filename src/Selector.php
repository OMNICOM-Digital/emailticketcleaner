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
 * Minimal selector language for the marker lists, compiled to XPath 1.0.
 *
 * One compound selector per line, no combinators:
 *   [tag] ( #id | .class | [attr] | [attr=value] | [attr*=value] | [attr^=value] )* [ :has(<compound>) ]
 * Examples: `#Signature`, `.gmail_quote`, `blockquote[type=cite]`,
 *           `table:has(a[href*=LearnAboutSenderIdentification])`.
 * Tag names, ids, classes and attribute names are matched exactly; tag and attribute names case-insensitively
 * as libxml lowercases them. Values are emitted as XPath string literals (never concatenated raw).
 */
final class Selector
{
    private const IDENT = '[A-Za-z_][A-Za-z0-9_\-:]*';

    /** XPath for elements matching $selector anywhere below the context node, or null when invalid. */
    public static function toXPath(string $selector): ?string
    {
        $condition = self::compound(trim($selector));
        return $condition === null ? null : './/' . $condition;
    }

    public static function isValid(string $selector): bool
    {
        return self::toXPath($selector) !== null;
    }

    /** "tag[conditions]" for one compound selector, or null. */
    private static function compound(string $s): ?string
    {
        if ($s === '' || strlen($s) > Settings::MAX_ENTRY_LENGTH) {
            return null;
        }

        $has = null;
        if (preg_match('/^(.*):has\((.+)\)$/s', $s, $m) === 1) {
            $inner = self::compound(trim($m[2]));
            if ($inner === null) {
                return null;
            }
            $has = './/' . $inner;
            $s   = trim($m[1]);
            if ($s === '') {
                return null;
            }
        }

        $tag = '*';
        if (preg_match('/^(' . self::IDENT . ')/', $s, $m) === 1) {
            $tag = strtolower($m[1]);
            $s   = substr($s, strlen($m[1]));
        }

        $conditions = [];
        while ($s !== '') {
            if (preg_match('/^#(' . self::IDENT . ')/', $s, $m) === 1) {
                $conditions[] = '@id=' . self::literal($m[1]);
            } elseif (preg_match('/^\.(' . self::IDENT . ')/', $s, $m) === 1) {
                $conditions[] = "contains(concat(' ', normalize-space(@class), ' '), " . self::literal(' ' . $m[1] . ' ') . ')';
            } elseif (preg_match(self::attributePattern(), $s, $m) === 1) {
                $attr  = '@' . strtolower($m[1]);
                $op    = $m[2] ?? '';
                $value = ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : ($m[5] ?? ''));
                $conditions[] = match ($op) {
                    ''      => $attr,
                    '='     => $attr . '=' . self::literal($value),
                    '*='    => 'contains(' . $attr . ', ' . self::literal($value) . ')',
                    '^='    => 'starts-with(' . $attr . ', ' . self::literal($value) . ')',
                };
            } else {
                return null;
            }
            $s = substr($s, strlen($m[0]));
        }

        if ($has !== null) {
            $conditions[] = $has;
        }
        if ($tag === '*' && $conditions === []) {
            return null; // a bare "*" would match everything
        }
        return $tag . implode('', array_map(static fn(string $c): string => '[' . $c . ']', $conditions));
    }

    /** `[attr]`, `[attr=v]`, `[attr*=v]`, `[attr^=v]`; v bare, "double" or 'single' quoted. */
    private static function attributePattern(): string
    {
        $value = '(?:"([^"]*)"|\'([^\']*)\'|([^\]\s"\']+))';
        return '/^\[\s*(' . self::IDENT . ')\s*(?:(\*=|\^=|=)\s*' . $value . '\s*)?\]/';
    }

    /** XPath 1.0 string literal for any value (XPath has no escape sequences). */
    private static function literal(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }
        $parts = array_map(static fn(string $p): string => "'" . $p . "'", explode("'", $value));
        return 'concat(' . implode(", \"'\", ", $parts) . ')';
    }
}
