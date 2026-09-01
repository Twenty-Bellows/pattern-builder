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

## Workflow

### 1. Orient before writing

Never invent colors, spacing, or font sizes. A pattern that hard-codes
`#2c3e50` and `padding: 48px` looks right in one theme and wrong everywhere
else, and it silently opts out of the site's dark mode, style variations, and
future redesigns.

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

### 4. Write the markup

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

Write real placeholder copy, not lorem ipsum. Copy of a plausible length is
what tells you the layout works, and in a design pattern the placeholder is
what shows in the inserter preview.

### 5. Validate — every time, before placing the file

This is the step that makes the difference, and it cannot be done by reading
the markup or by looking at the front end.

Use the bundled script, which runs the editor's real parser with the core
block library registered:

```bash
node <skill>/scripts/validate-pattern.mjs path/to/pattern.php
```

It handles a theme pattern's PHP header and inline `<?php echo esc_url( … ); ?>`
expressions, and it takes `-` to read markup from stdin — useful for checking a
draft before it is ever a file. It needs `@wordpress/blocks`,
`@wordpress/block-library` and `jsdom` resolvable; if the project doesn't have
them, `npm i --no-save @wordpress/blocks @wordpress/block-library jsdom`.

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

Remember what block validation cannot see: missing block-supports classes leave
a *valid* block that renders unstyled. After validating, re-read your own markup against
the attribute-to-class table in `references/block-markup.md`, and where a
running site is available, `pattern-builder/render-pattern` will show you the
HTML that actually comes out.

If the pattern uses Pattern Overrides slots, also check the split rules in
`references/design-content-split.md` — block validation cannot see those
mistakes at all, because malformed attributes leave a *valid* block behind.

### 6. Place it

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

A pattern can reference another with `core/pattern`:

```html
<!-- wp:pattern {"slug":"my-theme/section-intro"} /-->
```

Reach for this when a piece of design genuinely repeats — a section heading
treatment, a card, a masthead — and you want one place to change it. Don't
reach for it to avoid typing; a reference costs indirection, and a pattern
assembled from six references nobody can read is worse than one that repeats a
group wrapper twice.

Two things to know. A referenced pattern must be registered on the site the
pattern renders on, so a pattern that references others cannot travel alone —
it needs its dependencies installed too. And a `wp:pattern` block is
self-closing (`/-->`), which is easy to get wrong.

## Turning a screenshot into a pattern

Read the structure before the pixels: how many bands, how each is aligned,
where the rhythm changes. Then map each band to a block — a full-width group,
a `core/columns`, a `core/media-text`, a `core/cover`.

Match the screenshot to the theme's tokens rather than sampling its colors.
The point is a pattern that belongs to *this* design system, so pick the
nearest palette entry and the nearest spacing step. If nothing is close, say
so rather than hard-coding a hex value — a missing token is a design-system
decision for the user to make, not something to paper over.

For images, use a placeholder and leave the real asset to the user. An
`<img>` pointing at a URL you invented will 404, and a pattern carrying a
data-URI image is unusable.

## When a pattern needs something the design system lacks

Propose it; don't quietly add it. Say which token is missing, what you'd call
it, and what value you'd give it, then let the user decide. Silent additions
are how a design system becomes forty near-identical greys.

The exception is when the user has already said to extend the system — then
add the token to `theme.json` and mention what you added.

## References

- `references/pattern-kinds.md` — the six kinds, what each is for, and the headers each one writes
- `references/block-vocabulary.md` — which blocks are allowed where (core-only vs theme vs plugin), the core vocabulary by purpose, and composition guidance
- `references/block-markup.md` — the attribute-to-markup contract per block, and the mistakes that produce invalid markup
- `references/design-content-split.md` — Pattern Overrides slots, `core/pattern` `content`, synced patterns, and the silent failures
- `references/abilities.md` — asking a running site for its design system, block types and patterns, and storing results
