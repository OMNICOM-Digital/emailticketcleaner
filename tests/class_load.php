<?php

/**
 * Class-load check against GLPI core's REAL base classes (03 §1, 05 "Tests without a GLPI instance").
 *
 * Usage: php tests/class_load.php /path/to/glpi-checkout
 * Run once per supported major (11.0/bugfixes and 12.0/bugfixes). Catches what `php -l` misses:
 * incompatible method signatures, typed/untyped property redeclarations, static/instance clashes.
 * Only class declarations are loaded; no GLPI method is executed.
 */

$glpi = rtrim($argv[1] ?? '', '/');
if ($glpi === '' || !is_file("$glpi/src/CommonDropdown.php")) {
    fwrite(STDERR, "usage: php tests/class_load.php <glpi checkout>\n");
    exit(2);
}
$plugin = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($glpi, $plugin): void {
    $prefix = 'GlpiPlugin\\Emailticketcleaner\\';
    if (str_starts_with($class, $prefix)) {
        $file = "$plugin/src/" . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    } else {
        $file = "$glpi/src/" . str_replace('\\', '/', $class) . '.php';
    }
    if (is_file($file)) {
        require_once $file;
    }
});

// Constants referenced by class declarations only.
foreach (['READ' => 1, 'UPDATE' => 2, 'CREATE' => 4, 'DELETE' => 8, 'PURGE' => 16, 'ALLSTANDARDRIGHT' => 31] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

$classes = [
    GlpiPlugin\Emailticketcleaner\Settings::class,
    GlpiPlugin\Emailticketcleaner\Compat::class,
    GlpiPlugin\Emailticketcleaner\Rule::class,
    GlpiPlugin\Emailticketcleaner\ImportHook::class,
    GlpiPlugin\Emailticketcleaner\Cleaner::class,
    GlpiPlugin\Emailticketcleaner\CleanResult::class,
    GlpiPlugin\Emailticketcleaner\RuleRepository::class,
    GlpiPlugin\Emailticketcleaner\Log::class,
    GlpiPlugin\Emailticketcleaner\Pattern::class,
    GlpiPlugin\Emailticketcleaner\Selector::class,
];

$version = preg_match("/define\('GLPI_VERSION', '([^']+)'/", (string) file_get_contents("$glpi/src/autoload/constants.php"), $m) ? $m[1] : '?';
$failed  = 0;
foreach ($classes as $class) {
    try {
        $ok = class_exists($class);
    } catch (Throwable $e) {
        $ok = false;
        echo "  error: " . $e->getMessage() . "\n";
    }
    echo ($ok ? 'ok   ' : 'FAIL ') . $class . "\n";
    $failed += $ok ? 0 : 1;
}

$rule   = new ReflectionClass(GlpiPlugin\Emailticketcleaner\Rule::class);
$parent = $rule->getParentClass()->getName();
echo "GLPI $version: Rule extends $parent; Rule::\$rightname = "
    . GlpiPlugin\Emailticketcleaner\Rule::$rightname . "\n";

if (GlpiPlugin\Emailticketcleaner\Rule::$rightname !== GlpiPlugin\Emailticketcleaner\Settings::RIGHT) {
    echo "FAIL Rule::\$rightname\n";
    $failed++;
}
if (CommonDropdown::$rightname !== 'dropdown') {
    echo "FAIL core CommonDropdown::\$rightname was changed\n";
    $failed++;
}

// A: regex validation on save (04 P2). Translation functions are not loaded here; identity stubs.
if (!function_exists('__')) {
    function __($str, $domain = 'glpi')
    {
        return $str;
    }
}
$cases = [
    ['A1',  '(',                  false, 'unbalanced parenthesis refused'],
    ['A1+', '^Sent from my .*$',  true,  'twin: valid expression accepted'],
    ['A2',  '',                   false, 'empty expression refused'],
    ['A3',  "a\x01b",             false, 'delimiter character refused'],
    ['A4',  str_repeat('a', 1001), false, 'over-long expression refused'],
    ['A5',  '/e',                 true,  'slashes need no escaping (delimiter is ours)'],
];
foreach ($cases as [$id, $pattern, $valid, $label]) {
    $error = GlpiPlugin\Emailticketcleaner\Rule::getPatternError($pattern);
    $ok    = ($error === null) === $valid;
    echo ($ok ? 'ok   ' : 'FAIL ') . "$id $label" . ($error !== null ? " ($error)" : '') . "\n";
    $failed += $ok ? 0 : 1;
}

// A1b: same refusal while an error handler like GLPI's is active (it returns true for every error, so
// error_get_last() stays empty — the reason must still be reported, not "Internal error").
set_error_handler(static fn(): bool => true);
$error = GlpiPlugin\Emailticketcleaner\Rule::getPatternError('(');
restore_error_handler();
$ok = $error !== null && str_contains($error, 'missing closing parenthesis');
echo ($ok ? 'ok   ' : 'FAIL ') . "A1b reason reported under GLPI's error handler ($error)\n";
$failed += $ok ? 0 : 1;

exit($failed > 0 ? 1 : 0);
