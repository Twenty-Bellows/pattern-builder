# The design/content split

The layering Pattern Builder is built around, in which **design patterns own
the markup** and **page patterns own the words**. A design pattern marks its editable parts as
Pattern Overrides slots and carries placeholder copy; a page pattern references
it and fills those slots.

The payoff is that a redesign touches one file per component and no copy, and
a copy change touches no markup. The cost is a plugin dependency and a set of
failure modes that are completely silent.

## Use it wherever the runtime is present — and it usually is

The `content` attribute on `core/pattern` **is not core**. It comes from
Pattern Builder or Synced Patterns for Themes, which declare it on the block
twice over — on the server (`register_block_type_args`) and in the editor (a
`blocks.registerBlockType` filter) — so on a site running either, `content`
survives every parse, every save and every render, and a page's slot values
persist exactly as any other attribute does. That is the mechanism this whole
document is about, and it is what makes a pattern *reusable* rather than
copied: one design pattern, any number of pages filling it.

Every site these abilities run on has Pattern Builder, so the runtime is
present by construction; patternbuilderwp.com requires it; a theme authored
with Pattern Builder in the loop ships beside it or beside Synced Patterns for
Themes. So the default is to **use it**: design patterns with slots, page
patterns filling them through `content`. Match an existing project's layering
where it has one; where it has none and Pattern Builder is present, this is
the layering to introduce, and say so.

The one case to weigh is a pattern destined for a site with **neither**
plugin: there WordPress drops the unknown attribute, the reference renders the
design pattern's *placeholder* copy, and nothing reports a problem — a page
ships "A question somebody asks before buying" where the client's question
should be. A pattern shipped that way should carry its copy inline, and the
project should know why.

Two tools already know the attribute, so nothing you run should lose it:
`scripts/validate-pattern.mjs` declares it before it registers core's blocks
(so `parse()` keeps it and `createBlock`/`serialize` write it), and
`create-pattern` reads it to check every key against the referenced pattern's
slots. If some *other* environment — plain `@wordpress/blocks` in a scratch
script, say — serializes a reference, check the output still carries
`content`: with core's block library alone it will not.

## The design pattern

Header carries `Synced: yes`. Each editable element gets a `metadata` object
with a `name` (the slot's key) and a `__default` binding:

```php
<?php
/**
 * Title: FAQ Entry
 * Slug: my-theme/faq-entry
 * Description: One question and its answer, above a hairline.
 * Categories: my-theme_elements
 * Synced: yes
 */
?>
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
	<!-- wp:heading {"level":3,"metadata":{"name":"question","bindings":{"__default":{"source":"core/pattern-overrides"}}}} -->
	<h3 class="wp-block-heading">A question somebody asks before buying</h3>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"metadata":{"name":"answer","bindings":{"__default":{"source":"core/pattern-overrides"}}}} -->
	<p>The answer, in one or two sentences.</p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
```

`__default` binds every overridable attribute of that block at once — for a
heading, paragraph or list item that is `content`; for an image, `id`, `url`,
`title` and `alt` (and `caption` from WordPress 6.9); for a button, `url`,
`text`, `linkTarget` and `rel`. The list is core's own,
`get_block_bindings_supported_attributes()`, and the runtime reads it rather
than carrying a copy. Slot names are the keys a page pattern will use, so name
them for what they hold (`question`, `headline`, `cta`), not for their block
type.

Placeholder copy should be realistic and the right length. It is what shows in
the inserter preview, and it is what ships if a page forgets to fill the slot.

## The page pattern

References the design pattern and supplies the words:

```html
<!-- wp:pattern {"slug":"my-theme/faq-entry","content":{
	"question":{"content":"Can I use these on client sites?"},
	"answer":{"content":"Yes. Every plan allows unlimited client use."}
}} /-->
```

The shape of each value depends on what is bound:

| Bound block | Value shape |
|---|---|
| heading, paragraph, list item | `{"content":"…"}` |
| image | `{"url":"…","alt":"…"}` — `id` too when the file is a media library attachment |
| button | `{"text":"…","url":"…"}` |

A `wp:pattern` block is **self-closing** — `/-->`, no closing comment.

## The silent failures

These are why the split needs care, and why a lint exists for it in projects
that use it. None of them are visible to block validation.

**A malformed `metadata` object stops being a slot.** WordPress reads
attribute JSON that doesn't parse as *no attributes*. A heading with no
attributes saves byte-identical markup to one with `metadata` — so the block
stays **valid**, renders identically, and is quietly no longer a slot. The
page that fills it then ships placeholder copy. One lost brace, no error
anywhere.

**A typo in a slot name is free.** `content` is an object and nothing in
core checks its keys against the referenced pattern's slots, so an
unrecognised key inside it is simply ignored. Misspell `question` as `quesiton` and that
slot silently keeps its placeholder.

**Malformed attributes on the *reference* lose the `slug` too.** When a
`wp:pattern` block's whole attribute object fails to parse, `slug` goes with
it, and the entire section renders as nothing at all — a page with a hole in it.

So after writing either half, re-read the JSON by eye, and check that every key
in a page pattern's `content` matches a `metadata.name` in the design pattern
it references. Copy the names across rather than retyping them.

Storing through `pattern-builder/create-pattern` catches all three: it
refuses attribute JSON that does not parse, a `content` key that names no
slot in the referenced pattern (and says which slots it has), a binding with
no `metadata.name`, and a binding on a block core cannot bind. Writing files
by hand, `scripts/check-slots.php` is the equivalent check, by rendering.

## Static copy in a design pattern

Sometimes a design pattern genuinely owns its words — a legal line, a label
that is the same everywhere. Projects with a lint for this usually offer an
escape hatch comment, e.g. `oneshot-allow-static-copy: <reason>`, so the
decision is recorded rather than silently violating the layering. Look for the
convention in the project before assuming; if there is one, use it and say why.

## Synced vs unsynced, briefly

`Synced: yes` in a theme pattern header makes it a synced pattern: instances
reference it, so editing the pattern updates every instance, and the only
thing an instance carries of its own is its slot content. An unsynced pattern
is a starting point — inserting it copies the markup and the copy diverges
from then on.

Design patterns in this layering are synced by definition; that is what makes
one file per component the whole story. A pattern meant as a jumping-off point
for someone to modify freely should not be.
