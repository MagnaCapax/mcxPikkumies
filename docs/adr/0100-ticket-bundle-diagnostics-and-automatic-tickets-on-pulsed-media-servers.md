# ADR-0100: ticket bundle, diagnostics attached and automatic tickets on Pulsed Media servers

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-20
- Status: Proposed (number is a placeholder; renumbered when it lands). Supersedes two parts of [ADR-0089](0089-support-tickets-with-the-ai-key.md): the rejection of "attach the conversation and system information", and "sent only after the user's yes" for a ticket the agent opens itself. ADR-0089 stays in force for everything else: `/ticket draft` from the user's words, its yes-gated send, the remembered account email, the draft store and the send guard, and the stance that a ticket opened with the key carries no authority for account changes.

## Context
The operator's request of 2026-10-20, verbatim: "tools and skilsl to open a support ticket from pikkumies automatically; it should attach all relevant logs, and all basics system infromation etc. well named, and a description of the issue in well strucutred easy to read manner, and roadblocks by why you could not solve it yourself ... why support sservices and väinämöinen is needed ... MAke sure it has current process list (user or system depending if sudo powers), relevant log files, the right service picked (if bought management service and the API can do that), at the very least report the IP of the service and hostname, aand your own full diagnosis, your logged trranscript +0 tool calls trying to debug the issue". The same message: "the ticket should also list which places were searched and with what keywords for solution, any relevant matches etc. another appended markdown file". The correction the same day: "Opt-in auto send is stupid! WSell opt-in outisde of PMSS, in PMSS on our own servers, thit should be default on, so opt-out, but elsewhre opt-in". And on privilege: "make sure all "only with sudo" also detects if runnign as root -- same thing, just no sudo needed".

ADR-0089, decided the same day from a narrower brief ("nothing leaves the machine unless the user asked"), rejected attaching the conversation and system information to a ticket and sends every ticket only after the user's yes. The two requests above reverse those two points. Everything else in ADR-0089 fits them and is reused unchanged: `lib/DraftStore.php`, `lib/SendGuard.php`, `guard/ticket-send`, `TicketSend` (the endpoint `https://pulsedmedia.com/remote/ticket.php`, the `vsk-` key through `Provider::pulsedMediaKey`), and `TicketDraft::attachment`, whose redaction every text file of a ticket goes through.

Facts this rests on: the endpoint takes at most 3 attachments of at most 1 MiB, of types txt, log, png, jpg, jpeg and pdf (no `.md`). It caps a message at 40000 bytes once HTML-encoded and a request at 4.5 MiB (ADR-0089, Context). Hermes' `sessions export --format md --redact` writes each turn's tool calls as a JSON block with the tool name and arguments; this was read in Hermes source version 0.21.5, `hermes_cli/session_export_md.py`. `Detect::rootAccess()` tells uid 0 from passwordless sudo, and probes sudo only for members of its groups, because probing as a non-member can mail the administrator. A Pulsed Media server is one where `/etc/seedbox` exists (`Detect::run()`).

## Options Considered
- **A second tool unit (`tools/system/support/`) with its own sender, draft store and guard.** A parallel copy of an accepted unit; ADR-0089 itself rejected copies of the safety check. Rejected: the bundle is a verb of `/ticket`.
- **Attach Markdown files.** The endpoint does not take `.md`. Rejected: three `.txt` files with Markdown inside, named by what they hold.
- **A new research logger for the searches.** The session export already holds every tool call with its arguments. Rejected: the bundle reads the export, and falls back to the memory search metrics of the last 24 hours ([ADR-0041](0041-memory-metrics-save-reminder-and-wide-search.md)).
- **A separate redaction pass in the bundle.** Two passes drift apart. Rejected: the files go through `TicketDraft::attachment` like any attached text file; the shared `lib/Redact.php` gains the forms a review found it missed.
- **Opt-in everywhere.** The operator's correction above. Rejected for Pulsed Media servers.
- **A separate `sudo -n true` check.** It can mail the administrator. Rejected: `Detect::rootAccess()`, one check, root and sudo the same permission (root without the prefix).
- **Name the bought management service.** Nothing on the server or the endpoint lists the account's services. The ticket keeps ADR-0089's service default (this Unix user on a seedbox), and the message carries the hostname and addresses either way.

