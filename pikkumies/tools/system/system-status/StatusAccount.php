<?php
/**
 * StatusAccount: the record of `system-status account` (ADR-0058). This account's own figures in one live record, from
 * PMSS `~/bin/userShowLimits --json` read through Detect::userShowLimitsData (which drops the account and server names,
 * ADR-0023): disk quota, memory, upload traffic, processes, CPU quota, versions and bonus, plus the account's own
 * running apps. Metrics are picked by the names PMSS writes (etc/skel/bin/userShowLimits); a name not found is null,
 * never guessed. The keys never change: off PMSS, or when the tool fails, the figures are null. The server section is
 * left out (it is every account's), and nothing names the account or the server: the panel address is a template.
 */
class StatusAccount
{
    /** Record group => key => [section, metric, field]. */
    const MAP = [
        'disk' => [
            'used' => ['disk', 'blocks used', 'value'],
            'soft' => ['disk', 'blocks soft', 'value'],
            'hard' => ['disk', 'blocks hard', 'value'],
            'plan' => ['disk', 'quota configured', 'value'],
            'burst' => ['disk', 'quotaBurst configured', 'value'],
            'files' => ['disk', 'files used', 'value'],
            'files_soft' => ['disk', 'files soft', 'value'],
            'files_hard' => ['disk', 'files hard', 'value'],
            'age_s' => ['disk', 'snapshot age', 'value'],
        ],
        'memory' => [
            'used' => ['memory', 'memory', 'value'],
            'limit' => ['memory', 'memory', 'limit'],
            'pressure_pct' => ['memory', 'memory pressure', 'value'],
            'status' => ['memory', 'memory status', 'value'],
            'oom_kills' => ['memory', 'OOM kills', 'value'],
        ],
        'traffic' => [
            'upload_30d' => ['traffic', 'upload past 30 days', 'value'],
            'limit' => ['traffic', 'upload past 30 days', 'limit'],
            'inbound_30d' => ['traffic', 'inbound past 30 days', 'value'],
            'bonus' => ['traffic', 'bonus traffic', 'value'],
            'cap_mbit' => ['traffic', 'trafficCapMbit configured', 'value'],
        ],
        'processes' => [
            'used' => ['processes', 'processes', 'value'],
            'limit' => ['processes', 'processes', 'limit'],
        ],
        'cpu' => ['quota_pct' => ['cpu', 'CPU quota enforced', 'value']],
        'versions' => [
            'pmss' => ['account', 'PMSS version', 'value'],
            'debian' => ['account', 'Debian version', 'value'],
        ],
        'bonus' => [
            'value' => ['account', 'bonus', 'value'],
            'unit' => ['account', 'bonus', 'unit'],
        ],
        'docker' => ['enabled' => ['account', 'Docker enabled', 'value']],
    ];

    /** Used against limit: [group, used key, limit key, left key or null]. */
    const SHARES = [['disk', 'used', 'soft', 'left'], ['memory', 'used', 'limit', null], ['traffic', 'upload_30d', 'limit', 'left']];

    /**
     * Counters PMSS itself calls unlimited when the value is there and the limit is not (userShowLimits human view,
     * :301): group => [used key, limit key].
     */
    const UNLIMITED = ['memory' => ['used', 'limit'], 'processes' => ['used', 'limit'], 'traffic' => ['upload_30d', 'limit']];

    const PANEL = 'https://<this server>/user-<you>/ (Info tab: info.php, the user\'s own view of these figures)';

    /**
     * The record. $usl: Detect::userShowLimitsData() or null; $family: the cached host family; $apps: the account's own
     * running apps, read now (Detect::runningApps).
     */
    public static function record($usl, $family, array $apps)
    {
        $sections = is_array($usl) && isset($usl['sections']) && is_array($usl['sections']) ? $usl['sections'] : null;
        $out = ['family' => (string) $family, 'source' => $sections !== null ? 'userShowLimits' : null,
            'at' => $sections !== null && is_int($usl['generated_at']) ? gmdate('c', $usl['generated_at']) : null];
        foreach (self::MAP as $group => $keys) {
            foreach ($keys as $key => $where) {
                $v = $sections === null ? null : Detect::userShowLimitsMetric($sections, $where[0], $where[1], $where[2]);
                $out[$group][$key] = is_bool($v) || is_int($v) || is_float($v) ? $v : (is_string($v) ? substr(preg_replace('/[\x00-\x1f\x7f]/', '', $v), 0, 40) : null);
            }
        }
        foreach (self::SHARES as $s) {
            list($group, $used, $limit, $left) = $s;
            $u = $out[$group][$used];
            $l = $out[$group][$limit];
            $known = is_numeric($u) && is_numeric($l) && $l > 0;
            $out[$group]['used_pct'] = $known ? round(100 * $u / $l, 1) : null;
            if ($left !== null) {
                $out[$group][$left] = $known ? max(0, $l - $u) : null;
            }
        }
        $out['unlimited'] = [];
        foreach (self::UNLIMITED as $group => $pair) {
            if ($out[$group][$pair[0]] !== null && $out[$group][$pair[1]] === null) {
                $out['unlimited'][] = "$group.{$pair[1]}";
            }
        }
        $out['apps'] = array_values($apps);
        $out['panel'] = $sections !== null ? self::PANEL : null;
        $out['units'] = 'bytes; _pct percent; age_s seconds since the quota snapshot; cap_mbit Mbit/s';
        return $out;
    }
}
