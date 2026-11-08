# SOP: Compare a desktop's settings against the defaults it actually shipped with

Load when: the user says their shortcuts or settings are wrong, something keeps turning itself on, a panel or layout did not come back, or they ask to "reset" a desktop.

## 1. Never reset from memory

The authoritative default is installed on the machine. **Find the mechanism this machine uses and name what you found** — never assume the one you saw last. Two families are common: an entry's default stored beside the entry in the desktop's own configuration, and a settings-schema registry installed by the packages.

## 2. The default is a stack, not a value

Read the layers in order: the upstream or stored default, then any **distributor override** (a package that ships a replacement for some of those defaults), then the user's own value. **Say which layer you compared against.** An audit that reads only the top-level default and compares it to the user's value reports a false positive wherever an override exists — in the one report a confused user is meant to trust.

## 3. Compare only the keys the user's file sets

Report the differences and the total count. Do not list the matches; the list is the deliverable and the matches bury it.

## 4. Configuration from a previous major version

A file the current version no longer reads is a finding in itself: its presence is accumulated debt and its content is stale advice.

## 5. Autostart entries

Programs that no longer exist, and entries that pin a profile wrong for the machine's class — a desktop power profile pinned on a laptop is the recorded example.

## 6. Stored connector names

Names from an older display-server era are stale, and they explain "my panel settings did not apply".

## 7. Report, then change

When the ask is "what is non-default?", the report **is** the deliverable. When the ask is "fix my settings", apply the list without asking: one item at a time, each file copied first (Safety, Changing data). Producing a list and stopping is `[plan-instead-of-doing]`.

## 8. Verify the effective value

After the change, read the value back through the component's own query — not the file. A file that holds the right number and a component that ignores it is not a fix.

## 9. No trustworthy mechanism

Say so, and report the current values **without asserting which are defaults**. An invented default is worse than an unlabelled value.

## Why this is prose and not a tool

The comparison is mechanical, but discovering the mechanism is not. A tool would have to encode one desktop family's file layout, which this repository's provider-neutral rule forbids in shipped text. Revisit only if a second family is ever supported.
