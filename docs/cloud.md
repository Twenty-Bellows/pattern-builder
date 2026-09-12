# The cloud module — patternbuilderwp.com

The service side lives in the
[patternbuilderwp.com repository](https://github.com/Twenty-Bellows/patternbuilderwp.com);
this is the plugin half. Collections are [`collections.md`](collections.md),
dependency trees and attribution are [`dependencies.md`](dependencies.md).

## Connecting

Users connect their account **per WordPress user** by signing in or signing up
**inside wp-admin**. The connect panel posts credentials to this site's proxy,
which relays them server-side to the service's `/auth/login` or `/auth/signup`
and stores only the returned bearer token (`Pattern_Builder_Cloud`, user meta).
The browser never visits the service, and credentials are never logged or
stored.

The service URL is the `pattern_builder_cloud_url` option, overridable by a
`PATTERN_BUILDER_CLOUD_URL` wp-config constant — the declarative choice for dev
setups, since it survives DB resets — and filterable.

**The browser never talks to the service.** All cloud traffic flows through
nonce- and capability-gated proxy routes (`/pattern-builder/v1/cloud/*`,
`Pattern_Builder_Cloud_Controller`), which relay every refusal as it came,
upgrade link included: the service decides, the plugin only says so.

Both cloud tabs show the connect panel until a token exists, and the proxy
enforces the same rule — a disconnected `/cloud/directory`, `/cloud/collections`
or directory `/cloud/download` answers 401. So what a site downloads is
downloaded by somebody. The website's own directory stays public.

## The package

`Pattern_Builder_Cloud_Porter` converts local patterns ↔ the service's Portable
Pattern Package (`pbp/1`).

**Exports** bundle local images as `pbp-asset://` placeholders plus files.
Every reference the service checks is matched by host and path — `src`, a block
attribute's `url`, CSS `url()` — so a query string or an http/https difference
does not hide one, since anything left pointing at this site is refused there.
An image on another host, or one of a type a package cannot carry (JPEG, PNG,
GIF, WebP only), fails the export with that URL named. Attachment ids and
`wp-image-N` classes are dropped on the way out, since id 57 names nothing on
another site.

**Imports** fetch package assets into the media library, re-sanitize the markup
(KSES and scheme checks — never trust the wire), then land as a `wp_block` or
flow through `Pattern_File_Store::update_theme_pattern()`. A user pattern's
blocks are pointed at the attachments just created; a theme pattern's images
move into the theme's own `assets/images`, so it carries no ids. Assets are
always fetched from the configured service origin — URLs are re-rooted onto it,
never fetched from the host they name — so a service self-identifying by a
different URL than the one this site reaches it by just works.

### Version floor

A package says which WordPress it needs (`minWordPress`), and
`version_problem()` refuses an install this site is too old for **before
anything is written**. The re-sanitize runs against *this* site's KSES, so an
older release does not merely fail to render unknown markup — it strips it, and
the pattern would land looking installed and missing what it was for. MathML is
the case that costs content rather than appearance. The browser says so first,
from `needsNewerWordPress()`; the server is the check that counts.

## What travels with a pattern

**Design tokens.** A download carrying tokens this site lacks shows them first,
then writes only the missing ones into the destination the user already chose:
theme.json for a theme pattern, Global Styles for a user one. The server
re-checks.

**Block style variations**, for the same reason and by the same route: the
markup carries `is-style-{slug}` and the definition lives in the theme it was
authored in, so a pattern reaching for one would otherwise arrive with the
class intact and nothing styling it. `Block_Style_Variations::carried_by()`
gathers what the markup applies — only what *this* site defines, since one
declared in a block's own `block.json` ships with WordPress and would collide
with core's — and `install()` writes the ones the destination lacks, never
overwriting, always into the theme. Global Styles has no partials mechanism to
register one in, which is the same reason a dependency always lands as a theme
pattern.

`collect_tree()` therefore walks one level further down than the markup:
markup → `is-style-*` → the definition → the presets *that* references, because
a variation's colours are in its definition and none of them appear in the
markup.

**Global styles deliberately do not travel.** There is one
`styles.elements.link.color.text` on a site and it paints every page, so a
pattern that repainted its destination on install would be vandalism.

## CSS in a variation: `Safe_Css`

WordPress does not sanitize a theme.json `css` property — it gates it on
`edit_css` — so a string closing its own selector writes rules for the whole
document. `set-global-styles` therefore refuses one outright: a `css` at the
root or on an element is scoped to nothing a pattern brought with it.

A variation is the exception. `add-block-style-variation` accepts a `css` at the
partial's top-level `styles.css` and nowhere deeper, because its selector is a
class the pattern's own markup carries — and without one a variation cannot
express a pseudo-element, a descendant rule or a hover state, which is most of
what a variation is for.

What it accepts is decided by **`Safe_Css`**, a grammar rather than a filter:
declarations first, then nested rules anchored on `&`. That is a strict subset
of what `WP_Theme_JSON::process_blocks_custom_css()` parses *correctly* — that
parser splits on `&`, strips every `}` and explodes on `{`, so a declaration
after a rule, a second level of nesting and a `&` inside a string are all
refused because core mis-reads them.

The bans that are about danger:

- No `@` and no comments.
- No `<` anywhere, quoted strings included: the HTML tokenizer ends a `<style>`
  element at `</style` whatever CSS thinks a string is.
- No `,`, `+` or `~` in a selector: core's `scope_selector()` distributes over a
  comma and lands `body` at the top level, and a sibling combinator reaches
  outside the block the markup carries.
- No backslash outside a quoted string, where `u\72l(` spells `url(` past a
  name check — and allowed inside one, where `content: "\2713"` is how an icon
  glyph is written.
- An allow list for every `name(` in a value.

Nothing is stripped or repaired: a repair is how a checker and a browser come
to disagree. A refusal names the rule and the fragment.

**The check runs three times** — author, service, destination — and the third
is the one that counts, because it is the machine that will execute the CSS. A
string refused there costs that one look rather than the download
(`variationsRefused`).

There is deliberately **no `edit_css` gate**: a gate would say unvalidated CSS
is acceptable from a privileged caller, and `edit_css` is super-admin-only on
multisite, so it would stop an ordinary network administrator writing what they
can already write by hand.

`includes/class-safe-css.php` is vendored **byte-for-byte identical but for its
namespace** into the service as `includes/patterns/class-safe-css.php`; a
disagreement between the copies is the vulnerability. The accept/reject corpus
in `tests/php/fixtures/safe-css-cases.json` is the same file in both
repositories, so a rule loosened on one side turns a test red on the other.

## Which cloud pattern a local one is a copy of

The link lives **on the pattern itself**: the `Cloud:` file header, or
`pattern_builder_cloud` post meta for a user pattern —
`{handle}/{collection}/{slug}`, written by an upload (the name the service
answers with) and by an install (the name the package carries), carried through
theme↔user conversion, a rename and `update-pattern`, and dropped by
duplication.

It is a separate field from `Origin:` because a pattern can be somebody else's
work *and* have a copy of its own; overwriting attribution with a
self-reference would lose the credit on the next update.

**Nothing about the relationship is remembered.** Whether the copy still
exists, and whether it is the connected account's, is asked of the service each
time (`Pattern_Builder_Cloud::own_pattern()`), so a pattern deleted on the
cloud, a deleted collection or a different account connected here needs no
bookkeeping and simply reads as not on the cloud.

The download side needs no remembered reference either:
`Pattern_File_Store::cloud_names()` lists every cloud name a local pattern
answers to, from file headers and one meta query. That is what the details
card's Edit button, the collection view's installed markers, the tiles'
"installed n of m" and a whole-collection install's skip all read.

## The per-pattern control

Connected users get `PatternCloudControls` inside the Pattern Source panel, so
it appears in both the browse sidebar and the editor:

- *Update pattern on the cloud* and *Delete from cloud* when
  `/cloud/pattern-state` finds the pattern's reference in the connected
  account's library, with the line above them naming its collection.
- *Upload to the cloud* with the collection picker otherwise — never uploaded,
  somebody else's, or a copy since deleted.

There is no "changed since upload": Update is always offered, and pushing one
that changed nothing costs a request. Deleting from the panel also clears that
pattern's reference, so a different pattern uploaded under the name later is
not taken for its copy.

## Accounts, checkout and telemetry

Three things the plugin relays rather than decides.

**Creating an account** applies the service's password rule (eight characters
with an upper-case letter, a digit and a symbol — `passwordProblem()` mirrors
it so the form says what is missing before the round trip), asks the marketing
question as two buttons with neither preselected (a pre-checked box is not
consent under GDPR and reads as opt-out to the wp.org review team; no answer
relays as `no`), links the service's Terms of Service and Privacy Policy above
the button (at the configured service's own `/terms/` and `/privacy/`, so a
development service shows its own), and leaves the account unverified until
the emailed link is opened. *Forgot your password?* posts the address to
`/cloud/password/forgot`; the emailed link finishes the reset on
patternbuilderwp.com, never here.

