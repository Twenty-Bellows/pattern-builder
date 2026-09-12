# CLAUDE.md

Guidance for Claude Code and other AI coding agents working in this repository.

## What this is

**Pattern Builder** is a WordPress plugin by [Twenty Bellows](https://twentybellows.com)
for creating, editing and organising block patterns in the admin. It unifies
theme patterns (PHP files) and user patterns (`wp_block` posts) in one UI, and
connects to [patternbuilderwp.com](https://patternbuilderwp.com) for sharing
them.

- **Requires:** WordPress 6.8+, PHP 7.4+ · **License:** GPL-2.0-or-later
- **Issues:** https://github.com/twenty-bellows/pattern-builder/issues

## Where the detail lives

Read these before changing the areas they cover. They describe how the system
works **now**; a change that makes one wrong is not finished until that document
is right.

| Document | Covers |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | The shape of the plugin: the rowless `pb_pattern` entity, the file store, editing surfaces, the browse grid and its tiles, previews and lab themes, the vendored runtime, the webpack bundles, the 1.x migration. |
| [`docs/abilities.md`](docs/abilities.md) | The agent interface: the 26 abilities, how annotations pick the HTTP method, the markup checks, the validator, the authoring guides, media and fonts. |
| [`docs/cloud.md`](docs/cloud.md) | patternbuilderwp.com: connecting, the proxy, the `pbp/1` porter, what travels with a pattern, `Safe_Css`, accounts and telemetry. |
| [`docs/collections.md`](docs/collections.md) | Collections as the plugin sees them. |
| [`docs/dependencies.md`](docs/dependencies.md) | Dependency trees and attribution. |

The service side lives in the
[patternbuilderwp.com repository](https://github.com/Twenty-Bellows/patternbuilderwp.com),
whose `docs/decisions.md` is the decision log both repositories reference.

## Development

Node 18+, PHP 7.4+ with Composer, Docker for `wp-env` and the PHP tests.

| Command | Does |
|---|---|
| `npm run build` / `npm run watch` | Production build / dev build with hot reload. |
| `npm run format` / `npm run lint:js` / `npm run lint:css` | JavaScript and style formatting and linting. |
| `composer format` / `composer lint` | PHP formatting and linting (PHPCS, WordPress Coding Standards). |
| `npm run test:unit` | JavaScript unit tests (no Docker). |
| `npm run test:php` | PHP tests in wp-env (**Docker**). |
| `npm run start` / `npm run stop` / `npm run clean` | wp-env lifecycle. |
| `npm run plugin-test` | Build, zip and open in WP Playground. |
| `npm run version-bump` | Bump the version everywhere it is tracked. |

**No Docker?** The PHP suite runs host-native on SQLite: download WordPress and
the `sqlite-database-integration` plugin, copy that plugin's `db.copy` to
`wp-content/db.php` with its two placeholders filled, write a
`wp-tests-config.php` whose `ABSPATH` points at that WordPress (any `DB_*`
values; `DB_DIR`/`DB_FILE` name the database file), then:

```
WP_TESTS_DIR=$(pwd)/vendor/wp-phpunit/wp-phpunit \
WP_PHPUNIT__TESTS_CONFIG=/path/to/wp-tests-config.php \
vendor/bin/phpunit
```

Everything passes there except `test_convert_theme_image_pattern_exports_assets`,
which needs a media pipeline the sandbox lacks.

**The cloud round trip** is manual, because it needs a second WordPress:
`wp eval-file tests/e2e/cloud-roundtrip.php <token> [pattern-id] [tree-pattern]`
against a live patternbuilderwp.com, then `… <token> install <owner>/<slug>` on
a second site. Every automated test of this path mocks `pre_http_request`, so
nothing else exercises the real multipart upload, the service's sanitization
and asset rehosting, or the download that fetches those assets back. Run it
after touching the porter, the cloud controller, or the service's store.

**Releasing to wp.org:** `npm run plugin-ship:dry-run` stages everything and
stops before the commit; `npm run plugin-ship` ships it; `npm run
plugin-ship:reset` restores the `svn/` working copy. The ship set is
`.distignore`; wp.org assets live in `.wordpress-org/`. Preflight requires the
version to agree in `pattern-builder.php`, `package.json` and readme.txt's
`Stable tag`.

## Invariants

The things that bite. Each is expanded in the document beside it.

- **Theme pattern files are the single source of truth.** Nothing is mirrored
  into the database and no core REST route is intercepted.
  ([architecture](docs/architecture.md))
- **The vendored runtime must stay logic-identical** to
  [synced-patterns-for-themes](https://github.com/Twenty-Bellows/synced-patterns-for-themes):
  `Pattern_Block`, `Pattern_Resolver`, `Block_Markup`,
  `Inner_HTML_Processor`, `Synced_Patterns`, `Editor_Support`, and
  `src/runtime/`. ([architecture](docs/architecture.md))
- **`Safe_Css` is vendored byte-for-byte** into the service but for its
  namespace, and `tests/php/fixtures/safe-css-cases.json` is the same file in
  both repositories. Change one, change both. ([cloud](docs/cloud.md))
- **Every theme-pattern write goes through
  `Pattern_File_Store::update_theme_pattern()`**, which is where a bare name is
  namespaced. A pattern registered under a bare name is one no `core/pattern`
  reference can reach, and an unresolved reference renders as nothing rather
  than as an error. ([architecture](docs/architecture.md))
- **Block validity is decided in the browser and nowhere else.** `save()` is
  JavaScript; no server can re-run it. ([architecture](docs/architecture.md))
- **Ability annotations select the HTTP method** — `readonly` is GET,
  `destructive` + `idempotent` is DELETE, everything else POST — and
  `meta.show_in_rest` must be true or the ability is unreachable.
  ([abilities](docs/abilities.md))
- **The browser never talks to the service.** All cloud traffic goes through
  the nonce- and capability-gated proxy. ([cloud](docs/cloud.md))
- **The plugin is fully functional disconnected.** ([cloud](docs/cloud.md))

## Keeping the documentation true

**A change is not finished until the documents describing it are right.** That
is a step in the work, not a follow-up: correct them in place, in the same
commit, and never annotate a stale passage with a note saying it is stale.

Before you open a pull request:

1. **Find what your change touched** in the table above, and read that
   document. Ask whether any sentence in it is now wrong — a renamed class, a
   moved file, a behaviour that changed, a count that shifted, a mechanism
   replaced by another.
2. **Update `readme.md`** if you changed what the plugin *is*, what it
   requires, or how it is run. It is deliberately minimal; keep it that way and
   put the detail in `docs/`.
3. **Update this file** if you changed a command, an invariant, or a standard.
   It is an index, not a manual — if a paragraph starts explaining a mechanism,
   that paragraph belongs in `docs/`.
4. **Run `npm run check:docs`.**

```
npm run check:docs          # every identifier the docs name must exist
npm run check:docs -- --list
```

The check reads every backticked token in `CLAUDE.md`, `readme.md` and `docs/`
and verifies it against the source: a path has to exist, a symbol has to appear
somewhere in the tree. It catches the way these documents actually rot — a
class that was renamed, a file that moved, a method that was never built.

**It cannot check prose.** A sentence can be wrong with every identifier in it
spelled correctly, so a green run means the names are real, not that the
document is true. The reading in step 1 is the part that matters; the check is
the backstop.

Two notes on using it. A name this repository only talks about — WordPress
core, another repository, an illustrative path — belongs in `ALLOW` in
`scripts/check-docs.mjs`, and a line carrying `check-docs:ignore` is skipped.
And do not write a removed symbol in backticks in order to say it was removed;
describe it in words, or the check has to be told to ignore a name that is
correctly absent.

## Coding standards

- **`docs/` is living.** Every document there describes how the system works
  now, corrected in place rather than annotated.
- **Comments carry what the code cannot.** A docblock is its summary plus
  `@param`/`@return`; an inline comment earns its place by preventing a bug.
  Reasoning and history belong in `docs/`.
- **PHP:** WordPress Coding Standards (WPCS 3.x) via PHPCS, config
  `phpcs.xml.dist`, namespace `TwentyBellows\PatternBuilder`.
- **JavaScript:** ESLint via `@wordpress/scripts`, extended by
  `eslint.config.cjs` for one thing only — `src/runtime/` is vendored, so two
  rules that disagree with how that upstream is written are turned off *there*
  and nowhere else.
- **Jest:** `jest-unit.config.js` extends the `@wordpress/scripts` config to
  compile `node_modules` and `.mjs`. Much of the WordPress dependency tree now
  ships ESM only and Jest cannot `require()` an ES module before Node 24.9;
  without this the suite fails to *load* rather than failing a test.
- **CSS/SCSS:** Stylelint via `@wordpress/scripts`. **Formatting:** Prettier
  (wp-prettier).
- Some pre-existing PHPCS violations remain (Yoda conditions, inline comment
  formatting). Fix them in files you touch; don't go looking.

## Versioning

The version is tracked in `pattern-builder.php`, `package.json` and
`readme.txt`. `npm run version-bump` changes all three.
