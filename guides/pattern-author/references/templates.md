# Templates and template parts

A pattern is a part of a page. A template is the frame a whole kind of page
renders in: which header, which footer, and where the content goes, for the
front page, for one post, for a search result. A template part is a piece of
that frame several templates share, usually the header and the footer.

Both are files in the theme, `templates/{slug}.html` and `parts/{slug}.html`,
and Pattern Builder writes them there with `create-template`,
`update-template` and `delete-template`. Each takes a `type`, `"template"` or
`"template-part"`, and a `slug`, the filename without `.html`.
`list-templates` says what the site has and where each one comes from.

## A template places things; it doesn't carry them

**A template file is plain HTML.** WordPress reads it as text and never runs
it, so it has none of what a pattern file has: no localized text, no path to
the theme's own images, no way to travel to patternbuilderwp.com. The site
refuses PHP in one outright.

So keep templates thin. A template is a handful of references and the block
that shows the content, and everything with a design lives in a pattern it
places:

```html
<!-- wp:template-part {"slug":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group">
	<!-- wp:pattern {"slug":"my-theme/post-header"} /-->
	<!-- wp:post-content {"layout":{"type":"constrained"}} /-->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
```

Write the content area as a `main` group, as above. It's the landmark a screen
reader jumps to, and the header and footer parts take `header` and `footer` as
their `tagName` for the same reason.

The same goes for a part. A header's design (the logo, the menu, the button)
is a pattern, and `parts/header.html` places it:

```html
<!-- wp:pattern {"slug":"my-theme/header"} /-->
```

That way the logo can be the theme's own asset, the words can be localized,
and the design can be uploaded, none of which a part file can do.

## Decide what goes where first

| In the design | Goes in | Because |
|---|---|---|
| The header and footer every page shares | a template part each | Every template places the same part, so one change reaches every page. |
| A band: hero, features, testimonials, FAQ | a pattern | It's the reusable part, and only a pattern can reach the theme's assets. |
| How one kind of page is arranged | a template | Parts, patterns, and the block that shows the content. |
| One page's own words | the page, through a page pattern | Content belongs to the page, not the theme. See `pattern-kinds.md`. |

Then give each kind of page its template. A slug from WordPress's template
hierarchy is used wherever the hierarchy reaches it, with nothing to assign:

| The page | Template slug |
|---|---|
| The front page | `front-page` |
| A static page | `page`, or `page-{slug}` when one page's frame differs |
| The posts page | `home` |
| One post | `single`, or `single-{post type}` for a custom post type |
| A list of posts | `archive`, or `category`, `tag`, `taxonomy-{taxonomy}`, `archive-{post type}` |
| Search results, a missing page | `search`, `404` |
| Anything without its own | `index`, the fallback every request reaches |

Any other slug is a custom template: an editor picks it from the Template
panel. Give it a `title` they'll recognize. Once it has one, WordPress offers it
for pages alone, so name `postTypes` when posts or another post type should get
it too, such as `["page", "post"]`.

Write `index` first if the theme has nothing yet. A theme with no
`templates/index.html` isn't a block theme at all, which is also why
`delete-template` won't remove the last one.

## Template parts

Store a part before any template that places it, the same bottom-up order
patterns follow. A reference to a part that doesn't exist renders as nothing,
so `create-template` refuses one, and so is a part that ends up placing itself
through another.

**Give a part its `area`.** `header` and `footer` are the usual ones; the
areas this site supports come back from `list-templates` with
`type: "template-part"`. The area is how the Site Editor groups the part and
which Template Part Patterns it offers for it.

**Reference a part by its slug alone.** A `theme` attribute naming this theme
is dropped on the way to disk, as WordPress's own export drops it, and one
naming another theme is refused, since it would render only while that theme is
active.

**A header that differs on one page is usually one part.** A transparent header
over the front page's hero is the same header with a different look. Give the
front page template's reference a `className` for a block style variation, and
reach for a second part (`header-overlay`) only when the markup itself differs.

## Navigation and other things that live in the database

**Leave `ref` off `core/navigation`.** A `ref` is a menu's database ID, which
means this site's menu here and nothing anywhere else. Without it the block
shows the menu the site has, which is the one an editor manages. If the links
really are fixed, write them into the block as `core/navigation-link` blocks.

The same goes for anything else that names a row: a `core/block` by its `ref`,
an image by its media library ID. The answer to a write carries a
`template_database_id` warning for each, and `template_media` for images placed
in a template, which belong in a pattern.

## When the Site Editor got there first

Someone can edit a template in the Site Editor, and WordPress saves that as a
customization in the database, which renders in place of the theme's file with
nothing on disk to say so. `list-templates` and `get-template` report it as
`source: "custom"`.

`get-template` returns the customization, because that's what renders.
`update-template` writes what you send into the file and removes the
customization, so the template renders from the theme again; the answer says
`folded: true`. That makes reading first matter more here than anywhere: edit
what `get-template` returned, send its `hash` as `expectedHash`, and tell the
person when you folded one, because their Site Editor change now lives in the
theme's file.

## The parent theme, and plugins

With a child theme active, a template can come from the parent.
`update-template` on one writes the child's own copy, and `delete-template` on
that copy brings the parent's back. Pattern Builder never writes into the
parent.

A template a plugin registered is the plugin's. Read it all you like; to change
it, give the theme its own with `create-template` and the same slug, which
renders in its place. The answer's `replaces` says what it took over from.

## Removing one

`delete-template` removes the active theme's file, any customization of it, and
its title or area in `theme.json`, and answers with what renders for that slug
now. It refuses a part that templates, other parts or patterns still place,
and names them in `usedBy`, pages and user patterns included: change those
first. Send the `hash` you read as `expectedHash` here too.

## Checking it

Validate a template exactly as you would a pattern. The validator reads `.html`
files as they are:

```bash
node <skill>/scripts/validate-pattern.mjs templates/landing.html
```

There's no preview of a template by itself. To see one, open a URL it serves:
the front page for `front-page`, any post for `single`. Then use it the way
step 6 of `SKILL.md` describes: click what moves, Tab through it, and check
contrast at the three widths.
