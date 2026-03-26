# Pattern Builder — Architecture Deep Dive

> **Author:** Kedalion (architectural review)  
> **Date:** 2026-03-25  
> **Version reviewed:** 1.0.4

---

## Table of Contents

1. [What This Plugin Does](#1-what-this-plugin-does)
2. [Companion Plugin: Synced Patterns for Themes](#2-companion-plugin-synced-patterns-for-themes)
3. [PHP Architecture](#3-php-architecture)
4. [JavaScript Architecture](#4-javascript-architecture)
5. [Data Flow](#5-data-flow)
6. [The REST API Hijacking Strategy](#6-the-rest-api-hijacking-strategy)
7. [Pattern File Format](#7-pattern-file-format)
8. [Slug Encoding](#8-slug-encoding)
9. [Image Import and Export](#9-image-import-and-export)
10. [Localization System](#10-localization-system)
11. [Security Layer](#11-security-layer)
12. [Test Coverage](#12-test-coverage)
13. [Issues and Defects](#13-issues-and-defects)
14. [Architectural Observations](#14-architectural-observations)
15. [Summary Scorecard](#15-summary-scorecard)

---

## 1. What This Plugin Does

Pattern Builder solves a real WordPress problem: theme patterns (stored as `.php` files in `/patterns/`) are not editable in the WordPress editor. WordPress ships them as read-only artefacts. You build them in your IDE, commit them, and that's it.

The plugin bridges the gap by:

- Making theme patterns appear as editable blocks in the Site Editor and Block Editor
- Allowing pattern edits to round-trip back to the PHP file on disk
- Supporting synced theme patterns (which WordPress core historically didn't support for file-based patterns)
- Providing image import (media → theme assets), localization injection, and pattern lifecycle management (create, convert between theme/user, delete)

It does all of this without modifying WordPress core and without requiring custom block types. That's the impressive part. It's also the source of most of the complexity.

---

## 2. Companion Plugin: Synced Patterns for Themes

The companion plugin ([`Twenty-Bellows/synced-patterns-for-themes`](https://github.com/Twenty-Bellows/synced-patterns-for-themes)) is a **read-only predecessor** to Pattern Builder. They share the same conceptual approach (custom post type as DB mirror, REST hijacking, `syncedPatternFilter` JS) but the companion:

- Only handles **synced** patterns (skips unsynced)
- **Blocks editing** — its `handle_hijack_block_update` returns a hard `403` with the message "Synced Theme Patterns cannot be updated in the editor without additional tools." Pattern Builder is those additional tools.
- Has no admin page, no image import, no localization, no custom REST endpoints
- Uses `pb_block` post type (vs `tbell_pattern_block`)
- Deactivates itself when Pattern Builder is active:
  ```php
  if (is_plugin_active('pattern-builder/pattern-builder.php')) {
      return;
  }
  ```
- Uses PSR-2 code style, not WPCS; no security wrapper class
- Its JS (`syncedPatternFilter.js`) is **identical** to Pattern Builder's — same hook, same component

**Relationship:** The companion is the "lightweight standalone" for theme developers who want synced patterns in production without the full editing toolchain. Pattern Builder is the authoring environment that uses the same underlying mechanism. They can coexist in a developer's workflow (companion in production builds, Pattern Builder in dev/staging), and thanks to the early-return guard they won't conflict.

**The companion's code quality is lower.** It has no path validation for file operations, role capability assignment uses the full capability list (13 caps vs Pattern Builder's focused 2), and it lacks the WPCS linting infrastructure.

---

## 3. PHP Architecture

### 3.1 Entry Point and Bootstrap

```
pattern-builder.php
  └── Pattern_Builder::get_instance()  (singleton)
        ├── new Pattern_Builder_API()
        ├── new Pattern_Builder_Admin()
        ├── new Pattern_Builder_Editor()
        └── new Pattern_Builder_Post_Type()
```

All wiring happens in constructors via `add_action`/`add_filter`. No static boot methods, no DI container. Simple and appropriate for a WordPress plugin of this size.

The `require_once` chain is manual (no PSR-4 autoloading). `composer.json` has no `autoload` section — it's dev-only (PHPUnit, PHPCS). This is fine for a plugin; it just means class file locations are load-order-dependent.

### 3.2 Class Responsibilities

| Class | Responsibility |
|-------|---------------|
| `Pattern_Builder` | Singleton orchestrator. Instantiates the four subsystems. |
| `Pattern_Builder_API` | REST route registration, REST hijacking filters, pattern registration on `init`, block-to-pattern conversion. The largest and most complex class. |
| `Pattern_Builder_Admin` | Registers the "Appearance → Pattern Builder" admin menu page and enqueues the admin build. |
| `Pattern_Builder_Editor` | Enqueues the editor build on `enqueue_block_editor_assets`. |
| `Pattern_Builder_Post_Type` | Registers `tbell_pattern_block` CPT, assigns capabilities to roles, handles front-end rendering of `tbell_pattern_block` refs, and adds `content` attribute to `core/pattern` block type. |
| `Pattern_Builder_Controller` | Business logic: file I/O, DB upserts, image import/export, block formatter, slug encoding, pattern file metadata builder. The "model" layer. |
| `Abstract_Pattern` | Value object. PHP-side mirror of the JS `AbstractPattern` class. Factory methods: `from_file()`, `from_post()`, `from_registry()`. |
| `Pattern_Builder_Localization` | Static utility class. Transforms parsed block arrays to inject PHP localization calls around translatable strings. |
| `Pattern_Builder_Security` | Static utility class. Path validation, WP Filesystem wrappers for safe file write/delete/move. |

### 3.3 The `tbell_pattern_block` Custom Post Type

This CPT is the database mirror for theme pattern files. Key properties:

- `public: true` — **problematic** (see Issues §13.5)
- `show_in_rest: true`, `rest_base: 'tbell_pattern_blocks'`
- `supports: ['title', 'editor', 'revisions']`
- `capability_type: 'tbell_pattern_block'`, `map_meta_cap: true`
- Capabilities assigned to `administrator` and `editor` roles on every `init`

**Purpose:** When the pattern registry and the editor need a post ID to reference a synced theme pattern as a `wp:block {"ref": N}`, this CPT provides that ID. Without it, WordPress has no mechanism to give a file-based pattern a database identity.

**Meta fields** (all `show_in_rest: true`, all stored as comma-delimited strings despite being logically arrays):

| Meta Key | Purpose |
|----------|---------|
| `wp_pattern_sync_status` | `'unsynced'` or absent (synced) |
| `wp_pattern_block_types` | Comma-delimited block type slugs |
| `wp_pattern_template_types` | Comma-delimited template type slugs |
| `wp_pattern_post_types` | Comma-delimited post type slugs |
| `wp_pattern_inserter` | `'no'` or absent (visible) |

Note: `wp_pattern_keywords` is registered as REST meta (`type: 'string', single: true`) matching the comma-separated storage used by the controller.

### 3.4 Pattern Registration Flow (on `init`, priority 9)

```
glob(stylesheet_directory/patterns/*.php)
  → for each file:
      Abstract_Pattern::from_file($file)
        → get_file_data()  (parse PHP header comments)
        → ob_start() + include()  (execute PHP to get rendered content)
      create_tbell_pattern_block_post_for_pattern($pattern)
        → get_page_by_path() to find existing post
        → wp_insert_post() or wp_update_post()
        → wp_set_object_terms() for categories
      unregister if already registered
      register with inserter: false
        → synced: content = <!-- wp:block {"ref": POST_ID} /-->
        → unsynced: content = rendered PHP output
```

This runs on **every page load**. Every theme pattern means at least one `get_page_by_path()` query and a conditional upsert. For themes with 10+ patterns, this adds measurable database load on every request, not just on admin pages.

---

## 4. JavaScript Architecture

### 4.1 Build System

Two entry points via `webpack.config.js` (extends `@wordpress/scripts` default):

| Build | Output | Purpose |
|-------|--------|---------|
| `PatternBuilder_Admin.js` | `build/PatternBuilder_Admin.{js,css}` | Admin page UI |
| `PatternBuilder_EditorTools.js` | `build/PatternBuilder_EditorTools.{js,css}` | Block/site editor integration |

Asset dependencies are auto-generated by `@wordpress/scripts` as `.asset.php` files and used in `wp_enqueue_script()`.

### 4.2 Admin Page

The admin page (Appearance → Pattern Builder) is rendered as a **plain PHP page** — no React, no JS build artifact. `Pattern_Builder_Admin` registers the menu entry and renders a simple `<div class="wrap">` with a title, a description paragraph, and a list of help links pointing to `twentybellows.com/pattern-builder-help`.

There is no admin JS entry point. The admin page requires no asset enqueue.

### 4.3 Editor Build (`PatternBuilder_EditorTools.js`)

Three plugins registered:

```
registerPlugin('pattern-builder-editor-side-panel', EditorSidePanel)
registerPlugin('pattern-builder-pattern-panel-additions', PatternPanelAdditionsPlugin)
registerPlugin('pattern-builder-save-monitor', PatternSaveMonitor)
```

**`EditorSidePanel`** — A `PluginSidebar` with a `Navigator` (multi-screen router):
- `/` → category list + "Create Pattern" + "Configuration" buttons
- `/create` → `PatternCreatePanel` (create new pattern via POST to `/wp/v2/blocks`)
- `/browse/:category` → `PatternBrowserPanel` → `PatternList` → `PatternPreview`
- `/configuration` → `PatternBuilderConfiguration`

Pattern data is fetched once on mount via `fetchAllPatterns()` (direct `apiFetch`, not the Redux store). Categories are derived via `useMemo`. No refresh mechanism after mount.

**`PatternPanelAdditionsPlugin`** — Adds three `PluginDocumentSettingPanel` panels when editing a `wp_block` post:
- `PatternSourcePanel` — theme/user toggle, dispatches to `core` store
- `PatternSyncedStatusPanel` — synced/unsynced toggle, dispatches to `core` store
- `PatternAssociationsPanel` — block types, post types, template types, inserter visibility

**`PatternSaveMonitor`** — Invisible component. Uses `apiFetch.use()` to register middleware that appends `patternBuilderLocalize=true` and/or `patternBuilderImportImages=false` query params to PUT/POST requests targeting pattern endpoints, based on `localStorage` settings.

**`syncedPatternFilter`** — Hooks into `editor.BlockEdit`:
```js
addFilter('editor.BlockEdit', 'pattern-builder/pattern-edit', syncedPatternFilter);
```
Intercepts `core/pattern` blocks that have both `slug` and `content`. If the pattern's content resolves to a single `core/block` (a reusable block reference), renders `SyncedPatternRenderer` instead. `SyncedPatternRenderer` uses `queueMicrotask` + `registry.batch()` to replace the pattern block with cloned inner blocks, passing `content` as block overrides.

### 4.4 State Management

There is no custom Redux store in the current build. `store.js` was removed — it imported `deletePattern`, `fetchEditorConfiguration`, and `savePattern` from `resolvers.js`, none of which existed. The store was scaffolding for an admin app that was never built.

Pattern data in the editor build is managed via direct `apiFetch` calls (in `EditorSidePanel`) and by dispatching to the WordPress core `'core'` store (in `PatternPanelAdditionsPlugin`). No custom `'pattern-builder'` store exists.

### 4.5 `AbstractPattern` (JS)

Mirror of the PHP `Abstract_Pattern`. Key addition: a `getBlocks()` method that lazily parses `content` via `@wordpress/blocks`'s `parse()` and caches the result in `_blocks`. Used by `PatternPreview` to render a `BlockPreview`.

---

## 5. Data Flow

### 5.1 Normal Edit Flow

```
User opens Site Editor
  → editor loads /wp/v2/blocks (GET)
      → inject_theme_patterns filter appends tbell_pattern_block posts
          masquerading as wp_block posts
  → PatternPanelAdditions shows source/sync/association panels

User edits pattern content, clicks Save
  → editor sends PUT /wp/v2/blocks/{id}
  → WordPress REST API dispatches to rest_pre_dispatch filters first
  → handle_hijack_block_update intercepts (pre-dispatch, runs BEFORE the real handler)
      → identifies post as tbell_pattern_block
      → parses updated_pattern from body
      → optionally: import images (downloads to theme/assets/images/)
      → optionally: localize (inject PHP i18n calls)
      → update_theme_pattern_file() → writes PHP file via WP Filesystem
      → wp_update_post() → syncs DB post
      → returns formatted response (post appearing as wp_block)
  → real handler is skipped (pre_dispatch returned non-null)

Page renders with pattern
  → content is <!-- wp:pattern {"slug":"theme/pattern"} /-->
  → filter_pattern_block_attributes (pre_render_block) intercepts
  → reconstructs <!-- wp:block {"ref":POST_ID, ...attrs...} /-->
  → do_blocks() recurses
  → render_tbell_pattern_blocks (render_block) intercepts core/block with tbell_pattern_block ref
  → renders post_content via do_blocks()
```

### 5.2 Convert Theme Pattern → User Pattern

```
User changes Source from "Theme" to "User" in sidebar panel
  → dispatches editEntityRecord to core store
  → core store sends PUT /wp/v2/blocks/{id} with {source: "user"}
  → handle_hijack_block_update intercepts
  → calls update_user_pattern()
      → export_pattern_image_assets() (move theme assets → media library)
      → wp_insert_post() as wp_block (new post)
      → delete tbell_pattern_block post
      → delete PHP pattern file
```

### 5.3 Convert User Pattern → Theme Pattern

```
User changes Source from "User" to "Theme"
  → PUT /wp/v2/blocks/{id} with {source: "theme"}
  → handle_hijack_block_update detects wp_block post + source=theme
  → sets convert_user_pattern_to_theme_pattern = true
  → calls update_theme_pattern()
      → prepends theme slug to pattern name: theme-slug/pattern-name
      → writes PHP file
      → wp_update_post() as tbell_pattern_block (changes post_type)
```

---

## 6. The REST API Hijacking Strategy

This is the architectural heart of the plugin. WordPress doesn't provide an extension point for "I want to make file-based patterns editable as if they were `wp_block` posts." The plugin achieves this by filtering the REST API at three points:

| Filter | Hook | Purpose |
|--------|------|---------|
| `rest_pre_dispatch` | 2× | Intercept PUT (update) and DELETE before the real handler runs |
| `rest_request_after_callbacks` | 1× | Intercept GET responses to inject theme patterns |
| `rest_request_before_callbacks` | 1× | Intercept PUT/POST to convert `wp:block` refs to `wp:pattern` |

**Why `rest_pre_dispatch` for mutations?** This hook runs _before_ the actual REST controller. By returning a response from `rest_pre_dispatch`, the real handler is never invoked. For updates/deletes of `tbell_pattern_block` posts, this is essential because the real `WP_REST_Blocks_Controller` doesn't know about this custom post type and would either fail or corrupt data.

**Why `rest_request_after_callbacks` for reads?** This hook runs _after_ the real handler. The plugin can get the existing response data and append theme pattern entries to it. Injection is additive — it doesn't replace the real response.

**The `format_tbell_pattern_block_response()` trick:** To format a `tbell_pattern_block` post as a proper `wp_block` REST response, the plugin temporarily sets `post->post_type = 'wp_block'`, then passes it to `WP_REST_Blocks_Controller::prepare_item_for_response()`. This is a dirty hack — mutating the post object in memory — but it produces a correctly formatted response without reimplementing the entire REST serialization logic.

---

## 7. Pattern File Format

Pattern files are PHP files with a PHPDoc-style header block. This is standard WordPress pattern file format, with one addition: the `Synced` header.

```php
<?php
/**
 * Title: My Pattern
 * Slug: my-theme/my-pattern
 * Description: Short description.
 * Categories: text, featured
 * Keywords: hero, landing
 * Block Types: core/post-content
 * Post Types: page
 * Template Types: front-page
 * Inserter: no
 * Synced: yes
 */
?>
<!-- wp:heading --><h2>My Pattern</h2><!-- /wp:heading -->
```

The PHP body is executed with `ob_start()` + `include()`, so it can contain `<?php echo get_stylesheet_directory_uri(); ?>` and similar. After localization processing, the body will contain PHP i18n calls.

The `build_pattern_file_metadata()` method reconstructs this header from an `Abstract_Pattern` object when writing files. There is a TODO in `get_patterns()` about slugs not surviving the round-trip — this is acknowledged technical debt.

---

## 8. Slug Encoding

WordPress `post_name` columns don't support `/`. Theme pattern slugs are namespaced: `theme-slug/pattern-name`. The plugin encodes this as `theme-slug-x-x-pattern-name` for DB storage.

```php
str_replace('/', '-x-x-', $slug)   // encode for DB
str_replace('-x-x-', '/', $slug)   // decode from DB
```

This is a sentinel-based encoding with no escaping. A pattern whose base name legitimately contains `-x-x-` will be silently corrupted on decode. In practice, this is unlikely, but it's not safe in principle. A URL-safe base64 or hex encoding of the full slug would be more correct.

---

## 9. Image Import and Export

### Import (user → theme assets)

When saving a theme pattern, `import_pattern_image_assets()`:

1. Finds URLs matching `src="HOME_URL/..."` and `"url":"HOME_URL/..."` via regex
2. Downloads them with `download_url()`
3. Saves to `{stylesheet_directory}/assets/images/{filename}`
4. Replaces URLs with `<?php echo get_stylesheet_directory_uri() . '/assets/images/filename'; ?>`

There's a Docker workaround: if download fails on `localhost:PORT`, it retries on `localhost:80`. This is hardcoded dev-environment logic in production code.

### Export (theme assets → media library)

When converting a theme pattern to a user pattern, `export_pattern_image_assets()`:

1. Finds URLs matching `src="HOME_URL/..."` and `"url":"HOME_URL/..."`
2. Tries to `copy()` from local file path first, falls back to `download_url()`
3. Uploads to media library via `wp_insert_attachment()`
4. Replaces PHP template tags with media library URLs

**Issues:** Both methods only scan two URL patterns. Block JSON can encode image URLs in many other attributes (`href`, `backgroundUrl`, `url` inside deeply nested JSON, etc.). The regex approach is inherently incomplete for block markup.

---

## 10. Localization System

`Pattern_Builder_Localization::localize_pattern_content()` wraps translatable strings in PHP i18n calls:

```
parse_blocks(content)
  → localize_blocks() [recursive]
      → switch on blockName
          → core/paragraph, core/heading, etc. → localize_text_block()
          → core/button → localize_button_block()
          → core/image → localize_image_block()
          → ...
  → serialize_blocks()
  → str_replace('\u003c', '<', ...) + str_replace('\u003e', '>', ...)
```

**The `\u003c` hack:** `serialize_blocks()` JSON-encodes block attributes. PHP tags (`<` `>`) embedded in attribute values get Unicode-escaped to `\u003c` and `\u003e`. The post-processing replaces these back to literal `<>`. This works correctly because the block comment format treats attribute values as JSON inside `<!-- ... -->` comments — when the PHP file is executed, the PHP runs first and produces the translated string, then WordPress parses the result as block markup.

**Text domain:** Uses `get_stylesheet()` (the active theme's slug). This is correct for the pattern's context but means patterns can't be localized to a different text domain.

**Coverage:** The localization handles ~15 block types. Many blocks with translatable content are not handled (e.g., `core/navigation-link`, `core/site-title`, `core/post-title`, custom blocks). The test file explicitly marks `core/navigation-link` as intentionally not localized. This is acceptable as opt-in tooling, but should be documented clearly.

---

## 11. Security Layer

`Pattern_Builder_Security` is a dedicated static utility class for file operations. This is a good architectural decision — it centralises path validation instead of scattering it across the codebase.

### Path Validation

```php
validate_file_path($path, $allowed_dirs)
  → for existing files: realpath() → check prefix against allowed_dirs
  → for new files: wp_normalize_path() → check prefix
  → also checks for literal '../' or '..\' in normalized path
```

The approach is correct for existing files (realpath resolves symlinks and traversals). For non-existing files (new patterns being written), it uses string prefix matching on the normalized path. A path like `theme_dir/../../evil` after `wp_normalize_path()` may still start with the theme directory string if wp_normalize_path doesn't resolve `..`. This is a potential but impractical bypass — pattern writes go to `{stylesheet_directory}/patterns/` with filenames from `sanitize_file_name(basename($pattern->name))`, which removes directory separators.

### Capability Check Duplication

`handle_hijack_block_update` checks `current_user_can('edit_tbell_pattern_blocks')` inline. The `write_permission_callback` (used for the custom `/pattern-builder/v1/` routes) also manually calls `wp_verify_nonce()`. For the hijacked `/wp/v2/blocks` routes, WordPress's built-in nonce validation already applies — the explicit nonce check in custom routes is redundant. This inconsistency is harmless but confusing.

---

## 12. Test Coverage

### PHP Tests

**`test-pattern-builder-api.php`** (integration tests, WP_UnitTestCase):

Good coverage of the full REST lifecycle:
- GET `/wp/v2/blocks` with synced and unsynced theme patterns
- GET `/wp/v2/block-patterns/patterns` (core pattern registry)
- GET `/pattern-builder/v1/patterns`
- PUT to update content, title, description
- PUT to convert theme → user pattern (verifies file deletion, DB post type change)
- PUT to convert user → theme pattern
- PUT to convert back and forth
- PUT to update restrictions (block types, post types, template types)
- DELETE
- Image-containing pattern conversion

The test setup correctly redirects `stylesheet_directory` to a temp directory and cleans up after each test. A good, realistic integration test harness.

**`test-pattern-localization.php`** (unit tests, WP_UnitTestCase):

Thorough coverage of the localization engine:
- All major block types
- Single quotes, empty content, non-translatable blocks
- Details block duplicate closing tag regression test
- Search block partial/full attribute localization
- Query pagination, post excerpt

### JS Tests

**`tests/unit/util.test.js`**:
```js
it('should have unit tests', async () => {
    expect(true).toBe(true);
});
```

This is a placeholder. Zero actual JS test coverage. The `formatBlockMarkup` function (duplicated in JS and PHP) has no equivalence tests. The `AbstractPattern` class, `store.js`, `resolvers.js`, `syncedPatternFilter` — none are tested.

---

## 13. Issues and Defects

### 13.1 ✅ Fixed: `array_find()` requires PHP 8.4

`Pattern_Builder_Controller` uses `array_find()` in two places:

```php
// get_pattern_filepath()
$pattern = array_find($patterns, function($p) use ($pattern) {
    return $p->name === $pattern->name;
});

// remap_patterns()
$pattern = array_find($all_patterns, function($p) use ($pattern_slug) {
    return sanitize_title($p->name) === sanitize_title($pattern_slug);
});
```

`array_find()` was introduced in **PHP 8.4**. The plugin declares `Requires PHP: 7.2`. On any PHP version below 8.4, these calls will cause a fatal error. **Both sites and `get_pattern_filepath()` is a central function called on every pattern save.**

**Fix applied:** Replaced with `array_filter()` + `reset()`. `Requires PHP` updated from 7.2 → 7.4 (the codebase was already using 7.4 features; 7.2 was inaccurate).

### 13.2 ✅ Fixed: `store.js` imports non-existent functions

```js
import {
    deletePattern,
    fetchEditorConfiguration,
    savePattern,      // ← undefined
    fetchAllPatterns,
} from './resolvers';
```

`resolvers.js` only exports `fetchAllPatterns`. The three missing exports mean the store's thunk actions (`deleteActivePattern`, `fetchEditorConfiguration`, `saveActivePattern`) would throw `TypeError: deletePattern is not a function` if ever called.

**Fix applied:** `store.js` was deleted entirely. The admin page was also stripped to plain PHP, removing the need for an admin JS build altogether. See §4.2.

### 13.3 ✅ Fixed: Capabilities assigned on every `init`

```php
foreach ($roles as $role_name) {
    $role = get_role($role_name);
    if ($role) {
        foreach ($capabilities as $capability) {
            $role->add_cap($capability);  // ← DB write if not already set
        }
    }
}
```

`WP_Role::add_cap()` calls `update_option()` if the capability isn't already stored. On the first run this is a DB write per capability per role. On subsequent runs it's a read+no-op. But checking it on every `init` (every request) is wasteful. **Fix applied:** Moved to `register_activation_hook`. A corresponding `register_deactivation_hook` removes the custom capabilities on deactivation.

### 13.4 🟡 Open: DB upsert on every page load

`register_patterns()` calls `create_tbell_pattern_block_post_for_pattern()` for every theme pattern file on every request. This executes at minimum:
- 1× `get_page_by_path()` (DB query) per pattern
- 1× `wp_insert_post()` or `wp_update_post()` per pattern if data changed
- 1× `wp_set_object_terms()` per pattern

For a theme with 20 patterns, this is 40–60+ queries on every page load, every admin page, every REST request. Pattern data should be cached (transient) and invalidated only when pattern files change (using `filemtime()`).

### 13.5 ✅ Fixed: `tbell_pattern_block` is `public: true`

```php
$args = array(
    'public' => true,   // ← exposes posts at frontend URLs
    ...
);
```

**Fix applied:** Changed to `public: false` with explicit `show_in_rest: true` and `show_ui: true` preserved. Pattern posts no longer have public frontend URLs.

### 13.6 ✅ Fixed: Rules of Hooks violation in `syncedPatternFilter`

```js
export const syncedPatternFilter = (BlockEdit) => (props) => {
    const { name, attributes } = props;

    if (name === 'core/pattern' && attributes.slug && attributes.content) {
        const selectedPattern = useSelect(  // ← Hook called inside conditional
            ...
        );
    }
    return <BlockEdit {...props} />;
};
```

**Fix applied:** `useSelect` moved to the top of the component, called unconditionally. The conditional logic (whether the pattern matches) is now evaluated inside the selector callback, with an updated dependency array of `[name, attributes.slug]`.

### 13.7 ✅ Fixed: Admin page was an empty React shell

`PatternBuilder_Admin.js` rendered a welcome page with documentation links — a JS build artifact for what was purely a link list. **Fix applied:** Admin page converted to plain PHP rendering. The JS entry point, `AdminLandingPage.js`, `AdminLandingPage.scss`, and the webpack admin entry are all removed.

### 13.8 🟠 Open: Slug encoding sentinel `-x-x-` is not escaped

Any pattern slug containing `-x-x-` literally (e.g., `theme/pattern-x-x-name`) will be encoded to `theme-x-x-pattern-x-x-x-x-name` and decoded incorrectly as `theme/pattern/x/x/name`. A collision is unlikely in practice but should be addressed. Consider `str_replace('/', '__SLASH__', $slug)` with a more descriptive (and less likely to collide) sentinel, or use `base64url_encode`.

### 13.9 🟠 Open: `get_block_patterns_from_theme_files()` ignores parent theme

```php
$pattern_files = glob(get_stylesheet_directory() . '/patterns/*.php');
```

Only child theme (stylesheet) patterns are indexed. If a parent theme provides patterns, they won't appear in the Pattern Builder. Both the companion plugin and Pattern Builder have this limitation.

### 13.10 🟠 Open: No refresh after initial pattern fetch in EditorSidePanel

```js
useEffect(() => {
    fetchAllPatterns().then(setAllPatterns).catch(console.error);
}, []);
```

Patterns are fetched once on sidebar open. If a pattern is created or updated elsewhere in the editor, the sidebar list won't update. This creates a stale state problem in common multi-step workflows (create a pattern, go back to browse).

### 13.11 🟠 Low: `formatBlockMarkup` is duplicated

The block markup formatter exists in both `src/utils/formatters.js` and `includes/class-pattern-builder-controller.php`. They're meant to produce identical output but there are subtle differences (the JS version has a `formatBlockCommentJSON` function that doesn't exist in PHP; the PHP version has an `add_new_lines_to_block_markup` that eliminates blank lines differently). Any divergence will cause a diff on every save cycle if a pattern is round-tripped through both formatters.

### 13.12 🟠 Low: Settings persisted in `localStorage` instead of user meta

Localize/import-images settings are stored in `localStorage`. They're per-browser, not per-user. On a multisite or shared device, settings won't carry over. Appropriate for a power-user dev tool, but should be documented.

### 13.13 🟠 Low: `PatternSaveMonitor` middleware cannot be unregistered

```js
apiFetch.use(middleware);
// Note: apiFetch doesn't have a direct way to remove middleware
```

In development with HMR, `useEffect` will re-run on every hot reload, accumulating middleware instances. Each save will trigger `n` middleware calls where `n` is the HMR cycle count. The params will be duplicated in the URL (e.g., `?patternBuilderLocalize=true&patternBuilderLocalize=true&...`). In production this is a non-issue but it creates dev-time confusion.

### 13.14 🟠 Low: `PatternCreatePanel` creates via `/wp/v2/blocks` but doesn't handle the `source: 'theme'` conversion

```js
const createPatternCall = (pattern) => {
    return apiFetch({
        path: '/wp/v2/blocks',
        method: 'POST',
        body: JSON.stringify(pattern),
        ...
    });
};
```

When `source: 'theme'` is set in the create form, this POST goes to the standard core blocks endpoint. But `handle_hijack_block_update` only fires on PUT, not POST. The `handle_block_to_pattern_conversion` filter fires on POST but only does block reference rewriting — it doesn't handle the `source` conversion.

**Result:** Creating a pattern with Source: "Theme" in `PatternCreatePanel` creates a `wp_block` (user pattern), not a `tbell_pattern_block` (theme pattern). The source toggle is silently ignored on creation. The user would need to create the pattern, then change its source in the panel afterward.

### 13.15 🟠 Low: `handle_hijack_block_update` reads `wp_pattern_inserter` inconsistently

```php
if (isset($updated_pattern['wp_pattern_inserter'])) {
    $pattern->inserter = $updated_pattern['wp_pattern_inserter'] === 'no' ? false : true;
}
```

But in `PatternAssociationsPanel`, the value dispatched is `'yes'` or `'no'`:
```js
changePatternInserter: value ? 'yes' : 'no'
```

And in the meta registration, the stored value is `'no'` (when hidden). The server-side check `=== 'no' ? false : true` would interpret `'yes'` as truthy (not `'no'`), so `'yes'` → `inserter: true`. That happens to work, but storing `'yes'` and checking `!== 'no'` is the implicit logic — it's fragile because any non-`'no'` string (including a bug) would be treated as "visible."

---

## 14. Architectural Observations

### 14.1 The Core Tension

The plugin's fundamental challenge is that WordPress's block pattern system has two distinct identities for patterns:

1. **File-based patterns** — registered via PHP headers, rendered on the fly
2. **Database patterns** (`wp_block`) — stored as posts, editable in the editor

These two systems have completely different APIs, lifecycles, and capabilities. Pattern Builder bridges them by maintaining a **DB mirror** (`tbell_pattern_block`) that is kept in sync with the file system. This dual-write architecture is the right approach — the alternatives (making WordPress edit files directly, or abandoning the file format) are worse. But it creates the complexity of keeping two representations in sync, which requires careful handling on every create/update/delete/rename operation.

### 14.2 The Hijacking Strategy Is the Right Trade-Off

REST hijacking is messy but pragmatic. The alternative — implementing a completely custom pattern editor outside of the standard block editor's pattern context — would require far more code and wouldn't integrate seamlessly with the existing editor pattern panels. The approach works within WordPress's architecture rather than fighting it.

The risk is WordPress core changes breaking the hooks. The specific hooks used (`rest_pre_dispatch`, `rest_request_after_callbacks`, `rest_request_before_callbacks`) are stable and have been part of WP for years. The `format_tbell_pattern_block_response()` technique (temporarily changing `post_type` in memory) is the most fragile piece and could break if `WP_REST_Blocks_Controller` performs additional type checking in a future version.

### 14.3 The Admin Page Is a Plain PHP Page

The admin page (Appearance → Pattern Builder) is a simple PHP-rendered page: a title, a description, and a list of documentation links. No React, no JS build artifact.

A Redux store (`store.js`) and an admin JS entry point existed previously as scaffolding for an admin pattern manager that was never built. Both were removed. All pattern management functionality lives in the editor sidebar (Site Editor / Block Editor).

### 14.4 The Companion Plugin Relationship Is Clean

The mutual exclusion guard (`is_plugin_active()` check) and the architectural split (companion = read-only, Pattern Builder = read-write) is well-designed. The companion plugin is appropriate for production deployments where you want synced theme patterns but don't want the full editing toolchain exposed to editors.

The code quality gap between the companion and Pattern Builder is noticeable (WPCS compliance, security wrapper, namespace usage). The companion could benefit from the same security hardening, even if it's read-only.

### 14.5 PHP/JS Model Duplication

The `Abstract_Pattern` class exists in both PHP (`includes/class-pattern-builder-abstract-pattern.php`) and JS (`src/objects/AbstractPattern.js`). The `formatBlockMarkup` function exists in both PHP and JS. This duplication is inherent to WordPress plugin architecture (server/client split), but it means bugs can exist in one language but not the other. The PHP localization tests cover the pattern model thoroughly; JS has essentially no tests.

---

## 15. Summary Scorecard

| Area | Score | Notes |
|------|-------|-------|
| Core concept | ✅ Solid | The DB-mirror + REST-hijack strategy is the right approach |
| PHP architecture | ✅ Good | Clean class separation, appropriate singleton |
| PHP security | ✅ Good | Security class is well-thought-out |
| PHP test coverage | ✅ Good | Integration tests cover the happy path and key edge cases |
| PHP code quality | ✅ Good | `array_find()` fixed; `public: false` CPT; activation hook for caps |
| JS architecture | ✅ Good | Dead store removed; hooks violation fixed; admin JS eliminated |
| JS test coverage | ❌ None | Unit tests are a placeholder |
| Performance | ⚠️ Open | DB upsert on every page load (TWE-369); activation hook caps fixed |
| Admin UI | ✅ Simplified | Plain PHP page; no JS overhead |
| Companion plugin | ✅ Good | Clean relationship; appropriate read-only subset |

### Previously Fixed (see individual entries in §13)

1. ✅ `array_find()` replaced with PHP 7.4-compatible alternative — `Requires PHP` updated to 7.4
2. ✅ Dead `store.js` and admin JS build removed — admin page is now plain PHP
3. ✅ Rules of Hooks violation in `syncedPatternFilter` fixed
4. ✅ Capability assignment moved to `register_activation_hook`
5. ✅ `tbell_pattern_block` CPT changed to `public: false`
6. ✅ `wp_pattern_keywords` meta registered for REST

### Open Items (tracked in Linear — Pattern Builder project)

- **TWE-369** (medium): Cache pattern registration — transients keyed by `filemtime()` hashes
- **TWE-370** (medium): Fix `PatternCreatePanel` to handle `source: 'theme'` on creation
- **TWE-371** (low): Fix slug encoding sentinel collision risk
- **TWE-373** (low): Add parent theme pattern support
- **TWE-374** (low): Refresh sidebar pattern list after create/update
