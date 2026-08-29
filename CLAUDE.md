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

**Editing surfaces — always the WordPress editor, never a custom one.** The post editor opens any pattern in place via `onNavigateToEntityRecord` (both `wp_block` and `pb_pattern`). User patterns are otherwise edited by the Site Editor natively (its `/wp_block/:postId` route) — in place from within the Site Editor, deep-linked from everywhere else. Theme patterns cannot open in the Site Editor's canvas (core hard-codes the entity types its canvas binds and keeps route registration private), so from the Site Editor and the browse screen they open Appearance → Pattern Builder's edit mode (`&pattern={id}`), which boots core's own edit-post editor (`wp.editPost.initializeEditor`) against the `pb_pattern` entity — the genuine post-editor chrome, with a validated `back` URL whose Back button returns to the originating screen. The Appearance page's browse mode is a Site-Editor-style library: a category rail with counts, a grid of uniform 1:1 pattern cards, and a details sidebar for the selected pattern carrying the same panels the editor shows (staged on the entity, persisted by its Save button; Edit opens the pattern's editor).

**Synced patterns via the `core/pattern` content runtime.** Synced theme patterns (`Synced: yes` file header) work exactly like Synced Patterns for Themes 2.0: `core/pattern` gets a `content` attribute + `pattern/overrides` context and a render callback that attaches the pattern's blocks as inner blocks (`Pattern_Block`); `Pattern_Resolver` composes editor-facing content; a synthesized `--synced-instance` companion entry puts a reference in the inserter. Inserted copies are plain `<!-- wp:pattern {"slug":…,"content":{…}} /-->` — no post ID anywhere.

**Companion plugin coexistence.** The runtime classes (`Pattern_Block`, `Pattern_Resolver`, `Block_Markup`, `Inner_HTML_Processor`, `Synced_Patterns`, `Editor_Support`, and the `src/runtime/` JS) are vendored from [`synced-patterns-for-themes`](https://github.com/Twenty-Bellows/synced-patterns-for-themes) and must stay logic-identical to it. Pattern Builder boots on `plugins_loaded` and registers its copy of the runtime ONLY when the companion is not active (`class_exists` gate in `Pattern_Builder`); when both plugins run, the companion owns the runtime and Pattern Builder adds the editing/management layer on top. Both read the same `Synced: yes` header, and Pattern Builder flushes the companion's caches after file writes.

**Migration from 1.x.** `Pattern_Builder_Migration` runs once on upgrade: it rewrites `wp:block` refs pointing at the old `tbell_pattern_block` mirror posts to `wp:pattern` slugs (in post content and theme files), then deletes the mirror posts and the old capabilities.

**Webpack entries:** `PatternBuilder_EditorTools.js` (management: sidebar, panels, save monitor — all block-editor screens), `PatternBuilder_Runtime.js` (the vendored content runtime — enqueued only when this plugin owns it), `PatternBuilder_Admin.js` (the browse grid, plus the edit-mode boot of core's edit-post editor).

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
