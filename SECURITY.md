# Security policy

Weble MCP gives an AI agent write access to a WordPress site, so security reports get priority.

## Reporting a vulnerability

Email **info@weble.studio** with "Weble MCP security" in the subject. Please do not open a public issue.

Include the plugin version, WordPress and PHP versions, and steps to reproduce. You will get a reply within 3 working days, and a fix or a plan within 14 days for confirmed issues. Reporters are credited in the changelog unless they ask not to be.

## Supported versions

Only the latest release gets security fixes.

## In scope

- Bypassing the custom-code guard while custom code is off
- Using a connection password outside the MCP endpoint
- A role reaching a tool or data its WordPress capabilities should not allow
- Secrets leaking through `get_site_audit` or the site brief
- Forging or reusing preview links
- `upload_media` reaching internal hosts
- The admin REST routes (`/weble-mcp/v1/admin/*`) accepting anything but a logged-in administrator's cookie

## Out of scope

- Anything that requires an administrator account with custom code switched on. That mode is meant to allow scripts.
- Attacks that need an already leaked administrator password.
