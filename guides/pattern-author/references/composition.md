# Composition: elements, sections and pages

A pattern's worth is its reusability. That is the whole difference between a
pattern and a page of content, and it is decided before any markup is written —
by how the design is cut up.

This document is the long form of **step 4** in `SKILL.md`. Read it when you
are building anything bigger than one section, and especially when you are
reproducing a design that already exists.

## The failure it exists to prevent

A real one, worth reading before the rules.

A restaurant site was reproduced through the abilities: four pages, a design
system, forty-one images, twenty patterns. The menu page came out as **one
pattern of 343KB** containing the same nine-block subtree written out **146
times** — a thumbnail, a name, a price, a description, once per dish.

Everything about it was correct. It validated, it rendered, it matched the
source to within half a percent on every measurement. And it was useless as a
pattern:

- Nobody installs it. It is one restaurant's dishes.
- Changing how a menu item looks is 146 edits.
- The one genuinely reusable thing in it — *a menu item* — was never expressed,
  so nothing else can reuse it.

What it should have been is three patterns: a `menu-item` with four slots, a
`menu-section` that references items, and a `menu` that references sections.
Two hundred lines instead of 343KB, and every one of the three worth installing
on its own.

And on a site that sells those dishes, even the three patterns are the second
answer. The dishes are records, products with a price and a page each, and the
menu section is a query over them with the menu item's design applied to every
row. The restaurant can then change a price without opening a pattern. *When
the thing that repeats is a record*, below, says how to tell.

The mistake was not sloppiness. The source page was rendering a WooCommerce
loop, so there *was* no repeated markup to notice — there was a template, and
what came down the wire was 146 expansions of it. Copying the expansions felt
like faithful reproduction. **The output of a loop is the single strongest
signal that a pattern is wanted**, and it is easy to read as content.

## The three levels

| Level | What it is | What it contains | Repeats? |
|---|---|---|---|
| **Element** | the smallest named thing | markup, with slots | many times |
| **Section** | a band of the page, Wide or Regular | a heading and references to elements | sometimes across pages |
| **Page** | a whole page | references to sections, and this page's own markup | once |

A pattern at any level may reference patterns below it, **and the nesting is
not limited to one hop**. A page references sections. A section references
elements. A section may reference another section. The characteristic failure
is stopping after one level: bands become patterns, and everything inside them
is written out longhand.

An element repeats and that is why it is a pattern. A **section** is a pattern
for a different reason — it is a named part of a page, so a page can be
assembled from names rather than from markup, and a band shared by two pages is
shared rather than copied. A section that appears exactly once is still a
section pattern.

A **page** is where the rule stops. It is the one level that does not repeat,
so nothing about it has to be reusable, and it may hold plain blocks alongside
its references wherever naming a thing would buy nothing — see **When not to
factor** below. Every level *above* the page is composition; the page itself is
allowed to be a page.

## Filling in the inventory

Three tests. Only the second needs judgement.

### 1. What repeats — mechanical

Reduce the markup to a **shape**: the tree of block names and attributes with
all text, URLs and IDs stripped. Any shape occurring more than once is a
candidate.

```
group.is-style-card > columns > [ image, group > [ group.row > [ p, p ], p ] ]
```

If you are about to write that subtree a second time, you have found one. When
the design comes from an existing page, the repetition is already visible —
count it before writing rather than after.

### 2. Does it have a name — the filter

A candidate becomes a pattern when you can name it with a **domain noun
phrase**: *menu item*, *dish card*, *testimonial*, *hours row*, *feature*. If
the only name available is structural — *the group wrapper*, *the two-column
row*, *the inner div* — it is markup, not a pattern.

This is what stops the first test shattering a page into confetti. Two
`core/group`s that happen to share a shape are not a pattern; two things a
person would point at and call by the same word are.

### 3. What are the slots — mechanical again

Given N occurrences of one shape, **the slots are exactly the leaves whose
content differs between them.**

| Leaf | Occurrence 1 | Occurrence 2 | Slot? |
|---|---|---|---|
| name | "Chicken Momo" | "Jhol Momo" | yes |
| price | "$14.99" | "$15.99" | yes |
| description | "Stuffed ground chicken" | "…in a spiced broth" | yes |
| image | `momo-steamed.webp` | `momo-jhol.webp` | yes |
| wrapper padding | `var:preset\|spacing\|xs` | `var:preset\|spacing\|xs` | no |

Deciding what should be a slot is normally the hard part. It is not a decision:
it is a diff.

## The constraint that decides where a boundary can fall

**Pattern Overrides binds a short list of blocks:** `core/paragraph`,
`core/heading`, `core/image` and `core/button`, and from WordPress 6.9
`core/list-item` as well — the list is the server's
(`get_block_bindings_supported_attributes()`), the runtime that fills slots
reads it, and the editor offers *Enable overrides* on exactly those blocks.

So a slot must land on one of those. You cannot slot "some blocks", a whole
list, a group, or a table. This is not a limitation to work around — it is the
thing that tells you where the boundary goes. The menu item decomposes cleanly
because its varying parts are three paragraphs and an image. A card whose
varying part is *a different arrangement of blocks* is not one pattern with a
slot; it is two patterns.

