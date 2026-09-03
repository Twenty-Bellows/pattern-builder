# Design tokens: which slugs to reach for

A pattern references a site's design system by slug — `"backgroundColor":"base"`,
`var:preset|spacing|50`, `"fontSize":"large"`. A slug the site defines resolves;
a slug it does not resolves to **nothing at all**, silently. No error, no
warning, no fallback: the block simply renders with no background, or at the
default size, looking like a design mistake rather than a missing reference.

WordPress does not reconcile this. Core defines presets, themes define presets,
and nothing enforces a vocabulary.

**A pattern that travels through the cloud carries its tokens with it**, which
changes the question. On upload, every preset the pattern references is looked
up *in the authoring site* and shipped with it, value and all. On download, the
ones the destination lacks are installed and the ones it already has are left
alone. So the destination is not where a token goes missing.

Two things follow, and they are the whole of the discipline:

1. **Every preset a pattern references must exist on the site it is authored
   on.** A slug the authoring site does not define ships no value — it is
   skipped, silently — so the pattern arrives referencing something nothing
   defines and renders unstyled there too. The failure is made here and
   discovered there. `render-pattern` reports it under `tokens.undefined`.
2. **A slug the destination already defines keeps the destination's value.**
   That is usually exactly right: the pattern adopts the site's colours and
   sizes, which is what a design system is for. It is wrong only when the two
   sites mean different things by the same name.

Which turns the choice of slug into a choice about **who should win**.

## What the evidence says

Comparing what core ships against the three most recent default themes:

| | Core | Twenty Twenty-Three | Twenty Twenty-Four | Twenty Twenty-Five |
|---|---|---|---|---|
| **Colours** | `black`, `white`, `vivid-red`, … (12 named hues) | `base`, `contrast`, `primary`, `secondary`, `tertiary` | `base`, `base-2`, `contrast`, `contrast-2`, `contrast-3`, `accent`…`accent-5` | `base`, `contrast`, `accent-1`…`accent-6` |
| **Font sizes** | `small`, `medium`, `large`, `x-large` | + `xx-large` | + `xx-large` | + `xx-large` |
| **Spacing** | `20`–`80` (from `spacingScale`) | `30`–`80` | `10`–`60` | `20`–`80` |
| **Font families** | none | typeface names | `body`, `heading` | typeface names |
| **Content width** | none | 650px | 620px | 645px |

This is a map of where themes *agree*, which is what decides whether a slug
adapts to its destination or arrives carrying its own value. It is not a list of
what will break — for a pattern that travels through the cloud, nothing here
breaks — and the one case where it is a breakage map is called out at the end.

### Colours: `base` and `contrast` are the common ground

They are the only two slugs all three default themes agree on, and they mean
what they say — `base` is the page, `contrast` is the text on it. A pattern
using them lands on any theme and takes that theme's colours, which is the
behaviour you want for anything carrying the site's identity.

Everything else is theme-specific. `primary` is Twenty Twenty-Three's and
neither of the two themes that followed it kept the name; `accent-1` is Twenty
Twenty-Five's convention and Twenty Twenty-Four calls the same idea `accent`.
None of those *fail* on a travelled pattern — the value ships and is installed —
but none of them adapt either, so the pattern keeps its own colour on a site
that had a perfectly good one.

Core's own twelve — `vivid-red`, `pale-pink` and the rest — are the wrong answer
for a different reason: they are a fixed palette no theme designed, they ignore
the site's own colours entirely, and a theme *can* switch them off.

So: **`base` and `contrast` freely. Any other colour, check first** — ask
`get-design-system` what this site actually has, and use what is there. If
nothing suitable exists, add it with `add-design-tokens` under a name that says
what it is for. Never inline a hex.

### Font sizes: the five-step ladder, and know where core stops

`small`, `medium`, `large`, `x-large`, `xx-large` is unanimous among the default
themes. **Core itself stops at `x-large`** — `xx-large` is a theme convention, so
a site running a theme without a theme.json does not have it.

Use the ladder. Do not invent `tiny`, `huge`, `hero` or `display`: a size that
is not on the ladder is a size no theme will have defined.

### Spacing: numeric, and only the middle is safe

Every theme uses core's numeric scale, but not the same span of it: `40`, `50`
and `60` exist in all three, while `10`, `20`, `70` and `80` each go missing
somewhere.

Prefer `40`–`60` for the rhythm a pattern shares with its host, since those are
the steps most likely to be the site's own. The outer steps still work — a
travelled pattern installs the value it brought — they just stop adapting.

### Font families: there is no convention

Two of three default themes name the typeface (`manrope`, `dm-sans`); one uses
semantic slugs (`body`, `heading`). Neither is safe to assume.

