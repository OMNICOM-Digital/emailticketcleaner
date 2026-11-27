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

use CommonDBTM;
use Glpi\RichText\RichText;
use ITILFollowup;
use Laminas\Mail\Storage\Message;
use Throwable;
use Ticket;

/**
 * pre_item_add handler for Ticket and ITILFollowup (registered in setup.php).
 *
 * Core flow, identical on 11.0/bugfixes and 12.0/bugfixes (PROJECT-REFERENCE §7):
 * - MailCollector::buildTicket() builds the input, including `_mailgate` and `_message` (M1);
 *   follow-ups receive the same array (MailCollector::collect(), `$fup_input = $tkt`).
 * - CommonDBTM::add() calls Plugin::doHook(PRE_ITEM_ADD, $item) before prepareInputForAdd() (M2);
 *   changes to $item->input are kept.
 * - An exception escaping this hook fails the whole import of the email (MailCollector::addItemFromMessage()
 *   catches it and files the email as "not imported"), so everything is caught here (04 G15).
 */
final class ImportHook
{
    public static function preItemAdd(CommonDBTM $item): void
    {
        $type    = $item instanceof Ticket ? 'ticket' : ($item instanceof ITILFollowup ? 'followup' : null);
        if ($type === null || !is_array($item->input) || !self::isMailCollectorInput($item->input)) {
            return;
        }

        try {
            $new_input = self::cleanInput($item->input, $type);
        } catch (Throwable $e) {
            // 04 P3 / G15: any failure keeps the original input untouched.
            Log::write($type, 'error', ['exception' => $e::class, 'code' => $e->getCode()]);
            return;
        }

        if ($new_input === null) {
            return;
        }

        // Assigned only once everything succeeded: the input is either fully cleaned or untouched.
        $item->input = $new_input;
    }

    /**
     * 04 P5: act only on input built by the mail collector.
     *
     * `_mailgate` alone can be posted from the web forms (front/ticket.form.php and front/itilfollowup.form.php
     * pass $_POST to add() on 11 and 12), but `_message` must be a Laminas Message object, which cannot come from
     * HTTP or API input (PROJECT-REFERENCE §7 M1).
     *
     * @param array<string, mixed> $input
     */
    public static function isMailCollectorInput(array $input): bool
    {
        return !empty($input['_mailgate'])
            && ($input['_message'] ?? null) instanceof Message
            && isset($input['content'])
            && is_string($input['content']); // 04 G15: validate shape before use
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null new input, or null to keep the original
     */
    private static function cleanInput(array $input, string $type): ?array
    {
        $settings = Settings::getAll();
        if ($settings === null) {
            // 04 G7: configuration missing or unreadable -> no cleaning.
            Log::write($type, 'skipped', ['reason' => 'no-config']);
            return null;
        }

        $content = $input['content'];
        $is_html = RichText::isRichTextHtmlContent($content); // plain-text mails stay plain text (§7 M9)
        $title   = ($type === 'ticket' && isset($input['name']) && is_string($input['name'])) ? $input['name'] : null;

        $cleaner = new Cleaner($settings, RuleRepository::getActiveRules());
        $result  = $cleaner->clean($content, $is_html, $title);

        // 04 P3: fail-safe on empty / too-small result. A follow-up with empty content is refused by core
        // (ITILFollowup::prepareInputForAdd() with add_reopen), which would lose the email (§7 M10).
        $min = max(1, (int) ($settings[Settings::MIN_REMAINING_CHARS] ?? 1));
        if (Cleaner::visibleTextLength($result->content, $is_html) < $min && !Cleaner::hasImage($result->content)) {
            Log::write($type, 'kept-original', ['reason' => 'too-little-left', 'before' => strlen($content)]);
            return null;
        }

        if (!$result->changed()) {
            return null;
        }

        $input['content'] = $result->content;
        if ($title !== null && $result->title !== null && trim($result->title) !== '') {
            // An empty title is left to core, which builds one from the content (§7 M8); we never blank it.
            $input['name'] = $result->title;
        }
        if ($result->removed_image_tags !== [] && Settings::isEnabled($settings, Settings::DROP_CUT_IMAGES)) {
            $input = self::pruneImages($input, $result->removed_image_tags);
        }

        Log::write($type, 'result', [
            'html'    => $is_html ? 1 : 0,
            'before'  => strlen($content),
            'after'   => strlen($result->content),
            'images'  => count($result->removed_image_tags),
            'applied' => implode(',', $result->applied),
        ]);

        return $input;
    }

    /**
     * Drop the files of inline images that were inside the removed part (feature #7, 04 P6).
     *
     * MailCollector stores each inline image as `_filename[$f] = $f` and `_tag[$f] = <uuid>` and rewrites the
     * <img> to `<img id="<uuid>">` (getRecursiveAttached(), Ticket::convertContentForTicket()).
     * CommonDBTM::addFiles() creates a document for every `_filename` entry, and one whose tag is no longer in
     * the content is shown in the timeline as an attachment — so removing the <img> alone is not enough
     * (PROJECT-REFERENCE §7 M6). Only entries whose tag is in $removed_tags AND no longer in the content are
     * dropped; any mismatch keeps the file (fail towards keeping data).
     *
     * @param array<string, mixed> $input
     * @param list<string>         $removed_tags
     * @return array<string, mixed>
     */
    public static function pruneImages(array $input, array $removed_tags): array
    {
        if (!isset($input['_filename'], $input['_tag']) || !is_array($input['_filename']) || !is_array($input['_tag'])) {
            return $input;
        }
        $content = (string) $input['content'];
        foreach ($input['_tag'] as $key => $tag) {
            if (
                !is_string($tag) || $tag === ''
                || !in_array($tag, $removed_tags, true)
                || str_contains($content, $tag)
                || !isset($input['_filename'][$key])
            ) {
                continue;
            }
            $file = $input['_filename'][$key];
            unset($input['_filename'][$key], $input['_tag'][$key]);
            if (isset($input['_prefix_filename']) && is_array($input['_prefix_filename'])) {
                unset($input['_prefix_filename'][$key]);
            }
            self::deleteTempFile($file);
        }
        return $input;
    }

    private static function deleteTempFile(mixed $file): void
    {
        // Only a plain file name inside GLPI_TMP_DIR, as written by MailCollector::getRecursiveAttached().
        if (!is_string($file) || $file === '' || basename($file) !== $file || !defined('GLPI_TMP_DIR')) {
            return;
        }
        $path = GLPI_TMP_DIR . '/' . $file;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
