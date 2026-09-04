---
name: pattern-author
description: Write WordPress block patterns — hero sections, pricing tables, FAQ lists, CTA bands, testimonials, page layouts — as valid block markup that uses the theme's own design tokens. Use this whenever the task involves creating, editing, or composing a block pattern, a theme pattern file, a reusable block, or a section of a block-theme page, and also when turning a screenshot, a description, or a competitor's page into WordPress blocks. Hand-written block markup is invalid far more often than it looks, because invalid markup renders perfectly on the front end and only breaks when someone opens the editor — so reach for this skill even for a "quick" pattern, and especially before writing markup into a theme.
---

# Authoring block patterns

## The thing that makes this different from writing HTML

WordPress decides a block is valid by re-running the block's `save()` function
against its stored attributes and diffing the result against the markup on
disk. `save()` is JavaScript. That has one consequence that governs everything
here:

**Markup you write by hand can render perfectly on the front end and still be
broken.** The front end just prints what is stored. The editor re-derives it,
finds a mismatch, and shows "This block contains unexpected or invalid
content" — offering to discard the user's markup. Nothing warns you before
that moment. Not the browser, not PHP, not a screenshot.

So the loop is always: **write → validate → fix → only then place it.**
Skipping validation because the markup "looks fine" is how patterns ship
broken. It will look fine. That is the problem.

A second, quieter failure mode matters just as much. **WordPress reads
malformed attribute JSON as *no* attributes**, and many blocks save markup
that does not depend on their attributes — a paragraph, an `h2`, a plain
group. For those, dropping the whole attribute object produces byte-identical
output. So a lost brace leaves the block *valid*, rendering identically, and
silently stripped of everything the attributes were doing. If those attributes
were a Pattern Overrides slot, the slot is simply gone, and the page that
fills it ships the design pattern's placeholder copy as though it were real
copy.

## Order of operations

Each scale contains the ones before it — a site is pages, a page is patterns,
a pattern is markup. Working out of order is recoverable but expensive: every
step settles values the steps after it reference, so a late change to an early
one re-opens everything downstream.

**One pattern:** orient → establish the vocabulary → decide the kind → factor →
write → validate → place.

**A page:** the design system first, then *elements*, then the *sections* that
reference them, then the *page* that references those. Build bottom-up, because
a section cannot be written until the elements it references exist by name.

**A whole site:** settle the design system in this order — **layout** (the
widths every band measures against) → **tokens** (the presets markup
references) → **styles** (what a block looks like before a pattern speaks) →
**block style variations** (named looks applied with a class). Then the
patterns, bottom-up as above, then the pages that place them.

Layout comes first because it is the one thing every band's markup has to
agree with: settle it late and every pattern written before it has the wrong
measure baked in. Tokens come before styles because a style references a
preset by slug and a slug that does not resolve renders as nothing.

## Workflow

### 1. Orient before writing

Never invent colors, spacing, or font sizes. A pattern that hard-codes
`#2c3e50` and `padding: 48px` looks right in one theme and wrong everywhere
else, and it silently opts out of the site's dark mode, style variations, and
future redesigns.

Referencing a preset the site does not define fails the same way and just as
quietly — a slug that does not resolve renders as no styling at all.
`references/design-system.md` covers all three layers a pattern leans on — the
tokens it references, the styles it inherits and the block style variations it
applies — and which names are actually safe, measured against core and the
three most recent default themes: the short version is `base` and `contrast`
for colour, the `small`…`xx-large` ladder for type, and the numeric spacing
steps `40`–`60`. Everything else, check before you use it. It also says the
thing hardest to see from the markup: what the site already styles is what the
pattern inherits, so restating it is how a pattern stops adapting.

Get the real values. In order of preference:

**If the site is running and has Pattern Builder,** ask it — this resolves
core, parent theme, child theme and the active style variation for you:

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/get-design-system/run"
```

Also worth asking before using a block that is not core, since markup for a
block this site does not have parses to `core/missing`:

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" -G --data-urlencode 'input[namespace]=core' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/list-block-types/run"
```

Input goes under an `input` key — `input[key]=value` on a GET, `{"input":{…}}`
in a POST body. See `references/abilities.md` for the full set.

