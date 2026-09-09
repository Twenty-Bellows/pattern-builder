---
name: pattern-author
description: Write WordPress block patterns — hero sections, pricing tables, FAQ lists, CTA bands, testimonials, page layouts — as valid block markup that uses the theme's own design tokens, factored into reusable parts rather than written out longhand. Use this whenever the task involves creating, editing, or composing a block pattern, a theme pattern file, a reusable block, or a section of a block-theme page. Hand-written block markup is invalid far more often than it looks, because invalid markup renders perfectly on the front end and only breaks when someone opens the editor — so reach for this skill even for a "quick" pattern, and especially before writing markup into a theme. To rebuild a design that already exists somewhere — a site, a Figma file, a screenshot — load the design-reproduction skill as well; it decides what to build and how faithfully, and leans on this one to build it.
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
  style variations**, then the patterns bottom-up, then the pages.

Layout first because every band's markup has to agree with it; tokens before
styles because a style referencing an unresolvable slug renders as nothing.

## Workflow

### 1. Orient

Never invent colors, spacing, or font sizes — and never reference a preset the
site lacks, which fails just as quietly (an unresolved slug renders as no
styling at all).

On a running site, ask it. This resolves core, parent theme, child theme and
the active style variation:

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/get-design-system/run"
```

Worth two more calls: `list-block-types` before using anything non-core (markup
for a block the site lacks parses to `core/missing`), and
`get-authoring-guide` for house rules a theme has added — which blocks this
build has settled on, how its copy reads. Input goes under an `input` key:
`input[key]=value` on a GET, `{"input":{…}}` in a POST body.
`references/abilities.md` has the full set.

Otherwise read `theme.json` (`settings.color.palette`,
`settings.spacing.spacingSizes`, `settings.typography.fontSizes`,
`settings.layout`), plus `styles/*.json` and the parent theme. Then read two or
three existing patterns — they carry house style better than any description.

`references/design-system.md` covers the three layers a pattern leans on and
which slugs are actually safe: the short version is `base` and `contrast` for
colour, the `small`…`xx-large` ladder for type, and spacing steps `40`–`60`.
Check anything else before using it. It also says the thing hardest to see from
markup: what the site already styles is what the pattern inherits, so restating
it is how a pattern stops adapting.

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

Three tests fill it in; only the middle needs judgement.

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
| **Page** | the whole page | *references to sections* |

**Nesting is not limited to one hop** — a section may reference other sections.
The failure to avoid is stopping after one level: bands as patterns, everything
inside written out longhand.

Two bounds: **Pattern Overrides binds `core/paragraph`, `core/heading`,
`core/image` and `core/button`** — plus `core/list-item` from WordPress 6.9 —
so a slot must land on one of those; you cannot slot "some blocks". And **don't
factor what does not repeat**: a band appearing once is a section pattern
because it is a named part of the page, not because it repeats.

`references/composition.md` has the worked example.

### 5. Generate the markup

Block markup is HTML comments wrapping HTML, and the attributes and the HTML
have to agree exactly:

```html
<!-- wp:heading {"level":2,"fontSize":"x-large"} -->
<h2 class="wp-block-heading has-x-large-font-size">Section title</h2>
<!-- /wp:heading -->
```

**Let the block library write it.** It runs the real `save()` with the real
supports filters, so the output is valid by construction *and* carries every
support-contributed class correctly — which is the entire class of failure the
validator cannot see, and the reason this is faster than drafting and
iterating on errors:

```js
import { loadWordPressBlocksFromUrls } from '<skill>/scripts/wp-core.mjs';
const core = await loadWordPressBlocksFromUrls( urls, { version } );
const { createBlock, serialize } = core.window.wp.blocks;

console.log( serialize( [
        createBlock( 'core/group', { align: 'full', backgroundColor: 'contrast' }, [
                createBlock( 'core/heading', { level: 1, fontSize: 'xx-large' } ),
                createBlock( 'core/paragraph', { content: 'A sentence that sets it up.' } ),
        ] ),
] ) );
```

`urls` comes from `pattern-builder/get-editor-scripts`, or
`loadWordPressBlocks( wpRoot )` against a local install. Outside the dozen
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

It validates against a **WordPress install, not npm** — every install carries
the editor's block code under `wp-includes/js/dist`, and it is the exact
version the pattern is destined for. It finds the install itself when you are
working inside one. Otherwise:

| Situation | How |
|---|---|
| WordPress elsewhere on disk | `--wp /path/to/wordpress`, or `WP_PATH=…` |
| Only HTTP access to the site | `get-validator` for the script, `get-editor-scripts` > `scripts.json`, then `--scripts scripts.json` |
| No WordPress anywhere | `--npm`, using `node_modules` |

It needs a DOM either way: `npm i --no-save jsdom`. It handles a theme
pattern's PHP header and inline `<?php echo esc_url( … ); ?>`, and takes `-` to
read from stdin. If the project has its own validator, prefer it.

**Then check that it lays out.** Validation answers a question about one
block; a section is a relationship *between* blocks, and nothing above can see
one:

```bash
node <skill>/scripts/check-composition.mjs patterns/
```

It resolves `core/pattern` references against the theme's own files and reports
any flex or grid container whose children cannot size — most often a card
pattern written with `{"layout":{"type":"constrained"}}` at its root, which
fills the whole track, so six references render as six full-width cards stacked
vertically. That markup is entirely valid, every token resolves, and no other
check in this workflow reports a thing. Static and immediate; it needs no
browser and no rendering.

**If the pattern uses Pattern Overrides slots, render it too.** Slot problems
are invisible to block validation in both directions, and both ship the wrong
words with no error anywhere:

```bash
wp eval-file <skill>/scripts/check-slots.php path/to/page-pattern.php
```

It reports which slots took their value and which still show placeholder copy.
Also check the rules in `references/design-content-split.md` — malformed
attributes there leave a *valid* block behind.

### 7. Place it

A theme pattern is a PHP file in `patterns/` with a header comment:

```php
<?php
/**
 * Title: FAQ Entry
 * Slug: my-theme/faq-entry
 * Description: One question and its answer, above a hairline.
 * Categories: my-theme_elements
 * Synced: yes
 */
?>
<!-- wp:group ... -->
```

`Title` and `Slug` are required and the slug must be namespaced.
`references/pattern-kinds.md` lists which other headers each kind needs.
`Categories` names slugs the site has **registered** — core's own, or the
theme's through `register_block_pattern_category()`; an unregistered slug files
the pattern under Uncategorized, where nobody looks.

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

This is the normal way to build above the smallest scale, not an optimisation.
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
- `references/assets.md` — images and fonts
- `references/abilities.md` — asking a running site for what it has
- `references/keeping-current.md` — bringing these guides to a new WordPress release
- The `design-reproduction` skill — rebuilding a design that already exists
