# Kinds of pattern

"Pattern" covers six different jobs, and the job decides almost everything
mechanical about the file: where it can be stored, which headers place it,
whether it appears in the inserter, and whether it takes part in the
design/content split.

A kind is a **starting point, not a stored property**. Nothing in the file
records "this is a Page Pattern"; the kind just fixes the metadata its job
implies. All of it stays editable afterwards.

## The six

### Design Pattern

*Insert it anywhere, then edit freely.*

The building blocks of a site — blocks put together in a way worth reusing.
Inserting one copies the markup, and the copy diverges from then on; changing
the pattern only affects new insertions.

- Unsynced. `source` is theme or database, your choice.
- No placement headers. It just shows up in the inserter.

### Synced Design Pattern

*Content is editable, design is locked.*

The same building-block job, but instances reference the pattern rather than
copying it. Editing the pattern changes it everywhere it is used; the only
thing an instance carries of its own is the content of its slots.

- `Synced: yes`. Theme or database.
- This is the **design half of the design/content split**. If you are marking
  up Pattern Overrides slots, this is the kind you are writing — see
  `design-content-split.md`.

### Page Pattern

*Offered when new content is created.*

What WordPress shows in the "choose a pattern" modal when someone starts a new
page: an example page, whole, for somebody to start from and edit. What it
holds is whatever a page of that kind holds — references to the patterns that
make up its sections, and ordinary blocks where those are what the page is made
of.

- `Block Types: core/post-content` — that header is what makes WordPress offer
  it for new content.
- `Post Types: page` (or whichever types it suits).
- **Always a theme pattern.** The headers that place it are pattern-file
  headers; a `wp_block` in the database has nowhere to put them.
- This is the **content half of the split**: it fills the slots that synced
  design patterns expose.

**It is a page's worth of markup, not a manifest.** The test for what gets
referenced is the one in `composition.md`: a pattern earns its existence by
being reusable, so a band that repeats or carries a domain name gets
referenced, and a paragraph that appears once on one page is written where it
appears. Wrapping that paragraph in a pattern does not make it reusable; it
makes it a pattern nobody would install, named for the page that is already the
only thing using it. Projects that layer more strictly than this say so in
their own conventions — follow those where they exist, and do not infer them
here.

### Block Starter Pattern

*Offered when a block is inserted.*

Belongs to a block type. WordPress offers it when that block is inserted and
still empty — an untouched Query Loop or Cover asks which design to start
from — and from the block's toolbar, to swap one design for another.

- `Block Types: core/query` (or whichever block it starts).
- Always a theme pattern, same reason as above.

### Template Pattern

*Offered when a template is created.*

A whole template — an archive, a home page, a 404 — offered in the Site Editor
when someone creates a template of that type. Header and footer included,
because it is the entire page.

- `Template Types: archive, index, …`
- `Inserter: no` — a whole template is noise in the block inserter, and the
  themes that ship these keep it out.
- A wide `Viewport Width`, since it previews as a full page.
- Always a theme pattern.

### Template Part Pattern

*Offered for a header or a footer.*

Belongs to a template part. The Site Editor offers it when a header or footer
is created, and from the part itself to swap designs.

- `Block Types: core/template-part/header` or `core/template-part/footer`,
  plus the matching `Categories: header` / `footer`.
- **WordPress supports those two areas only.** There is no "sidebar" template
  part pattern.
- A wide `Viewport Width`. Always a theme pattern.

## Choosing

Start from what the pattern is *for*, not from what it looks like:

| The user wants… | Kind |
|---|---|
| a section to drop in and edit | Design Pattern |
| a component whose design must stay consistent everywhere | Synced Design Pattern |
| a starting layout for new pages | Page Pattern |
| a design for an empty Query Loop, Cover, etc. | Block Starter Pattern |
| a whole archive/404/home layout | Template Pattern |
| a header or footer design | Template Part Pattern |

