# Block markup: what nothing else can tell you

Three different sources answer questions about block markup, and they own
disjoint slices. Going to the wrong one is how a pattern ends up wrong in a way
no check reports.

| Question | Ask | In this document? |
|---|---|---|
| What markup does this block write? | Run `save()` — `createBlock`/`serialize`, or the validator | Fallback only |
| What attributes exist? Types, enums, defaults, supports, context? | `list-block-types` | **No.** Ask the site |
| What goes *inside* `layout`, `style`, `metadata`? What strings does `contentPosition` accept? | Nothing. Anywhere. | **Yes — this is the subject** |

The middle row is a call, not a memory:

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" -G \
  --data-urlencode 'input[blocks][]=core/cover' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/list-block-types/run"
```

That returns the **post-registration** schema — every attribute including the
ones block supports inject, with enums where they exist, plus `supports`,
`usesContext` and whether the block is dynamic. It is current for the
WordPress you are talking to and cannot go stale. Never take an attribute list
from a document, this one included.

## What the schema does not say

Across 116 registered blocks, 1,190 attributes are fully described by their
schema. The gap is small, it is on almost every block, and both known
pattern-authoring disasters landed in it.

### Opaque objects

Four attribute names come back as `{"type":"object"}` with no interior:
`lock` and `metadata` on all 116 blocks, `style` on 109, `layout` on 24.

**`layout.type` is a closed set of four.** Nothing validates it — not
`parse()`, not `validateBlock()`, not `createBlock()`, not the server:

| Want | Write |
|---|---|
| content width | `{"layout":{"type":"constrained"}}` |
| plain vertical flow | `{"layout":{"type":"default"}}` |
| a row | `{"layout":{"type":"flex"}}` |
| a grid | `{"layout":{"type":"grid"}}` |

Those four names and no others. `flow`, `row`, `stack` and `columns` all
*look* right — `flow` most of all, since it is Gutenberg's own filename for the
default layout — and every one of them parses, serializes and validates clean,
then **crashes the editor**: core resolves the name with a `.find()` over a
private registry and calls a method on the `undefined` it gets back
("Cannot read properties of undefined (reading `getAlignments`)"). The saved
markup is byte-identical either way, which is why no save-side check can see
it.

The other `layout` properties, and the values each accepts:

| Property | Layout types | Values |
|---|---|---|
| `contentSize`, `wideSize` | constrained | any CSS length |
| `orientation` | flex | `horizontal` (default), `vertical` |
| `justifyContent` | flex | `left`, `center`, `right`, `space-between` |
| `flexWrap` | flex | `wrap` (default), `nowrap` |
| `verticalAlignment` | flex | `top`, `center`, `bottom` |
| `columnCount`, `minimumColumnWidth` | grid | a number; a CSS length |
| `columnSpan`, `rowSpan` | grid (on a child) | a number |

**`style`** is a theme.json-shaped subtree: `color.{background,text,gradient}`,
`spacing.{padding,margin,blockGap}`, `typography.{fontSize,fontStyle,
fontWeight,letterSpacing,lineHeight,textAlign,textDecoration,textTransform}`,
`border.{color,radius,style,width}`, `dimensions.minHeight`,
`elements.link.color.text`. Preset references inside it use the `var:preset|…`
spelling — see "Two spellings" below.

**`metadata`** carries `name` (the label a Pattern Overrides slot binds to),
`bindings`, `categories` and `patternName`. `design-content-split.md` owns it.

**`lock`** is `{"move":bool,"remove":bool}`.

### Strings with an unstated vocabulary

Declared `{"type":"string"}` with no enum, but only certain values do anything:

| Attribute | On | Values |
|---|---|---|
| `contentPosition` | `core/cover` | nine pairs: `{top\|center\|bottom} {left\|center\|right}`, e.g. `"top left"`, `"center center"` |
| `verticalAlignment` | `core/columns`, `core/column`, `core/media-text` | `top`, `center`, `bottom` — plus `stretch` on a column |
| `mediaPosition` | `core/media-text` | `left` (default), `right` |

A wrong value here does not crash and does not warn — the class core would
have emitted simply never appears, and the block renders in its default
position. That is the failure that reads as a design mistake.

> Every value in the two sections above was extracted from the running
> WordPress, not written from memory, and `test-abilities.php` re-checks them
> against the install the test suite runs on. A vocabulary that drifts fails a
> test rather than misleading a reader.

## Two spellings of a preset

Custom values go in an inline `style` attribute *as well as* the attribute
JSON, and the two spell a preset differently:

```html
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50)"></div>
<!-- /wp:group -->
```

`var:preset|spacing|50` in the JSON, `var(--wp--preset--spacing--50)` in the
CSS. Both mistakes validate, and they fail differently. The JSON form inside
the CSS is not CSS, so the browser drops the declaration and the block renders
with no padding at all. The CSS form inside the JSON renders — the saved
`style` attribute is what the front end prints — but it is not the spelling the
editor writes, so the control no longer shows the preset as chosen and the
value stops following the design system in the editor's eyes.

## How the validator reports a mismatch

`scripts/validate-pattern.mjs` runs the real `save()` and reports three things.
Only the first is the one people expect.

**INVALID** — no version of this block ever wrote markup like this. The editor
says "unexpected or invalid content" the moment it opens the pattern. A heading
whose tag contradicts its `level`; a group missing `wp-block-group`; a button
with no `<a>`; `"align":"full"` with no `alignfull`; attribute JSON that does
not parse; a block this site does not have (`core/missing`).

**OLD FORM** — this matches a *deprecated* save. Blocks keep their old
implementations (`core/paragraph` has six) and the parser tries every one, so
the editor opens the pattern without a murmur and migrates it. But the file on
disk is missing what the block writes today — nearly always a supports class,
so the styling silently does not apply on the front end.

**DROPPED ATTRIBUTE** — the same migration, one step worse: it treats the
*markup* as authoritative, so it throws away an attribute you wrote. A heading
with `{"level":2,"fontSize":"xx-large"}` whose tag carries no
`has-xx-large-font-size` comes back with no `fontSize` at all, perfectly
self-consistent, with nothing reporting it.

Two things it stays deliberately quiet about, because the markup is fine and
only the check would be wrong:

- **An attribute core relocated.** Block library 10.5 moved text alignment out
  of a paragraph's `align` and a heading's `textAlign` into a typography
  support, migrating the value to `style.typography.textAlign`. The key is gone
  and the setting is intact.
- **A block with Pattern Overrides bindings.** Its content comes from the
  binding source at render, so the file and a save computed from the file's own
  attributes are not comparable. Check slots by rendering
  (`design-content-split.md`).

A running site refuses a narrower set on its own — unparseable attribute JSON,
a heading contradicting its attributes, an unregistered block, an unresolved
reference, an unfillable slot. It cannot see any of the three above.

## Fallback: the save() contract by hand

**Generate the markup and none of this matters** — `createBlock`/`serialize`
gets every class right by construction, for the version you are targeting.
What follows is for reading markup you did not generate, for debugging a
validator report, and for when you cannot run Node. It is *derivable*: anything
here can be confirmed by serializing the block and looking.

| Attribute | Class the markup must carry |
|---|---|
| `"backgroundColor":"x"` | `has-x-background-color has-background` |
| `"textColor":"x"` | `has-x-color has-text-color` |
| `"gradient":"x"` | `has-x-gradient-background has-background` |
| `"fontSize":"x"` | `has-x-font-size` |
| `"fontFamily":"x"` | `has-x-font-family` |
| `"style":{"typography":{"textAlign":"center"}}` on text blocks | `has-text-align-center` |
| `"align":"wide"` / `"align":"full"` on containers | `alignwide` / `alignfull` |
| `"style":{"color":{"background":"…"}}` | `has-background` + an inline `style` |
| `"className":"is-style-x"` | `is-style-x` |

Structure, for the families patterns are mostly built from:

- **heading** — tag matches `level`; include `wp-block-heading`.
- **group** — `wp-block-group` required.
- **columns / column** — both classes required; an explicit width needs
  `{"width":"33.33%"}` *and* `style="flex-basis:33.33%"`.
- **buttons / button** — `wp-block-buttons` > `wp-block-button` >
  `<a class="wp-block-button__link wp-element-button">`. The anchor is required.
- **image** — `<figure class="wp-block-image">` around the `<img>`; a
  `sizeSlug` obliges the matching `size-*` class or the migration drops it.
  Never invent a `src` (`assets.md`).
- **list / list-item** — each item is its own `core/list-item` block; bare
  `<li>` passes validation and then behaves oddly in the editor.
- **cover** — `wp-block-cover`, the overlay span, and
  `wp-block-cover__inner-container` around the content. The most moving parts
  of any of these; generate it.
- **media-text** — `wp-block-media-text` with `__media` and `__content`
  children, and the grid template inline when `mediaWidth` is not 50.
- **spacer / separator** — a spacer needs its height in both the attribute and
  the inline style, plus `aria-hidden="true"`. A plain separator is
  `<hr class="wp-block-separator has-alpha-channel-opacity"/>`; a bare
  `wp-block-separator` is an old form.

**Self-closing blocks.** A block with no saved inner HTML takes `/-->` and must
have no closing comment — `core/pattern`, `core/post-content`,
`core/site-title` and other dynamic blocks. `list-block-types` reports
`dynamic` per block. Getting it wrong is a parse failure.

```html
<!-- wp:pattern {"slug":"my-theme/header"} /-->
```

## A worked example

```html
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)">
	<!-- wp:heading {"level":2,"style":{"typography":{"textAlign":"center"}},"fontSize":"xx-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-xx-large-font-size">What we do</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"style":{"typography":{"textAlign":"center"}}} -->
	<p class="has-text-align-center">A sentence that says it plainly.</p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
```

Every attribute has its class; every preset appears in both spellings;
`alignfull` accompanies `"align":"full"`; `layout.type` is one of the four. And
every slug is one `design-system.md` says is safe to assume — `base`,
`contrast`, the numeric spacing scale, the font-size ladder — which is what
lets this band land on another theme and take that theme's colours.

The example is checked, not just written: `keeping-current.md` runs the
validator over every example in these documents at each release.
