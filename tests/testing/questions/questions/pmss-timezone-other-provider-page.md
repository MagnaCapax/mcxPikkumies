---
tags: source:whatbox-wiki, docs, cross-provider
found: tier:3
family: pmss
first_call: tools/memory/pikkumies/pikkumies\.php.* search
contains: TZ
judge: says the instructions come from another provider's documentation and may not apply exactly on this seedbox
source: Changing Your Bash Timezone — Whatbox wiki (whatbox-wiki) — https://whatbox.ca/wiki/Changing_Your_Bash_Timezone
note: neither pm-wiki nor pm-kb mentions a timezone (grep of both downloaded copies, 2026-10-19), so on a PMSS host the page is tier 3, another provider's; it was among the first 38 pages a 40-request refresh of whatbox-wiki downloaded. The stale guard keeps the question out if it is not on disk.
---
When I run commands over SSH on my seedbox, the times are shown in the server's timezone. How can I make the command line show my own local time instead?
