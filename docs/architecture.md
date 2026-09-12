# Architecture

How Pattern Builder is built. The agent interface is [`abilities.md`](abilities.md),
the patternbuilderwp.com integration is [`cloud.md`](cloud.md).

## The idea

Theme pattern files are the single source of truth. Nothing is mirrored into the
database, no core REST route is intercepted, and a synced pattern is referenced
by its slug rather than by a post ID.

Two constraints shaped the design, and each is answered with a mechanism core
already uses elsewhere.

**"The editor can only edit things with a post ID."** Not since templates:
`wp_template` is a registered post type with zero rows whose REST controller
serves file-backed entities with string IDs. Pattern Builder does the same — a
rowless `pb_pattern` type whose controller serves theme patterns at
`/pattern-builder/v1/patterns/{theme}/{name}`, reading and writing the pattern
files. The type is `show_in_rest`, so the block editor auto-creates a matching
client-side entity from `/wp/v2/types`; undo, dirty tracking and the save flow
come from core's entity layer.

**"A synced pattern needs a post to reference."** Not since the companion
plugin: `core/pattern` gets a `content` attribute and `pattern/overrides`
context — the shape `core/block` already has — plus a render callback that
attaches the pattern's blocks as inner blocks, so core's own
`core/pattern-overrides` binding source resolves the overrides. An inserted
synced pattern is `<!-- wp:pattern {"slug":"…","content":{…}} /-->`, with no
post ID anywhere.

## Editing surfaces

One editor for every pattern, and it is always the WordPress editor — the
plugin ships no editor UI of its own.

- A post editor already on screen swaps either entity into its canvas through
  `onNavigateToEntityRecord`.
- Everywhere else, including the Site Editor, a pattern opens Appearance →
  Pattern Builder's edit mode (`&pattern={id}`, plus `&type=user` for a
  `wp_block`), which boots core's edit-post editor against the entity with a
  validated `back` URL.

The Site Editor's canvas is never used, even for the user patterns it could
host: theme patterns cannot enter it, because core hard-codes the entity types
its canvas binds and keeps route registration private. Editing both kinds in
the same editor is worth more than the nicer canvas for half of them.

In that editor a pattern is only its blocks. The canvas title field is hidden
by an editor style, because it lives inside the canvas iframe where page styles
never reach; the document tab's post card is hidden by a rule anchored on
`.editor-post-card-panel__title`. Name and description are edited in the
Pattern Metadata panel.

## The browse page

Appearance → Pattern Builder is a Site-Editor-style library: a header with four
collection tabs (User, Theme, Uploaded, Community — the last two served by the
cloud browser), each with its own search and category rail, over a grid of
fixed-size square tiles, plus an always-present details sidebar whose Save and
Edit actions sit above the same panels the editor shows.

**Every tile is a document drawn by a server.** A cloud tile is the service's
preview document; a local tile is this site's own front-end render
(`Pattern_Builder_Preview::serve_tile()`, `?pattern_builder_tile={id}&v={key}`
on the home URL). Both are framed at one design width (1400px) and scaled into
the tile by a constant the stylesheet computes from the two sizes
(`src/_pattern-tiles.scss`). The tile document centres its own content, so a
short pattern is centred and a tall one cropped at the same point in both
grids, with nothing measuring anything in JavaScript.

Drawing local tiles on the server is what makes them show what the site shows.
The in-browser `BlockPreview` the grid used before could not apply block style
variations at all — core styles each block carrying one individually, from
global-styles data only an editor boot supplies — and needed its own
workarounds for bindings and pattern references.

The tile is a front-end GET rather than a REST call because an iframe can send
the login cookie but not the REST nonce, and a nonce in the URL would change
every twelve hours and take the browser's cache with it. It is safe without
one: it changes nothing, answers only a user who can `edit_posts`, and may be
framed only by this site (`Content-Security-Policy: frame-ancestors`). It
carries no scripts and no admin bar.

**The cache key** (`src/utils/tileKey.js`) hashes the pattern's markup, the
markup of every pattern it places at any depth, and the `designVersion` the
page prints (`Pattern_Builder_Preview::design_version()`: theme.json, the style
partials, `style.css` and `functions.php` of the theme and its parent, Global
Styles, WordPress, this plugin and the active plugins). A versioned tile is
`Cache-Control: private, max-age=31536000, immutable`, so the browser redraws
only the tiles whose render could have changed.

