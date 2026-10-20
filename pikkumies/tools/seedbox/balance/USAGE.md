# balance

| verb | arguments | changes | what it does |
|---|---|---|---|
| plan | `[--tag seedbox] [--goal free\|upload] [--max-moves N]` | no | Reads "local" (this account) and every registered service tagged `--tag` (default `seedbox`), then proposes a deterministic list of torrent moves: `--goal free` (default) levels out free disk space, moving the largest completed, least currently active torrent off the fullest box onto the emptiest; `--goal upload` spreads active seeding, moving the most actively seeding completed torrent off the busiest box onto the idlest. Stops once the two are within tolerance, `--max-moves` is reached, or no completed torrent can move without pushing its target over 90% of that box's own quota. Never moves anything. |

```sh
php balance.php plan                               # default: even out free space across "local" and every seedbox-tagged service
php balance.php plan --goal upload                  # spread active-seeding upload load instead
php balance.php plan --tag backup --max-moves 3     # only services tagged "backup"; at most 3 proposals
php balance.php plan --human                        # the same records, printed for a person
```

Each proposal is one record:

```json
{"move": 1, "from": "box1", "to": "box2", "client": "rtorrent", "id": "3f2a...", "name": "debian-13.7.0-amd64-netinst.iso",
 "size": 792723456, "size_human": "756.0 MiB", "reason": "box1 is 8.0% free, box2 is 61.0% free; moving the largest completed torrent with the lowest current upload rate to even out free space",
 "command": "move plan '3f2a...' --to 'box2' --from 'box1' --client 'rtorrent'"}
```

The final status line adds `goal`, `services_considered`, `services_usable`, `excluded` (service nick => why its quota could not be read), `moves_proposed` and `stopped_because`.

A service that cannot be reached, or whose quota cannot be read, is skipped and named in `excluded` or in a per-service note -- it never aborts the rest of the plan, and it is never guessed into the ranking.
