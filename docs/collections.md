# Collections in Pattern Builder

How collections work in the plugin. The service side — definitions, tiers, data model and REST contract — is in the [patternbuilderwp.com repository's `docs/collections.md`](https://github.com/Twenty-Bellows/patternbuilderwp.com/blob/main/docs/collections.md); this is what the plugin builds on top of that contract.

## 1. What changes for the user

A **collection** is the unit of organisation, publishing and installation on the cloud. Every uploaded pattern is in exactly one; every account has a locked private **Personal** collection; and **Pattern Builder is the only way to install anything** — a single pattern or a whole collection — for people and for agents. In the plugin that means:

- **Directory tab**: collections first — the collections Twenty Bellows and partner studios publish. Open one, save one pattern or save the whole collection.
- **Uploaded tab**: your collections, managed here and nowhere else. Upload asks which one.
- **Installed patterns** land under a local pattern category named for the collection they came from.
- **Agents** get abilities for all of it, through the connection the WordPress user made.

### Names (D37)

A pattern on the cloud is named `{handle}/{collection}/{pattern}` — the account's handle, the collection's slug, the pattern's slug — and **that is the name it installs under here**. So:

- The connect panel's signup form asks for a handle, and the New collection dialogs (the modal on the Uploaded tab, and `New collection…` inline in the picker) ask for a slug alongside the name. Both are permanent; the field follows the name until it is typed into. `slugProblem()` and `handleProblem()` mirror the service's rules so a form can say no before the round trip; the service is the check that counts.
- A downloaded pattern keeps its cloud name instead of being renamed into this theme's namespace, and is written to `patterns/{handle}/{collection}/{slug}.php`. Core scans `patterns/` to unlimited depth, so nothing has to register it — and two accounts' `hero` patterns no longer overwrite each other, which is what `get_stylesheet() . '/' . $slug` did.
- The theme's own patterns keep the flat layout every theme uses: a directory named after the theme, inside the theme, says nothing.
- Uploading does not rename a pattern; only the namespace it hangs under changes. `export_local()` sends the last segment of the local name as the slug, and the service refuses it if that name is already used in the target collection.

## 2. The proxy (`/pattern-builder/v1/cloud/*`, `Pattern_Builder_Cloud_Controller`)

Every route stays nonce- and capability-gated and answers 401 disconnected, as today. The service's verified-account and Pro rules are enforced there; the proxy relays the refusal and its message.

| Route | Purpose |
| --- | --- |
| `GET /cloud/collections` | Public and premium collections: search, page. Replaces the old categories-as-rail use. |
| `GET /cloud/collections/{owner}/{slug}` | One collection with its pattern summaries (tokens included, so the union check needs no second pass). |
| `GET /cloud/directory` | Unchanged; gains `collection` filter. |
| `POST /cloud/download` | Unchanged: one cloud pattern into theme or user, with tokens. Gains `collection` in the request so the porter can file it. |
| `GET /cloud/library/collections` · `POST` · `PUT /{id}` · `DELETE /{id}` | The account's collections. Create relays the service's rule (collections of your own are Pro; public only for a publisher; private is the default). Delete takes the collection's patterns with it — there is nowhere to move them (D38). |
| `GET /cloud/library` | Unchanged; gains `collection` filter. |
| `POST /cloud/upload` | Gains `collection` (required; `personal` accepted). |
| `GET /cloud/status` | `/me` relayed; now carries `entitlements` (personal cap, `can_create_collections`, `can_publish`, fair use), `personal { count, cap }` and `over_policy`. |
| `GET /cloud/pattern-state` | Whether the pattern's `Cloud:` reference is in the connected account's library, asked of the service by name; the answer names the collection. With `name`, which local pattern answers to that cloud name. |
| `GET /cloud/installed` | Every cloud name a pattern on this site answers to — what a collection tile counts. |

`/cloud/categories` goes away.

**Installing a collection** is one PHP method used by the REST route and by the ability alike: `Pattern_Builder_Cloud_Porter::install_collection( $owner, $slug, $destination, $tokens )` fetches the collection, then imports each pattern in turn through the existing single-pattern path, skipping ones already here under their cloud name, collecting per-pattern results, and never stopping on one failure. The browser calls `POST /cloud/download` per pattern itself so it can show progress; the ability calls the method.

## 3. Directory tab (`src/cloud/`)

- **Landing**: a grid of **collection tiles** — a collage of up to four of the collection's previews rendered the way pattern tiles are (fixed design width, scaled), title, owner, count, and a Premium badge. The collections rail goes; the landing *is* the collections.
- **Search** shows two groups: matching collections as a row of tiles, then matching patterns as the existing grid, each pattern labelled with its collection.
- **Collection view**: a header with title, owner, description, count, and **Save collection to this site**; then the pattern grid with the details sidebar exactly as today (single-pattern save, already-installed → Edit).
- **Save collection**: one destination choice (Theme or User); one design-tokens step that computes the union of missing tokens across every pattern and offers **Add tokens & save**; then sequential downloads with "3 of 12" progress, already-installed patterns skipped, failures listed at the end with the rest installed. Premium collections show the Pro prompt before any of it when the account is free.
- **Landing footprint**: every pattern installed from a collection carries a local pattern category whose slug is `pbwp-{owner}-{slug}` and whose label is the collection's title. Theme patterns get it in `Categories:`; user patterns get the `wp_pattern_category` term. The plugin registers the category label on `init` from a site option (`pattern_builder_collection_categories`) so the inserter shows the title rather than the slug.
- **Cloud names**: an installed pattern keeps the name of the cloud pattern it is a copy of (a theme install's own name, and the `Cloud:` header or `pattern_builder_cloud` meta on either kind), and a cloud name carries its collection — so "already installed" is a lookup by name and "installed n of m" on a tile counts the local names under the collection's namespace. Nothing is recorded about the relationship.

## 4. Uploaded tab

- **Rail**: the account's collections, Personal first with a lock icon and its meter ("7 of 25", or the count alone on Pro). Selecting one filters the grid.
- **New collection**: name, slug (permanent — part of the name of every pattern in the collection), description; visibility only for a publisher, private otherwise. A free account is told, in place of the form, that collections of its own are a Pro feature, with Go Pro.
- **Collection header**: Rename, Describe, Visibility (for a publisher, or to take an inherited public collection private; Personal offers only Describe), **Delete**, which says plainly that the collection's patterns go with it and offers no alternative — a move would rewrite the middle segment of a permanent name (D38), so the work is downloaded first or not at all.
- **Over policy** (a lapsed Pro): a banner from `/me` saying what is locked and the way out — delete, or go Pro — matching the service's rule.

## 5. Upload (`PatternCloudPanel`, inside the Pattern Source panel)

- With only Personal, nothing is asked. With more, a **collection picker** defaulting to the last one used, with **New collection…** inline.
- When the target is public: "This collection is public. The pattern will be listed once it passes the checks."
- A pattern whose `Cloud:` reference the connected account's library still has offers **Update pattern on the cloud** and **Delete from cloud**; any other — never uploaded, somebody else's, or a copy since deleted — offers the upload.
- Update keeps the pattern's collection, which is the only thing it can do: a pattern stays in the collection it was uploaded into (D38).
- The block-validity gate is unchanged.

## 6. Abilities (`Pattern_Builder_Abilities`, `pattern-builder/*`)

All run as the WordPress user the application password names and use that user's connection; without one they fail with `pattern_builder_not_connected` and the message "Connect Pattern Builder to your patternbuilderwp.com account on this site first." Reads are GET, writes POST, by the same annotation rule as the existing ten.

| Ability | Method | Input → output |
| --- | --- | --- |
| `list-collections` | GET | `scope` = `community` (default) or `mine`; `search` → collections with owner, count, visibility |
| `get-collection` | GET | `owner` + `slug`, or `id` → metadata plus pattern summaries |
| `search-cloud-patterns` | GET | `search`, optional `collection` → pattern summaries, each naming its collection |
| `install-collection` | POST | `owner` + `slug`, `destination` (`theme`\|`user`), `tokens` (`add`\|`skip`) → per-pattern results |
| `install-cloud-pattern` | POST | `id`, `destination`, `tokens` → the local pattern |
| `upload-pattern` | POST | a local pattern `id` (or `title` + `content`), `collection` (default `personal`) → the cloud pattern and its state |
| `create-collection` | POST | `name`, `description` → the collection. Always private; on a free account the service refuses with the upgrade message, since collections of your own are Pro, and an agent never publishes |

No ability changes visibility or deletes a collection. The authoring guide's `abilities.md` gains the seven, and the guide index's `validate` block still names what to run before `upload-pattern`.

## 7. Code map

- `includes/class-pattern-builder-cloud-controller.php`: the routes in §2.
- `includes/class-pattern-builder-cloud-porter.php`: `install_collection()`, the collection parameter on import, the category footprint.
- `includes/class-pattern-builder-cloud.php`: `own_pattern()` (a `Cloud:` reference, looked up by name as the connected account), the collection-categories option and its `init` registration.
- `includes/class-pattern-builder-abilities.php`: §6.
- `src/cloud/`: `CloudBrowser.js` splits into `CommunityTab`, `UploadedTab`, `CollectionTile`, `CollectionView`, `CollectionPicker`, `SaveCollectionFlow`, with `cloud.scss` for the tiles; `src/components/PatternCloudPanel.js` gains the picker.
- `guides/pattern-author/references/abilities.md`, `readme.txt`, `CLAUDE.md`: the documentation.

## 8. Tests

- **PHP** (`tests/php/`, `pre_http_request` mocked as the cloud tests do today): every new proxy route relays the service's refusals verbatim; `install_collection()` skips installed patterns, continues past a failure and files the category; an installed pattern carries its cloud name; each ability is registered, refuses disconnected, and `install-collection` produces per-pattern results.
- **JS** (`tests/unit/`): the collection picker's default and inline create; the save-collection flow's progress and failure list; the token-union computation.
- **Manual**: `tests/e2e/cloud-roundtrip.php` extended to upload into a collection and install that collection on a second site.
