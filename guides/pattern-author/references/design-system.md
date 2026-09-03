# The design system: tokens, styles and variations

A pattern is markup that leans on the site around it, and there are exactly
three layers it can lean on. They are easy to confuse and they behave
completely differently, so it is worth being precise about which is which:

| | What it is | The question it answers |
|---|---|---|
| **Token** | A named value — `base`, `spacing|40`, `large` | *What is the site's blue?* |
| **Style** | What a block looks like before any pattern speaks — the root font size, the heading face, the link colour, `elements.button` | *What does an unstyled heading look like here?* |
| **Variation** | A named look applied with a class — `is-style-outline` | *How do I ask for a second kind of button?* |

A pattern **references** a token, **inherits** a style, and **applies** a
variation. `get-design-system` returns all three: `palette`/`spacing`/
`fontSizes`/`fontFamilies`, then `styles`, then `blockStyles`.

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
   Usually exactly right — the pattern adopts the site's colours and sizes,
   which is what a design system is for. Wrong only when the two sites mean
   different things by the same name.
3. **Never restate a style.** What the site styles, the pattern inherits.
   Saying it again in the markup is how a pattern stops adapting.

Which turns every naming choice into a choice about **who should win**.

---

# Tokens

## What the evidence says

Comparing what core ships against the three most recent default themes:

| | Core | Twenty Twenty-Three | Twenty Twenty-Four | Twenty Twenty-Five |
|---|---|---|---|---|
| **Colours** | `black`, `white`, `vivid-red`, … (12 named hues) | `base`, `contrast`, `primary`, `secondary`, `tertiary` | `base`, `base-2`, `contrast`, `contrast-2`, `contrast-3`, `accent`…`accent-5` | `base`, `contrast`, `accent-1`…`accent-6` |
| **Font sizes** | `small`, `medium`, `large`, `x-large` | + `xx-large` | + `xx-large` | + `xx-large` |
| **Spacing** | `20`–`80` (from `spacingScale`) | `30`–`80` | `10`–`60` | `20`–`80` |
| **Font families** | none | typeface names | `body`, `heading` | typeface names |
| **Content width** | none | 650px | 620px | 645px |

This is a map of where themes *agree*, which decides whether a slug adapts to
its destination or arrives carrying its own value. It is not a list of what
will break — for a pattern that travels, nothing here breaks — and the one case
where it *is* a breakage map is called out at the end.

### Colours: `base` and `contrast` are the common ground

The only two slugs all three default themes agree on, and they mean what they
say: `base` is the page, `contrast` is the text on it. A pattern using them
lands on any theme and takes that theme's colours, which is the behaviour you
want for anything carrying the site's identity.

Everything else is theme-specific. `primary` is Twenty Twenty-Three's and
neither theme that followed kept the name; `accent-1` is Twenty Twenty-Five's
convention and Twenty Twenty-Four calls the same idea `accent`. None of those
*fail* on a travelled pattern — the value ships and is installed — but none of
them adapt either, so the pattern keeps its own colour on a site that had a
perfectly good one.

Core's own twelve — `vivid-red`, `pale-pink` and the rest — are the wrong
answer for a different reason: a fixed palette no theme designed, ignoring the
site's own colours entirely, and a theme *can* switch them off.

So: **`base` and `contrast` freely. Any other colour, check first** — ask
`get-design-system` what this site has and use what is there. If nothing
suitable exists, add it with `add-design-tokens` under a name that says what it
is for. Never inline a hex.

### Font sizes: the five-step ladder, and know where core stops

`small`, `medium`, `large`, `x-large`, `xx-large` is unanimous among the
default themes. **Core itself stops at `x-large`** — `xx-large` is a theme
convention, so a site running a theme without a theme.json does not have it.

Use the ladder. Do not invent `tiny`, `huge`, `hero` or `display`: a size that
is not on the ladder is a size no theme will have defined.

A coarse ladder is a real constraint and not one to route around. If a site's
`medium` is 20px and its `large` is 36px, the 28px you wanted does not exist —
pick the nearer rung rather than hard-coding, or add the missing rung
deliberately if the design genuinely needs it.

### Spacing: numeric, and only the middle is safe

Every theme uses core's numeric scale, but not the same span: `40`, `50` and
`60` exist in all three, while `10`, `20`, `70` and `80` each go missing
somewhere.

Prefer `40`–`60` for the rhythm a pattern shares with its host. The outer steps
still work — a travelled pattern installs the value it brought — they just stop
adapting.

### Font families: there is no convention

Two of three default themes name the typeface (`manrope`, `dm-sans`); one uses
semantic slugs (`body`, `heading`). Neither is safe to assume.

**Do not set a `fontFamily` on a pattern unless the design requires a specific
face**, and if it does, install it with `add-font`, which registers the preset
and returns the slug. A pattern that sets no font family inherits the site's,
which is almost always what you want.

### Layout: assume about 620px

The default themes sit between 620px and 650px of content width, and 1200px to
1340px wide. A pattern that needs more should declare its own `contentSize` on
its outermost group rather than assume the theme is generous.

## Two tiers, and why a theme wants both

The portable vocabulary above is small, and a real design system needs more
than two colours. The way out is not to invent slugs in place of the standard
ones — that is how a pattern stops travelling — but to define the standard ones
**and** the roles nothing standard covers.

### Tier 1 — the shared vocabulary. Slugs you *want* overridden.

