# Pattern dependencies and attribution — the plan

*Approved design, 2026-09-02, direction from Jason. The service side — the closed-world rule, the `origin` field, the validation and the removal of move (D38) — is in the [patternbuilderwp.com repository's `docs/dependencies.md`](https://github.com/Twenty-Bellows/patternbuilderwp.com/blob/claude/collections-feature-l3yl9t/docs/dependencies.md); this document is what the plugin builds on top of that contract. It follows collections and namespacing on the same branch.*

## 1. What changes for the user

Today, uploading a page pattern fails: it contains `core/pattern` references and the service refuses the block. After this, it works, and it brings its sections with it.

- **Upload takes the tree.** Uploading a page pattern uploads every pattern it references, and everything those reference, into the same collection. The panel says how many before it starts.
- **Install takes the tree.** Installing a page pattern installs its sections first. Nothing is left rendering a placeholder.
- **A pattern says where it came from.** A copy of somebody else's pattern carries an `Origin:` header naming the original, in the pattern file itself, and the details sidebar shows it. It survives editing, re-uploading, and any number of hops.
- **Move to collection is gone.** Download it and upload it where you want it.

## 2. The tree

A pattern's dependencies are found by parsing its saved markup and collecting the `slug` of every `core/pattern` block, recursively through the patterns those name. `src/utils/patternTree.js`:

- `referencesOf( content )` — the names a pattern's markup references, in document order.
- `treeOf( name, resolve )` — the transitive closure, leaves first, with cycle detection. `resolve` is "give me this pattern by name", so the same function serves the local walk and any future remote one.

Three things fall out of the walk, and all three are checked before anything is sent:

| Finding | What happens |
| --- | --- |
| A referenced pattern is not installed | Refuse, naming what is missing. This is the common real error and the message has to be the useful one. |
| A cycle | Refuse, naming the loop. |
| A referenced pattern is a `wp_block` | Cannot happen — `core/pattern` resolves against the block-pattern registry and user patterns are not in it. The walk treats an unresolvable name as missing. |

The existing block-validity gate (`src/utils/blockValidity.js`) runs over **every member of the tree**, not just the pattern being uploaded, and one invalid member stops the whole upload. It is the same check; it just runs more times.

## 3. Upload

`Pattern_Builder_Cloud_Porter` gains `export_tree( $type, $id )` beside `export_local()`, and the controller uploads what it returns.

1. **Walk** the tree locally, leaves first.
2. **Pre-flight.** Fetch the target collection's patterns once (`GET /cloud/library/collections/{id}` already returns them with their namespaces). For each member: already there and unchanged → skip; already there and changed → update; not there → create. Count what that adds and check it against the Personal cap and the account's entitlements **before uploading anything**, so a tree that will not fit is refused whole rather than halfway.
3. **Rewrite** each member's references from local names to `{handle}/{collection}/{slug}` — the target collection's namespace, with each dependency's own slug unchanged, because uploading never renames a pattern. The rewrite happens on the exported copy; the local file is untouched.
4. **Upload** leaves first. Each `POST`/`PUT` is validated by the service against what is already in the collection, so ordering is the whole of the transaction: no batching, no rollback.
5. **Link** every member in the link map, as a single upload does today.

A failure part-way leaves the successfully uploaded members in place — they are valid patterns on their own — and reports which member failed and why. The parent is not uploaded.

The panel shows the tree before the upload: *"Uploads 5 patterns: Home Page, plus Bold Hero, Feature Grid, Testimonial, CTA Band."* Where an existing pattern in the collection will be updated as part of the tree, it says so, because updating a shared section changes every page in the collection that uses it.

## 4. Install

Installing needs no rewriting at all. The reference in the markup is `studio-a/heroes/hero`, the file written is `patterns/studio-a/heroes/hero.php` with that exact `Slug:` header, and it resolves. This is namespacing paying for itself.

- `install_cloud_pattern()` resolves the tree from the collection listing (every dependency is in the same collection, guaranteed by the service) and installs **leaves first**.
- A member already installed under that name is skipped, so installing two page patterns that share a hero installs the hero once. Installs are idempotent by name.
- **Dependencies always install as theme patterns**, even when the parent is going to a user pattern, because a `wp_block` can never be a `core/pattern` target. The destination step says so in a line rather than silently doing something surprising.
- A failed dependency aborts the parent and names what failed; already-written members stay.
- `install_collection()` needs no ordering change — it installs everything anyway — but it does need the leaves-first order so a partial failure never leaves a page ahead of its sections.

## 5. Attribution

The `Origin:` file header, and `pattern_builder_origin` post meta for user patterns.

| Moment | Rule |
| --- | --- |
| Install, package carries an `origin` | Write it, unchanged. |
| Install, no `origin`, and the package's handle is not the connected account's | Write the package's own name. |
| Install, no `origin`, and it is the account's own pattern | Write nothing. |
| Upload | Send the header if there is one. |

Core parses a fixed list of pattern-file headers and ignores the rest, so `Origin:` is inert to WordPress and travels with the theme — which is the point, since a link map does not. It is the one piece of service metadata that belongs in a distributed theme file: a **link** is this site's relationship to a cloud copy and stays in the option map, an **origin** is part of the pattern's identity.

`PatternDetailsPanel` and the editor's Pattern Source panel show one line — *"Originally from studio-a/heroes/hero"* — linking to that pattern's page on patternbuilderwp.com. By construction an origin never names your own account, so there is no display-time check and a pattern you authored shows nothing.

## 6. What goes away

- `PUT /cloud/library/{id}`, the proxy route that moved a cloud pattern.
- **Move to collection** in the Uploaded tab's details sidebar and in `PatternCloudPanel`.
- The delete-collection dialog's *move its patterns to Personal* option: deleting a collection deletes its patterns, and the dialog says so.
- The "move within the cap" line in the over-policy banner.

## 7. Abilities

- `upload-pattern` uploads the tree, and its description says so — an agent that uploads a page pattern is uploading five patterns and the count comes back in the result.
- `install-cloud-pattern` and `install-collection` install trees; per-pattern results already carry each member.
- No new ability. Nothing here needs an agent to do anything it could not already ask for.

## 8. Code map

- `src/utils/patternTree.js`: the walk, cycle detection, reference rewriting. Pure, and unit-tested on its own.
- `includes/class-pattern-builder-cloud-porter.php`: `export_tree()`, tree install, the `Origin:` stamp on import.
- `includes/class-pattern-file-store.php`: the `Origin:` header, read and written.
- `includes/class-pattern-builder-cloud-controller.php`: the upload endpoint takes a tree; the move route goes.
- `src/components/PatternCloudPanel.js`, `src/cloud/UploadedTab.js`: the tree summary before upload, the removal of Move to…, the origin line.
- `docs/`, `CLAUDE.md`, `readme.txt`, `guides/pattern-author/`: the documentation.

## 9. Tests

- **JS** (`tests/unit/pattern-tree.test.js`): references extracted from nested markup; the transitive walk in leaves-first order; a cycle detected and named; a missing name reported; references rewritten into a target namespace without touching anything else in the markup.
- **PHP** (`tests/php/`, `pre_http_request` mocked as every cloud test does): `export_tree()` sends leaves first and rewrites references; a missing dependency refuses before any request is made; the install order; a second install of a shared dependency is skipped; the `Origin:` stamp in each of its three cases; the header round-trips through a file write and read.
- **Manual**: `tests/e2e/cloud-roundtrip.php` extended to upload a page pattern with two sections and install it on a second site, checking the installed page renders its sections rather than placeholder copy.

## 10. Order of work (commits, stacked on the collections branch)

1. `patternTree.js` and its tests — the walk, alone, with nothing depending on it yet.
2. The `Origin:` header: file store read/write, the stamp on import, the details line.
3. `export_tree()` and the upload path, including the pre-flight and the refusals.
4. Tree install, leaves first, with the theme-destination rule.
5. Remove move, in the plugin and the service alike.
6. Abilities, the panel copy, `CLAUDE.md`, `readme.txt`, the authoring guide.

## 11. Acceptance

- A page pattern with two sections uploads as three patterns, leaves first, with its references rewritten to the target collection's namespace; the local files are unchanged.
- Uploading with a section missing locally refuses by name, before any request.
- A cycle refuses by name.
- An upload that would exceed the cap refuses before uploading anything.
- Installing that page pattern installs three patterns, sections first, and the page renders its sections; installing it again installs nothing.
- Installing a page as a user pattern lands the page as a `wp_block` and its sections as theme patterns.
- A pattern installed from another account carries `Origin:`; one installed from your own does not; the header survives an edit and a re-upload into another collection.
- Nothing in the plugin moves a pattern between collections.
- `npm run lint:js`, `composer lint`, `npm run test:unit` and the PHP suite pass.
