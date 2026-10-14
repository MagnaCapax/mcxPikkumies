<?php
/**
 * AutobrrChange: autobrr's two changing verbs besides restart: `toggle` (PUT filters/{id}/enabled, then read back:
 * internal/http/filter.go:60,268-295) and `login` (store the user's own API key after autobrr accepted it).
 */
class AutobrrChange
{
    /**
     * Turns filters on or off one at a time, reading each back. One record per id, `state` changed, unchanged, failed
     * or not_sent. The calls stop at the first failure (an error, or a filter that reads back otherwise), and then the
     * call ends ok:false naming what happened to every filter, as ArrRemove::finish() does for `remove`.
     * Turning one on lets autobrr grab what it matches.
     */
    public static function toggle(ArrClient $c, array $args, array $opt)
    {
        if (empty($opt['on']) === empty($opt['off'])) {
            throw new CliError('toggle needs exactly one of --on or --off', 'toggle <id>... --on   or   toggle <id>... --off');
        }
        $want = !empty($opt['on']);
        $out = [];
        $stop = false;
        foreach (AutobrrRun::resolve($c, $args) as $id) {
            $r = ['id' => $id, 'name' => '', 'was_enabled' => null, 'enabled' => null, 'state' => 'not_sent'];
            if ($stop) {
                $out[] = $r + ['error' => 'not sent: an earlier change in this call failed'];
                continue;
            }
            $missing = ['missing' => "no filter $id"];
            $put = false;
            try {
                $before = (array) $c->call('GET', "filters/$id", [], null, $missing);
                $r = array_merge($r, ['name' => ArrRecords::s($before, 'name'), 'was_enabled' => !empty($before['enabled'])]);
                $c->call('PUT', "filters/$id/enabled", [], ['enabled' => $want], $missing);
                $put = true;
                $r['enabled'] = !empty(((array) $c->call('GET', "filters/$id", [], null, $missing))['enabled']);
                $r['state'] = $r['enabled'] !== $want ? 'failed' : ($r['was_enabled'] === $want ? 'unchanged' : 'changed');
                if ($r['state'] === 'failed') {
                    $r['error'] = 'autobrr accepted the change but reads back otherwise';
                }
            } catch (CliError $e) {
                $r['state'] = 'failed';
                $r['error'] = ($put ? 'sent, but not read back (it may have changed): ' : '') . $e->getMessage();
            }
            $stop = $r['state'] === 'failed';
            $out[] = $r;
        }
        if ($stop) {
            throw new CliError('not done as asked: ' . implode(' | ', array_map(function ($r) {
                $now = $r['enabled'] === null ? '' : ', now ' . ($r['enabled'] ? 'on' : 'off');
                return "filter {$r['id']}" . ($r['name'] !== '' ? " ({$r['name']})" : '') . ": {$r['state']}$now"
                    . (isset($r['error']) ? ", {$r['error']}" : '');
            }, $out)), 'tell the user exactly this, filter by filter; `list` shows each filter\'s state now');
        }
        return ['records' => $out, 'status' => ['verified' => true]];
    }

    /** Stores the key the user types, after autobrr accepted it (or unchecked when autobrr does not answer). */
    public static function login(callable $locate, AutobrrSecret $secret, callable $prompt, $timeout)
    {
        $key = ArrConfig::key((string) call_user_func($prompt, 'autobrr API key (Settings, API keys in autobrr; not shown): '));
        if ($key === '') {
            throw new CliError('that is not an autobrr API key (20 to 64 letters and digits); nothing stored', 'run login again');
        }
        $where = ['key' => $key] + $locate('key'); // only the key may be missing
        try {
            (new ArrClient($where, function () use ($where) {
                return $where; // the key being checked: never the stored one
            }, '', $timeout))->call('GET', 'config');
        } catch (CliError $e) {
            if (strpos($e->getMessage(), 'HTTP 401') !== false || strpos($e->getMessage(), 'HTTP 403') !== false) {
                throw new CliError('autobrr rejected that key; nothing was stored', 'copy the key again from autobrr (Settings, API keys)');
            }
            $secret->save($key);
            return ['records' => [['stored' => $secret->path(), 'verified' => false,
                'note' => 'stored without a check: ' . $e->getMessage()]],
                'status' => ['verified' => false]];
        }
        $secret->save($key);
        return ['records' => [['stored' => $secret->path(), 'verified' => true]], 'status' => ['verified' => true]];
    }
}
