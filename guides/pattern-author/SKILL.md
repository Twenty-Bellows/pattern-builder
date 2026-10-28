---
name: pattern-author
description: Write WordPress block patterns — hero sections, pricing tables, FAQ lists, CTA bands, testimonials, page layouts — as valid block markup that uses the theme's own design tokens, factored into reusable parts rather than written out longhand. Use this whenever the task involves creating, editing, or composing a block pattern, a theme pattern file, a reusable block, or a section of a block-theme page. Hand-written block markup is invalid far more often than it looks, because invalid markup renders perfectly on the front end and only breaks when someone opens the editor — so reach for this skill even for a "quick" pattern, and especially before writing markup into a theme. To rebuild a design that already exists somewhere — a site, a Figma file, a screenshot — load the design-reproduction skill as well; it decides what to build and how faithfully, and leans on this one to build it.
metadata:
  version: 1.19.0
---

# Authoring block patterns

## Why this is not writing HTML

WordPress decides a block is valid by re-running its `save()` against the
stored attributes and diffing the result against the markup on disk. `save()`
is JavaScript. So **markup you write by hand can render perfectly and still be
broken** — the front end prints what is stored, the editor re-derives it and
offers to discard your markup. Nothing warns you first.

Two consequences govern everything below:

- **Generate the markup; don't type it.** The block library will serialize it
  for you, correctly, for the version you are targeting (step 5). That is the
  whole reason you can be confident in a pattern without opening a browser.
- **Validate before placing it** (step 6). Not optional, and not doable by
  reading the markup or looking at the front end.

`references/block-markup.md` has the failure modes in detail, including the
quiet one: WordPress reads malformed attribute JSON as *no* attributes, and for
many blocks that produces byte-identical output — so a lost brace leaves a
valid block silently stripped of everything its attributes were doing.

## Order of operations

Each scale contains the ones before it, and every step settles values the later
ones reference.

- **One pattern:** orient → vocabulary → kind → factor → generate → validate → place.
- **A page:** design system, then *elements*, then the *sections* referencing
  them, then the *page* referencing those. Bottom-up, because a section cannot
  be written until its elements exist by name.
- **A site:** settle the design system as **layout → tokens → styles → block
  style variations**, then the patterns bottom-up, then the template parts and
  templates that place them, then the pages.

Layout first because every band's markup has to agree with it; tokens before
styles because a style referencing an unresolvable slug renders as nothing.

## Workflow

### What to read, step by step

The references below are long, and a single pattern needs few of them. Read
what a step requires before it, the rest only in the case named, and nothing
else. Each fact lives in one of them; this table only points.

| Step | Read before it | Only when |
|---|---|---|
| 1. Orient | nothing more | `references/abilities.md` if you reach the site over HTTP or through its `pattern-builder-*` MCP tools; `references/changing-patterns.md` if the site already has patterns; `references/design-system.md` if you set color, type, spacing or layout beyond the shared presets, or install a design system |
| 2. Vocabulary | nothing more | `references/block-vocabulary.md` for a block beyond paragraph, heading, image, button, group and columns, or a destination other than this site |
| 3. Kind | nothing more | `references/pattern-kinds.md` for any kind but a plain design pattern; `references/design-content-split.md` for a synced pattern with slots or the page that fills it |
| 4. Factor | `references/composition.md` for anything bigger than one section | |
| 5. Generate | nothing more | `references/block-markup.md` if you cannot run Node; `references/assets.md` if the pattern needs an image or a typeface |
| 6. Validate | `references/design-content-split.md` if the pattern has slots or fills them | |
| 7. Place | nothing more | `references/pattern-kinds.md` for the headers, and how to write a description and keywords someone can find it by; `references/templates.md` if it belongs in a template or template part, or you are building a whole site |

`references/keeping-current.md` is for maintaining these guides, not for
writing a pattern.

### 1. Orient

Never invent colors, spacing, or font sizes — and never reference a preset the
site lacks, which fails just as quietly (an unresolved slug renders as no
styling at all).

