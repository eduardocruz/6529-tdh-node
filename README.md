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
Merkle root over the result set:

```json
{ "count": 10239,
  "block": 25663469,
  "merkle_root": "0xdc652a6922d3b60c99201c28f33340d1966023f8ff27a10a9f8413e037eea2f9" }
```

Two implementations conform when they produce **the same `merkle_root` at the same
`block`**. No row-by-row diff, no judgement call, no "close enough". One hash
decides, and anyone can check it with `curl`.

This repository treats that as the definition of correctness, and the conformance
harness that checks it is the first deliverable — before any of the calculation is
written.

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

**Nothing is implemented yet.** This repository currently contains this README.

The intended order is deliberate, and inverted from the obvious one:

1. **Specification** — write down what TDH actually is, including the parts that
   exist only in code today
2. **Conformance harness** — a test that can tell whether *any* implementation
   agrees with production, running in CI against live endpoints
3. **Implementation** — build to the spec, judged by the harness

The harness is useful on its own, before this node computes anything: it answers a
question nobody can currently answer, which is whether a given independent
implementation is producing correct numbers right now.

## License

MIT.
