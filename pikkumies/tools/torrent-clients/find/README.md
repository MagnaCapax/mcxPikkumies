# find

Finds one torrent -- by info-hash, exact name, or a few words -- without the agent having to know in advance which client, or which box, has it. Every other torrent-client verb (`show`, `pause`, `remove`, ...) needs a client and an id; `find` is how those are discovered first.

## What it searches

- **Local**: every torrent-client unit this account actually has, discovered by directory (any `tools/torrent-clients/<name>/<name>.php` next to this unit, except `lib`), run concurrently -- `rtorrent`, `qbittorrent`, `deluge`, and `transmission` once it exists. A unit this account does not have (its own `list` answers "... is not installed ...") is left out of the results without comment; one that is installed but not running, not enabled, or too slow to answer inside the timeout is named in the status line (`issues`, `busy`) instead of silently vanishing.
- **Remote**: with `--service <nick>` or `--all`, the configured services (`pikkumies/tools/seedbox/services`) too, through the shared Services contract: `Services::fanout` runs the services concurrently, and for each one's configured clients, `Services::jsonl($nick, ['tool', "torrent-clients/<client>", 'list'])` asks the same `list` verb over the forced SSH command. A service's own records already carry `"service": <nick>`.

Presence and the reason a client cannot be reached are never re-detected here: `find` only reads the same `list` answer every other tool already gives, the same way `show`, `pause`, and the rest already read it.

## Matching

- A query that is a lower-case 40-character hex string is an info-hash: compared only against each candidate's `id`, exactly. It never falls back to a name search (a name that happens to look like a hex string is not a hash).
- Any other query is matched against the name: an exact (case-insensitive) match scores 1.0, a substring match 0.8, and a word-token match (how many of the query's words appear in the name, out of how many the query has) a smaller score that is always below a substring match's. A query that shares no word with the name is not a candidate at all -- `find` never returns a torrent that has nothing in common with what was asked for.

## Deciding there is "one" torrent

`unique` in the last line is true only for exactly one info-hash match, or exactly one exact-name match. A lone substring or word-token match is never called unique on its own: a short or generic query can easily have one weak match and no strong one, and that is exactly the case that needs a size/files/tracker comparison, not an auto-pick. See `skill.md` for the comparison-before-asking procedure this is built for.

## Limits

- `--limit` behaves like every other unit's `list` (`lib/Cli.php`): it only truncates how many candidate records are printed, never which ones are considered for `unique`/`ambiguous_reason` -- those are always computed over the full set first.
- A client's whole answer is read into memory before it is used; this is `list` output (one torrent client's torrents), not a file transfer, so that stays small in practice.

## Untrusted fields and output budget

`name`, `path`, `label` and `message` are the client's own text -- a torrent's name, where it saved the data, a tracker or error message -- carried through unparsed; the status line's `untrusted` field names exactly these so an agent never mistakes a crafted torrent name for an instruction. `issues` and `busy` sit in the status line, which (unlike the candidate records above it) `lib/Cli.php` never truncates to its own output budget on its own; `find.php` bounds them itself -- `issues` to 10 entries of at most 400 bytes each, `busy` to 20 -- so a `--all` fan-out across many remote services that all fail the same way cannot overrun it.

## A stuck client cannot stall `find`

A client past its own timeout gets SIGTERM, then up to `FindLocal::GRACE_SEC` (2 seconds) to exit on its own -- polled, never a blocking wait -- and then SIGKILL to its whole process group. Each client runs under `setsid` (the way `lib/ToolProc.php` and `tools/backup/remote/RemotePipe.php` already detach a child) so it is its own session and process group leader: a client that ignores SIGTERM, or shells out to something stuck in a way SIGTERM cannot reach, is still gone well inside the grace window, and `find` never calls the blocking `proc_close()` before that is confirmed.
