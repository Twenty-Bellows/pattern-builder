# Which blocks, and which are allowed

Two separate questions, and conflating them is how a pattern ends up either
clumsy or unusable:

1. **What is allowed here** — a policy question decided by where the pattern
   is going.
2. **What is right for the job** — a craft question about composition.

Answer the first before writing anything, because it decides the vocabulary
you get to compose from.

## 1. What is allowed

The destination sets the rule. Ask, or infer from the project, and say which
one you assumed.

**Core blocks only.** Required for anything that leaves this site — a pattern
submitted to the WordPress.org pattern directory, uploaded to a shared or
public library, or shipped inside a theme meant for other people. The reason
is unforgiving: markup for a block the receiving site does not have parses to
`core/missing`, which renders as a grey "block cannot be displayed" box. The
pattern doesn't degrade, it breaks, and it breaks on somebody else's site
where you will not see it.

Treat this as the default when the destination isn't clear, because it is the
only choice that is safe everywhere.

**Core plus what the theme provides.** A theme's own blocks and its registered
block *styles* (`is-style-*`) are fine in a pattern shipped inside that same
theme — they travel together. Style variations are usually the better tool
anyway: `{"className":"is-style-card"}` on a core block gets the theme's
design with no new block type at all.

**Core plus installed plugins.** Fine for a pattern that stays on this site —
your own project, a client build, a private cloud library. Not fine for
anything shared, unless the receiving site is known to have the same plugins.

**The runtime blocks are a special case.** `core/pattern` with a `content`
attribute needs Pattern Builder or Synced Patterns for Themes. `core/pattern`
itself is core, so nothing reports a problem — WordPress just drops the
unknown attribute and renders the design pattern's placeholder copy. See
`design-content-split.md`.

**patternbuilderwp.com is narrower than "core only".** A pattern uploaded to
the cloud passes a server-side allowlist, and core membership is not the
test: it takes the blocks that render from their own markup and attributes,
the ones that render the destination's own identity (site title, logo,
navigation, breadcrumbs, page list) and the ones that render its posts (the
Query Loop family and the `post-*` fields). It refuses anything that runs
code or names a row on the site that made it — `core/shortcode`,
`core/html`, `core/freeform`, `core/embed`, `core/rss`, the widget blocks,
`core/block` and `core/template-part` — and it carries images only, so
`core/video`, `core/audio` and `core/file` do not travel either. An upload
that includes one is refused by name rather than quietly stripped.

It also records the WordPress version a pattern needs, worked out from the
blocks it actually holds, and refuses to install one on a site too old for
it. That matters most for `core/math`: below 6.9 the destination's KSES does
not know MathML and removes it, so the pattern would land looking installed
and missing its equations.

**Check, don't assume.** Where a site is running, `list-block-types` says what
is actually registered there. Otherwise look at the theme's `patterns/` for
which blocks it already uses, and its `functions.php` or `theme.json` for
registered block styles.

## 2. What is right for the job

WordPress core has grown; some of what follows may be newer than you expect.
The list below is core as registered on a current install. Check what a
specific site has rather than trusting this to be current.

### Where each question is answered

Four different sources, and using the wrong one is how patterns break:

