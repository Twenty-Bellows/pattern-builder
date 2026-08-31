# CLAUDE.md

This file provides guidance to Claude Code and other AI coding agents when working with code in this repository.

## Project Overview

**Pattern Builder** is a WordPress plugin developed by [Twenty Bellows](https://twentybellows.com). It allows WordPress users to create, edit, organize, and manage block patterns directly in the admin interface — unifying theme patterns (PHP files) and user-created patterns (`wp_block` posts) in a single, intuitive UI with visual editing, live preview, metadata management, and conversion between the two.

- **Version:** 2.0.0
- **Repository:** https://github.com/twenty-bellows/pattern-builder
- **Issue Tracker:** GitHub Issues — https://github.com/twenty-bellows/pattern-builder/issues
- **Plugin URI:** https://www.twentybellows.com/pattern-builder/
- **License:** GPL-2.0-or-later
- **WordPress Requires:** 6.8+
- **PHP Requires:** 7.4+

## Architecture (Key Design Decisions)

Version 2.0 removed the 1.x DB-mirror + REST-hijacking design entirely. Theme pattern files are the single source of truth; nothing is mirrored into the database and no core REST route is intercepted.

**Theme patterns are file-backed REST entities.** A rowless post type `pb_pattern` (registered like core's `wp_template` — zero DB rows) hangs `Pattern_Builder_REST_Patterns_Controller` off core routing at `/pattern-builder/v1/patterns`. Theme patterns have string IDs (their namespaced name, e.g. `my-theme/hero`), templates-style. Reads come from the pattern files (child + parent theme); writes go back to the files (`Pattern_File_Store`). Because the type is `show_in_rest`, the block editor auto-creates a matching client-side entity from `/wp/v2/types`, which gives theme patterns entity-powered editing (undo, dirty tracking, save flow) for free.

**Editing surfaces — always the WordPress editor, never a custom one.** The post editor opens any pattern in place via `onNavigateToEntityRecord` (both `wp_block` and `pb_pattern`). User patterns are otherwise edited by the Site Editor natively (its `/wp_block/:postId` route) — in place from within the Site Editor, deep-linked from everywhere else. Theme patterns cannot open in the Site Editor's canvas (core hard-codes the entity types its canvas binds and keeps route registration private), so from the Site Editor and the browse screen they open Appearance → Pattern Builder's edit mode (`&pattern={id}`), which boots core's own edit-post editor (`wp.editPost.initializeEditor`) against the `pb_pattern` entity — the genuine post-editor chrome, with a validated `back` URL whose Back button returns to the originating screen. The Appearance page's browse mode is a Site-Editor-style library: a header carrying the Pattern Builder mark and four collection tabs (User / Theme / Uploaded / Community — the last two served by the cloud browser), each with its own search and its own category rail (local pattern categories, or cloud collections); a grid of fixed-size square pattern tiles — the Site Editor's pattern grid: every preview, local or cloud, renders at one design width (1400px) and is scaled into the tile by a constant the stylesheet computes from the two sizes (`src/_pattern-tiles.scss`), so a short pattern is centred and a tall one is cropped at the same point in both grids, with nothing measuring anything in JavaScript; and an always-present details sidebar whose Save and Edit actions sit at the top above the panels the editor also shows (staged on the entity, persisted by Save; Edit opens the pattern's editor). Creating a pattern happens in one modal — title, destination, synced status, and an optional *Create with AI* prompt.

**Synced patterns via the `core/pattern` content runtime.** Synced theme patterns (`Synced: yes` file header) work exactly like Synced Patterns for Themes 2.0: `core/pattern` gets a `content` attribute + `pattern/overrides` context and a render callback that attaches the pattern's blocks as inner blocks (`Pattern_Block`); `Pattern_Resolver` composes editor-facing content; a synthesized `--synced-instance` companion entry puts a reference in the inserter. Inserted copies are plain `<!-- wp:pattern {"slug":…,"content":{…}} /-->` — no post ID anywhere.

**Companion plugin coexistence.** The runtime classes (`Pattern_Block`, `Pattern_Resolver`, `Block_Markup`, `Inner_HTML_Processor`, `Synced_Patterns`, `Editor_Support`, and the `src/runtime/` JS) are vendored from [`synced-patterns-for-themes`](https://github.com/Twenty-Bellows/synced-patterns-for-themes) and must stay logic-identical to it. Pattern Builder always registers the full stack; when both plugins are installed, the companion sees `PATTERN_BUILDER_VERSION` at `plugins_loaded` and stays entirely unloaded — one check in one place, no coordination anywhere else. Deactivate Pattern Builder and the companion takes over again with identical rendering (both read the same `Synced: yes` header; keeping the vendored runtime in sync at release time is what makes the hand-off invisible). Pattern Builder also clears the companion's transient after file writes so it never wakes to a stale cache.

**Migration from 1.x.** `Pattern_Builder_Migration` runs once on upgrade: it rewrites `wp:block` refs pointing at the old `tbell_pattern_block` mirror posts to `wp:pattern` slugs (in post content and theme files), then deletes the mirror posts and the old capabilities.

**Webpack entries:** `PatternBuilder_EditorTools.js` (management: sidebar, panels, save monitor — all block-editor screens), `PatternBuilder_Runtime.js` (the vendored content runtime — enqueued only when this plugin owns it), `PatternBuilder_Admin.js` (the browse grid, plus the edit-mode boot of core's edit-post editor).

**The cloud module (2.1) — patternbuilderwp.com integration.** Users connect their personal [patternbuilderwp.com](https://github.com/Twenty-Bellows/patternbuilderwp.com) account per WP user by signing in (or signing up) **inside wp-admin** — the connect panel posts credentials to this site's proxy, which relays them server-side to the service's `/auth/login` / `/auth/signup` and stores only the returned bearer token (`Pattern_Builder_Cloud`, user meta); the browser never visits the service and credentials are never logged or stored. The service URL is the `pattern_builder_cloud_url` option, overridable by a `PATTERN_BUILDER_CLOUD_URL` wp-config constant — the declarative choice for dev setups like a `.wp-env.json` `config` block, since it survives DB resets — and filterable. The browser never talks to the service: all cloud traffic flows through nonce+capability-gated proxy routes (`/pattern-builder/v1/cloud/*`, `Pattern_Builder_Cloud_Controller`). `Pattern_Builder_Cloud_Porter` converts local patterns ↔ the service's Portable Pattern Package (`pbp/1`): exports bundle local images as `pbp-asset://` placeholders + files; imports fetch package assets into the media library — always from the configured service origin (asset URLs are re-rooted onto it, never fetched from the host they name, so the service self-identifying by a different URL than the one this site reaches it by just works) — re-sanitize the markup (KSES + scheme checks — never trust the wire), then land as a `wp_block` or flow through `Pattern_File_Store::update_theme_pattern()` for theme destinations. The cloud details card recognizes a pattern already installed locally (link map + a liveness check) and offers *Edit pattern* in place of the download actions. A site option (`pattern_builder_cloud_links`) maps local patterns to cloud IDs so re-uploads offer Update-vs-New. UI lives in `src/cloud/CloudBrowser.js`, mounted behind the browse app's Uploaded and Community tabs (search and collection filter owned by the browser chrome). **AI generation lives in the create-pattern modal** (`src/cloud/generate.js`): with a prompt filled in, Create submits prompt and/or screenshot through `/cloud/generate`, polls the job, downloads the result to the chosen destination, and opens it in the editor; empty prompt creates a blank pattern. The section is hidden while disconnected and upgrade-gated for free accounts (`upgrade_url`). Connected users also get the **cloud control inside the Pattern Source panel** (`PatternCloudControls`, so it appears in both the browse sidebar and the editor): per-pattern *Upload to the cloud* / *Update pattern on the cloud* / up-to-date, with "changed since upload" tracked by a raw-content hash stored in the cloud-link map at upload time and read back through `/cloud/pattern-state` (link map + hash only — no service round trip). All gating is server-side on the service — the client only mirrors `/me`. The plugin remains fully functional disconnected.

---

## Development Environment

### Prerequisites
- Node.js (v18+ recommended)
- PHP 7.4+ with Composer
- Docker (for `wp-env` local WordPress environment and PHP integration tests)

### Environment Notes
- Docker is available (host socket shared). All `wp-env` commands work.
- `wp-env` binary is at `node_modules/.bin/wp-env` — run via npm scripts from this directory.
- First `npm run start` will pull WordPress Docker images (~1-2 min).

### Known Pre-Existing Issues
- Several PHP lint violations exist in the codebase (Yoda conditions, inline comment formatting). These are pre-existing and not regressions. Fix them if you touch the file; don't feel obligated to fix unrelated files.

## Development Commands

### Build Commands
- `npm run build` - Production build with minification
- `npm run watch` - Development build with hot reload
- `npm run format` - Format JavaScript code
- `npm run lint:js` - Lint JavaScript files
- `npm run lint:css` - Lint CSS/SCSS files
- `composer format` - Format PHP code using WordPress coding standards
- `composer lint` - Lint PHP code

### Testing Commands
- `npm run test:unit` - Run JavaScript unit tests (no Docker required)
- `npm run test:unit:watch` - Run JavaScript tests in watch mode
- `npm run test:php` - Run PHP unit tests in wp-env environment (**requires Docker**)
- `npm run test:php:watch` - Run PHP tests in watch mode (**requires Docker**)
- `composer test` - Run PHP tests directly via PHPUnit (requires WP test bootstrap)

### Development Environment (Docker required)
- `npm run start` - Start wp-env with xdebug enabled
- `npm run stop` - Stop wp-env
- `npm run clean` - Clean wp-env
- `npm run plugin-test-env` - Start WP Playground for testing
- `npm run plugin-test` - Full build, zip, and test workflow

### Releasing to WordPress.org (same workflow as synced-patterns-for-themes)
- `npm run plugin-ship:dry-run` - Stage everything (SVN sync, assets, tag) and stop before the commit
- `npm run plugin-ship` - Ship the release to the WordPress.org SVN (asks for confirmation; SVN prompts for wp.org credentials)
- `npm run plugin-ship:reset` - Put the `svn/` working copy back the way wp.org has it
- The ship set is defined by `.distignore`; wp.org assets (icon) live in `.wordpress-org/`
- Preflight requires the version to agree in `pattern-builder.php` (header), `package.json`, and readme.txt's `Stable tag`

## Architecture Overview

### Plugin Structure
The plugin follows a component-based OOP architecture with clear separation of concerns:

1. **Main Entry Point**: `pattern-builder.php` initializes the plugin
2. **Core Class**: `Pattern_Builder` (singleton in `includes/class-pattern-builder.php`) bootstraps all plugin components
3. **Component Classes** (`includes/`):
   - `Pattern_Builder_Entity` - Rowless `pb_pattern` post type registration
   - `Pattern_Builder_REST_Patterns_Controller` - String-ID REST controller for theme patterns
   - `Pattern_File_Store` - Reads/writes pattern files; image import/export; conversions
   - `Pattern_Builder_API` - The `/pattern-builder/v1/process-theme` endpoint
   - `Pattern_Builder_Admin` - Appearance → Pattern Builder: browse grid + core-editor boot
   - `Pattern_Builder_Editor` - Block editor asset integration
   - `Pattern_Builder_Migration` - One-time 1.x → 2.0 upgrade
   - `Pattern_Builder_Security` - File-path validation and safe filesystem helpers
   - `Pattern_Builder_Localization` - i18n support
   - Vendored runtime (kept identical to synced-patterns-for-themes): `Pattern_Block`, `Pattern_Resolver`, `Block_Markup`, `Inner_HTML_Processor`, `Synced_Patterns`, `Editor_Support`

### Frontend Architecture
- **Build System**: Webpack via `@wordpress/scripts` with three entry points:
  - `src/PatternBuilder_EditorTools.js` - Editor tools (sidebar, document panels, save monitor)
  - `src/PatternBuilder_Runtime.js` - The vendored core/pattern content runtime
  - `src/PatternBuilder_Admin.js` - The Appearance → Pattern Builder page (browse grid + edit-post boot)
- **React Components** in `src/components/`:
  - `PatternBrowserPanel` - Main pattern browsing interface
  - `PatternCreatePanel` - Pattern creation flow
  - `PatternPreview` - Pattern preview rendering
  - `BlockBindingsPanel` - Block bindings configuration panel
  - `PatternAssociationsPanel`, `PatternSyncedStatusPanel`, `PatternMetadataPanel`, `PatternPanelAdditions`, `PatternSourcePanel` - Editor sidebar panels (also reused by the browse page's details sidebar)
  - `PatternCloudPanel` - The per-pattern cloud upload/update control, rendered inside `PatternSourcePanel` (connected users only)
  - `PatternCard`, `PatternDetailsPanel` - The browse grid's square cards and details sidebar
  - `EditPatternToolbarButton` - "Edit Pattern" in the toolbar of synced theme pattern instances
  - `EditorSidePanel` - Editor sidebar container
  - `PatternList` - Pattern list/grid view
  - `PatternBuilderConfiguration` - Plugin settings UI
- **Admin app** in `src/admin/`: `App`, `PatternBrowser`, `editor-boot`
- **Vendored runtime** in `src/runtime/` (keep identical to synced-patterns-for-themes `src/`)
- **State Management**: core data stores (`core`, `core/editor`, `core/block-editor`) — no custom store

### Pattern Handling
- Supports both **theme patterns** (PHP files in `patterns/`, child and parent theme) and **user patterns** (core `wp_block` posts)
- Abstract pattern class (`src/objects/AbstractPattern.js`) provides unified interface
- Pattern syncing capabilities between theme files and database

### REST API
Endpoints registered under `/wp-json/pattern-builder/v1/`. Authentication via WordPress nonce system.

### Key Development Patterns
- PHP classes follow WordPress coding standards with proper namespace (`TwentyBellows\PatternBuilder`)
- JavaScript follows WordPress/Gutenberg patterns using `@wordpress` packages
- Assets enqueued with proper dependency management using `.asset.php` files generated by Webpack
- Security: nonce verification on all state-changing operations, capability checks, data sanitization

## Coding Standards

- **PHP**: WordPress Coding Standards (WPCS 3.x) via PHPCS. Config: `phpcs.xml.dist`
- **JavaScript**: ESLint via `@wordpress/scripts` defaults
- **CSS/SCSS**: Stylelint via `@wordpress/scripts` defaults
- **Formatting**: Prettier (wp-prettier) for JS/CSS

## Versioning

Version is tracked in:
- `pattern-builder.php` (plugin header)
- `package.json`
- `readme.txt`

Use `npm run version-bump` to bump all at once.
