# CLAUDE.md

This file provides guidance to Claude Code and other AI coding agents when working with code in this repository.

## Project Overview

**Pattern Builder** is a WordPress plugin developed by [Twenty Bellows](https://twentybellows.com). It allows WordPress users to create, edit, organize, and manage block patterns directly in the admin interface — unifying theme patterns (PHP files) and user-created patterns (custom post type) in a single, intuitive UI with visual editing, code editing, live preview, and export capabilities.

- **Version:** 1.0.4
- **Repository:** https://github.com/twenty-bellows/pattern-builder
- **Issue Tracker:** GitHub Issues — https://github.com/twenty-bellows/pattern-builder/issues
- **Plugin URI:** https://www.twentybellows.com/pattern-builder/
- **License:** GPL-2.0-or-later
- **WordPress Requires:** 6.6+
- **PHP Requires:** 7.2+

## Development Environment

### Prerequisites
- Node.js (v18+ recommended)
- PHP 7.2+ with Composer
- Docker (for `wp-env` local WordPress environment and PHP integration tests)

### Environment Limitations
> ⚠️ **No Docker = No wp-env.** The commands `npm run start`, `npm run stop`, `npm run clean`, `npm run test:php`, and `npm run test:php:watch` all require Docker via `@wordpress/env`. If Docker is unavailable, skip those commands.
>
> **What still works without Docker:** `npm run build`, `npm run watch`, `npm run test:unit`, `npm run lint:js`, `npm run lint:css`, `composer lint`, `composer test` (direct PHPUnit, if WordPress test suite is bootstrapped).

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

## Architecture Overview

### Plugin Structure
The plugin follows a component-based OOP architecture with clear separation of concerns:

1. **Main Entry Point**: `pattern-builder.php` initializes the plugin
2. **Core Class**: `Pattern_Builder` (singleton in `includes/class-pattern-builder.php`) bootstraps all plugin components
3. **Component Classes** (`includes/`):
   - `Pattern_Builder_API` - REST API endpoints under `/pattern-builder/v1/`
   - `Pattern_Builder_Admin` - Admin UI under Appearance → Pattern Builder
   - `Pattern_Builder_Editor` - Block editor integration
   - `Pattern_Builder_Post_Type` - Custom post type for pattern storage
   - `Pattern_Builder_Security` - Security/nonce helpers
   - `Pattern_Builder_Localization` - i18n support

### Frontend Architecture
- **Build System**: Webpack via `@wordpress/scripts` with two entry points:
  - `src/PatternBuilder_EditorTools.js` - Editor-specific functionality (Gutenberg sidebar panels)
  - `src/PatternBuilder_Admin.js` - Admin interface (Appearance → Pattern Builder page)
- **React Components** in `src/components/`:
  - `PatternBrowserPanel` - Main pattern browsing interface
  - `PatternCreatePanel` - Pattern creation flow
  - `PatternPreview` - Pattern preview rendering
  - `BlockBindingsPanel` - Block bindings configuration panel
  - `PatternAssociationsPanel`, `PatternSyncedStatusPanel`, `PatternPanelAdditions`, `PatternSourcePanel` - Editor sidebar panels
  - `EditorSidePanel` - Editor sidebar container
  - `AdminLandingPage` - Main admin page component
  - `PatternList` - Pattern list/grid view
  - `PatternBuilderConfiguration` - Plugin settings UI
- **State Management**: WordPress data stores via `src/utils/store.js`

### Pattern Handling
- Supports both **theme patterns** (PHP files in `patterns/`) and **user patterns** (custom post type `pattern_builder`)
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
