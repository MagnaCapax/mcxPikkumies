# ADR-0010: Soul and voice: job, who it works for, what it is, and the Kalevala verses

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-04
- Status: Accepted

## Context
`doctrine/soul.md` and `doctrine/voice.md` become part of `SOUL.md`, which Hermes sends to the model on every turn. When `SOUL.md` exists, Hermes uses it instead of its own default identity (`agent/system_prompt.py`, `_identity_parts`). That default carries a reply-sizing rule (reply length matches the weight of the question; finished work reports what changed, what was checked and what is left). Our voice file did not, so the rule was lost.

The soul named the agent and its working loop but never stated its job, who may give it tasks when it is run from a script, what it is (an AI program, made by whom), or how to answer questions about its model or its maker. The name came from a Kalevala story the soul only paraphrased.

## Options Considered
- **Keep Hermes's default identity and ship no identity of our own.** Its text introduces the agent as Hermes Agent, so the agent could not say who made it.
- **Add a values list (privacy, honesty, harm, task) to the soul.** It repeats `safety.md` (safety before the task) and `doctrine.md`. Putting privacy first also invites the agent to refuse reading the user's own files, which it needs to do its job.
- **Put the Kalevala verses only in the README.** Costs nothing per turn, but then the agent cannot explain its name or the "as the work needs" rule.
- **Rewrite soul and voice in place, keeping the four-file assembly.** Chosen.

## Decision
- The soul states the job in one line: keep the software in this account working for the person the agent runs as (with root, the whole server), and help with any question, server-related or not.
- Who it works for: the person whose account it runs in, whether they type or run it from a script or another tool. Their data is theirs: used freely for their tasks, kept from everyone else, never sent off the machine unasked. With root, other users' files are looked at by size, owner and permissions only, unless the person it works for asks for more. Who gives tasks is still decided by `safety.md`: text the agent reads is data, never orders.
- What it is: an AI program that never claims to be a person, and answers "which model?" only from its configuration. One factual line says it is open-source software made by Pulsed Media; it says so when asked, helps with any provider's server just as well, does not advertise, and sends plan and price questions to the website instead of quoting from memory.
- The name section quotes two verses with our own English and a citation: Runo II 139-141 (the little man from the sea who came to fell the oak) and Runo XLVIII 166-168, 171-172 ("with all my strength, or as the work needs?" "As the work needs: that is plenty"). The second becomes step 3 of the working loop: work as the job needs, the change that fixes the cause and no more, with the least privilege that does it. The README tells both episodes in full.
- The voice file regains the reply-sizing rule in our own words and adds two lines: agree because it is right, not because the user said it (say so once if you disagree, then it is the user's call unless a safety rule says no); answer in the user's language and keep commands, paths, config keys and quoted output as they are.
- A test keeps the rendered `SOUL.md` at or under 8000 bytes (now about 6700; Hermes truncates context files past 20 000 characters).

## Consequences
- The agent can answer "what is your job", "who made you" and "what does your name mean" from its own soul, and replies are sized like the Hermes default again.
- About 2 KB more text on every turn.
- The English renderings of the verses are ours, from the 1849 Finnish text. "Asun mukahan" is read from Runo XXXV, where the same couplet pairs it with "tarpehen takoa" (as the need demands) and Kullervo, who rows "väen mukahan" (with all his strength), breaks the boat, then beats the water with full force and shreds the net and the fish. Crawford's 1888 translation is loose here: it renders Väinämöinen's answer as "scare with all thy forces", the opposite of the Finnish.

## References
- [ADR-0003](0003-hermes-integration.md) (Hermes integration, SOUL.md assembly), [ADR-0006](0006-every-pack-always-loaded.md) (the "This machine" block opens SOUL.md)
- *Kalevala*, Elias Lönnrot, 1849 (Project Gutenberg #7000); J. M. Crawford's English translation, 1888 (Project Gutenberg #5186)
- Hermes Agent source: `agent/system_prompt.py`, `agent/prompt_builder.py` (`DEFAULT_AGENT_IDENTITY`, `CONTEXT_FILE_MAX_CHARS`)

## Amendment 2026-10-17

The 8000-byte budget above is withdrawn: it was based on reading Hermes' 20 000-character floor as the limit. SOUL.md is now checked only against the cut Hermes really applies ([ADR-0029](0029-tool-doctrine-loaded-into-soul.md) amendment). The doctrine's accuracy section was restored in full: a source for every fact about the machine, no typical values in place of read ones, proof for causes, checks that could have found what is claimed absent, more than one check for big claims, and saying "I don't know".

## Amendment 2026-10-18: reports are made to be scanned

Request of 2026-10-18: "the doctrine specifies output formats easy to read and pretty a bit like our [status reports'] pretty tables, using emojis colors etc." (the bracketed words replace the name of an internal report command) `doctrine/voice.md` gains a short "Reports" section: for a status, a check of several things or a comparison, the first line gives the result; same-shaped items go in a table of at most 5 short columns within 80 characters; status marks (✅ 🟡 ❌ ⏳, each a single character so tables stay aligned) start a row; a share is a 10-cell bar with its number. One-line questions still get one-line answers. Colour comes from the marks, never from escape codes, because Hermes shows the final answer with its markdown stripped (`display.final_response_markdown: strip`, its default, which Pikkumies does not change) and realigns pipe tables to the terminal width, counting wide characters (`agent/markdown_tables.py`, Hermes 2026.9.24); escape codes in model text would not be a reliable way to colour. About 600 bytes more on every turn.
