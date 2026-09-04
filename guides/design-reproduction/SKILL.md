---
name: design-reproduction
description: Rebuild an existing design as WordPress block patterns — a live website, a Figma file, a screenshot, a PDF or a mockup — page by page, pulling the reusable parts out rather than transcribing the pixels. Use this whenever the source of the design already exists somewhere else and the job is to reproduce it: "recreate this site", "build this in WordPress", "match this design", "here's a screenshot of what we want". Reproduction fails quietly in two specific ways this covers — values approximated instead of read, and repeated parts copied instead of factored — and both look like a finished page. Load the pattern-author skill alongside it; this one decides what to build and how faithfully, that one is how a pattern gets written.
---

# Reproducing an existing design

You are copying something. That changes two things about the job, and both of
them fail in ways that look like success.

**The values are somebody else's.** Whether you can *read* them or must
*infer* them decides how exact you may be, and whether you can check your work
at the end. Getting this wrong produces a page that is close at every measure
and exact at none.

**The structure is already there to be seen — which is the trap.** A source
hands you six cards, twelve rows, a hundred and forty-six menu items, all
spelled out. Copying them out feels like progress and produces content rather
than patterns. Factoring is not optional here; it is the work.

> **Load `pattern-author` too.** It owns how a pattern is written — the block
> vocabulary, the markup contract, validation, and the **factor** step this
> skill leans on at step 5. Nothing here repeats it. If you only have this
> skill, ask the site for the other: `pattern-builder/get-authoring-guide`.

## Workflow

### 1. Classify the source — do this first, and say the answer

Everything after this branches on one question: **can the values be read, or
must they be inferred?**

| Source | Values are | How you get them | What you may claim |
|---|---|---|---|
| A live web page, or theme files you can open | **readable** | parse the page's generated stylesheet; read block attributes | **exact**, and provable by diffing |
| A Figma file you have **access** to | **readable** | variables and styles via the API or an export | exact for tokens; layout still inferred |
| A screenshot, PDF, image, or Figma **export** | **inferred** | sample colours, measure at a stated viewport | *consistent*, never exact — and say so |

Two things to be careful about:

- **Most "Figma" sources are screenshots of Figma.** A PNG of a frame is the
  third row, not the second. Ask which you have. An image of a design system
  is not a design system.
- **A live page you cannot fetch is an image.** If the source is behind a
  login or a bot wall and all you can get is a screenshot, you are in row
  three, whatever the source technically is.

State your classification in your first reply. It is what the person asking
is agreeing to when they say "go ahead".

### 2. Extract the design system

Before any markup. `references/reading-a-source.md` has the techniques per
class; the short version:

**Readable.** Fetch the page and read its `global-styles-inline-css` block —
that is the whole design system as the browser resolves it: every preset, the
root and element styles, the layout widths. Then read the *block attributes*
in the markup for the places the design overrode those defaults, because a
constrained block may carry its own `contentSize` and a group its own
`blockGap`.

**Inferred.** Sample colours from the image. Measure type and spacing at a
stated viewport width, and write that width down — every measurement you take
is only true at it. Expect to be wrong about font stacks, line-heights and any
fluid scale; those are not recoverable from a picture.

### 3. Write the design system down before you install it

Produce a table: every colour, size, spacing step, font and layout width, with
its value **and where the value came from**.

| Token | Value | Evidence |
|---|---|---|
| `ink` | `#17120E` | `--wp--preset--color--ink`, read |
| `x-large` | `clamp(2.6rem, 1.6rem + 4.4vw, 5rem)` | read |
| `chili` | `#B03A26` | sampled from hero heading at 1400px |

For a **readable** source this is a transcription record, and any row without
evidence is a row you guessed and should go back for.

For an **inferred** source this artifact is mandatory and goes to the person
who asked **before you build anything.** You cannot measure your way to
correctness from an image, so the only honest substitute is to surface every
inference while it is still cheap to correct. Twenty patterns built on a
wrong type ladder is an expensive way to discover the ladder was wrong.

### 4. Install it, in this order

**layout → tokens → styles → block style variations.**

`set-layout` first: the widths are what every band's markup has to agree with,
and settling them late means every pattern written beforehand has the wrong
measure baked in. Then `add-design-tokens`, because a style references a
preset by slug and a slug that does not resolve renders as nothing. Then
`set-global-styles`, then `add-block-style-variation`.

