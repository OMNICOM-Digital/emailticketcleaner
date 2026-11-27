<p align="center">
  <img src="logo.png" width="120" alt="Email Ticket Cleaner" />
</p>

<h1 align="center">Email Ticket Cleaner for GLPI</h1>

<p align="center">
  <img src="https://img.shields.io/badge/release-unreleased-C61F40" alt="Not released yet" />
  <img src="https://img.shields.io/badge/license-GPLv3-C61F40" alt="GPLv3 license" />
  <img src="https://img.shields.io/badge/GLPI-11.0.11--11.0-C61F40" alt="GLPI 11.0.11-11.0" />
</p>

**Clean emails before they become GLPI tickets.**

A GLPI plugin that cleans every email the mail collector imports, for new tickets and for follow-ups, so the
ticket timeline shows only what the sender wrote: no signatures, logos, social icons, legal disclaimers, quoted
reply threads or heavy formatting.

Free and open source. Built and maintained by [OMNICOM s.r.o.](https://omnicom.digital), an ITSM/ESM consultancy.

> Email Ticket Cleaner is a separate plugin by OMNICOM s.r.o. It is not a new version of the "Ticket Cleaner"
> plugin (key `ticketcleaner`, GLPI 10 only); it is an alternative to it for GLPI 11.

## Download

The first release (1.0.0) is in final testing. Once published, install Email Ticket Cleaner from the GLPI
Marketplace (**Setup > Plugins > Marketplace**), or download `emailticketcleaner-X_Y_Z.zip` (for example
`emailticketcleaner-1_0_0.zip`) from [Releases](https://github.com/OMNICOM-Digital/emailticketcleaner/releases).
Both are free for everyone. Each release lists the SHA-256 checksum of its zip in the release notes.

## Features

- **Signature removal:** the signature and everything below it (logos, contact and social icons, legal
  disclaimers), recognised by the markers that Outlook, Gmail, Apple Mail and Thunderbird add
- **Quoted thread removal:** earlier messages quoted in replies and forwards
- **Sign-off rules:** the message is cut from a closing phrase on its own line ("Best regards", "Med vennlig
  hilsen", "Mvh", "Sent from my iPhone", ...). The list is editable.
- **Your own rules:** regular expressions that remove recurring text from the message or the ticket title
- **Banners:** blocks such as Microsoft 365's "You don't often get email from..." notice are removed
- **Clean formatting:** fonts, colours and styling are removed; text, links, lists, tables and pasted
  screenshots stay. Remote images (tracking pixels) are removed.
- **No leftover images:** logos and icons from the removed part are not saved as documents. Real attachments
  are kept.
- **Safe by default:** if cleaning fails, or would leave too little text, the original email is kept unchanged
- **Only imported email:** tickets and follow-ups created in GLPI's web interface, through the API or forms,
  and all later edits, are never changed

## Installation

1. **Recommended:** in GLPI, go to **Setup > Plugins > Marketplace**, tab **Discover**, search for
   `Email Ticket Cleaner`, then click **Download**, **Install** and **Enable**. Works on GLPI Network Cloud too.
   Self-hosted GLPI needs a GLPI Network registration key (**Setup > General > GLPI Network**) to use the
   Marketplace.
   **Manual:** extract the zip from [Releases](https://github.com/OMNICOM-Digital/emailticketcleaner/releases)
   into `<glpi>/plugins/` (the folder must be named `emailticketcleaner`), then install and enable the plugin in
   **Setup > Plugins**.
2. Review the settings (**Setup > Plugins**, the plugin's configure link, or the sliders icon on
   **Setup > Email Ticket Cleaner**), especially the default sign-off list.
3. Optional: add your own rules in **Setup > Email Ticket Cleaner**.

Cleaning starts with the next mail-collector run. Existing tickets are not changed.

## Settings and permissions

- Right **Email Ticket Cleaner configuration** (`plugin_emailticketcleaner_config`): granted on install to every
  profile that has the core *General setup* right (read and update). It controls the settings page and the
  rule list. Settings are global, not per entity.
- **Settings page:** a switch per feature, plus the lists of signature markers, quote markers, blocks removed on
  their own and sign-off phrases (one per line), and the fail-safe minimum of remaining characters.
- **Rules** (**Setup > Email Ticket Cleaner**): pattern, applies to message text or ticket title, order, active.
  Patterns are checked when saved; an invalid pattern is refused. Delimiters and flags are added by the plugin
  (`m` and `u`); use `(?i)` for case-insensitive matching. Rules only remove text.

### Permission enforcement

- `front/config.form.php` (settings): `Session::checkRight()` on the plugin right, READ to open the page,
  UPDATE before saving. Forms use GLPI's CSRF protection.
- The rule list and form have no own front files: GLPI's generic dropdown controllers serve them and check the
  rule class's right (`plugin_emailticketcleaner_config`) on every request.
- The import hook runs inside the mail collector, not in a user session. It only changes the content and title
  of the ticket or follow-up being created from an email, and acts only when the input carries the parsed mail
  object, which web forms and the API cannot send.

## Good to know

- Plain-text emails carry no markers; they are cleaned only by the sign-off phrases and your own rules.
- Signatures typed by hand, or added by a central signature tool, may carry no marker. The sign-off phrases
  cover most of these.
- Text written below a signature or a sign-off phrase is removed with it.
- Some mail apps attach an old signature logo without showing it in the message; GLPI then saves it as an
  attachment. Mark that logo once as "Blacklisted for import" (**Management > Documents**) and GLPI skips it.
- GLPI business rules that test the ticket *Description* see the cleaned text.

More detail on how it works: [docs/technical-overview.md](docs/technical-overview.md).

## Compatibility

- GLPI 11.0.11 to 11.0.x, on-premise and GLPI Network Cloud
- PHP 8.2 or later
- GLPI 12 support is planned for 1.1.0. After an upgrade to GLPI 12, GLPI disables the plugin until a release
  supporting it is installed; emails are then imported uncleaned.

## Source code

The plugin source is in this repository (`setup.php`, `hook.php`, `src/`, `front/`, `templates/`); it is the
folder GLPI expects as `<glpi>/plugins/emailticketcleaner`. `main` can contain changes that are not released
yet, so for installation use the Marketplace or the zip from Releases. Issues and pull requests are welcome; for
security problems follow [SECURITY.md](SECURITY.md).

## Security

Each release is supported with free security fixes for at least five years from its release date. To report a
vulnerability, see [SECURITY.md](SECURITY.md) or e-mail security@omnicom.digital. Please do not open public issues
for security problems.

## License

GPL v3.0 or later, see [LICENSE](LICENSE). Copyright 2026 OMNICOM s.r.o.

## Get in touch

Email Ticket Cleaner comes without a support SLA. Questions, or help setting it up for your GLPI:
sales@omnicom.digital or [omnicom.digital](https://omnicom.digital).
