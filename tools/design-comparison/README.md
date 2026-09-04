# Design comparison tooling

Two scripts for the question "does what I built match the design I was
copying?", written after a whole-site rebuild where the answer was checked by
eye and four separate transcription errors survived to the end.

**These are deliberately not part of the `pattern-author` skill.** The skill
reaches agents through the abilities, which are JSON in and JSON out; shipping
scripts through it is a different decision from writing guidance, and this is
parked here until that decision is made. Nothing in `guides/` references these
files.

## Why they exist

Reproducing a design fails quietly. A wrong font size, a 4px block gap, a
button line-height of `inherit` where the original said `1.1` — none of them
error, none of them look wrong on their own, and all of them are obvious the
moment you compare numbers instead of pictures.

In the rebuild these two scripts would have caught, in one pass each:

| Error | Caught by |
|---|---|
| `elements.button` line-height `inherit` vs `1.1` | design-system diff |
| Button padding: core's default vs `0.9rem/1.6rem` | design-system diff |
| Block gap 24px vs 20px | design-system diff |
| Layout 800/1200 vs 46rem/80rem | design-system diff |
| Nav links underlined where the source had none | render diff |
| A heading wrapping to 4 lines instead of 3 | render diff |

## `compare-design-system.mjs`

No browser, no dependencies. Fetches two pages, pulls the
`global-styles-inline-css` block out of each, and diffs it rule by rule —
which is the design system exactly as the browser sees it, so a difference
here is a difference in what was installed rather than in how a pattern used
it.

```bash
node tools/design-comparison/compare-design-system.mjs \
  https://source.example.com/ https://mine.example.com/
```

Run this **first**. It is cheap, it needs nothing installed, and most
reproduction errors are design-system errors.

## `compare-render.mjs`

Loads both pages in a browser and compares the *computed* style of every
element carrying text, matched on the text itself — which works because when
you are reproducing a design the copy is identical by construction. Reports
per-property differences, then where the two documents drift apart vertically.

```bash
npm i --no-save playwright   # or reuse an existing install
node tools/design-comparison/compare-render.mjs \
  https://source.example.com/ https://mine.example.com/
```

Slower and needs Chromium, so it is the second pass: run it once the design
system agrees, when what is left is how the markup used it.

## What it looks like working

Run against the rebuild that prompted these, `compare-design-system.mjs`
reduces two 100KB stylesheets to one finding:

```
:root
   --wp--style--global--content-size: 46rem   ->   800px
   --wp--style--global--wide-size: 80rem   ->   1200px
   (38 properties the source has and mine does not: --wp--preset--color--black, …)
```

The layout widths are genuinely still wrong on that site; the 38 absent
properties are core's default palette, which the blank theme strips on purpose.
Separating "this value differs" from "this side does not have this property at
all" is what keeps the first visible.

## Known rough edges

- `compare-render.mjs` matches on text, so it is only useful where both pages
  carry the same copy. That is the reproduction case and not much else.
- Neither handles authentication.
- Playwright's `fullPage` screenshot drops images on very tall pages in some
  headless builds; neither script screenshots, but be aware if you extend them.
