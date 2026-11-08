# SOP: A session that freezes, blanks or shows nothing through a dock

Load when: the screen froze on its last frame, went black, or shows nothing when an external dock, hub or adapter is connected — and especially when the machine still answers.

## 1. Machine alive, or session dead

Reach the machine over the network and read the kernel log over a bounded window. A frozen picture with a working shell, a healthy init system and a quiet kernel log is a display-path or compositor fault. It is not a dead machine, and never a reason to cut power.

## 2. Display path or compositor

Switch to another virtual terminal. A frozen image that survives the switch is stuck in the display path, not a hung application.

## 3. Read the kernel log as a shape, not a search

Count **distinct message templates** over the window and rank them by rate. A storm of one template at a steady interval is the finding. Do not start from a list of message names you expect — that presupposes the diagnosis. Count; never `head`, or an absence you report is only the absence from the lines you looked at.

## 4. Panel self-refresh, when the panel is internal

Read the driver's own self-refresh state from the kernel's debug interface. The signature is a frozen frame, a live shell and **no driver errors logged**. The bits that disable it come from the header that defines them **at the running kernel's version** — never from recall — and changing them is a kernel boot parameter, so follow the boot-parameter section of `upgrade-a-host-and-reboot.md`.

## 5. Recover before diagnosing — after checking your own path

Disconnecting the newest external device is the right first action, and instant recovery is itself evidence. **Before you disconnect anything, confirm this session is not running through it:** a dock that carries the network is your own management path, and unplugging it ends your access to the machine you were sent to fix.

## 6. Which session type, and does the fault follow it

Name the session type the user is in. A device that fails only under the newer display protocol and works under the older one is a driver-side limitation in the newer path, not a user configuration fault.

## 7. Falsify the configuration hypothesis

With the compositor's own stored output layout removed and its defaults in force, does it still fail? If it does, the user's configuration was never the cause. "It must be the inherited config" asserted before this check is `[coincidence-as-cause]`.

## 8. A control tool that rejects every change is a defect

Errors that mirror an output onto itself, or place it at an integer minimum, mean the tool's model of the layout is broken. The falsifiable test: does the same operation succeed with only one output enabled? If it does, stop iterating arguments and recover by disconnecting the output or restarting the session.

## 9. Never edit a running compositor's live configuration file

It holds the file in memory and rewrites it; your edit disappears. Write it while the compositor is down, or drive it through its own interface.

## 10. Match outputs by identity, never by connector name

Read the monitor's own identity from its extended display data. Behind a hub the same monitor appears under a different connector name, so a name-based change lands on the wrong screen or nowhere.

## 11. A new monitor may default to its highest refresh rate

On a bandwidth-limited link that is exactly the mode that cannot work. Try a lower mode before declaring the hardware broken.

## 12. Peripheral firmware, when the changelog claims this symptom

Read the vendor's changelog and ask whether the version claims to fix *this* fault. Verify the published checksum and the package signature. Prefer the platform's own updater when it already exposes the device. The device disappears for several minutes — never run it while you depend on that device's network, disk or display. Re-read the version from the device, not from the installer's success line.

## 13. Record what recovered it

The counts before and after, the boot parameter and its revert, and — when a firmware update changed the fault's rate without removing it — that rate change, as a finding, not as a fix.

## Why this SOP exists

An agent working this fault once blamed the migrated high-refresh monitor configuration and link bandwidth. A fully default configuration failed identically, which falsified it; the defect was upstream and open. Running step 7 before forming the hypothesis costs one reboot and saves a day.