A site can also carry authoring guides of its own — house rules a theme adds
through the `pattern_builder_authoring_guides` filter, like which blocks this
build has settled on or how its copy reads. Ask for the index when you have a
site to ask; anything it returns beyond these documents is project policy, and
this repository cannot know it:

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/get-authoring-guide/run"
```

**Otherwise, read the theme directly:** `theme.json` for `settings.color.palette`,
`settings.spacing.spacingSizes`, `settings.typography.fontSizes`, and
`settings.layout`. Check `styles/*.json` too, and the parent theme if this is a
child. If the project has a design-system document, read that as well — it
carries the intent that JSON cannot, like which variation to use when.

Then read two or three existing patterns in `patterns/`. They tell you more
about house style than any description: how sections are spaced, whether
groups are constrained or full, how headings step down.

### 2. Establish which blocks you may use

Ask where the pattern is going, because it decides the vocabulary — and say
which answer you assumed if nobody told you.

**Core blocks only** for anything that leaves this site: the WordPress.org
pattern directory, a shared or public cloud library, a theme other people will
install. Markup for a block the receiving site lacks parses to `core/missing`
and renders as a grey "block cannot be displayed" box — it doesn't degrade, it
breaks, and it breaks where you can't see it. This is the safe default when
the destination is unclear.

**patternbuilderwp.com is narrower still**: core membership is not its test,
and it refuses code, raw HTML, embeds, non-image media and anything naming a
row on the site that made it. It also records the WordPress version a pattern
needs and refuses to install one on a site too old for it. See
`references/block-vocabulary.md`.

**Core plus the theme's own blocks and styles** for a pattern shipping inside
that theme; they travel together. A registered block style
(`{"className":"is-style-card"}`) is usually the better tool than a custom
block anyway.

**Core plus installed plugins** only for patterns staying on this site.

`references/block-vocabulary.md` has the full rule, the current core
vocabulary by purpose, and the composition guidance — which block is right for
a job, and where hand-building something core already provides goes wrong.

### 3. Decide what the pattern is *for*

"Pattern" covers six different jobs, and the job settles most of the
mechanics — where it can be stored, which headers place it, whether it appears
in the inserter. Pick the kind before writing markup:

| The user wants… | Kind | What it fixes |
|---|---|---|
| a section to drop in and edit | **Design Pattern** | unsynced; theme or database |
| a component whose design stays consistent everywhere | **Synced Design Pattern** | `Synced: yes`; theme or database |
| a starting layout for new pages | **Page Pattern** | `Block Types: core/post-content` + `Post Types` |
| a design for an empty Query Loop, Cover, etc. | **Block Starter Pattern** | `Block Types: <that block>` |
| a whole archive/404/home layout | **Template Pattern** | `Template Types`, `Inserter: no`, wide viewport |
| a header or footer design | **Template Part Pattern** | `Block Types: core/template-part/header\|footer` |

A kind is a starting point, not a stored property — nothing records it, and
everything stays editable afterwards. `references/pattern-kinds.md` has each
one in full.

Two consequences that catch people out:

**The four starter kinds are always theme patterns.** Their placement lives in
pattern-file headers, and a `wp_block` in the database has nowhere to put
them. If a request wants a database pattern *and* wants WordPress to offer it
for new pages, those conflict — say so rather than silently picking one.

**Synced Design Pattern + Page Pattern is the design/content split.** Where a
project uses that layering, those two kinds are its halves: the synced pattern
owns the markup and carries placeholder copy, the page pattern owns the words
and fills the slots. Read `references/design-content-split.md` before writing
either; the failure modes there are silent.

Follow the project rather than imposing on it. If existing patterns are
self-contained, write self-contained patterns — introducing the split unasked
adds a dependency the project may not want, since `core/pattern`'s `content`
attribute comes from Pattern Builder or Synced Patterns for Themes, not core.
Without one of them, WordPress drops the attribute and every page renders
placeholder copy.

### 4. Factor before you write

This is the step whose absence produces the most wasted work, and skipping it
does not look like a mistake — it looks like a finished page.

A pattern's value is exactly its reusability. Markup that spells out one
business's 146 menu items is that business's *content* wearing a pattern's
clothes: nobody else installs it, and changing the design of a menu item means
146 edits. The fix is not "write less markup"; it is to find the parts that
repeat and make each one a pattern the others reference.

So before writing anything, produce an **inventory**. Not a mental note — a
table, because a step with an artifact is a step you can see was skipped:

| shape | occurrences | leaves that differ | name | level |
|---|---|---|---|---|
| `group > columns > [image, group > [row > [p, p], p]]` | 146 | name, price, description, image | menu item | element |
| `group > [rule, heading, p, group > columns…]` | 24 | heading, blurb | menu section | section |

Three tests fill it in, and only the middle one needs judgement.

**1. What repeats?** Reduce the markup you are about to write to a *shape* —
the tree of block names and attributes with all text, URLs and IDs stripped.
Any shape occurring more than once is a candidate. This is mechanical: if you
are about to write the same subtree twice, you have found one.

Where the design comes from an existing page, the repetition is already in
front of you. Be especially alert to the output of a loop — a menu, a product
grid, a card deck. A page that renders the same subtree fifty times was built
from a template, and a template is what you are supposed to be writing.

**2. Does it have a name?** A candidate becomes a pattern when you can name it
with a domain noun phrase — *menu item*, *dish card*, *testimonial*, *hours
row*. If the only name available is structural — *the group wrapper*, *the
two-column row* — it is markup, not a pattern. This is the test that stops the
first one shattering a page into confetti.

**3. What are the slots?** Given N occurrences of one shape, the slots are
exactly the leaves whose content differs between them. Name differs → slot.
Price differs → slot. The wrapper's padding is identical everywhere → not a
slot. You compute this rather than decide it.

Then place each row at its level:

| Level | What it is | What it contains |
|---|---|---|
| **Element** | the smallest named repeated thing | markup, with slots |
| **Section** | a full-width band | a heading and *references to elements* |
| **Page** | the whole page | *references to sections* |

**A pattern at any level may reference patterns below it, and the nesting is
not limited to one hop.** A page references sections; a section references
elements; a section may reference other sections. The failure to avoid is
stopping after one level — bands as patterns, everything inside them written
out longhand.

Two things bound how far this goes:

- **Pattern Overrides binds only `core/paragraph`, `core/heading`,
  `core/image` and `core/button`.** A slot must land on one of those four, so
  boundaries fall where the varying content is text, a heading, an image or a
  button. You cannot slot "some blocks".
- **Don't factor what does not repeat.** A band that appears once on one page
  is a section pattern because it is a named part of the page, not because it
  repeats. A wrapper that appears twice and has no name stays inline.

`references/composition.md` has the worked example, the mechanics of
references and slots together, and where this goes wrong.

### 5. Write the markup

Block markup is HTML comments wrapping HTML:

```html
<!-- wp:heading {"level":2,"fontSize":"x-large"} -->
<h2 class="wp-block-heading has-x-large-font-size">Section title</h2>
<!-- /wp:heading -->
```

The attributes and the HTML have to agree, and they fail in **two different
ways** that need different defences:

**Structure the block's own `save()` writes** — a heading's tag matching its
`level`, `wp-block-group` on a group, a button's anchor, a column's class. Get
these wrong and the block is *invalid*: the editor offers to discard it. The
validator catches all of these, which is why the validation step is not optional.

**Classes contributed by block supports** — `"backgroundColor":"primary"`
obliges `has-primary-background-color has-background`; `"align":"center"`
obliges `has-text-align-center`; `"fontSize":"large"` obliges
`has-large-font-size`. Get these wrong and the block stays *valid* — and the
styling simply does not apply. No error anywhere; the pattern just renders
wrong, usually in a way that looks like a design mistake rather than a bug.
**The validator cannot catch these**, because the supports filters that add
the classes only run inside a real editor. They are yours to get right by
hand, and to confirm by looking at the rendered result.

`references/block-markup.md` has the attribute-to-class table and a tested
list of what the validator does and does not see.

Reference presets by slug, never by value:

- Color: `"backgroundColor":"base"` (slug), or `var(--wp--preset--color--base)` in a style
- Spacing: `"var:preset|spacing|large"` in attributes, `var(--wp--preset--spacing--large)` in the inline style
- Font size: `"fontSize":"large"`, or `var(--wp--preset--font-size--large)`

Note the two spellings. Inside a block's attribute JSON, WordPress uses its
own `var:preset|spacing|large` shorthand; in the actual CSS of the `style`
attribute it must be the real custom property. Patterns that get this backwards
render with no spacing at all.

**For anything outside the common blocks, do not hand-write it — generate it.**
`references/block-markup.md` carries the contract for the dozen or so blocks
patterns are mostly made of. Outside that set, the shape is guesswork: the
accordion family saves a `role="group"`, an `has-icon has-icon-right` pair, a
`__toggle-title` span and an icon span, and nothing short of the block's own
`save()` will tell you that. No documentation carries it — see
`references/block-vocabulary.md` on what the handbook does and does not cover.

The block library the validator already loads will write it for you, correctly
and for the version you are targeting:

```js
import { loadWordPressBlocksFromUrls } from '<skill>/scripts/wp-core.mjs';
const core = await loadWordPressBlocksFromUrls( urls, { version } );
const { createBlock, serialize } = core.window.wp.blocks;

console.log( serialize( [
        createBlock( 'core/accordion', {}, [
                createBlock( 'core/accordion-item', {}, [
                        createBlock( 'core/accordion-heading', { title: 'A question' } ),
                        createBlock( 'core/accordion-panel', {}, [
                                createBlock( 'core/paragraph', { content: 'An answer.' } ),
                        ] ),
                ] ),
        ] ),
] ) );
```

`urls` comes from `pattern-builder/get-editor-scripts` (or
`loadWordPressBlocks( wpRoot )` against a local install). Markup produced this
way is valid by construction — it is the editor's own output — so this is
faster and more reliable than writing a draft and iterating on validator
errors. Validate it anyway: the run is cached and it costs a second.

Two things the serializer will do that you have to allow for:

- **It escapes a PHP tag.** A theme asset's reference is
  `<?php echo get_stylesheet_directory_uri() . '…'; ?>`, and passing that as an
  attribute value serializes it as `&lt;?php …&gt;`, which lands in the file as
  text and renders as a broken image. Serialize with a plain marker in its
  place and substitute the PHP afterwards:
  `serialize( … ).replace( /HERO_SRC/g, reference )`.
- **It drops an attribute the block does not have**, silently, which is the
  behaviour you want — it is the same answer the editor would give — but it
  means a setting can vanish without a word. `textAlign` on `core/heading` is
  the one to know: on block library 10.5 it belongs under
  `style.typography.textAlign`, and passed at the top level it is simply gone,
  along with the `has-text-align-center` class you were expecting.

Write real placeholder copy, not lorem ipsum. Copy of a plausible length is
what tells you the layout works, and in a design pattern the placeholder is
what shows in the inserter preview.

### 6. Validate — every time, before placing the file

This is the step that makes the difference, and it cannot be done by reading
the markup or by looking at the front end.

Use the bundled script, which runs the editor's real parser with the core
block library registered:

```bash
node <skill>/scripts/validate-pattern.mjs path/to/pattern.php
```

It reports three different things, and only the first is the one people expect:

- **INVALID** — no version of the block ever wrote markup like this. The editor
  will say "unexpected or invalid content".
- **OLD FORM** — the markup matches a *deprecated* version of the block. The
  editor opens it happily and migrates it, so it never looks broken there, but
  the file on disk is missing what the block writes today — nearly always a
  block-supports class, which means the style silently does not apply on the
  front end.
- **DROPPED ATTRIBUTE** — the same migration threw away something you wrote.
  A heading with `"fontSize":"xx-large"` and no `has-xx-large-font-size` class
  comes back with no `fontSize` at all.

The last two are the dangerous ones, because nothing else anywhere reports
them: the pattern renders, the editor is quiet, and the design is just wrong.

It handles a theme pattern's PHP header and inline `<?php echo esc_url( … ); ?>`
expressions, and it takes `-` to read markup from stdin — useful for checking a
draft before it is ever a file.

**It validates against a WordPress install, not against npm.** Every install
already carries the editor's block code — about 4MB under `wp-includes/js/dist`
— so there is nothing to download, and more importantly it is the *exact*
version the pattern is destined for. That is not a detail: block library 10.5
moved text alignment into a typography support, so it disagrees with 9.22 about
whether the same file is current. The first line of output says what was used:

```
Checked against WordPress 7.1 at /srv/www/example — 113 block types.
```

It finds the install by itself when you are working anywhere inside one — a
theme directory, a plugin directory — and the script ships inside the plugin,
so it usually finds the install it lives in. Otherwise point it:

```bash
node <skill>/scripts/validate-pattern.mjs --wp /path/to/wordpress pattern.php
WP_PATH=/path/to/wordpress node <skill>/scripts/validate-pattern.mjs pattern.php
```

The one thing WordPress cannot supply is a browser, and its editor code expects
a document to exist as it loads: `npm i --no-save jsdom`. With no WordPress
anywhere, `--npm` uses `@wordpress/blocks` and `@wordpress/block-library` from
`node_modules` instead, which is the same check against whichever version npm
resolved.

**If you reached this site over HTTP and have no copy of any of it**, the site
will hand you both halves. Two abilities, then the same command:

```bash
# The tool itself — write each file it returns into one directory.
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/get-validator/run"

# Where this site's own block code lives, in the order it loads.
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/get-editor-scripts/run" \
  > scripts.json

npm i --no-save jsdom
node validate-pattern.mjs --scripts scripts.json pattern.html
```

The first run downloads that site's editor scripts (about 4MB) and caches
them, so later runs are immediate. The URLs carry version strings, so an
upgraded site fetches afresh rather than trusting a stale cache. You are
checking against the WordPress the pattern is destined for, which is the
whole point.

If the project has its own validator (`npm run validate:blocks`), prefer it —
it may carry project-specific lints as well.

Fix what it reports and run it again; an empty report is the only acceptable
result. A `core/missing` means the block is not registered on the target site,
so either the markup has a typo or the site genuinely lacks that block.

**If the pattern uses Pattern Overrides slots, render it.** Slot problems are
invisible to block validation in both directions, and both ship the wrong
words with no error anywhere. Against a site with the runtime:

```bash
wp eval-file <skill>/scripts/check-slots.php path/to/page-pattern.php
wp eval-file <skill>/scripts/check-slots.php my-theme/faq '{"question":{"content":"…"}}'
```

It renders the reference and reports which slots took their value and which
still show the design pattern's placeholder. A misspelled slot name reports as
`MISSED quesiton — no slot by that name in the design pattern (typo?)`; an
unregistered slug reports that the reference renders as nothing at all.

When it names a missing class, the attribute-to-class table in
`references/block-markup.md` says what each attribute requires. Where a running
site is available, `pattern-builder/render-pattern` is a useful second look —
it shows the HTML that actually comes out, which is the thing your visitor
gets.

If the pattern uses Pattern Overrides slots, also check the split rules in
`references/design-content-split.md` — block validation cannot see those
mistakes at all, because malformed attributes leave a *valid* block behind.

### 7. Place it

A theme pattern is a PHP file in the theme's `patterns/` directory with a
header comment:

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

`Title` and `Slug` are required, and the slug must be namespaced with the
theme slug. Which of the other headers you need follows from the kind —
`references/pattern-kinds.md` lists them; `Keywords`, `Block Types`,
`Post Types`, `Template Types`, `Viewport Width` and `Inserter` are the ones
that appear.

Write the file directly when you have filesystem access. When you're working
against a running site instead, `pattern-builder/create-pattern` stores
finished markup — but it stores what you give it, so validate first either way.

## Composing patterns from other patterns

A pattern references another with `core/pattern`:

```html
<!-- wp:pattern {"slug":"my-theme/section-intro"} /-->
```

This is the normal way to build anything above the smallest scale, not an
optimisation to apply afterwards. A page is references to its sections; a
section is a heading and references to its elements. Step 4 is where you work
out what those are.

The cost of a reference is one hop of indirection. The cost of *not* using one
is a copy — and every copy is a place the design has to be changed again, a
pattern nobody else can reuse, and markup an editor has to load. Where a part
repeats and has a name, the reference is cheaper. Where it does neither, write
it inline.

Two mechanics to know. A referenced pattern must be registered on the site the
pattern renders on, so a pattern that references others cannot travel alone —
it needs its dependencies installed too, and **an unresolved reference renders
as nothing at all**, with no error anywhere. And a `wp:pattern` block is
self-closing (`/-->`), which is easy to get wrong.

To fill a referenced pattern's slots, `core/pattern` takes a `content`
attribute — that is the design/content split, and it needs Pattern Builder or
Synced Patterns for Themes to resolve. `references/composition.md` covers the
composition; `references/design-content-split.md` covers the slots.

## Turning a screenshot into a pattern

Read the structure before the pixels: how many bands, how each is aligned,
where the rhythm changes. Then map each band to a block — a full-width group,
a `core/columns`, a `core/media-text`, a `core/cover`.

Then factor it (step 4) before writing a line. A screenshot is the strongest
invitation there is to transcribe rather than build, because everything in it
is already spelled out — the six cards, the twelve rows, the forty menu items
are all *there*, and copying them out feels like progress. Count the repeats
first and name them; what you write afterwards is one card and a reference to
it, not six cards.

Match the screenshot to the theme's tokens rather than sampling its colors.
The point is a pattern that belongs to *this* design system, so pick the
nearest palette entry and the nearest spacing step. If nothing is close, say
so rather than hard-coding a hex value — a missing token is a design-system
decision for the user to make, not something to paper over.

For images, never invent a URL — an `<img>` pointing at a plausible-looking
path will 404 on a page the user thinks is finished, and a data-URI image
bloats the markup past what an editor handles comfortably. Either put the
file on the site or use a placeholder; `references/assets.md` covers both.

## When a pattern needs an image or a typeface

A pattern is markup plus the files it points at, and a reference that does not
resolve fails quietly — a dead `src` shows a broken image, a `fontFamily`
naming no preset renders in the default face with nothing to say why. So the
files come first, and the reference you write is the one the site hands back.

On a running site, `pattern-builder/find-media` lists what is already here —
the media library *and* the theme's own `assets/images`, which no core route
reports — and every result carries the exact `reference` to put in the markup.
Use it verbatim: a theme pattern is a PHP file, so its own assets are composed
at render, and a hard-coded URL breaks as soon as the theme moves.

What to reach for depends on what you are holding:

| You have | Use |
| --- | --- |
| A file (JPEG, PNG, WebP, AVIF) | `POST /pattern-builder/v1/assets` — the bytes are the request body. An ability cannot carry binary |
| A URL the user pointed you at | `add-asset` with `url`; the site fetches it |
| Something you can draw | `add-asset` with `svg`, or `add-placeholder-image` for a plain one |
| Nothing yet | `add-placeholder-image`. Never a remote placeholder service — that makes every page view fetch from somebody else's server |
| A typeface | `add-font`, which installs the files *and* registers the preset that makes them render |

`references/assets.md` has the requests, the parameters, the 2400px resize on
the way in, and why a font needs both halves.

## When a pattern needs something the design system lacks

Propose it; don't quietly add it. Say which token is missing, what you'd call
it, and what value you'd give it, then let the user decide. Silent additions
are how a design system becomes forty near-identical greys.

The exception is when the user has already said to extend the system. Then add
the token and mention what you added — never inline the value in the markup,
which opts the pattern out of the site's palette, its dark mode and every
future restyle. On a running site that is `pattern-builder/add-design-tokens`
(`references/abilities.md`); editing files directly, it is `theme.json`'s
`settings.color.palette`, `settings.spacing.spacingSizes`,
`settings.typography.fontSizes` or `settings.typography.fontFamilies`.

Whichever route, add the token **before** the pattern that references it, and
reference it by slug — `{"backgroundColor":"kiln-red"}` with the
`has-kiln-red-background-color has-background` classes, or
`var:preset|spacing|band` in a style attribute. A slug that does not resolve
renders as no styling at all, silently.

## References

- `references/pattern-kinds.md` — the six kinds, what each is for, and the headers each one writes
- `references/block-vocabulary.md` — which blocks are allowed where (core-only vs theme vs plugin), the core vocabulary by purpose, and composition guidance
- `references/design-system.md` — the three layers a pattern leans on: the tokens it references, the styles it inherits, the block style variations it applies, and which names travel
- `references/block-markup.md` — the attribute-to-markup contract per block, and the mistakes that produce invalid markup
- `references/composition.md` — factoring a design into elements, sections and pages, and how patterns reference patterns
- `references/design-content-split.md` — Pattern Overrides slots, `core/pattern` `content`, synced patterns, and the silent failures
- `references/assets.md` — images and fonts: finding what the site has, adding what it lacks, and the reference to write for each
- `references/keeping-current.md` — how to bring these guides up to a new WordPress release, and what to re-check
- `references/abilities.md` — asking a running site for its design system, block types and patterns, storing results, and the guides the site itself carries
