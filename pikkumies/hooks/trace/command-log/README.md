# command-log

Runs after each tool call (`post_tool_call`) and writes one line for every shell command the agent ran (ADR-0044), so a session can be traced later: what ran, where, when, and how it ended.

- **Where:** `local/logs/commands/YYYYMMDD.jsonl`, one file per UTC day, next to the hook log `local/logs/hooks/`. The directory is 0700 and the files 0600: only the account can read them.
- **What one line holds:** `ts`, `session_id`, `tool_call_id`, `cwd`, `tool`, `command`, `background`, `exit_code`, `status`, `duration_ms`, `output_bytes`, and `error` or `truncated` when they apply. The output itself is never logged.
- **Secrets:** the command is cleaned the way Pikkumies cleans everything it prints (`Cli::cleanString`: URL passwords and tokens) and also loses the value of password, token, key and secret options, `Authorization`/`Cookie` headers, `*_KEY=`/`*_TOKEN=`-style variables and `mysql -p<password>`. A secret in another shape can still land in the line; the files stay on this machine.
- **How long:** as long as the session history, `sessions.retentionDays` (0 = forever, the default). A new day's file removes files older than that.
- **Tracing a session:** `grep '"session_id":"<id>"' ~/pikkumies/local/logs/commands/*.jsonl`, or one day's file read as JSON lines.

Only the `terminal` tool is logged. It fails open: a log that cannot be written never stops a command.
