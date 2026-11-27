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

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use RuntimeException;

/**
 * Pure cleaning logic: no GLPI calls, no writes, identical on GLPI 11 and 12 (context/02).
 *
 * Input at hook time (PROJECT-REFERENCE §7):
 * - HTML mails: raw HTML of the <body> part, <style>/<script>/MSO conditionals already removed by core (M4, M5);
 *   inline images appear as `<img id="<uuid>" width height>` when attachments are enabled (M6).
 * - Plain-text mails: raw text (M9) — stays plain text; only sign-off phrases and regex rules apply.
 *
 * Order (HTML): 1. block removal  2. cut at the earliest signature / quote marker  3. sign-off cut
 *               4. regex rules on text  5. formatting strip  6. trim empty leading/trailing blocks.
 *
 * Security (04): output is a subset of the input — nodes, attributes and text are removed, never added (P1);
 * regex errors abort the whole clean so the caller keeps the original (P2, P3); work is bounded by input size,
 * the libxml defaults (no network, no external entities — LIBXML_NONET) and a lowered PCRE backtrack limit (P7).
 */
final class Cleaner
{
    /** 04 P7: larger bodies are not cleaned. */
    public const MAX_INPUT_BYTES = 2_000_000;

    /** 04 P2: backtracking bound for admin regexes while cleaning (PHP default is 1 000 000). */
    private const PCRE_BACKTRACK_LIMIT = '100000';

    private const ROOT_ID = 'etc-root-9f3a';

