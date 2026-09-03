# Block markup: the contract between attributes and HTML

Every block is an HTML comment carrying JSON attributes, wrapping the HTML
that block's `save()` would produce. Validity means those two agree — and when
they disagree, WordPress usually does not say so. It has three different ways
of not saying so, which is what the first section is about.

## Three ways a pattern is wrong

Tested directly against `@wordpress/blocks` with the core library registered —
the same code the editor runs. `scripts/validate-pattern.mjs` reports all
three, and only the first is the one people know about.

**INVALID** — no version of this block ever wrote markup like this. The editor
says "unexpected or invalid content" the moment it opens the pattern.

- heading with `"level":2` but an `<h3>` tag
- `group` missing `class="wp-block-group"`
- `button` with no `<a>` inside
- `column` missing `class="wp-block-column"`, or a width with no `flex-basis`
- `"align":"full"` with no `alignfull`
- a block comment never closed, or attribute JSON that does not parse
- a block this site does not have (reported as `core/missing`)

**OLD FORM** — this matches a *deprecated* version of the block. Blocks carry
their old `save()` implementations for backward compatibility (`core/paragraph`
has six), and the parser tries every one. When an old version matches, the
editor opens the pattern without a murmur and quietly migrates it — so it never
looks broken *there*. But the front end renders the file, and the file is
missing what the block writes today:

- `"backgroundColor":"primary"` with no `has-primary-background-color`
- `"textColor":"accent"` with no `has-accent-color has-text-color`
- `"fontSize":"large"` with no `has-large-font-size`
- `"align":"center"` with no `has-text-align-center`
- `heading` with no `wp-block-heading`
- `<li>` written directly instead of a `list-item` block

Every one of those renders unstyled. It reads as a design mistake rather than a
bug, which is why it survives review.

**DROPPED ATTRIBUTE** — the same migration, one step worse. `migrate()` treats
the *markup* as authoritative, so it can throw away an attribute you wrote.
A heading with `{"level":2,"fontSize":"xx-large"}` whose tag carries no
`has-xx-large-font-size` comes back with no `fontSize` at all — the size is
simply gone, the block is perfectly self-consistent afterwards, and nothing
reports it. Custom values go the same way: `{"style":{"color":{"background":
"#eeeeee"}}}` with no inline `style` attribute loses the whole `style` object.

The common cause of all three is the same: **the attribute JSON and the HTML
have to agree.** The table below is that correspondence.

Two things the validator deliberately stays quiet about, because in both the
markup is fine and only the check would be wrong:

- **An attribute core relocated.** Block library 10.5 moved text alignment out
  of a paragraph's `align` and a heading's `textAlign` and into a typography
  support, migrating the value to `style.typography.textAlign`. The key is gone
  and the setting is intact, so that is not a dropped attribute.
- **A block with Pattern Overrides bindings.** A bound block takes its content
  from the binding source at render rather than from the file, and core
  reserves room in the saved markup for that value — so the file and a save
  computed from the file's own attributes are not comparable. Slots are checked
  by rendering them instead; see `design-content-split.md`.

## What this document covers

The contract below is for the blocks patterns are mostly built from — the ten
families in "Structural requirements per block". It is deliberately not a
catalogue of every core block: WordPress ships new ones every release, their
saved markup is `save()`'s output rather than anything declared, and a list
here would be wrong within two releases.

**Outside those families, generate the markup instead of writing it** — the
editor's own `createBlock`/`serialize`, as `SKILL.md` step 4 shows. That is
right by construction and right for the version you are targeting. The
attribute-to-class table below still applies to whatever comes out, because
block supports are shared across every block that opts into them.

## Attribute to class

| Attribute | Class the markup must carry |
|---|---|
| `"backgroundColor":"x"` | `has-x-background-color has-background` |
| `"textColor":"x"` | `has-x-color has-text-color` |
| `"gradient":"x"` | `has-x-gradient-background has-background` |
| `"fontSize":"x"` | `has-x-font-size` |
| `"fontFamily":"x"` | `has-x-font-family` |
| `"style":{"typography":{"textAlign":"center"}}` on text blocks | `has-text-align-center` |
| `"align":"wide"` on containers | `alignwide` |
| `"align":"full"` on containers | `alignfull` |
| `"style":{"color":{"background":"…"}}` | `has-background` + an inline `style` |
| `"className":"is-style-x"` | `is-style-x` |

