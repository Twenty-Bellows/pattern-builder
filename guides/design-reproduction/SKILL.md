---
name: design-reproduction
description: Rebuild an existing design as WordPress block patterns — a live website, a Figma file, a screenshot, a PDF or a mockup — page by page, pulling the reusable parts out rather than transcribing the pixels. Use this whenever the source of the design already exists somewhere else and the job is to reproduce it: "recreate this site", "build this in WordPress", "match this design", "here's a screenshot of what we want". Reproduction fails quietly in two specific ways this covers — values approximated instead of read, and repeated parts copied instead of factored — and both look like a finished page. Load the pattern-author skill alongside it; this one decides what to build and how faithfully, that one is how a pattern gets written.
---

# Reproducing an existing design

You are copying something. That changes two things about the job, and both
fail in ways that look like success.

**The values are somebody else's.** Whether you can *read* them or must
*infer* them decides how exact you may be. Getting this wrong produces a page
that is close at every measure and exact at none.

**The structure is already there to be seen — which is the trap.** A source
hands you six cards, twelve rows, a hundred and forty-six menu items, all
spelled out. Copying them out feels like progress and produces content rather
than patterns.

> **Load `pattern-author` too.** It owns how a pattern is written — vocabulary,
> generation, validation, and the **factor** step this skill leans on at step
> 5. Nothing here repeats it. If you only have this skill, ask the site:
> `pattern-builder/get-authoring-guide`.

**Do only the steps your job needs.** One section or one pattern: steps 1, 2,
3, 5. A whole page or site: all eight. Nothing here waits on a reply — say
what you decided and keep building.

## Workflow

### 1. Classify the source

Everything branches on one question: **can the values be read, or must they be
inferred?**

| Source | Values are | How you get them | What you may claim |
|---|---|---|---|
| A live web page, or theme files you can open | **readable** | parse the page's generated stylesheet; read block attributes | **exact**, and provable by diffing |
| A Figma file you have **access** to | **readable** | variables and styles via the API or an export | exact for tokens; layout still inferred |
| A screenshot, PDF, image, or Figma **export** | **inferred** | sample colours, measure at a stated viewport | *consistent*, never exact — and say so |

Two cautions: **most "Figma" sources are screenshots of Figma** — a PNG of a
frame is the third row, not the second. And **a live page you cannot fetch is
an image**, whatever it technically is.

State the classification in your first reply, as a statement. It sets what you
may claim at the end; it is not a permission slip.

### 2. Extract the design system — and the structure

`references/reading-a-source.md` has the techniques per class.

**Readable.** Read the page's `global-styles-inline-css` block — the whole
design system as the browser resolves it. Then read the *block attributes* for
where the design overrode those defaults; a group may carry its own
`contentSize` or `blockGap`.

**Inferred.** Sample colours. Measure type and spacing at a stated viewport,
and write that width down — no measurement is true at any other. Expect to be
wrong about font stacks, line-heights and any fluid scale.

**Both: the design system is only half of it.** The other half never becomes a
preset — alignment, whether a band is full-width, which way a group flows,
column ratios, which side the image sits on — and it goes straight into block
attributes with nothing downstream to catch it wrong. They are comparisons
*between* elements, never readings of one; `references/reading-a-source.md`
carries the measurement that decides each.

### 3. Write down what you read — two tables

**The tokens**, each with its value and where the value came from:

| Token | Value | Evidence |
|---|---|---|
| `ink` | `#17120E` | `--wp--preset--color--ink`, read |
| `x-large` | `clamp(2.6rem, 1.6rem + 4.4vw, 5rem)` | read |
| `chili` | `#B03A26` | sampled from hero heading at 1400px |

**The structure**, one row per band or element, carrying the comparison you
actually performed:

| Element | Property | Value | Measurement |
|---|---|---|---|
| hero text block | text alignment | centred | subhead inset 180px left, 178px right; headline reaches both margins. The short line is symmetric, so centred |
| hero band | width | `align: full` | painted background reaches both viewport edges at 1400px |
| hero band | content measure | 960px | longest body line 954px, against 1160px in the features band |
| feature row | arrangement | 4 across, 1 row, equal | each card 311px in a 1305px row |
| hero image | ground or sibling | ground (`core/cover`) | the orange field continues behind the headline |
| card image | aspect ratio | 3:2 | rendered box 384×256 |

