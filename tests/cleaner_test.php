<?php

/**
 * Cleaner fixture tests (04 regression suite C and F). Pure PHP, no GLPI needed.
 *
 * The fixtures are SYNTHETIC: they copy the HTML structure (ids, classes, nesting) of the client's sample emails
 * as GLPI hands them to the hook (MailCollector::getBody() + Ticket::convertContentForTicket()), with invented
 * text. The client's .eml files are under NDA and never enter this repository (01 Secrets and customer data).
 *
 * Usage: php tests/cleaner_test.php
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Emailticketcleaner\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use GlpiPlugin\Emailticketcleaner\Cleaner;
use GlpiPlugin\Emailticketcleaner\Selector;
use GlpiPlugin\Emailticketcleaner\Settings;

$failures = 0;
$count    = 0;
function check(string $id, bool $ok, string $label, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    echo ($ok ? 'ok   ' : 'FAIL ') . "$id $label" . (!$ok && $detail !== '' ? "\n     got: $detail" : '') . "\n";
    $failures += $ok ? 0 : 1;
}
function clean(string $html, bool $is_html = true, array $settings = [], array $rules = [], ?string $title = null)
{
    $cleaner = new Cleaner(array_merge(Settings::getDefaults(), $settings), $rules);
    return $cleaner->clean($html, $is_html, $title);
}
function text(string $html): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '</div>', '</p>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

// ------------------------------------------------------------------------------------------------------------
// C: client families (structure of the samples)
// ------------------------------------------------------------------------------------------------------------

// C1 Outlook web/new — new ticket with #Signature holding the company logo (samples "test sak", 16 KB logo).
$c1 = '<div class="elementToProof" style="font-family: Aptos; font-size: 12pt; color: rgb(0, 0, 0);">The VPN does not connect since this morning.</div>'
    . '<div id="Signature" class="elementToProof"><div><br></div><div>Mvh</div><div><span style="color:#333">Kari Nordmann</span></div>'
    . '<div><span>T: </span><span>+47 000 00 000</span></div><div><img id="TAG-LOGO" width="120" height="40"></div></div>';
$r = clean($c1);
check('C1', text($r->content) === 'The VPN does not connect since this morning.', 'Outlook signature with logo cut', $r->content);
check('C1i', $r->removed_image_tags === ['TAG-LOGO'], 'logo reported for pruning');
check('C1f', !str_contains($r->content, 'style=') && !str_contains($r->content, 'class='), 'formatting removed');

// C2 iPhone reply to an Outlook mail — quoted thread in blockquote[type=cite], Outlook headers and #Signature inside.
$c2 = '<div dir="ltr">Thanks, it works again.</div><div dir="ltr"><br></div><div dir="ltr">Mvh</div><div dir="ltr">Nils</div>'
    . '<div dir="ltr"><br id="lineBreakAtBeginningOfSignature"><div dir="ltr">Sendt fra min iPhone</div><div dir="ltr"><br>'
    . '<blockquote type="cite">Den 1. des. 2026 kl. 10:00 skrev Support:</blockquote></div>'
    . '<blockquote type="cite"><div class="elementToProof">Earlier message text.</div>'
    . '<div id="Signature" class="elementToProof"><div>Mvh</div><div>Support Team</div></div>'
    . '<div id="appendonsend"></div><hr><div id="divRplyFwdMsg"><b>Fra:</b> Someone<br><b>Emne:</b> Old</div></blockquote></div>';
$r = clean($c2);
check('C2', text($r->content) === 'Thanks, it works again.', 'iPhone reply: own sign-off and quoted Outlook thread cut', text($r->content));

// C3 Outlook mobile reply — separator line, reference container, quoted signature with logo.
$c3 = '<div dir="auto">Hi</div><div dir="auto"><br></div><div dir="auto">Can you call me about the licence?</div><div><br></div>'
    . '<div dir="auto">Mvh</div><div dir="auto">Per</div><div id="ms-outlook-mobile-body-separator-line" dir="auto"><br></div>'
    . '<div id="mail-editor-reference-message-container"><hr><div><b>Fra:</b> X<br><b>Emne:</b> Y</div>'
    . '<div id="mail-editor-reference-message-body"><div class="elementToProof">Old text</div>'
    . '<div id="Signature"><div>Mvh</div><img id="TAG-QUOTED-LOGO"></div></div></div>';
$r = clean($c3);
check('C3', text($r->content) === 'Hi Can you call me about the licence?', 'Outlook mobile: thread and own sign-off cut', text($r->content));
check('C3i', $r->removed_image_tags === ['TAG-QUOTED-LOGO'], 'quoted logo reported for pruning');

// C4 Third-party sender (Edison Mail) through Microsoft 365 — first-contact banner on top, edo-signature, edo-original.
$c4 = '<table border="0" cellspacing="0" cellpadding="0" width="100%" align="left"><tbody><tr><td width="1px" bgcolor="#A6A6A6"></td>'
    . '<td bgcolor="#EAEAEA"><div>You don\'t often get email from x@example.com <a href="https://aka.ms/LearnAboutSenderIdentification">'
    . 'Learn why this is important</a></div></td><td width="75px"></td></tr></tbody></table>'
    . '<div><div id="edo-message"><div></div>Please reset my password for the portal.</div><div id="edo-meta">'
    . '<div id="edo-signature"><div>Jane Doe<br>Example AS<br>+47 000 00 000</div></div></div><div id="edo-original"><div></div></div></div>';
$r = clean($c4);
check('C4', text($r->content) === 'Please reset my password for the portal.', 'M365 banner removed, Edison signature cut', text($r->content));

// C5 Apple Mail iOS — photo only, then "Sendt fra min iPhone" after the signature line break.
$c5 = '<img id="TAG-PHOTO" width="3024" height="4032"><br id="lineBreakAtBeginningOfSignature"><div dir="ltr">Sendt fra min iPhone</div>';
$r = clean($c5);
check('C5', str_contains($r->content, 'TAG-PHOTO') && text($r->content) === '', 'photo kept, iPhone footer cut', $r->content);
check('C5i', $r->removed_image_tags === [], 'photo not reported for pruning');

// C6 Plain-text iPhone mail.
$c6 = "Hello,\r\nthe printer on floor 2 shows error E5.\r\n\r\nCan someone have a look?\r\n\r\n\r\nSendt fra min iPhone";
$r = clean($c6, false);
check('C6', $r->content === "Hello,\nthe printer on floor 2 shows error E5.\n\nCan someone have a look?", 'plain text: footer cut, stays plain', json_encode($r->content));

// C8 New Outlook / Outlook on the web: the user typed INSIDE <div id="Signature"> (clicked into the template's first
// line) — structure seen live on glpi-demo 2026-11-26. Cut moves to the signature card (table) inside the marker.
$c8 = '<div class="elementToProof" style="font-size:11pt"><br></div><div id="Signature" data-signature-name="New" class="elementToProof">'
    . '<div class="elementToProof">The printer on floor 3 is offline.</div><div class="elementToProof"><img id="TAG-SHOT" width="600"></div>'
    . '<div class="elementToProof">Screenshot above.</div>'
    . '<table cellspacing="0" style="color:#333"><tbody><tr><td style="background:#c61f40"><div>&nbsp;</div></td><td><div><b>Jane D.</b></div>'
    . '<div>Consultant · Example</div><div>Mobile: +421 000 000 000<br>E-mail: <a href="mailto:jane@example.com">jane@example.com</a></div>'
    . '</td></tr></tbody></table><div class="elementToProof"><br></div></div>';
$r = clean($c8);
check('C8', text($r->content) === 'The printer on floor 3 is offline. Screenshot above.', 'text typed inside #Signature kept, signature card cut', text($r->content));
check('C8i', str_contains($r->content, 'TAG-SHOT') && $r->removed_image_tags === [], 'screenshot inside #Signature kept');
$c8b = '<div><br></div><div id="Signature"><div>Only text, no card</div><div>Mvh</div><div>Jane</div></div>';
$r = clean($c8b);
check('C8b', text($r->content) === 'Only text, no card', 'no card in the marker: marker skipped, sign-off still cuts', text($r->content));
$c8c = '<div><br></div><div id="Signature"><div><br></div><table><tr><td>Jane D. · Consultant</td></tr></table></div>';
$r = clean($c8c);
check('C8c', !in_array('signature', $r->applied, true), 'nothing typed at all: no signature cut (fail-safe keeps the email)');

// C7 Gmail reply.
$c7 = '<div dir="ltr">Done, thank you.</div><br><div class="gmail_quote"><div dir="ltr" class="gmail_attr">On Mon, X wrote:<br></div>'
    . '<blockquote class="gmail_quote">old</blockquote></div>';
check('C7', text(clean($c7)->content) === 'Done, thank you.', 'Gmail quote cut');

// ------------------------------------------------------------------------------------------------------------
// Sign-off rules: own line only (scope), positive twins
// ------------------------------------------------------------------------------------------------------------
$r = clean('<div>Best regards to the team, the fix works.</div><div>More text.</div>');
check('O1', !$r->changed(), 'phrase inside a sentence is not cut');
$r = clean('<div>The fix works.</div><div>Best regards,</div><div>Name</div>');
check('O1+', text($r->content) === 'The fix works.', 'twin: phrase on its own line is cut (trailing comma ok)', text($r->content));
$r = clean('<div>Mvh</div><div>Question below</div>');
check('O2', !in_array('signoff', $r->applied, true), 'never cut at the very first line');
$r = clean('<div>Text <span>Mvh</span> more</div><div>x</div>');
check('O3', !in_array('signoff', $r->applied, true), 'phrase sharing a line with other text is not cut');
$r = clean("Line one\nMVH.\nName", false);
check('O4', $r->content === 'Line one', 'case and punctuation insensitive in plain text', json_encode($r->content));
$r = clean('<div>Hi</div><div>Mvh</div><div>Name</div>', true, [Settings::SIGNOFF_RULES => '0']);
check('O5', !in_array('signoff', $r->applied, true), 'switch off disables sign-off cutting');

// ------------------------------------------------------------------------------------------------------------
// Regex rules (remove-only), title rules, PCRE errors (04 P2/P3)
// ------------------------------------------------------------------------------------------------------------
$rules = [
    ['id' => 1, 'pattern' => '(?i)^CAUTION: This email originated outside\.?\s*', 'target' => 'body'],
    ['id' => 2, 'pattern' => '^\[EXT\]\s*', 'target' => 'title'],
];
$r = clean('<p>CAUTION: This email originated outside. Printer is broken.</p>', true, [], $rules, '[EXT] Printer');
check('R1', text($r->content) === 'Printer is broken.', 'body rule removes text', text($r->content));
check('R2', $r->title === 'Printer', 'title rule removes prefix', (string) $r->title);
$r = clean('<p>x</p>', true, [], [['id' => 3, 'pattern' => '^.*$', 'target' => 'title']], 'Everything');
check('R3', $r->title === null, 'a title rule that would empty the title leaves it unchanged');
$r = clean('<p>a &lt;b&gt; c</p>', true, [], [['id' => 4, 'pattern' => 'a ', 'target' => 'body']]);
check('R4', !str_contains($r->content, '<b>'), 'rules work on text: escaped markup is never turned into tags (04 P1)', $r->content);
$thrown = false;
try {
    clean('<p>' . str_repeat('a', 5000) . 'b</p>', true, [], [['id' => 5, 'pattern' => '(a+)+$', 'target' => 'body']]);
} catch (RuntimeException $e) {
    $thrown = true;
}
check('F1', $thrown, 'catastrophic backtracking aborts the clean (caller keeps the original)');
$thrown = false;
try {
    clean('<p>aaab</p>', true, [], [['id' => 6, 'pattern' => '(a+)+b', 'target' => 'body']]);
} catch (RuntimeException $e) {
    $thrown = true;
}
check('F1+', !$thrown, 'twin: the same pattern on a small input runs');
check('F2', (int) ini_get('pcre.backtrack_limit') >= 1000000, 'backtrack limit restored after cleaning');
$r = clean('<p>x</p>', true, [], [['id' => 7, 'pattern' => '(', 'target' => 'body']]);
check('F3', !$r->changed(), 'invalid stored pattern is skipped');

// ------------------------------------------------------------------------------------------------------------
// Formatting strip, subset (04 P1), images (04 P6)
// ------------------------------------------------------------------------------------------------------------
$r = clean('<div style="color:red" onclick="x()"><font face="Arial"><a href="https://ex.com" onmouseover="y()" style="c">link</a></font>'
    . '<ul><li class="x">one</li></ul><img id="TAG-SHOT" width="10" onerror="z()"><img src="https://tracker.example/p.gif" width="1"></div>');
check('S1', !preg_match('/on\w+=|style=|class=|<font/i', $r->content), 'event handlers, styles, classes, font removed', $r->content);
$r2 = clean('<p><a href="javascript:alert(1)">x</a> <a href="mailto:a@example.com">m</a></p>');
check('S1b', !str_contains($r2->content, 'javascript:') && str_contains($r2->content, 'mailto:'), 'script links lose href, mail links kept', $r2->content);
check('S2', str_contains($r->content, '<a href="https://ex.com">link</a>') && str_contains($r->content, '<li>one</li>'), 'links and lists kept', $r->content);
check('S3', str_contains($r->content, 'id="TAG-SHOT"') && !str_contains($r->content, 'tracker.example'), 'GLPI image kept, remote image removed', $r->content);
check('S4', $r->removed_image_tags === [], 'kept image never reported for pruning');
$r = clean('<div style="color:red">Hello</div>', true, [Settings::FORMATTING_STRIP => '0']);
check('S5', !$r->changed(), 'formatting switch off: untouched');
$r = clean('<p>Ærlig talt — søknad på 3 språk: ąčę ö 😀</p><div style="x"></div>');
check('S6', str_contains($r->content, 'Ærlig talt — søknad på 3 språk: ąčę ö 😀'), 'UTF-8 text survives', $r->content);
$r = clean('<p>plain</p>');
check('S7', !$r->changed(), 'nothing to clean: unchanged, no re-serialisation');

// ------------------------------------------------------------------------------------------------------------
// Bounds and selectors
// ------------------------------------------------------------------------------------------------------------
$r = clean('<div id="Signature">' . str_repeat('x', Cleaner::MAX_INPUT_BYTES) . '</div>');
check('B1', !$r->changed(), 'oversized input is not cleaned (04 P7)');
$deep = str_repeat('<div>', 3000) . 'deep' . str_repeat('</div>', 3000) . '<div id="Signature">sig</div>';
$r = clean($deep);
check('B2', true, 'deeply nested input does not crash (' . strlen($r->content) . ' bytes)');
$r = clean('<div>Text</div><div id="Signature">sig</div>', true, [Settings::SIGNATURE_MARKER_LIST => "#Signature']|//*|x['"]);
check('B3', Selector::toXPath("#Signature']|//*|x['") === null && str_contains($r->content, 'sig'), 'malicious selector refused, nothing cut', $r->content);

check('X1', Selector::toXPath('#Signature') === ".//*[@id='Signature']", 'id selector');
check('X2', Selector::toXPath('blockquote[type=cite]') === ".//blockquote[@type='cite']", 'attribute selector');
check('X3', Selector::toXPath('table:has(a[href*=Learn])') === ".//table[.//a[contains(@href, 'Learn')]]", ':has selector');
check('X4', Selector::toXPath('*') === null && Selector::toXPath('div > p') === null && Selector::toXPath('') === null, 'unsupported selectors refused');
check('X5', Selector::toXPath("[title=\"it's\"]") === ".//*[@title=\"it's\"]", 'quotes in values become safe literals');

echo "\n$count checks, $failures failed\n";
exit($failures > 0 ? 1 : 0);
