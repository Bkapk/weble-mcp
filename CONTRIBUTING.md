# Contributing

Issues and pull requests are welcome. For a bigger change, open an issue first so we can agree on the approach.

Every pull request is reviewed by the maintainer before anything is merged, and some will be declined to keep the plugin focused. Nothing reaches a release, or anyone's site, without that review.

## Ground rules

- Plain PHP, no build step and no Composer dependencies. The plugin must keep running on PHP 7.4 and WordPress 6.2.
- No tools that change plugins, themes, users or files. That boundary is the core of the safety model.
- Every tool checks the connected user's WordPress capabilities.
- Prefix everything with `wbmcp_` / `WBMCP_`.
- Match the existing style: short functions, comments only where the reason isn't obvious.

## Working on the code

- `php -d zend.assertions=1 tests/tree-test.php` checks the logic without WordPress: the Elementor tree operations, the settings merge and dynamic-tag encoding, the custom-code guard, the color and contrast math, the lint rules, the preview tokens, and the audit redaction and size limits.
- `php build-zip.php` builds the installable zip. `php build-zip.php --wporg` builds the wordpress.org version, which leaves out the GitHub updater.
- The admin screen is a React app in `app-src/`, built with Vite into `weble-mcp/assets/app/`. The build is committed, so the plugin runs without Node. After changing the app:

  ```
  cd app-src && npm install && npm run build
  ```

## Before you open a pull request

```
php -d zend.assertions=1 tests/tree-test.php
find . -name '*.php' -exec php -l {} \;
```

Add a test to `tests/tree-test.php` for any logic that runs without WordPress (tree operations, the code guard, lint rules, redaction).

## Reporting a site that doesn't work

Use the "Site compatibility" issue template. Include the theme, page builder versions and the exact error the agent got.

Security issues go by email, not issues. See [SECURITY.md](SECURITY.md).
