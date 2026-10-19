# ADR-0078: Changing the login of the user's own apps: the password goes to the file first

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted

## Context
The request of 2026-10-19: "tools for all the ARR apps to change passwords and configs with the guidance, promtps etc.", with the example case "user used install media stack and one of the apps cannot login". Until now the tools only read the apps ([ADR-0019](0019-hosts-and-credentials.md): never weaken a login, never print a credential).

Facts this rests on, checked 2026-10-19:
- The PMSS media-stack installer (`etc/skel/install-media-stack.sh`) keeps each app's login in `~/.media-stack-credentials.txt` (`KEY = value` lines, mode 600) and sets it through the app's own API with the app's own key (`PUT config/host`, `servarr_auth_seed`). The key a tool already uses can therefore change the login; no privilege is added.
- Sonarr, Radarr, Lidarr, Prowlarr and Whisparr refuse an `updateMechanism` of `script` with no script path over the API, so the installer sends `builtIn` and then sets the config.xml tag back to `Script`. On PMSS an app's own updater has broken the .NET runtime the other media apps share (PMSS#747).
- A user who cannot log in usually has one of a short list of causes (auth never set up, no user, a placeholder in the file, a wrong UrlBase, a non-loopback listener, an invalid config.xml), each with one fix.

## Options Considered
- **Tell the user to rerun the installer.** Fixes some causes, not a forgotten password, and makes the user do the agent's work.
- **Reset the app's login to none, or edit config.xml directly.** Opens the app on the shared loopback (ADR-0019) and fights the installer.
- **Verbs per Servarr app that find the cause, then set a new login or repair the host settings through the app's API, with the password written to the installer's file before the app is changed.**

## Decision
- Each Servarr app unit has `login-check` (finds the cause, names the one next step), `set-login`, `repair` and `config` (README "Servarr apps: login and settings"). Readarr keeps its read verbs only.
- `set-login` makes a 24-character random password in-process, writes it to `~/.media-stack-credentials.txt` first (under a lock, other lines kept, the old lines held to undo), then sets it in the app (forms login, required). A refused change puts the file back. The result is checked by a real login: the API without the key must answer 401/403 and the new pair must log in.
- The password is never printed, logged or put on a command line. The user is told where it is (the file only they can read), not what it is.
- `repair` only puts back the PMSS-safe host settings (listen on 127.0.0.1, forms login required, the installer's proxy UrlBase) through the API. An invalid config.xml is copied aside and the hint names the installer rerun; the tool never starts an app from a template of its own.
- `config set` changes one field of the app's own settings and reads it back; the login, the listen address, the user name, password, API key, port, UrlBase, update settings and file permissions are never set there.
- After a host change on an installer-managed app, the config.xml tag `UpdateMechanism` is set back to `Script` (only in a regular file this account owns, never through a link, with the file's own mode) and the app is restarted. When that write or restart fails, the call ends `ok:false` although the login change was made, and the hint names the fix.
- `~/.media-stack-credentials.txt` has one parser and writer (`lib/MediaStackCredentials.php`), shared with the Jellyfin login.
- Every change appends one line to `local/logs/arr/YYYYMMDD.jsonl`: verb, app, outcome, never a value.

## Consequences
- "An app will not let me log in" is answered and fixed without asking the user anything, and the user keeps one file with every app's login, as the installer left it.
- A password the user chose is replaced by a random one when `set-login` runs; the user reads the new one from the file.
- The API key remains the one secret that can change a login; it is never reset (`ResetApiKey` is on the never-sent list).
- Jellyfin, autobrr, Jackett and Bazarr follow in a later change under the same rules.

## References
- [ADR-0019](0019-hosts-and-credentials.md) (this ADR adds changing verbs under its rules), [ADR-0015](0015-tool-units-one-skill-per-tool.md), [ADR-0034](0034-containers-category.md)
- PMSS `etc/skel/install-media-stack.sh`; PMSS#747, PMSS#858

## Amendment 2026-10-19: Jellyfin, autobrr, Bazarr and Jackett
- Context: the Decision above left these four for a later change. Each has its own API, so each was checked against its own upstream source for a way to change the login with what the tool already holds.
- Jellyfin (`tools/media-servers/jellyfin`): `login-check`, `set-login` and `config`. `set-login` signs in with the pair in `~/.media-stack-credentials.txt` (`JELLYFIN_USERNAME`, `JELLYFIN_PASSWORD`, the installer's keys), writes the new one to the file, then calls Jellyfin's own `POST /Users/Password` with the current one (`UserController.cs:270-319` at tag v12.2), and checks a real login with the new pair. Jellyfin signs out the user's other sessions on a change; the record says so. Without a usable current one in the file there is no set-login: Jellyfin asks for it, and the reset paths that skip it (an administrator key, the Forgot Password PIN) are the user's own steps. `config` reads and replaces `/System/Configuration` one field at a time with the rules of `config` above; the login methods, the startup wizard flag, the network, the data folders and internal flags are never set, and only these verbs get the password and configuration routes on their allowlist (`JellyfinLogin::ALLOW`).
- Bazarr: `login-check`, `set-login` and `config` through `GET`/`POST system/settings`, which accept the API key (`api/system/settings.py`, `api/utils.py:100-115` at bfe4593b), checked by `POST system/account`. The keys in the file are `BAZARR_USERNAME` and `BAZARR_PASSWORD` (the installer does not manage Bazarr; the file and its convention are the same).
- autobrr: `login-check` and `set-login`. No route the key may call changes the password, so `set-login` runs autobrr's own `autobrrctl change-password` (`cmd/autobrrctl/main.go:173-235` at c6b78bdd) as an argument list with the new one on standard input, as the installer runs `create-user`; never the installer's `--secure-app` on an autobrr that has a user, which writes its placeholder over the file.
- Jackett: `login-check` only. Its admin password route needs a dashboard sign-in, never the key (`ServerConfigurationController.cs:50-64`, `Startup.cs:51-59, 83-86` at cbf1fa3f), so the skill names the user's step.
- The guard (`guard-destructive`) blocks every terminal command and file tool call that names `~/.media-stack-credentials.txt`, holds a glob that expands to it, or searches content recursively from the home folder without excluding it, as it blocks `local/secrets/`: the tools read the file in their own PHP, and the agent points the user to it. A name built in a variable stays out of its sight (a safety prompt, AGENTS.md).
- A change call that gets no answer never loses the new password: Jellyfin and Bazarr try the new login (Jellyfin then the old one) and keep the line that works, or both lines (`<APP>_PASSWORD_PREVIOUS` for the old one) when neither can be checked.
- Consequence: the four apps answer "I cannot log in" the same way the Servarr apps do, without a question to the user except where the app itself needs the user (Jackett's dashboard, a Jellyfin login nobody knows).
