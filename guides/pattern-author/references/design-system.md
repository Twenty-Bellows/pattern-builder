# The design system: layout, tokens, styles and variations

A pattern is markup that leans on the site around it. Three layers carry design
decisions, and one setting decides the measure everything is drawn against.
The three are easy to confuse and behave completely differently, so it is worth
being precise about which is which:

| | What it is | The question it answers |
|---|---|---|
| **Token** | A named value — `base`, `spacing\|40`, `large` | *What is the site's blue?* |
| **Style** | What a block looks like before any pattern speaks — the root font size, the heading face, the link color, `elements.button` | *What does an unstyled heading look like here?* |
| **Variation** | A named look applied with a class — `is-style-outline` | *How do I ask for a second kind of button?* |

A pattern **references** a token, **inherits** a style, and **applies** a
variation. `get-design-system` returns all three: `palette`/`spacing`/
`fontSizes`/`fontFamilies`, then `styles`, then `blockStyles` — and `layout`,
which is none of the three but decides how wide every band ends up.

Miss any of them and the failure looks identical and is equally silent: the
block renders with no styling at all, no error anywhere, and it reads as a
design mistake rather than a missing reference.

## What travels, and why that decides where you author

**A pattern going through the cloud carries its tokens and its variations with
it.** On upload, every preset it references and every variation it applies is
looked up *in the authoring site* and shipped, value and all. On download, the
ones the destination lacks are installed and the ones it already has are left
alone.

**Styles do not travel, and must not.** There is one
`styles.elements.link.color.text` on a site and it paints every link on every
page. A pattern that repainted its destination on install would be vandalism,
not an install. So a pattern *inherits* styles and never carries them.

Three things follow, and they are the whole of the discipline:

1. **Everything a pattern references must exist on the site it is authored
   on.** A slug the authoring site does not define ships no value — it is
   skipped, silently — so the pattern arrives referencing something nothing
   defines and renders unstyled there too. The failure is made here and
   discovered there. `render-pattern` reports it under `tokens.undefined`.
2. **A name the destination already defines keeps the destination's value.**
   Usually exactly right — the pattern adopts the site's colors and sizes,
   which is what a design system is for. Wrong only when the two sites mean
   different things by the same name.
3. **Never restate a style.** What the site styles, the pattern inherits.
   Saying it again in the markup is how a pattern stops adapting.

Which turns every naming choice into a choice about **who should win**.

## Where a look goes, whoever is writing it

The abilities can't write a theme stylesheet, so through them a look can only
land in theme.json, a block's settings or a block style variation. With the
theme's files open you can put CSS anywhere, and that's when this matters most,
because nothing stops you. The order is the same either way: put a look in the
first place that can hold it.

- **theme.json**, when it's the site's: the palette, the type and spacing
  scales, and what every heading, link and button looks like.
- **The block's own settings**, using those tokens, when it's this block's: a
  background color, padding from the spacing scale, one of the five font sizes,
  a gap. They're what someone editing the page sees in the sidebar and can
  change. On WordPress 7.1 and later that includes a different value at a
  narrower width: a grid that drops to one column on phones, or padding that
  shrinks, goes under the block's `style["@tablet"]` and `style["@mobile"]`
  (`block-vocabulary.md`, *A different look at another width*).
- **A block style variation's styles**, when it's a named look more than one
  block shares: the color, spacing, border and typography properties in its
  `styles/{slug}.json`.
- **The variation's `css`**, for what those properties can't say about the
  block it's on: a pseudo-element, a mask, a transform, a hover state.
  *CSS in a variation*, below, has what it accepts.
- **The theme's stylesheet**, last, for what none of those can hold, like a
  rule for `::selection` or a focus ring, or a media query for something the
  block's per-viewport settings can't express (any media query, before 7.1),
  and only when the theme is yours to change. Scope each rule to a variation's
  `is-style-` class and the block carrying it, or to an element, and say in the
  handoff what you put there and why nothing above could hold it.