On a running site, ask it. This resolves core, parent theme, child theme and
the active style variation. If you have the site's `pattern-builder-*` MCP
tools, call `pattern-builder-get-design-system` and use those tools for every
call this skill shows as curl — they need no credentials, wrapper or method,
and they are the only pattern tools on the site that check what you write.
Otherwise:

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/get-design-system/run"
```

Worth two more calls: `list-block-types` before using anything non-core (markup
for a block the site lacks parses to `core/missing`), and
`get-authoring-guide` for house rules a theme has added — which blocks this
build has settled on, how its copy reads. Over HTTP, input goes under an
`input` key: `input[key]=value` on a GET, `{"input":{…}}` in a POST body.
`references/abilities.md` has the full set, and what still needs curl when
you have the tools.

Otherwise read `theme.json` (`settings.color.palette`,
`settings.spacing.spacingSizes`, `settings.typography.fontSizes`,
`settings.layout`), plus `styles/*.json` and the parent theme. Then read two or
three existing patterns — they carry house style better than any description.

`references/design-system.md` covers the three layers a pattern leans on and
which slugs are actually safe: the short version is a role for every color
(`base` and `contrast` first, never a hue), five font sizes from `x-small` to
`x-large` with heading sizes on `styles.elements`, and spacing steps `40`–`60`.
Check anything else before using it. It also says the thing hardest to see from
markup: what the site already styles is what the pattern inherits, so restating
it is how a pattern stops adapting.

**Then look for the pattern before you write it.** On a site that already has
patterns, what you are asked for is often close to one that exists. Search
`list-patterns` (`search`, `keywords`), read what comes back, and decide
whether to reuse it, add a slot or a variation to it, copy it, or write a new
one. Before changing one that pages use, `list-pattern-usage` says what the
change reaches. `references/changing-patterns.md` has the order and the cost.

### 2. Establish the vocabulary

Ask where the pattern is going — and say which answer you assumed if nobody
told you.

- **Core blocks only** — anything that leaves this site: the pattern
  directory, a shared cloud library, a theme others install. This is the
  default when the destination is unclear.
- **patternbuilderwp.com is narrower still** — no code, raw HTML, embeds or
  non-image media.
- **Core + the theme's own blocks and styles** — a pattern shipping inside that
  theme; they travel together. A registered block style
  (`{"className":"is-style-card"}`) usually beats a custom block anyway.
- **Core + installed plugins** — only for patterns staying on this site.

`references/block-vocabulary.md` has the rule in full, the current core
vocabulary by purpose, and which block is right for which job.

### 3. Decide what the pattern is *for*

The job settles where it can be stored, which headers place it, and whether it
appears in the inserter.

| The user wants… | Kind | What it fixes |
|---|---|---|
| a section to drop in and edit | **Design Pattern** | unsynced; theme or database |
| a component whose design stays consistent everywhere | **Synced Design Pattern** | `Synced: yes`; theme or database |
| a starting layout for new pages | **Page Pattern** | `Block Types: core/post-content` + `Post Types` |
| a design for an empty Query Loop, Cover, etc. | **Block Starter Pattern** | `Block Types: <that block>` |
| a whole archive/404/home layout | **Template Pattern** | `Template Types`, `Inserter: no`, wide viewport |
| a header or footer design | **Template Part Pattern** | `Block Types: core/template-part/header\|footer` |

A kind is a starting point, not a stored property; everything stays editable
afterwards. `references/pattern-kinds.md` has each in full.

**The four starter kinds are always theme patterns** — their placement lives in
file headers and a `wp_block` has nowhere to put them. A request wanting a
database pattern *and* wanting WordPress to offer it for new pages is asking
for two incompatible things; say so rather than silently picking one.

**Synced Design Pattern + Page Pattern is the design/content split** — the
synced pattern owns the markup and carries placeholder copy, the page pattern
owns the words and fills the slots. It is the default wherever the runtime is
present, and every site these abilities run on has Pattern Builder. Read
`references/design-content-split.md` before writing either; those failure modes
are silent.

### 4. Factor before you write

Skipping this does not look like a mistake — it looks like a finished page. A
pattern's value is exactly its reusability: markup spelling out one business's
146 menu items is that business's *content* wearing a pattern's clothes.

Produce an **inventory**. Not a mental note — a table, because a step with an
artifact is a step you can see was skipped:

| shape | occurrences | leaves that differ | name | level |
|---|---|---|---|---|
| `group > columns > [image, group > [row > [p, p], p]]` | 146 | name, price, description, image | menu item | element |
| `group > [rule, heading, p, group > columns…]` | 24 | heading, blurb | menu section | section |

Three tests fill it in; only the middle needs judgment.

1. **What repeats?** Reduce the markup to a *shape* — block names and
   attributes, all text and URLs stripped. Any shape occurring twice is a
   candidate. Mechanical: if you are about to write the same subtree twice, you
   found one. Be especially alert to the output of a loop.
2. **Does it have a name?** A candidate becomes a pattern when a domain noun
   phrase fits — *menu item*, *dish card*, *hours row*. If only a structural
   name fits — *the group wrapper* — it is markup, not a pattern. This is the
   test that stops the first one shattering a page into confetti.
3. **What are the slots?** Given N occurrences of a shape, the slots are
   exactly the leaves whose content differs. You compute this rather than
   decide it.

Then place each row at its level:

| Level | What it is | What it contains |
|---|---|---|
| **Element** | the smallest named repeated thing | markup, with slots |
| **Section** | a full-width band | a heading and *references to elements* |
| **Page** | the whole page | *references to sections*, plus its own markup |

**Nesting is not limited to one hop** — a section may reference other sections.
The failure to avoid is stopping after one level: bands as patterns, everything
inside written out longhand.

Three bounds. **Pattern Overrides binds `core/paragraph`, `core/heading`,
`core/image` and `core/button`** — plus `core/list-item` from WordPress 6.9 —
so a slot must land on one of those; you cannot slot "some blocks". **Don't
factor what does not repeat**: a band appearing once is a section pattern
because it is a named part of the page, not because it repeats. And **don't
factor a single styled block** — one paragraph with a class on it is not a
pattern, it is a block style variation applied where the paragraph goes, and a
page pattern is allowed to hold that paragraph directly.

`references/composition.md` has the worked example.

### 5. Generate the markup

Block markup is HTML comments wrapping HTML, and the attributes and the HTML
have to agree exactly:

```html
<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size">A lede that sets it up.</p>
<!-- /wp:paragraph -->
```

**Let the block library write it.** It runs the real `save()` with the real
supports filters, so the output is valid by construction *and* carries every
support-contributed class correctly — which is the entire class of failure the
validator cannot see, and the reason this is faster than drafting and
iterating on errors:

```js
import { loadWordPressBlocksFromUrls } from '<skill>/scripts/wp-core.mjs';
const { scripts: urls, wordpress: version } = await (
        await fetch( `${ site }/?rest_route=/pattern-builder/v1/editor-scripts` )
).json();
const core = await loadWordPressBlocksFromUrls( urls, { version } );
const { createBlock, serialize } = core.window.wp.blocks;

console.log( serialize( [
        createBlock( 'core/group', { align: 'full', backgroundColor: 'contrast' }, [
                createBlock( 'core/heading', { level: 1, content: 'The headline' } ),
                createBlock( 'core/paragraph', { content: 'A sentence that sets it up.' } ),
        ] ),
] ) );
```

`site` is the one in `metadata.source` above; that route needs no credentials,
and the validator asks it the same way. Against a local install, use
`loadWordPressBlocks( wpRoot )` instead. Outside the dozen
common blocks this is the *only* reliable route — the accordion family saves a
`role="group"`, an `has-icon has-icon-right` pair, a `__toggle-title` span and
an icon span, and nothing but the block's own `save()` will tell you that.

Three things the serializer does that you must allow for:

- **It keeps `content` on `core/pattern` only because the loader declares it.**
  That attribute is Pattern Builder's, not core's; plain `@wordpress/blocks`
  drops it from `parse()` and `serialize()` both, silently, and a generated
  reference comes out with its slots gone.
- **It escapes a PHP tag.** Serialize with a plain marker and substitute
  afterwards: `serialize( … ).replace( /HERO_SRC/g, reference )`.
- **It drops an attribute the block does not have**, silently — the same answer
  the editor would give. `textAlign` on `core/heading` is the one to know: on
  block library 10.5 it lives under `style.typography.textAlign`, and passed at
  the top level it vanishes along with the class you expected.

Reference presets by slug, never by value — and note the two spellings:
`"var:preset|spacing|50"` inside attribute JSON,
`var(--wp--preset--spacing--50)` in the `style` attribute's actual CSS. The
shorthand inside CSS renders as no spacing at all.

**If you cannot run Node**, hand-write it against the attribute-to-class table
in `references/block-markup.md`. Every row there is a class the supports
filters would have added for you, and getting one wrong leaves the block
*valid* with the styling silently not applied.

Write real placeholder copy, not lorem ipsum: copy of a plausible length is
what tells you the layout works, and in a design pattern it is what shows in
the inserter preview.

### 6. Validate — every time, before placing the file

```bash
node <skill>/scripts/validate-pattern.mjs path/to/pattern.php
```

It reports three things, and only the first is the one people expect:

- **INVALID** — no version of the block ever wrote this. The editor will say
  "unexpected or invalid content".
- **OLD FORM** — matches a *deprecated* version. The editor opens it happily
  and migrates it, so it never looks broken there, but the file is missing what
  the block writes today — nearly always a supports class, so the style
  silently does not apply on the front end.
- **DROPPED ATTRIBUTE** — that migration threw away something you wrote.

The last two are the dangerous ones: the pattern renders, the editor is quiet,
and the design is just wrong. Fix and re-run; an empty report is the only
acceptable result. A `core/missing` means the site lacks that block, or the
markup has a typo.

It validates against **the WordPress the pattern is destined for, not npm**:
the editor's own block code, at the version that site runs. Where it gets that
code needs nothing from you in the usual cases:

| Situation | How |
|---|---|
| Working inside a WordPress install | Nothing: it finds the install itself |
| WordPress elsewhere on disk | `--wp /path/to/wordpress`, or `WP_PATH=…` |
| This skill installed from a site | Nothing: it asks the site in `metadata.source` above for its editor scripts |
| Only `get-validator`'s copy of the script | `--site https://the-site.example` |
| No WordPress anywhere | `--npm`, using `node_modules` |

Asking the site happens on every run, so there is nothing to save, place or
refresh: after the site updates WordPress, the next run checks against the new
code. The scripts themselves are cached by URL in the system's temp folder, and
a cache unused for a week is removed.

It needs a DOM either way, from `jsdom`, installed once for the machine rather
than in each project: `npm i --prefix ~/.cache/pattern-builder jsdom`. A
project that already has `jsdom` is used first, and when neither is there the
validator prints the exact command for this machine. It handles a theme
pattern's PHP header and inline `<?php echo esc_url( … ); ?>`, and takes `-` to
read from stdin. If the project has its own validator, prefer it.

**Then check that it lays out.** Validation answers a question about one
block; a section is a relationship *between* blocks, and nothing above can see
one. The site checks this when you store through `create-pattern` or
`update-pattern`, following `core/pattern` references to the patterns they
place, and reports it under `warnings` with a `composition_` code: a flex or
grid container whose children cannot size — most often a card pattern written
with `{"layout":{"type":"constrained"}}` at its root, which fills the whole
track, so six references render as six full-width cards stacked vertically.
That markup is entirely valid, every token resolves, and nothing else reports
it, so read the warnings after every write and fix one before moving on. For
theme files written directly, look at the page in `render-pattern`'s `page`
preview instead.

**If the pattern uses Pattern Overrides slots, check that every value lands.**
Slot problems are invisible to block validation in both directions, and both
ship the wrong words with no error anywhere. Storing through `create-pattern`
or `update-pattern` refuses them: a `content` key that names no slot in the
referenced pattern, a binding with no `metadata.name`, a binding on a block
core cannot bind. Writing theme files directly, nothing checks them for you:
match every `content` key to a `metadata.name` yourself, copying the names
rather than retyping them, then open the page in `render-pattern`'s `page`
preview and look for placeholder copy where a value should be. The rules are in
`references/design-content-split.md` — malformed attributes there leave a
*valid* block behind.

**Then use it, not only look at it.** Everything above is about structure and
values. None of it notices a navigation overlay that will not open, a details
block that will not toggle, a focus outline the design took away, or text that
fails contrast on its band, and every one of those passes. Open the `signed`
page URL `render-pattern` hands back, which opens in any browser, and:

- click what moves: the navigation overlay open and closed, details, search;
- Tab through it: focus visible on every link and button, a sensible order,
  and Escape closing an open overlay;
- read the console, where an error is a defect;
- check text contrast against the nearest painted background, AA at least, on
  every band rather than only the first;
- do it at about 390px, 768px and 1440px wide, and just above the width where
  the navigation collapses, which is where it most often overflows.

A pattern with nothing that moves has less to click, but focus and contrast
apply to every one.

### 7. Place it

A theme pattern is a PHP file in `patterns/` with a header comment:

```php
<?php
/**
 * Title: FAQ Entry
 * Slug: my-theme/faq-entry
 * Description: FAQ entry with one question and its answer above a hairline, repeated to build a list of questions.
 * Categories: my-theme_elements
 * Synced: yes
 */