**Do not set a `fontFamily` on a pattern unless the design requires a specific
face**, and if it does, install it with `add-font`, which registers the preset
and returns the slug to use. A pattern that sets no font family inherits the
site's, which is almost always what you want.

### Layout: assume about 620px

The default themes sit between 620px and 650px of content width, and 1200px to
1340px wide. A pattern that needs more should declare its own `contentSize` on
its outermost group rather than assume the theme is generous.

## Two tiers, and why a theme wants both

The portable vocabulary above is small, and a real design system needs more than
two colours. The way out is not to invent slugs in place of the standard ones —
that is how a pattern stops travelling — but to define the standard ones **and**
the roles nothing standard covers.

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
there is no portable answer to inherit — but there is a sensible one to agree on:

| Slug | For |
|---|---|
| `surface` | A panel raised or inset against `base` — a card, a well |
| `surface-variant` | A second step of the same |
| `text-muted` | Secondary text: captions, meta, supporting copy |
| `primary` | The action colour — buttons, links |
| `primary-hover` | Its hover state |
| `accent` | Emphasis, distinct from the action colour |
| `hairline` | Borders, rules, separators |

These are the pattern's *own* design intent, and they are the ones that survive
a move intact — a destination that has never heard of `hairline` installs the
value the pattern brought, so the pattern looks as it was drawn. Where the
authoring site lacks one, add it with `add-design-tokens` before uploading:
that is the whole reason the ability exists, and a token that is not in the
design system is a token that does not travel.

**`primary` is a tier-2 name here, and Twenty Twenty-Three also uses it** for
something similar. Twenty Twenty-Four and Twenty Twenty-Five do not, which is
why it is tier 2 rather than tier 1: usable, worth standardising on, not safe to
assume.

### Put the semantics in the name, not the slug

The temptation is to name spacing steps `xs`, `sm`, `md`, `lg` because it reads
better. It reads better and it costs portability: **not one of those slugs
exists on any default theme**, so a pattern padded with `var:preset|spacing|md`
loses every spacing value the moment it leaves the site it was written on.

`name` is the field for that. The slug is the machine reference and belongs to
the numeric scale; the name is what a person sees in the editor, and it can say
whatever you like:

```json
{ "slug": "40", "name": "Base", "size": "1.25rem" }
```

The same applies to colour. A palette wanting a semantic name for its body text
should call it `contrast` with `"name": "Text"`, not `text-default`.

## The rules, in short

1. **Colours** — `base` and `contrast` freely, the tier-2 roles by agreement;
   check anything else with `get-design-system`; add what is missing with
   `add-design-tokens`; never inline a hex.
2. **Font sizes** — `small` … `xx-large`, and nothing invented.
3. **Spacing** — numeric slugs, `40`–`60` for preference. Semantics go in `name`.
4. **Font families** — set none unless the design needs one.
5. **Layout** — assume ~620px of content; declare your own if you need more.
6. **Whatever you reference must exist on the site you are writing on.** A slug
   that does not resolve renders as no styling at all, with no error anywhere,
   and ships no value — so the pattern arrives broken as well.

### The one case where the evidence above *is* a breakage map

A pattern that never goes through the cloud carries nothing. Copied into a
theme by hand, shared as a file, pasted between sites — there is no upload to
collect its tokens and no download to install them, so it resolves against
whatever the destination happens to define and the agreement table is the whole
story. If a pattern is meant to travel that way, stay inside tier 1.

## Checking a pattern against both worlds

`render-pattern` returns preview URLs, and they take a `theme` parameter naming
a theme to render against without activating it. Two themes ship with the plugin
for exactly this:

- **`blank-theme`** has no presets at all, and the preview carries the pattern's
  own into it — the same values an upload would ship, filling only what the
  theme lacks, exactly as a download does. So what you see is the pattern with
  its own design and nothing on top of it: **its intent**. If it looks right
  here, the pattern is self-consistent and every token it needs is really in the
  design system. If something renders unstyled here, that token is missing at
  the authoring end and the pattern would arrive broken anywhere.
- **`opinionated-theme`** follows every convention above with values chosen to
  be nobody's defaults: `base` is a warm cream, `medium` is 1.15rem, the content
  width is 560px, the body face is a serif. A pattern that references the right
  slugs looks *different but correct*. A pattern that hard-codes looks wrong.

```
GET /pattern-builder/v1/preview?pattern=my-theme/hero&context=page&theme=blank-theme
GET /pattern-builder/v1/preview?pattern=my-theme/hero&context=page&theme=opinionated-theme
```

A pattern that renders correctly in both travels. One that renders correctly in
only the site it was written on has hard-coded something.
