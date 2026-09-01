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
page. Usually an assembly: several design patterns referenced together, with
this pattern supplying the words.

- `Block Types: core/post-content` — that header is what makes WordPress offer
  it for new content.
- `Post Types: page` (or whichever types it suits).
- **Always a theme pattern.** The headers that place it are pattern-file
  headers; a `wp_block` in the database has nowhere to put them.
- This is the **content half of the split**: it fills the slots that synced
  design patterns expose.

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
 * Description: Two plans side by side.
 * Categories: my-theme_sections, call-to-action
 * Keywords: pricing, plans
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