| | Slugs |
|---|---|
| Colour | `base`, `contrast` |
| Font size | `small`, `medium`, `large`, `x-large`, `xx-large` |
| Spacing | `20`, `30`, `40`, `50`, `60`, `70`, `80` |

These carry the *site's* identity rather than the pattern's. Use them precisely
because they collide: a heading at `x-large` should be the destination's
`x-large`, and a band padded with `spacing|60` should breathe the way that site
breathes. Every theme with a `theme.json` has them, so they resolve everywhere
even for a pattern that never went through the cloud.

### Tier 2 — roles worth adding, because no convention covers them

Every design needs these and neither core nor the default themes name them, so
there is no portable answer to inherit — but there is a sensible one to agree
on:

| Slug | For |
|---|---|
| `surface` | A panel raised or inset against `base` — a card, a well |
| `surface-variant` | A second step of the same |
| `text-muted` | Secondary text: captions, meta, supporting copy |
| `primary` | The action colour — buttons, links |
| `primary-hover` | Its hover state |
| `accent` | Emphasis, distinct from the action colour |
| `hairline` | Borders, rules, separators |

These are the pattern's *own* design intent, and they survive a move intact — a
destination that has never heard of `hairline` installs the value the pattern
brought, so the pattern looks as it was drawn. Where the authoring site lacks
one, add it with `add-design-tokens` before uploading: that is the whole reason
the ability exists, and a token that is not in the design system is a token
that does not travel.

**`primary` is a tier-2 name here, and Twenty Twenty-Three also uses it** for
something similar. Twenty Twenty-Four and Twenty Twenty-Five do not, which is
why it is tier 2 rather than tier 1: usable, worth standardising on, not safe
to assume.

### Put the semantics in the name, not the slug

The temptation is to name spacing steps `xs`, `sm`, `md`, `lg` because it reads
better. It reads better and it costs portability: **not one of those slugs
exists on any default theme**, so a pattern padded with `var:preset|spacing|md`
loses every spacing value the moment it leaves the site it was written on.

`name` is the field for that. The slug is the machine reference and belongs to
the numeric scale; the name is what a person sees in the editor:

```json
{ "slug": "40", "name": "Base", "size": "1.25rem" }
```

The same applies to colour. A palette wanting a semantic name for its body text
should call it `contrast` with `"name": "Text"`, not `text-default`.

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

Two things it will not do. **Raw CSS is refused** — WordPress does not sanitize
a theme.json `css` property, it gates it on the `edit_css` capability instead,
and a string that closes its own selector writes rules for the whole document.
And what core's schema does not recognise comes back under `skipped` rather
than vanishing, because an agent that believes it set a property will build the
rest of the design on top of one that is not there.

A theme that sets no root font size is worth watching for. A pattern that names
no size then inherits the browser's, not the site's, and the copy comes out two
steps too small — visible only in a rendered preview, never in the markup.

---

# Block style variations

## The third layer, and when to reach for it

A design usually wants more than one kind of button, and there are three ways
to get one. They are not equal:

- **Attributes on every button** fossilise the design into the markup. The
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
the markup carries the class and no colour at all, so a token named only inside
a variation still ships.

## Names collide, so an upload namespaces them

A variation slug is a name in a shared namespace exactly as a preset slug is,
so two designs that both call something `button-secondary` would collide at any
site holding both — and unlike a colour, a wrong variation can restructure the
block entirely.

An upload therefore stamps the collection onto the name:
`is-style-button-secondary` becomes `is-style-studio-a-heroes-button-secondary`
in the markup, and the definition's slug moves with it. **You do not do this
yourself.** Author under the readable local name; the namespace is applied on
the way up, once, and never rewritten afterwards — a re-upload that renamed it
would install a second identical variation beside the first.

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
   button on a dark band needs its colours set or it vanishes; that is context,
   not taste.

And a rule that falls out of the partial-override problem: **set background and
text colour together or set neither.** Setting one leaves the other at the
theme's value, and the contrast failure is invisible to a pattern that never
read it.

---

# The rules, in short

1. **Colours** — `base` and `contrast` freely, the tier-2 roles by agreement;
   check anything else with `get-design-system`; add what is missing with
   `add-design-tokens`; never inline a hex.
2. **Font sizes** — `small` … `xx-large`, and nothing invented.
3. **Spacing** — numeric slugs, `40`–`60` for preference. Semantics go in
   `name`.
4. **Font families** — set none unless the design needs one; `add-font` if it
   does.
5. **Layout** — assume ~620px of content; declare your own if you need more.
6. **Whatever you reference must exist on the site you are writing on.** A slug
   that does not resolve renders as no styling at all, with no error anywhere,
   and ships no value — so the pattern arrives broken as well.
7. **Read `styles` and set only what they do not cover.** What the site styles,
   the pattern inherits; restating it is how a pattern stops adapting.
8. **A second kind of anything is a variation, not a pile of attributes.**
9. **Write styles only when the design is yours to set** — recreating a design
   or establishing one, not when writing a pattern for somebody's site.

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

```
GET /pattern-builder/v1/preview?pattern=my-theme/hero&context=page&theme=blank-theme
GET /pattern-builder/v1/preview?pattern=my-theme/hero&context=page&theme=opinionated-theme
```

A pattern that renders correctly in both travels. One that renders correctly in
only the site it was written on has hard-coded something.