?>
<!-- wp:group ... -->
```

`Title` and `Slug` are required and the slug must be namespaced.
`references/pattern-kinds.md` lists which other headers each kind needs, and
how to write a `Description` and `Keywords` someone can find the pattern by.
`Categories` names slugs the site has **registered** — core's own, or the
theme's through `register_block_pattern_category()`; an unregistered slug files
the pattern under Uncategorized, where nobody looks.

**A link to a page of the site is `home_url()`, never the site's address.** In
a theme pattern write `href="<?php echo esc_url( home_url( '/about/' ) ); ?>"`,
or `home_url()` alone for the home page, so the link follows the theme to any
site. A navigation link takes the same call in its `url` attribute. A theme
save turns a link written as this site's address into the call itself.

**A file the theme carries is `get_theme_file_uri()`, never its URL:**
`<?php echo esc_url( get_theme_file_uri( 'assets/images/logo.svg' ) ); ?>`.
That's the `reference` `find-media` and `add-asset` hand back. It finds the file
in a child theme or its parent, and a theme save turns a URL into the theme back
into this call. `get_template_directory_uri()` and
`get_stylesheet_directory_uri()` with a literal path are accepted as well, and
the next save respells them. Those calls, localized text and the home link are
the only PHP a theme pattern may carry. Anything else, including a path built
from a variable or a call without `esc_url()`, is refused by `create-pattern`
and opens read-only in the editor.

Write the file directly when you have filesystem access. Against a running
site, `pattern-builder/create-pattern` stores finished markup — it refuses what
PHP can see (attribute JSON that does not parse, a heading contradicting its
level, a missing block, a reference to nothing, a slot key naming no slot) and
enforces the bottom-up order, but it cannot see block validity. Validate first
either way.

A page pattern is not a page. To make one:

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" -H 'Content-Type: application/json' \
  -d '{"title":"About","slug":"about","status":"publish",
       "content":"<!-- wp:pattern {\"slug\":\"my-theme/page-about\"} /-->"}' \
  "$WP_URL/?rest_route=/wp/v2/pages"
```

