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

### The structure is readable here too — so read it, don't eyeball it

Alignment, band width, column ratios, media position and the rest are as
readable as any token when the source is. Where you can see the markup they
*are* attributes — `align`, `layout`, `verticalAlignment`, `mediaPosition`, a
column's `width` — and copying them is transcription. Where you only have the
rendered page, the computed style carries them: `text-align`,
`justify-content`, `align-items`, `flex-direction` on the container, plus the
element's own box.

Falling back to looking at the picture, on a source you could have read, is the
same error as eyeballing a `clamp()`. The one trap peculiar to this half:
**a computed `text-align` may be inherited rather than set.** A group with
`textAlign` centred and a group whose three children are each centred compute
identically and render identically, and only one of them matches the source's
structure. Check which element the declaration is actually on.

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

### Structure first — and measure it as a comparison

Read the structure before the pixels, and write down what you read. Structural
facts are the ones with no safety net anywhere downstream: a mistyped preset
slug renders as nothing and `render-pattern` reports it under
`tokens.undefined`; a missing supports class is a line in the validator's
report. A wrong `textAlign` is a valid block, a clean validation, a rendered
page and the wrong design. Nothing will tell you. So these need *more*
discipline than the values that become presets, not less.

**Every fact below is a comparison between elements, never a reading of one.**
Where a single element sits on the page is almost never diagnostic; the
relationship between elements of *different lengths* is what carries the
answer. "The headline starts near the left margin" is not evidence of
left-alignment — a near-full-width line starts near the left margin whether it
is centred or left-aligned. Measure the short thing against the long thing.

| Fact | Lands in | The measurement that decides it |
|---|---|---|
| **Text alignment** | `textAlign` on text blocks; `justifyContent` on a buttons row; `contentPosition` on a cover | Compare the elements that are **not** the widest. Equal left edges = left; insets symmetric from both sides = centred. The widest element in a band tells you nothing. |
| **Band width** | `align: full`, `wide`, or neither | Does the band's **painted background** reach the viewport edge, stop at a width wider than the text, or share the text's width? Measure the background, not the words — a full-width band with constrained content looks inset if you measure the words. |
| **Content measure** | `layout.contentSize` on the band, against the site's | Width of the longest full line of body text, compared band to band. A band whose text stops short of every other band's carries its own `contentSize`. |
| **Arrangement** | `core/columns` for a fixed count, `layout.type: grid` with `columnCount` for a wrapping one. **Never a flex row** — see `pattern-author`'s vocabulary | Count the items **in one row** and the number of rows: "4 across, 2 rows" is the fact. "It is a row" is not, because it does not say how the children are sized, and a flex row of card-sized children is a vertical stack. Measure one child's width against the row's: 311 in 1305 is four across. |
| **Ground or sibling** | `core/cover` when the image is the band's background; `core/media-text` or `core/columns` when it sits beside the text | Does the text sit *on* the image, or next to it? Check whether the image's colour continues behind the words. This is the most visible structural error there is — read it as a sibling and an overlaid hero becomes a text block with a picture underneath. |
| **Column ratios** | `width` on each `core/column` | Each column's box as a percentage of their shared parent. Near-equal means leave `width` off entirely; recording `50.4% / 49.6%` freezes your screenshot's rounding error into the markup as a deliberate asymmetry. |
| **Vertical alignment** | `verticalAlignment` on columns and media-text | Only measurable when the columns' heights differ: equal content tops = `top`, equal bottoms = `bottom`, centres equidistant = `center`. With equal heights it is unmeasurable — record that rather than picking one. |
| **Media position** | `mediaPosition` on `core/media-text` | Which side the image is on at the measured viewport. A source screenshot taken narrow enough to have stacked shows no side at all, so it cannot answer this. |
| **Image aspect ratio** | `aspectRatio`, or `width` + `height` | Measure the image's rendered box, not the subject inside it, then reduce to a familiar ratio — 3:2, 16:9, 1:1. A raw pixel pair is a coincidence of your screenshot's scale. |
| **Corner radius** | `style.border.radius` | Zoom a corner and decide *square or not* explicitly. At screenshot scale a 4px radius reads as square, and a card design missing it looks subtly wrong on every card. |
| **Capitals** | `style.typography.textTransform`, or the copy itself | A run reading as capitals is either a transform or the words. Getting this wrong is the most durable mistake here: typing the caps into the copy renders identically, passes every check, and hands the editor a string nobody can un-capitalise. |

The exact attribute spellings — and the ones that moved between WordPress
versions, `textAlign` in particular — are in `pattern-author`'s
`references/block-markup.md`. Read them there rather than from this table.

What no single image can answer, whatever you measure: what happens at any
other viewport, source order behind a stack, and anything that only appears on
interaction. Those are declarations, not measurements.

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

## Whatever the source: write it down before you install it

The two artifacts from `SKILL.md` step 3 — the tokens, and the structure —
every value with its evidence. For a readable source they are a transcription
record and a row without evidence is a row you guessed. For an inferred source
they are how a guess becomes correctable, which is why they are written at all.

**Written down and handed over — not waited on.** They travel with the work, or
ahead of it where a design system is about to be installed and everything
after would inherit the error. Neither case stops the build.

Both tables, not just the tokens. A design system with every colour traced and
every alignment assumed is the failure this document's structure section
exists for: the tokens are the half that has other checks, and the structure is
the half that has none.
