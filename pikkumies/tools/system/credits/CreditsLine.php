<?php
/**
 * CreditsLine: the credits line's record, fetched and cached (ADR-0040). Used by `credits line` (the tmux status
 * line runs it every minute) and by `pikkumies` itself for the start banner, as a library call.
 *
 * Two read-only requests, GET {endpoint}/usage and GET {endpoint}/models, each with curl's 2-second timeout (the
 * name lookup in Http comes before it). Under local/state/credits/ (0700, files 0600): the record for 60 s, so
 * several windows cost one request a minute; the model rates for a day; a 404 on /usage (no usage route: another
 * provider) for a day; any other failed answer, of either route, for 10 minutes. Each cache is for one endpoint and
 * one key, told apart by a short sha256 of the key. Any failure is null: the caller prints nothing. The key goes only
 * into the request's Authorization header; it is never printed and never written here.
 */
require_once __DIR__ . '/CreditsFormat.php';

class CreditsLine
{
    const LINE_TTL = 60;
    const RATES_TTL = 86400;
    const NO_ROUTE_TTL = 86400;
    const FAIL_TTL = 600;
    const TIMEOUT = 2;
    /** The longest usage window the API gives (days); the newest grant's day is found inside it. */
    const DAYS = 400;

    public static function stateDir()
    {
        return Config::localDir() . '/state/credits';
    }

    /** The line for $mode ('utf8', 'ascii', 'tmux'), or '' on any failure. Never throws. */
    public static function text(array $prov, $dir, $mode, $utf8 = true)
    {
        try {
            $r = self::record($prov, $dir);
            return $r === null ? '' : CreditsFormat::render($r, $mode, $utf8);
        } catch (Throwable $e) {
            return '';
        }
    }

    /** The record (CreditsFormat::record), cached; null when there is no key or endpoint, no usage route, or any error. */
    public static function record(array $prov, $dir, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        if ($prov['key'] === '' || $prov['endpoint'] === '') {
            return null;
        }
        $cached = self::cached($prov, $dir, self::LINE_TTL, $now);
        if ($cached !== null) {
            return $cached;
        }
        $failed = self::read("$dir/usage-failed.json");
        if (self::fresh($failed, $prov, isset($failed['code']) && $failed['code'] === 404 ? self::NO_ROUTE_TTL : self::FAIL_TTL, $now)) {
            return null;
        }
        // Up to 10 grants: the newest can be a refund (negative); the pot is the newest positive one.
        $r = Provider::routeAnswer($prov, '/usage?days=' . self::DAYS . '&grants=10', self::TIMEOUT, 1048576);
        if ($r === null) {
            return null;
        }
        $usage = $r['code'] === 200 ? json_decode($r['body'], true) : null;
        if (!is_array($usage) || !isset($usage['balance']) || !is_int($usage['balance'])) {
            self::write($dir, 'usage-failed.json', self::stamp($prov, $now) + ['code' => $r['code']]); // 0: no answer
            return null;
        }
        $record = CreditsFormat::record($usage, self::rates($prov, $dir, $now), $prov['model'], gmdate('Y-m-d', $now));
        self::write($dir, 'line.json', self::stamp($prov, $now) + ['model' => $prov['model'], 'record' => $record]);
        return $record;
    }

    /**
     * The cached record when line.json is for this endpoint, key and model and younger than $maxAge seconds, else null.
     * Never makes a request: the low-balance hook reads the line's cache with it (ADR-0050).
     */
    public static function cached(array $prov, $dir, $maxAge, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $line = self::read("$dir/line.json");
        return self::fresh($line, $prov, $maxAge, $now) && isset($line['model'], $line['record']) && $line['model'] === $prov['model']
            && is_array($line['record']) ? $line['record'] : null;
    }

    /** [['id', 'input', 'output'], ...] in the endpoint's order, cached a day; [] when the rates cannot be had (no token figure then), cached 10 minutes. */
    public static function rates(array $prov, $dir, $now)
    {
        $cache = self::read("$dir/rates.json");
        if (isset($cache['rates']) && is_array($cache['rates']) && self::fresh($cache, $prov, $cache['rates'] ? self::RATES_TTL : self::FAIL_TTL, $now)) {
            return $cache['rates'];
        }
        $r = Provider::routeAnswer($prov, '/models', self::TIMEOUT, 1048576);
        $data = $r !== null && $r['code'] === 200 ? json_decode($r['body'], true) : null;
        $rates = [];
        foreach (isset($data['data']) && is_array($data['data']) ? $data['data'] : [] as $m) {
            $rate = CreditsFormat::rate($m);
            if ($rate !== null && isset($m['id']) && is_string($m['id'])) {
                $rates[] = ['id' => $m['id'], 'input' => $rate['input'], 'output' => $rate['output']];
            }
        }
        self::write($dir, 'rates.json', self::stamp($prov, $now) + ['rates' => $rates]);
        return $rates;
    }

    /** The rates rates() cached for this endpoint and key, when under RATES_TTL old; never a request ([] otherwise; the goal review's pick, ADR-0096). */
    public static function cachedRates(array $prov, $dir, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $cache = self::read("$dir/rates.json");
        return isset($cache['rates']) && is_array($cache['rates']) && self::fresh($cache, $prov, self::RATES_TTL, $now) ? $cache['rates'] : [];
    }

    /** What every cache file starts with: when, which endpoint, and which key as the first 12 hex of its sha256 (never the key). */
    private static function stamp(array $prov, $now)
    {
        return ['at' => $now, 'endpoint' => $prov['endpoint'], 'key_sha256' => substr(hash('sha256', $prov['key']), 0, 12)];
    }

    /** Written for this endpoint and key, not in the future (a clock set back), and younger than $ttl. */
    private static function fresh($cache, array $prov, $ttl, $now)
    {
        $want = self::stamp($prov, $now);
        return is_array($cache) && isset($cache['at'], $cache['endpoint'], $cache['key_sha256']) && is_int($cache['at'])
            && $cache['endpoint'] === $want['endpoint'] && $cache['key_sha256'] === $want['key_sha256']
            && $cache['at'] <= $now && $cache['at'] > $now - $ttl;
    }

    private static function read($file)
    {
        return is_file($file) && !is_link($file) ? json_decode((string) @file_get_contents($file), true) : null;
    }

    /** Best effort, temp file + rename; a cache that cannot be written only costs a request next time. */
    private static function write($dir, $name, array $data)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return;
        }
        if (is_link($dir) || is_link("$dir/$name")) {
            return;
        }
        @chmod($dir, 0700);
        $tmp = "$dir/$name.tmp-" . getmypid();
        $body = json_encode($data) . "\n";
        if (!(@file_put_contents($tmp, $body) === strlen($body) && @chmod($tmp, 0600) && @rename($tmp, "$dir/$name"))) {
            @unlink($tmp);
        }
    }
}
