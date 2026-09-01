# Asking a running site

Where a WordPress site is running and has Pattern Builder active, it can
answer the questions an agent otherwise has to guess at — what the design
system resolves to, which blocks exist, what patterns are already there — and
it can store finished markup. This is WordPress core's Abilities API, so the
same calling convention works for any plugin that registers abilities.

None of it is required. A pattern can be written and validated with nothing
but the theme's files. This is the shortcut where a site is available.

## Connecting

Abilities are permission-gated, so the call needs authentication. Application
Passwords (core, HTTP Basic) are the usual way:

```bash
# In wp-admin: Users → Profile → Application Passwords, or:
wp user application-password create <login> pattern-author --porcelain
```

```bash
export WP_URL=https://example.com
export WP_USER=jane
export WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'
```

**If application passwords appear unavailable**, the site is on plain HTTP.
Core only offers them over HTTPS or when `WP_ENVIRONMENT_TYPE` is `local` —
`development` is not enough. In a wp-env project that means a `config` entry:

```json
{ "config": { "WP_ENVIRONMENT_TYPE": "local" } }
```

## The calling convention

Two things about it are easy to get wrong, and both produce confusing errors.

**Input goes under an `input` key**, never at the top level:

```bash
# GET — nested query params
curl -u "$WP_USER:$WP_APP_PASSWORD" -G \
  --data-urlencode 'input[id]=my-theme/hero' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/get-pattern/run"

# POST — an "input" wrapper in the body
curl -u "$WP_USER:$WP_APP_PASSWORD" -X POST -H 'Content-Type: application/json' \
  --data '{"input":{"title":"Hero","content":"<!-- wp:group -->…<!-- /wp:group -->"}}' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/create-pattern/run"
```

Top-level fields give `input is not of type object`.

**The annotations pick the HTTP method.** A read-only ability is refused over
anything but GET; a write wants POST. `Read-only abilities require GET method`
means exactly that, not that anything is misconfigured.

Discover what a site offers with `GET /wp-abilities/v1/abilities` — the list is
filtered to what the authenticated user may run, and each entry carries its
input and output schema.

## The abilities

| Name | Method | Purpose |
|---|---|---|
| `pattern-builder/get-design-system` | GET | palette, gradients, spacing, font sizes and families, layout widths, style variations — resolved across core, parent theme, child theme and the active variation |
| `pattern-builder/list-block-types` | GET | every block registered here, with attribute schemas. Optional `input[namespace]=core` |
| `pattern-builder/list-patterns` | GET | patterns on the site, without markup. Optional `input[source]=theme\|user\|all` |
| `pattern-builder/get-pattern` | GET | one pattern with its markup. `input[id]` is a namespaced name or a `wp_block` post ID |
| `pattern-builder/render-pattern` | GET | the front-end HTML a stored pattern produces. `input[id]` as above |
| `pattern-builder/get-authoring-guide` | GET | these documents, as Markdown. No input for an index; `input[guide]` by name, or `all` |
| `pattern-builder/create-pattern` | POST | store finished markup. `title` and `content` required; `source` is `theme` (default) or `user`; also `name`, `description`, `categories`, `keywords`, `synced`, `viewportWidth` |
| `pattern-builder/update-pattern` | POST | replace an existing pattern. `id` and `content` required |

## Guides the site itself carries

`get-authoring-guide` serves this documentation over the wire — the same
Markdown you are reading, so an agent whose harness has no notion of a "skill"
can install it wherever it does read instructions from.

It is worth calling **even when you already have these documents**, because a
site's set may not be this set. A theme can amend a shipped guide or add one of
its own through the `pattern_builder_authoring_guides` filter, and that is
where a project's house rules live: which blocks this build has settled on, the
copy voice, why a section is composed the way it is. Nothing here can know
those; the site can.

For a theme author, adding one is a filter:

```php
add_filter( 'pattern_builder_authoring_guides', function ( $guides ) {
	$guides['house-rules'] = array(
		'title'   => 'House rules for this theme',
		'content' => "# House rules\n\nSections are full width…",
	);

	// Or amend one that ships:
	$guides['block-vocabulary']['content'] .= "\n\nCore blocks only on this site.";

	return $guides;
} );
```

Guides are text rather than file paths on purpose: one added this way needs no
filesystem access, and no caller can steer a read outside the plugin. An entry
without `content` is dropped rather than served empty.

## What they will not do for you

**Nothing here generates a pattern.** No ability takes a prompt. The writes
take markup you have already composed — the judgement of what to build is
yours, and this is only somewhere to put the result.

**Nothing here validates markup.** Block validity is decided by re-running the
block's `save()`, which is JavaScript; no PHP endpoint can answer it. Run the
validator before calling `create-pattern`, or you will store markup that
renders correctly and breaks the moment anyone opens it in the editor.

`render-pattern` is the nearest thing to a check the site can offer, and it
answers a different question: what HTML comes out. That is genuinely useful for
catching a missing supports class — the styling that silently didn't apply —
which the validator cannot see. It says nothing about whether the block is
valid.

## Without a site

Everything the reads provide is available from the files:

- design system → `theme.json`, `styles/*.json`, the parent theme's `theme.json`
- block types → assume core; check anything else against the plugins present
- existing patterns → the theme's `patterns/` directory

Which is the normal case when authoring in a theme repository, and works fine.
