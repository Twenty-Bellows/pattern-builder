# Verifying a reproduction

Comparing by eye is why a reproduction ships "close". Four errors survived a
whole rebuild that was checked visually at the end and looked right: a button
line-height of `inherit` where the source said `1.1`, core's default button
padding against `0.9rem/1.6rem`, a 24px block gap against 20px, and the layout
widths. None of them look wrong on their own. All four are obvious the moment
you compare numbers.

A fifth, from a later rebuild, is the reason the compare list below has a
second row: a hero centred that the source left-aligned. It survived because
the check compared type and colour, and every size, weight and colour in that
band was right.

**This document is for a readable source and a whole rebuild** — the one case
where a real ground truth exists and the comparison is worth its time. Against
an inferred source there is nothing to diff, and `SKILL.md` step 7 says what to
do instead. For a single pattern, generation and the validator already give you
what a browser would.

## Diff, do not look

### First, the design systems

The cheapest and highest-yield check. Both sites write their whole resolved
design system into one inline stylesheet, so comparing those two blocks
rule by rule compares the design systems themselves — a difference there is a
difference in what you *installed*, before any pattern is involved.

All four errors above are in the first screen of that comparison.

Two things make its output readable rather than a wall:

- **Compare values as CSS means them.** `#17120E` and `#17120e` are the same
  colour; reporting the difference buries the ones that matter.
- **Keep "this value differs" apart from "one side lacks this property".**
  The second is usually two themes differing on purpose — a blank theme strips
  core's default palette, so forty absent properties are expected — and mixing
  them hides the handful that are mistakes.

### Then, the rendering

Load both at the same width and compare *computed* style per element, matched
on the text, which is identical by construction when you are reproducing.
Compare at least:

*Type and colour* — `font-size` · `font-family` · `font-weight` ·
`line-height` · `letter-spacing` · `text-transform` · `text-decoration` ·
`color` · the nearest painted background

*The box* — `text-align` · `flex-direction` · `justify-content` ·
`align-items` · the element's content-box width · `margin-inline` · `padding`

The second row is not optional and is the one most often left out, because a
type-and-colour diff produces a clean report on a page whose every band is
centred where the source was left-aligned. Nothing in the first row can see
that: the sizes, the weights and the colours are all correct.

Three details that decide whether the comparison is any good:

- **Compare every occurrence of a string, not the first.** The same words
  appear on bands with different grounds; taking one hides the others. A link
  that is correct in the header and wrong in the footer looks correct.
- **Take the nearest *painted* ancestor for the background**, not the
  element's own, or everything reads `transparent` and the check means
  nothing.
- **Compare band boxes as well as text elements.** Each band's painted
  background against the viewport is where `alignfull` versus a constrained
  group shows up, and a diff matched on text runs straight past it — the words
  inside a full-width band and inside a constrained one can sit at identical
  coordinates.

Then look at where the two documents drift apart vertically. Report only where
the running offset *changes* — that is where a band grew or shrank, rather
than every element downstream of it repeating the same number.

### What "done" means

Page height within a percent or so, and no element differing on any compared
property. State both. "617 strings, zero differences, heights within 0.5%" is
a claim somebody can check; "it matches" is not.

## Things worth checking

- **Every band's width.** The most common single error, because the site's
  measure and a block's own override are two different numbers.
- **Alignment, on the elements that are not the widest.** Check the short
  paragraph and the button row against the headline, not the headline against
  the margin — a near-full-width line looks the same centred or left-aligned,
  so it is the only element in the band that cannot answer the question.
- **Buttons.** Padding and line-height are set by an element style most
  designs override and most reproductions forget.
- **Link decoration**, in both directions — an underline you added, and one
  you dropped on a dark band.
- **Spacing between stacked blocks.** One wrong block gap is a few pixels per
  block and a visibly different page by the bottom.
- **The blocks that read this site's state.** `core/site-title`,
  `core/navigation` with no inner blocks, `core/query` — they render *your*
  site's content while looking like they work.
