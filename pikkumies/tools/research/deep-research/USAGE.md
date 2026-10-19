# deep-research

A procedure, not a tool: how to research a question in depth with what is already here. It uses the memory unit, Hermes' `web_search` and `web_extract`, Hermes' bundled `grounded-citations` skill for the source ledger, and subagents (`delegate_task`).

```sh
php ~/pikkumies/tools/memory/pikkumies/pikkumies.php search "<question words>"     # memory first
# sources: Hermes' bundled grounded-citations skill (in $HERMES_HOME/skills, i.e. pikkumies/local/hermes/skills);
# follow its SKILL.md for the ledger commands (add, list, verify), which run with Hermes' own python
php ~/pikkumies/tools/memory/pikkumies/pikkumies.php save --kind research --topic <topic> \
    --title "<the question>" --sources "<url 1; url 2>" --body-file /tmp/x/answer.md
```

Changes: saves one research note to `memory/research/` at the end. Web search works only when the user configured a backend ([ADR-0021](../../../../docs/adr/0021-web-access-off-unless-configured.md)).