Whichever of these holds it, CSS styles one block. A variation's `css` styles
the block it's applied to and markup that block renders itself, like an image's
`img` or a button's link, and never a block placed inside it. A variation still
styles the blocks inside it, through its JSON: an entry in its `blocks` gives
one kind of block inside it its own properties, and can carry a `css` of its
own, which styles that block the same way and nothing nested in it.

The order breaks in the same few ways, and each one looks finished:

**A class you make up is a styling hook, and the look behind it is hidden.** A
paragraph with `{"className":"card__time"}` and its look in a stylesheet shows
nothing in the editor, tells nobody what it does, and leaves its CSS behind
wherever the pattern goes. When a look needs a name, make it a block style
variation: the editor lists it, the cloud carries it, and anyone can apply it.
The validator notes every class that isn't a variation's.

**CSS that sets what the block already sets makes the editor lie.** A paragraph
whose markup says `fontSize: medium`, overridden in a stylesheet by
`clamp(1.125rem, 1.5vw, 1.375rem)`, shows Medium in the sidebar and ignores
whatever size someone picks there. If the block has a setting for it, the value
belongs in the setting.

**A size the scale doesn't have isn't one to invent.** A 22px label between a
20px `medium` and a 32px `large` is `medium`, with the rounding written into
your tokens table (*Font sizes*, below). A stylesheet full of bespoke `clamp()`
sizes matches the mockup and leaves the site without a type scale.

**CSS that reaches into the blocks inside a variation is one component's
stylesheet.** A group variation whose `css` sizes the heading with `& h2`, tilts
the image with `& .hero__photo` and sets the button's size with `& > * > a` is
keyed to the classes and positions the first pattern happened to use. Rename the
class or move a block and the look stops applying, with nothing to say so, and
nothing on the heading says where its size comes from. Give each piece its look
where it belongs: its own settings, a variation of its own, or the group
variation's `blocks` entry for that kind of block, whose properties and `css`
core scopes to that block inside the variation. If someone can select it in the
editor and move, remove or replace it, it's a block of its own, and no other
block's `css` styles it.

Matching a mockup to the pixel is what pushes all of these. The deliverable is
a page built from blocks that reads as the mockup at its width, not a page that
matches it exactly by working around the blocks.

---

# Tokens

## What the evidence says

Comparing what core ships against the three most recent default themes:

| | Core | Twenty Twenty-Three | Twenty Twenty-Four | Twenty Twenty-Five |
|---|---|---|---|---|
| **Colors** | `black`, `white`, `vivid-red`, … (12 named hues) | `base`, `contrast`, `primary`, `secondary`, `tertiary` | `base`, `base-2`, `contrast`, `contrast-2`, `contrast-3`, `accent`…`accent-5` | `base`, `contrast`, `accent-1`…`accent-6` |
| **Font sizes** | `small`, `medium`, `large`, `x-large` | + `xx-large` | + `xx-large` | + `xx-large` |
| **Spacing** | `20`–`80` (from `spacingScale`) | `30`–`80` | `10`–`60` | `20`–`80` |
| **Font families** | none | typeface names | `body`, `heading` | typeface names |
| **Content width** | none | 650px | 620px | 645px |

This is a map of where themes *agree*, which decides whether a slug adapts to
its destination or arrives carrying its own value. It is not a list of what
will break — for a pattern that travels, nothing here breaks — and the one case
where it *is* a breakage map is called out at the end.

### Colors: a role, never a hue

A color's slug says what the color does on the page, never what it looks like.
`blush`, `forest` and `ink` describe one site's palette: a style variation that
repaints them leaves the names lying, and a pattern written against them means
nothing on a site whose palette is different. `surface`, `primary` and
`hairline` name the same job everywhere.

`base` and `contrast` are the only two slugs all three default themes agree on,
and they mean what they say: `base` is the page, `contrast` is the text on it.
A pattern using them lands on any theme and takes that theme's colors.

Every color is one of these roles, and `add-design-tokens` refuses any slug
that is not:

| Job | Roles |
|---|---|
| Page and panels | `base`, `surface`, `surface-muted`, `surface-strong`, `surface-inverse` |
| Text | `contrast`, `text-muted`, `text-subtle`, `text-inverse` |
| On a filled color | `on-primary`, `on-secondary`, `on-accent` |
| Action | `primary`, `primary-hover`, `secondary`, `secondary-hover`, `tertiary` |
| Emphasis | `accent`, `accent-strong`, `accent-muted` |
| Lines | `hairline`, `hairline-strong` |
| Feedback | `success`, `warning`, `error`, `info` |
| Effects | `overlay`, `highlight` |

**Map each color to the job it does, then name it for that job.** The page
background is `base` whatever its hue; the card sitting on it is `surface`; the
rule between rows is `hairline`; the button is `primary` and its label
`on-primary`; `overlay` is the scrim over an image and `highlight` marked text.
When two colors want one role, the quieter is `-muted` and the louder
`-strong`. The `name` says the role in words — "Surface Muted", not "Blush" —
because it is what a person reads in the color picker, and it has to stay true
after a restyle.

Core's own twelve — `vivid-red`, `pale-pink` and the rest — are a fixed palette
no theme designed, ignoring the site's own colors entirely, and a theme *can*
switch them off. Don't reference them.

So: **ask `get-design-system` what this site has and use the role that is
there.** If the role is missing, add it with `add-design-tokens`. Never inline
a hex.

### Font sizes: five, and no others

`x-small`, `small`, `medium`, `large`, `x-large`. `add-design-tokens` refuses
any other slug, so there is no `tiny`, `huge`, `display`, `xx-large` or
`heading-2`. Core defines `small` through `x-large` on every site, which is what
lets a pattern sized with them take its host's type scale; `x-small` is this
design system's own, and travels with its value.

Each is larger than the one before it. A size added out of that order —
`x-small` larger than `small` — is written with a warning, because a pattern
asking for the smaller one would get the larger.

A coarse ladder is a real constraint and not one to route around. If a site's
`medium` is 20px and its `large` is 36px, the 28px you measured does not exist:
use the nearer of the two. Add a missing one of the five only when your size
falls between its neighbors.

**A heading's size is not a font size.** h1 to h6 take their sizes from
`styles.elements.h1` … `h6` (`typography.fontSize`), set once with
`set-global-styles`, and a heading block then sets no `fontSize` at all: it
takes the size its level has. Those values can be whatever the design measured,
fixed or fluid, because `h2` already says what it is for. Give one heading a
`fontSize` only when it departs from its level, and then use one of the five.

**Text that is not a heading never borrows a heading's size.** An eyebrow, a
lede, a pull quote or a paragraph styled to look like a heading takes the
nearest of the five.

### Spacing: numeric, and only the middle is safe

Every theme uses core's numeric scale, but not the same span: `40`, `50` and
`60` exist in all three, while `10`, `20`, `70` and `80` each go missing
somewhere.

Prefer `40`–`60` for the rhythm a pattern shares with its host. The outer steps
still work — a traveled pattern installs the value it brought — they just stop
adapting.

### Font families: there is no convention

Two of three default themes name the typeface (`manrope`, `dm-sans`); one uses
semantic slugs (`body`, `heading`). Neither is safe to assume.

**Do not set a `fontFamily` on a pattern unless the design requires a specific
face**, and if it does, install it with `add-font`, which registers the preset
and returns the slug. A pattern that sets no font family inherits the site's,
which is almost always what you want.

### Layout: the site's widths are defaults, and a block may override them

`settings.layout.contentSize` is the max-width a constrained block gives its
ordinary children; `wideSize` is what an `.alignwide` child gets. The default
themes sit between 620px and 650px of content, and 1200px to 1340px wide.

**These are defaults, and any constrained block can override them for itself**
by carrying its own `layout` in its attributes:

```html
<!-- wp:group {"layout":{"type":"constrained","contentSize":"38rem"}} -->
```

Core reads the block's own layout first and falls back to the global custom
property only when the block says nothing (`block-supports/layout.php`, where
`$used_layout = $block['attrs']['layout'] ?? $fallback_layout` and the max-width
falls through to `var(--wp--style--global--content-size, none)`). When a block
does override, core emits a per-block `wp-container-core-…-is-layout-{hash}`
class carrying the value.