Two consequences worth holding onto:

**The four starter kinds are always theme patterns.** Their placement lives in
pattern-file headers. If a task says "make a reusable block in the database"
*and* "offer it when a new page is created," those requirements conflict — say
so rather than silently picking one.

**Synced Design Pattern plus Page Pattern is the design/content split.** If a
project uses that layering, those two kinds are its two halves. Writing one
without the other leaves the job half done: a synced pattern with slots and
nothing filling them ships its placeholder copy.

## The headers these become

Written by `Pattern_File_Store`, and readable in any theme's `patterns/`:

```php
<?php
/**
 * Title: Pricing Plans
 * Slug: my-theme/pricing-plans
 * Description: Pricing section for a landing page, with two plans side by side, each with a price and a button.
 * Categories: my-theme_sections, call-to-action
 * Keywords: pricing, card, grid
 * Block Types: core/post-content
 * Post Types: page
 * Template Types: index, archive
 * Viewport Width: 1400
 * Inserter: no
 * Synced: yes
 */
```

`Title` and `Slug` are required and the slug must be namespaced. Everything
else is present only when the kind calls for it — a Design Pattern has none of
the placement headers at all.

A **database pattern has no headers**, because a `wp_block` post has nowhere
to put them. Its title and description are post fields, and unsynced is
expressed as post meta (`wp_pattern_sync_status: unsynced`) rather than the
absence of a `Synced` header. That is the mechanical reason the four starter
kinds cannot be database patterns.

A **Template Part Pattern is a Block Starter Pattern underneath**: the area
chosen decides both the block type that offers it
(`core/template-part/header`) and the category it files under (`header`).

## Describing a pattern so it can be found

An agent choosing from `list-patterns`, a person searching the inserter, a
search on patternbuilderwp.com: each of them has the title and the description
to go on, and nothing else until they open the pattern. A pattern nobody can
find gets written again, so the description is part of the pattern's job, not
a label stuck on afterwards.

A description is **one sentence**: what the pattern is for, then roughly what
is in it. It is read in a list beside twenty others, so its job is to say which
pattern this is, not how it is built.

- "Site header with the logo and main menu, in light text for placing over a
  photograph."
- "Home page hero with the site header over a full-width photograph, a
  headline, a short sentence and two buttons."
- "The Farmhouse Flower Farm home page, assembled from the hero, shop, story,
  course, journal, press and newsletter sections."

Lead with the kind of thing and where it goes — "Pricing section for a landing
page", not "a pricing block" — then name its parts in a word or two each. Aim
for 12 to 30 words.

Leave out what anyone who opens the pattern can see: the names of its slots,
slugs, a list of what it depends on (or that it depends on nothing), how it
stacks on a narrow screen, and what each section of a page holds. Mention
behaviour only when it is what the pattern is for, such as an accordion or a
list of posts.

Write what stays true on another theme. Colours, fonts and radii come from the
design system, so "a dark band with rounded cards" describes the site it was
built on, not the pattern. Leave out the words that are true of every pattern
("modern", "clean", "responsive", "beautiful"): they help nobody choose this
one. And write it from the markup you stored, not from what you set out to
build.

**Keywords** are the filter. Lead with the kind of section, from a short list
the whole site shares: hero, features, services, pricing, faq, testimonials,
team, logos, stats, steps, gallery, contact, form, cta, newsletter, header,
footer, content. Then the mechanics, only for what the markup does (accordion,
query, navigation, video), then the composition (card, grid, split,
full-width). `list-patterns` answers with `keywordsInUse`: reuse those words,
so the same shape does not arrive as `card` from one pattern and `cards` from
the next.

`create-pattern` and `update-pattern` return `warnings` when a description is
missing, too short to tell two patterns apart, longer than one sentence of
about 30 words, or made of words that describe everything, and when an update
moves the markup under a description it did not touch. They never refuse the
write.

