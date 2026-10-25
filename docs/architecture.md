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

A pattern is not a post, and one block has to be told so. The editor gives a
document's blocks a `postId` and `postType` context unless its post type is one
of core's `NON_CONTEXTUAL_POST_TYPES` — `wp_block`, `wp_navigation`,
`wp_template_part`. A user pattern is on that list; `pb_pattern` cannot be,
because the list is a constant inside core's editor bundle with no filter over
it. So `core/post-content` in a theme pattern used to be handed the pattern's
own id, find it already on the recursion stack, and render "Block cannot be
rendered inside itself" in place of anything editable — while the same pattern
saved as a user pattern behaved perfectly.

`registerPatternPostContent()` takes the two values back out, on an
`editor.BlockEdit` filter narrowed to that one block and that one post type,
which puts it on the path core's own pattern editor uses: the placeholder
describing what the block will show. The narrowing is not optional —
`enqueue_block_editor_assets` fires on every editor screen, so a filter that
did not check would break `core/post-content` in the page editor. `withoutPost()`
removes only what is there, so this stops mattering by itself if core ever adds
the post type to that list.

## Pattern bindings

A block's value can come from three places: the pattern file, whoever places
the pattern, or the post the pattern lands in. The Pattern Bindings panel
(`BlockBindingsPanel`) is where that is decided, and it writes nothing but
core's own `metadata.bindings`.

**The post type lens comes first.** A pattern binds against a post it has not
met, so there is no record to read a field list from — which is why core's own
bindings panel is empty inside a pattern and says nothing about why. Naming a
post type in *Fields from* supplies the one piece of context the sources need;
`core/post-meta` builds its list from `postType` alone. The lens is not saved
to the pattern: the binding still resolves against whichever post the pattern
is placed in. It starts at *User input only*, where no source is offered and a
block gets only the choice a pattern can make by itself.

**The lens lists only post types worth naming.** Most of a site's post types are
machinery a pattern is never placed in — templates, navigation, font faces, the
plugin's own `pb_pattern` — and offering them promises fields that do not apply.
`Pattern_Builder_API::get_bindable_post_types()` decides, in three steps: a
viewable post type is listed, because those are the ones a pattern lands in and
the fields every post has apply to them even when they register no meta, which
on a stock site `post` and `page` do not; any other post type is listed if the
filter declared a field for it, a deliberate act honoured whatever the post
type; failing that, a post type a plugin registered is listed if it has meta
worth binding to. That last courtesy stops short of core's own internals, or
`wp_block` would appear on the strength of the sync flag core registers against
it.

The question is asked of the server because it has to be asked about every post
type at once, and `core/post-meta` enumerates one with a request apiece — so
deciding it in the editor would cost a round trip per post type to populate a
dropdown. The same route answers it: `post_type` is optional, and omitting it
returns the slugs rather than the fields. The cost is that a source publishing
its fields only from JavaScript is invisible to the decision, so a post type
nothing else offers will not be listed; answering the filter is how such a
source puts one there.

**The per-block control follows the lens.** With no post type a block gets an
*Overridable* toggle, off being the value the pattern file saved; it is
disabled until the block is named, since an override is stored against the
name. Choose a post type and the toggle gives way to one row per bindable
attribute, each offering *Not connected*, *Overridable*, or a field. A block
whose bindings are too detailed for the toggle — a source binding, or overrides
on only some attributes — keeps its rows whatever the lens says, so nothing
already bound is hidden.

**A source is offered only where it has fields to offer.** Every choice in a
row is a field some source published for the chosen post type; a source that
published none for it is left out rather than shown empty, and there is no
typed escape hatch. The `args` a binding is written with come from the field
descriptor, which is what lets sources disagree about the argument name — most
read `key`, while `core/post-data` and `core/term-data` read `field`.

Fields reach the panel two ways. A source registered in JavaScript publishes
them itself through `getFieldsList`; on WordPress 6.8 the store kept that
callback for `core/post-meta` and discarded it for every other source unless
the Gutenberg plugin was running, a restriction `@wordpress/blocks` 15.7
lifted. A source registered in PHP cannot publish one at all — core's registry
rejects any property beyond `label`, `get_value_callback` and `uses_context`,
and fails the whole registration rather than ignoring the extra — so the
`pattern_builder_binding_fields` filter is how a PHP source declares its
fields, and how a site adds a field to a source it does not own. The panel
fetches them per post type, once one is chosen rather than for every type up
front, and merges them with whatever the source published itself. A source
whose declaration is malformed contributes nothing: a field with no `args` has
nothing to bind to, so `Pattern_Builder_API::get_binding_fields()` drops it.