`styles.spacing.blockGap` works the same way: it sets the site's default gap,
and a block's own `style.spacing.blockGap` overrides it for that block's
children.

**This matters most when reproducing a design.** `get-design-system` tells you
the site's defaults. It does not tell you where the design you are copying
overrode them — that lives in the *block attributes* of the markup, so read
those. If all you have is a rendered page, the overrides are exactly the
generated `wp-container-…-is-layout-…` rules in its inline CSS; grep those
before concluding the site's settings are what the page uses.

A pattern that needs a different measure should declare its own `contentSize`
on the group that needs it. When the whole design is yours to set, set the
site's default once with **`set-layout`** — `contentSize` and `wideSize` are
*settings* rather than presets or styles, so neither `add-design-tokens` nor
`set-global-styles` reaches them. Do it before writing patterns: settle it late
and every band written beforehand has the wrong measure baked into its markup.

`set-layout` also carries `useRootPaddingAwareAlignments`. Turn it on before
giving the root a left/right padding, or every `alignfull` band is inset by
that padding instead of reaching the edges of the window.

## Two tiers, and why a theme wants both

The portable vocabulary is small, and a real design system needs more than two
colors. The way out is not to invent slugs in place of the standard ones — that
is how a pattern stops traveling — but to define the standard ones **and** the
roles nothing standard covers.

### Tier 1 — the shared vocabulary. Slugs you *want* overridden.

| | Slugs |
|---|---|
| Color | `base`, `contrast` |
| Font size | `small`, `medium`, `large`, `x-large` |
| Spacing | `20`, `30`, `40`, `50`, `60`, `70`, `80` |

These carry the *site's* identity rather than the pattern's. Use them precisely
because they collide: a lede at `large` should be the destination's `large`,
and a band padded with `spacing|60` should breathe the way that site
breathes. Every theme with a `theme.json` has them, so they resolve everywhere
even for a pattern that never went through the cloud.

### Tier 2 — the rest of the roles, and `x-small`

Every design needs more than `base` and `contrast`, and neither core nor the
default themes agree on names for the rest. The role table under *Colors* is
the answer this design system settles on — `surface`, `text-muted`, `primary`,
`accent`, `hairline` and the others — with `x-small` beside them for type.

These are the pattern's *own* design intent, and they survive a move intact — a
destination that has never heard of `hairline` installs the value the pattern
brought, so the pattern looks as it was drawn. Where the authoring site lacks
one, add it with `add-design-tokens` before uploading: that is the whole reason
the ability exists, and a token that is not in the design system is a token
that does not travel.

**`primary` is a tier-2 name here, and Twenty Twenty-Three also uses it** for
something similar. Twenty Twenty-Four and Twenty Twenty-Five do not, which is
why it is tier 2 rather than tier 1: usable, worth standardizing on, not safe
to assume.

### Spacing: put the meaning in the name, not the slug

The temptation is to name spacing steps `xs`, `sm`, `md`, `lg` because it reads
better. It reads better and it costs portability: **not one of those slugs
exists on any default theme**, so a pattern padded with `var:preset|spacing|md`
loses every spacing value the moment it leaves the site it was written on.

`name` is the field for that. The slug is the machine reference and belongs to
the numeric scale; the name is what a person sees in the editor:

```json
{ "slug": "40", "name": "Base", "size": "1.25rem" }
```

Color is the one exception, because there the slug is the role, and the role
is what a pattern means by it.

---

# Styles

## Read them before you specify anything

A style is what a block looks like when the pattern says nothing.
`get-design-system` returns them under `styles`, and reading them is what turns
"set nothing and inherit" from a hope into a decision.

The failure it prevents is over-specification. A theme that sets
`styles.elements.heading.typography.fontFamily` gives every heading its face
with nothing in the pattern saying so. A pattern that sets `fontFamily` on each
heading anyway looks identical on that site and stops adapting everywhere else:
it will keep the authoring site's typeface on a site with a perfectly good one
of its own, forever.

