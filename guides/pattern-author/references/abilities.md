# Asking a running site

Where a WordPress site is running and has Pattern Builder active, it can
answer the questions an agent otherwise has to guess at — what the design
system resolves to, which blocks exist, what patterns are already there — and
it can store finished markup. This is WordPress core's Abilities API, so the
same calling convention works for any plugin that registers abilities.

None of it is required. A pattern can be written and validated with nothing
but the theme's files. This is the shortcut where a site is available.

## Connecting

Abilities are permission-gated, so the call needs authentication. Application
Passwords (core, HTTP Basic) are the usual way:

```bash
# In wp-admin: Users → Profile → Application Passwords, or:
wp user application-password create <login> pattern-author --porcelain
```

```bash
export WP_URL=https://example.com
export WP_USER=jane
export WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'
```

**If application passwords appear unavailable**, the site is on plain HTTP.
Core only offers them over HTTPS or when `WP_ENVIRONMENT_TYPE` is `local` —
`development` is not enough. In a wp-env project that means a `config` entry:

```json
{ "config": { "WP_ENVIRONMENT_TYPE": "local" } }
```

## The calling convention

Two things about it are easy to get wrong, and both produce confusing errors.

**Input goes under an `input` key**, never at the top level:

```bash
# GET — nested query params
curl -u "$WP_USER:$WP_APP_PASSWORD" -G \
  --data-urlencode 'input[id]=my-theme/hero' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/get-pattern/run"

# POST — an "input" wrapper in the body
curl -u "$WP_USER:$WP_APP_PASSWORD" -X POST -H 'Content-Type: application/json' \
  --data '{"input":{"title":"Hero","content":"<!-- wp:group -->…<!-- /wp:group -->"}}' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/create-pattern/run"
```

Top-level fields give `input is not of type object`.

**The annotations pick the HTTP method.** A read-only ability is refused over
anything but GET; a write wants POST. `Read-only abilities require GET method`
means exactly that, not that anything is misconfigured.

Discover what a site offers with `GET /wp-abilities/v1/abilities` — the list is
filtered to what the authenticated user may run, and each entry carries its
input and output schema.

## The abilities

