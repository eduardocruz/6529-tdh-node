# 6529-tdh-node

An independent TDH provider for the 6529 network: a second, from-scratch
implementation of the TDH calculation.

> **Not to be confused with** [`6529node`](https://github.com/6529-Collections/6529node)
> (the Go networking node) or with the TDH worker inside the 6529 core backend.
> This repository implements **only** the TDH accounting layer — the same role the
> [6529-Prenode](https://github.com/6529-Collections/6529-Prenode) fills today.

---

## Why a second implementation

A calculation that exists in exactly one codebase is an implementation. A
calculation that two independent codebases agree on is a **protocol**.

TDH (Time-weighted Days Held) is the scoring primitive behind a great deal of the
6529 ecosystem, and today every number anyone sees is produced by one program.
There is no independent way to answer "is this number right?" — only "is this
number the same as the one 6529 published?", which is the same question asked twice.

The 6529 team named this problem themselves. The first and most important purpose
they gave the Prenode was:

> "The immediate use of the Prenode is to enable multiple parties to independently
> serve as TDH providers to the on-chain TDH oracle contract that will be launching
> soon™. This will allow for on-chain composable TDH that is not dependent on any
> single party."
>
> — [6529-Prenode README](https://github.com/6529-Collections/6529-Prenode)

Independence only means something if the implementations are actually independent.
A second copy of the same code, run by a different person, is not a second witness.

## Why the name

"Prenode" was always explicitly temporary. From the same README:

> "You should assume that there will be further updates to the Prenodes and, later,
> to Nodes. **The Prenode name will remain until the code is restructured to run in
> any computer environment.**"

That is precisely the goal here: run in any computer environment. This project is
not a fork and not a rename of the Prenode — it is a separate implementation aimed
at the outcome that sentence describes. `6529-tdh-node` says what the thing does.

## Conformance

### The target is production, not the Prenode source

**Conformance is measured against `api.6529.io`, never against the Prenode
codebase.** The Prenode has not been updated since July 2025 and its TDH math has
since diverged from production. Faithfully reimplementing the Prenode would produce
an implementation that is faithful and wrong.

The Prenode source is a reference for *structure*. Production is the oracle for
*values*.

### The pass/fail criterion is a single hash

Every response from the `/oracle/*` API carries the block it was computed at and a
Merkle root:

```json
{ "count": 10239,
  "block": 25663469,
  "merkle_root": "0xdc652a6922d3b60c99201c28f33340d1966023f8ff27a10a9f8413e037eea2f9" }
```

The root is **global for the block**, not a digest of whatever rows a given query
returned — `/tdh/above/0` and `/tdh/above/1000` return different counts and the
same root. It covers every identity holding a positive TDH at that block.

Two implementations conform when they produce **the same `merkle_root` at the same
`block`**. No row-by-row diff, no judgement call, no "close enough". One hash
decides.

## Conformance

The root is only a test if an outsider can reproduce it. This is that check,
running against the live API:

```
php conformance/verify.php                    # against api.6529.io
php conformance/verify.php --endpoint=URL     # against any TDH provider
php conformance/run-vectors.php               # offline, against frozen vectors
```

`verify.php` fetches a provider's own entries, rebuilds the tree from scratch and
compares. It exits `0` when the published root reproduces, `1` when it does not,
`2` when the data could not be read — so it works as a CI gate, not just as
something to read. It targets one endpoint you name; it does not crawl the prenode
registry or publish scoreboards of other people's nodes.

### How the root is built

Recovered from production's `src/tdhLoop/tdh_merkle.ts` and confirmed against the
live API. It is not documented anywhere upstream, and three of these steps are
impossible to guess:

1. Take every identity with `tdh > 0` at the block. Note the API's `/above/0` route
   filters `>= 0`, so **it serves rows the tree never saw** — 71 of them at block
   25663469.
2. Sort by `tdh` **descending**, then `consolidation_key` **ascending**. The
   secondary key is load-bearing: thousands of identities tie on `tdh`, and the
   API's own response order does *not* reproduce the root.
3. Leaf = `sha256("<consolidation_key>:<tdh>")`, lowercase hex.
4. Parent = `sha256(left . right)` over the two children as hex **strings**, not
   bytes.
5. On an odd level the last node is **promoted** unchanged, not duplicated.
6. Prefix the result with `0x`.

`conformance/vectors/` holds golden vectors in the sense borrowed from
cryptography: known inputs and the exact output any correct implementation must
produce. They are JSON rather than test code, so an implementation in any language
can be checked against the same files. The synthetic cases pin the tree's shape;
the frozen mainnet snapshot carries the root 6529's production actually published,
which is the one expectation in this repository that nothing here authored.

### Surface

Ten read-only `GET` routes, served with `Access-Control-Allow-Origin: *`, which
means a static frontend can consume a node directly from the browser with no
backend of its own:

```
/oracle/tdh/total                       /oracle/tdh/above/:value/:extra?
/oracle/tdh/percentile/:value           /oracle/tdh/cutoff/:value
/oracle/address/:address                /oracle/address/:address/breakdown
/oracle/address/:address/memes_seasons/:season?
/oracle/address/:address/:contract/:id
/oracle/nfts/memes_seasons/:season?     /oracle/nfts/:contract?/:id?
```

## One thing worth knowing up front

Despite the name, **TDH is not an accumulator.** It is a pure function of current
ownership state evaluated over the full transfer history, recomputed from scratch
on a schedule. Nothing is banked; yesterday's TDH is not an input to today's.

This matters because the name suggests the opposite, and an implementer who assumes
an accumulator will diverge from production in several independent places — wallet
consolidation rewrites token dates retroactively, edition sizes and the HODL index
change after the fact, set-completion boosts apply to history made without them,
and sale detection can reclassify an old transfer.

The specification will open with this, because it is the single most common source
of confusion about TDH and it is nowhere in the existing documentation.

## Scope

**In scope**

- The TDH calculation: ownership derivation, holding periods, HODL rate, boosts,
  ranks, Merkle root
- Wallet grouping via the on-chain `nftdelegation` contract (consolidation and
  delegation) — this part is derivable from chain state alone
- The ten `/oracle/*` routes
- Running on modest, self-hosted infrastructure, with no dependency on any
  particular hosting provider

**Out of scope, at least for now**

- Human-readable identity — handles, profile pictures, levels, rep, CIC. These live
  in 6529's own database and cannot be derived from the chain. If this node ever
  serves them, they will be labelled with their source rather than presented as
  independently computed.
- P2P networking, mempool, RPC-between-nodes. That is what `6529node` is for.
- Waves, drops, subscriptions, minting, and the rest of the 6529 application.

## Status

**The conformance harness works. The node does not exist yet.**

1. ~~**Conformance harness**~~ — done. Runs in CI daily against `api.6529.io`, and
   offline against frozen vectors.
2. **Specification** — next. What TDH actually is, including the parts that exist
   only in code today: that it is a function of current state rather than an
   accumulator, where identity stops being derivable from the chain, and the
   token-date accounting.
3. **Implementation** — build to the spec, judged by the harness.

The harness came first, which inverts the order this README originally announced.
The reason is that it worked: the Merkle construction was the hardest part of the
protocol to specify from the outside, and reproducing the root turned it from
inference into measured fact. The specification is now partly a byproduct of the
harness rather than a prerequisite for it.

It is also useful on its own, before this node computes anything — it answers a
question nobody could previously answer, which is whether a given TDH provider is
serving numbers that match the root it publishes.

## Requirements

PHP 8.5. Nothing else — the harness uses only core hashing, JSON and HTTP. There is
no `composer.json` yet and no `vendor/`; anyone with `php` on their path can run it.

## License

MIT.
