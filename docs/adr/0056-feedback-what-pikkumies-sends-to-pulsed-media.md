# ADR-0056: /feedback, the one thing Pikkumies sends to Pulsed Media

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted

## Context
The operator's request of 2026-10-18: "We should probably have feedback API option ... Basicly integrated with pikkumies, so people can /feedback and that sends us crucial information + transcript and we store it for later analysis ... private secure storage, only for us", and on the place: "should we do it separately a pikkumies.mcx.fi domain for functions like this? I think we will regardless need to create one so we can upsell power functionality".

AGENTS.md says Pikkumies "never sends anything off the machine that the user did not ask it to". [ADR-0032](0032-settings-configure-skill-first-run-and-install-flags.md) keeps `privacy.telemetry` a setting with no sender and says a sender needs its own ADR. This is that ADR, for feedback only.

Facts this rests on, checked 2026-10-18 in Hermes v2026.9.24:
- Hermes' built-in `/debug` uploads system information and log tails to a public paste service. Typed in the terminal it passes `yes=True` and asks nothing (`hermes_cli/cli_commands_mixin.py`, `_handle_debug_command`); from a chat platform it uploads first and shows the privacy notice with the links (`gateway/slash_commands.py`, `_handle_debug_command`). Only `hermes debug share` on a terminal asks y/N (`hermes_cli/debug.py`, `_confirm_upload`). `/debug local` uploads nothing.
- A tool unit is a skill the model runs ([ADR-0015](0015-tool-units-one-skill-per-tool.md)), so a y/N inside the tool, or a `--yes`, is answered by the model. Hermes' human-approval gate is not ([ADR-0020](0020-deletes-need-the-users-approval.md)): a `pre_tool_call` hook answering `approve` makes Hermes ask the user, the model cannot answer, and unattended runs are refused unless approvals are turned off (ADR-0020).
- `hermes sessions export <dir> --format md --session-id <id> --redact --lineage logical` writes one session, compacted turns included, with secrets removed from the messages (`hermes_cli/sessions_cmd.py`, `redact_session_data`). Its redaction leaves a bare `vsk-` key in prose untouched.
- Hermes sets `HERMES_SESSION_ID` in the environment of every command it runs (`gateway/session_context.py`).

## Options Considered
- **Point users at Hermes `/debug`.** Public, and a typed `/debug` asks nothing. Rejected for anything private.
- **Disable or wrap Hermes `/debug`.** Hermes stays vanilla ([ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md)) and has no setting that removes a built-in command. Rejected; the feedback skill and README say `/debug` is public and point to `/feedback` or `/debug local`.
- **A y/N in the tool before sending.** The model can answer it, and text the agent reads (a torrent name, a file) can steer it. Rejected.
- **Open a support ticket with the transcript.** Puts the transcript in the general ticket queue and needs billing credentials where the feedback arrives. Rejected.
- **Send in the clear over HTTPS and encrypt on the server.** The plaintext would pass through the receiving server's memory. Rejected: the client seals it.
- **Retry automatically when sending fails.** A send at a time the user did not choose, outside the approval gate. Rejected.
- **Read the key the way the agent does (`Provider::resolve`).** It prefers `~/.pikkumiesKey`, the user's own provider: feedback would carry that provider's key to Pulsed Media. Rejected: only `~/.virsilipasKey`, and only a `vsk-` key.

