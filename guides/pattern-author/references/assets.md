# Images and fonts a pattern points at

A pattern is markup plus the files it references. The markup you can write;
the files need somewhere to live on the site, and a reference the pattern can
carry to them. This is how.

## What this document covers

Getting an image or a typeface onto a running site with Pattern Builder
active, and knowing what to write in the markup afterwards. It assumes the
connection set up in `references/abilities.md`.

Editing theme files directly instead? Then the same rules apply without the
routes: images go in the theme's `assets/images/`, fonts in `assets/fonts/`
with a `fontFace` entry in `theme.json`, and a theme pattern references its
own assets through PHP rather than a URL.

## Look before you add

Most sites already have what a pattern needs. `find-media` lists both places
an image can be — the media library, and the theme's own `assets/images`,
which no core route reports:

```bash
curl -s -u "$WP_USER:$WP_APP_PASSWORD" \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/find-media/run&input[search]=hero"
```

Every result carries a `reference` field. **Use it verbatim.** It is not the
same string in both cases, and the difference matters:

| Where the file is | `reference` looks like |
| --- | --- |
| Media library | `https://example.com/wp-content/uploads/2026/09/hero.webp` |
| Theme assets | `<?php echo get_stylesheet_directory_uri() . '/assets/images/hero.webp'; ?>` |

A theme pattern is a PHP file, so its own assets are composed at render — a
hard-coded URL breaks the moment the theme is installed anywhere else. That is
why the reference is handed to you rather than left to be assembled.

## Adding an image

Three ways in, and which one you use depends on what you are holding.

### You hold a file (JPEG, PNG, WebP, AVIF, GIF)

**This is the case an ability cannot serve.** Abilities are JSON in and JSON
out, so bytes would have to be base64 inside that JSON — which means reading
the file into your own context and paying for it there. There is a plain REST
route instead, shaped exactly like core's `POST /wp/v2/media`: the bytes are
the request body, and the filename comes from a `Content-Disposition` header.

```bash
curl -s -u "$WP_USER:$WP_APP_PASSWORD" \
  -H 'Content-Disposition: attachment; filename="hero.webp"' \
  -H 'Content-Type: image/webp' \
  --data-binary @hero.webp \
  "$WP_URL/?rest_route=/pattern-builder/v1/assets&destination=theme"
```

The file goes from disk to the site without passing through you. `@hero.webp`
is curl reading it; nothing encodes it and nothing reads it into a prompt.

| Parameter | Meaning |
| --- | --- |
| `destination` | `theme` (default) writes the active theme's `assets/images`; `media` adds a media library attachment |
| `filename` | Overrides the `Content-Disposition` filename, if setting the header is awkward |
| `alt` | Alternative text. Recorded on a media library attachment |

A multipart form works too — the first file field is taken, whatever it is
named — so `-F 'file=@hero.webp'` is equivalent.

The answer carries `reference`, plus `width` and `height` as stored. **An
image over 2400px on its longest edge is resized down to it**, because
nothing else resizes a file written into a theme and a pattern should not ship
a 4MB hero. Use the returned dimensions, not the ones you sent.

### You have a URL

`add-asset` has the site fetch it, which keeps the bytes out of your context
just as well:

```bash
curl -s -u "$WP_USER:$WP_APP_PASSWORD" \
  -H 'Content-Type: application/json' \
  -d '{"input":{"url":"https://example.org/photo.jpg","destination":"theme"}}' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/add-asset/run"
```

Only do this for an image the user has pointed you at. Fetching something
because it looked right in a search result puts a licensing problem in
somebody's theme.

### You can draw it

An SVG is the one image you can author outright, so `add-asset` takes SVG
markup directly under `svg` (with a `filename`). Scripts, event handlers and
external references are stripped on the way in, and SVG can only go to the
theme — WordPress does not accept SVG in the media library.

For a pattern under construction, `add-placeholder-image` is quicker than
drawing one:

```bash
curl -s -u "$WP_USER:$WP_APP_PASSWORD" \
  -H 'Content-Type: application/json' \
  -d '{"input":{"width":1400,"height":700,"label":"Hero"}}' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/add-placeholder-image/run"
```

**Never point a pattern at a remote placeholder service.** A pattern whose
`<img>` names `placehold.co` makes every page view fetch from somebody else's
server, and it stops working when that server does. Draw one locally instead.

### Never invent a URL

An `<img>` pointing at a plausible-looking URL you made up will 404, and it
will do so on a page the user thinks is finished. Either the file is on the
site — because you found it or added it — or the pattern gets a placeholder.
There is no third option, and a data-URI image is not one: it bloats the
markup past what an editor can comfortably handle.

## Adding a font

Two things have to happen and only one is obvious. The files make the
typeface available; a **`fontFamily` preset** is what actually renders it,
because `wp_print_font_faces()` builds its `@font-face` rules from the merged
`theme.json` rather than from the font library. A font installed without the
preset is a font nothing can use.

`add-font` does both:

```bash
curl -s -u "$WP_USER:$WP_APP_PASSWORD" \
  -H 'Content-Type: application/json' \
  -d '{"input":{"family":"Fraunces","weights":["400","700"],"destination":"theme"}}' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/add-font/run"
```

| Parameter | Meaning |
| --- | --- |
| `family` | The family name as the collection lists it. `list-fonts` confirms the spelling |
| `weights` | Defaults to `["400","700"]`. A variable font covering the weight is installed once and serves the range |
| `styles` | `normal` (default) and/or `italic` |
| `destination` | `theme` (default) writes `theme.json` and `assets/fonts`, so the font is part of the theme; `user` writes Global Styles and the site's font library |

The source is the Google Fonts collection WordPress itself ships, and **the
files are copied to the site and served from it** — nothing is fetched from
Google at render time, which is both the privacy answer and the reason this
works on a site with no outbound access at render.

The answer tells you how to reference it, which is the point of registering
the preset:

```json
"reference": {
  "attribute": "\"fontFamily\":\"fraunces\"",
  "class": "has-fraunces-font-family",
  "css": "var(--wp--preset--font-family--fraunces)"
}
```

So a heading in that face is `{"fontFamily":"fraunces"}` **plus** the
`has-fraunces-font-family` class — both, as with every preset reference. The
attribute alone renders in the theme's default face, silently.

### A licensed font you hold

`add-font` installs from the collection only, deliberately: every family in
Google Fonts is open-licensed, and fetching a font file from an arbitrary URL
is a licensing decision that is not an agent's to make. To self-host
something you have bought, put the files in the theme's `assets/fonts/` (the
asset route above, or directly) and register the preset yourself — the
`fontFace` `src` uses `file:./assets/fonts/name.woff2`, which WordPress
rewrites into a theme URI.

## Order of work

Assets before the markup that references them, for the same reason design
tokens come first: a reference that does not resolve fails silently. An
`<img>` with a dead `src` shows a broken image; a `fontFamily` that names no
preset renders in the default face with nothing to indicate why.

1. `find-media` — is it already here?
2. Add what is missing, and keep the `reference` from the answer.
3. `add-font` for a typeface, and keep its `reference` too.
4. Write the markup using those references verbatim.
5. Validate, then store.