The page prints the server-registered block bindings sources, as
`edit-form-blocks.php` does: without those label-bearing stubs core's
`registerBlockBindingsSource()` refuses every source silently, and a pattern
filling another's slots renders the other pattern's placeholder copy.

## Creating a pattern

Creation starts from a **kind**, listed down the left of one modal under two
headings — Design (Design Pattern, Synced Design Pattern) and Starter (Page,
Block Starter, Template, Template Part). `src/components/patternKinds.js` holds
the kinds and the request each turns into; `PatternCreatePanel` is the UI.

A kind is a starting point, not a stored property: it fixes the metadata its
job implies, following the theme handbook's pattern pages, so the modal asks
only for what the kind leaves open beyond the name and description every kind
takes. A design pattern asks where it is stored and is the only kind that does;
the four starter kinds are always theme patterns, because everything that
places them is a pattern-file header a `wp_block` has nowhere to put. Nothing a
kind decides is locked in — it all stays editable in the pattern's own metadata
panels afterwards.

## Synced patterns and the companion plugin

The runtime classes (`Pattern_Block`, `Pattern_Resolver`, `Block_Markup`,
`Inner_HTML_Processor`, `Synced_Patterns`, `Editor_Support`, and `src/runtime/`)
are vendored from [synced-patterns-for-themes](https://github.com/Twenty-Bellows/synced-patterns-for-themes)
and must stay logic-identical to it.

Pattern Builder always registers the full stack. When both plugins are
installed the companion sees `PATTERN_BUILDER_VERSION` at `plugins_loaded` and
stays entirely unloaded — one check in one place. Deactivate Pattern Builder
and the companion takes over with identical rendering, since both read the same
`Synced: yes` header. Pattern Builder clears the companion's transient after
file writes so it never wakes to a stale cache.

The product story: build with Pattern Builder, ship the theme with Synced
Patterns for Themes. Swapping one for the other changes nothing about how the
site renders.

## Names

A pattern installed from the cloud keeps its cloud name,
`{handle}/{collection}/{pattern}`, and `Pattern_File_Store` writes it to
`patterns/{handle}/{collection}/{slug}.php`. The theme scan is therefore
recursive: core reads `patterns/` to unlimited depth, so a nested file is a
pattern WordPress registers with no help from us, and two accounts' `hero`
patterns stop overwriting each other. The theme's own patterns keep the flat
layout every theme uses; `path_for_name()` is the one place that decides.

A bare name is namespaced at the one door every theme-pattern write goes
through (`Pattern_File_Store::update_theme_pattern()`, via `namespaced_name()`)
rather than at each caller. WordPress registers a pattern under whatever its
`Slug:` header says, so a bare name registers something no `core/pattern`
reference can reach — and an unresolved reference renders as nothing rather
than as an error.

## Previews

`Pattern_Builder_Preview` renders a pattern as a whole page with the site's
styles: `standalone` on its own, `page` inside the resolved page template, and
either one against a bundled lab theme through the route's `theme` parameter.

The page context needs a post to exist, so a stand-in is primed into the object
cache for one request and never written. Two things make it work:
`core/post-content` checks `$block->context['postId']` and refuses without it,
then calls `get_the_content()` with no arguments, which reads the *global* post
and the `$pages` globals `setup_postdata()` fills.

### Lab themes (`themes/`)

Two block themes ship with the plugin and nothing registers or activates them:

- **`blank-theme`** — no presets, no styles, templates that constrain nothing,
  so a pattern rendered against it shows what the pattern itself does. It needs
  a `functions.php` because `theme.json` cannot make a theme blank on its own:
  `settings.color.defaultPalette: false` hides core's colours from the picker
  but does not stop `--wp--preset--color--vivid-red` being emitted, so core's
  presets are emptied through `wp_theme_json_data_default` instead.
- **`opinionated-theme`** — the worked example of `references/design-system.md`,
  with the safe slugs at values that are nobody's defaults, so a pattern
  referencing them correctly looks *different but right* and one hard-coding a
  hex looks wrong. Opinionated and deliberately not broken.

A pattern that renders correctly in both travels. The swap filters
`stylesheet`/`template` and their directories for one request and registers the
plugin's themes directory as a theme root. A bundled theme exposes
`{slug}_boot()`/`_unboot()` because `require_once` runs a file once per process.

## Migration from 1.x

`Pattern_Builder_Migration` runs once on upgrade: it rewrites `wp:block` refs
pointing at the old `tbell_pattern_block` mirror posts to `wp:pattern` slugs, in
post content and theme files, *while the mirror rows still exist as the ID→slug
map*, then deletes the rows and the old capabilities.

## Code map

### PHP (`includes/`)

| Class | Job |
|---|---|
| `Pattern_Builder` | Singleton bootstrap; requires and wires every component. |
| `Pattern_Builder_Entity` | The rowless `pb_pattern` post type. |
| `Pattern_Builder_REST_Patterns_Controller` | String-ID CRUD for theme patterns; the collection also lists `wp_block` so one request paints the library. |
| `Pattern_File_Store` | The file pipeline: theme scanning, header round-trip, image import/export, conversions, cache flushing. |
| `Pattern_Builder_API` | `POST /pattern-builder/v1/process-theme` (bulk localize / import images). |
| `Pattern_Builder_Admin` | Appearance → Pattern Builder: the browse grid and the edit-mode boot. |
| `Pattern_Builder_Editor` | Enqueues the management bundle on block-editor screens. |
| `Pattern_Builder_Preview` | Whole-page renders, the lab-theme swap, and the grid's tiles. |
| `Pattern_Builder_Assets` / `Pattern_Builder_Fonts` | The images and typefaces a pattern can point at. |
| `Pattern_Builder_Theme_Json` | One load/save/edit door over the two places a theme.json-shaped config lives. |
| `Pattern_Builder_Theme_Styles` | The `styles` half: deep merge, the `css` refusal, the report of what core's schema dropped. |
| `Pattern_Builder_Block_Style_Variations` | The theme's `styles/*.json` partials. |
| `Safe_Css` | The safe subset of literal CSS a variation may carry. See [`cloud.md`](cloud.md). |
| `Pattern_Builder_Markup_Checks` | The failures PHP can see and every one of them silent at render. |
| `Pattern_Builder_Migration` | The one-time 1.x upgrade. |
| `Pattern_Builder_Security` / `Pattern_Builder_Localization` | Path-validated filesystem helpers; pattern localization. |
| Vendored runtime | `Pattern_Block`, `Pattern_Resolver`, `Block_Markup`, `Inner_HTML_Processor`, `Synced_Patterns`, `Editor_Support`. |

Cloud classes and abilities have their own documents.

### JavaScript (`src/`)

Three webpack bundles:

- **`PatternBuilder_EditorTools`** — every block-editor screen: the sidebar,
  the document panels for `pb_pattern` and `wp_block` (Source with conversion,
  Synced Status, Metadata, Associations, Bindings), and the save monitor.
- **`PatternBuilder_Runtime`** — the vendored content runtime, enqueued only
  when this plugin owns it.
- **`PatternBuilder_Admin`** — the browse grid, and the edit-mode boot of
  core's edit-post editor: `wp.editPost.initializeEditor` pointed at the
  entity, a `history.replaceState` guard (the editor believes it lives at
  post.php and rewrites the address bar; a string id would 404 there), and a
  `MainDashboardButton` fill whose Back button returns to the `back` URL.

Supporting modules: `src/admin/` (App, PatternBrowser, editor-boot),
`src/cloud/` (see [`cloud.md`](cloud.md)), `src/components/`, `src/objects/`,
`src/utils/` (`tileKey`, `patternTree`, `blockValidity`, `telemetry`), and
`src/runtime/` (vendored; keep identical to the companion's `src/`).

State comes from the core data stores (`core`, `core/editor`,
`core/block-editor`) — there is no custom store.

## Uploading is gated on block validation

Markup a block type would not have written itself renders correctly but reads
as "unexpected or invalid content" the moment any editor opens it, and only a
browser can tell: a block's `save()` is JavaScript and no server can re-run it.
`src/utils/blockValidity.js` parses the saved markup with `@wordpress/blocks`,
disables the upload button and names the offending blocks.

Core answers two questions and the panel asks both. `parse()` is tolerant —
every block keeps its old `save()` implementations and markup matching any of
them is accepted and migrated — so it decides what is *invalid* and blocks the
upload. `validateBlock()` asks whether this is what the block writes today, and
only warns. Two cases are exempt: an attribute core relocated rather than
dropped, and any block carrying Pattern Overrides bindings, whose content comes
from the binding source at render.
