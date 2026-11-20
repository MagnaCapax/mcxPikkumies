# ADR-0117: An outbound credential pass for text that leaves the machine

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-11-20
- Status: Accepted. Adds `Redact::outbound`, `Redact::leftover` and `Redact::refuseLeftover` to `pikkumies/lib/Redact.php` and moves the outbound call sites to them. Issues [#24](https://github.com/MagnaCapax/mcxPikkumies/issues/24) and [#25](https://github.com/MagnaCapax/mcxPikkumies/issues/25).

## Context
The operator's request of 2026-11-20: "we need outbound safety gates in a bit similar style than ours, but not identical. Liteweight version in pikkumies, not full redaction of passwords from everything but on outbound. for this feedback too." In the discussion it answered: "credentials need to be handled. Without it you cannot do jack shit ... the feedback, or anything outbound needs to scrub passwords tho ... needs an outbound gate".

What was found:
- `Redact::secrets` works one line at a time. A credential assignment inside a JSON-escaped tool-call argument (a script written with `write_file`, newlines as the two characters `\n`, quotes as `\"`) never starts a line and has no plain `NAME=` form, so it is kept (#24). Its users that send text away ([ADR-0056](0056-feedback-what-pikkumies-sends-to-pulsed-media.md) feedback, [ADR-0089](0089-support-tickets-with-the-ai-key.md) and [ADR-0100](0100-ticket-bundle-diagnostics-and-automatic-tickets-on-pulsed-media-servers.md) tickets, the [ADR-0083](0083-check-before-publishing.md) publish check, [ADR-0092](0092-upstream-issue-search-and-drafting.md) upstream drafts and searches) all inherit that gap. The publish check even uses "a line Redact changes" as its detector.
- Hermes' own export redaction runs first on a feedback conversation. Where it acts on an assignment it can leave `NAME=REDACTED = "<value>"`, and nothing removes the value after the marker.
- Stored feedback was checked for these shapes (values not read out): the REDACTED tail was the only one that survived both passes. Hermes had already removed the JSON-escaped assignments in those conversations, but the text, ticket and publish paths have no such first pass.
- A scratch copy of the two rules below over this repository's own `pikkumies/` and `docs/` (92,381 lines) fired 9 times: test fixtures, doc examples and a regex constant. Over stored real conversations, the non-tail hits were 3 quoted phrases; code (`$x`, calls, `a.b.c` names, `PWD=/path`, `pass = 0;`) never matched.
- A sealed feedback draft cannot be re-redacted by the client or scanned by the server; a fix protects later sends only. Drafts saved before it sit in `local/state/feedback/` and `local/state/ticket/` until sent.

## Options Considered
- **Add the rules to `Redact::secrets`.** One change, but the command log, scratchpad, hook records and stream progress would redact more inside the session, which the request rules out ("not full redaction of passwords from everything"). Rejected.
- **A second detector list in its own class.** [ADR-0083](0083-check-before-publishing.md) rejected a second list: it duplicates the redactor and drifts. Rejected; the two rules live in `Redact` and only the outbound call sites use them.
- **Decode JSON strings, redact, encode back.** Re-escaping a view is unsound for pretty-printed JSON, and a conversation cut at 1.5 MB can split a JSON value. Rejected for in-place rules that never touch the quotes and escapes around a value.
- **Refuse a draft that holds a credential (the wording of #25).** A false positive would cost the person their conversation. Rejected for scrubbing; refusal stays for what the scrub could not have done (below).
- **A model reviews what goes out.** Costs the user credits on every send ([ADR-0083](0083-check-before-publishing.md)). Rejected.
- **Send no conversation by default.** A product decision of [ADR-0056](0056-feedback-what-pikkumies-sends-to-pulsed-media.md), not a fix. Rejected here.

## Decision
- `Redact::outbound($text)` is `Redact::secrets` plus two rules, applied in place:
  - a secret-like name (the same name list as `Redact::NAME`, optionally quoted as a JSON key), `=` or `:`, then a quoted literal at any escaping depth (`"`, `\"`, `\\\"`), anywhere on a line; a name may start right after an escaped `\n`, `\r` or `\t`. The value between the quotes becomes `REDACTED`, the quotes and escapes stay.
  - a value after a `REDACTED` marker (`NAME=REDACTED = "<value>"`, plain or escaped) becomes `REDACTED`.
  - A value that is already `REDACTED`, holds no letter or digit, starts like code (`$`, `(`, `{`, `%`, `<`, `*`, `[`, `#`), holds a `(`, is a dotted name (`a.b.c`) or a type or flag word (`true`, `null`, `string`, `basic`, `bearer` and the like) is left alone. Length is never a filter. A regex engine error throws, as in `secrets`.
  - `$names` receives the variable names a value was removed from, never a value.
- Outbound call sites use it: the feedback text, conversation and session title; the ticket subject, message and text attachments (and so the ticket bundle); the ticket bundle check (`TicketAuto::checkFiles`, which compares a file with its own outbound pass); the publish check (a line the pass changes is a hit); upstream issue drafts and search words. The command log, scratchpad, hook records, stream progress, reports and configure notes keep `Redact::secrets` as before.
- The exact bytes are checked again before they leave: `FeedbackDraft::load` (the sealed JSON) and `TicketDraft::load` (subject, message and text attachments) call `Redact::refuseLeftover`. Because the two rules repeat to the same text, a draft made now passes; one saved before this change, or changed by hand, is refused with the variable's name and "draft it again". Both loads are used by the send verbs and the send hooks, so the refusal comes before anything leaves.
- The feedback `draft` record carries `removed` (how many values the outbound pass took out of the text and conversation) and `removed_names`.

## Consequences
- A script with credentials, pasted or written in a session, no longer reaches Pulsed Media, a public repository or an upstream issue through these paths; credentials keep working in the session itself.
- The value after a REDACTED marker no longer survives.
- Unsent drafts made before the change are refused at send and have to be drafted again.
- False positives cost a value turned into `REDACTED` in what is sent, or one confirmation in the publish check.
- Out of reach: Hermes' own `/debug` upload (public, as [ADR-0056](0056-feedback-what-pikkumies-sends-to-pulsed-media.md) says), a secret in a form neither list knows, a value holding a quote or a backslash, and anything already sent.
- Tests: `tests/RedactOutboundTest.php`.

## References
- Issues #24 and #25; [ADR-0044](0044-every-shell-command-is-logged.md), [ADR-0056](0056-feedback-what-pikkumies-sends-to-pulsed-media.md), [ADR-0083](0083-check-before-publishing.md), [ADR-0089](0089-support-tickets-with-the-ai-key.md), [ADR-0092](0092-upstream-issue-search-and-drafting.md), [ADR-0100](0100-ticket-bundle-diagnostics-and-automatic-tickets-on-pulsed-media-servers.md)
- Code: `pikkumies/lib/Redact.php`, `pikkumies/tools/system/feedback/`, `pikkumies/tools/system/ticket/`, `pikkumies/hooks/guard/publish-check/`, `pikkumies/tools/development/upstream/`, `pikkumies/lib/UpstreamScrub.php`
