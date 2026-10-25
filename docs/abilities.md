# Abilities — the agent interface

Pattern Builder registers **33 abilities** with WordPress core's Abilities API:
twenty-one in `Pattern_Builder_Abilities`, five for the theme's templates and
template parts in `Pattern_Builder_Template_Abilities`
([below](#templates-and-template-parts)), and seven for the cloud in
`Pattern_Builder_Cloud_Abilities` (see [`cloud.md`](cloud.md)). Core exposes the
registry over REST at `wp-abilities/v1`, and `Pattern_Builder_Mcp` serves the
same abilities as MCP tools through the WordPress MCP Adapter plugin
([below](#over-mcp)) — which is why there is no bespoke agent API here. An MCP
bridge reaches them only if it really bridges the Abilities API; a plugin that
ships its own fixed tool set does not.

Registration is conditional on `function_exists( 'wp_register_ability' )`: the
API postdates the plugin's 6.8 floor and nothing depends on it, so the REST
controller remains the baseline.

## Three behaviors of core's that are load-bearing

- `meta.show_in_rest` must be true, or the ability registers but is
  unreachable over REST.
- `meta.annotations` **select the HTTP method**: `readonly` is GET,
  `destructive` **and** `idempotent` together are DELETE, everything else is
  POST. So `update-pattern` is deliberately not marked destructive — that word
  means delete-like here, not "changes data". `delete-template` is the one
  ability marked both, and core accepts it over DELETE and nothing else.
- Input arrives under an `input` key, not at the top level: `input[key]=value`
  query args on a GET, `{"input":{…}}` in the body on a POST.

Agents authenticate with an Application Password, which core only offers over
HTTPS or when `WP_ENVIRONMENT_TYPE` is `local` (which is why `.wp-env.json`
sets it).

## Over MCP

The WordPress MCP Adapter plugin is the transport; this plugin only says what it
serves. `Pattern_Builder_Mcp` hooks `mcp_adapter_init`, which fires only where
the adapter is active, so without it nothing here runs and nothing else changes.
The adapter is never bundled: 0.7.0 deprecated that in favor of the plugin.

- **Its own server, `pattern-builder`**, at `/wp-json/pattern-builder/v1/mcp`
  and under WP-CLI as `wp mcp-adapter serve --server=pattern-builder`. It lists
  every ability in the `pattern-builder` category, read from the registry, and
  each becomes a tool named after it with `/` written `-`
  (`pattern-builder-create-pattern`). The adapter's default server serves them
  too, since every ability sets `meta.mcp.public`, but behind three generic
  tools — discover, get info, execute — which fold every annotation into one
  tool that is never read-only. A client can then neither allow the reads nor
  ask before a write. Here each ability's annotations arrive as that tool's
  hints, so it can.
- **`openWorldHint` marks what reaches past the site**: the seven cloud
  abilities, through their meta helpers, and the two in
  `Pattern_Builder_Abilities::OPEN_WORLD` — `add-asset`, which can fetch a URL,
  and `add-font`.
- **A refusal keeps its data.** The adapter hands a client an error's message
  and nothing else, and the data is half of what most refusals here say: the
  `currentHash` to read again from, the places a slot still fills, the
  problems. `keep_error_data()` filters `mcp_adapter_tool_call_result` for this
  server alone and appends the error's code and data, as JSON after a blank
  line.
- **The calling convention above stops mattering.** The adapter picks the
  method and supplies the `input` wrapper.

The Agents tab on the browse page is how a person connects one: it checks the
adapter and Application Passwords, creates the password, and hands over a
message for Claude to set the connection up from
([architecture](architecture.md#the-agents-tab)).

What MCP does not carry is what no ability carries: a file, which goes to the
assets route as a request body, and the validator, which runs where the agent
runs. An agent with a shell reaches both with curl and an Application Password,
as before.

`tests/php/test-mcp.php` talks to the server over its REST route, as a client
does, so it runs where the adapter plugin is installed — wp-env installs a
pinned release — and skips elsewhere. Every tool call it makes checks the
answer against the `outputSchema` its tool declares, because a client may
check `structuredContent` that way and an empty PHP array encodes as a list —
which is how `add-design-tokens` came to answer an all-skipped call with a
`written` list where its schema promised an object. The calls cover every read
that needs no input, everything an agent does to one pattern (on a user
pattern, so nothing lands in a theme), and the design-system writers to Global
Styles. The writers of theme files and the abilities that reach the network
are not called.

## The reads

| Ability | Answers |
|---|---|
| `get-authoring-guide` | The pattern-authoring documentation as Markdown, and the two skills it makes up, as files to install. |
| `get-design-system` | The merged presets, the `styles` a pattern inherits, and the `blockStyles` registered here — each marked `portable`. |
| `list-block-types` | What is registered *on this site*; naming blocks also returns each one's `supports`. |
| `list-patterns` | Every pattern's summary with its placement headers, any `origin` or `cloud` reference, and the registered pattern categories. `source` narrows it to the theme's files, the database's user patterns, or the read-only ones a plugin registered; `search`, `categories` and `keywords` narrow it further, and `page` with `perPage` pages it. |
| `get-pattern` | One pattern, whichever of the three sources it comes from. |
| `list-pattern-usage` | Every place that places a pattern, directly or through another pattern. |
| `render-pattern` | The rendered HTML plus preview URLs (`standalone`, `page`, and `themes` against each lab theme), each also `signed` so a browser with no credentials can open it for an hour. |
| `find-media` | The images a pattern can point at, plus how to upload one. |
| `list-fonts` | The font collection; naming a `family` describes that one, including whether it has a variable face. |
| `get-validator` | The validator script itself. |
| `get-editor-scripts` | The site's own editor script URLs, in load order. |
| `list-templates` | Every template and template part that resolves here, and where each comes from; listing parts also returns the areas a part can fill. |
| `get-template` | One template or template part with its markup and a `hash`. |

Two of these need their shape explained.

**`get-design-system` returns the whole system, not half of it.** Presets alone
left an agent able to see that a site defines a color called `ink` and not
that the theme paints every heading with it — and the safe move under that
blindness is to over-specify, restating the font on each heading and the whole
button on each button, none of which then follows the destination. Each block
style is marked `portable`: true for one declared in a block's own `block.json`,
which ships with WordPress, false for one this site registered, whose
definition has to be carried.

**`list-patterns` is how an agent finds a pattern before writing one**, and a
pattern nobody can find gets written again. `search` matches title, name,
description and keywords, ignoring case; `categories` and `keywords` keep only
patterns carrying every one asked for. Paging is opt-in: with neither `page`
nor `perPage` every match comes back, as it always did, and `total` counts the
matches either way. `keywordsInUse` is every keyword the source carries,
lowercased and counted before search and the filters, so an agent filters by
words that exist and tags its own pattern with them rather than a synonym.

**`list-pattern-usage` walks references upward.** `references_of()` in
`Pattern_File_Store` answers what a pattern places; this answers what places
it, which is the cost of changing a synced pattern. `Pattern_Builder_Usage`
first finds the patterns that carry it at any depth (theme, user and plugin
patterns alike), then the templates and template parts that place any of
them, then the posts. A place reached through another pattern is marked
indirect, with that pattern as `via`. Posts are found with a LIKE on each
carrier's name and confirmed by parsing, so a name mentioned in prose is not a
use, and only posts the caller may read are counted. Templates and patterns
come back in full; posts are named up to ten and counted beyond that.

**`list-block-types` reports supports because no validator can.** The classes a
block's saved markup must carry come from filters that only run inside an
editor. Supports is about two and a half times the size of everything else in a
listing, so naming blocks is what turns it on and a browse stays a catalog; a
name this site does not have comes back under `unknown`.

## The writes

| Ability | Does |
|---|---|
| `create-pattern`, `update-pattern` | Take **finished markup** and persist it. `update-pattern` refuses a pattern a plugin registered, which has no file or row here to write. |
| `copy-plugin-pattern` | Copies a plugin's pattern into the theme with everything it places, repointing its references at the copies. The one way one of those becomes a pattern an agent can write. |
| `add-design-tokens` | Writes presets into the theme's `theme.json` or Global Styles. |
| `set-global-styles` | Sets the `styles` a pattern inherits. |
| `add-block-style-variation` | Registers a named look as a `styles/{slug}.json` partial. |
| `set-layout` | `settings.layout.contentSize`, `wideSize`, `useRootPaddingAwareAlignments`. |
| `add-asset`, `add-placeholder-image`, `add-font` | Put a file where a pattern can reach it. |
| `create-template`, `update-template`, `delete-template` | Write, change and remove the active theme's templates and template parts. |

Every asset answer carries a **`reference`** — the string to put in the markup —
because the right string is not guessable: a theme pattern is a PHP file whose
assets are composed at render, and a hard-coded URL breaks the moment the theme
moves.

### Why the design-system writers are separate

They behave differently and folding them together would have meant an ability
documented as never overwriting that always does.

- A **preset** is additive and inert, so a collision is skipped.
- There is one `styles.elements.link.color.text` and setting it repaints every
  page at once, so `set-` replaces where `add-` never does.
- A **variation** is a third thing: a named look applied with a class and
  scoped to the blocks carrying it, which is what lets it describe a second
  kind of button without fossilizing one into the markup.

**A preset's slug is checked against a vocabulary, for two of the five types.**
A color's slug must be one of the roles `color_roles()` lists — the job the
color does (`surface`, `text-muted`, `primary`, `hairline`…), never its hue —
and a font size's one of `FONT_SIZE_LADDER`: `x-small`, `small`, `medium`,
`large`, `x-large`. Anything else is refused before a byte is written, and the
refusal lists what is allowed; a color slug in `COLOR_ROLE_SUGGESTIONS` also
names the role it most likely meant, and a font size that looks like a heading
is sent to `styles.elements`. A hue-named palette and font sizes named for
headings are what an agent reproducing a site produced without this, copying the
source's own names: a style variation leaves a hue name lying, and a heading
size belongs on `styles.elements.h1` … `h6`. A font size that lands out of order
with the sizes either side of it is written, with a `font_size_out_of_order`
warning, when both values are plain lengths. The check is
`execute_add_design_tokens()`'s alone: the cloud install writes through the same
`Pattern_Builder_Cloud_Tokens::apply()` and installs whatever a pattern carries.

**Registering a variation is a file rather than a theme.json key.**
`styles.blocks.variations` only *styles* a variation something else registered:
core builds its valid list from the block style registry and `sanitize()` drops
a node not in it. What registers one without PHP is a `styles/{slug}.json`
partial carrying a `blockTypes` key.

**A partial cannot carry a block state.**
`WP_Theme_JSON_Resolver::get_style_variations()` runs a partial through the
whole-theme schema before filing the styles under the variation's node, and a
whole-theme tree has no `:hover`. So the ability checks the file exactly as
core reads it, reports a state under `skipped`, and answers with the
`set-global-styles` call that sets it —
`styles.blocks.core/button.variations.{slug}.:hover`, the one place core
accepts one. A state set that way does not travel; the cloud carries the
partial.

What core's schema drops comes back as `skipped` rather than vanishing, since
an agent that believes it set a property builds the rest of the design on one
that is not there.

## What the writes refuse

`Pattern_Builder_Markup_Checks` refuses the set of failures PHP can see, every
one of them silent at render — attribute JSON that does not parse, a heading or
list contradicting its attributes, a block this site has not registered, a
`core/pattern` reference resolving to nothing or to the pattern itself, and a
Pattern Overrides slot nothing can fill. Each is named under the error's
`problems`.

A theme pattern meets one more refusal, in the file store rather than here
because it applies to every caller: markup carrying PHP beyond the calls the
plugin generates for localized text and the theme's own images is refused as
`pattern_has_custom_php`, quoting what was found (see
[`architecture.md`](architecture.md#pattern-files-that-are-programs)). The
`reference` that `find-media` and `add-asset` hand out is one of those calls, so
it passes.

A reference resolves through the registry *or* the theme's files, because a
pattern written a request ago is registered only on the next `init` — which is
what lets a page be stored in the same session as its sections, and what
enforces the bottom-up order the guides prescribe.

Both writes report a category nothing on the site registered under
`unregisteredCategories`.

Both also return `warnings`, never refusals, about the description: missing,
too short to tell two patterns apart (`DESCRIPTION_MIN_WORDS`), longer than one
sentence or `DESCRIPTION_MAX_WORDS` (`description_long`), or using words that
describe every pattern (`EMPTY_WORDS`). The description is what an agent
choosing from `list-patterns`, or a person searching the inserter, has to go
on, and a pattern nobody can find gets written again. It is one sentence saying
what the pattern is for and roughly what is in it; slot names, dependencies and
how it stacks are left to the pattern, which says them for itself. Only a description the
write sets is judged, since an edit is no occasion to re-judge prose its author
never wrote; an update that moves the markup under an unchanged description
gets `description_unchanged` instead, as something worth a look. The guidance
on writing one is in `pattern-kinds.md`.

**And about whether the markup lays out.** `Pattern_Builder_Composition_Checks`
looks at every flex or grid container and asks whether its children can size in
their tracks, following a `core/pattern` reference to the root block of the
pattern it places: a child whose root is a constrained group fills the whole
track (`composition_children_fill_track`), three or more children of a flex row
with no width will not hold a grid (`composition_flex_unsized`), and a
reference with more than one root block leaves its sizing unknown
(`composition_reference_unknown`). A grid that sets a column count or a minimum
column width sizes its own tracks and is left alone. These are warnings because
the markup is valid and renders; it just renders as a vertical stack, and
nothing else reports that. It runs on the site rather than beside the agent
because it needs only the parser and the patterns a reference points at, both
of which are here — an agent connected over MCP has neither. Like the
description, only markup the write changed is judged.

The reference check above runs when the *referencing* markup is written. The
same failure can arrive from the other side: an update that removes or renames
a slot sends every reference that filled it back to the pattern's own words.
So `update-pattern` compares the slot names before and after
(`Pattern_Builder_Markup_Checks::slot_names()`), asks `Pattern_Builder_Usage`
which places fill a removed one (every post, not just the ten a listing
names), and refuses with `pb_slots_still_filled`, naming each place and key. A
rename is reported as what it is, a removal plus an addition. A caller that
means it lists the slots in `dropSlots`; the write goes through and the answer
carries `reverted`, the places that now show placeholder copy. Saves from the
editor are not checked.

## Pinning an update to what was read

`update-pattern` replaces a pattern whole, and the pattern can change between an
agent's read and its write: a person saves it in the editor, another agent
updates it, or the read is simply old. So `get-pattern`, and the answer to
either write, carry a `hash` taken over everything an update replaces (the
summary `summarize()` builds plus the markup), and `update-pattern` takes it
back as `expectedHash`. When the stored pattern no longer matches, nothing is
written and the error, `pb_pattern_changed` with a 409, carries `currentHash`
so the caller can read again and redo its change on what is there now.

The hash is taken from the pattern as re-read after the write, because the
file store formats markup on the way to disk: hashing what was sent would hand
back a value the next read never matches. `expectedHash` is optional, so an
older caller keeps working, and the guides tell agents to send it.

An update also answers `changed`, false when the stored pattern is exactly
what it was. That stays a success, since the ability is annotated idempotent,
but it is usually the sign of an edit that did not say what it meant.

## Changing part of a pattern

Sending a long page pattern back whole to change one class costs tokens and
invites changes nobody asked for: an attribute reserialised differently, a
slot name retyped wrong. So `update-pattern` takes `edits` as an alternative
to `content`, a list of `{ old, new, replaceAll }` applied in order to one copy
of the stored markup by `Pattern_Builder_Abilities::apply_edits()`.

- Each `old` must match exactly once unless `replaceAll` is set. An exact
  match is tried first, then one that lets any run of whitespace stand for any
  other, since an agent copying a fragment out of formatted markup rarely
  keeps its indentation.
- A fragment that matches nothing or more than once refuses every edit, with
  `pb_edit_refused` naming the edit and, for an ambiguous one, the lines it
  matched. One failure writes nothing.
- `edits` requires `expectedHash`: a fragment only means something against the
  version it was cut from.
- The edited result goes through the same markup checks as content sent
  whole. Block validity is still the caller's to check, on the result.

Content and edits together are refused, and so is an update with neither. The
input schema cannot say "one of", so the ability does.

## Templates and template parts

`Pattern_Builder_Template_Abilities` reads and writes the active theme's
`templates/*.html` and `parts/*.html` (or the older `block-templates/` and
`block-template-parts/`, where a theme still uses them) through
`Pattern_Builder_Template_Store`, which [`architecture.md`](architecture.md#templates-and-template-parts)
describes. Every one takes a `type`, `"template"` or `"template-part"`, and a
`slug`, the filename without `.html`.

**What a read returns is what renders**, resolved in core's order: a Site Editor
customization, then the active theme's file, then the parent's, then a template
a plugin registered. Each says which in `source` (`custom`, `theme` or `plugin`),
names the theme a file is in and its path there, and says whether a file and a
customization exist. A theme file's markup is its bytes on disk, not core's
reading of it, which inserts hooked blocks: those bytes are what an update
replaces.

**Reading needs `edit_theme_options`, not `edit_posts`** as the pattern reads
do, because core's own templates endpoint asks for it to read as well as to
write. A template is the theme's structure rather than content an author
places.

**A write lands in a file in the active theme, and nowhere else.**
`create-template` refuses a slug the active theme already has as a file or a
customization; one that only the parent theme or a plugin provides is allowed,
and the answer's `replaces` says what the new file now renders in place of.
`update-template` on a customization folds it into the file and removes the row,
as the Site Editor's reset removes it, and answers `folded`; on a parent's
template it writes the active theme's own copy. A template only a plugin
provides is refused, as a plugin pattern is: nothing is written back to a
plugin. A title, a part's `area` or a custom template's `postTypes` go into the
active theme's `theme.json` (`templateParts`, `customTemplates`), the only place
a file's metadata lives; WordPress's own template types keep the titles WordPress
gives them. `postTypes` reads as core reads it: a custom template with no entry
is offered for every post type, one with an entry that names none for pages
alone, so an empty list sent here goes back to pages.

**Pinning and partial edits work as they do for patterns.** `get-template`
answers a `hash` over everything that renders and everything an update
replaces, the theme the file is in included, so a customization saved, folded or
reset in between changes it. `update-template` and `delete-template` take it as
`expectedHash` and refuse a stale one with `pb_template_changed`.
`update-template` takes `edits`, applied by the same `apply_edits()`, and an
update with metadata but neither `content` nor `edits` rewrites the markup that
is there.

**The checks are the pattern checks and then the theme file's.**
`Pattern_Builder_Template_Checks` runs everything `Pattern_Builder_Markup_Checks`
refuses, then refuses a `core/template-part` with no slug, one that resolves to
nothing in this theme, one that places the part being stored (directly or
through other parts), or one whose `theme` names another theme. A `theme`
naming the active theme is dropped on the way to disk, as core's own export
drops it. The store refuses any `<?` on the bytes it writes, for every caller,
as `pb_template_has_php`: a template is read as text and never run. Warnings, never refusals, cover what will not travel with the
theme: a `core/navigation` or `core/block` pointing at a row by its database ID,
or a `core/query` that doesn't inherit the request's query and filters by
`taxQuery`, `author`, `exclude` or `parents` (`template_database_id`), and
images placed in the template rather than in a pattern, which is the only way
to reach the theme's own assets from a template (`template_media`). The
composition warnings carry over.

**`delete-template` removes everything of the active theme's own**: its file,
any customization, and its `theme.json` entry, and answers `now`, what renders
for the slug afterwards. It refuses the index template when no parent theme has
one, since a theme without `templates/index.html` is no longer a block theme; a
template only the parent or a plugin provides, since the active theme has
nothing of its own to remove; and a part that templates, other parts, theme or
user patterns, or posts still place, unless something of the same slug would be
left to resolve, naming each place under `usedBy`. While something of the slug
is left, the `theme.json` entry stays with it.

## Two things deliberately absent

**Nothing takes a prompt.** An `execute_callback` that turned a description
into a pattern would need a model behind it, which is the inference business
this plugin is out of. The judgment lives in whatever agent is calling.

**Block validity cannot be offered and is not.** `save()` is JavaScript, so the
one check most worth having is the one no server can perform. The agent runs it
before calling `create-pattern` or `create-template`.

## The validator

`guides/pattern-author/scripts/validate-pattern.mjs` loads **the site's own
WordPress** (`wp-core.mjs`), so the answer comes from the block library that
site actually runs — the only version whose opinion counts. Nothing to install
but `jsdom`; `--npm` falls back to `node_modules` where there is no install to
find.

**`jsdom` is installed once per machine, not per project.** `loadJsdom()` takes
it from wherever Node finds it beside the scripts — a project that already has
it — and otherwise from `MACHINE_DIR`, the user's cache directory
(`~/.cache/pattern-builder`, or `%LOCALAPPDATA%` on Windows). When it is in
neither, the error gives the one command that fills that folder. The skill's
install stays a copy of its files: it runs no npm, and nothing lands in a skill
folder or in a theme repository with no JavaScript of its own.

The script order comes from core's generated `script-loader-packages.php`, and
the files go in as real `<script>` elements because these bundles are
strict-mode and a strict `eval` keeps its `var` declarations to itself, which
loses `ReactJSXRuntime` and every JSX call with it. Bundling the npm packages
instead would ship 21MB, because every block's `save()` imports
`@wordpress/block-editor` and core keeps each support's `addSaveProps` in the
same module as that support's editor UI.

An agent with no WordPress beside it gets the same check from the site. The
list of editor scripts has to come from there, because WordPress serves those
files to anyone but not the graph, and the order is not forgiving: the JSX
runtime reads `globalThis.React` as it loads, so React arriving late costs every
JSX call in the bundles, silently.

**The validator asks for that list itself, on every run.** Where it gets its
block code is, in order: a list given with `--scripts`, a WordPress install
(`--wp`, `WP_PATH`, or one it finds around it), a site named with `--site` or
else the one an installed skill records in its front matter as
`metadata.source`, and last `node_modules`. A site answers at
`/pattern-builder/v1/editor-scripts`, which opens without credentials, because
the validator runs in a shell beside an agent that holds none. It returns what
`get-editor-scripts` does, the dependency closure of core's block editor
packages and nothing a plugin registered, so it says no more than the WordPress
version the front end's `?ver=` usually shows. Asking every run is what keeps
the check current. A saved list goes stale silently: every script URL carries
its version and the scripts are cached by URL, so a list kept from before an
update checks against the old code, and an agent left to save one puts it
wherever it likes. Asking leaves nothing to save.

The scripts are cached in the system's temp folder, one folder per list. Each
run touches its own folder and removes any other unused for a week, so a site
that updated WordPress, or one no longer worked on, does not leave its old code
behind for good, while several sites in use at once keep theirs.

## The guides

The authoring documentation lives under `guides/` rather than `.claude/`,
because `.claude/` does not ship to wp.org; each directory in `.claude/skills/`
is a symlink to its counterpart there, so there is one copy. The same prose
ships as a Claude skill and over the wire.

They are **two skills, split by posture rather than by subject**:

- **`pattern-author`** — how a pattern gets written: the block vocabulary, the
  markup contract, validation, and the **factor** step. A pattern's worth is
  its reusability, so before any markup is written the guide asks for an
  inventory of what repeats, and the three levels (element, section, page) name
  what references what. Prose is made mechanical by producing an artifact — a
  table the writing step consumes — since a step with no output is a step
  nobody can see was skipped.
- **`design-reproduction`** — what to build when the design already exists
  somewhere else. A reproduction has two failure modes authoring does not, and
  both look like a finished page: values approximated where they could have
  been read, and a source's structure transcribed rather than factored. So it
  opens by **classifying the source** — readable or inferred — because the
  answer decides how exact it may claim to be, then installs the design system
  before any markup in the one order that works (layout, tokens, styles,
  variations), and verifies it numerically before comparing a rendered page. It
  loads `pattern-author` alongside rather than restating it.

The references are long and one pattern needs few of them, so each `SKILL.md`
opens its workflow with a table of what to read before each step and what only
in a named case, pointing rather than restating so every fact keeps one home.
The index gives the same choice to an agent reading over HTTP: each shipped
guide's entry carries a one-line `when` beside its word count, and a guide a
theme adds through the filter carries none.

The set is filtered before it is served (`pattern_builder_authoring_guides`), so
a theme can amend a shipped guide or add house rules. The filter deals in text
rather than file paths, so a supplied guide needs no filesystem access and no
caller can steer a read out of the plugin.

The guide index carries a `validate` block naming both validator abilities and
the three writes to run the check before (`create-pattern`, `update-pattern`,
`upload-pattern`): an agent that goes straight to a write reads no guide, so
the one step it cannot afford to skip sits where anyone asking what to read
sees it first. A test asserts the abilities it names are registered.

That block only reaches an agent that opened the index, though, and the one
text every MCP client puts in front of a model when it picks a tool is that
tool's description. So every ability with rules ends its description with the
same line naming the guide to read first, built in one place:
`Pattern_Builder_Abilities::GUIDES` maps each ability to its guides, and the
private `register()` every registration goes through appends
`guide_line()`. Reads that need no rules carry no line, and a test asserts
that every guide the map names is one the index can serve.

`copy-plugin-pattern` is a write and is deliberately not among them. The markup
it stores is the plugin's, copied as it stands, and the checks exist to stop an
agent persisting markup *it* composed — refusing a faithful copy over a fault in
somebody else's pattern would close the only route by which that pattern becomes
one this site can edit, while leaving the plugin's own copy rendering on the page
regardless.

### Keeping a copy

Reading a guide and keeping one are different jobs, and the ability answers
both. Reading is by name (`input[guide]`). Keeping is by skill
(`input[skill]`), which answers with a manifest — every file the skill is made
of, each with the path to write it at, its size, its checksum and a signed
`url` — and an `install` recipe. Files are addressed by path, because an
installer needs the layout: every one of these documents names the others by
relative path, so a copy that flattened them would have a dangling link on
every page. A path from a caller never reaches the filesystem; it is looked up
in the skill's own inventory, so nothing can be named that the site was not
already offering. One file can also be read through the ability
(`input[file]`), but that is for looking, not installing.

A theme's filtered guide may carry a `path` too, which puts it in a shipped
skill's layout and makes it install alongside the rest — a house reference
beside the plugin's own. It may not carry a path that leaves the directory the
installer chose: one that climbs out is dropped rather than corrected, leaving
that guide where a guide with no place in a layout belongs, in the flat index
and part of no skill.

**The recipe is the commands themselves, and they need no credentials.** It
downloads each file straight to disk, so the bytes never pass through the
agent's context — the same reason an image is uploaded as a request body
rather than base64 inside JSON — and ends by checking every file against the
sha256 of what was served, with `sha256sum -c -` or `shasum -a 256 -c -`, the
one form GNU, BSD and Perl's tools all read from stdin. The downloads are
signed links to `/pattern-builder/v1/skills/{skill}/{path}`, signed as preview
links are (an HMAC over the skill and an expiry an hour out, keyed from the
site's salts) and good for that skill only. They have to be: an agent connected
over MCP holds no password, because the Agents tab leaves it in the client's
own settings, and an agent that went to read it from there would be stopped by
its permissions, rightly. Before this, that left fetching every file through
the ability, about 190 KB per skill passing through the model twice; the
manifest an agent reads now is a few thousand tokens. The route answers with
the file itself rather than JSON (`serve_skill_download()`), so `curl -o`
writes exactly what the checksum covers.

The entry file is served with `frontmatter` above its prose: the name and
description that make the skill trigger, which the prose alone does not carry,
plus the site it came from and the skill's checksum. That is why the recipe's
check is against what was served, not against the manifest's per-file
checksums, which cover the prose alone.

A skill's **scripts travel with it**, and they are only the check no server can
make: block validity, the validator and its loader, run with Node where the
agent is. A check the site can make on every write does not travel. Slot errors
are refused by `create-pattern` and `update-pattern`, and whether blocks lay out
together is reported by them as warnings (below), so the skill carries no
script for either, and the guides tell an agent writing theme files directly
to compare slot names by hand and look at the rendered page. That also keeps
every skill free of PHP, which would need WordPress and WP-CLI beside the
agent, and of any link ending in `.php`, which some hosts answer themselves
instead of handing to WordPress. A test holds it there.

**Staleness is a checksum, not a version.** Each guide's checksum is taken over
the text the site serves, *after* `pattern_builder_authoring_guides` runs, and
rolled up per skill over paths and checksums so that adding or removing a file
moves it too. Taking it after the filter is the point: a version read off the
plugin cannot see a theme's house rules, and would call a copy made before them
current. `metadata.version` in each SKILL.md is the other half and is
hand-moved — the checksum says that something changed, the version says whether
it was a typo or the workflow — and it is versioned apart from the plugin, so a
patch release that does not touch the prose does not read as a change to it.

**A copy is replaced, never merged.** There is no conflict state and nothing
local to preserve, which is why the installed SKILL.md says that a change worth
keeping belongs in the theme behind that filter, where it reaches every agent
that asks the site rather than one checkout. `references/keeping-current.md`
carries the maintainer's half: bump the version in the same commit that changes
the guidance.

## Media and fonts

A pattern is markup plus the files it points at, and the markup half was the
only half an agent could supply. Four things fix that, and one is deliberately
**not an ability**: abilities are JSON in and JSON out, so a JPEG would have to
be base64 inside that JSON, which means the agent reading the file into its own
context and paying for it there.

`POST /pattern-builder/v1/assets` takes the bytes as the **request body** with
the filename in `Content-Disposition`, exactly as core's attachments controller
does (multipart works too, first file field wins) — so `curl --data-binary
@hero.webp` moves a file from disk to the site without it passing through the
agent at all. Discovery is the part that had to be designed: `find-media`
returns the route, its parameters, the limits and a runnable `curl` line in an
`upload` key of its own answer, and `add-asset` names it in its description.

The other three are ordinary abilities: `add-asset` for the forms that do fit
in JSON (SVG markup, or a `url` for the site to fetch), `add-placeholder-image`,
which draws a plain SVG locally because a pattern pointing at `placehold.co`
makes every page view fetch from somebody else's server, and `add-font`.

**What makes a font render is a `fontFamily` preset carrying `fontFace`**, not
the font library's posts: `wp_print_font_faces()` builds its `@font-face` rules
from `WP_Font_Face_Resolver::get_fonts_from_theme_json()`. A font installed
without a preset is a font nothing can use. So `add-font` writes the preset in
both destinations and additionally creates the `wp_font_family`/`wp_font_face`
posts for `user`, so the font is visible and removable where a person would
look. Files are self-hosted either way.

The source is core's own registered `google-fonts` collection and nothing else,
because every family in it is open-licensed and fetching a font from an
arbitrary URL is a licensing decision that is not an agent's to make.

SVG is accepted for the theme and refused for the media library — core does not
allow SVG uploads, and enabling it site-wide to satisfy one pattern is a poor
trade — and scrubbed of scripts, handlers, external references and doctypes on
the way in.