`Pattern_Builder_API::add_post_data_fields()` answers that filter for
`core/post-data`, whose `date`, `modified` and `link` resolve against any block
while its own field list appears only when a Post Date block is selected.
`core/term-data` is deliberately not given the same treatment: it resolves from
a `termId` and `taxonomy` that a pattern placed in a post does not carry.
`Pattern_Builder_ACF` answers it for `acf/field`. ACF registers that source
with a value callback and nothing else, and ships no field list in JavaScript
either, so without this the source appears in the panel with nothing under it.
Only enumeration is supplied: resolving stays ACF's own callback, so the two
gates it applies before returning a value are applied before offering one — a
field type that supports bindings, and a field whose *Allow Access to Value in
Editor UI* setting is on. ACF defaults that setting off for any field created
since its 6.3.6, so a field added today is offered nowhere until somebody turns
it on, and offering it regardless would be offering a choice that renders
nothing.

`core/post-meta` needs no help — it enumerates the registered meta itself, and
it is the one source whose field list and render-time gate are the same, so a
key missing from the list is a key that would render nothing.

No core source exposes a post's title or content, and declaring one would not
change that. Declaring and resolving are separate powers: the filter decides
what the panel offers, while the source's own `get_value_callback` decides what
a rendered block gets, and `core/post-data` answers the three fields above and
null for everything else — a block bound to a title would render its fallback
forever. Reaching a title through a binding means registering a source whose
callback returns it, which is now a PHP-only job: the same filter gives that
source its field list.

**None of this is needed to render a binding.** The panel writes core's
`metadata.bindings` and the plugin registers no binding source of its own, so a
pattern's bindings resolve wherever the pattern is rendered — including on a
site running only synced-patterns-for-themes, or neither plugin.
`Pattern_Resolver::fill_slots()` removes only the `core/pattern-overrides`
bindings it answered and leaves every other one in the markup for core, and
`Pattern_Block` attaches the pattern's blocks as inner blocks so they inherit
the post context a binding needs. Both are vendored, so both plugins behave
identically; core's own `render_block_core_pattern()` reaches the same place by
calling `do_blocks()`. The `pattern_builder_binding_fields` filter is an
authoring convenience with no render-time role — it names fields for the panel
and never resolves one.

**Which attributes are bindable is core's decision**, read from the editor
setting `__experimentalBlockBindingsSupportedAttributes` and falling back to
the 6.8 table in `src/utils/bindings.js`, which mirrors
`Pattern_Resolver::get_supported_attributes()`. A theme filtering
`block_bindings_supported_attributes` widens the panel with it.

**Writes stay in core's shape.** `__default` means every supported attribute is
a pattern override; the panel expands it for display and collapses back to it
whenever every attribute ends up overridable, so a pattern file does not change
shape just because it was opened. Bindings on attributes the panel does not
manage are carried through untouched.

Editing any of this needs `canUpdateBlockBindings`, which core derives from
`edit_block_binding` and denies outright for an editor context carrying no
post. `Pattern_Builder_Admin::allow_block_bindings_editing()` grants it back on
this screen to users who can already edit the pattern.

## The browse page