| Name | Method | Purpose |
|---|---|---|
| `pattern-builder/get-design-system` | GET | the three layers a pattern leans on, resolved across core, parent theme, child theme and the active variation: the presets it may reference (palette, gradients, spacing, font sizes and families), the `styles` it inherits (root, elements, blocks), the `blockStyles` it may apply (each with its class, whether it ships with WordPress, and — for one this theme defines as a partial — its definition), plus the `layout` widths and the site's whole-site style `variations` |
| `pattern-builder/list-block-types` | GET | every block registered here, with attribute schemas. Optional `input[namespace]=core` |
| `pattern-builder/list-patterns` | GET | patterns on the site, without markup but with their placement headers (`blockTypes`, `postTypes`, `templateTypes`, `inserter`, `viewportWidth`) and any `origin`, plus the pattern `categories` registered here — the only ones the inserter files a pattern under. Optional `input[source]=theme\|user\|all` |
| `pattern-builder/get-pattern` | GET | one pattern with its markup. `input[id]` is a namespaced name or a `wp_block` post ID |
| `pattern-builder/render-pattern` | GET | the front-end HTML a stored pattern produces, which presets it references and whether this site defines them, and preview URLs: `standalone`, `page`, and under `themes` the page render against `blank-theme` and `opinionated-theme`. `input[id]` as above |
| `pattern-builder/get-authoring-guide` | GET | these documents, as Markdown. No input for an index; `input[guide]` by name, or `all`. The index also carries a `validate` block naming what to run before storing anything |
| `pattern-builder/get-validator` | GET | the source of the markup validator, as files to write and run with Node |
| `pattern-builder/get-editor-scripts` | GET | this site's own block editor script URLs, in load order, for that validator |
| `pattern-builder/create-pattern` | POST | store finished markup. `title` and `content` required; `source` is `theme` (default) or `user`; also `name`, `description`, `categories`, `keywords`, `synced`, `blockTypes`, `postTypes`, `templateTypes`, `inserter`, `viewportWidth`. Refuses what PHP can see (below); a category nothing registered comes back under `unregisteredCategories` |
| `pattern-builder/update-pattern` | POST | replace an existing pattern. `id` and `content` required |
| `pattern-builder/add-design-tokens` | POST | add presets to the design system. `input[tokens]` is a list of `{type, slug, name, value}`; `input[destination]` is `theme` (default) or `user`. Only ever **adds** — a slug already here is skipped |
| `pattern-builder/set-global-styles` | POST | set what a block looks like before any pattern speaks: `input[styles]` is a theme.json `styles` subtree, `input[destination]` is `theme` (default) or `user`. **Replaces** at each property it names and changes the whole site, so read `get-design-system`'s `styles` first |
| `pattern-builder/add-block-style-variation` | POST | register a named look a pattern applies with a class: `slug`, `blockTypes`, `styles`, optional `title` and `description`. Answers with the `class` to put on the block. A block state (`:hover`) cannot live in the partial it writes; the answer's `note` gives the `set-global-styles` call that sets one |
| `pattern-builder/set-layout` | POST | set the widths every constrained band measures against: `contentSize`, `wideSize`, `useRootPaddingAwareAlignments`, `input[destination]` `theme` (default) or `user`. **Replaces** what it names |
| `pattern-builder/find-media` | GET | images the site already has, in the media library and in the theme's `assets/images`, each with the reference to write. Optional `input[search]`, `input[source]=all\|media\|theme`. The answer also carries how to upload a file |
| `pattern-builder/add-asset` | POST | store an image: `svg` markup you authored (with `filename`), or a `url` for the site to fetch. `destination` is `theme` (default) or `media`. **A file you hold goes to the route below instead** |
| `pattern-builder/add-placeholder-image` | POST | draw a plain placeholder into the theme: `width`, `height`, optional `label` |
| `pattern-builder/list-fonts` | GET | font families installable from the collection WordPress ships. Optional `input[search]`, `input[category]`; `input[family]` names one and answers with its weights, styles and whether it has a variable face |
| `pattern-builder/add-font` | POST | install a family and register its preset: `family`, optional `weights`, `styles`, `destination` (`theme` default, or `user`) |

### The one route that is not an ability

Abilities are JSON in and JSON out, so **no ability can accept a file**. A
JPEG, PNG, WebP or AVIF goes to a plain REST route instead, shaped exactly
like core's `POST /wp/v2/media` — the bytes are the request body and the
filename rides in a `Content-Disposition` header:

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  -H 'Content-Disposition: attachment; filename="hero.webp"' \
  -H 'Content-Type: image/webp' \
  --data-binary @hero.webp \
  "$WP_URL/?rest_route=/pattern-builder/v1/assets&destination=theme"
```

The file goes from disk to the site without passing through your context —
nothing is base64-encoded and nothing is read into a prompt. `destination` is
`theme` (default) or `media`; `alt` sets alternative text on a media library
attachment. An image over 2400px on its longest edge is resized down to it, so
use the `width` and `height` the answer reports rather than the ones you sent.

`find-media` returns these instructions in an `upload` key, so the route is
discoverable from inside the abilities rather than something to remember.
`references/assets.md` has the whole picture, fonts included.

### Adding to the design system

`add-design-tokens` is the answer to "this pattern needs a colour the theme
doesn't have". Inlining the value in the markup is the wrong answer: it opts
the pattern out of the site's palette, its dark mode and every future
restyle. Add the preset, then reference it by slug.

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  -H 'Content-Type: application/json' \
  -d '{"input":{"destination":"theme","tokens":[
        {"type":"color","slug":"kiln-red","name":"Kiln Red","value":"#b3391f"},
        {"type":"spacing","slug":"band","name":"Band","value":"clamp(3rem, 8vw, 7rem)"}
      ]}}' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/add-design-tokens/run"
```

