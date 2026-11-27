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

/** Output of Cleaner::clean(). */
final class CleanResult
{
    /**
     * @param string       $content            cleaned content
     * @param string|null  $title              cleaned title, null when unchanged / not applicable
     * @param list<string> $removed_image_tags `id` values of inline <img> removed with a cut part (§7 M6)
     * @param list<string> $applied            feature names that changed something (for the log, 04 P4)
     * @param bool         $content_changed    whether $content differs from the input
     */
    public function __construct(
        public readonly string $content,
        public readonly ?string $title = null,
        public readonly array $removed_image_tags = [],
        public readonly array $applied = [],
        public readonly bool $content_changed = false,
    ) {}

    public static function unchanged(string $content): self
    {
        return new self($content);
    }

    public function changed(): bool
    {
        return $this->content_changed || $this->title !== null;
    }
}
