# Security policy

Fastleo is an admin panel that is open to the internet on every site that installs it, so security reports are welcome and taken seriously.

## Supported versions

Fixes are released for the latest minor version of the 4.x line (see [CHANGELOG.md](CHANGELOG.md)). Older versions do not get fixes: update with `composer update camanru/fastleo`.

## Reporting a vulnerability

Please do not open a public issue for a vulnerability.

Report it privately through GitHub: open the **Security** tab of this repository and choose **Report a vulnerability** ([private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing/privately-reporting-a-security-vulnerability)).

A good report has the version of the package, the steps to reproduce (a short request sequence or a failing test is ideal), what an attacker gains, and what role the attacker needs (a guest, an administrator with a limited role, a site visitor).

## What to expect

- An answer within 7 days saying whether the report is accepted.
- A fix in a patch or minor release as soon as it is ready, with a note in the CHANGELOG; you are credited there if you wish.
- Please give us time to release a fix before you publish details.

## Scope

In scope: the package code in this repository and its documented defaults (login, roles and Policies, file uploads, import and export, rich text sanitizing, the MCP server, notifications).

Out of scope: a site's own code and configuration (for example a Policy that a site did not write, or `APP_DEBUG=true` in production), denial of service that needs a large amount of traffic, and attacks that need access to the server or to the database.