Five types, each landing where the editor looks for it:

| `type` | Referenced as | Value |
|---|---|---|
| `color` | `{"backgroundColor":"kiln-red"}` + `has-kiln-red-background-color has-background` | a hex, `rgb()`/`hsl()`, `transparent`, `currentColor` |
| `gradient` | `{"gradient":"dusk"}` + `has-dusk-gradient-background` | a `linear-`, `radial-` or `conic-gradient()` |
| `spacing` | `var:preset\|spacing\|band` in a style attribute | a length, or `clamp()`/`calc()`/`min()`/`max()` of lengths |
| `fontSize` | `{"fontSize":"display"}` + `has-display-font-size` | as above |
| `fontFamily` | `{"fontFamily":"display-face"}` + `has-display-face-font-family` | a stack of family names |

**Two destinations.** `theme` (the default) writes the active theme's
`theme.json`, so the token travels with the theme and is versioned with it;
`user` writes Global Styles, which stays in the site's database and a person
can revert in the editor. A classic theme with no `theme.json` refuses the
first and names the second.

**It never overwrites.** A slug the site already defines keeps its own value
and comes back under `skipped`. That is the site telling you it already has an
answer — reference that slug rather than inventing `accent-2` beside it, and
call `get-design-system` first so you know what is there. `written` lists what
actually landed, by type, with the slugs as they were stored: a slug is put
through WordPress's own slug rules, so `Kiln Red!` becomes `kiln-red`, and
that is the name your markup has to use.

**No font files.** A `fontFamily` is a stack of names. Installing a webfont is
a different job, and this is not it — name families the site can already
serve, or say what needs installing.

### The other three design writes

`add-design-tokens` gives a pattern a vocabulary to *reference*. Three siblings
cover the rest of the design system, and `references/design-system.md` is the
long form.

`set-global-styles` decides what a block looks like when the pattern says
nothing — the root font size, the heading face, the link colour,
`elements.button`. It is the opposite of the tokens in every way that matters:
there is one `elements.link.color.text`, so setting it **replaces** what was
there and repaints every page at once, including ones you have not seen. Right
when the design is yours to set — recreating a site, establishing a look on a
blank theme, filling a gap. Wrong when you are writing a pattern for somebody's
existing site: there, read `get-design-system`'s `styles` and conform to them.

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  -H 'Content-Type: application/json' \
  -d '{"input":{"styles":{
        "typography":{"fontSize":"var:preset|font-size|medium"},
        "elements":{"link":{"color":{"text":"var:preset|color|primary"}}}
      }}}' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/set-global-styles/run"
```

`add-block-style-variation` registers a **named look applied with a class** —
a second kind of button, a card treatment. It answers with the `class` to put
on the block, and that class is all the markup carries. Reach for it whenever
you catch yourself about to set the same five attributes on every button: the
styling lives in one place, the editor offers it by name, and it applies only
where the class is.

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  -H 'Content-Type: application/json' \
  -d '{"input":{"slug":"button-secondary","title":"Secondary",
        "blockTypes":["core/button"],
        "styles":{"border":{"radius":"999px"},
                  "color":{"background":"var:preset|color|accent"}}}}' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/add-block-style-variation/run"
```

Check `get-design-system`'s `blockStyles` first. A variation with
`portable: true` is one WordPress itself ships — `is-style-outline` and the
rest — and hand-rolling what one of those already does is the mistake.

`set-layout` sets the two widths every constrained band measures against —
`contentSize` for ordinary children, `wideSize` for an `alignwide` one. These
are *settings* rather than presets or styles, which is why neither of the
other two can reach them, and they are the quietest thing to get wrong: a
pattern built against a 46rem measure on a site set to 800px does not fail,
it just wraps somewhere else, and the only alternative is restating the width
on every band in the markup.

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  -H 'Content-Type: application/json' \
  -d '{"input":{"contentSize":"46rem","wideSize":"80rem"}}' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/set-layout/run"
