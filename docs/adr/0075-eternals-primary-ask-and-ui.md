# ADR-0075: Eternal Pikkumies windows, local ask and UI start

## Metadata
- Date: 2026-10-19
- Status: Accepted. Amends [ADR-0022](0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md), [ADR-0025](0025-scheduled-jobs-per-minute-tick-under-no-new-privs.md), [ADR-0032](0032-settings-configure-skill-first-run-and-install-flags.md) and [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md).

## Context
A person may leave several Pikkumies conversations running in tmux. A reboot or a missing pane should restore them, while an explicit quit should clear the conversation pointer. A local program also needs a narrow way to ask the Primary conversation a question. The platform launcher needs one command to start its UI process.

## Decision
The host enables supervision with `/etc/seedbox/config/pikkumiesEternals.enabled`. Without it the cron tick is inert. The user's `cron.disabled` removes that tick and therefore stops window restarts. `cron-tick` calls the short ensure step before its job lock and job checks. The step never builds Hermes. It refuses starts outside the account's `user-<uid>.slice`, while the runtime, key or an update is not ready, while an off or transfer hold applies, or when it cannot persist the record. It starts at most one missing window per tick, Primary first. The window's resident process is `pikkumies run --eternal pmN`, with a close-on-exec run lock. A separate wrapper process is not used.

Records live under `pikkumies/local/eternals/pmN` and contain only `sessionId`, `fails`, `lastStart`. A record is replaced atomically. Session identifiers have a conservative allowlist; other record content is never used as a command. A successful user exit clears Primary's pointer or deletes another window's record. Signals and not-ready exits do not count as failures. Abnormal exits back off across subsequent minute ticks. A fresh session must live ten minutes before its pointer replaces the prior one. `eternal off` retains records and stops windows; `on` permits restoration. `hold --until` requires an expiry. `tmux [N]` retains its existing attach semantics and makes an Eternal record for its named window.

`ask` reads one question from stdin, refuses root, terms-pending, an absent Primary and a non-empty or unknown input widget, strips control characters, and submits via tmux's stdin buffer. It logs only length. `ui start` is the platform launcher's entry point to the installed UI binary; cron supervision does not start it. The UI process needs the account's user slice.

## Alternatives and limits
Commands are never replayed from records. A user-controlled setting cannot grant OS privilege. The host marker is deployed separately. The first implementation uses tmux's terminal-session pointer file and a conservative input-widget check; this needs live host verification before fleet enablement. The target transfer hold remains a host-owned file. The UI server is installed by the platform, not by this repository.

## Session-store preflight
A stored pointer is first checked against the read-only `sessions.id` table in the user's `local/hermes/state.db`. A proven missing row starts fresh without increasing the failure count. If the database cannot be read or the SQLite extension is absent, the launcher leaves the pointer intact and lets the runtime decide. This keeps a broken or changing store from being mistaken for a deleted conversation.

## ADR-0062 boundary
[ADR-0062](0062-hermes-config-seeded-once-pins-in-hermes-managed-scope.md) leaves the user's `display` settings outside the managed layer so `/skin` and other choices remain available. This takes precedence over the requested managed pin for `display.busy_input_mode`. New user configs are seeded with `queue`; an existing user's display setting is not overwritten. `session.terminal_continue` remains managed.

## Local ask limits
The local sender accepts at most five successful questions per minute per account. A repeated `--nonce=TOKEN` within one hour is acknowledged without another paste. The nonce file and the rate counter stay under `local/eternals/`; both are private state. A Primary pane is ready only after its terminal breadcrumb has a valid session pointer written since the latest start.

## Contract v1.1
On opted-in hosts, Pikkumies calls to the `pikkumies` tmux server share private `local/tmux/` and `local/tmp/` directories (0700), so a foreign-owned default socket directory under shared `/tmp` cannot prevent startup. The UI process inherits these paths. `ask` removes C1 controls as well as ESC and C0, cancels copy mode before pasting, and sends only when the captured pane ends at a recognized empty input prompt. An active turn or unknown screen returns status 6. Visible unsent text on a single input line returns status 3; an editor state that cannot be read reliably stays busy. Each UI launch gets separate fork data, log, and tmux PID paths beneath `local/ui/<ui>/`.

## Review corrections (2026-10-20)
The new command words claim only their exact forms; other phrases still reach the agent as prompts under ADR-0022. Ask refuses slash-prefixed text before touching the pane. An ordinary answer mentioning approvals is not an approval prompt; only the input widget determines whether ask may paste. `hold --until` writes the hold before signalling managed panes to stop. A managed HUP with the tmux server still alive is a user quit unless a stop marker was written by the updater, off, hold, or shutdown path. SIGTERM marks a resident shutdown before exit. User quit clears Primary's pointer or removes an extra window's record. A closed Primary reports `quit` until it starts fresh; a retained record under off or hold reports `quit` with its cause. A closed server during a reboot retains records. A host without the opt-in marker keeps the original interactive `pikkumies tmux` server and plain sessions; `eternal`, `ask`, and `ui` launch no processes. Managed tmux starts with an explicit environment allowlist. From inside tmux, attach opens a new client and never switches a different client.
