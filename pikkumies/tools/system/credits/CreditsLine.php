<?php
/**
 * CreditsLine: the credits line's record, fetched and cached (ADR-0040). Used by `credits line` (the tmux status
 * line runs it every minute) and by `pikkumies` itself for the start banner, as a library call.
 *
 * Two read-only requests, GET {endpoint}/usage and GET {endpoint}/models, 2 seconds each at most. Under
 * local/state/credits/ (0700, files 0600): the record for 60 s, so several windows cost one request a minute; the
 * model rates for a day; and "this endpoint has no usage route" (HTTP 404) for a day, so another provider is asked
 * once a day, not every minute. Any failure is null: the caller prints nothing. The key goes only into the request's
 * Authorization header; it is never printed and never written here.
 */
require_once __DIR__ . '/CreditsFormat.php';

class CreditsLine
{
    const LINE_TTL = 60;
    const RATES_TTL = 86400;
    const NO_ROUTE_TTL = 86400;
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
        $line = self::read("$dir/line.json");
        if (self::fresh($line, $prov['endpoint'], self::LINE_TTL, $now) && isset($line['model'], $line['record']) && $line['model'] === $prov['model']) {
            return $line['record'];
        }
        if (self::fresh(self::read("$dir/no-usage-route.json"), $prov['endpoint'], self::NO_ROUTE_TTL, $now)) {
            return null;
        }
        $r = Provider::routeAnswer($prov, '/usage?days=' . self::DAYS . '&grants=1', self::TIMEOUT, 1048576);
        if ($r !== null && $r['code'] === 404) {
            self::write($dir, 'no-usage-route.json', ['at' => $now, 'endpoint' => $prov['endpoint']]);
        }
        $usage = $r !== null && $r['code'] === 200 ? json_decode($r['body'], true) : null;
        if (!is_array($usage) || !isset($usage['balance']) || !is_int($usage['balance'])) {
            return null;
        }
        $record = CreditsFormat::record($usage, self::rates($prov, $dir, $now), $prov['model'], gmdate('Y-m-d', $now));
        self::write($dir, 'line.json', ['at' => $now, 'endpoint' => $prov['endpoint'], 'model' => $prov['model'], 'record' => $record]);
        return $record;
    }

    /** [['id', 'input', 'output'], ...] in the endpoint's order, cached a day; [] when the rates cannot be had (no token estimate then). */
    public static function rates(array $prov, $dir, $now)
    {
        $cache = self::read("$dir/rates.json");
        if (self::fresh($cache, $prov['endpoint'], self::RATES_TTL, $now) && isset($cache['rates']) && is_array($cache['rates'])) {
            return $cache['rates'];
        }
        $r = Provider::routeAnswer($prov, '/models', self::TIMEOUT, 1048576);
        $data = $r !== null && $r['code'] === 200 ? json_decode($r['body'], true) : null;
        if (!isset($data['data']) || !is_array($data['data'])) {
            return [];
        }
        $rates = [];
        foreach ($data['data'] as $m) {
            $c = isset($m['credits_per_million_tokens']) ? $m['credits_per_million_tokens'] : null;
            if (isset($m['id'], $c['input'], $c['output']) && is_string($m['id']) && is_int($c['input']) && is_int($c['output'])) {
                $rates[] = ['id' => $m['id'], 'input' => $c['input'], 'output' => $c['output']];
            }
        }
        if ($rates) {
            self::write($dir, 'rates.json', ['at' => $now, 'endpoint' => $prov['endpoint'], 'rates' => $rates]);
        }
        return $rates;
    }

    private static function fresh($cache, $endpoint, $ttl, $now)
    {
        return is_array($cache) && isset($cache['at'], $cache['endpoint']) && is_int($cache['at']) && $cache['endpoint'] === $endpoint
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