So: **read `styles` first, and set only what it does not already cover.**

## Setting them, when the design is yours to set

`set-global-styles` writes the `styles` half of theme.json or Global Styles.
It is the right tool when you are **building the site's design** — recreating a
design, establishing a look on a blank theme, filling a gap the theme left. It
is the wrong tool when you are **writing a pattern for somebody's existing
site**: there, read the styles and conform.

It differs from `add-design-tokens` in every way that matters, and the names
say so. `add-` only ever adds, and a name already taken is skipped. `set-`
**replaces** what is at each property it names, and it changes the whole site
at once, including pages you have not seen. Properties it does not name are
left alone, so you can set one thing without restating the rest.

Two things it will not do. **Raw CSS is refused here** — WordPress does not
sanitize a theme.json `css` property, it gates it on the `edit_css` capability
instead, and a string that closes its own selector writes rules for the whole
document. A `css` at the root or on an element is scoped to nothing a pattern
brought with it, so it stays refused even though a *block style variation* may
carry one (below): there the selector is a class the pattern's own markup
carries, and here it is the whole page. And what core's schema does not
recognize comes back under `skipped` rather than vanishing, because an agent
that believes it set a property will build the rest of the design on top of one
that is not there.

A theme that sets no root font size is worth watching for. A pattern that names
no size then inherits the browser's, not the site's, and the copy comes out two
steps too small — visible only in a rendered preview, never in the markup.

---

# Block style variations

## The third layer, and when to reach for it

A design usually wants more than one kind of button, and there are three ways
to get one. They are not equal:

- **Attributes on every button** fossilize the design into the markup. The
  radius a pattern states is the radius it keeps wherever it goes, long after
  the site's buttons have changed.
- **`styles.elements.button`** is one rule for every button on the site, so it
  cannot describe a second kind at all.
- **A block style variation** is a named look, applied by putting
  `is-style-{slug}` on the block, scoped to exactly the blocks carrying the
  class. Registered with `add-block-style-variation`, which writes a
  `styles/{slug}.json` partial into the theme — the file that both registers
  and styles it. (A `styles.blocks.variations` key in theme.json only *styles*
  a variation something else registered; core drops a variation node whose name
  is not in the block style registry.)

That scoping is also what makes a variation the only styling a pattern can
honestly bring with it: the selector needs a class the pattern's own markup
carries, so installing one changes nothing the pattern did not put there.

## Which ones travel

`get-design-system`'s `blockStyles` lists what is registered here, keyed by
block, each with the `class` to apply and a `portable` flag.

- **`portable: true`** — declared in the block's own `block.json`, so it ships
  with WordPress itself: `is-style-outline` on a button, `is-style-wide` on a
  separator. Use these freely. Hand-rolling what one of them already does is
  the mistake, and they need no carrying — an upload deliberately leaves them
  behind.
- **`portable: false`** — registered by this site. **These do travel through
  the cloud**: an upload collects the definition and a download installs it if
  the destination lacks it, exactly as a token does. What `portable: false`
  means is that the definition is *this site's* and has to be carried; a
  pattern copied by hand, outside the cloud, arrives with the class and nothing
  styling it.

So the discipline is the token discipline again: **the variation must exist on
the site you author on**, and a name the destination already holds wins.

A variation's own styles may reference presets, and those are collected too —
the markup carries the class and no color at all, so a token named only inside
a variation still ships. So does its `css`, below.

## CSS in a variation

The style properties cannot express a pseudo-element, a hover state or a rule
for markup the block renders itself, and those are most of what a variation is
for. So a variation, alone among the things these abilities write, may carry
CSS in two places and no others: a `css` string at the top of its `styles`, and
one in each entry of its `blocks`. Writing the theme's files yourself, put that
CSS here too rather than in a stylesheet: here it's scoped to the variation's
class and travels with the definition.

