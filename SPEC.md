# TDH — a specification

**Version 0.1 — 2 August 2026.** Complete enough to build from, and not yet validated
by anything having been built. Nothing here is authoritative in the sense of being
blessed by 6529; this is one attempt to write down what their production code does, in
a form another implementation can be built and checked against.

Two things would make it 1.0, and neither is more writing: **an implementation that
reproduces a published Merkle root using only this document**, which is the only real
test of a specification, and **a reading by someone who did not write it** — ideally
from the 6529 team, on the points marked `[stated]` and `[open]`.

Every claim carries a marker:

| | meaning |
|---|---|
| **[code]** | read in `6529seize-backend` production source, path given |
| **[live]** | confirmed against `api.6529.io` on the date given |
| **[stated]** | said by a member of the 6529 team in a public channel; not confirmed in code |
| **[open]** | not established — written down so it is not mistaken for settled |

No implementation language is assumed anywhere in this document. Where an operation
could be read two ways, the intent is that two implementations in two languages
produce byte-identical output.

---

## 0. The smallest thing worth building first

This document is longer than the first implementation needs to be. A spike that
proves the whole approach needs **five of the eight sections**, and produces exactly
one output: a Merkle root for a block, to compare against the one production
published for that same block.

**Needed for a first spike**

| | why |
|---|---|
| §1 — block selection | you must resolve the calculation instant to the same block |
| §2 — contracts, ingestion | the three addresses and how to build the transfer set |
| §4.1, §4.2, §4.3, §4.5 | days held, hold rate, boost, composition |
| §5 — the Merkle commitment | the output, and the only thing being compared |
| §8 — the reference range | replay to block 25663469, whose correct root is known |

**Not needed, and safe to skip entirely**

| | why |
|---|---|
| §3 — identity enrichment | handles and profiles are not derivable and not in the root |
| §4.4 — sale detection | the calculation does not implement it; neither should you |
| §4.6 — ranks | not in the tree; a rank mismatch is not a conformance failure |
| §6 — the ten routes | a spike needs no HTTP surface at all — print the root |

### Smaller still: one wallet, no consolidation

Even the list above is more than the first useful milestone. **Consolidation can be
left out entirely**, and the result is still exactly checkable against production.

For an address that belongs to no consolidation, its `consolidation_key` is the
address itself and its TDH is its own. **94.2% of identities are in that position** —
9,644 of the 10,239 in the tree at block 25663469; only 595 group two or three
wallets. **[live]**

So the smallest thing that proves the calculation works is:

> **Take one address. Compute its TDH from chain data. Compare against
> `GET /oracle/address/:address`.** Equal or not equal.

It is a single number, against a public endpoint, for any of thousands of addresses.
When `addresses` in that response contains only the address you asked for, no
consolidation is involved and the comparison is direct. **[live]** verified against
`0xde112b77…c351`, whose response lists exactly one address.

The token-date rules of §4.1 simplify accordingly: with a group of one, inbound
transfers always create new dates and outbound always removes them, most recent
first. The carry-over branch never fires.

### Choosing something to test against

Do not start against an arbitrary identity. Pick one whose shape removes every rule
you have not implemented yet, so a mismatch means one thing rather than five.

**What to look for**, in rough order of how much it simplifies:

| property | why it helps | how to check |
|---|---|---|
| not consolidated | no wallet grouping, no date carry-over | `addresses` in `/oracle/address/:address` has exactly one entry |
| **no outbound transfers** | the LIFO removal path of §4.1 never runs | no row where `from_address` is the address itself |
| few transactions | the whole history fits on one screen | the `count` on the transactions query |
| no complete season set | boost stays at 1, §4.3 is out of scope | `boost` is `1` in the same response |

An address holding a single card, acquired once and never moved, exercises the entire
chain — read the transfer, resolve the instant to a block, count whole days, apply the
hold rate, compose — while needing none of consolidation, removal, or boost.

**Finding one is a short filter, not a hunt.** Both endpoints are public and need no key:

1. `GET /oracle/tdh/above/0/entries` → every identity, with its `consolidation_key`.
2. Keep the keys containing **no `-`**. Those are single-wallet identities; there are
   thousands, and they are the overwhelming majority.
3. For a candidate, `GET /api/transactions?wallet=<address>&page_size=200` → its full
   transfer history in the shape of §2.
