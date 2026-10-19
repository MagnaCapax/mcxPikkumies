# ADR-0058: One live account record; the status verbs do not wait for the search

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Amends [ADR-0011](0011-documentation-sources-and-search-first.md), [ADR-0028](0028-search-first-every-turn-and-a-light-model-for-hooks.md) and [ADR-0038](0038-plain-search-command-config-overlay-live-source-check.md) (a plain account or show call is allowed while the search-first gate is closed).

## Context
The operator's words of 2026-10-18, after a basic disk, memory and quota question cost about 26k credits, 23 seconds and 9 tool calls: "the tooling, that should be pborderline deterministic, make sure similar questions are tooled and use minimal amounts of tokens. that was such a basic thing it should be basicly like agentDiagnostics.php but on account basis and that's it", and "the levytila etc. question should basicly see that we are PMSS and info.php ertc. so that was really weakly integrated".

What was already there:
- PMSS ships `~/bin/userShowLimits --json` (PMSS `etc/skel/bin/userShowLimits`). It runs as the account and reports that account's disk quota, memory, processes, CPU, disk I/O and traffic, the PMSS and Debian versions and the bonus, plus a section shared by all accounts.
- `Detect::userShowLimitsData()` already runs it and drops the account's and the server's names ([ADR-0023](0023-host-detection-cache.md), [ADR-0035](0035-performance-diagnostics.md)). But `system-status show` kept only the CPU and memory ceilings. The rest reached the model only through `performance check`, the slowness tool.
- The search-first gate (ADR-0011, ADR-0028) blocked every terminal call until the memory search ran, the doctrine said "search first" for every service question, and the storage skill claimed "how much space is left". So a figure the machine knows took a search, `df`/`free`/`du`, and wiki text.
- `show` returned the detection cache's `apps` and `resources` as they were at detection. Its disk was an old quota snapshot, shown next to the live `limits.disk`. Its CPU, memory and /home totals were the whole server's, with nothing saying so. And it carried the account name (`user`).

## Options Considered
- **A pre-turn hook that injects a snapshot when the message looks like an account question.** It costs no tool call, but it is a keyword classifier on every turn and goes stale within the turn. Kept as a fallback only if the question suite still shows more than one call after this.
- **Mirror `agentDiagnostics --user`.** That is PMSS's root-side tool (it uses `su`, `du` over the home and `curl`). The account-side contract is userShowLimits.
- **Re-parse `~/.quota`, `pmss-user.json` and `.trafficData` in Pikkumies.** That duplicates PMSS. userShowLimits is the interface.
- **One `account` verb over userShowLimits, the gate letting the two read-only status verbs through, and routing text that names it (chosen).**

## Decision
- **`system-status account`** prints one record (`StatusAccount::record`), at most 1.5 KB:
  - `disk`: used, soft, hard, plan, burst, files, snapshot age, `left` and `used_pct`.
  - `memory`: used against limit, pressure, status, OOM kills.
  - `traffic`: upload in the last 30 days against its limit, `left`, inbound, bonus, `cap_mbit`.
  - Also `processes`, `cpu.quota_pct`, `versions`, `bonus`, the account's own running apps (read now), `unlimited`, and a panel address template.
- **Where the figures come from.** On PMSS each one is picked by the metric name PMSS writes. A name not found is null, never guessed, and a test fails when a fixture metric is renamed. `unlimited` lists only what PMSS itself calls unlimited: a value with no limit, the rule of userShowLimits' own human view. Off PMSS, or when the tool fails, the keys stay the same and the figures are null. userShowLimits runs only when the host family is `pmss`, the same rule as `Detect::limits()`.
- **Privacy (ADR-0023).** The record never contains the account's or the server's name. Its panel address is a template (`https://<this server>/user-<you>/`). The section shared by all accounts is left out. `show` also stops returning the cached `user`.
- **`show`:**
  - `apps` is read now (`Detect::runningApps`, which gains a `$proc` test seam).
  - The cached quota snapshot is dropped.
  - The cached CPU, memory and /home totals move to `server_totals`, whose `scope` says "whole server, shared by all accounts" or "whole machine".
- **The search-first gate** lets one plain call of `system-status.php show` or `account` run while it is closed, through the existing `searchFirstPlainCall` (ADR-0038). It opens nothing: other acting calls still wait for the search. `logs` and every chained, piped, redirected or substituted command still wait.
- **Routing:**
  - The PMSS line of SOUL.md's machine block names `system-status account`.
  - The system-status skill carries the English and Finnish words (levytila, kiintiö, muisti, liikenne, rajat) and names the panel's Info tab as the user's own view.
  - The storage skill keeps "what uses the space" and sends "how much is left" on PMSS to `account`.
  - The doctrine says this account's own figures are live and are not searched, and never come from `df`, `free`, `du` or wiki text.
- **Question suite ([ADR-0039](0039-question-suite.md)).** A `max_calls` key counts every tool call. A new question asks for disk, memory and traffic left on PMSS (`first_call` account, `max_calls: 2`). The Finnish disk-left and the disk-quota questions expect `account` first. The pm-wiki disk question is re-scoped to ask what the wiki says.

**Control delta (the gate allows more).** The gate exists so the agent does not answer a service question from recall. A live, read-only record of this account's own figures, produced by the platform's own tool, is the source a search would point at. Making the agent search first spends tokens to reach the same command, which is the incident above. So the gate is moved to the hazard, not the proxy: "answers from memory" is still gated, and "reads its own live figures" is not. What stays blocked is what the gate protects against: any command that acts, `logs` (it reads log text), and any command whose plain-call reading could hide a second command (`;`, `&&`, `|`, a redirect, a newline, `$(...)`, backticks, backslashes), each covered by a test. The allowance is two verbs of one script under the same install-directory rule as the memory unit's `list` and `sources`, and like the whole gate it is a habit prompt, not a security boundary (AGENTS.md).

## Consequences
- An account question costs one tool call and about 1 KB of record instead of a search, three commands and wiki text. The figures are the platform's own, so the agent's and the panel's Info tab agree.
- PMSS renaming a metric shows up as a null figure and a failing test, not a wrong number.
- Whether the throttle is active is not in userShowLimits yet. That PMSS change is separate. Because figures are picked by name, carrying it here takes one `StatusAccount::MAP` line once PMSS names the metric; nothing new reaches the model before then.
- `show` changed shape: `resources` became `server_totals` without its disk, and `user` is gone. No code in this repository read either key; only the model did.

## References
- ADR-0011, ADR-0023, ADR-0028, ADR-0035, ADR-0038, ADR-0039.
- PMSS `etc/skel/bin/userShowLimits` (metric names; human view's unlimited rule), `etc/skel/www/index.php` (the `info` tab, `info.php`), `etc/skel/www/panelSessionAuth.php` (`/user-<name>/`).