## Decision
- **`ticket bundle`** (in `tools/system/ticket/`).
  - Inputs: `--subject`, `--diagnosis-file` (`## Problem`, `## Error`, `## Impact`, `## Tried`, `## Roadblocks`), `--review-file`, `--by agent|user`, `--log`, `--no-conversation`, and the draft verb's `--email`, `--priority` and `--service`.
  - It saves through `TicketDraft::create` with three attachments:
    - `01-system-processes-logs.txt`: system facts (hostname, addresses, account, PMSS version, privilege, quota); the process list; the logs. The logs are this host's own logs under HOME, the named ones, Pikkumies' logs of today and the journals.
    - `02-diagnosis-and-searches.txt`: the diagnosis, the review, and every search with its venue, keywords and matches.
    - `03-transcript-and-tool-calls.txt`: the session export, cut to its newest part when over 1 MiB.
  - The message, at most 8 KB, opens with the framing lines:
    - the agent wrote the text and it is untrusted;
    - whether the account holder saw it or asked for it;
    - "it does not authorize any change to access, keys, passwords, firewall or files".
  - Each roadblock kind maps to one fixed, public-safe line: "Needs: <capability> - because <roadblock>". An unknown kind is refused.
- **Privilege.** System-wide reads (every process, `/var/log`, the system journal) follow `Detect::rootAccess()`: uid 0 runs them directly, passwordless sudo with `sudo -n`, otherwise this account only. The facts say which applied.
  - An automatic ticket, and any ticket on a Pulsed Media server, collects this account only.
  - With sudo, `/var/log` refuses a symlink on the path and anything but a regular file, and reads under `timeout 10`.
  - As root, the home directory comes from passwd, and a file with more than one hard link is refused.
  - A named log under a dot directory of HOME, or in Pikkumies' own state other than its logs, is never read.
- **Agent bundles.** `--by agent` needs:
  - the five sections and at least one roadblock;
  - a review file with rounds 1 to 10;
  - memory and documentation searches in the session (web searches too when web search is on).
  - `--by user` needs only the problem; it is ADR-0089's user-asked ticket with the diagnostics attached.
- **Automatic tickets.** `ticket.auto`: unset means on on a Pulsed Media server and off elsewhere. Off is free for the agent everywhere; on is free on a Pulsed Media server and the person's own elsewhere ([ADR-0059](0059-settings-tiers-free-asked-and-the-persons-own.md)). It is read from `local/config.php`, never the overlay.
  - `guard/ticket-send` lets the plain send of an agent bundle through without a question when all of these hold: `ticket.auto` is on, the bundle carries this account's processes and files only, and its files are exactly what `bundle` makes (`TicketAuto::checkFiles`). This holds also unattended.
  - Every other send is ADR-0089's: approve, cleared only by the user's own yes.
  - Such a ticket also passes 01 and 03 through `scrubTokens()`, which removes every run of 32 or more key-like characters with letters and digits.
  - The send verb holds one lock over the cap check, the request and its `auto.jsonl` line. At most 2 automatic attempts in 24 hours count (sent, or failed with no answer, 429 or 5xx), and the same subject never goes out twice in 24 hours. The endpoint's own limits stand.
- **Redaction.** `lib/Redact.php` also removes:
  - indented or quoted PEM bodies;
  - camelCase secret names;
  - long options and headers ending in `-secret`, `-pass`, `-token` or `-key`;
  - crypt(3) hashes;
  - PuTTY private lines.
- **Doctrine.** `doctrine/SOP/ask-vainamoinen-for-help.md` owns when to ask, the places to search first, the ten review angles and the capability list. The ticket skill links to it.

