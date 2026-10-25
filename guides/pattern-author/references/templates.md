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
<!-- wp:group {"style":{"spacing":{"blockGap":"0"}}} -->
<div class="wp-block-group">
	<!-- wp:template-part {"slug":"header","tagName":"header"} /-->

	<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
	<main class="wp-block-group">
		<!-- wp:pattern {"slug":"my-theme/post-header"} /-->
		<!-- wp:post-content {"layout":{"type":"constrained"}} /-->
	</main>
	<!-- /wp:group -->

	<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
</div>
<!-- /wp:group -->
```

Write the content area as a `main` group, as above. It's the landmark a screen
reader jumps to, and the header and footer parts take `header` and `footer` as
their `tagName` for the same reason. The group around all three is there for
the spacing, which the next section explains.

The same goes for a part. A header's design (the logo, the menu, the button)
is a pattern, and `parts/header.html` places it:

```html
<!-- wp:pattern {"slug":"my-theme/header"} /-->
```

That way the logo can be the theme's own asset, the words can be localized,
and the design can be uploaded, none of which a part file can do.

## No gap between the header, the content and the footer

When a theme sets a block gap (most do, with `settings.spacing.blockGap` in
`theme.json`), WordPress puts that gap between every top-level block of a
template. A header that sits flush on the hero in the design gets a strip of
space under it, and the footer floats a step below the content. It looks almost
right, which is why it ships.

**Wrap the header, the content and the footer in one group with a block spacing
of zero**, as the example above does: `{"style":{"spacing":{"blockGap":"0"}}}`.
The template then has one top-level block, so the site's gap has nothing to sit
between, and inside the group the spacing is zero. On a theme with no block gap
the wrapper changes nothing, so it's always safe to write. Space the bands
themselves with their own padding.

**A footer that stays at the bottom of a short page** is the same wrapper, made
a vertical flex group at least as tall as the window, with the content set to
fill the space between:

```html
<!-- wp:group {"style":{"dimensions":{"minHeight":"100vh"},"spacing":{"blockGap":"0"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch","flexWrap":"nowrap"}} -->
<div class="wp-block-group" style="min-height:100vh">
	<!-- wp:template-part {"slug":"header","tagName":"header"} /-->

	<!-- wp:group {"tagName":"main","style":{"layout":{"selfStretch":"fill","flexSize":null}},"layout":{"type":"constrained"}} -->
	<main class="wp-block-group">
		<!-- wp:post-content {"layout":{"type":"constrained"}} /-->
	</main>
	<!-- /wp:group -->

	<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
</div>
<!-- /wp:group -->
```

`justifyContent: "stretch"` keeps every child full width, and `selfStretch:
"fill"` is what grows the content. Someone logged in sees the admin bar above
the page too, so on a short page they'll scroll by its height; visitors won't.

**A header that stays at the top as the page scrolls** goes in its own group,
inside the wrapper, with a sticky position:

```html
<!-- wp:group {"style":{"position":{"type":"sticky","top":"0px"}}} -->
<div class="wp-block-group">
	<!-- wp:template-part {"slug":"header","tagName":"header"} /-->
</div>
<!-- /wp:group -->
```

WordPress offsets it for the admin bar itself. It only works when the theme
turns sticky positioning on with `settings.position.sticky` in `theme.json`;
without that the group renders as an ordinary one, with no error anywhere. None
of Pattern Builder's abilities sets it, so if you can't edit `theme.json`
yourself, ask the person to, or leave the header where it is and say so.

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
To sit *over* the hero rather than above it, the header part goes inside the
hero's `core/cover`, above the headline, in the front page template or in the
hero pattern it places.

## Lists of posts

The posts page, the archives and search results each show a list, and the list
is a `core/query` with a `core/post-template` inside it, the card repeated once
per post.

**On those templates the loop takes the request's query.** `"inherit": true` is
what makes a category archive show that category, the second page show the
second page, and a search show its results. Without it every archive shows the
same latest posts, renders fine, and nothing reports it. `inherit` is the
block's default, but a `query` written with any other key replaces that default
whole, so always write it out:

```html
<!-- wp:query {"query":{"inherit":true},"layout":{"type":"constrained"}} -->
<div class="wp-block-query">
	<!-- wp:query-title {"type":"archive"} /-->

	<!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->
		<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"3/2"} /-->
		<!-- wp:post-title {"isLink":true,"level":3} /-->
		<!-- wp:post-excerpt /-->
		<!-- wp:post-date /-->
		<!-- wp:post-terms {"term":"category"} /-->
	<!-- /wp:post-template -->

	<!-- wp:query-pagination -->
		<!-- wp:query-pagination-previous /-->
		<!-- wp:query-pagination-numbers /-->
		<!-- wp:query-pagination-next /-->
	<!-- /wp:query-pagination -->

	<!-- wp:query-no-results -->
		<!-- wp:paragraph -->
		<p>Nothing here yet.</p>
		<!-- /wp:paragraph -->
	<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
```

`core/query-title` names the category, tag or author on an archive, and with
`"type":"search"` says what was searched for. `core/query-pagination` pages
through the results, and `core/query-no-results` is what shows when there
aren't any. When two lists share a card's look, give it a block style
variation both post templates apply; `composition.md` says why that travels
better than a pattern inside a query.

**A strip of latest posts on another page has its own query.** Set `inherit` to
false and say how many:

```html
<!-- wp:query {"query":{"inherit":false,"perPage":3,"postType":"post","order":"desc","orderBy":"date"}} -->
<div class="wp-block-query">
	<!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->
		<!-- wp:post-title {"isLink":true,"level":3} /-->
		<!-- wp:post-date /-->
	<!-- /wp:post-template -->
</div>
<!-- /wp:query -->
```

**A filter by ID doesn't travel.** `taxQuery`, `author`, `exclude` and
`parents` hold database IDs, so a strip limited to one category names that
category on this site and nothing anywhere else. Keep a theme's lists to a post
type and an order, and say in the handoff when one needs a filter set after the
theme is installed.

## Which block reads what

A template shows whichever post or page the request is for, so most of it is
blocks that read from the site rather than words of its own:

| In the design | Block | Reads |
|---|---|---|
| The site's name, logo and tagline | `core/site-title`, `core/site-logo`, `core/site-tagline` | the site's own settings |
| The big image on a post | `core/post-featured-image` | the post's featured image |
| A post's title, excerpt, date, author | `core/post-title`, `core/post-excerpt`, `core/post-date`, `core/post-author-name` | the post |
| Its categories or tags | `core/post-terms` with `term` | the post's terms |
| The body | `core/post-content` | the post |
| Comments | `core/comments` | the post's comments |

**A placeholder in a design is usually one of these.** A grey box where a post's
photo goes is the featured image, a logo-shaped box in the header is the site
logo, and a bracketed "[Excerpt]" is the excerpt. Use the block rather than an
image or copy of your own, and say which you chose.

**When the design lists things the site doesn't keep yet** (team members, case
studies, events), the list is a Query Loop over a post type of their own, and
that post type is the person's call, not yours: it shapes the whole site.
`composition.md` says how to tell records from content.

## Navigation and other things that live in the database

**Leave `ref` off `core/navigation`.** A `ref` is a menu's database ID, which
means this site's menu here and nothing anywhere else. Without it the block
shows the menu the site has, which is the one an editor manages. If the links
really are fixed, write them into the block as `core/navigation-link` blocks.

The same goes for anything else that names a row: a `core/block` by its `ref`,
a query filtered by ID (above), an image by its media library ID. The answer to
a write carries a `template_database_id` warning for each, and `template_media`
for images placed in a template, which belong in a pattern.

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

**Check the permalinks before you check the routes.** If a post's address ends
in `?p=` rather than a name, the site uses plain permalinks, and a path like
`/blog/` or `/category/news/` can show the front page instead, which reads as a
pass. Ask the person to choose a structure under Settings, Permalinks before you
trust a route.

**The Site Editor shows samples, not defects.** Editing an archive template
there, `core/query-title` reads "Archive type: Name" and the loop shows whatever
posts exist, because no category is being viewed. A post with no featured image
shows a placeholder in the editor and nothing on the front end. Judge a template
on the front end.

## What a template can't do here

Some of what a site's frame does needs PHP, script or CSS that Pattern Builder
doesn't write into a theme. Say which of these a design asks for rather than
approximating them:

- **Opening posts in the editor with their template shown.** That's a setting a
  theme makes in PHP, so editors see the post's content alone unless they turn
  on Show template themselves.
- **A header that changes as the page scrolls**, shrinking or changing color.
  That's script.
- **Anything that needs a media query**, such as a different layout below a
  width the blocks don't already respond to. `design-system.md` says what a
  block style variation can and can't carry.
- **Sticky positioning on a theme that hasn't turned it on.** The markup is
  above; the setting is the theme's.
