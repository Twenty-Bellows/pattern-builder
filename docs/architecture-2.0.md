# Pattern Builder 2.0 — Architecture

> **Version:** 2.0.0
> Replaces the 1.x design analyzed in [`architecture.md`](architecture.md)
> (DB mirror + REST hijacking — both removed).

## The idea

Theme pattern files are the single source of truth. Nothing is mirrored into
the database, no core REST route is intercepted, and a synced pattern is
referenced by its slug — never by a post ID.

Two problems shaped 1.x, and 2.0 solves each with a mechanism core already
uses for something else:

1. **"The editor can only edit things with a post ID."** False since
   templates: core's `wp_template` is a *registered post type with zero rows*
   whose REST controller serves file-backed entities with string IDs
   (`theme//slug`). Pattern Builder does the same: a rowless `pb_pattern`
   post type whose controller (`Pattern_Builder_REST_Patterns_Controller`)
   serves theme patterns at `/pattern-builder/v1/patterns/{theme}/{name}`,
   reading from and writing to the pattern files. Because the type is
   `show_in_rest`, the block editor auto-creates a matching client-side
   entity from `/wp/v2/types` — undo, dirty tracking, and the save flow all
   come from core's entity layer.

2. **"A synced pattern needs a post to reference."** False since the
   companion plugin's 2.0: `core/pattern` gets a `content` attribute and
   `pattern/overrides` context — the exact shape `core/block` already has —
   and a render callback that attaches the pattern's blocks as inner blocks
   so core's own `core/pattern-overrides` binding source resolves overrides.
   An inserted synced pattern is `<!-- wp:pattern {"slug":"…","content":{…}} /-->`.

## The pieces

### PHP (`includes/`)

| Piece | Job |
|---|---|
| `Pattern_Builder_Entity` | Registers the rowless `pb_pattern` post type (REST namespace `pattern-builder/v1`, base `patterns`). |
| `Pattern_Builder_REST_Patterns_Controller` | String-ID CRUD for theme patterns; the collection also lists user patterns (`wp_block`, numeric IDs) so one request paints the whole library. Creation accepts `fromWpBlock` (user→theme conversion); an update with `source: "user"` converts theme→user. |
| `Pattern_File_Store` | The file pipeline: parent+child theme scanning, header round-trip (Title, Slug, Description, Categories, Keywords, Block Types, Post Types, Template Types, Viewport Width, Inserter, Synced), image import (theme assets) / export (media library), formatting, conversions, cache flushing. |
| `Pattern_Builder_API` | The one non-entity route: `POST /pattern-builder/v1/process-theme` (bulk localize / import-images). |
| `Pattern_Builder_Admin` | Appearance → Pattern Builder: the browse grid, and an edit mode that boots core's edit-post editor with real block-editor settings. |
| `Pattern_Builder_Editor` | Enqueues the management bundle on block-editor screens. |
| `Pattern_Builder_Migration` | One-time 1.x upgrade: rewrites `wp:block {"ref":N}` (mirror-post refs) to `wp:pattern {"slug":…}` in post content and theme files *while the mirror rows still exist as the ID→slug map*, then deletes the rows and the 1.x capabilities. |
| `Pattern_Builder_Security` / `Pattern_Builder_Localization` | Path-validated filesystem helpers; pattern localization. |
| Vendored runtime | `Pattern_Block`, `Pattern_Resolver`, `Block_Markup`, `Inner_HTML_Processor`, `Synced_Patterns`, `Editor_Support` — copied from Synced Patterns for Themes 2.0 and kept logic-identical. |

### JavaScript (`src/`)

Three webpack bundles:

- **`PatternBuilder_EditorTools`** (every block-editor screen): the Pattern
  Builder sidebar (browse / create / configure), the document panels for
  `pb_pattern` and `wp_block` (Source with conversion, Synced Status,
  Metadata, Associations, Bindings), and the save monitor that appends the
  localize / import-images flags to `/pattern-builder/v1/` writes.
- **`PatternBuilder_Runtime`** (only when this plugin owns the runtime): the
  vendored editor modules — `content` attribute declaration, instance
  editing (`SyncedPatternEdit`), client-side expansion, and the
  `core/pattern-overrides` `setValues` amendment (marked with
  `patternHostAmended` so two providers never double-wrap).
- **`PatternBuilder_Admin`** (the plugin's own page): the browse grid
  (`registerCoreBlocks()` runs at boot for its previews), and — when the URL
  carries `&pattern={id}` — the edit-mode boot: `wp.editPost.initializeEditor`
  pointed at the `pb_pattern` entity, plus a `history.replaceState` guard
  (the editor believes it lives at post.php and rewrites the address bar; a
  string id would 404 there) and a `MainDashboardButton` fill whose Back
  button returns to the server-validated `back` URL.

### Editing surfaces

Always the WordPress editor — the plugin ships no editor UI of its own.

- **Post editor:** in-context — the sidebar's Edit button resolves the entity
  first, then swaps it into the canvas via `onNavigateToEntityRecord`
  (`wp_block` and `pb_pattern` alike).
- **User patterns everywhere else:** the Site Editor edits `wp_block`
  natively (`/wp_block/:postId` route) — in place from within the Site
  Editor, deep-linked from the browse grid and any other screen.
- **Theme patterns everywhere else:** the Site Editor's canvas cannot bind a
  `pb_pattern` — core hard-codes the entity types its canvas resolves
  (template, template part, navigation, user pattern, attachment) and its
  route registration is a private API. So the Site Editor and the browse
  grid open Appearance → Pattern Builder's edit mode
  (`themes.php?page=pattern-builder&pattern={id}&back={origin}`), which
  boots core's own edit-post editor — the genuine post-editor chrome — and
  its Back button returns to the originating screen. If core ever opens
  site-editor routes to plugins, this URL is the only thing that changes.

## Coexistence with Synced Patterns for Themes

The pattern runtime is one shared surface. At `plugins_loaded`, Pattern
Builder checks for the companion's classes: if present, the companion owns
the runtime (its filter registered first; both filters also carry an
attribute-presence guard as belt and braces) and Pattern Builder skips its
vendored copy entirely — PHP hooks and JS bundle both. Pattern Builder
always owns the management layer; the companion has no write path, so there
is no overlap. Both plugins read the same `Synced: yes` file header, and
Pattern Builder flushes the companion's slug cache after every file write.

The product story: build with Pattern Builder in development, ship the theme
with Synced Patterns for Themes in production — deactivating Pattern Builder
changes nothing about how the site renders.

## What 1.x behaviors changed

- Theme patterns now appear in the core inserter per their own `Inserter:`
  header (1.x hid all of them and re-listed them as fake `wp_block`s).
- `Viewport Width` round-trips (1.x read it and dropped it on save).
- Parent-theme patterns are included (1.x scanned only the child theme).
- The mirror CPT's silent revisions are gone — files are the history, and
  version control is the developer workflow.
- The unauthenticated delete and edit-context read paths that rode
  `rest_pre_dispatch` no longer exist as a class of bug: there is no
  pre-dispatch interception at all, and every route has a permission
  callback (`edit_posts` to read, `edit_theme_options` to write).
