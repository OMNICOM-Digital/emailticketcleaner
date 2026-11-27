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

use Config;
use Throwable;

/**
 * Plugin settings, stored in GLPI's core configuration table (context "plugin:emailticketcleaner").
 *
 * Deliberately NOT a CommonDBTM/CommonGLPI subclass: GLPI 12 declares typed core properties
 * (`public static string $rightname`, ...) that GLPI 11 declares untyped, and a subclass that redeclares
 * them fatals on one of the two versions (PROJECT-REFERENCE §7 G1).
 *
 * Core API used, identical on 11.0/bugfixes and 12.0/bugfixes (§7 G5):
 * - Config::getConfigurationValues(string $context, array $names = []): array
 * - Config::setConfigurationValues(string $context, array $values = []): void
 * - Config::deleteConfigurationValues(string $context, array $values = []): void
 */
final class Settings
{
    public const CONTEXT = 'plugin:emailticketcleaner';

    /** Plugin right, used by the settings page and the rule list (Rule::$rightname). */
    public const RIGHT = 'plugin_emailticketcleaner_config';

    /** Feature switches (PROJECT-REFERENCE §3). */
    public const SIGNATURE_MARKERS = 'signature_markers_enabled';
    public const QUOTE_MARKERS     = 'quote_markers_enabled';
    public const SIGNOFF_RULES     = 'signoff_rules_enabled';
    public const FORMATTING_STRIP  = 'formatting_strip_enabled';
    public const DROP_CUT_IMAGES   = 'drop_cut_images_enabled';
    public const BLOCK_MARKERS     = 'block_markers_enabled';

    /** Lists, stored as text, one entry per line. */
    public const SIGNATURE_MARKER_LIST = 'signature_markers';
    public const QUOTE_MARKER_LIST     = 'quote_markers';
    public const SIGNOFF_LIST          = 'signoff_phrases';
    public const BLOCK_MARKER_LIST     = 'block_markers';

    /** Lists holding selectors (validated with Selector on save). */
    public const SELECTOR_LISTS = [self::SIGNATURE_MARKER_LIST, self::QUOTE_MARKER_LIST, self::BLOCK_MARKER_LIST];

    /** Fail-safe: minimum characters of visible text that must remain, otherwise keep the original (04 P3). */
    public const MIN_REMAINING_CHARS = 'min_remaining_chars';

    /** Version of the shipped defaults last applied (one-time default changes on upgrade, context/02 hook.php). */
    public const DEFAULTS_VERSION = 'defaults_version';

    /** Bounds for list input (04 P2/P7). */
    public const MAX_LIST_ENTRIES   = 200;
    public const MAX_ENTRY_LENGTH   = 200;

    /** @var array<string, string>|null Per-request cache; the mail collector imports many messages per run. */
    private static ?array $cache = null;

    /**
     * Shipped defaults. The marker and sign-off lists are initial and are finalised from the sample emails.
     *
     * @return array<string, string>
     */
    public static function getDefaults(): array
    {
        return [
            self::SIGNATURE_MARKERS => '1',
            self::QUOTE_MARKERS     => '1',
            self::SIGNOFF_RULES     => '1',
            self::FORMATTING_STRIP  => '1',
            self::DROP_CUT_IMAGES   => '1',
            self::BLOCK_MARKERS     => '1',
            // Start of the signature: it and everything after it is cut. Reviewed against the client samples
            // (Outlook web/new/mobile, Apple Mail iOS, Edison) plus known Gmail / Thunderbird / Apple Mail markers.
            self::SIGNATURE_MARKER_LIST => implode("\n", [
                '#Signature',
                '#ms-outlook-mobile-signature',
                '#lineBreakAtBeginningOfSignature',
                '#AppleMailSignature',
                '#edo-signature',
                '.gmail_signature',
                '.moz-signature',
            ]),
            // Start of a quoted reply or forward: it and everything after it is cut.
            self::QUOTE_MARKER_LIST => implode("\n", [
                '#appendonsend',
                '#divRplyFwdMsg',
                '#ms-outlook-mobile-body-separator-line',
                '#mail-editor-reference-message-container',
                'blockquote[type=cite]',
                '#edo-original',
                '.gmail_quote',
                '.gmail_quote_container',
                '.moz-cite-prefix',
                '.yahoo_quoted',
            ]),
            // Removed on their own, wherever they are (e.g. banners added by mail gateways).
            self::BLOCK_MARKER_LIST => implode("\n", [
                // Microsoft 365 "You don't often get email from ... Learn why this is important"
                'table:has(a[href*=LearnAboutSenderIdentification])',
            ]),
            // Cut from the phrase when it stands on its own line (closings and mail-app footers).
            self::SIGNOFF_LIST => implode("\n", [
                'Med vennlig hilsen',
                'Vennlig hilsen',
                'Mvh',
                'Best regards',
                'Kind regards',
                'Regards',
                'S pozdravom',
                'S pozdravem',
                'Sendt fra min iPhone',
                'Sendt fra min iPad',
                'Sent from my iPhone',
                'Sent from my iPad',
                'Hent Outlook for iOS',
                'Get Outlook for iOS',
                'Get Outlook for Android',
            ]),
            self::MIN_REMAINING_CHARS => '1',
            self::DEFAULTS_VERSION    => '1',
        ];
    }

