<?php

/**
 * Behaviour tests for ImportHook with stubs of the GLPI classes it touches (05 "Tests without a GLPI instance").
 *
 * The stubs model what matters (PROJECT-REFERENCE §7): the hook receives the item and mutates $item->input;
 * mail-collector input carries `_mailgate` and a Laminas Message in `_message`; inline images are
 * `_filename[$f]` / `_tag[$f]` pairs. Every negative test has a positive twin (01 Test discipline).
 *
 * Usage: php tests/import_hook_test.php
 */

namespace Laminas\Mail\Storage {
    class Message {}
}

namespace Glpi\RichText {
    final class RichText
    {
        // Same expression as 11.0/bugfixes and 12.0/bugfixes src/Glpi/RichText/RichText.php::isRichTextHtmlContent().
        public static function isRichTextHtmlContent(string $content): bool
        {
            $tags = ['a', 'b', 'em', 'i', 'img', 'span', 'strong', 'br', 'hr', 'blockquote', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'pre', 'table', 'ul', 'ol'];
            return preg_match('/<(' . implode('|', $tags) . ')(\s+[^>]*)?>/i', $content) === 1;
        }
    }
}

namespace {
    define('GLPI_VERSION', 'test');
    define('GLPI_TMP_DIR', sys_get_temp_dir() . '/etc_test_' . getmypid());
    @mkdir(GLPI_TMP_DIR);

    class CommonDBTM
    {
        public $input = [];
    }
    class Ticket extends CommonDBTM {}
    class ITILFollowup extends CommonDBTM {}
    class Computer extends CommonDBTM {}

    final class Config
    {
        /** @var array<string, array<string, string>>|null null simulates an unreadable configuration */
        public static ?array $store = [];
        public static function getConfigurationValues($context, array $names = [])
        {
            if (self::$store === null) {
                throw new RuntimeException('db down');
            }
            return self::$store[$context] ?? [];
        }
        public static function setConfigurationValues($context, array $values = [])
        {
            self::$store[$context] = array_merge(self::$store[$context] ?? [], $values);
        }
        public static function deleteConfigurationValues($context, array $values = [])
        {
            unset(self::$store[$context]);
        }
    }

    final class Toolbox
    {
        /** @var list<string> */
        public static array $log = [];
        public static function logInFile($name, $text, $force = false, bool $output = true)
        {
            self::$log[] = $text;
            return true;
        }
    }

    final class StubDB
    {
        public bool $fail = false;
        public function request(array $criteria): array
        {
            if ($this->fail) {
                throw new RuntimeException('query failed');
            }
            return [];
        }
    }
    $DB = new StubDB();

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
    use GlpiPlugin\Emailticketcleaner\ImportHook;
    use GlpiPlugin\Emailticketcleaner\RuleRepository;
    use GlpiPlugin\Emailticketcleaner\Settings;

    $failures = 0;
    $count    = 0;
    function check(string $id, bool $ok, string $label, string $detail = ''): void
    {
        global $failures, $count;
        $count++;
        echo ($ok ? 'ok   ' : 'FAIL ') . "$id $label" . (!$ok && $detail !== '' ? " (got: $detail)" : '') . "\n";
        $failures += $ok ? 0 : 1;
    }
    function reset_state(): void
    {
        global $DB;
        Config::$store = [Settings::CONTEXT => Settings::getDefaults()];
        Toolbox::$log  = [];
        $DB->fail      = false;
        RuleRepository::resetCache();
        (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, null);
    }
    function mail_input(array $extra = []): array
    {
        return array_merge([
            '_mailgate'    => 3,
            '_auto_import' => 1,
            '_message'     => new Laminas\Mail\Storage\Message(),
            'name'         => 'Printer broken',
            'content'      => '<p>The printer on floor 2 is broken.</p>',
        ], $extra);
    }
    function last_log(): string
    {
        return (string) end(Toolbox::$log);
    }

