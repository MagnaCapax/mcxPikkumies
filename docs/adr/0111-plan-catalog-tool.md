# ADR-0111: A read-only tool for Pulsed Media's plans, prices and stock

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-11-05
- Status: Accepted

## Context

Users ask their agent what a plan costs and whether it is on sale. Pikkumies had no tool for it: the agent could only quote documentation up to a day old or fetch the whole 95 KB public feed. A community request showed the gap.

## Options Considered

- A skill that runs curl and grep on the short feed: it has no billing cycles or specs.
- A documentation source: a copy a day old cannot answer stock.
- A verb on the kb tool: its verbs search downloaded pages and hold no live fetch.
- One tool unit reading the full feed and printing only what matches: chosen.

## Decision

- `tools/seedbox/plans/catalog` has `find` and `list`, fetches the feed once per run and keeps no cache and no older copy: a failure refuses and gives no figure.
- The status line carries the feed's age and that prices exclude VAT.
- Most plans have no specs block. Those show their own description; no filter on figures exists, so none can drop them.
- It orders nothing. A question to buy gets the plan's order link.
- The skill forbids comparing with the user's own service: their price can be an older one they keep.
- The tool is its own category, `seedbox/plans`, and is on only in an install that opted in with the file `local/catalog.enabled`. Without it `Generate::unavailableCategories` leaves the category out of the skills, the tool index and SOUL.md. Every account follows main while no release is tagged, so this tool ships off until its end-use tests pass. The opt-in is per install, not per host: the weekly QA asks its questions on a production server, and a host-wide switch there would turn the tool on for every account of that server. The question suite's sandbox creates the file for its own copy. The storage skill's "never state a price" is unchanged, because accounts without the catalog keep that rule.

## Consequences

- OpenClaw on the same account reaches the tool through the generated skill (ADR-0105).
- The rule on comparing is guidance to the model, not enforcement.
- `available` follows the store's own stock counter, so the order page has the final word.

## References

- [ADR-0015](0015-tool-units-one-skill-per-tool.md), [ADR-0105](0105-openclaw-skill.md)
