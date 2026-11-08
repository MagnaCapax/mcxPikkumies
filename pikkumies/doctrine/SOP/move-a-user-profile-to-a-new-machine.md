# SOP: Move a user's apps and profile to a new machine

Load when: a user is moving between machines, or an app on the new machine will not start, opens an empty profile, or says the profile is "in use by another computer".

## 0. Which machine, which direction

Confirm which machine you are on and which way the move goes. You assist the destination. You never orchestrate a delete on a source you cannot see.

## 1. Copy, never overwrite

Merge into an existing home. Never remove the source until the destination has been verified by launching each application.

## 2. Pointer versus data

Some packaging systems keep a `current` symbolic link in front of a per-revision directory. A naive copy leaves the pointer naming a revision that does not exist on the new machine, while the real data sits in a different revision's directory. Resolve the pointer, copy the newest data directory onto the installed revision, repoint the pointer — **before the application's first launch**. Otherwise the user opens an empty profile and reasonably concludes their data is gone.

## 3. Stale single-instance locks

Many applications write a lock file embedding the previous machine's name. With the application closed, remove only those lock files.

## 4. Ownership and modes

Verify the copied tree against the target account. A tree that is right in size and wrong in owner is a permission fault the user will report as data loss.

## 5. Do not carry over what must be regenerated

Device databases, socket paths, and anything embedding an absolute path into the old home.

## 6. Launch and confirm before deleting anything

Each application, once, on the destination, opening the data the user expects. Only then is the source safe to remove, and only if the user's own request covers removing it.

## 7. Prefer the application's own import

Where the app has an import or export path, use it instead of a file copy: it knows its own layout, and a file copy does not.