**To look at a pattern you need no page at all.**
`pattern-builder/render-pattern` returns the HTML plus a `page` URL rendering
it inside the resolved template, using a stand-in post primed into the object
cache for one request and never written — the only way to see whether an
`alignfull` band escapes the content width, and it reports any preset the
markup references that the site does not define under `tokens.undefined`.
Create a real page only when the page is the deliverable.

## Composing patterns from other patterns

```html
<!-- wp:pattern {"slug":"my-theme/section-intro"} /-->
```

This is the normal way to build above the smallest scale, not an optimization.
Step 4 works out what the parts are. The cost of a reference is one hop of
indirection; the cost of avoiding one is a copy, and every copy is a place the
design must be changed again.

Two mechanics: the block is **self-closing** (`/-->`), and a referenced pattern
must be registered on the site it renders on — **an unresolved reference
renders as nothing at all**, with no error anywhere. To fill its slots,
`core/pattern` takes a `content` attribute; see
`references/design-content-split.md`.

## Turning a screenshot into a pattern

Load the `design-reproduction` skill. It owns reading a design out of a
source — including the structural measurements (alignment, band width, column
ratios) that go straight into attributes with nothing downstream to catch them
wrong — and what fidelity an image can honestly support.

## When a pattern needs an image or a typeface

