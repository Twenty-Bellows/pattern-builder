# Block markup: the contract between attributes and HTML

Every block is an HTML comment carrying JSON attributes, wrapping the HTML
that block's `save()` would produce. Validity means those two agree. What
follows separates the parts a validator will catch from the parts it will not,
because the two need different care.

## What the validator catches, and what it doesn't

Tested directly against `@wordpress/blocks` `parse()` with the core library
registered — the same code the editor runs.

| Mistake | Result |
|---|---|
| `heading` with `"level":2` but an `<h3>` tag | **invalid** — caught |
| `group` missing `class="wp-block-group"` | **invalid** — caught |
| `button` with no `<a>` inside | **invalid** — caught |
| `image` missing `class="wp-block-image"` on the figure | **invalid** — caught |
| `column` missing `class="wp-block-column"` | **invalid** — caught |
| A block comment never closed | **invalid** — caught |
| Attribute JSON that doesn't parse | caught (as an unparsed block) |
| A block the site doesn't have | reported as `core/missing` |
| `"backgroundColor":"primary"` with no `has-primary-background-color` | **valid** — missed |
| `"align":"center"` with no `has-text-align-center` | **valid** — missed |
| `"fontSize":"large"` with no `has-large-font-size` | **valid** — missed |
| `heading` missing `class="wp-block-heading"` | **valid** — missed |
| `<li>` written directly instead of a `list-item` block | **valid** — missed |

The dividing line is worth internalising: **structure that a block's own
`save()` writes is checked; classes contributed by block *supports* are not.**
Supports classes (color, typography, spacing, text alignment) are added by
filters that only run inside a real editor, so Node-based validation is blind
to them.

That blindness has a silver lining and a trap. The silver lining: a missing
supports class does not make the block invalid, so nobody gets the "unexpected
or invalid content" dialog. The trap: **the style silently does not apply.**
`"backgroundColor":"primary"` with no class renders with no background at all.
It looks like a design mistake, not a bug, so it survives review.

So: run the validator for structure, and check supports classes by eye against
the table below.

## Attribute to class

| Attribute | Class the markup must carry |
|---|---|
| `"backgroundColor":"x"` | `has-x-background-color has-background` |
| `"textColor":"x"` | `has-x-color has-text-color` |
| `"gradient":"x"` | `has-x-gradient-background has-background` |
| `"fontSize":"x"` | `has-x-font-size` |
| `"fontFamily":"x"` | `has-x-font-family` |
| `"align":"center"` on text blocks | `has-text-align-center` |
| `"align":"wide"` on containers | `alignwide` |
| `"align":"full"` on containers | `alignfull` |
| `"style":{"color":{"background":"…"}}` | `has-background` + an inline `style` |
| `"className":"is-style-x"` | `is-style-x` |

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
