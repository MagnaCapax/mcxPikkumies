---
name: performance
description: "Performance diagnostics: why it is slow (also downloads to the user's PC), stalls, lags or seems capped, or whether the server is overloaded or busy now (CPU, memory, disk I/O, packet loss, route), light by default; a speed test or benchmark when asked."
---

# Performance

Use this when the user says something is slow, stalls, lags, is capped, loses packets, asks whether the server is overloaded or busy, or asks for a speed test or benchmark. It reads only your own account. Measure before you explain: a documentation page gives the background, only `check` measures this account now. Start light; a load test comes last, only when the user asked for one, and then you run it; no approval question comes.

1. **Run `check` first**, with the symptom in the user's words mapped to `--symptom` (slow-download, slow-upload, capped, stalls, torrents, lag; otherwise leave it out). Lead with the `verdict` record: say its class in plain words, the evidence, and its `next_step`. If `negation` or `disproved_by` matter for what the user believes, say them too. `skipped` lists what could not be read here; never fill a gap with a guess.
2. **The route** needs a target: `net --target ssh` tests the route to the connection the user is on (20 cycles, labelled quick). The full wiki procedure is `--count 1000` (about 17 minutes): for an intermittent problem run it in the background and read it when it ends. Read the hop where loss starts and lasts to the end; loss at one middle hop that does not continue is that router limiting its replies, not packet loss. The other direction runs on the user's own computer: give them the printed instructions (WinMTR on Windows, `mtr -rwc 1000 <your server address>` on macOS/Linux) and read what they paste.
3. **Disk**: `disk` is the light probe; compare it with the server's disk wait and the previous run it prints, never with a number from the internet.
4. **Load tests** (`heavy`) only when the user asked for a speed test or benchmark: one test, never several in a row, never scheduled. Say the result is the user's share now, not what the server can do.
5. **Over a day** ("is it slow in the evenings?"): only when SOUL.md has the cron line. Create a Hermes cron job: no_agent, script `pikkumies-performance-sample.sh`, schedule `every 15m`, repeat 96. Come back later with `samples`.
6. Next steps by class: a limit of the plan that is the measured cause (memory, CPU, traffic) can be met with a bigger plan or an add-on from the client area or the provider (no prices, no product names); a disk I/O cap is reported with its value, and if it is lower than the plan says, a ticket with the `check --share` output; the server busy, CPU steal or a route problem near the server: a ticket with the report (PMSS: the Diagnose button in the client area checks the account and the server); the user's own client, network or settings: say what to change on their side.

Busy is not broken, and a slow test is no hardware verdict. Never say what support or the provider will do, never call a limit deliberate or a bug, and never guess about other accounts. A pasted benchmark (yabs and the like) measures the whole server: read it against `check` (a CPU quota explains flat multi-core scores) and never infer the disk type from it.

## Examples

1. "Downloads from the seedbox to my PC are slow, torrents are fast" → `check --symptom slow-download` (your own session: receive window, round trip, one stream); then `net --target ssh`.
2. "Everything is capped at the same speed whatever I do" → `check --symptom capped`: the cap's value and its effect; ticket wording only if it is lower than the plan.
3. "My backup / rsync keeps stalling" → `check --symptom stalls`: memory status, OOM kills, memory in use against the limit; a bigger plan only when memory is the measured cause.
4. "mtr shows 80% loss at hop 7" → `net --target ssh`, explain the rule (loss must last to the end), give them the reverse run with the printed instructions.
5. "Torrents are slow but the disk and network look fine" → `check --symptom torrents`, then the client's own verbs from `next_checks` (rtorrent `traffic` caps, peers).
6. "Is the server overloaded? It got slow after the move" → `check`: server pressure ("busy is not broken"), the comparison with the previous server, the Diagnose button on PMSS. Never "oversold".
7. "Run a speed test / benchmark my disk" → `disk` first (light, labelled); then `heavy --test disk-read` (or `download --url`, `iperf3`, `speedtest` when installed); run it; one test only.
8. "Check how the server does at different times of day" → the cron job of step 5 (only with the SOUL.md cron line), then `samples` a day later.