A reference that does not resolve fails quietly: a dead `src` shows a broken
image, a `fontFamily` naming no preset renders in the default face. So the
files come first, and you write the reference the site hands back —
`pattern-builder/find-media` lists the media library *and* the theme's own
`assets/images`, each with the exact `reference` to use verbatim.

| You have | Use |
| --- | --- |
| A file (JPEG, PNG, WebP, AVIF) | `POST /pattern-builder/v1/assets` — bytes as the request body. An ability cannot carry binary |
| A URL the user pointed you at | `add-asset` with `url`; the site fetches it |
| Something you can draw | `add-asset` with `svg`, or `add-placeholder-image` |
| Nothing yet | `add-placeholder-image`. Never a remote placeholder service |
| A typeface | `add-font` — installs the files *and* the preset that makes them render |

`references/assets.md` has the requests and the parameters.

## When the design system lacks something

First check that is what is happening. **Picking the nearest existing token is
not a gap** — it is the normal case, needs nobody's permission, and is what to
do. Name the one you picked and what it stood in for.

A real gap is when nothing is close. Then propose it — which token is missing,
what you'd call it, what value — and **build with the nearest existing token
meanwhile**, putting the proposal in the handoff. Stopping to ask hands back
the work you were given, and the answer costs nothing after the fact. Never
inline the value in the markup: that opts the pattern out of the site's
palette, its dark mode and every future restyle. Where the user has already
said to extend the system, add it with `pattern-builder/add-design-tokens`
(or `theme.json` directly) **before** the pattern that references it.