When the varying content is a list of things whose *length* varies, the answer
is another level: the list becomes a section that references N elements, not
a slot holding a list. A list whose length is fixed and whose items vary can
slot each `core/list-item` on a site new enough to bind it.

`references/design-content-split.md` has the slot mechanics — the `metadata`
block, the binding, and the silent failures.

## Writing it

An element carries **placeholder copy**, not the first occurrence's copy. It is
what the inserter previews and what shows if a slot is never filled.

```html
<!-- wp:group {"className":"is-style-menu-item"} -->
<div class="wp-block-group is-style-menu-item">
	<!-- wp:paragraph {"metadata":{"name":"name","bindings":{"__default":{"source":"core/pattern-overrides"}}}} -->
	<p>Dish name</p>
	<!-- /wp:paragraph -->
	<!-- wp:paragraph {"metadata":{"name":"price","bindings":{"__default":{"source":"core/pattern-overrides"}}}} -->
	<p>$0.00</p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
```

A section references it and fills the slots:

```html
<!-- wp:pattern {"slug":"my-theme/menu-item","content":{
	"name":{"content":"Chicken Momo steamed (10pcs)"},
	"price":{"content":"$14.99"}
}} /-->
```

Three things about that reference:

- It is **self-closing** (`/-->`).
- The referenced pattern must be registered on the site, and an **unresolved
  reference renders as nothing at all** — no error, no placeholder, an empty
  band. If a page comes out blank, suspect the reference before the markup.
- `content` is resolved by Pattern Builder or Synced Patterns for Themes, not
  by core. Without one of them WordPress drops the attribute silently and every
  reference renders the element's placeholder copy.

The element should be **synced** (`Synced: yes`). The slots fill either way —
the runtime renders any `core/pattern` that carries `content` — but only a
synced reference *stays* a reference: the editor expands a reference to an
unsynced pattern into a copy the moment it opens the page, and from then on a
change to the element no longer reaches it. Synced is what makes "one file per
component" true.

## When the thing that repeats is a record

The inventory finds what repeats and makes it a pattern. Before it turns a
collection into references, ask what the repeated thing is, because some
collections are not section content at all.

It is a **record** when each item has a page of its own, carries a date, a
price, an author or a category, the design shows a filter, paging or "see
all", the same set appears in more than one place, or the list will obviously
grow. Menu items, products, team members, projects, events, articles. Then the
words do not belong in a pattern, where only someone editing patterns can
change them. They belong in posts, or a post type of their own, and the section
is a Query Loop over them, or the block a commerce or events plugin ships for
its own records. What gets factored is the card, the post template inside the
query, built from blocks that read the post: the post blocks, and bindings to
the post's fields. When more than one list shows it, the card is a synced
pattern each post template places; `templates.md` says what one can't hold
inside a loop. A slot fills from the reference, not from the post, so a card
and an element pattern used as section content can't be the same pattern. When
they should look alike, give them a block style variation both apply.

It is **section content** when it is a small, fixed set written as part of the
section: features, steps, pricing tiers, stats, a logo strip. Then it is
references to an element pattern, exactly as above.

Creating a post type is the person's call, not yours: it shapes the whole site.
Propose it and say why, keep building with references in the meantime so the
page works today, and put the proposal in the handoff.

## When not to factor

Indirection is cheap but it is not free, and a design cut too fine is worse
than one cut too coarse.

- **Two occurrences with no name** — leave inline. A repeated group wrapper is
  not a pattern.
- **A single styled block.** A pattern whose whole content is one paragraph,
  one heading or one image with a class on it is indirection with nothing
  inside it. The reusable thing there is the *style*, and a block style
  variation already carries it: `styles/callout.json` registers
  `is-style-callout`, the editor offers it on every paragraph, and any markup
  that wants the treatment writes `{"className":"is-style-callout"}`. Wrapping
  that in a pattern adds a name to remember, a file to open and a reference to
  resolve, and it reaches one place instead of every paragraph on the site.
  Write the block where it goes. The same holds for a block whose difference is
  an attribute — a font size, an alignment, a background: those travel in the
  markup, and a pattern around them travels nowhere the markup did not.

  What flips it back is content, not styling: once the thing has *slots* that
  different pages fill differently, it is an element and it wants a pattern.
- **A part that varies structurally** rather than in its leaves — two
  "cards" where one has an image above and the other beside it are two designs,
  not one pattern with a slot.
- **A whole page's worth of genuinely one-off copy** — an about page's three
  paragraphs are content. They belong in a section pattern's markup, or written
  directly into the page pattern, whichever reads better. A pattern per
  paragraph is the confetti this section exists to prevent.
- **When the pattern is bound for a site with neither runtime.** References
  and `content` need Pattern Builder or Synced Patterns for Themes at the far
  end; every site these abilities run on has one, and the service requires
  one, so this is rare — but a pattern handed to a plain site should carry
  its copy inline. Say what you would have factored otherwise.

## The two questions to finish on

Before you write a pattern of any size:

1. **Would somebody else install this?** If the answer is no because it
   contains one business's content, the reusable thing inside it has not been
   expressed yet.
2. **If this design changes, how many edits is it?** If the answer is more than
   one, the thing that changes is the pattern you have not written.