Each of those strings styles one block, with `&` standing for it. The top one
styles the block the variation is applied to. A `blocks` entry's styles that
kind of block inside the variation, and core scopes it there:
`blocks.core/heading.css` prints scoped to `.is-style-band-dark--{n} .wp-block-heading`.
Either may reach markup its own block renders, like an image's `img`, a
button's link or a search's input, and neither reaches into another block. The
blocks inside a variation are styled through its JSON, `blocks` and `elements`
(which is how a dark band inverts its link color, *What the schema cannot tell
you*, below), never by a selector in the variation's own `css`.

```json
{
  "slug": "band-dark",
  "blockTypes": ["core/group"],
  "styles": {
    "css": "position: relative; isolation: isolate; overflow: hidden; &::before { content: \"\"; position: absolute; inset: 0; z-index: -1; background-image: radial-gradient(rgba(255, 255, 255, 0.12) 1px, transparent 1px); background-size: 22px 22px; }",
    "blocks": {
      "core/heading": {
        "typography": { "letterSpacing": "0.04em" },
        "css": "&::after { content: \"\"; display: block; width: 3rem; margin-top: 0.5rem; border-bottom: 2px solid currentColor; }"
      }
    }
  }
}
```

The dots sit behind the band's content without a rule on anything inside it.
`isolation: isolate` makes the band its own stacking context, so the
pseudo-element's `z-index: -1` puts it above the band's background and below
everything in the band. The usual alternative, `& > * { z-index: 1 }`, gets
there by reaching into every block placed in it. The headings get their
tracking and the short rule under them from the `blocks` entry, where `&` is the
heading.

The shape is **declarations first, then nested rules anchored on `&`** — and
the order matters: WordPress folds a declaration written after a rule into that
rule, so one written there is refused rather than silently moved.

It travels with the pattern like the rest of the definition, and it is checked
three times on the way: where you write it, on the service, and on the site
that installs it. What the check refuses, and why:

| Refused | Because |
|---|---|
| `url()`, `image-set()`, `attr()`, `expression()` — any function not on a fixed allow list | a stored pattern fetches nothing, and an unknown name is refused rather than ignored |
| `@media` and every other at-rule | core's parser cannot place one here at all, so a media query in variation CSS does not work today. On 7.1 and later, a difference at a width goes on the block's own per-viewport settings instead |
| comments (`/* … */`) | stripping them correctly means stripping them exactly as a browser does |
| `<`, anywhere, quoted strings included | `</style` ends the style element whatever CSS thinks a string is |
| `,`, `+` and `~` in a selector | a comma escapes the variation's scope and lands `body` at the top level; a sibling combinator reaches outside the block the pattern carries |
| a backslash outside a quoted string | an escape can spell `u\72l(` past a name check. Inside a string it is fine and necessary — `content: "\2713"` is how an icon glyph is written |
| a rule nested inside a rule | WordPress reads one level and drops the rest |

Caps: 2000 characters, 20 nested rules, 40 declarations each. `!important` is
allowed; `:not()`, `:is()`, `:where()`, `:has()` and the `nth-` family are
allowed but single-argument only, since the comma is out.

Nothing is stripped or repaired — a refusal names the rule you broke and the
fragment that broke it, so the fix is usually one edit away.

## Names collide, so an upload namespaces them

A variation slug is a name in a shared namespace exactly as a preset slug is,
so two designs that both call something `button-secondary` would collide at any
site holding both — and unlike a color, a wrong variation can restructure the
block entirely.

An upload therefore stamps the collection onto the name:
`is-style-button-secondary` becomes `is-style-studio-a-heroes-button-secondary`
in the markup, and the definition's slug moves with it — as does any
`is-style-` its own `css` names. **You do not do this yourself.** Author under
the readable local name; the namespace is applied on the way up, once, and
never rewritten afterwards — a re-upload that renamed it would install a second
identical variation beside the first.

---

# Buttons, since everyone hits this one

`core/button` renders `wp-element-button`, and `styles.elements.button` paints
every button on the site. A pattern that sets background, radius, padding,
weight and size has re-specified the whole control, and none of it follows the
site when its buttons change.