## References

- `references/pattern-kinds.md` — the six kinds and the headers each writes
- `references/block-vocabulary.md` — which blocks are allowed where, and the core vocabulary by purpose
- `references/design-system.md` — tokens, inherited styles, block style variations, and which names travel
- `references/block-markup.md` — the attribute-to-markup contract per block, for hand-writing and for debugging
- `references/composition.md` — factoring into elements, sections and pages
- `references/design-content-split.md` — Pattern Overrides slots and their silent failures
- `references/changing-patterns.md`: reusing, extending or copying a pattern that already exists, and what a change to one costs
- `references/assets.md` — images and fonts
- `references/templates.md`: the theme's templates and template parts, what goes in them, and how a write treats a Site Editor customization
- `references/abilities.md` — asking a running site for what it has
- `references/keeping-current.md` — bringing these guides to a new WordPress release
- The `design-reproduction` skill — rebuilding a design that already exists

## Staying current

If you are reading this from a project rather than from the site, it is a
copy. Which site it came from is in the front matter above, under
`metadata.source`, beside the checksum the whole skill hashed to when it was
taken.

That site is the original, and not only because it shipped these files: a
theme amends them through the `pattern_builder_authoring_guides` filter, so
what a site serves is its house rules as well as the plugin's documents, and
those are the part general documentation cannot know.

To see whether the copy still matches, ask that site's `get-authoring-guide`
for its index — the `pattern-builder-get-authoring-guide` tool when you are
connected over MCP — and compare the `checksum` it lists under `skills` for
`pattern-author` with the one recorded above.

Different, ask the same ability for the skill, `{"skill": "pattern-author"}`,
and run the commands its `install` answers with, as they stand. They download
every file by signed link, straight to disk, and need no password: never read
one out of your client's configuration for this. It overwrites, which is the
whole contract — there is nothing here to merge and no local edit to keep. A change worth keeping belongs in the theme, behind
that filter, where it reaches every agent that asks the site instead of this
one checkout.

Nothing here is worth checking on a schedule. Check when the site is to hand
and something reads as off — prose describing an ability the site does not
offer is the clearest sign, and the cheapest one to confirm.
