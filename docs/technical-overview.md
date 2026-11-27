# Email Ticket Cleaner: technical overview

Email Ticket Cleaner hooks into GLPI's ticket and follow-up creation and cleans the content of items created by
the mail collector, before GLPI saves them. It has no endpoints, makes no network requests and runs no
background tasks of its own.

## Flow

```
MailCollector (automatic action "mailgate")
        │ builds the input: content, name, _mailgate, _message, _filename, _tag
        ▼
Ticket::add() / ITILFollowup::add()
        │ pre_item_add hook (before prepareInputForAdd)
        ▼
ImportHook::preItemAdd()
        │ mail-collector input?  _mailgate set AND _message is a parsed mail object
        │                         no  ──► input left untouched
        ▼ yes
Cleaner::clean()  (pure PHP + DOM, no GLPI calls)
        0. remove blocks marked "removed on their own" (e.g. Microsoft 365 first-contact banner)
        1. cut at the earliest signature or quote marker, and everything after it
        2. cut at a sign-off phrase that stands on its own line
        3. admin regex rules on text nodes (message) and on the title
        4. strip formatting: keep text, links, lists, tables and GLPI inline images
        5. collect the GLPI tags of inline images that were in removed parts
        │
        ├── error, regex failure, too little text left ──► original input kept, event logged
        ▼ ok
Replace content (and title), drop _filename / _tag entries of removed inline images
        ▼
GLPI saves the ticket or follow-up
```

## Classes

| Class | Role |
|---|---|
| `ImportHook` | Detects mail-collector input, calls the cleaner, applies the fail-safe, replaces the input in one step, prunes removed inline images |
| `Cleaner`, `CleanResult` | Cleaning logic, identical on every GLPI version; HTML in, cleaned HTML and removed image tags out |
| `Selector` | Marker syntax (`#id`, `.class`, `tag[attr=value]`, `[attr*=part]`, `[attr^=start]`, `tag:has(...)`) to XPath |
| `Pattern` | Compiles and validates rule patterns: body only, delimiter and flags added by the plugin |
| `Settings` | Feature switches and lists, stored in GLPI's core configuration (`plugin:emailticketcleaner`) |
| `Rule`, `RuleRepository` | Regex rules as a GLPI dropdown; active rules in order for the hook |
| `Compat` | The only place that knows about GLPI version differences (feature detection) |
| `Log` | Import log in `files/_log/emailticketcleaner.log` |

## Behaviour guarantees

- **Only mail-collector input.** `_mailgate` alone can be posted from GLPI's web forms, so the hook also requires
  `_message` to be a parsed mail object (`Laminas\Mail\Storage\Message`), which cannot come from HTTP or API
  input. Updates (`pre_item_update`) are never hooked.
- **Output is a subset of the input.** The cleaner removes nodes, attributes and text; it never adds markup from
  the email. Rules are remove-only and run on text nodes, so they cannot create markup. GLPI's own sanitiser still
  runs on display.
- **Fail-safe.** Any exception, PCRE error, parse failure, or a result with fewer visible characters than the
  configured minimum keeps the input exactly as received. A follow-up with empty content would be refused by
  GLPI and the email lost, so the plugin never produces empty content.
- **Bounded work.** Bodies over 2 MB are not cleaned; regex rules run with a lowered PCRE backtrack limit; HTML is
  parsed without network access or external entities. Patterns are limited to 1000 characters, lists to 200
  entries of 200 characters.
- **Images.** GLPI creates a document for every `_filename` entry. Only inline images that were inside a removed
  part, and are no longer referenced in the cleaned content, are dropped. Real attachments and images in the kept
  text stay.
- **Logs carry metadata only:** event, item type, sizes, features applied, error class. Never message content,
  subjects, addresses or file names.
- **Missing configuration means no cleaning**, never "clean with everything on".

## GLPI 11 and 12

One code base. GLPI 12 declares some core properties with types (for example `public static string $rightname`)
that GLPI 11 declares without, and a class redeclaring them fatals on the other version. Settings therefore use
core `Config` instead of a class, and `Rule` extends a version-specific base selected by feature detection. 1.0.0
declares GLPI 11 only; GLPI 12 is declared once it has passed live tests.

## Tests

`tests/` (not shipped in the release zip):

- `cleaner_test.php`: cleaner fixtures shaped like real emails from each mail-client family, fail-safe cases with
  positive twins, rule and selector cases
- `import_hook_test.php`: hook scope, fail-safe, image pruning and settings, with stubs of the GLPI classes used
- `class_load.php <glpi-checkout>`: loads every plugin class against the real GLPI base classes of a checkout

```bash
php tests/cleaner_test.php
php tests/import_hook_test.php
php tests/class_load.php /path/to/glpi
```

Translations: `python3 tools/locales.py` rebuilds `locales/emailticketcleaner.pot`, merges the `.po` files and
compiles the `.mo` files.
