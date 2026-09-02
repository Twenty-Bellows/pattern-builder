# Collections in Pattern Builder — the plan

*Approved design, 2026-09-02, direction from Jason. The service side — definitions, tiers, data model, REST contract, decisions D30–D36 — is in the [patternbuilderwp.com repository's `docs/collections.md`](https://github.com/Twenty-Bellows/patternbuilderwp.com/blob/main/docs/collections.md); this document is what the plugin builds on top of that contract. Work is based on `2.1`.*

## 1. What changes for the user

A **collection** is the unit of organisation, publishing and installation on the cloud. Every uploaded pattern is in exactly one; every account has a locked private **Personal** collection; and **Pattern Builder is the only way to install anything** — a single pattern or a whole collection — for people and for agents. In the plugin that means:

- **Community tab**: collections first. Open one, save one pattern or save the whole collection.
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
| `GET /cloud/library/collections` · `POST` · `PUT /{id}` · `DELETE /{id}?patterns=delete\|move` | The account's collections. Create relays the service's rule (free: public only). Delete relays the refused move past the cap. |
| `GET /cloud/library` | Unchanged; gains `collection` filter. |
| `POST /cloud/upload` | Gains `collection` (required; `personal` accepted). |
| `PUT /cloud/library/patterns/{id}` (via the existing update path) | Gains `collection` to move a pattern. |
| `GET /cloud/status` | `/me` relayed; now carries `entitlements` (personal cap, can_create_private, fair use), `personal { count, cap }` and `over_policy`. |
| `GET /cloud/pattern-state` | Unchanged; the link map gains the collection. |

`/cloud/categories` goes away.

**Installing a collection** is one PHP method used by the REST route and by the ability alike: `Pattern_Builder_Cloud_Porter::install_collection( $owner, $slug, $destination, $tokens )` fetches the collection, then imports each pattern in turn through the existing single-pattern path, skipping ones the link map says are already installed from this collection, collecting per-pattern results, and never stopping on one failure. The browser calls `POST /cloud/download` per pattern itself so it can show progress; the ability calls the method.

## 3. Community tab (`src/cloud/`)

- **Landing**: a grid of **collection tiles** — a collage of up to four of the collection's previews rendered the way pattern tiles are (fixed design width, scaled), title, owner, count, and a Premium badge. The collections rail goes; the landing *is* the collections.
- **Search** shows two groups: matching collections as a row of tiles, then matching patterns as the existing grid, each pattern labelled with its collection.
- **Collection view**: a header with title, owner, description, count, and **Save collection to this site**; then the pattern grid with the details sidebar exactly as today (single-pattern save, already-installed → Edit).
- **Save collection**: one destination choice (Theme or User); one design-tokens step that computes the union of missing tokens across every pattern and offers **Add tokens & save**; then sequential downloads with "3 of 12" progress, already-installed patterns skipped, failures listed at the end with the rest installed. Premium collections show the Pro prompt before any of it when the account is free.
- **Landing footprint**: every pattern installed from a collection carries a local pattern category whose slug is `pbwp-{owner}-{slug}` and whose label is the collection's title. Theme patterns get it in `Categories:`; user patterns get the `wp_pattern_category` term. The plugin registers the category label on `init` from a site option (`pattern_builder_collection_categories`) so the inserter shows the title rather than the slug.
- **Link map** (`pattern_builder_cloud_links`): each entry gains `collection: { owner, slug, title }`, which is what "already installed" and "installed n of m" on a collection tile read.

## 4. Uploaded tab

- **Rail**: the account's collections, Personal first with a lock icon and its meter ("7 of 25", or the count alone on Pro). Selecting one filters the grid.
- **New collection**: name, slug (permanent — part of the name of every pattern in the collection), description; visibility only where the account may choose (Pro). A free account is told, in the same dialog, that the collection will be public and why.
- **Collection header**: Rename, Describe, Visibility (as allowed; Personal offers only Describe), **Delete** with the prompt: delete its patterns, or move them to Personal. A refused move (past the cap) shows the upgrade prompt and leaves delete available.
- **Pattern actions**: **Move to collection** in the details sidebar.
- **Over policy** (a lapsed Pro): a banner from `/me` saying what is locked and the three ways out, matching the service's rule.

## 5. Upload (`PatternCloudPanel`, inside the Pattern Source panel)

- With only Personal, nothing is asked. With more, a **collection picker** defaulting to the last one used, with **New collection…** inline.
- When the target is public: "This collection is public. The pattern will be listed once it passes the checks."
- Update keeps the pattern's collection; **Move to…** sits beside it.
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
| `create-collection` | POST | `name`, `description` → the collection. Always private; on a free account the service refuses with the upgrade message, since an agent never publishes |

No ability changes visibility or deletes a collection. The authoring guide's `abilities.md` gains the seven, and the guide index's `validate` block still names what to run before `upload-pattern`.

## 7. Code map

- `includes/class-pattern-builder-cloud-controller.php`: the routes in §2.
- `includes/class-pattern-builder-cloud-porter.php`: `install_collection()`, the collection parameter on import, the category footprint.
- `includes/class-pattern-builder-cloud.php`: link-map collection, the collection-categories option and its `init` registration.
- `includes/class-pattern-builder-abilities.php`: §6.
- `src/cloud/`: `CloudBrowser.js` splits into `CommunityTab`, `UploadedTab`, `CollectionTile`, `CollectionView`, `CollectionPicker`, `SaveCollectionFlow`, with `cloud.scss` for the tiles; `src/components/PatternCloudPanel.js` gains the picker.
- `guides/pattern-author/references/abilities.md`, `readme.txt`, `CLAUDE.md`: the documentation.

## 8. Tests

- **PHP** (`tests/php/`, `pre_http_request` mocked as the cloud tests do today): every new proxy route relays the service's refusals verbatim; `install_collection()` skips installed patterns, continues past a failure and files the category; the link map carries the collection; each ability is registered, refuses disconnected, and `install-collection` produces per-pattern results.
- **JS** (`tests/unit/`): the collection picker's default and inline create; the save-collection flow's progress and failure list; the token-union computation.
- **Manual**: `tests/e2e/cloud-roundtrip.php` extended to upload into a collection and install that collection on a second site.

## 9. Order of work (one pull request, commits in this order)

1. Proxy routes, `/cloud/status` shape, link map, the categories option.
2. Community tab: tiles, collection view, search groups.
3. Save collection: porter method, the flow, the footprint.
4. Uploaded tab: rail, create, header actions, move, meter, over-policy banner.
5. Upload picker in the cloud panel.
6. Abilities and the authoring guide.
7. `CLAUDE.md`, `readme.txt`, and the docs pages on the website (in that repository).

## 10. Acceptance

- Disconnected, every cloud route and every new ability refuses with the connect message; nothing installs without a connection.
- A whole collection installs in one action into the chosen destination, with one tokens step, progress, skipped-if-installed, and a failure list; the patterns carry the collection's local category and the inserter shows its title.
- Upload into a second collection asks; upload with only Personal does not; a public target says so.
- A free account cannot create a private collection from the plugin or from an agent, and sees the cap on Personal; Pro can.
- Delete offers delete-or-move and relays the refused move with the upgrade prompt.
- `npm run lint:js`, `composer lint`, `npm run test:unit` and the PHP suite (SQLite route in `CLAUDE.md`) pass.