| Question | Ask |
|---|---|
| Which blocks exist, what each is for, what may nest in what | The [core blocks reference](https://developer.wordpress.org/block-editor/reference-guides/core-blocks/) |
| What attributes a block takes — types, defaults, selectors | `pattern-builder/list-block-types` on the target site |
| What the saved markup must look like | The site's own block library, via `createBlock`/`serialize` |
| Whether the markup you have is right | `scripts/validate-pattern.mjs` |

The handbook is the best answer to the first and no answer at all to the
third. It is generated from each block's `block.json`, so it carries the name,
category, supports, attribute names, and — usefully — the nesting rules, as
`Parent: core/accordion-item` or `Allowed Blocks: core/accordion-item`. It
carries **no markup**: not one `<!-- wp:` example, not one class name. Saved
markup is `save()`'s output, which is JavaScript and is not declared anywhere
a document generator can read.

It also documents whichever WordPress is current, while a pattern is destined
for whatever version the target site runs. Where the two disagree the site
wins, every time, which is why the last three rows of that table all point at
the site rather than at a page.

### Layout and structure

| Need | Block |
|---|---|
| A band, a wrapper, anything with a background or shared padding | `core/group` |
| Side-by-side content | `core/columns` + `core/column` |
| A row of things that flow and wrap | `core/group` with `{"layout":{"type":"flex"}}` |
| A grid | `core/group` with `{"layout":{"type":"grid"}}` |
| Deliberate vertical gap | `core/spacer` — but prefer `blockGap` or padding |
| A visible rule | `core/separator` |

Reach for `core/group` first. Most "sections" are a full-width group with a
constrained layout inside, and most spacing problems are solved by the
group's `blockGap` rather than by spacers.

### Text

`core/heading` (with `level`), `core/paragraph`, `core/list` + `core/list-item`,
`core/quote`, `core/pullquote`, `core/details` (a native disclosure), `core/table`,
`core/code`, `core/preformatted`, `core/verse`.

A list is `core/list` wrapping `core/list-item` blocks — not bare `<li>`. This
matters for Pattern Overrides: a list item is only separately fillable if it
is its own block.

### Media

`core/image`, `core/gallery`, `core/cover` (image or color with content on
top), `core/media-text` (image beside content), `core/video`, `core/audio`,
`core/file`, `core/embed`, `core/icon`.

Of these, a cloud upload takes only the image-based ones and `core/icon` —
see "What is allowed" above.

**Use `core/media-text` rather than building it from columns + image.** It
handles the stacking behaviour, the media/content ratio and the vertical
alignment that a hand-built pair of columns gets wrong on mobile. Same for
`core/cover` over a group with a background image — cover gets the overlay,
the focal point and the min-height for free.

### Interactive

`core/accordion` (+ `accordion-item`, `accordion-heading`, `accordion-panel`),
`core/tabs` (+ `tab-list`, `tab-panel`, `tab-panels`), `core/buttons` +
`core/button`, `core/search`, `core/social-links` + `core/social-link`.

Accordions and tabs are relatively recent core additions. If the target site
predates them, a `core/details` list is the portable fallback for FAQ-style
content.

### Dynamic and site content

`core/query` + `core/post-template` and the `post-*` family, `core/site-title`,
`core/site-tagline`, `core/site-logo`, `core/navigation`, `core/page-list`,
`core/breadcrumbs`, `core/search`, `core/post-content`.

These render the destination's content and identity rather than yours, which
is the point of putting them in a pattern: a header shows that site's name,
a Query Loop that site's posts. A navigation carrying no links of its own
falls back to the site's menu or its pages, which is usually what a header
starter wants.

Two things about them are mechanical rather than matters of taste:

- **A `core/query` with no `core/post-template` inside it renders nothing.**
  The query block is the wrapper; the template block is what repeats.
- **`core/template-part` and `core/block` do not travel.** Both name
  something stored on the site that made them — a template part slug in that
  theme, a `wp_block` post id — so neither resolves anywhere else.

On patternbuilderwp.com these preview against an invented site rather than
the service's own content, so a header or a page pattern previews as
somebody's website instead of as the service.

## Composition, briefly

**Fewer wrappers.** Every group you add is a div someone has to reason about
later. If a group has no background, no padding and no layout of its own, it
is probably not earning its place.

**One full-width band, constrained inside.** The common section shape is
`{"align":"full"}` on the outer group with `{"layout":{"type":"constrained"}}`,
so the background reaches the edges while the content respects `contentSize`.
Nesting several full-width groups is usually a mistake.

**Let layout do spacing.** `blockGap` on the container beats a spacer between
every child, and it stays right when a slot is filled with more or less text.

**Prefer a style variation to inline styles.** `is-style-card` carries the
theme's decision; a hand-rolled border and shadow carries yours, and drifts
from everything else the moment the theme changes.

**Match the heading level to the document, not the size.** A section heading
inside a page is usually `h2`; use `fontSize` for how big it looks. An `h1`
belongs to the page, so a reusable section pattern should rarely contain one.

**Keep the shape fixed where slots are involved.** Pattern Overrides fills
slots but cannot add or remove blocks, so a design pattern's structure is
fixed at authoring time. A list whose length varies per page has to be
composed from repeated references instead — see `design-content-split.md`.