```

It also carries `useRootPaddingAwareAlignments`. Turn that on **before** giving
the root a left/right padding in `set-global-styles`: without it the padding
sits on the body, and every `alignfull` band is inset by it instead of reaching
the edges of the window.

**Neither accepts raw CSS.** WordPress does not sanitize a theme.json `css`
property; it gates it on the `edit_css` capability instead, because a string
that closes its own selector writes rules for the whole document. Both refuse
it, and the service will not store one either, so a variation carrying CSS
cannot travel with a pattern.

## The cloud, through the site's connection

Where the WordPress user you authenticate as has connected Pattern Builder to
a [patternbuilderwp.com](https://patternbuilderwp.com) account, seven more
abilities reach that cloud through the site. You never hold a cloud
credential: the site admin issued your application password, the account
holder made the connection, and every call runs as that user with that
connection. Without a connection each of these refuses with
`pattern_builder_not_connected` — "Connect Pattern Builder to your
patternbuilderwp.com account on this site first" — which is for a person to
do on the Pattern Builder screen, not for you.

A **collection** is the unit there: every cloud pattern is in exactly one,
every account has a locked private **Personal**, and a collection is public or
private as a whole. Installing puts each pattern under a local pattern
category named for its collection.

| Name | Method | Purpose |
|---|---|---|
| `pattern-builder/list-collections` | GET | the community's public and premium collections (`input[scope]=community`, the default; `input[search]`), or the account's own, Personal first (`input[scope]=mine`) |
| `pattern-builder/get-collection` | GET | one collection with its pattern summaries, each marked `installed` when this site already has it. `input[owner]` + `input[slug]` for a community collection, `input[id]` for one of the account's own |
| `pattern-builder/search-cloud-patterns` | GET | search public patterns by `input[search]`; each names its collection; `input[collection]=owner/slug` narrows to one |
| `pattern-builder/install-collection` | POST | every pattern of a collection, in one action: `owner`, `slug`, `destination` (`theme`\|`user`, default user), `tokens` (`add`\|`skip`, default add). Already-installed patterns are skipped, a failure is reported and the rest carry on; per-pattern results come back |
| `pattern-builder/install-cloud-pattern` | POST | one pattern by `id`, with the same `destination` and `tokens`; `source` is `directory` (default) or `library` |
| `pattern-builder/upload-pattern` | POST | a local pattern (`id`) or finished markup (`title` + `content`, stored here as a user pattern first) into `collection` — an id, or `personal` (the default), read on a pattern's first upload only. A pattern that references others takes them with it, and `members` lists everything that went up; a reference to a pattern this site does not have refuses the upload by name. Validate first, as for create-pattern |
| `pattern-builder/create-collection` | POST | a **private** collection: `name`, `description`. On a free account the service refuses with an upgrade message, since free accounts only make public collections and an agent never publishes; upload into Personal instead |

No ability makes a collection public, changes a visibility, or deletes a
collection. Those are the account holder's, in Pattern Builder.

An install can also be refused with `pb_cloud_needs_newer_wordpress`, which
names both the version the pattern needs and the one this site runs. Nothing
is written when it is — not the design tokens, not the pattern's
dependencies — so there is nothing to undo. Update WordPress, or install a
pattern that does not need the newer blocks.

```bash
# What is there.
curl -u "$WP_USER:$WP_APP_PASSWORD" -G --data-urlencode 'input[search]=hero' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/list-collections/run"

# Install one, as theme patterns.
curl -u "$WP_USER:$WP_APP_PASSWORD" -X POST -H 'Content-Type: application/json' \
  --data '{"input":{"owner":2,"slug":"starter-sections","destination":"theme"}}' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/install-collection/run"
