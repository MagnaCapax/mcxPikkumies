# deep-research

Hermes has the parts for research but no loop that ties them together (ADR-0021). This unit is that loop, written as a procedure the agent follows: search the memory on this machine first, plan the sub-questions, search and read the web, keep every source in a numbered ledger, give wide sub-questions to subagents that must bring back exact quotes, weigh the sources by trust, answer with citations, and save the result to `memory/research/` so the next search finds it.

Nothing here fetches pages itself, and nothing leaves the machine unless the user configured a web backend. Pages that need a browser are not readable out of the box, because runtime downloads are off; the user can turn them on with `hermes.lazyInstalls`.