A *partial* override is worse than a full one: a background and nothing else
takes the fill and leaves the shape, producing a button that belongs to neither
design.

In order of preference:

1. **Set nothing.** That is the site's button, which is what a primary action
   should be.
2. **Apply a portable variation** — `is-style-outline` for the secondary
   action.
3. **Add a variation** with `add-block-style-variation` if the design needs a
   kind core has no name for.
4. **Set attributes** only when the pattern knows something the theme
   structurally cannot — chiefly what the button is sitting on. A secondary
   button on a dark band needs its colors set or it vanishes; that is context,
   not taste.

And a rule that falls out of the partial-override problem: **set background and
text color together or set neither.** Setting one leaves the other at the
theme's value, and the contrast failure is invisible to a pattern that never
read it.

---

## What theme.json can express: read the schema, don't guess

WordPress publishes the **official JSON Schema** for `theme.json`, and it is
the authoritative answer to "is there a property for this?" — better than
memory, better than reading core:

```
https://schemas.wp.org/trunk/theme.json          # the development version
https://schemas.wp.org/wp/6.8/theme.json         # pinned to a release
https://schemas.wp.org/wp/7.1/theme.json
```

Pin the version to the site you are writing for. You already know it:
`pattern-builder/get-editor-scripts` reports it as `wordpress`. Reach for the
schema whenever you are about to assume a property exists, and again whenever
something you wrote did not take — a property theme.json does not have is
dropped silently, which reads as a style that had no effect.

The two facts most often guessed wrong, both confirmed there:

- **`styles.dimensions`** is `aspectRatio`, `height`, `minHeight`, `minWidth`
  and `width`. So a fixed-size decorative element — a 3rem rule under a
  heading — *can* be a block style variation and travel with the pattern.
- **There is no `max-width` in `styles` at all.** The only max-width in the
  system is `settings.layout.contentSize` / `wideSize`. To cap a paragraph's
  measure you wrap it in a constrained group with its own `contentSize`; there
  is no property to set on the paragraph.

### What the schema cannot tell you

Three things verified by testing rather than by reading, because the schema
describes shape and not behavior:

- **A block style variation may carry `elements`.** `styles.blocks.{block}.variations.{name}.elements.link` is valid, which is
  how a variation inverts link colors on a dark band — something no element
  style can do, because there is only one `elements.link` per site.
- **There are no pseudo-*elements*.** Elements accept pseudo-*classes*
  (`:hover`, `:focus`, `:visited` and the rest), and nothing anywhere accepts
  `::before` or `::after`. A design that draws a rule or a leader with a
  pseudo-element has to be rebuilt from real blocks.
- **A partial cannot carry a block state.** `core/button` and
  `core/navigation-link` accept `:hover`, `:focus`, `:focus-visible` and
  `:active` under their variation node in theme.json — but core reads a
  `styles/*.json` partial through the whole-theme schema before it files the
  styles under that node, and a whole-theme tree has no states, so a `:hover`
  written in the partial is dropped on read with nothing said.
  `add-block-style-variation` reports it as skipped and says where it goes:
  `set-global-styles` with
  `{"blocks":{"core/button":{"variations":{"<slug>":{":hover":{…}}}}}}`,
  after the variation exists. A state set that way styles this theme's
  buttons and does not travel with a pattern — the cloud carries the partial
  and nothing else — so a design whose hover states matter should say so when
  it ships.
- **Core's flow layout beats a variation on margins.** A container with a block
  gap emits `> * { margin-block-end: 0 }` at the same specificity as a block
  style variation's rule, and wins on source order. So a variation's
  `spacing.margin` is unreliable inside a flow container: put the *look* in the
  variation and the *contextual spacing* in the block's own attributes, where
  an inline style beats both.