    // --- S: scope (04 P5) -----------------------------------------------------------------------------------
    reset_state();
    $web = ['name' => 'x', 'content' => '<p>typed in GLPI</p>'];
    check('S1', !ImportHook::isMailCollectorInput($web), 'web-UI input is not mail input');
    $forged = ['_mailgate' => 1, '_message' => 'posted string', 'content' => '<p>x</p>'];
    check('S2', !ImportHook::isMailCollectorInput($forged), 'posted _mailgate + string _message is not mail input');
    check('S2+', ImportHook::isMailCollectorInput(mail_input()), 'twin: collector input with Message object is detected');
    check('S3', !ImportHook::isMailCollectorInput(mail_input(['content' => ['array']])), 'non-string content is refused (04 G15)');

    $ticket        = new Ticket();
    $ticket->input = $forged;
    ImportHook::preItemAdd($ticket);
    check('S4', $ticket->input === $forged && Toolbox::$log === [], 'forged input left untouched, nothing logged');

    $computer        = new Computer();
    $computer->input = mail_input();
    $before          = $computer->input;
    ImportHook::preItemAdd($computer);
    check('S5', $computer->input === $before, 'other itemtypes are ignored');

    // --- F: fail-safe (04 P3, G7, G15) ------------------------------------------------------------------------
    reset_state();
    Config::$store = null;
    $fup           = new ITILFollowup();
    $fup->input    = mail_input();
    $before        = $fup->input;
    ImportHook::preItemAdd($fup);
    check('F1', $fup->input === $before && str_contains(last_log(), 'reason=no-config'), 'unreadable config -> untouched, logged');

    reset_state();
    Config::$store = [];
    $fup->input    = $before;
    ImportHook::preItemAdd($fup);
    check('F2', $fup->input === $before && str_contains(last_log(), 'reason=no-config'), 'plugin config absent -> untouched');

    reset_state();
    $DB->fail   = true;
    $fup->input = $before;
    ImportHook::preItemAdd($fup);
    check('F3', $fup->input === $before && str_contains(last_log(), 'event=error'), 'exception inside cleaning -> untouched, logged');

    reset_state();
    $fup->input = $before;
    ImportHook::preItemAdd($fup);
    check('F3+', $fup->input === $before && !str_contains(implode('', Toolbox::$log), 'event=error'), 'twin: same input, no failure, no error logged');

    check('F4', Cleaner::visibleTextLength('<p>&nbsp;</p><div> <br></div>', true) === 0, 'HTML with only spaces/nbsp counts as empty');
    check('F4+', Cleaner::visibleTextLength('<p>Hi&nbsp;there</p>', true) === 7, 'twin: real text is counted');
    check('F5', Cleaner::visibleTextLength("  \r\n\t ", false) === 0, 'plain text with only whitespace counts as empty');
    check('F6', Cleaner::hasImage('<p><img id="abc"></p>'), 'image-only content is not empty');

    // --- P4: logs carry no content ---------------------------------------------------------------------------
    $all = implode('', Toolbox::$log);
    check('L1', !str_contains($all, 'printer') && !str_contains($all, 'Printer'), 'no subject or body text in the log');

    // --- I: inline image pruning (04 P6, §7 M6) ---------------------------------------------------------------
    $files = ['logo.png', 'shot.png', 'report.pdf', 'kept.png'];
    foreach ($files as $f) {
        file_put_contents(GLPI_TMP_DIR . "/$f", 'x');
    }
    $input = [
        'content'   => '<p>See screenshot <img id="TAG-SHOT"> and <img id="TAG-KEPT"></p>',
        '_filename' => ['logo.png' => 'logo.png', 'shot.png' => 'shot.png', 'report.pdf' => 'report.pdf', 'kept.png' => 'kept.png'],
        '_tag'      => ['logo.png' => 'TAG-LOGO', 'shot.png' => 'TAG-SHOT', 'kept.png' => 'TAG-KEPT'],
    ];
    // TAG-KEPT is (wrongly) reported as removed but is still in the content: must be kept (fail towards keeping data).
    $out = ImportHook::pruneImages($input, ['TAG-LOGO', 'TAG-KEPT']);
    check('I1', !isset($out['_filename']['logo.png'], $out['_tag']['logo.png']), 'removed signature logo: file and tag dropped');
    check('I1+', !is_file(GLPI_TMP_DIR . '/logo.png'), 'removed signature logo: temp file deleted');
    check('I2', isset($out['_filename']['shot.png'], $out['_tag']['shot.png']) && is_file(GLPI_TMP_DIR . '/shot.png'), 'kept screenshot stays');
    check('I3', isset($out['_filename']['report.pdf']) && is_file(GLPI_TMP_DIR . '/report.pdf'), 'real attachment stays');
    check('I4', isset($out['_filename']['kept.png'], $out['_tag']['kept.png']), 'tag still present in content is never dropped');