4. Discard it if any row has `from_address` equal to the address (it has sold or moved
   something), or if `count` exceeds one page. Keep the ones with a handful of rows.
5. Confirm with `GET /oracle/address/<address>`: `addresses` should list only that
   address, and `boost` should be `1`.

Start from the low-TDH end of the entries list — a small TDH usually means few cards
held for a short time, which is exactly the short history you want to trace by hand.

**Read the expected value at the moment you compare it. Never hardcode one.**

Two independent reasons, and both will bite:

1. **TDH grows every day.** The same address holding the same card is worth more
   tomorrow — by exactly its hold rate. A number that was right on Monday is wrong on
   Tuesday, and that is the model in §1 working correctly, not a defect.
2. **The address belongs to someone.** They can sell, buy or consolidate between two
   of your runs, with no warning. A test that suddenly fails may be reporting their
   activity rather than your bug — so re-check the shape (still one address in the
   list? still no outbound rows?) before assuming the implementation broke.

The comparison to make is therefore always: fetch the transfers, compute, fetch
`/oracle/address/:address` **at the same block**, and check equality. The arithmetic
to expect is just §4.5 with a group of one:

```
tdh = round( hold_rate × whole_days_held )      boost 1, single card, never moved
```

*Later, this gets easier: a wallet created for the purpose, holding a fixed set of
cards and never touched, would be a stable reference nobody else can move. Worth doing
once the implementation is real; for now, arbitrary addresses plus a fresh read are
enough.*

A natural ladder falls out of this, each rung independently verifiable:

| | milestone | checked against |
|---|---|---|
| 1 | one unconsolidated address | `/oracle/address/:address` — 94% of identities |
| 2 | consolidation | the same route, for the remaining 6% |
| 3 | every identity, hashed | the Merkle root — full conformance |

Only rung 3 needs consolidation to be right, because the root covers everyone.

So the first milestone is not a node. It is a program that reads the chain, computes,
prints one number, and is either right or wrong. Everything in §6 and §3 is packaging
that comes after the number is correct.

## 1. The model

**TDH is not an accumulator.** Despite the name — Time-weighted Days Held — nothing
is banked. It is a **pure function of current ownership state evaluated over the
complete transfer history**, recomputed from scratch on a schedule. Yesterday's TDH
is not an input to today's. **[code]** `src/tdhLoop/index.ts`, `tdh.ts`

This is the single most consequential sentence in this document. An implementer who
assumes an accumulator will produce something that tracks production for a while and
then diverges, in at least four independent ways:

1. **Consolidation rewrites history.** Grouping wallets retroactively changes the
   holding dates of tokens already held. **[code]** `getTokenDatesFromConsolidation`
2. **Edition sizes change after the fact**, and the HODL index derives from the set
   of all editions — so minting a new card reprices the entire collection. **[code]**
   `tdh.ts` — `MEMES_HODL_INDEX` is the maximum over all calculation edition sizes
3. **Boosts multiply history made without them.** Completing a set today changes the
   value of days held before you completed it. **[code]** `calculateBoost`
4. **A priced transfer may reclassify an old holding** — publicly stated by the team,
   and not found in the code. See §4.4. **[stated]** **[open]**

A correct implementation may cache and may recompute incrementally, but only if it
can reproduce the from-scratch result exactly. Any incremental design must therefore
checkpoint the **configuration** in force (consolidation groupings, edition sizes,
the HODL index) and not only the resulting state — because all three are inputs that
change retroactively.

### Schedule

Production recomputes at **00:00 UTC** and the result is addressed by an Ethereum
block number. **[code]** `getLastTDH()` returns today at 00:00:00 UTC, or yesterday
if that instant is in the future.

Consequences visible to users, all downstream of this and all reported as confusing
in the public archive: a card must be held for more than 24 hours before it
contributes; a set-completion boost appears the day after completion; TDH can fall.

### Block selection

The calculation instant is a timestamp; the result is addressed by a block. The
mapping between them is **deterministic**, which is what makes conformance possible
at all — two implementations that agree on the rule choose the same block without
coordinating.

> **The block is the one with the greatest timestamp less than or equal to the
> calculation instant.** **[code]** `findLatestBlockBeforeTimestamp` in `tdh.ts`