```

## Guides the site itself carries

`get-authoring-guide` serves this documentation over the wire — the same
Markdown you are reading, so an agent whose harness has no notion of a "skill"
can install it wherever it does read instructions from.

It is worth calling **even when you already have these documents**, because a
site's set may not be this set. A theme can amend a shipped guide or add one of
its own through the `pattern_builder_authoring_guides` filter, and that is
where a project's house rules live: which blocks this build has settled on, the
copy voice, why a section is composed the way it is. Nothing here can know
those; the site can.

For a theme author, adding one is a filter:

```php
add_filter( 'pattern_builder_authoring_guides', function ( $guides ) {
	$guides['house-rules'] = array(
		'title'   => 'House rules for this theme',
		'content' => "# House rules\n\nSections are full width…",
	);

	// Or amend one that ships:
	$guides['block-vocabulary']['content'] .= "\n\nCore blocks only on this site.";

	return $guides;
} );
```

Guides are text rather than file paths on purpose: one added this way needs no
filesystem access, and no caller can steer a read outside the plugin. An entry
without `content` is dropped rather than served empty.

## What they will not do for you

**Nothing here generates a pattern.** No ability takes a prompt. The writes
take markup you have already composed — the judgement of what to build is
yours, and this is only somewhere to put the result.

**Nothing here decides whether a block is valid.** That is decided by
re-running the block's `save()`, which is JavaScript; no PHP endpoint can
answer it, and this one does not pretend to. What the site can do is hand you
the tool and its own block code — `get-validator` and `get-editor-scripts`,
described under **Validating from here** below. Run that before calling
`create-pattern`, or you will store markup that renders correctly and breaks
the moment anyone opens it in the editor.

**What the writes do refuse** is the set of failures PHP *can* see, every one
of them silent at render, each named in the error's `problems`:

| Refused | Because otherwise |
|---|---|
| attribute JSON that does not parse | WordPress reads it as *no* attributes; a slot that lost a brace is quietly no longer a slot |
| a heading whose tag disagrees with `level`, a list whose element disagrees with `ordered` | valid nowhere; every editor reports it |
| a block this site has not registered | it parses to `core/missing` and renders as a grey box |
| a `core/pattern` reference to nothing, or to the pattern itself | an unresolved reference renders as nothing at all; a self-reference is dropped as a loop |
| a `content` key naming no slot in the referenced pattern | an unknown key is ignored and the placeholder copy ships |
| a Pattern Overrides binding with no `metadata.name`, or on a block core cannot bind | the binding never fills |

So the order the guides prescribe is enforced: a page that references a
section cannot be stored before the section is. A pattern written earlier in
the same session counts, even before WordPress has registered it.

`render-pattern` is the nearest thing to a check the site can offer, and it
answers a different question: what HTML comes out. That is genuinely useful for
catching a missing supports class — the styling that silently didn't apply —
which the validator cannot see. It says nothing about whether the block is
valid.

## Validating from here

You need a JavaScript runtime — Node, plus `jsdom` to play the browser
WordPress's editor code expects as it loads. That is the one requirement no
server can lift, because `save()` is JavaScript and PHP cannot run it.

Everything else the site provides:

```bash
# 1. The validator. Write each file it returns into one directory.
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/get-validator/run"

# 2. This site's own block code, in the order it loads.
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/get-editor-scripts/run" \
  > scripts.json

# 3. Check.
npm i --no-save jsdom
node validate-pattern.mjs --scripts scripts.json pattern.html
```

The second call exists because WordPress serves its editor scripts to anyone
but not the order they load in: core's dependency manifest is a PHP file, so a
request for it executes and returns nothing at all. Only the site can answer
it — and getting it wrong is not loud. The JSX runtime reads `globalThis.React`
as it loads, so React arriving late costs every JSX call in the editor bundles,
with nothing but a missing function to show for it.

The scripts are cached after the first download (about 4MB) and their URLs
carry version strings, so an upgraded site fetches afresh.

## Without a site

Everything the reads provide is available from the files:

- design system → `theme.json`, `styles/*.json`, the parent theme's `theme.json`
- block types → assume core; check anything else against the plugins present
- existing patterns → the theme's `patterns/` directory

Which is the normal case when authoring in a theme repository, and works fine.
