# Pattern Builder - Copilot Instructions

You are working on **Pattern Builder**, a WordPress plugin by Twenty Bellows that manages block patterns in the WordPress block editor.

## Project Context
- WordPress plugin (GPL-2.0), requires WP 6.6+, PHP 7.2+
- Manages both theme patterns (PHP files) and user patterns (custom post type)
- Frontend: React components using `@wordpress` packages (Gutenberg ecosystem)
- Backend: PHP OOP, singleton pattern, REST API under `/pattern-builder/v1/`
- See `CLAUDE.md` for full architecture, commands, and environment notes

## Key Principles
- Follow **WordPress Coding Standards** for PHP (WPCS 3.x via PHPCS)
- Use `@wordpress` packages for editor integration — don't reinvent Gutenberg primitives
- All state-changing REST endpoints must have **nonce verification** and **capability checks**
- Sanitize inputs on the way in, escape outputs on the way out
- Use `declare(strict_types=1)` in PHP files
- Namespace: `TwentyBellows\PatternBuilder`

## PHP/WordPress Rules
- Use WordPress core functions and APIs when available (`wp_*`, `get_*`, `sanitize_*`, `esc_*`)
- Use `$wpdb->prepare()` for all direct DB queries
- Use WordPress transients API for caching
- Implement hooks (actions/filters) for extensibility — avoid coupling
- Follow WordPress plugin file/class naming: `class-pattern-builder-*.php` → `Pattern_Builder_*`

## JavaScript/React Rules
- Use `@wordpress/data` stores for state — see `src/utils/store.js`
- Use `@wordpress/components` for UI primitives
- Follow Gutenberg component patterns for editor panels and sidebar integration
- Build output goes to `build/` — never edit files there directly

## Testing
- JS unit tests: `npm run test:unit` (no Docker needed)
- PHP integration tests: `npm run test:php` (requires Docker/wp-env)
- Always run `npm run lint:js` and `composer lint` before committing
