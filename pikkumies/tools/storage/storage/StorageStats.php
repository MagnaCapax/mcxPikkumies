<?php
/**
 * StorageStats: the `stats` record. No scan: the user's quota from lib/Quota.php (live `quota -s`,
 * else PMSS's ~/.quota), the filesystem as a second fact, and why a quota figure is missing or
 * may be stale. A non-root `quota -s` that prints "Cannot open quotafile ... Permission denied"
 * means kernel quota is off on the whole filesystem: usage figures freeze and ~/.quota keeps being
 * rewritten with them (quota-tools init_io; see this unit's README.md).
 */
class StorageStats
{
    const HINTS = [
        'ok'          => '',
        'over_soft'   => 'over the plan quota: writes still work until the hard limit or the grace time; free space through the owning'
            . ' tools (torrent client remove --with-data)',
        'over_hard'   => 'at the hard limit: new writes fail, torrent clients and the panel stop working; free space through the owning'
            . ' tools',
        'off_on_host' => 'quota is switched off on this server, so the figures may be old; report it as a server problem for support (this is not something to fix'
            . ' in the account)',
        'no_data'     => 'the provider has not written quota figures for this account yet (~/.quota holds a placeholder); report it for support'
            . ' if it stays so',
        'unavailable' => 'no quota command here and no ~/.quota file; say that the plan\'s space is not known here (the provider\'s client area shows it)',
        'none'        => 'no quota is set for this account on this filesystem',
        'unknown'     => 'no quota figure could be read; run `quota -s` in a terminal to see what it says',
    ];

    public static function record($home)
    {
        $f = Quota::fields($home);
        $live = $f['quota_source'] === 'quota -s' ? 'ok' : self::liveProblem();
        if ($f['quota_source'] === null) {
            $state = $live === 'off_on_host' || $live === 'none' ? $live : (self::placeholder($home) ? 'no_data' : ($live === 'missing'
                ? 'unavailable' : 'unknown'));
        } elseif ($live === 'off_on_host') {
            $state = 'off_on_host';
        } elseif ($f['quota_used_bytes'] >= $f['quota_hard_bytes'] && $f['quota_hard_bytes'] > 0) {
            $state = 'over_hard';
        } else {
            $state = $f['over_quota'] ? 'over_soft' : 'ok';
        }
        $total = @disk_total_space($home);
        $r = $f + [
            'quota_state' => $state,
            'quota_used_percent' => $f['quota_limit_bytes'] ? round(100 * $f['quota_used_bytes'] / $f['quota_limit_bytes'], 1) : null,
            'live_quota' => $live, // ok, missing (no quota command), off_on_host, none, failed
            'fs_total_bytes' => $total === false ? null : (int) $total,
            // ENOSPC: the whole filesystem is full, which the user sees exactly like a full quota.
            'fs_full' => $f['fs_free_bytes'] === null ? null : $f['fs_free_bytes'] < 1048576,
            'home' => $home,
        ];
        $hint = self::HINTS[$state];
        if ($r['fs_full']) {
            $hint = trim('the server\'s filesystem is full (not this account\'s quota): tell support. ' . $hint);
        }
        if ($hint !== '') {
            $r['hint'] = $hint;
        }
        return $r;
    }

    /** What the live `quota -s` says when it gave no figure: missing, off_on_host, none or failed. */
    public static function liveProblem()
    {
        if (ToolProc::which('quota') === null || ToolProc::which('timeout') === null) {
            return 'missing';
        }
        $r = ToolProc::run(['timeout', '5', 'quota', '-s']);
        $text = $r['out'] . "\n" . $r['err'];
        if (stripos($text, 'Cannot open quotafile') !== false) {
            return 'off_on_host';
        }
        return preg_match('/\bnone\b/', $text) ? 'none' : 'failed';
    }

    /** PMSS writes "/dev/null 0K 0K 0K" for uid 0 when its own quota call failed (updateQuotas.php:116-124). */
    private static function placeholder($home)
    {
        $file = "$home/.quota";
        $text = is_file($file) && !is_link($file) && filesize($file) < 65536 ? (string) @file_get_contents($file) : '';
        return strpos($text, '(uid 0)') !== false || strpos($text, '/dev/null') !== false;
    }
}
