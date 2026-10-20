# Changing a pattern that already exists

The factor step in `SKILL.md` cuts a new design into parts. On a site that
already has patterns, the more common ask is different: something close to
what is wanted is already there. Two things then go wrong without looking
wrong. Edit the pattern, and every page that places it changes with it. Write a
near-copy, and the site has two designs to keep in step, which drift the first
time one of them changes.

Read this before writing a pattern on a site that has any, and before changing
one that pages use.

## Look before you write

Most sections an agent is asked for already exist on a site that has been
built for a while.

- `list-patterns` with `search` and `keywords`. Its `keywordsInUse` is the
  site's own vocabulary, so filter by those words rather than guessing at one.
- The patterns a plugin registered (`source: "plugin"`). One that fits is
  copied with `copy-plugin-pattern`, which brings what it places and records
  where it came from. It is never rebuilt by reading its markup.
- The cloud, when the site is connected: `search-cloud-patterns`.

Read the descriptions first, then the markup of the few that are left with
`get-pattern`.

## Then decide, in this order

- **Reuse it as it is**, when nothing needs to change. Place it and stop.
- **Add what it is missing**, when it is the same design with one difference
  of a kind a pattern can carry:
  - *words, an image or a link that differ per placement* become a slot,
    which needs the leaf to be one of the blocks Pattern Overrides binds
    (`design-content-split.md`);
  - *a look* becomes a block style variation, applied with a class, so the
    original keeps its own (`design-system.md`).
- **Copy it into a new pattern**, when the two need to diverge and the original
  has to keep looking as it does. Start from its markup with `get-pattern`
  rather than from nothing. Keep the slot names that mean the same thing, so
  content moves between the two without renaming, and share the look through
  tokens and variations rather than restating it.
- **Write a new one**, only when nothing is close.

The line between adding and copying is sharper here than it looks, because a
block pattern has no conditionals. A block that is present in one placement and
absent in another, or an arrangement that changes, cannot be an option of one
pattern: Pattern Overrides fills leaves and cannot add or remove a block. "The
same card without the photo" is therefore a copy, not a setting. A rough test
for the rest: most of the structure is shared and one leaf or one look differs.
Two signs it is really two designs are an option that only makes sense when
another is set a particular way, and a difference that swaps the arrangement
rather than one leaf.

## Know what a change costs

`list-pattern-usage` lists every place a pattern reaches, directly or through
another pattern, and which slots each direct place fills. That list is the
cost of the change. Read it before touching a synced pattern.

- **A change made for one new placement leaves every existing one as it is.**
  Add a slot, and its placeholder copy is what every reference that does not
  fill it will show, so the placeholder has to be the text the pattern shows
  today. Restyle through a new variation rather than the pattern's own
  attributes.
- **Slot names are a contract.** Renaming or removing a slot sends every page
  that filled it back to placeholder copy, with no error anywhere.
  `update-pattern` refuses that unless `dropSlots` names the slot, and the
  refusal names the places. Keep the names, or change the keys where they are
  filled first.
- **A change meant for every placement**, because the person asked for the
  pattern itself to change, is made the same way, and the answer says which
  places now show it.

## Change it minimally

Read the pattern with `get-pattern` and keep its `hash`. Change only what was
asked, and send the change as `edits` (fragments of the markup you read, each
replaced in place) rather than the whole pattern, with the hash as
`expectedHash`. A pattern that changed after you read it is refused rather than
overwritten; read it again and redo the change on what is there. Validate the
result as you would any markup. `changed: false` in the answer means the write
left the pattern exactly as it was, which is rarely what was meant. A
`description_unchanged` warning means the markup moved and the description did
not: check it still says what the pattern is for and roughly what is in it.

## When to ask

Decide and say what you decided, as everywhere else. The exception is a genuine
toss-up between changing a pattern in use and writing a new one, where either
answer is reasonable and the wrong one is expensive: a near-duplicate nobody
wanted, or a live page restyled by surprise. Ask then, and name the places
`list-pattern-usage` returned, because "this would also change these four
pages" is a question a person can answer at a glance.