**If a fact is not in one of these tables, it does not go into an attribute.**
An unwritten guess is indistinguishable from a measurement once it is markup,
and no later step looks at it again.

They are **deliverables, not a gate**: hand them over with the finished work
and keep building. An ambiguous value gets a documented best guess —
`accent-2, sampled #C4551F against the theme's #B84A1E, nearest match` is a row
somebody can reject at a glance, where "which orange did you want?" hands back
the work you were given. The one thing worth front-loading is a design system a
run of patterns will reference, because everything built after it inherits the
error.

### 4. Install it, in this order *(whole page or site)*

**layout → tokens → styles → block style variations.** `set-layout` first: the
widths are what every band's markup agrees with. Then `add-design-tokens`,
because a style referencing an unresolvable slug renders as nothing. Then
`set-global-styles`, then `add-block-style-variation`.

**Prefer starting from a blank theme**, especially for an inferred source. With
nothing to borrow, every value must be declared — which turns a silent
approximation into a written one. Picking "the closest existing font size" is
how a reproduction ends up close at every size and right at none.

### 5. Factor, then build

`pattern-author`'s **factor** step, rules unchanged: inventory what repeats,
name it, take the slots from the diff between occurrences, place each part as
an element, a section or a page. Then build bottom-up.

The only thing this skill adds is emphasis. **Count the repeats before writing
a line.** A source page is the strongest invitation there is to transcribe,
because everything is already spelled out; the output of a loop — a menu, a
product grid, a card deck — is a template someone else already wrote.

### 6. Place the pages *(whole page or site)*

A page pattern is not a page; create one whose content is a single reference to
it (`pattern-author`, step 7).

Watch for blocks that read the *destination's* state rather than carrying their
own: `core/site-title` prints this site's name, `core/navigation` with no inner
blocks fabricates a menu from this site's pages, `core/query` lists this site's
posts. In a reproduction those show the wrong thing while looking like they
work. Supply the content explicitly, or use ordinary blocks and say what you
substituted.

### 7. Check what can be checked

Most of the confidence comes from the steps above, not from looking: markup
generated by the block library is valid by construction, the validator catches
the rest, and `render-pattern` reports any preset you referenced that the site
does not define under `tokens.undefined`. None of that needs a browser.

**Inferred source.** There is no ground truth, so there is nothing to diff and
no reason to open a browser to compare two pictures. Say what you inferred and
could not confirm — the font, line-heights, whether any size is fluid, and any
structural row whose measurement was ambiguous (a `verticalAlignment` where the
columns were equal height, a `mediaPosition` on an already-stacked source) —
and **do not call it a match**. The true sentence is *"consistent with the
design at 1400px, with the font identified as X and these values inferred."*

**Readable source, whole rebuild.** Here a real check exists and is worth the
time: diff computed values rather than looking. `references/verifying.md` has
the method, the properties that most often differ, and what "done" means.

### 8. Say what you left *(whole page or site)*

**List what you added** — presets, styles, block style variations, layout
settings, uploaded images, installed fonts. A reproduction touches far more
than the patterns, and nobody can review or undo what they were not told about.
Anything you created only to look at something, remove; if you could not, say
what and where.

## What you cannot reproduce, and should say so

- **Templates and template parts.** Nothing here writes them. A header and
  footer become patterns referenced by each page — same rendering, not the same
  block-theme structure.
- **Anything needing CSS theme.json has no property for** — pseudo-elements,
  `list-style`, transforms, transitions. Rebuild from real blocks and say what
  changed.
- **Variable font axes.** The font collection serves fixed instances, one file
  per weight. Ask `list-fonts` for the family before promising a match.
- **Images you only have a picture of.** Use placeholders and hand back the
  list of images the person needs to supply — that list is part of the
  deliverable, not a caveat.

## References

- `references/reading-a-source.md` — extracting the design system *and the structure* from each kind of source, and what each cannot tell you
- `references/verifying.md` — the numeric diff for a readable source, and what you may honestly claim
- The `pattern-author` skill — how a pattern is actually written
