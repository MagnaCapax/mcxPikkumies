<?php
/**
 * Drive writes the drives tool never makes and the agent must not make by hand (ADR-0094, tools/hardware/DOCTRINE.md).
 * Merged into guard-destructive's block list, so a regex engine error blocks like every other block pattern there:
 * - raw `badblocks` with -w in any short-flag cluster, also in a subprocess list (['badblocks','-w',...]);
 * - `hdparm` writes, erases and trims (--write-sector, --security-erase(-enhanced), --security-set-pass,
 *   --make-bad-sector, --trim-sector-ranges);
 * - whole-device discards, formats and sanitizes: blkdiscard, nvme format/sanitize/write-zeroes, sg_format, sg_sanitize,
 *   mkswap on a /dev path; output redirected, tee'd, or copied (cp, install, qemu-img, ddrescue) onto a disk, a partition,
 *   an md, dm, mapper, loop or nbd device, or a /dev/disk/by-* name;
 * - md `repair` written to a sync_action (in one command, or piped into tee): on RAID5/6 it recomputes parity from the data
 *   blocks, so a wrong block becomes the truth. `mdadm --action=repair` is mdadm syntax, refused by GuardDisk.php with
 *   every other mdadm write (ADR-0095), so each command has one owner and its refusal names the right tool.
 * Left alone: `badblocks -n`, `hdparm -I`, `smartctl -t`, the scrub (`check`), reading a device. Filesystem formats
 * (mkfs, mke2fs) are guard-destructive's own rule. A verb or flag hidden in a variable is out of sight, as everywhere.
 * The drives tool's own `selftest` (it loads the disk) is asked as a load.
 */
function guardDrivePatterns()
{
    $disk = '\/dev\/((sd|hd|vd|xvd|nvme|md|loop|nbd|mmcblk|dm-|bcache)[a-z0-9]*|md\/\S+|mapper\/\S+|disk\/by-[a-z]+\/\S+)'; // a disk, partition, md, dm or loop device, or a by-* name
    return [
        '/\bbadblocks\b[^;&|\n]*[\s,\[(]-[a-zA-Z0-9]*w/' => 'a raw badblocks write test overwrites the disk; the drives tool runs the non-destructive pass (verify)',
        '/\bhdparm\b[^;&|\n]*--(write-sector|security-erase|security-set-pass|make-bad-sector|trim-sector-ranges|dco-restore)\b/'
            => 'a raw write or erase on a drive; on an array member it corrupts data behind the array\'s back',
        '/\bblkdiscard\b/' => 'a whole-device discard erases the data',
        '/\bnvme\b[^;&|\n]*\s(format|sanitize|write-zeroes)\b|\bsg_(format|sanitize)\b/' => 'a drive format or sanitize erases the data',
        "/\\bmkswap\\b[^;&|\\n]*\\s$disk/" => 'mkswap on a device overwrites its start, making it swap',
        "/(>|\\btee\\b[^;&|\\n]*\\s)\\s*$disk\\b|\\b(cp|install|qemu-img|ddrescue)\\b[^;&|\\n]*\\s$disk\\s*($|[;&|])/" => 'output written onto a disk overwrites its data',
        '/\b(echo|printf)\s+(-\S+\s+)*["\']?repair\b["\']?\s*(>|\|\s*(sudo\s+)?tee\b)[^;&\n]*\bsync_action\b/'
            => 'md repair rewrites parity from the data blocks and can make a wrong block the truth; a scrub is check',
    ];
}

/** [regex => [kind, why]] for guard-destructive's approve list; $v is its separator pattern. */
function guardDriveApproves($v)
{
    return ["/\\bphp\\b.*{$v}selftest\\b/s" => ['load', 'a drive self-test through a Pikkumies tool']];
}