Text alignment is the row that has already moved once, and the move is
complete: on a current install `core/heading` has no `textAlign` attribute at
all, and `{"align":"center"}` on a `core/paragraph` serializes with no
`has-text-align-center` class. Both now carry it under
`style.typography.textAlign`. The older spellings are what the "attribute core
relocated" exemption below is about — the validator will not report them, so
this table is the only thing that will.

Custom values go in an inline `style` attribute *as well as* the attribute
JSON, and the two use different spellings of a preset:

```html
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|large"}}}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--large)">
```

`var:preset|spacing|large` in the JSON, `var(--wp--preset--spacing--large)` in
the CSS. Writing the CSS form in the JSON, or the JSON form in the CSS,
produces markup that validates and renders with no spacing.

## Structural requirements per block

These are the ones `save()` writes, so getting them wrong is a hard failure.

**heading** — the tag must match `level` (`{"level":3}` → `<h3>`). Include
`class="wp-block-heading"`; it is not enforced but the editor writes it and
themes style it.

**paragraph** — `<p>`, no required class.

**group** — `class="wp-block-group"` required. The layout lives in attributes:
`{"layout":{"type":"constrained"}}` for content-width, `"default"` for flow,
`{"type":"flex"}` for a row.

**columns / column** — `wp-block-columns` and `wp-block-column` both required.
A column carrying an explicit width needs it in both places:
`{"width":"33.33%"}` and `style="flex-basis:33.33%"`.

**buttons / button** — `<div class="wp-block-buttons">` containing
`<div class="wp-block-button">` containing
`<a class="wp-block-button__link wp-element-button">`. The anchor is required.
A style variation goes on the button:
`{"className":"is-style-outline"}`.

**image** — `<figure class="wp-block-image">` around the `<img>`. Add size and
link attributes when relevant (`{"sizeSlug":"large","linkDestination":"none"}`).
Never invent a `src`; leave a placeholder for the user.

**list / list-item** — `core/list` wraps `<ul class="wp-block-list">`, and each
item is its own `core/list-item` block. Writing bare `<li>` passes validation
and then behaves oddly in the editor, because the list block expects inner
blocks.

**cover** — `wp-block-cover` on the figure/div, with the span for the overlay
and `wp-block-cover__inner-container` around the content. Copy an existing
cover rather than writing one from scratch; it has the most moving parts.

**media-text** — `wp-block-media-text` with `wp-block-media-text__media` and
`wp-block-media-text__content` children, and the grid template in an inline
style when `mediaWidth` is not 50.

**spacer / separator** — simple, but `spacer` needs its height in both the
attribute and the inline style.

## Self-closing blocks

A block with no inner HTML closes itself and must not have a closing comment:

```html
<!-- wp:pattern {"slug":"my-theme/header"} /-->
<!-- wp:spacer {"height":"2rem"} -->…<!-- /wp:spacer -->
```

`core/pattern`, `core/post-content`, `core/site-title` and other dynamic blocks
with no saved markup take the `/-->` form. Adding a closing comment to one, or
omitting `/` from one that needs it, produces a parse failure.

## A worked example

```html
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"backgroundColor":"base-2","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-2-background-color has-background" style="padding-top:var(--wp--preset--spacing--x-large);padding-bottom:var(--wp--preset--spacing--x-large)">
	<!-- wp:heading {"textAlign":"center","level":2,"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-xx-large-font-size">What we do</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center","textColor":"contrast-2"} -->
	<p class="has-text-align-center has-contrast-2-color has-text-color">A sentence that says it plainly.</p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
```

Every attribute has its class; every preset appears in both spellings;
`alignfull` accompanies `"align":"full"`. That correspondence is the whole
discipline.
