# Themes for pattern development

Two block themes that travel with the plugin. Nothing registers or activates
them: they are here so they are *available* wherever the plugin is, including
on a site an agent only reaches over HTTP, and so a later feature can offer
them without shipping anything new.

| Theme | For |
| --- | --- |
| `blank-theme` | A control. Nothing underneath a pattern, so what you see is what the pattern does. |
| `opinionated-theme` | An adversary. A design system of its own that a pattern has to survive. |

(The PHP suite's long-standing fixture, `simple-theme`, stays in `dev-assets/`
— it is a test fixture rather than a theme anyone would use, and `dev-assets/`
ships nowhere.)

## The pair

`blank-theme` and `opinionated-theme` are only useful together, and they answer
different questions:

- **Blank** — no presets, no styles, and templates that constrain nothing. If a
  pattern looks wrong here, the pattern is wrong. Its full-bleed bands are
  full-bleed because the pattern said so and nothing else.
- **Opinionated** — a palette using the slugs everyone reaches for (`primary`,
  `accent`), a type scale at sizes nobody expects (`medium` is 1.15rem), a
  560px content width, headings it styles itself, and root padding. It is
  opinionated, *not broken*: `alignfull` still escapes, because a template that
  caps every band at the content width is a fault rather than a view, and a
  harness that shipped one would teach the wrong lesson.

A pattern that renders correctly in both is portable. One that only renders in
`blank` tells you the markup is fine and nothing about whether it will work on
anybody's site.

The two map onto what `render-pattern` hands back: `preview.standalone` is the
blank question, `preview.page` is the opinionated one.

## Why blank-theme has a functions.php

Because `theme.json` cannot make a theme blank, though it reads as though it
can. `settings.color.defaultPalette: false` hides core's colours from the
editor's picker and governs whether a theme may reuse their slugs — it does
**not** stop `--wp--preset--color--vivid-red` being emitted. The same is true
of the default font sizes, spacing steps, gradients and duotones. A pattern can
therefore depend on a preset the theme believes it switched off, and nothing
reports it.

Core's own presets arrive through the `wp_theme_json_data_default` filter.
Emptying them there is what actually leaves nothing behind, and
`blank-theme/functions.php` does exactly that. `Test_Lab_Themes` asserts the
result against the generated custom properties rather than against the JSON,
because the JSON is a statement of intent and the CSS is the outcome.

## Using them

They are ordinary block themes, and nothing in the plugin registers them. Copy
either into `wp-content/themes/`, or point WordPress at this directory:

```php
// wp-config.php, or a small mu-plugin
register_theme_directory( WP_PLUGIN_DIR . '/pattern-builder/themes' );
```

Both then appear in Appearance → Themes and activate normally. Activating a
theme replaces the site's front end, so this belongs on a local or staging
install rather than anywhere a visitor will see.

## What they are worth to an agent

Nothing, as files. An agent reaching a site over HTTP cannot see a directory
inside a plugin, cannot activate a theme, and authors against whatever theme
happens to be active. These become useful in one of two ways, and both need
something built on top of them:

- **Somebody activates one**, and the site becomes a place to author patterns
  against a known design system — `get-design-system` then answers with
  nothing, and the agent knows it has nothing to lean on.
- **A preview renders against one without activating it.** `render-pattern`
  already hands back preview URLs; rendering those against a named theme rather
  than the active one would let an agent check a pattern in both worlds without
  touching the site. That is the version that makes these themes worth carrying
  for a remote agent, and it is not built yet.
