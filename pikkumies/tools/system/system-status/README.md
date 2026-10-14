# system-status

What the machine looks like to the agent: the operating system, which seedbox host it is (Pulsed Media's PMSS is checked first), whether root is available, which of the user's own apps are running, and how much CPU, memory and home disk there is. The agent runs it before diagnosing anything.

It reads `/proc`, `/etc/os-release` and the user's own processes only. It never looks at other users' processes.
