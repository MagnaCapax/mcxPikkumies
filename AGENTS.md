# mcxPikkumies: notes for contributors and coding agents

## What this is

A lightweight helper agent that runs on the server it manages: as the user by default, with root as an optional capability layer. Design stage; see README.md for the intended shape.

## Rules

- **License:** Apache-2.0. New files carry no per-file headers; LICENSE and NOTICE cover the project.
- **Provider-agnostic.** Never name which model provider a deployment uses: no vendor, hostname, model id or product name in code, docs, tests or commit messages. Provider and keys live only in the deployer's config. Naming an API format ("OpenAI-compatible") is fine.
- **Privilege comes from the OS, never from the agent's own config.** Root capability is detected from the system (uid, sudo, capabilities) deterministically. Anything that must be enforced is enforced by the OS or by the server the agent talks to, never by a setting the agent's user can edit.
- **Privacy.** The agent works on its user's data at that user's request. It never sends anything off the machine that the user did not ask it to.
- **Keep it small.** Each feature has to earn its lines. If the OS, the web server or an existing tool already does it, leave it there.
