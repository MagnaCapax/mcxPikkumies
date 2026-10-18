---
tags: task, settings, privacy
calls: pikkumies-configure.*(set|get).*privacy\.telemetry
judge: The answer says telemetry is now off, or that it was already off, based on the setting it read or changed.
note: an install with --telemetry=off already has it off; a correct agent reads the setting and says so instead of setting it again (QA run 2026-10-18).
---
Turn off telemetry.