Appearance → Pattern Builder is a Site-Editor-style library: a header with five
collection tabs (Theme, User, Plugin, Uploaded, Directory — the last two served
by the cloud browser) and an Agents tab ([below](#the-agents-tab)), each
collection with its own search and category rail, over a grid
of fixed-size square tiles, plus an always-present details sidebar: an Edit
action that opens the pattern's own editor, over the Source and Actions panels.
Those two act on the pattern as they are used rather than staging an edit, so
the sidebar has nothing to save — everything that is edited rather than acted on
is edited in the editor. Plugin is the exception to the Edit action, and has its
own sidebar: those patterns belong to a plugin and can only be copied.

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

### Patterns a plugin registered (`Plugin`)

A pattern plugin puts its patterns in the block pattern registry and nowhere
else. There is no file this plugin may write and no row to update, so the
Plugin tab is the one read-only collection of local patterns:
`PluginPatternDetailsPanel` shows what the plugin declared and offers a copy
rather than an edit, and the only way to change one — or to put one on the
cloud, which needs somewhere to keep the `Cloud:` reference — is to copy it
first.

**What counts as one** is decided by `Pattern_File_Store::get_plugin_patterns()`:
everything in the registry except what WordPress itself put there (`source` of
`core` or `pattern-directory/*`, which core sets and nothing else does) and
every slug the theme's own pattern files claim. The registry cannot be asked
which of its entries came from the theme — `get_all_registered()` unsets a
pattern's `filePath` as a side effect of resolving its content, so by the
second call it is gone — which is why `theme_pattern_slugs()` reads the `Slug:`
header of the files in `get_pattern_directories()`. Those are the same
directories the rest of the store reads, so the answer stays right while a lab
theme is worn, and a header read is not the render a full scan would be.

A pattern registered by a *theme*, from its `functions.php` rather than a file
in `patterns/`, lands here too. It is rare, and it is read-only for the same
reason, so the tab is right about it even though its label is not.

**Which plugin** is a label, not a fact the registry keeps: `plugin_label()`
matches the pattern's namespace against the installed plugins by directory slug
and text domain, and falls back to the namespace itself.

**The listing is asked for.** `GET /pattern-builder/v1/patterns` still answers
with the patterns this site owns; `?source=plugin` answers with these instead,
and the tab fetches them the first time it is opened. A pattern plugin can
register hundreds, and the editor's own sidebar — which lists everything the
unqualified route returns — wants none of them.

**A copy takes the tree, and only into the theme.** `POST /patterns` takes
`fromPlugin` beside `fromWpBlock`, and `Pattern_File_Store::copy_plugin_pattern()`
writes the pattern and every pattern it places, leaves first, repointing each
`core/pattern` reference at the copy through `repoint_references()`. The
destination is not offered: a section has to stay reachable by `core/pattern`,
which resolves against the block pattern registry, and a `wp_block` is never in
it — the same reason a cloud install lands a dependency as a theme pattern.
`update_theme_pattern()` pulls the images each one points at into the theme's
`assets/images` on the way.

Four things can be at the end of a reference, and each is answered differently:
a pattern of the theme's own is left alone, because the reference already
reaches it; one the theme already has a copy of is pointed at rather than
copied again; a plugin pattern is copied; and a name nothing on this site
answers to is left as it is and reported, since the plugin's own pattern
renders it no better and refusing the copy would help nobody. A loop is
refused, naming it.

**Copying is idempotent**, which is what makes the second of two pages that
share a section find the section already there rather than write `hero-2`. It
rests on `Origin:` being machine-readable: `find_copy_of_plugin_pattern()`
matches on the exact value, which a note in the header comment could not
support. A name the theme uses for something *else* is still numbered — the
user picked a pattern, not a slug.

**`Origin:` records which registered pattern a copy came from**, under a
scheme: `plugin:twentig/hero`. The scheme is the whole point. An origin is
otherwise a cloud name, `{handle}/{collection}/{slug}`, which the service
resolves to a pattern page and renders as a link — so a plugin's name reaching
the service would publish a link to nothing. Counting segments would not have
told the two apart, because nothing stops a plugin registering `acme/blocks/hero`
and Pattern Builder writes three-segment names itself for cloud installs. A
colon cannot occur in a cloud name, every segment of which is a slug, so the
rule is exact and states itself: **an origin carrying a scheme never leaves
this site**. `Pattern_File_Store::is_cloud_origin()` is that rule, and the
porter asks it before putting an origin in a package.

## The Agents tab

The last tab connects Claude to the site over MCP (see
[`abilities.md`](abilities.md#over-mcp)) for someone who has never configured an
MCP client and may never open a terminal. It adds no REST routes: everything it
does goes through core's.

- **Readiness** comes from `Pattern_Builder_Mcp::client_state()`, printed with
  the page's settings: whether the Abilities API exists, whether the MCP
  Adapter runs (its version, and the plugin bundling it when that is not the
  adapter's own), and whether Application Passwords are offered — `insecure`
  when the site is not on https and not local, `disabled` when filtered off. A
  missing or inactive adapter is installed or activated through core's
  `/wp/v2/plugins` route by someone who may, after which the page reloads with
  `tab=agents` so it comes back to the tab rather than to Theme.
- **A connection is an Application Password** created for the current user
  through core's `/wp/v2/users/me/application-passwords`, carrying
  `Pattern_Builder_Mcp::APP_ID`. That `app_id` is how the tab lists only its own
  connections, with the created and last-used times core records, and revokes
  them through the same route.
- **The person never handles the key.** `src/admin/agentConnect.js` turns login
  and password into the `Authorization` header and wraps it in a message to
  paste into Claude, which sets the connection up itself with `claude mcp add`.
  The server is named after the site's address
  (`Pattern_Builder_Mcp::server_name()`), since one person connects many sites.
  The password is shown once, as core only returns it once.
- **It is saved for the folder only, outside it.** The message prescribes
  Claude Code's local scope, which files the server under the folder's path in
  Claude Code's own settings, and tells Claude to ask before putting the
  password in any file in the folder. The key is an administrator's password
  for the site, and a project folder is what gets committed, synced and shared,
  while a user-wide server would put every site's tools in every session.
  Because the person will not find it in the folder, Claude is asked to say
  where it went and how to disconnect: the tab's own revoke, or removing the
  server. The price is that renaming or moving the folder disconnects it.
- **The key is tried before the person leaves.** Right after creating it, the
  tab calls the endpoint with the new header and no cookies, because some hosts
  strip `Authorization` before WordPress sees it, and that failure would
  otherwise surface only in Claude. The call counts as a use, so the
  connection's last-used time starts at its creation.

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
patterns stop overwriting each other. `Synced_Patterns` finds the `Synced:`
header in the same files the same way — any depth, through core's
`theme_block_pattern_files` filter — because a registered pattern it misses is
inserted as a copy whatever its header says. The theme's own patterns keep the
flat layout every theme uses; `path_for_name()` is the one place that decides.

A bare name is namespaced at the one door every theme-pattern write goes
through (`Pattern_File_Store::update_theme_pattern()`, via `namespaced_name()`)
rather than at each caller. WordPress registers a pattern under whatever its
`Slug:` header says, so a bare name registers something no `core/pattern`
reference can reach — and an unresolved reference renders as nothing rather
than as an error.

## What a pattern file carries

Writing a theme pattern rebuilds its file. `build_pattern_file_metadata()`
composes the header out of the pattern's own fields, and
`Abstract_Pattern::FILE_HEADERS` is the one list naming them, so the read and
the write cannot drift apart. That is lossless for a file the plugin wrote, and
lossy for every other one: `get_file_data()` can only extract headers it is
asked for by name, so the `@package` block a theme conventionally carries is
invisible to it and used to disappear on the first save.

`additionalMetadata` holds the rest of that comment verbatim — every line
`is_recognised_header()` says is not a header, stripped of its leading `*`, in
the order it was written. Preserved rather than parsed, because a docblock tail
is freeform: tags, a note to the next reader, a license paragraph. A user
pattern has no file and so always has an empty one, and nothing carries it to
the cloud, where a tag naming this theme would be wrong in anybody else's.

The value is free text from the editor written into a PHP comment, so the
writer breaks apart the one sequence that escapes one rather than refusing the
save. Every other header is free text too — a title, a description, a keyword
arriving from an agent — so `header_value()` breaks the same sequence in each,
and also puts each on one line, since a newline would start a header of its own.
`tests/php/test-pattern-metadata.php` asserts that what lands on disk
tokenizes to an opening tag, a doc comment, a closing tag and literal markup,
and nothing else.

## Pattern files that are programs

A pattern file may be a program. Core reads one by running it and keeping the
output — `WP_Block_Patterns_Registry` includes the `filePath` it registered when
a pattern's content is first asked for — and `Abstract_Pattern::from_file()`
does the same. That is how a theme writes a pattern that changes with the post
it is placed in.

It is also why such a file cannot be written back. What the read produced is a
snapshot of one run, in whatever context that run had: one side of every branch,
every `do_blocks()` call frozen as rendered HTML, every translated string frozen
in one language, every generated URL resolved for this site. Rebuilding the file
from that would put the snapshot where the program was.

`Abstract_Pattern::find_custom_php()` decides, with the tokenizer rather
than a search for an opening tag, and `file_has_custom_php()` asks it about a
file on disk. A file that is an opening tag, a doc comment,
a closing tag and literal markup is one this plugin wrote and can rewrite;
anything else is not. An ordinary comment counts as well — it runs nothing, but
it sits outside the header comment and would be dropped just the same, so the
conservative answer is the safe one. The cost is about the same as the `include`
that already happens for every pattern read.

The exceptions are the plugin's own writes, and there are two of them.
A localized write turns a pattern's text into
`<?php echo wp_kses_post( 'text', 'domain' ); ?>`, and importing a pattern's
images turns each one into `Pattern_Builder_Assets::theme_reference()` —
`<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/images/x.svg' ); ?>`.
Both round-trip: reading the file runs the call, writing it puts the same call
back, so a file carrying either is still one the plugin can rebuild.

`written_call_shapes()` names those two shapes in tokens and `shape_ends_at()`
matches one exactly, down to the identifier in each position — the escape
function is one of `LOCALIZED_STRING_FUNCTIONS`, the asset call is `esc_url`
around `get_stylesheet_directory_uri`. A variable where a literal belongs, a
different concatenation, or any other function fails. A hand-written
`esc_html_e()` is therefore custom PHP, correctly, and so is the same `esc_url`
reaching for a directory that is not the theme's: the string would survive the
round trip but the call around it would not.

Getting this list wrong is not a quiet failure. A pattern the plugin wrote
itself, read back and misjudged, becomes a pattern the plugin refuses to save,
and since the same shapes check every write (below), a writer whose output
drifts from them has its writes refused at once — so the shapes here and the
strings the two writers produce have to be changed together.
`test_the_plugins_own_php_still_writes()` holds them to each other.

**A write is followed by a read, so the file has to be forgotten first.** A save
answers with the pattern as it now is, which means running the file that was
just written — and PHP will hand back the file as it *was*, from the stat cache
and from opcache, which under the usual `validate_timestamps` does not look at
an mtime again for a second or two. `forget_compiled()` drops both after every
write. Without it a save appears not to have taken: the response, and anything
read in the moment after it, describe the markup that was replaced.

`hasCustomPhp` carries the answer through the REST response and an agent's pattern
summary, and `refuse_if_file_has_custom_php()` turns it into a 409 at both
`update_theme_pattern()`, before anything has been imported or localized, and
`update_theme_pattern_file()`, so no later caller can route around it. A pattern
with no file yet is a creation and passes. In the editor the pattern still opens
and still previews; `usePatternCustomPhpSaveLock()` takes the Save button away and
`PatternPhpNotice` says why, in the sidebar and on the browse screen, because a
save that fails at the server costs whatever was typed first.

What this does not do is let anyone edit such a pattern. The file is the place
to edit it, and a source editor inside the plugin would have to be gated on
`edit_themes` rather than the `edit_theme_options` every other write here uses —
a different capability, which a multisite site administrator does not have.

**No write makes one, either.** The guard above keeps the plugin from
overwriting a program; this keeps it from writing one. Markup goes into the file
whole, after the header closes, so PHP in it would run on every render, and
`edit_theme_options` would become the power to run code, which WordPress keeps
behind `edit_themes` and a site can switch off entirely with
`DISALLOW_FILE_EDIT`. So `update_theme_pattern_file()` runs `find_custom_php()`
over the bytes it is about to write and refuses with `pattern_has_custom_php`,
a 400 quoting what it found.

It checks the finished file rather than any one field, so every input and every
caller is covered at once, and so are the plugin's own writers.
`create_localized_string()` and `theme_reference()` put text inside
single-quoted PHP strings and escape the backslash as well as the quote; a
string that ended early would leave code behind it that fails the shapes like
any other. The tokenizer is the same one the later `include` uses, so short
tags and the echo tag are judged as PHP will judge them.

Raw PHP in markup is refused, not rewritten. Legitimate block markup never
carries an opening tag — a code block stores PHP HTML-escaped — so one is either
the plugin's own call or someone writing PHP, and a refusal tells them which.
No capability lifts it: on a single site every administrator holds
`edit_themes`, so an exception there would reopen the hole for any agent working
as one. A pattern that needs a value computed at render belongs in a block or a
binding, or in a new shape added to `written_call_shapes()` in code.

## Templates and template parts

Templates and template parts are files too, and the same rule holds for them:
the theme's file is what Pattern Builder writes, and nothing it writes lands
anywhere else. They are reached only through the abilities
([`abilities.md`](abilities.md#templates-and-template-parts)); there is no
editing surface for them here, because the Site Editor already is one.

`Pattern_Builder_Template_Store` is the one door, the way
`update_theme_pattern()` is for patterns. It writes only into the active theme's
template folders (`get_block_theme_folders()`, so a theme still on
`block-templates/` keeps them), refuses a slug that is not a flat lowercase
filename, and refuses `<?` on the bytes about to be written: a template file is
read as text and never run.

**Core keeps a second copy of a template, and the store has to decide what to do
with it.** A Site Editor save is a `wp_template` or `wp_template_part` row filed
under the theme, and it renders in place of the file with nothing on disk to
say so. So the store reads in core's order (customization, then the active
theme's file, then the parent's, then a template a plugin registered) and a
write that lands in the file removes a customization of the same slug, as the
Site Editor's own reset does. The caller read the customization and edited it,
which the update's `expectedHash` proves, so the row goes and its content is
the file. Only its revision history is lost.

**The store reads for itself rather than through core**, for two reasons that
look alike. Core lists a theme's template files once per process
(`_get_block_templates_paths()` keeps a static list per folder) and
`WP_Theme_JSON_Resolver` keeps each `theme.json` it has decoded for the rest of
the request, and neither is cleared by anything a plugin can call. A template
written during a request, or a part's area recorded in `theme.json`, would not
show to a read later in the same request. And core's reader inserts hooked
blocks into a file's markup, which a write would then store. So the store scans
the folders and reads `theme.json` itself, child over parent, and returns a
file's bytes as they are.

A template's title and post types, and a part's title and area, live only in
`theme.json` (`customTemplates`, `templateParts`), so that is where the store
records them, rewriting the file only when an entry changes. Deleting removes
the active theme's file, any customization and that entry, and refuses the last
`index.html`, without which WordPress stops treating the theme as a block
theme. A template a plugin registered is read-only, as a plugin pattern is.

## Previews

`Pattern_Builder_Preview` renders a pattern as a whole page with the site's
styles: `standalone` on its own, `page` inside the resolved page template, and
either one against a bundled lab theme through the route's `theme` parameter.

The route asks for `edit_posts`, which the agent calling `render-pattern` has
and the browser it opens the URL in usually does not. So `render-pattern` also
hands back signed URLs from `signed_url_for()`: an `expires` timestamp an hour
out and a `token` that signs the pattern id, context, theme and expiry with a
key derived from the site's salts. `can_preview()` accepts that token in place
of the capability for exactly those parameters; carried to another pattern or
context, altered or expired, it grants nothing, and changing the salts revokes
every link handed out. The `noindex` and frame-ancestors headers apply the same
either way.

`find()` resolves what to render in the order the site owns them — a numeric id
as a `wp_block` post, then the theme's own files, then the registry — which is
what lets the browse grid draw a tile for a plugin pattern the same way it
draws one for a theme pattern.

Every render needs a post to exist, so a stand-in is primed into the object
cache for one request and never written. Two things make it work:
`core/post-content` checks `$block->context['postId']` and refuses without it,
then calls `get_the_content()` with no arguments, which reads the *global* post
and the `$pages` globals `setup_postdata()` fills. `supply_the_page()` satisfies
the first and `pose_as_a_page()` the second.

The page context poses as a page whose content *is* the pattern, so the
template's `core/post-content` renders it; the pattern's own, if it has one,
meets core's `$seen_ids` guard and renders nothing.

`render_blocks()` is why the other contexts pose as well, and it is not a
nicety. A tile is drawn on a front-end request to `tile_base()` —
`home_url( '/' )` — and `serve_tile()` runs on `template_redirect`, by which
point WordPress has queried the front page and set the global post. Core's
`render_block()` seeds `postId` and `postType` context from that global, so a
pattern carrying `core/post-content` passed the block's guard and then drew the
site's home page inside itself, in every tile of every such pattern. Posing puts
`stand_in_content()` there instead: filler that says it is filler, because a
pattern is a layout and an empty column shows less of one than filler does.

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
| `Pattern_Builder_REST_Patterns_Controller` | String-ID CRUD for theme patterns; the collection also lists `wp_block` so one request paints the library, and answers `?source=plugin` with the registered ones. |
| `Pattern_File_Store` | The file pipeline: theme scanning, the registry read behind the Plugin tab, header round-trip, image import/export, conversions, cache flushing. |
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
| `Pattern_Builder_Template_Store` | The theme's templates and template parts: resolved as core resolves them, written only into the active theme. See [below](#templates-and-template-parts). |
| `Pattern_Builder_Template_Checks` | The markup checks, plus what a theme file may not carry and what will not travel with it. |
| `Pattern_Builder_Usage` | Where a pattern is used: the patterns, templates, template parts and posts that place it, directly or through another pattern. See [`abilities.md`](abilities.md). |
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
`src/utils/` (`tileKey`, `patternTree`, `blockValidity`, `patternExport`,
`telemetry`, `bindings`), and
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