    /** Tags kept by the formatting strip; any other element is unwrapped (its content stays). */
    private const KEEP_TAGS = [
        'a', 'b', 'strong', 'i', 'em', 'u', 's', 'sub', 'sup', 'br', 'p', 'div', 'blockquote', 'pre', 'code',
        'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'img', 'hr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    /** Attributes kept by the formatting strip, per tag. `img id` is GLPI's document tag (§7 M6). */
    private const KEEP_ATTRIBUTES = [
        'a'   => ['href', 'title'],
        'img' => ['id', 'src', 'alt', 'width', 'height'],
        'td'  => ['colspan', 'rowspan'],
        'th'  => ['colspan', 'rowspan'],
        'ol'  => ['start', 'type'],
    ];

    /** Removed together with their content. */
    private const DROP_TAGS = ['script', 'style', 'head', 'meta', 'title', 'link', 'object', 'embed', 'iframe', 'form'];

    private const BLOCK_TAGS = [
        'p', 'div', 'blockquote', 'pre', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'hr', 'h1', 'h2', 'h3', 'h4',
        'h5', 'h6', 'tbody', 'thead', 'tfoot',
    ];

    /** @var list<string> */
    private array $applied = [];

    /** @var list<string> */
    private array $removed_image_tags = [];

    /**
     * @param array<string, string>                                 $settings Settings::getAll()
     * @param list<array{id: int, pattern: string, target: string}> $rules    active rules in order
     */
    public function __construct(
        private readonly array $settings,
        private readonly array $rules,
    ) {}

    /**
     * @throws RuntimeException on a regex runtime error (caller keeps the original, 04 P3)
     */
    public function clean(string $content, bool $is_html, ?string $title): CleanResult
    {
        if (strlen($content) > self::MAX_INPUT_BYTES) {
            return CleanResult::unchanged($content);
        }

        $this->applied            = [];
        $this->removed_image_tags = [];

        $previous_limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', self::PCRE_BACKTRACK_LIMIT);
        try {
            $new_content = $is_html ? $this->cleanHtml($content) : $this->cleanText($content);
            $new_title   = $title !== null ? $this->applyRules($title, Pattern::TARGET_TITLE) : null;
        } finally {
            ini_set('pcre.backtrack_limit', $previous_limit === false ? '1000000' : $previous_limit);
        }

        $title_changed = $title !== null && $new_title !== null && $new_title !== $title && trim($new_title) !== '';
        if ($title_changed) {
            $this->applied[] = 'title-rules';
        }

        return new CleanResult(
            content: $new_content ?? $content,
            title: $title_changed ? trim($new_title) : null,
            removed_image_tags: array_values(array_unique($this->removed_image_tags)),
            applied: array_values(array_unique($this->applied)),
            content_changed: $new_content !== null,
        );
    }

    // ---------------------------------------------------------------------------------------------------------
    // Plain text
    // ---------------------------------------------------------------------------------------------------------

    /** @return string|null cleaned text, or null when nothing changed */
    private function cleanText(string $content): ?string
    {
        $text = $content;

        if (Settings::isEnabled($this->settings, Settings::SIGNOFF_RULES)) {
            $lines  = preg_split('/\R/u', $text);
            if ($lines === false) {
                throw new RuntimeException('pcre');
            }
            $phrases = $this->signoffPhrases();
            foreach ($lines as $index => $line) {
                if ($index > 0 && isset($phrases[self::normalisePhrase($line)])) {
                    $text            = implode("\n", array_slice($lines, 0, $index));
                    $this->applied[] = 'signoff';
                    break;
                }
            }
        }

        $text = $this->applyRules($text, Pattern::TARGET_BODY);
        $text = rtrim($text);

        return $text === rtrim($content) ? null : $text;
    }

    // ---------------------------------------------------------------------------------------------------------
    // HTML
    // ---------------------------------------------------------------------------------------------------------

    /** @return string|null cleaned HTML, or null when nothing changed */
    private function cleanHtml(string $content): ?string
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
            . '<body><div id="' . self::ROOT_ID . '">' . $content . '</div></body></html>';
        $previous = libxml_use_internal_errors(true);
        try {
            // 04 P7: LIBXML_NONET — no network access; HTML parsing never loads external entities.
            $loaded = $doc->loadHTML($wrapped, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $loaded ? $doc->getElementById(self::ROOT_ID) : null;
        if (!$root instanceof DOMElement) {
            return null;
        }
        $xpath   = new DOMXPath($doc);
        $changed = false;

        if (Settings::isEnabled($this->settings, Settings::BLOCK_MARKERS)) {
            $changed = $this->removeBlocks($xpath, $root) || $changed;
        }

        $cut_selectors = [];
        if (Settings::isEnabled($this->settings, Settings::SIGNATURE_MARKERS)) {
            $cut_selectors['signature'] = Settings::getList($this->settings, Settings::SIGNATURE_MARKER_LIST);
        }
        if (Settings::isEnabled($this->settings, Settings::QUOTE_MARKERS)) {
            $cut_selectors['quote'] = Settings::getList($this->settings, Settings::QUOTE_MARKER_LIST);
        }
        if ($cut_selectors !== []) {
            $changed = $this->cutAtEarliestMarker($xpath, $root, $cut_selectors) || $changed;
        }

        if (Settings::isEnabled($this->settings, Settings::SIGNOFF_RULES)) {
            $changed = $this->cutAtSignoff($root) || $changed;
        }

        $changed = $this->applyRulesToTextNodes($root) || $changed;

        if (Settings::isEnabled($this->settings, Settings::FORMATTING_STRIP)) {
            $changed = $this->stripFormatting($root) || $changed;
        }

        if (!$changed) {
            return null;
        }

        $this->trimEmptyEdges($root);

        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }
        return trim($html);
    }

    private function removeBlocks(DOMXPath $xpath, DOMElement $root): bool
    {
        $removed = false;
        foreach (Settings::getList($this->settings, Settings::BLOCK_MARKER_LIST) as $selector) {
            $query = Selector::toXPath($selector);
            $nodes = $query !== null ? $xpath->query($query, $root) : false;
            if ($nodes === false) {
                continue;
            }
            foreach (iterator_to_array($nodes) as $node) {
                if ($node->parentNode !== null && $this->isInside($node, $root)) {
                    $this->remove($node);
                    $removed = true;
                }
            }
        }
        if ($removed) {
            $this->applied[] = 'blocks';
        }
        return $removed;
    }

    /**
     * Cut at the first marker (in document order) of any enabled list: the marker and everything after it go.
     *
     * A cut must leave content before it (min_remaining_chars of text, or an image). New Outlook / Outlook on the web
     * put the whole signature template in <div id="Signature">, and a user who clicks into its first (empty) line
     * types the message inside that div (seen live on glpi-demo, 2026-11-26). For a signature marker that would leave
     * nothing, the cut moves inside the marker, to its first child holding a <table> (the signature card), if what
     * comes before that child is enough. Otherwise the marker is skipped and the next one is tried; the fail-safe in
     * ImportHook still guards the end result.
     *
     * @param array<string, list<string>> $lists feature name => selectors
     */
    private function cutAtEarliestMarker(DOMXPath $xpath, DOMElement $root, array $lists): bool
    {
        $candidates = [];
        foreach ($lists as $name => $selectors) {
            foreach ($selectors as $selector) {
                $query = Selector::toXPath($selector);
                $nodes = $query !== null ? $xpath->query($query, $root) : false;
                if ($nodes === false || $nodes->length === 0) {
                    continue;
                }
                $candidates[] = [$nodes->item(0), $name]; // XPath node-sets are in document order
            }
        }
        usort($candidates, fn(array $a, array $b): int => $a[0]->isSameNode($b[0]) ? 0 : ($this->isBefore($a[0], $b[0]) ? -1 : 1));

        foreach ($candidates as [$node, $feature]) {
            if ($node->parentNode === null) {
                continue;
            }
            if ($this->hasEnoughBefore($node, $root)) {
                $this->cutFrom($node, $root);
                $this->applied[] = (string) $feature;
                return true;
            }
            if ($feature === 'signature' && $node instanceof DOMElement) {
                $card = $this->findSignatureCard($node);
                if ($card !== null && $this->hasEnoughBefore($card, $root)) {
                    $this->cutFrom($card, $root);
                    $this->applied[] = 'signature';
                    return true;
                }
            }
        }
        return false;
    }

    /** First descendant of a signature marker, on the way down single wrappers, that holds a <table>. */
    private function findSignatureCard(DOMElement $marker): ?DOMNode
    {
        $container = $marker;
        for ($depth = 0; $depth < 5; $depth++) {
            $elements = array_values(array_filter(
                iterator_to_array($container->childNodes),
                static fn($n): bool => $n instanceof DOMElement,
            ));
            foreach ($elements as $child) {
                if (strtolower($child->tagName) === 'table' || $child->getElementsByTagName('table')->length > 0) {
                    // Descend when the table sits inside the only element child (a wrapper div).
                    if (count($elements) === 1 && strtolower($child->tagName) !== 'table') {
                        $container = $child;
                        continue 2;
                    }
                    return $child;
                }
            }
            return null;
        }
        return null;
    }

    /** Whether the content before $node (outside it) has enough text or an image to stand alone. */
    private function hasEnoughBefore(DOMNode $node, DOMElement $root): bool
    {
        $min    = max(1, (int) ($this->settings[Settings::MIN_REMAINING_CHARS] ?? 1));
        $length = 0;
        foreach ($this->textNodes($root) as $text) {
            if (!$this->isBefore($text, $node)) {
                break;
            }
            if (!$this->isInsideNode($text, $node)) {
                $length += mb_strlen(preg_replace('/[\s\p{Z}]+/u', '', $text->data) ?? '');
                if ($length >= $min) {
                    return true;
                }
            }
        }
        foreach ($root->getElementsByTagName('img') as $image) {
            if (!$this->isBefore($image, $node)) {
                break;
            }
            if (!$this->isInsideNode($image, $node)) {
                return true;
            }
        }
        return false;
    }

    private function isInsideNode(DOMNode $node, DOMNode $ancestor): bool
    {
        for ($n = $node->parentNode; $n !== null; $n = $n->parentNode) {
            if ($n->isSameNode($ancestor)) {
                return true;
            }
        }
        return false;
    }

    /** Cut from the first text node that is a sign-off phrase on its own line (04 / scope: own line only). */
    private function cutAtSignoff(DOMElement $root): bool
    {
        $phrases = $this->signoffPhrases();
        if ($phrases === []) {
            return false;
        }
        $seen_text = false;
        foreach ($this->textNodes($root) as $text) {
            $normalised = self::normalisePhrase($text->data);
            if ($normalised === '') {
                continue;
            }
            // Never cut at the very first line: the message would be empty (the fail-safe would keep it anyway).
            if ($seen_text && isset($phrases[$normalised]) && $this->isOwnLine($text)) {
                $this->cutFrom($text, $root);
                $this->applied[] = 'signoff';
                return true;
            }
            $seen_text = true;
        }
        return false;
    }

    private function applyRulesToTextNodes(DOMElement $root): bool
    {
        if ($this->rulesFor(Pattern::TARGET_BODY) === []) {
            return false;
        }
        $changed = false;
        foreach ($this->textNodes($root) as $text) {
            $new = $this->applyRules($text->data, Pattern::TARGET_BODY);
            if ($new !== $text->data) {
                $text->data = $new; // text only: no markup can be introduced (04 P1)
                $changed    = true;
            }
        }
        return $changed;
    }

    private function stripFormatting(DOMElement $root): bool
    {
        $changed = false;
        // Children before parents, so unwrapping never skips nodes.
        $elements = [];
        foreach ($root->getElementsByTagName('*') as $element) {
            $elements[] = $element;
        }
        foreach (array_reverse($elements) as $element) {
            /** @var DOMElement $element */
            $tag = strtolower($element->tagName);
            if (in_array($tag, self::DROP_TAGS, true)) {
                $this->remove($element);
                $changed = true;
                continue;
            }
            if ($tag === 'img' && !$element->hasAttribute('id') && !self::isSafeImageSource($element->getAttribute('src'))) {
                // Remote images (tracking pixels, hosted logos) are not part of what the sender wrote.
                $this->remove($element);
                $changed = true;
                continue;
            }
            $keep = self::KEEP_ATTRIBUTES[$tag] ?? [];
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                if (
                    !in_array($name, $keep, true)
                    || ($name === 'src' && !self::isSafeImageSource($attribute->value))
                    || ($name === 'href' && !self::isSafeLink($attribute->value))
                ) {
                    $element->removeAttribute($attribute->name);
                    $changed = true;
                }
            }
            if (!in_array($tag, self::KEEP_TAGS, true)) {
                $this->unwrap($element);
                $changed = true;
            }
        }
        // Comments carry nothing for the reader.
        foreach (iterator_to_array((new DOMXPath($root->ownerDocument))->query('.//comment()', $root)) as $comment) {
            $comment->parentNode?->removeChild($comment);
            $changed = true;
        }
        if ($changed) {
            $this->applied[] = 'formatting';
        }
        return $changed;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Rules
    // ---------------------------------------------------------------------------------------------------------

    /** @return list<string> compiled patterns for a target */
    private function rulesFor(string $target): array
    {
        $patterns = [];
        foreach ($this->rules as $rule) {
            if ($rule['target'] === $target && Pattern::getProblem($rule['pattern']) === null) {
                $patterns[$rule['id']] = Pattern::compile($rule['pattern']);
            }
        }
        return array_values($patterns);
    }

    /** Remove-only: every match is replaced by an empty string. Throws on a PCRE runtime error (04 P2). */
    private function applyRules(string $subject, string $target): string
    {
        $patterns = $this->rulesFor($target);
        foreach ($patterns as $pattern) {
            $result = preg_replace($pattern, '', $subject);
            if ($result === null || preg_last_error() !== PREG_NO_ERROR) {
                throw new RuntimeException('pcre:' . preg_last_error());
            }
            if ($result !== $subject) {
                $this->applied[] = $target === Pattern::TARGET_TITLE ? 'title-rules' : 'rules';
            }
            $subject = $result;
        }
        return $subject;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------------

    /** @return array<string, true> normalised phrase => true */
    private function signoffPhrases(): array
    {
        $phrases = [];
        foreach (Settings::getList($this->settings, Settings::SIGNOFF_LIST) as $phrase) {
            $normalised = self::normalisePhrase($phrase);
            if ($normalised !== '') {
                $phrases[$normalised] = true;
            }
        }
        return $phrases;
    }

    /** Lower case, single spaces (incl. NBSP), no trailing punctuation: "Mvh," "MVH" "Mvh." all match "mvh". */
    public static function normalisePhrase(string $text): string
    {
        $text = preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text) ?? '';
        $text = trim($text, " \t,.!:;-–—");
        return mb_strtolower($text, 'UTF-8');
    }

    /**
     * A text node is on its own line when nothing but whitespace or a line break separates it from the start and
     * the end of its line (the nearest block element or <br>).
     */
    private function isOwnLine(DOMText $text): bool
    {
        foreach (['previousSibling', 'nextSibling'] as $direction) {
            $node = $text;
            while (true) {
                $sibling = $node->{$direction};
                while ($sibling === null) {
                    $parent = $node->parentNode;
                    if (!$parent instanceof DOMElement || in_array(strtolower($parent->tagName), self::BLOCK_TAGS, true)) {
                        continue 3; // reached the edge of the line in this direction
                    }
                    $node    = $parent;
                    $sibling = $node->{$direction};
                }
                if ($sibling instanceof DOMElement) {
                    $tag = strtolower($sibling->tagName);
                    if ($tag === 'br' || in_array($tag, self::BLOCK_TAGS, true)) {
                        continue 2;
                    }
                    if (trim($sibling->textContent) !== '' || $sibling->getElementsByTagName('img')->length > 0) {
                        return false;
                    }
                } elseif ($sibling instanceof DOMText && self::normalisePhrase($sibling->data) !== '') {
                    return false;
                }
                $node = $sibling;
            }
        }
        return true;
    }

    /** Remove $node and everything after it in document order, up to (not including) $root. */
    private function cutFrom(DOMNode $node, DOMElement $root): void
    {
        $current = $node;
        while ($current !== null && !$current->isSameNode($root)) {
            $parent = $current->parentNode;
            while ($current->nextSibling !== null) {
                $this->remove($current->nextSibling);
            }
            if ($current->isSameNode($node)) {
                $this->remove($current);
            }
            $current = $parent;
        }
    }

    /** Remove a node, remembering the GLPI tags of images inside it (§7 M6). */
    private function remove(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $images = strtolower($node->tagName) === 'img' ? [$node] : iterator_to_array($node->getElementsByTagName('img'));
            foreach ($images as $image) {
                $id = $image->getAttribute('id');
                if ($id !== '') {
                    $this->removed_image_tags[] = $id;
                }
            }
        }
        $node->parentNode?->removeChild($node);
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    /** Leading/trailing empty blocks and line breaks left by cuts or by the mail client (trailing: at any depth). */
    private function trimEmptyEdges(DOMElement $root): void
    {
        while (($node = $root->firstChild) !== null && $this->isEmptyNode($node)) {
            $root->removeChild($node);
        }
        $container = $root;
        while ($container instanceof DOMElement) {
            while (($node = $container->lastChild) !== null && $this->isEmptyNode($node)) {
                $container->removeChild($node);
            }
            $last = $container->lastChild;
            $container = ($last instanceof DOMElement && strtolower($last->tagName) !== 'img') ? $last : null;
        }
    }

    private function isEmptyNode(DOMNode $node): bool
    {
        if ($node instanceof DOMText) {
            return self::normalisePhrase($node->data) === '';
        }
        if (!$node instanceof DOMElement) {
            return $node->nodeType === XML_COMMENT_NODE;
        }
        if (strtolower($node->tagName) === 'img' || $node->getElementsByTagName('img')->length > 0) {
            return false;
        }
        return in_array(strtolower($node->tagName), ['br', 'p', 'div', 'span', 'font', 'hr', 'o:p'], true)
            && self::normalisePhrase($node->textContent) === '';
    }

    /** @return list<DOMText> */
    private function textNodes(DOMElement $root): array
    {
        $nodes = (new DOMXPath($root->ownerDocument))->query('.//text()', $root);
        return $nodes === false ? [] : array_values(array_filter(
            iterator_to_array($nodes),
            static fn($n): bool => $n instanceof DOMText,
        ));
    }

    private function isInside(DOMNode $node, DOMElement $root): bool
    {
        for ($n = $node->parentNode; $n !== null; $n = $n->parentNode) {
            if ($n->isSameNode($root)) {
                return true;
            }
        }
        return false;
    }

    /** Document order comparison via node paths (DOMNode has no compareDocumentPosition in PHP 8.2). */
    private function isBefore(DOMNode $a, DOMNode $b): bool
    {
        $path = static function (DOMNode $n): array {
            $indexes = [];
            for (; $n->parentNode !== null; $n = $n->parentNode) {
                $i = 0;
                for ($s = $n->previousSibling; $s !== null; $s = $s->previousSibling) {
                    $i++;
                }
                array_unshift($indexes, $i);
            }
            return $indexes;
        };
        $pa = $path($a);
        $pb = $path($b);
        $n  = min(count($pa), count($pb));
        for ($i = 0; $i < $n; $i++) {
            if ($pa[$i] !== $pb[$i]) {
                return $pa[$i] < $pb[$i];
            }
        }
        return count($pa) < count($pb); // an ancestor comes before its descendants
    }

    /** Embedded image sources only: cid: parts of the mail and data:image payloads; nothing fetched remotely. */
    private static function isSafeImageSource(string $src): bool
    {
        $src = strtolower(trim($src));
        return str_starts_with($src, 'cid:') || str_starts_with($src, 'data:image/');
    }

    /** Links keep web, mail and phone targets only (no javascript:, data:, file: ...). */
    private static function isSafeLink(string $href): bool
    {
        return preg_match('~^\s*(https?://|mailto:|tel:|#)~i', $href) === 1;
    }

    /** Length of the visible text, used by the fail-safe (04 P3). */
    public static function visibleTextLength(string $content, bool $is_html): int
    {
        $text = $is_html
            ? html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : $content;
        // \p{Z} includes non-breaking spaces left by mail clients.
        $text = preg_replace('/[\s\p{Z}]+/u', '', $text) ?? '';
        return mb_strlen($text);
    }

    /** An image-only message (e.g. a pasted screenshot) is not empty. */
    public static function hasImage(string $content): bool
    {
        return stripos($content, '<img') !== false;
    }
}