**Prefer starting from a blank theme**, especially for an inferred source.
With nothing already defined there is nothing to borrow, so every value has to
be declared — which makes a guess visible instead of letting you quietly reach
for the destination's nearest equivalent. Picking "the closest existing font
size" is how a reproduction ends up close at every size and right at none.

### 5. Factor, then build

This is `pattern-author`'s **factor** step and its rules apply unchanged:
inventory what repeats, name it, take the slots from the diff between
occurrences, and place each part as an element, a section or a page.

The only thing this skill adds is emphasis. A source page is the strongest
invitation there is to transcribe, because everything is already spelled out
in front of you. **Count the repeats before writing a line.** The output of a
loop — a menu, a product grid, a card deck — is a template someone else
already wrote, and reproducing it as a hundred copies is the characteristic
failure of this job.

Then build bottom-up: elements, then the sections that reference them, then
the pages that reference those.

### 6. Place the pages

A page pattern is not a page. Create a WordPress page whose content is a
single reference to it:

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" -H 'Content-Type: application/json' \
  -d '{"title":"About","slug":"about","status":"publish",
       "content":"<!-- wp:pattern {\"slug\":\"my-theme/page-about\"} /-->"}' \
  "$WP_URL/?rest_route=/wp/v2/pages"
```

Watch for blocks that read the *destination's* state rather than carrying
their own: `core/site-title` prints this site's name, `core/navigation` with
no inner blocks fabricates a menu from this site's pages, `core/query` lists
this site's posts. In a reproduction those show the wrong thing while looking
like they work. Either supply the content explicitly or use ordinary blocks
and say what you substituted.

### 7. Verify — and the method depends on step 1

**Readable source.** Diff, do not look. Render both at the same width and
compare *computed* values — font size, weight, line height, letter spacing,
text decoration, colour, painted background — matched on the text, which is
identical by construction. Then compare the two generated stylesheets rule by
rule, which catches design-system errors in one pass.
`references/verifying.md` has the method and the properties that most often
differ.

**Inferred source.** There is no ground truth to diff against, so the check is
visual and the honesty is in the claim. Compare at the viewport you measured
at, list what you inferred and could not confirm, and **do not describe the
result as matching**. "Consistent with the design, at 1400px, with these
values inferred" is the true sentence.

Note that you do **not** need to create a page to look at a pattern:
`pattern-builder/render-pattern` returns a `page` URL that renders it inside
the resolved page template using a stand-in post primed into the object cache
for one request and never written.

### 8. Clean up, and say what you left

Two different things, and an agent that conflates them deletes its own work:

- **Scaffolding** — anything created only to look at something. Prefer the
  form that leaves nothing behind; if you did create something, remove it. On
  a failure, leave it and say what and where.
- **Deliverables** — the patterns, the pages, the design system. These stay.

Then **list what you added**, because a reproduction touches far more than the
patterns: presets, styles, block style variations, layout settings, uploaded
images, installed fonts. Nobody can review or undo what they were not told
about.

## What you cannot reproduce, and should say so

Surface these when you hit them rather than silently substituting:

- **Templates and template parts.** Nothing here writes them. A header and
  footer become patterns referenced by each page — same rendering, not the
  same block-theme structure.
- **Anything needing CSS that theme.json has no property for** — pseudo-
  elements (`::before` rules, dotted leaders), `list-style`, transforms and
  transitions. Rebuild from real blocks and say what changed.
- **Variable font axes.** The font collection serves fixed instances, one file
  per weight. A design leaning on optical sizing will set wider or narrower
  than the original with nothing to show why. Ask `list-fonts` for the family
  before promising a match.
- **Images you only have a picture of.** An image source gives you no files.
  Use placeholders and hand back a list of the images the person needs to
  supply — that list is part of the deliverable, not a caveat.

## References

- `references/reading-a-source.md` — extracting a design system from each kind of source, and what each kind cannot tell you
- `references/verifying.md` — comparing numerically rather than by eye, the properties that most often differ, and what you may honestly claim
- The `pattern-author` skill — how a pattern is actually written: vocabulary, markup, factoring, validation
