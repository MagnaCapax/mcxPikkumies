<?php
/**
 * FfmpegHw: the `hw` verb (ADR-0045). Whether a GPU render node is usable by this account: does
 * /dev/dri/renderD128 exist, is it readable/writable by this account, what this account's own
 * groups are, and the device's group. Names the exact gap instead of assuming a group name (a
 * provider may call it "render", "video", or something else entirely). `vainfo`, when installed,
 * is read for its own summary line but is never required.
 */
class FfmpegHw
{
    const DEVICE = '/dev/dri/renderD128';

    public static function record($device = null)
    {
        $device = $device !== null ? $device : self::DEVICE;
        $exists = @file_exists($device);
        $rec = ['device' => $device, 'exists' => $exists, 'readable' => false, 'writable' => false,
            'device_group' => null, 'account_groups' => self::accountGroups(), 'gap' => null, 'vainfo' => null];
        if (!$exists) {
            $rec['gap'] = "$device does not exist on this machine; no GPU render node to use";
            return $rec;
        }
        $st = @stat($device);
        $rec['readable'] = (bool) @is_readable($device);
        $rec['writable'] = (bool) @is_writable($device);
        $groupName = $st !== false ? self::groupName((int) $st['gid']) : null;
        $rec['device_group'] = $groupName !== null ? $groupName : ($st !== false ? (string) $st['gid'] : null);
        if ($rec['readable'] && $rec['writable']) {
            $rec['gap'] = null;
        } elseif ($groupName !== null && !in_array($groupName, $rec['account_groups'], true)) {
            $rec['gap'] = "the device belongs to group $groupName and this account is not in it; ask your provider";
        } else {
            $rec['gap'] = "$device is not readable/writable by this account (owner or mode); ask your provider";
        }
        $rec['vainfo'] = self::vainfo();
        return $rec;
    }

    /** This account's own group names (not just its primary group), from posix_getgroups(). */
    public static function accountGroups()
    {
        if (!function_exists('posix_getgroups')) {
            return [];
        }
        $names = [];
        foreach ((array) @posix_getgroups() as $gid) {
            $name = self::groupName((int) $gid);
            if ($name !== null) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /** A group's name from its gid, or null when it cannot be looked up (posix_getgrgid missing or unknown gid). */
    public static function groupName($gid)
    {
        if (!function_exists('posix_getgrgid')) {
            return null;
        }
        $g = @posix_getgrgid($gid);
        return is_array($g) && isset($g['name']) ? $g['name'] : null;
    }

    /** The first line of `vainfo`'s driver summary, or null when it is not installed or fails. */
    private static function vainfo()
    {
        $bin = ToolProc::which('vainfo');
        if ($bin === null) {
            return null;
        }
        $r = ToolProc::run([$bin]);
        foreach (explode("\n", $r['out'] . $r['err']) as $line) {
            if (stripos($line, 'driver version') !== false || stripos($line, 'vainfo: VA-API version') !== false) {
                return trim($line);
            }
        }
        return null;
    }
}