Production finds it by binary search over block timestamps, seeded by an estimate at
12 seconds per block. The search is an optimisation; the rule above is the
specification. An implementation may find that block any way it likes.

Note this is a property worth checking rather than assuming: had the block instead
been "whatever the indexer had ingested when the job ran", two correct
implementations would pick different blocks and could never agree at the same
instant. It is not — the target timestamp fully determines the block.

Transactions are then filtered `block <= target`. **[code]** `updateTDH`

---

## 2. Inputs — what is read from the chain

Chain: **Ethereum mainnet**.

### The three contracts

TDH is computed over exactly three contracts. **[code]** `tdhContracts` in
`updateTDH`

| collection | address | standard |
|---|---|---|
| **The Memes** | `0x33FD426905F149f8376e227d0C9D3340AaD17aF1` | ERC-1155 |
| **6529 Gradient** | `0x0c58ef43ff3032005e472cb5709f8908acb00205` | ERC-721 |
| **NextGen Core** | `0x45882f9bc325E14FBb298a1Df930C43a874B83ae` | ERC-721 |

**Meme Lab (`0x4db52a61dc491e15a2f78f5ac001c14ffe3568cb`) is not included.** It is a
6529 collection and it does not contribute to TDH. An implementation that indexes it
will produce numbers that do not reconcile. **[code]** absent from `tdhContracts`
· **[live]** `GET /oracle/nfts/0x4db52a61…` returns an empty list, while the three
above return their tokens with TDH — checked 2026-08-02

NextGen resolves per network; the address above is mainnet. **[code]**
`NEXTGEN_CORE_CONTRACT`

### Burn addresses

Transfers to either are burns, not holdings: **[code]** `constants/index.ts`

```
0x0000000000000000000000000000000000000000
0x000000000000000000000000000000000000dEaD
```

### Edition sizes

Meme edition sizes derive from Manifold claim data, not from a raw supply count —
see §4.2 for the floor that is applied. The Manifold contract is
`0x3A3548e060Be10c2614d0a4Cb0c03CC9093fD799`. **[code]**

**One hardcoded correction exists.** Meme card #8 carries an adjustment of
**−2588** editions, tied to a specific burn transaction
`0xa6c27335d3c4f87064a938e987e36525885cc3d136ebb726f4c5d374c0d2d854`. **[code]**
`MEME_8_EDITION_BURN_ADJUSTMENT`

This is a historical fact about one card baked into the constants, not a rule that
generalises. An implementation must reproduce it to match, and cannot derive it.

### Building the transaction set

The calculation consumes a table of transfers. Producing it is the first thing an
implementation must do, and nothing about it is exotic. **[code]**
`transactions-discovery.service.ts`

Read **ERC-721 and ERC-1155 transfer events** for the three contracts, in ascending
block order, from each contract's first block up to the target block. Production uses
Alchemy's `getAssetTransfers` with `category: [ERC721, ERC1155]` filtered by
`contractAddresses`; any equivalent source works, including plain `eth_getLogs`
against the two `Transfer` topics. The provider is an implementation detail.

**For a spike, the chain can wait.** 6529 serves its own indexed transfers publicly:
`GET https://api.6529.io/api/transactions?wallet=<address>` returns exactly the rows
described below, already decoded and paginated. A first implementation can build
against that, get the arithmetic right, and only then replace the source with a real
chain read — which is required for independence, but is not what the first milestone
is testing. **[live]** 2026-08-02

One row per `(transaction, from_address, to_address, contract, token_id)`, carrying:

| field | note |
|---|---|
| `transaction` | tx hash |
| `block`, `transaction_date` | the block's number and UTC timestamp |
| `from_address`, `to_address` | lowercased |
| `contract`, `token_id` | |
| `token_count` | units moved — always 1 for ERC-721; the amount for ERC-1155 |

An ERC-1155 batch transfer expands to one row per token id. Rows are de-duplicated on
that same five-part key. **[code]** `consolidateTransactions`

**Price is recorded but not used.** Production also stores `value`, `primary_proceeds`
and `royalties` per row, resolved by a separate 1,719-line module. The TDH calculation
never reads them — see §4.4. An implementation targeting conformance does not need to
compute transaction values at all.

### Consolidation size

A consolidation holds **at most 3 wallets**. **[code]** `CONSOLIDATIONS_LIMIT`

---

## 3. Identity

