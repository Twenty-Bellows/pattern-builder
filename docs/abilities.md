# Abilities — the agent interface

Pattern Builder registers **26 abilities** with WordPress core's Abilities API:
nineteen in `Pattern_Builder_Abilities` and seven more for the cloud in
`Pattern_Builder_Cloud_Abilities` (see [`cloud.md`](cloud.md)). Core exposes the
registry over REST at `wp-abilities/v1`, and an MCP bridge over that surface
gets these for free — which is why there is no bespoke agent API here.

Registration is conditional on `function_exists( 'wp_register_ability' )`: the
API postdates the plugin's 6.8 floor and nothing depends on it, so the REST
controller remains the baseline.

## Three behaviours of core's that are load-bearing

- `meta.show_in_rest` must be true, or the ability registers but is
  unreachable over REST.
- `meta.annotations` **select the HTTP method**: `readonly` is GET,
  `destructive` **and** `idempotent` together are DELETE, everything else is
  POST. So `update-pattern` is deliberately not marked destructive — that word
  means delete-like here, not "changes data".
- Input arrives under an `input` key, not at the top level: `input[key]=value`
  query args on a GET, `{"input":{…}}` in the body on a POST.

Agents authenticate with an Application Password, which core only offers over
HTTPS or when `WP_ENVIRONMENT_TYPE` is `local` (which is why `.wp-env.json`
sets it).

## The reads

| Ability | Answers |
|---|---|
| `get-authoring-guide` | The pattern-authoring documentation as Markdown. |
| `get-design-system` | The merged presets, the `styles` a pattern inherits, and the `blockStyles` registered here — each marked `portable`. |
| `list-block-types` | What is registered *on this site*; naming blocks also returns each one's `supports`. |
| `list-patterns` | Every pattern's summary with its placement headers, any `origin` or `cloud` reference, and the registered pattern categories. |
| `get-pattern` | One pattern. |
| `render-pattern` | The rendered HTML plus preview URLs — `standalone`, `page`, and `themes` against each lab theme. |
| `find-media` | The images a pattern can point at, plus how to upload one. |
| `list-fonts` | The font collection; naming a `family` describes that one, including whether it has a variable face. |
| `get-validator` | The validator script itself. |
| `get-editor-scripts` | The site's own editor script URLs, in load order. |

Two of these need their shape explained.

**`get-design-system` returns the whole system, not half of it.** Presets alone
left an agent able to see that a site defines a colour called `ink` and not
that the theme paints every heading with it — and the safe move under that
blindness is to over-specify, restating the font on each heading and the whole
button on each button, none of which then follows the destination. Each block
style is marked `portable`: true for one declared in a block's own `block.json`,
which ships with WordPress, false for one this site registered, whose
definition has to be carried.

**`list-block-types` reports supports because no validator can.** The classes a
block's saved markup must carry come from filters that only run inside an
editor. Supports is about two and a half times the size of everything else in a
listing, so naming blocks is what turns it on and a browse stays a catalogue; a
name this site does not have comes back under `unknown`.

## The writes

| Ability | Does |
|---|---|
| `create-pattern`, `update-pattern` | Take **finished markup** and persist it. |
| `add-design-tokens` | Writes presets into the theme's `theme.json` or Global Styles. |
| `set-global-styles` | Sets the `styles` a pattern inherits. |
| `add-block-style-variation` | Registers a named look as a `styles/{slug}.json` partial. |
| `set-layout` | `settings.layout.contentSize`, `wideSize`, `useRootPaddingAwareAlignments`. |
| `add-asset`, `add-placeholder-image`, `add-font` | Put a file where a pattern can reach it. |

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
  kind of button without fossilising one into the markup.

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

A reference resolves through the registry *or* the theme's files, because a
pattern written a request ago is registered only on the next `init` — which is
what lets a page be stored in the same session as its sections, and what
enforces the bottom-up order the guides prescribe.

Both writes report a category nothing on the site registered under
`unregisteredCategories`.

## Two things deliberately absent

**Nothing takes a prompt.** An `execute_callback` that turned a description
into a pattern would need a model behind it, which is the inference business
this plugin is out of. The judgement lives in whatever agent is calling.

**Block validity cannot be offered and is not.** `save()` is JavaScript, so the
one check most worth having is the one no server can perform. The agent runs it
before calling `create-pattern`.

## The validator

`guides/pattern-author/scripts/validate-pattern.mjs` loads **the site's own
WordPress** (`wp-core.mjs`), so the answer comes from the block library that
site actually runs — the only version whose opinion counts. Nothing to install
but `jsdom`; `--npm` falls back to `node_modules` where there is no install to
find.

The script order comes from core's generated `script-loader-packages.php`, and
the files go in as real `<script>` elements because these bundles are
strict-mode and a strict `eval` keeps its `var` declarations to itself, which
loses `ReactJSXRuntime` and every JSX call with it. Bundling the npm packages
instead would ship 21MB, because every block's `save()` imports
`@wordpress/block-editor` and core keeps each support's `addSaveProps` in the
same module as that support's editor UI.

An agent that only reaches the site over HTTP gets the same check from
`get-validator` and `get-editor-scripts`. That second one is necessary because
WordPress serves those files to anyone but not the graph, and the order is not
forgiving: the JSX runtime reads `globalThis.React` as it loads, so React
arriving late costs every JSX call in the bundles, silently.

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

The set is filtered before it is served (`pattern_builder_authoring_guides`), so
a theme can amend a shipped guide or add house rules. The filter deals in text
rather than file paths, so a supplied guide needs no filesystem access and no
caller can steer a read out of the plugin.

The guide index carries a `validate` block naming both validator abilities and
the three writes to run the check before (`create-pattern`, `update-pattern`,
`upload-pattern`): an agent that goes straight to a write reads no guide, so
the one step it cannot afford to skip sits where anyone asking what to read
sees it first. A test asserts the abilities it names are registered.

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
