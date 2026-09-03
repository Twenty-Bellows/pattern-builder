# Design tokens: which slugs to reach for

A pattern references a site's design system by slug — `"backgroundColor":"base"`,
`var:preset|spacing|50`, `"fontSize":"large"`. A slug the site defines resolves;
a slug it does not resolves to **nothing at all**, silently. No error, no
warning, no fallback: the block simply renders with no background, or at the
default size, looking like a design mistake rather than a missing reference.

So the question "which slugs can I rely on?" decides whether a pattern travels.
WordPress does not answer it. Core defines presets, themes define presets, and
nothing reconciles the two or enforces a vocabulary.

## What the evidence says

Comparing what core ships against the three most recent default themes:

| | Core | Twenty Twenty-Three | Twenty Twenty-Four | Twenty Twenty-Five |
|---|---|---|---|---|
| **Colours** | `black`, `white`, `vivid-red`, … (12 named hues) | `base`, `contrast`, `primary`, `secondary`, `tertiary` | `base`, `base-2`, `contrast`, `contrast-2`, `contrast-3`, `accent`…`accent-5` | `base`, `contrast`, `accent-1`…`accent-6` |
| **Font sizes** | `small`, `medium`, `large`, `x-large` | + `xx-large` | + `xx-large` | + `xx-large` |
| **Spacing** | `20`–`80` (from `spacingScale`) | `30`–`80` | `10`–`60` | `20`–`80` |
| **Font families** | none | typeface names | `body`, `heading` | typeface names |
| **Content width** | none | 650px | 620px | 645px |

Three things fall out of that, and they are the whole of the guidance.

### Colours: only `base` and `contrast` are portable

They are the only two slugs all three default themes agree on, and they mean
what they say — `base` is the page, `contrast` is the text on it.

Everything else is theme-specific. **`primary` exists in Twenty Twenty-Three
and in neither of the two themes that followed it**, so a pattern reaching for
`primary` renders unstyled on the two newest default themes and on anything
built after them. `accent-1` is Twenty Twenty-Five's convention and Twenty
Twenty-Four calls the same idea `accent`.

Core's own twelve — `vivid-red`, `pale-pink` and the rest — are present on
every site and are the wrong answer anyway: they are a fixed palette that no
theme designed, they ignore the site's own colours, and a theme *can* switch
them off.

So: **`base` and `contrast` freely. Any other colour, check first** — ask
`get-design-system` what this site actually has, and use what is there. If
nothing suitable exists, add your own with `add-design-tokens` under a name
that says what it is for. Never inline a hex.

### Font sizes: the five-step ladder, and know where core stops

`small`, `medium`, `large`, `x-large`, `xx-large` is unanimous among the default
themes. **Core itself stops at `x-large`** — `xx-large` is a theme convention, so
a site running a theme without a theme.json does not have it.

Use the ladder. Do not invent `tiny`, `huge`, `hero` or `display`: a size that
is not on the ladder is a size no theme will have defined.

### Spacing: numeric, and only the middle is safe

Every theme uses core's numeric scale, but not the same span of it: `40`, `50`
and `60` exist in all three, while `10`, `20`, `70` and `80` each go missing
somewhere. A band whose padding is `var:preset|spacing|80` collapses to nothing
on Twenty Twenty-Four.

Prefer `40`–`60` for anything a pattern needs. Where a design genuinely wants a
large band, either check the site has the step or declare the value on the block
and accept that it will not scale with the theme.

### Font families: there is no convention

Two of three default themes name the typeface (`manrope`, `dm-sans`); one uses
semantic slugs (`body`, `heading`). Neither is safe to assume.

**Do not set a `fontFamily` on a pattern unless the design requires a specific
face**, and if it does, install it with `add-font`, which registers the preset
and returns the slug to use. A pattern that sets no font family inherits the
site's, which is almost always what you want.

### Layout: assume about 620px

The default themes sit between 620px and 650px of content width, and 1200px to
1340px wide. A pattern that needs more should declare its own `contentSize` on
its outermost group rather than assume the theme is generous.

## The rules, in short

1. **Colours** — `base` and `contrast` freely; check anything else; add what is
   missing; never inline a hex.
2. **Font sizes** — `small` … `xx-large`, and nothing invented.
3. **Spacing** — numeric, `40`–`60` for preference.
4. **Font families** — set none unless the design needs one.
5. **Layout** — assume ~620px of content; declare your own if you need more.
6. **Whatever you reference must exist.** `get-design-system` says what this
   site has. A slug that does not resolve renders as no styling at all.

## Checking a pattern against both worlds

`render-pattern` returns preview URLs, and they take a `theme` parameter naming
a theme to render against without activating it. Two themes ship with the plugin
for exactly this:

- **`blank-theme`** has no presets at all. A pattern previewed against it shows
  what the pattern itself does. If it looks right here, the markup is right.
- **`opinionated-theme`** follows every convention above with values chosen to
  be nobody's defaults: `base` is a warm cream, `medium` is 1.15rem, the content
  width is 560px, the body face is a serif. A pattern that references the right
  slugs looks *different but correct*. A pattern that hard-codes looks wrong.

```
GET /pattern-builder/v1/preview?pattern=my-theme/hero&context=page&theme=blank-theme
GET /pattern-builder/v1/preview?pattern=my-theme/hero&context=page&theme=opinionated-theme
```

A pattern that renders correctly in both travels. One that renders correctly in
only the site it was written on has hard-coded something.