Identity in this system is in two halves, and only one of them is derivable from
public data. An implementation should be explicit about which half it is serving.

**Derivable — wallet grouping.** Consolidation and delegation are recorded in the
on-chain `nftdelegation` contract. Registration is two-way: both wallets must
register for the grouping to take effect. Any node can derive this from chain state
alone. **[code]** Prenode and production both do it

**Not derivable — the human-readable layer.** Handles, profile pictures, level, rep
and CIC live in 6529's own database. There is no on-chain source. **[code]** absence
in both trees; **[live]** they are served only from their API

`GET /oracle/address/:address` already resolves an address to its whole
consolidation and returns every address in the group. **[live]** 2026-08-02

An implementation that enriches responses with the human-readable layer **must label
its source** rather than present it as independently computed. Suggested shape:

```json
{ "addresses": ["0x…", "0x…"],
  "handle": "eduardocruz",
  "handle_source": "api.6529.io" }
```

---

## 4. The calculation

### 4.1 Days held

For each token held, the contribution is the number of whole days between the
token's date for that owner and the calculation instant. **[code]** `getDaysDiff`

**Token dates use LIFO accounting.** Where an owner holds several units of the same
ERC-1155 id and disposes of some, the units removed are the **most recently
acquired** — the owner keeps the oldest dates. **[code]** `removeDates` splices from
the end of the array.

This is undocumented anywhere upstream and it is not neutral: LIFO maximises
reported TDH relative to FIFO. It exists because ERC-1155 units have no individual
identity, so some convention was required; this is the convention.

### 4.2 Hold rate

```
hodlRate(nft) = MEMES_HODL_INDEX / calculationEditionSize(nft)      # Memes
              = nft.hodl_rate                                        # other contracts
```

`MEMES_HODL_INDEX` is the **maximum** calculation edition size across all Meme
cards. **[code]** `tdh.ts` — `reduce((acc, size) => Math.max(acc, size), 0)`

`calculationEditionSize` is **not** raw supply: it is supply floored at the Manifold
`totalMax`, capped at 310. **[code]** `memes-edition-size-floor`

For **Gradients and NextGen** the rate is not derived in this loop at all: it is read
from the NFT record as `nft.hodl_rate`, computed when the NFT was indexed. An
implementation must reproduce those stored values, not recompute them from the Memes
formula. **[code]** `getTokenTdh` call site

**The rate is rounded to two decimals before it is used**, not after:
`hodlRate = round(hodlRate * 100) / 100`. **[code]** `getTokenTdh`

> **This is where the Prenode diverged.** The Prenode computes
> `HODL_INDEX / nft.edition_size` — raw supply, and an index taken across Memes,
> Gradients and NextGen combined. Any card whose supply sits below its floor is
> valued higher there than in production. An implementation targeting production
> must use the floored form. **[code]** diff of the two `tdhLoop` trees, 2026-08-02

### 4.3 Boost

Boost starts at `1` and is **rounded to two decimals at the end**. Intermediate
values round to six decimals. **[code]** `calculateBoost`, `roundBoostValue`

**The seasons that count exclude the current one.** Boostable seasons are those with
`id < max(id)` and `boost > 0` — the season in progress never contributes.
**[code]** `getBoostableSeasons`

**Two mutually exclusive categories.** This is the part most likely to be
implemented wrongly, because it reads as additive and is not: **[code]**
`calculateMemesBoosts`

- **Category A — holds at least one complete collection set** (`cardSets > 0`):
  ```
  fullSetBoost   = sum of boost over all boostable seasons        (rounded to 6dp)
  additional     = 0.05 * (1 - 0.6529^n) / (1 - 0.6529)           # n = cardSets - 1
  boost          = 1 + round6(fullSetBoost + additional)
  ```
  A geometric series, so additional sets converge to a limit of
  `0.05 / (1 - 0.6529)` rather than growing without bound.
  **Season boosts are not applied in this category.**