**Go Pro** opens Freemius's overlay checkout on the Pattern Builder screen
(`src/cloud/checkout.js`). The service's `/me` carries the configuration;
Freemius's script loads on the first click and never before — it is the one
third-party script this plugin loads, permitted by wp.org guideline 8 as a
documented service on its own domain, and the readme's External services
section says so. `purchaseCompleted` posts the licence id to
`/cloud/billing/sync` so Pro is on before the overlay closes; the hosted
checkout URL stays as the fallback and the status poll as the safety net.

**Usage telemetry** is opt-in, because wp.org guideline 7 requires explicit
consent. The browse app asks once per site with Allow and No thanks
(`TelemetryPrompt`), a site that declined is offered it once more as a one-line
Allow on the connect panel, and `track()` is a no-op until the answer is yes.
When allowed, named events carry the environment — plugin, WordPress and PHP
versions, locale, theme slug, multisite, environment type, and the connected
account id — under a random install id minted at opt-in. Never the site's URL
or name, never content. Events are buffered per request and posted once on
`shutdown` to the service's public `/telemetry` relay, non-blocking; a lost
batch is lost. The plugin loads no analytics script and names one service.

## Nothing generates patterns, at either end

Neither the plugin nor the service runs a model; the service carried a pipeline
for a while and has removed it (its decision log, D47). Generation is what an
agent the user already runs does through the abilities — create, edit, upload,
install, read the authoring guide — with the `pattern-author` and
`design-reproduction` skills, on the user's own provider. The create-pattern
modal makes blank patterns only, and there is no generate proxy.

## Code map

| Piece | Job |
|---|---|
| `Pattern_Builder_Cloud` | Token store, account cache, `own_pattern()`. |
| `Pattern_Builder_Cloud_Controller` | The proxy routes. |
| `Pattern_Builder_Cloud_Porter` | `pbp/1` conversion both ways, trees, installs. |
| `Pattern_Builder_Cloud_Tokens` | Design tokens: the value grammar and the never-overwrite rule. |
| `Pattern_Builder_Cloud_Abilities` | The seven cloud abilities. |
| `Pattern_Builder_Telemetry` | The site's answer, the install id, the shutdown batch. |
| `src/cloud/` | `CloudBrowser` (shell), `CommunityTab`, `UploadedTab`, `CollectionTile`, `CollectionView`, `SaveCollectionFlow`, `CollectionPicker`, `collections` (pure helpers), `checkout`. |

The plugin remains fully functional disconnected.
