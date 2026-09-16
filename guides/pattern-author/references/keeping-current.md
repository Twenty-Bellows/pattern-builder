# Keeping these guides current

WordPress ships new blocks every release and occasionally moves a setting from
one place to another. These documents make claims that go stale when it does,
and the stale ones fail quietly: a pattern written against a claim that is no
longer true validates, renders, and is simply wrong.

This is the maintenance pass to run at each WordPress release. It is mostly
mechanical, because the checks are the same tools the guides tell an author to
use.

## What goes stale, and how it shows

| Claim | Where | How it breaks |
|---|---|---|
| The core vocabulary by purpose | `block-vocabulary.md` §2 | A new block exists for a job the guide still solves the long way round |
| "relatively recent", "newer than you expect" | `block-vocabulary.md` | Prose that ages into being wrong about what is safe to assume |
| The attribute-to-class table | `block-markup.md` | A support moves; markup the table calls correct becomes OLD FORM |
| Structural requirements per block | `block-markup.md` | A block changes what `save()` writes; the documented shape becomes INVALID |
| What patternbuilderwp.com accepts | `block-vocabulary.md` §1 | The service's allowlist widens or narrows and the guide misreports it |

The precedent worth remembering is the one the guides already cite: block
library 10.5 moved text alignment out of a heading's `textAlign` and into a
typography support, so the value now lives at `style.typography.textAlign`.
Nothing announced that in a way a pattern author would see. Markup written the
old way still parses, still renders, and is quietly a deprecated form.

## The pass

Work against a site running the new release — a `wp-env` instance is enough.
Everything below is the same tooling the guides prescribe, pointed at the
documents instead of at a pattern.

### 1. What blocks exist now

```bash
curl -u "$WP_USER:$WP_APP_PASSWORD" -G --data-urlencode 'input[namespace]=core' \
  "$WP_URL/?rest_route=/wp-abilities/v1/abilities/pattern-builder/list-block-types/run" \
  | python3 -c 'import json,sys; [print(b["name"]) for b in json.load(sys.stdin)["blocks"]]' \
  | sort > /tmp/registered.txt

grep -o 'core/[a-z0-9-]*' block-vocabulary.md | sort -u > /tmp/documented.txt
comm -23 /tmp/registered.txt /tmp/documented.txt
```

What that prints is every core block the vocabulary does not name. Most will
be children of families already covered, or blocks no pattern would reach for.
Judgement, not a checklist: add the ones that answer a job an author actually
has, and leave the rest.

Cross-check the new names against the [core blocks
reference](https://developer.wordpress.org/block-editor/reference-guides/core-blocks/)
for what each is for and what may nest inside it. That page is generated from
`block.json`, so it is current with core and carries no markup — see
`block-vocabulary.md` §2 on which question to ask where.

### 2. Whether the markup contract still holds

This is the check that catches a relocation, and it cannot be done by reading.
Serialize a canonical instance of each block the table documents, with the
attributes the table claims, and confirm the classes it says are required
actually appear:

```js
import { loadWordPressBlocksFromUrls } from '../scripts/wp-core.mjs';
const core = await loadWordPressBlocksFromUrls( urls, { version } );
const { createBlock, serialize } = core.window.wp.blocks;

const expect = [
        [ 'core/heading',   { level: 2, fontSize: 'large' }, 'has-large-font-size' ],
        [ 'core/heading',   { level: 2, textAlign: 'center' }, 'has-text-align-center' ],
        [ 'core/paragraph', { backgroundColor: 'base' }, 'has-base-background-color' ],
        [ 'core/group',     { align: 'full' }, 'alignfull' ],
        // …one row per line of the attribute-to-class table
];

for ( const [ name, attrs, cls ] of expect ) {
        const html = serialize( [ createBlock( name, attrs ) ] );
        if ( ! html.includes( cls ) ) {
                console.log( 'MOVED:', name, JSON.stringify( attrs ), '->', cls, 'no longer written' );
                console.log( html );
        }
}
```

A `MOVED:` line means the table's row is wrong for this release. Find where the
value went — it is usually still in the serialized attributes under a new key —
and correct the row. If the old key still works but is deprecated, say so
rather than silently swapping it: patterns in the wild carry the old form.

### 3. Whether the structural rules still hold

Same idea, one level up. For each block in "Structural requirements per
block", serialize a plain instance and read what comes out:

```js
for ( const name of [ 'core/group', 'core/columns', 'core/buttons', 'core/image' ] ) {
        console.log( name, '\n', serialize( [ createBlock( name ) ] ), '\n' );
}
```

Compare against what the document says is required. A block that has changed
what `save()` writes is rare, but when it happens the documented shape becomes
INVALID rather than merely stale, so it is worth the minute.

### 3b. Whether the documents' own examples still pass

The examples in these guides are markup, and markup goes stale the same way
a pattern does. Pull every fenced block that carries a `<!-- wp:` out of the
documents and run the validator over it — which is how the worked example in
`block-markup.md` was once found carrying a deprecated alignment spelling on
the same page that said the spelling had moved:

```bash
python3 - <<'EOF'
import re, glob, os
out = '/tmp/guide-examples'; os.makedirs(out, exist_ok=True)
for f in glob.glob('guides/**/*.md', recursive=True):
    for i, m in enumerate(re.finditer(r'```(html|php)\n(.*?)```', open(f).read(), re.S)):
        if '<!-- wp:' in m.group(2):
            open(f'{out}/{os.path.basename(f)[:-3]}-{i}.{m.group(1)}', 'w').write(m.group(2))
EOF
node guides/pattern-author/scripts/validate-pattern.mjs --wp /path/to/wordpress /tmp/guide-examples/*
```

A fragment written to illustrate one line (an unclosed group, a `…`) reports
as INVALID and is fine; anything else the validator names is a claim in the
prose that the block library no longer agrees with. Fix the example and the
sentence beside it together.

### 4. Run the evals

`evals/evals.json` carries three prompt-level cases that exercise the skill end
to end. They are the check on whether the *guidance* still produces good
patterns, which none of the above can tell you. Run them with the
`skill-creator` tooling after any substantive edit.

### 5. The service's allowlist

`block-vocabulary.md` §1 describes what patternbuilderwp.com accepts, which is
maintained in the service repository rather than here. When that changes, the
decision log entry says so — `docs/decisions.md`, the D39–D42 range at the time
of writing — and the authority is `Pattern_Validator::allowed_blocks()`. Read
it rather than transcribing the log, which records what was decided and not
necessarily what the code settled on.

## What not to do

**Do not turn `block-markup.md` into a catalogue of every core block.** It
covers the families patterns are mostly made of, and that scope is deliberate:
a complete list would be wrong within two releases and would compete with the
one source that is right by construction, which is the block library itself.
Where a block falls outside the table, the answer is to generate its markup,
not to document it here.

**Do not date the prose.** "New in 6.9" ages badly and invites a reader to
reason about versions the guide cannot know. Say what a block does and let
`list-block-types` answer whether this site has it.