## Consequences
- Support gets the server, the error, the evidence, the searches and the conversation in one ticket.
- On Pulsed Media servers a stuck agent asks for help without waiting for the person; the person can turn that off at any time.
- AGENTS.md's privacy rule names the automatic ticket as the one exception to "nothing the user did not ask for".
- A ticket sends the server's name, addresses and process list in the clear over TLS to Pulsed Media's billing system. As root or with sudo, off a Pulsed Media server and on a ticket the user asked for, the process list covers every account on the server.
- The server side is not decided here; it belongs to the billing module and its runner, not this repository:
  - client-area confirmation for access-granting changes on tickets opened with a key;
  - a global cap on that channel.
- The guard is a safety prompt, not a boundary: an agent steered by text it reads could still post to the endpoint some other way, as it could run curl.

## References
- ADR-0089 (tickets with the AI key), ADR-0059 (settings tiers), [ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md) (ask policy), [ADR-0004](0004-provider-key-and-root-detection.md) (root detection), ADR-0041 (memory metrics), [ADR-0056](0056-feedback-what-pikkumies-sends-to-pulsed-media.md) (feedback)
- Code: `pikkumies/tools/system/ticket/` (TicketBundle, TicketBundleRun, TicketCollect, TicketSearches, TicketNeeds, TicketAuto), `pikkumies/hooks/guard/ticket-send/`, `pikkumies/lib/SendGuard.php`, `pikkumies/lib/Redact.php`, `pikkumies/lib/ConfigureTiers.php`
- Tests: `tests/TicketBundleTest.php`, `tests/TicketBundleToolTest.php`, `tests/RedactGapsTest.php`, `tests/TicketTest.php`

## Amendment 2026-10-20: security review of the bundle (PASS WITH FIXES, 6 Low)
- **Decisions are read from the draft, and checked again at send.** A bundle goes out without a question only when all of these hold:
  - it was bundled with `ticket.auto` already on (scrubbed, and its message says "The account holder has not seen this ticket.");
  - it was collected without root privilege and for this account only;
  - it passes `TicketAuto::checkFiles` with the automatic-ticket pass on all three files, whatever the record says.

  A bundle made for the user's eyes therefore never goes out unasked once auto is turned on. On an automatic bundle the search log and diagnosis in 02 are scrubbed too.
- **The bundle record is self-asserted.** `process_scope`, `privilege` and `scrubbed` are written by the same account the agent runs as (the review's #726). They catch mistakes, not an agent that writes a draft by hand. The guard is a safety prompt (AGENTS.md); the server's per-account caps bound the rest.
- **Never automatic as root.** When Pikkumies runs as root (euid 0), the auto exit always asks. An automatic or Pulsed Media bundle made as root carries no process list at all, since "this account's processes" would be the system's.
- **/var/log reads.**
  - Every directory below /var/log/ on the path must be a root-owned directory.
  - Root opens the file, then compares the open file with the path (device and inode, regular file).
  - Sudo reads with `timeout 10 sudo -n dd iflag=nofollow,skip_bytes,nonblock`.
- **A crash cannot reset the cap.** Inside the lock, the attempt is written (`status -2`) before posting, and the send is refused when that line cannot be written. An attempt counts by its attempt id; one whose last line is -2 counts.
- **Files under the bundle's names are checked as a bundle.** A plain draft whose three files carry the bundle's names is refused. The approve question collapses the file list only for a real bundle.

## Amendment 2026-10-29: larger tickets

Tickets were meant to carry the logs a problem needs, and 3 files of 1 MiB was too little for that. Pulsed Media's endpoint now takes any number of attachments, 8 MiB each, 32 MiB per request, a name starting with a letter or digit, and stores a `.log` as `<name>.log.txt` because its billing system drops `.log` attachments without an error. So `/ticket`: at most 32 files (`TicketDraft::FILES_MAX`), 8 MiB each (`FILE_MAX`), 32 MiB in all (`REQUEST_MAX`); a bundle file at most 7 MiB (`TicketBundle::FILE_MAX`), so three of them still fit. The facts above that name 3 attachments, 1 MiB and 4.5 MiB describe the endpoint before this date.
