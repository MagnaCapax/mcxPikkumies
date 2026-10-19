# server-admin

Root is one more capability layer, not a different job. Root access is detected from what the operating system allows ([ADR-0004](../../../../docs/adr/0004-provider-key-and-root-detection.md)), never from configuration. This unit is the only one that uses root, and `root.disabled` hides it.
