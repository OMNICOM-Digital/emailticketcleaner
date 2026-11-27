# Changelog

Release history of Email Ticket Cleaner. Releases are published under [Releases](https://github.com/OMNICOM-Digital/emailticketcleaner/releases), free for everyone.

## Support period and release convention

- **Support period:** every release receives security fixes for five years from its own release date, as shown on [Releases](https://github.com/OMNICOM-Digital/emailticketcleaner/releases). A security patch does not restart the period of the release it fixes. See [SECURITY.md](SECURITY.md).
- **Feature releases** (`1.x.0`): new features, other fixes, or newly supported GLPI versions (GLPI 12 is planned for 1.1.0).
- **Security patches** (`1.x.y`, y > 0): security fixes only, never mixed with features or other changes; each is published as a GitHub Release with a Security Advisory.
- **Tags** are the plain version number without a prefix (for example `1.0.0`), matching the version in `setup.php`. Release packages are named `emailticketcleaner-X_Y_Z.zip` (for example `emailticketcleaner-1_0_0.zip`).
- Entries prefixed **Security:** fix a vulnerability or close a security gap.

## 1.0.0 — 2026-11-27

First release. For GLPI 11.0.11 to 11.0.x, PHP 8.2 or later.

- Cleans emails imported by the mail collector, for new tickets and follow-ups; items created in GLPI, through the API or forms, and later edits are never changed
- Signature removal: the signature and everything below it, recognised by mail-client markers (Outlook web, new and mobile, Gmail, Apple Mail, Thunderbird, Edison)
- Outlook's signature block is cut where the signature text starts, not as a whole: screenshots pasted into the block above the signature are kept, and text typed into it is kept when an empty line or a signature card follows
- Quoted reply and forward threads removed
- Blocks removed on their own, such as the Microsoft 365 "You don't often get email from..." banner
- Sign-off phrases: cut from a closing phrase on its own line; editable list with English, Norwegian, Slovak and Czech defaults and mail-app footers
- Regex rules on the message text or the ticket title, remove-only, validated on save
- Formatting strip: keeps text, links, lists, tables and pasted screenshots; removes styles, fonts, colours, remote images and scripts
- Inline images from removed parts are not saved as documents; real attachments are kept
- Fail-safe: on any error, or when too little text would remain, the original email is kept unchanged
- Settings in GLPI's core configuration; own right `plugin_emailticketcleaner_config`, granted on install to profiles with the General setup right
- GLPI 12 code paths are included and tested offline; GLPI 12 is declared after live tests (planned 1.1.0)
