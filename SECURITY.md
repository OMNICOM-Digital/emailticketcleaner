# Security Policy

This policy covers **Email Ticket Cleaner** (`emailticketcleaner`), developed and maintained by OMNICOM s.r.o. ("OMNICOM") and licensed under GPL-3.0-or-later.

Email Ticket Cleaner is published in this public repository, https://github.com/OMNICOM-Digital/emailticketcleaner, together with its changelog, security advisories and security releases.

## Supported versions

Every release receives security fixes for **five years from its own release date**, as shown on
[GitHub Releases](https://github.com/OMNICOM-Digital/emailticketcleaner/releases).

| Release | GLPI | Released | Security support until |
|---|---|---|---|
| 1.0.0 | 11.0.11 to 11.0.x (GLPI 12 planned for 1.1.0) | not yet released | five years from its release date |

A security patch (`1.x.y`) does not restart the support period of the release it fixes. Release dates are never
changed after publication. A new major version (for example 2.0) is created only when the security model or
intended use changes substantially, or when GLPI versions can no longer share one code base; releases of the
earlier major version keep their own support periods.

## Reporting a vulnerability

**Please do not open public issues for security problems.**

Report privately by either of these channels:

- E-mail: **security@omnicom.digital**
- GitHub private vulnerability reporting on this repository: [Report a vulnerability](https://github.com/OMNICOM-Digital/emailticketcleaner/security/advisories/new)

Please include:

- affected version of the plugin, and the GLPI version
- a description of the issue and its impact
- steps to reproduce or a proof of concept (for example a sample email with all personal data removed)
- whether you believe the issue is already being exploited

Reports are welcome from anyone: researchers, users and downstream integrators.

### What to expect

| Step | Target |
|---|---|
| Acknowledgement of your report | within **3** business days |
| Initial assessment and severity rating | within **10** business days |
| Fix or mitigation for confirmed issues | prioritised by severity; actively exploited vulnerabilities are handled first |
| Advisory published with the fix | on release of the fix |

We will keep you informed of progress and credit you in the advisory unless you prefer otherwise.

### Coordinated disclosure

We ask for **90** days from your report to prepare and release a fix before public disclosure. We will agree a shorter or longer period with you where the circumstances justify it (for example, active exploitation or a complex fix).

## How security fixes are delivered

- Security fixes are issued as **separate security releases** (patch releases such as `1.x.y`) that contain security fixes only, distinguishable from feature updates in the changelog (entries marked `Security:`).
- A fix is released as a patch of the current 1.x version, and users of older releases are asked to upgrade to it. Where upgrading is not possible for a release that is still within its support period (for example because of GLPI version constraints), we decide per advisory whether that release receives its own patch.
- Every security fix is tested on all GLPI versions the plugin supports.
- **Security fixes are free of charge** and available to everyone.
- Each fix is accompanied by an advisory describing the issue, affected versions, severity, and the action users should take.

### Where to get security releases

[GitHub Releases](https://github.com/OMNICOM-Digital/emailticketcleaner/releases) and [Security Advisories](https://github.com/OMNICOM-Digital/emailticketcleaner/security/advisories) of this repository, and the GLPI Marketplace.

## Scope

In scope: the plugin code, its import hook, settings and rule pages, and the plugin's own dependencies.

Out of scope: vulnerabilities in GLPI core itself (please report these to the GLPI project), in mail servers or mail clients, or in the customer's own infrastructure and configuration, unless the plugin makes the issue materially worse.

## Our commitments

- We document, triage and remediate reported vulnerabilities and track them to closure.
- We notify the competent authorities where the law requires it.
- We cooperate with competent authorities on request.
- We share information about fixed vulnerabilities with affected users and, where relevant, the wider open-source community.

## Safe harbour

We will not pursue legal action against researchers who act in good faith, avoid access to or disclosure of other people's data, do not degrade services, and report to us privately and promptly under this policy.