- **Category B — no complete collection set** (`cardSets == 0`):
  `+ season.boost` for each season whose set is complete. Season 1 is special: if its
  set is *not* complete, `+0.01` for holding the Genesis Set (cards #1, #2, #3) and
  `+0.01` for holding NakamotoFreedom (card #4).

**Gradients**, in both categories: `min(count * 0.02, 0.10)`.

Constants: `ADDITIONAL_CARD_SET_BOOST = 0.05`, `ADDITIONAL_CARD_SET_RATIO = 0.6529`,
per-season boost `0.05`. **[code]**

> **Boost has no dedicated vectors, and does not need them to be verified.** The
> Merkle root already covers it transitively: boost feeds `boosted_tdh`, which is the
> value hashed into every leaf. An implementation with a wrong boost produces a wrong
> root and fails conformance immediately.
>
> Dedicated vectors would still help *localise* a failure rather than merely detect
> it. Upstream has a real test to port —
> [`src/tests/calculate-boost.test.ts`](https://github.com/6529-Collections/6529seize-backend/blob/c8287bd527fa49a16df0700b7b03f8be5145e1de/src/tests/calculate-boost.test.ts),
> 359 lines carrying the twelve seasons' actual card ranges, present in both the
> backend and the Prenode. Worth doing; not a prerequisite for building.

### 4.4 Sale detection

**Do not implement it.** This is the one instruction in this document that tells an
implementer to leave something out, so it is worth being precise about why.

A 6529 team member stated publicly in April 2026 that a transfer carrying a price is
treated as a sale and resets TDH even between wallets inside the same consolidation.
**[stated]**

**No such rule exists in the calculation.** Price is available — every transaction row
carries `value`, `primary_proceeds` and `royalties` — and the calculation never reads
any of them. Verified by searching all seven modules of `src/tdhLoop/` for those
fields, in **both** production and the Prenode:

```
tdh.ts  tdh_consolidation.ts  index.ts  tdh_merkle.ts
tdh_nft.ts  tdh_memes.ts  tdh_editions.ts          →  0 occurrences, both repos
```

So the rule an implementation must follow is the one in the code, and it is simple —
this is what `getTokenDatesFromConsolidation` does: **[code]**

- **inbound from outside the consolidation** → the token gets a *new* date, the
  transaction date. The clock restarts.
- **inbound from inside the consolidation** → the existing dates are *carried over*
  from the sending wallet. The clock is preserved, whatever was paid.
- **outbound to outside** → dates are removed, most recent first (see §4.1).

An implementation that adds sale detection will produce a different root and fail
conformance.

**[open]** The contradiction between the statement and the code is left unresolved
here rather than explained away. The remaining possibility not ruled out is that the
rule once existed and was removed. It is worth asking the team directly — it is the
only place in this document where public description and running code disagree.

### 4.5 Composition

```
tdh_raw(token) = sum of whole days held, per edition, counting only days > 0
tdh(token)     = round( round(hodlRate*100)/100 * tdh_raw * 1000 ) / 1000, then rounded to an integer
boosted_tdh    = round(memes * boost) + round(gradients * boost) + round(nextgen * boost)
```

Two rounding details that will not forgive being simplified: the hold rate is rounded
to two decimals **before** multiplying, and the per-token result passes through three
decimals **before** being rounded to an integer. Rounding once at the end gives a
different number. **[code]** `getTokenTdh`

Note also that the boost is applied **per component before summing**, not once at the
end. **[code]** `tdh.ts`

### 4.6 Ranks

Ranks are dense positions in a sorted list — `index + 1`, with no shared positions for
ties. **[code]** `calculateRanks`

**Overall wallet rank** (`tdh_rank`) sorts by, in order: `boosted_tdh` desc, `tdh`
desc, `gradients_tdh` desc, `nextgen_tdh` desc.

**Per-collection ranks** (Gradients, NextGen) sort by that collection's `tdh` desc,
then by token `id` ascending.

**Per-card ranks** (`memes_ranks`) sort by that card's `tdh` desc, then by the
holder's `boosted_tdh` desc.

An implementation should reproduce these orderings, and should not treat a rank
mismatch on an exact tie as a conformance failure — conformance is decided by the
root, and ranks are not in the tree.

**[open] — the overall sort has no final tie-breaker.** Two wallets equal on all four
values fall through to a constant, so their relative order depends on the order rows
arrived from the database. This does **not** affect the Merkle root, which is sorted
by `(boosted_tdh desc, consolidation_key asc)` and is fully determined — but it does
mean two correct implementations can serve different `tdh_rank` values for a tie.
**[code]** the `else return -1` at the end of the comparator

---

## 5. The Merkle commitment

This section is **complete and verified**: the construction below was recovered from
source and reproduced from public data, matching the root production published for
block 25663469 on 2026-08-02. **[code]** `src/tdhLoop/tdh_merkle.ts` · **[live]**

1. Take every consolidation with `boosted_tdh > 0` at the block.
2. Sort by `boosted_tdh` **descending**, then `consolidation_key` **ascending**.
3. Leaf = `sha256("<consolidation_key>:<boosted_tdh>")`, lowercase hex.
4. Parent = `sha256(left || right)` over the two children **as hex strings**, not as
   decoded bytes.
5. On an odd level, the last node is **promoted unchanged** — not duplicated. (The
   comment in production source says "duplicate"; its code promotes. Follow the code.)
6. Prefix the final value with `0x`.

The root is **global for the block**. It is not a digest of whatever rows a query
returned: `/oracle/tdh/above/0` and `/oracle/tdh/above/1000` return different counts
and the same root. **[live]**

Note that `/oracle/tdh/above/0/entries` serves rows the tree does not contain: the
route filters `>= 0` while the tree is built over `> 0`. There were 71 such rows at
block 25663469. An implementation that hashes what that route returns, verbatim,
gets the wrong answer. **[live]**

Vectors: `conformance/vectors/tree-shape.json` (shape) and
`conformance/vectors/mainnet-25663469.json.gz` (end to end).

---

## 6. Surface

Ten read-only `GET` routes, served with `Access-Control-Allow-Origin: *`. Every
response carries `block` and `merkle_root`. **[live]** all ten checked 2026-08-02

```
/oracle/tdh/total                       /oracle/tdh/above/:value/:extra?
/oracle/tdh/percentile/:value           /oracle/tdh/cutoff/:value
/oracle/address/:address                /oracle/address/:address/breakdown
/oracle/address/:address/memes_seasons/:season?
/oracle/address/:address/:contract/:id
/oracle/nfts/memes_seasons/:season?     /oracle/nfts/:contract?/:id?
```

`:extra` on the `above` route takes the literal value `entries` to include the rows
rather than only the count. **[code]** `api.oracle.routes.ts`

---

## 7. Conformance

An implementation conforms when both hold:

**Correctness — identical, bit for bit.** At a given block it produces the same
`merkle_root` as production. This is not a tolerance; one hash decides. Every
implementation in every language must produce the same value or one of them is
wrong.

**Vectors — reproduced exactly.** Every case in `conformance/vectors/` produces its
`expected_root`.

Both are checked by `conformance/verify.php` and `conformance/run-vectors.php`. Those
happen to be written in PHP; nothing about the criteria is. A conforming
implementation in any language can be checked by the same vectors, and is encouraged
to ship its own runner.

---

## 8. Operational requirements

Correctness is an equality. Operational behaviour is a **bound** — implementations
will legitimately differ in speed, and are only required to clear the bar.

**The window.** Production recomputes at 00:00 UTC. An implementation must complete
a full recomputation and publish the new root **within the daily cycle**, on hardware
it declares. Missing the window is the failure mode that killed most of the existing
prenode fleet: a node that cannot finish never becomes correct again.

**The benchmark set.** Numbers from different implementations are only comparable if
they did the same work. As correctness is pinned by golden vectors, throughput must
be pinned by a **frozen block range** — a defined start and end block over the
tracked contracts, published in this repository, over which any implementation can
replay and report.

**A baseline exists and is observable from outside.** Production's own turnaround —
how long after 00:00 UTC its published `block` and `merkle_root` advance — can be
measured by polling the API, with no access to its infrastructure. Recording that
daily gives the only reference point anyone has for what the workload costs in
practice. **[open]** — proposed, not yet measured.

Any comparison drawn from it must be scoped honestly: production's daily loop
computes a great deal this specification does not cover, so wall-clock against wall-
clock is not a like-for-like measure. The frozen block range exists precisely so
that a fair comparison is possible — same work, same input, different
implementations.

**What to report**, so results can be compared rather than asserted:

- event count replayed, and the block range
- wall-clock for a full from-scratch recomputation
- peak memory
- hardware: cores, RAM, storage class
- whether the resulting root matched production for the end block

### The reference range

> **Genesis of each of the three contracts, through block 25663469.**

That end block is not arbitrary: it is the block frozen in
`conformance/vectors/mainnet-25663469.json.gz`, whose correct root is already known
and was published by production. So the benchmark run is also a correctness run — an
implementation replays the full history, reports what it cost, and the root it
arrives at either matches or does not. A fast wrong answer scores nothing.

The start is each contract's own first transfer rather than a fixed number, because
that is what "full history" means and it is what an implementation discovers on its
first sync. Report the event count so the runs are comparable.

**[open] — no measurement has been taken yet.** The first run is the first task of the
implementation phase, before anything is built on top. It may show the daily window is
comfortable on any reasonable runtime, in which case this section becomes a
formality — or it may not, in which case it should change the architecture rather than
be discovered late.

---

## Sources

Everything cited here is public and free to read without an account or a key.

### Production — the system this specification describes

**[`github.com/6529-Collections/6529seize-backend`](https://github.com/6529-Collections/6529seize-backend)**
· TypeScript · Apache-2.0 · in continuous use, commits most days.

Every **[code]** marker above refers to a path in this repository unless it says
otherwise. They are pinned to commit
[`c8287bd`](https://github.com/6529-Collections/6529seize-backend/tree/c8287bd527fa49a16df0700b7b03f8be5145e1de)
(2 August 2026), which is what was read. Links to `main` would rot as the file moves
underneath the quote; these will not.

| cited as | permalink |
|---|---|
| `src/tdhLoop/tdh.ts` — the calculation, 1,142 lines | [read it](https://github.com/6529-Collections/6529seize-backend/blob/c8287bd527fa49a16df0700b7b03f8be5145e1de/src/tdhLoop/tdh.ts) |
| `src/tdhLoop/tdh_merkle.ts` — the tree, 64 lines | [read it](https://github.com/6529-Collections/6529seize-backend/blob/c8287bd527fa49a16df0700b7b03f8be5145e1de/src/tdhLoop/tdh_merkle.ts) |
| `src/tdhLoop/tdh_consolidation.ts` — wallet grouping, token dates | [read it](https://github.com/6529-Collections/6529seize-backend/blob/c8287bd527fa49a16df0700b7b03f8be5145e1de/src/tdhLoop/tdh_consolidation.ts) |
| `src/tdhLoop/index.ts` — the daily loop, `getLastTDH()` | [read it](https://github.com/6529-Collections/6529seize-backend/blob/c8287bd527fa49a16df0700b7b03f8be5145e1de/src/tdhLoop/index.ts) |
| `src/api-serverless/src/oracle/api.oracle.routes.ts` — the ten routes | [read it](https://github.com/6529-Collections/6529seize-backend/blob/c8287bd527fa49a16df0700b7b03f8be5145e1de/src/api-serverless/src/oracle/api.oracle.routes.ts) |
| `src/api-serverless/src/oracle/api.oracle.db.ts` — what each route queries | [read it](https://github.com/6529-Collections/6529seize-backend/blob/c8287bd527fa49a16df0700b7b03f8be5145e1de/src/api-serverless/src/oracle/api.oracle.db.ts) |
| `src/tests/calculate-boost.test.ts` — their boost test, 359 lines | [read it](https://github.com/6529-Collections/6529seize-backend/blob/c8287bd527fa49a16df0700b7b03f8be5145e1de/src/tests/calculate-boost.test.ts) |

### The Prenode — cited only where it differs

**[`github.com/6529-Collections/6529-Prenode`](https://github.com/6529-Collections/6529-Prenode)**
· pinned to [`d60f5bd`](https://github.com/6529-Collections/6529-Prenode/tree/d60f5bda6410e6ce33d9218efd682ab2bab5ca08),
its last commit, 2 July 2025. It is 6529's own self-hostable TDH provider, and it is
**not** the target of this specification — see §4.2 for where it has drifted.

### The live API

`https://api.6529.io/oracle/*` — unauthenticated reads, served with
`Access-Control-Allow-Origin: *`. Every **[live]** marker was checked on 2026-08-02.
The registry of running providers is at
[6529.io/network/prenodes](https://6529.io/network/prenodes).

### Statements by the team

Anything marked **[stated]** comes from 6529's public channels on
[6529.io](https://6529.io), not from code, and is repeated here with that caveat
attached. It has not been confirmed against an implementation.

---

Corrections are the most useful thing you can send, particularly to anything marked
**[open]** or **[stated]**. Open an issue.
