# antipattern-check

An interval hook unit ([ADR-0067](../../../../docs/adr/0067-interval-hook-units.md)) that checks the agent's own recent work against [doctrine/antipatterns.md](../../../doctrine/antipatterns.md), the list of common mistakes ([ADR-0063](../../../../docs/adr/0063-common-mistakes-list-changing-data-and-no-python-tools.md)). Decided and built: [ADR-0088](../../../../docs/adr/0088-common-mistakes-list-and-check.md).

## What it reads

Since its last run for this session: the agent's reply texts (`kind: "reply"`) and its tool calls' tool name and arguments (`kind: "tool"`), from the session's interval-hook record spool (`lib/HookSpool.php`). Nothing when there was no reply in the window.

## What it sends

One call to the light model (`lib/HookLlm.php`, the account's own endpoint and key, 20 s timeout, 80 output tokens): a fixed instruction, a rubric built from the list (each entry's id, shape and cue -- never the "Do" text), and the checked text itself, delimited and marked as data, not instructions. Nothing from a server, a customer, or a torrent ever leaves the account beyond what the agent itself already wrote or ran.

## What comes back

The model may name at most two entries, one per line, as `id | exact words copied from the text`. Only a line whose id is real and whose quoted words (at least 12 characters, case and whitespace folded) are actually found in the checked text survives; the rest, and anything that is not that exact shape, is dropped and counted. An id already shown earlier this session is dropped too. What the agent sees next turn is always the entry's own fixed sentence from the file -- the model's own words are discarded once they have done their one job of pointing at an entry.

## Cost

One light-model call at most once per interval (default 300 seconds, counted together with the two end-of-turn checks below so the three never run inside the same window) per session, at most 80 output tokens. A scheduled (`cron_`) session is skipped before any call, the same as `goal-review.php` skips one. All three callers together spend from one shared daily ledger (`lib/HookLedger.php`, [ADR-0071](../../../../docs/adr/0071-goal-review.md)), default 48 calls a day per account unless `hooks.dailyCalls.antipattern-check` says otherwise -- a call is reserved against it before it is made, so a failed or timed-out call still counts.

## Switches

- `hooks.every.antipattern-check`: the interval, 60 to 3600 seconds (`lib/HookInterval.php`); `0` also turns off all three callers, the same as `antipatterns.disabled` below.
- `hooks.dailyCalls.antipattern-check`: the shared daily cap, default 48 (`lib/HookLedger.php`).
- `hooks.async.disabled`: turns off every interval unit, this one included.
- `antipatterns.disabled`: turns off this unit and the two end-of-turn checks in `lib/HaltRun.php` and `hooks/halt/halt-guard/halt-guard.php` together -- a second, explicit switch alongside `hooks.every.antipattern-check=0` above, kept because the two read differently in `local/config.php`.
- `antipatterns.resume`: only the end-of-turn checks' one-shot resume; has no effect here.
- `hooks.llm.disabled`: the light model is never asked; this unit then always answers nothing.

## Log

`local/logs/antipatterns/YYYYMMDD.jsonl` (dir 0700, file 0600): timestamp, the session id's sha1, `source: "async"`, the outcome, the ids flagged, how many lines were dropped as ungrounded, the checked text's length, and the run's time in milliseconds. Never the reply text, the model's answer, or the commands themselves.
