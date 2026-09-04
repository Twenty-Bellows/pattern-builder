# Verifying a reproduction

Comparing by eye is why a reproduction ships "close". Four errors survived a
whole rebuild that was checked visually at the end and looked right: a button
line-height of `inherit` where the source said `1.1`, core's default button
padding against `0.9rem/1.6rem`, a 24px block gap against 20px, and the layout
widths. None of them look wrong on their own. All four are obvious the moment
you compare numbers.

What you can do depends on the source class from `SKILL.md` step 1.

## Readable source: diff, do not look

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

`font-size` · `font-family` · `font-weight` · `line-height` ·
`letter-spacing` · `text-transform` · `text-decoration` · `color` ·
the nearest painted background

Two details that decide whether the comparison is any good:

- **Compare every occurrence of a string, not the first.** The same words
  appear on bands with different grounds; taking one hides the others. A link
  that is correct in the header and wrong in the footer looks correct.
- **Take the nearest *painted* ancestor for the background**, not the
  element's own, or everything reads `transparent` and the check means
  nothing.

Then look at where the two documents drift apart vertically. Report only where
the running offset *changes* — that is where a band grew or shrank, rather
than every element downstream of it repeating the same number.

### What "done" means

Page height within a percent or so, and no element differing on any compared
property. State both. "617 strings, zero differences, heights within 0.5%" is
a claim somebody can check; "it matches" is not.

## Inferred source: you cannot diff, so declare

There is no ground truth. The source is a picture; the rendered result is a
picture; comparing them is the weak check this document exists to replace, and
here it is the only one available. So the rigour moves from measuring to
disclosing.

1. **Compare at the viewport you measured at**, and say which that was. A
   screenshot measured at 1400px says nothing about 900px.
2. **List what you inferred and could not confirm** — the font, every
   line-height, whether any size is fluid, every spacing step, anything behind
   an interaction.
3. **Do not call it a match.** The honest sentence is: *"Consistent with the
   design at 1400px, with the font identified as X and these values inferred:
   …"* Anything stronger is a claim the source cannot support.

An inferred reproduction is finished when the person who has the original has
looked at the list, not when it looks right to you.

## Things worth checking whatever the source

- **Every band's width.** The most common single error, because the site's
  measure and a block's own override are two different numbers.
- **Buttons.** Padding and line-height are set by an element style most
  designs override and most reproductions forget.
- **Link decoration**, in both directions — an underline you added, and one
  you dropped on a dark band.
- **Spacing between stacked blocks.** One wrong block gap is a few pixels per
  block and a visibly different page by the bottom.
- **The blocks that read this site's state.** `core/site-title`,
  `core/navigation` with no inner blocks, `core/query` — they render *your*
  site's content while looking like they work.

## Do not create a page just to look at a pattern

`pattern-builder/render-pattern` returns a `page` URL that renders the pattern
inside the resolved page template, using a stand-in post primed into the
object cache for one request and never written. That is the whole check with
nothing to clean up afterwards.

Create a real page when the page *is* the deliverable — and then it stays.