Also not expressible at all, so don't design around them: `list-style`,
`object-fit` (use the block's own `scale` attribute), `display`, `flex`,
transforms and transitions.

# The rules, in short

1. **Colors** — every slug is a role from the table, never a hue; `base` and
   `contrast` freely; check the rest with `get-design-system`; add what is
   missing with `add-design-tokens`; never inline a hex.
2. **Font sizes** — `x-small`, `small`, `medium`, `large`, `x-large`, and
   nothing else; the nearest of them when your size falls between two. Heading
   sizes go on `styles.elements.h1` … `h6`, and heading blocks set no
   `fontSize`.
3. **Spacing** — numeric slugs, `40`–`60` for preference. Semantics go in
   `name`.
4. **Font families** — set none unless the design needs one; `add-font` if it
   does. Ask `list-fonts` for that `family` first: it says which weights exist,
   and whether the family has a variable face. Most do not — the collection
   serves one fixed file per weight, cut at the family's default optical size —
   and a design leaning on a variable axis will set wider or narrower than the
   original with nothing to say why.
5. **Layout** — the site's widths are defaults; a block may override them on
   its own `layout` attribute. Set the site's with `set-layout` when the design
   is yours, and do it before writing patterns.
6. **Check the schema before assuming a property exists** —
   `https://schemas.wp.org/wp/{version}/theme.json`.
7. **Whatever you reference must exist on the site you are writing on.** A slug
   that does not resolve renders as no styling at all, with no error anywhere,
   and ships no value — so the pattern arrives broken as well.
8. **Read `styles` and set only what they do not cover.** What the site styles,
   the pattern inherits; restating it is how a pattern stops adapting.
9. **A second kind of anything is a variation, not a pile of attributes.**
10. **Write styles only when the design is yours to set** — recreating a design
    or establishing one, not when writing a pattern for somebody's site.
11. **Put a look in the first place that can hold it**: theme.json, the block's
    settings, a variation's styles, its `css`, and the theme's stylesheet last,
    scoped to a variation or an element. No made-up classes, no CSS setting
    what the block already sets, and no sizes the scale doesn't have. That
    holds when you edit the theme's files yourself too.
12. **A variation's CSS styles one block.** Its top-level `css` styles the block
    it's applied to, and a `blocks` entry's `css` that kind of block inside it.
    Each reaches its own block's pseudo-elements, states and rendered markup,
    and never a block nested in it: the blocks inside a variation are styled
    through its JSON, not by a selector.

## The one case where the evidence above *is* a breakage map

A pattern that never goes through the cloud carries nothing. Copied into a
theme by hand, shared as a file, pasted between sites — there is no upload to
collect its tokens and variations and no download to install them, so it
resolves against whatever the destination happens to define, and the agreement
table is the whole story. If a pattern is meant to travel that way, stay inside
tier 1 and use only portable variations.

## Checking a pattern against both worlds

`render-pattern` returns preview URLs, and they take a `theme` parameter naming
a theme to render against without activating it. Two themes ship with the
plugin for exactly this:

- **`blank-theme`** has no presets at all, and the preview carries the
  pattern's own into it — the same values an upload would ship, filling only
  what the theme lacks, exactly as a download does. So what you see is the
  pattern with its own design and nothing on top: **its intent**. If it looks
  right here, the pattern is self-consistent and every token it needs is really
  in the design system. If something renders unstyled here, that token is
  missing at the authoring end and the pattern would arrive broken anywhere.
- **`opinionated-theme`** follows every convention above with values chosen to
  be nobody's defaults: `base` is a warm cream, `medium` is 1.15rem, the
  content width is 560px, the body face is a serif, and it registers one block
  style variation of its own. A pattern that references the right slugs looks
  *different but correct*. A pattern that hard-codes looks wrong.

`render-pattern` hands both URLs back under `preview.themes`, so there is
nothing to compose:

```json
"preview": {
  "standalone": "…/pattern-builder/v1/preview?pattern=my-theme%2Fhero&context=standalone",
  "page":       "…/pattern-builder/v1/preview?pattern=my-theme%2Fhero&context=page",
  "themes": {
    "blank-theme":       "…&context=page&theme=blank-theme",
    "opinionated-theme": "…&context=page&theme=opinionated-theme"
  }
}
```

A pattern that renders correctly in both travels. One that renders correctly in
only the site it was written on has hard-coded something.