## Decision
- **`tools/system/feedback/`** (a tool unit, ADR-0015) with `draft`, `send <id>` and `list`.
  - `draft --text T | --text-file F [--no-conversation]` builds the payload and saves it once under `local/state/feedback/<id>.json`, mode 0400, where `<id>` is the first 32 hex digits of the file's sha256: the text (at most 20,000 bytes), the current conversation (exported as above into a fresh 0700 directory under `local/state/feedback/`, never a shared `/tmp`, read once and deleted; export directories older than 10 minutes, left by a killed draft, are removed on the next draft), its session id and title, and the Pikkumies commit, Hermes version and PMSS version. The export's header also carries the session's model, provider and working directory. Both texts go through `lib/Redact.php`, line by line: `Cli::cleanString`, the shell forms the command log already removed, and the common password and key forms of config files, headers, PEM blocks and token prefixes (`vsk-`, `sk-`, `AKIA`, `ghp_`, `xoxb-`, JWT); the command log uses the same class. These are common forms, not every secret, so the user reads the draft before sending. A regex engine error stops the draft with an error, never an emptied text. The conversation is cut to its newest 1.5 MB before redaction and the whole payload stays under 1.5 MB encoded; a cut conversation says so. It prints the size, the session, whether the conversation is in and how the text starts. Nothing leaves the machine.
  - `send <id>` refuses a draft whose sha256 no longer starts with its id, seals it to Pulsed Media's public key (`config/feedback-public-key.txt`, a libsodium sealed box) and posts it to `https://pikkumies.mcx.fi/v1/feedback` with the `vsk-` key as the Bearer token, through `lib/Http.php`. When it fails, the draft stays and the error says so, and says that trying again will not help after a 4xx other than 429; nothing retries by itself. After the server takes it (201) the draft becomes `<id>.sent.json`, which is never sent again, so a remembered approval cannot send a duplicate. `load()` accepts only the known fields with their types. Without sodium it says what is missing and keeps the draft.
- **`hooks/guard/feedback-send/`** (pre_tool_call, `failClosed: true`): the terminal command exactly `php <this Pikkumies' feedback.php> send <id>`, alone, with an absolute (or `~/`, expanded with HOME) canonical path, gets `approve` with `rule_key` `pikkumies-send:<id>`. The message is built from the draft file itself: where it goes, that it is linked to the account through the key, its size, the session id and title, that it carries version numbers and the session's model, provider and working directory, the path of the whole file, and last, on one line with quotes escaped, the first 400 bytes of the text. The forms of a feedback send it can see are blocked: a feedback path followed by the verb `send` (only the word right after the path counts, so a draft about a send button runs), `FeedbackSend` or `FeedbackDraft` with the word send, and a feedback path with shell expansion (`$ * ? [` or a backtick) and the word send, in a terminal command, code, a background process's input, `write_file` or `patch` content and a scheduled job; and a missing, changed or already sent draft. It sorts before `guard-destructive`, and a block wins over any approve.
- **The prompt is a safety prompt, not a security boundary.** It catches the agent's own and casual sends. An agent steered by text it reads runs as the same user and can still run the sender some other way (a relative path from the tool's directory with a glob, code that rebuilds the sender), as it could run curl to send anything anywhere; AGENTS.md says hooks are safety prompts. What the sender sends is sealed to Pulsed Media either way.
- **USAGE `changes`: `sends`** marks a verb whose data leaves the machine. The guard contract test checks that no `sends` example is let through unasked.
- **Feedback is not telemetry.** It is sent only when the user approves one specific payload, so `privacy.telemetry` does not gate it, and it never sends anything on its own. AGENTS.md's privacy rule says so.
- **What the server keeps:** the sealed blob, the sha256 of the key, the size and the time. Only Pulsed Media's private key, kept off that server, opens it. The Pulsed Media privacy policy says what is kept, for how long and how to have it deleted; the server takes nothing before that text is published.

## Consequences
- The user is asked before every send the agent makes in the plain form, and sees what goes out. An agent steered into rebuilding the sender can still send without the prompt, sealed to Pulsed Media, as it could already send data anywhere with curl.
- Pulsed Media gets the conversation that explains a problem, sealed end to end.
- `/debug` stays as Hermes ships it; the docs say it is public.
- The server cannot check what a sealed payload contains: a client could send junk up to the size limit. The server's per-key daily limit and storage cap bound it.
- Feedback needs a Pulsed Media AI key and PHP with sodium; without either, the draft is kept and the tool says why.

## References
- ADR-0015 (tool units), ADR-0020 (approval gate), [ADR-0030](0030-hooks-as-units-run-by-one-orchestrator.md) (hook units), ADR-0032 (telemetry setting), ADR-0033 (vanilla Hermes), [ADR-0044](0044-every-shell-command-is-logged.md) (command log)
- Code: `pikkumies/tools/system/feedback/`, `pikkumies/hooks/guard/feedback-send/`, `pikkumies/lib/Redact.php`, `pikkumies/config/feedback-public-key.txt`
- Tests: `tests/FeedbackTest.php`, `tests/GuardUsageContractTest.php`, `tests/CommandLogTest.php`
