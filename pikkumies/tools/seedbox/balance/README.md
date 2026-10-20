# balance

Plans moves of completed torrents between the user's own seedboxes, to level out free disk space or
to spread upload load. It is read-only: it proposes, it never moves data, and every proposal carries
the exact command to act on it with the [move](../../torrent-clients/move/README.md) unit
(owner E, SPEC.md "Move orchestrator").

## Where the data comes from

`plan` reads "local" (this account) and every service registered under the Services registry (owner
A's unit, SPEC.md "Services list"; [ADR-0084](../../../../docs/adr/0084-services-list-and-moving-torrents-between-boxes.md))
that carries the `--tag` (default `seedbox`). For each one it reads:

- the account's own disk quota and 30-day upload traffic, the same record `system-status account` answers;
- every present torrent client's torrents, the same records `list` on each of
  [torrent-clients](../../torrent-clients/README.md) answers.

Local calls run the real unit script as a subprocess (`pikkumies/lib/ToolProc.php`, which `proc_open`s
a string built with `escapeshellarg` -- PHP 7.3's `proc_open` has no array form). Remote calls go
through the registry's `fanout` and `jsonl` helpers, which run the same two verbs over SSH against
each service's forced command. A service, or one client on a service, that cannot be reached is
skipped and recorded, never fatal: a plan is built from whatever answered.

If the Services registry is ever missing from a build, `plan` still works against "local" alone, and
says plainly that the registry is not available rather than guessing at remote boxes.

## The algorithm

Only completed torrents (progress 1.0) are ever candidates. Each iteration:

1. Ranks the services: by free-space ratio (`--goal free`, the default) or by total current upload
   rate (`--goal upload`).
2. Picks the worst-ranked service as the source and the best-ranked other service that has room as
   the target.
3. Picks, from the source's completed torrents, the largest one with the lowest current upload rate
   (`--goal free`) or the one with the highest current upload rate (`--goal upload`).
4. Proposes the move only if the target would stay at or under 90% of its own quota afterwards.

It stops once the services are within tolerance of each other, `--max-moves` is reached, or no
completed torrent can move without breaking the 90% rule. The working copy is updated after each
proposal (the torrent "moves" in the plan's own bookkeeping too), so a plan can chain several moves
in one call; this is a planning approximation, not a reservation -- running the proposals is still up
to the move unit, which re-checks the real state before it moves anything.

A torrent's name is carried through every record for display only. It is untrusted data (the
torrent's own metadata): this unit never reads it, matches against it, or branches on it.

## Limits

- A service whose quota cannot be read is excluded from ranking and listed in the plan's `excluded`
  field; it never silently becomes "full" or "empty".
- A box with no quota configured (unlimited) is never treated as "the fullest" (there is nothing to
  measure), but it is always a valid target.
- With very few, very large, very active torrents and only two services, the planner can end up
  proposing to move the same one back and forth without ever reaching "within tolerance" (there is
  nothing else to balance against). `--max-moves` is the way to bound a plan in that situation; the
  planner also stops on its own after a fixed number of iterations regardless.
