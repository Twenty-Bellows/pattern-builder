# Reading a design out of its source

How to get the values, per source class, and — as important — what each class
cannot tell you. Classify first (`SKILL.md` step 1); everything here branches
on that answer.

## Readable: a live web page

If the source is a block theme, the whole design system is in the page,
resolved, in one place.

### The generated stylesheet is the design system

WordPress writes every preset, the root and element styles, and the layout
widths into one inline stylesheet:

```bash
curl -s "$SOURCE_URL" \
  | sed -n '/id="global-styles-inline-css"/,/<\/style>/p'
```

Read out of it:

| Looking for | In the stylesheet |
|---|---|
| colours, spacing, font sizes, families | `--wp--preset--{type}--{slug}` on `:root` |
| the measure and the wide measure | `--wp--style--global--content-size`, `--wp--style--global--wide-size` |
| the default gap between blocks | `--wp--style--block-gap` |
| root type and colour | the `body` rule |
| buttons, links, headings | `:root :where(.wp-element-button…)`, `:root :where(a…)`, the `h1`–`h6` rules |
| block styling | `:root :where(.wp-block-…)` |

**Transcribe, do not approximate.** A `clamp()` copied exactly is exact; the
same clamp eyeballed to "about 2.6rem" is a design that reads slightly wrong
at every viewport and right at none.

### Then read the block attributes, because the stylesheet is only the defaults

The site's `settings.layout` is the width a constrained block gives its
children *unless the block carries its own*. A group may declare
`{"layout":{"type":"constrained","contentSize":"38rem"}}`; another may declare
its own `style.spacing.blockGap`. Core reads the block's own value first and
falls back to the global custom property only when the block says nothing.

Where you can see the source's markup, read it there. Where you only have the
rendered page, those overrides appear as generated per-block rules:

```bash
curl -s "$SOURCE_URL" | grep -o 'wp-container-core-[a-z-]*-is-layout-[0-9a-f]*[^}]*}' | head -40
```

A `max-width` in one of those is a block overriding the measure. A `gap` is a
block overriding the block gap. Miss them and every band comes out the right
colour and the wrong width.

### What a live page still cannot tell you

- **Which parts the author considered one thing.** The page shows you a
  hundred and forty-six rendered items; whether they came from a loop, a
  pattern or a hundred copies is not in the HTML. Factor on the repetition you
  can see (`pattern-author`, step 4).
- **Anything behind an interaction** — hover, focus, a menu that opens.
  Hover states *are* in the stylesheet if the theme set them; anything driven
  by JavaScript is not.
- **What the design meant.** A colour used once for a warning and once for a
  price is one hex in the stylesheet and two roles in the design.

## Readable: a Figma file you have access to

Figma has real variables and styles, so tokens are readable and worth reading
rather than sampling: colours, type ramps and spacing come out as named values
with the names attached, which is exactly what `add-design-tokens` wants.

Two cautions:

- **Layout is still inferred.** A frame width is not a `contentSize`, and
  absolute positions are not a block layout. You are reading tokens, not a
  structure.
- **A PNG or PDF export is not this row.** It is an image. See below.

## Inferred: a screenshot, a PDF, an image, a Figma export

You are measuring a picture. Everything you produce is an estimate, and the
job is to make the estimates explicit rather than to hide them.

**Colours** are the one thing you can be nearly exact about: sample the pixel.
Watch for colours that are a tint of another over a background rather than
their own token — a "light grey card" on cream is often the same ink at low
alpha, and guessing wrong gives you two tokens where the design has one.

**Type** is measurable but not readable. You can get a pixel size at the
viewport you measure; you cannot get the font stack, the line-height as a
ratio, or whether the size is fluid. State the viewport with every
measurement, because none of them are true at any other width.

**Spacing** measures the same way and suffers the same limit. Look for a
repeated step rather than recording every gap separately — designs are built
on scales, and recovering the scale gives you a system instead of forty
numbers.

**Font identification** is a guess. Say it is a guess, name your best match,
and ask `list-fonts` whether that family exists before promising anything.

**Images** you do not have. You have a picture of them. Use
`add-placeholder-image` at the right aspect ratio and collect the list of what
the person needs to supply — that list is part of the deliverable.

### Start from a blank theme

With an inferred source especially. A populated theme offers you a nearby
value for everything, and taking it is how a reproduction ends up close at
every size and exact at none — the wrong ladder is invisible because each rung
looks plausible. A blank theme has nothing to borrow, so every value must be
declared, which turns a silent approximation into a written one.

## Whatever the source: write it down before you install it

The artifact from `SKILL.md` step 3 — every value with its evidence. For a
readable source it is a transcription record and a row without evidence is a
row you guessed. For an inferred source it goes to the person who asked
**before** you build, because it is the only correction opportunity that costs
nothing.
