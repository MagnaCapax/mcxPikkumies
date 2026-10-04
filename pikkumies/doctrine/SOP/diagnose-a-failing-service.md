# SOP: Diagnose a failing service

1. **Snapshot.** Run the system-status skill. Note free disk, memory, load, and whether the service's process is running.
2. **Search memory** for the service name and the symptom.
3. **Read the logs** for the service (its own log file, or `journalctl --user -u <name>` when it runs under systemd). Limit output (`tail -n 100`).
4. **Name the cause** from evidence: a log line, a full disk, a missing file, a port already in use. If you cannot name it, keep looking; do not guess.
5. **Back up** any config file before editing it.
6. **Make the smallest fix**, then restart only that service.
7. **Verify** the same way the problem was seen (the web UI loads, the port answers, the process stays up for a minute).
8. **Save a lesson** if the cause was not obvious.