    /**
     * Current settings: stored values over defaults.
     *
     * Fail closed (04 G7): if the configuration cannot be read, the caller must not clean.
     *
     * @return array<string, string>|null null when the configuration could not be read
     */
    public static function getAll(): ?array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $stored = Config::getConfigurationValues(self::CONTEXT);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($stored) || $stored === []) {
            // Not installed / config removed: no cleaning (04 G7).
            return null;
        }
        $values = self::getDefaults();
        foreach ($values as $name => $default) {
            if (isset($stored[$name]) && is_scalar($stored[$name])) {
                $values[$name] = (string) $stored[$name];
            }
        }
        return self::$cache = $values;
    }

    public static function isEnabled(array $settings, string $switch): bool
    {
        return ($settings[$switch] ?? '0') === '1';
    }

    /**
     * Split a stored list into entries.
     *
     * @return list<string>
     */
    public static function getList(array $settings, string $name): array
    {
        return self::normaliseList((string) ($settings[$name] ?? ''));
    }

    /**
     * Normalise a textarea list: trim, drop empty, dedupe, bound count and length (04 P2/P7).
     *
     * @return list<string>
     */
    public static function normaliseList(string $text): array
    {
        $entries = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line) > self::MAX_ENTRY_LENGTH) {
                continue;
            }
            $entries[$line] = $line;
            if (count($entries) >= self::MAX_LIST_ENTRIES) {
                break;
            }
        }
        return array_values($entries);
    }

    /**
     * Validate and store values posted from the settings form. Unknown keys are ignored.
     *
     * @param array<string, mixed> $input
     * @return list<string> marker entries refused as invalid selectors (not saved)
     */
    public static function save(array $input): array
    {
        $values = [];
        $switches = [
            self::SIGNATURE_MARKERS, self::QUOTE_MARKERS, self::BLOCK_MARKERS, self::SIGNOFF_RULES,
            self::FORMATTING_STRIP, self::DROP_CUT_IMAGES,
        ];
        foreach ($switches as $switch) {
            $values[$switch] = !empty($input[$switch]) ? '1' : '0';
        }
        $rejected = [];
        foreach ([self::SIGNATURE_MARKER_LIST, self::QUOTE_MARKER_LIST, self::BLOCK_MARKER_LIST, self::SIGNOFF_LIST] as $list) {
            if (isset($input[$list]) && is_string($input[$list])) {
                $entries = self::normaliseList($input[$list]);
                if (in_array($list, self::SELECTOR_LISTS, true)) {
                    $valid    = array_values(array_filter($entries, [Selector::class, 'isValid']));
                    $rejected = array_merge($rejected, array_diff($entries, $valid));
                    $entries  = $valid;
                }
                $values[$list] = implode("\n", $entries);
            }
        }
        if (isset($input[self::MIN_REMAINING_CHARS]) && is_numeric($input[self::MIN_REMAINING_CHARS])) {
            $values[self::MIN_REMAINING_CHARS] = (string) max(1, min(1000, (int) $input[self::MIN_REMAINING_CHARS]));
        }
        Config::setConfigurationValues(self::CONTEXT, $values);
        self::$cache = null;

        return $rejected;
    }

    /** Write defaults for keys that are not stored yet (install / upgrade). Never overwrites admin choices. */
    public static function installDefaults(): void
    {
        $stored  = Config::getConfigurationValues(self::CONTEXT);
        $missing = array_diff_key(self::getDefaults(), is_array($stored) ? $stored : []);
        if ($missing !== []) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
        self::$cache = null;
    }

    public static function uninstall(): void
    {
        Config::deleteConfigurationValues(self::CONTEXT, array_keys(self::getDefaults()));
        self::$cache = null;
    }
}
