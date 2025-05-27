# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
- `npm run test:unit` - Run JavaScript unit tests
- `npm run test:unit:watch` - Run JavaScript tests in watch mode
- `npm run test:php` - Run PHP unit tests in wp-env environment
- `npm run test:php:watch` - Run PHP tests in watch mode
- `composer test` - Run PHP tests directly
- `composer test:watch` - Run PHP tests with file watching

### Development Environment
- `npm run start` - Start wp-env with xdebug enabled
- `npm run stop` - Stop wp-env
- `npm run clean` - Clean wp-env
- `npm run plugin-test-env` - Start WP Playground for testing
- `npm run plugin-test` - Full build, zip, and test workflow

## Architecture Overview

### Plugin Structure
The plugin follows a component-based OOP architecture with clear separation of concerns:

1. **Main Entry Point**: `pattern-builder.php` initializes the plugin and Freemius integration
2. **Core Class**: `Pattern_Builder` (singleton) bootstraps all plugin components
3. **Component Classes**:
   - `Pattern_Builder_API` - REST API endpoints under `/pattern-builder/v1/`
   - `Pattern_Builder_Admin` - Admin UI under Appearance → Pattern Builder
   - `Pattern_Builder_Editor` - Block editor integration
   - `Pattern_Builder_Post_Type` - Custom post type for pattern storage

### Frontend Architecture
- **Build System**: Webpack via @wordpress/scripts with two entry points:
  - `PatternBuilder_EditorTools.js` - Editor-specific functionality
  - `PatternBuilder_Admin.js` - Admin interface
- **React Components** in `src/components/`:
  - `PatternBrowser` - Main pattern browsing interface
  - `PatternEditor` - Pattern editing functionality
  - `PatternPreview` - Pattern preview rendering
  - `BlockBindingsPanel` & `BlockTypesPanel` - Pattern configuration panels
- **State Management**: Uses WordPress data stores via `src/utils/store.js`

### Pattern Handling
- Supports both theme patterns (PHP files in `patterns/`) and user patterns (custom post type)
- Abstract pattern class (`AbstractPattern.js`) provides unified interface
- Pattern syncing capabilities between theme files and database

### Key Development Patterns
- All PHP classes use WordPress coding standards with proper namespacing
- JavaScript follows WordPress/Gutenberg patterns using @wordpress packages
- REST API authentication handled via WordPress nonce system
- Assets enqueued with proper dependency management using `.asset.php` files