    $evil = [
        'content'   => '',
        '_filename' => ['x' => '../etc_test_evil'],
        '_tag'      => ['x' => 'TAG-X'],
    ];
    file_put_contents(sys_get_temp_dir() . '/etc_test_evil', 'x');
    ImportHook::pruneImages($evil, ['TAG-X']);
    check('I5', is_file(sys_get_temp_dir() . '/etc_test_evil'), 'path outside GLPI_TMP_DIR is never deleted');
    @unlink(sys_get_temp_dir() . '/etc_test_evil');

    // --- H: end to end through the hook with the real Cleaner --------------------------------------------------
    reset_state();
    file_put_contents(GLPI_TMP_DIR . '/logo.png', 'x');
    file_put_contents(GLPI_TMP_DIR . '/report.pdf', 'x');
    $ticket        = new Ticket();
    $ticket->input = mail_input([
        'content'   => '<div style="color:red">The scanner is offline.</div><div id="Signature"><div>Mvh</div><img id="TAG-LOGO"></div>',
        '_filename' => ['logo.png' => 'logo.png', 'report.pdf' => 'report.pdf'],
        '_tag'      => ['logo.png' => 'TAG-LOGO'],
    ]);
    ImportHook::preItemAdd($ticket);
    check('H1', $ticket->input['content'] === '<div>The scanner is offline.</div>', 'ticket cleaned through the hook', $ticket->input['content']);
    check('H1i', !isset($ticket->input['_filename']['logo.png']) && isset($ticket->input['_filename']['report.pdf']), 'signature logo pruned, attachment kept');
    check('H1l', str_contains(last_log(), 'event=result') && str_contains(last_log(), 'applied=signature,formatting'), 'result logged as metadata');

    reset_state();
    $fup        = new ITILFollowup();
    $only_sig   = mail_input(['content' => '<div id="Signature"><div>Mvh</div><div>Name</div></div>']);
    $fup->input = $only_sig;
    ImportHook::preItemAdd($fup);
    check('H2', str_contains($fup->input['content'], 'Mvh') && str_contains($fup->input['content'], 'Name'), 'signature-only follow-up is never emptied (§7 M10)', $fup->input['content']);

    // H3: the hook's own fail-safe (positive twin of H2): a regex rule that empties the message is refused there.
    reset_state();
    (new ReflectionProperty(RuleRepository::class, 'cache'))->setValue(null, [['id' => 9, 'pattern' => '(?s).+', 'target' => 'body']]);
    $fup->input = $only_sig2 = mail_input(['content' => '<div>Some text</div>']);
    ImportHook::preItemAdd($fup);
    check('H3', $fup->input === $only_sig2 && str_contains(last_log(), 'reason=too-little-left'), 'rule that would empty the message: original kept, logged');

    // --- Settings ---------------------------------------------------------------------------------------------
    $list = Settings::normaliseList("  Mvh \r\n\r\nMvh\nBest regards\n" . str_repeat('x', 300));
    check('C1', $list === ['Mvh', 'Best regards'], 'lists trimmed, deduped, empty and over-long lines dropped');
    reset_state();
    Config::$store[Settings::CONTEXT][Settings::SIGNOFF_LIST] = 'Admin phrase';
    Settings::installDefaults();
    check('C2', Config::$store[Settings::CONTEXT][Settings::SIGNOFF_LIST] === 'Admin phrase', 'installDefaults never overwrites an admin choice');

    foreach (glob(GLPI_TMP_DIR . '/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir(GLPI_TMP_DIR);

    echo "\n$count checks, $failures failed\n";
    exit($failures > 0 ? 1 : 0);
}
