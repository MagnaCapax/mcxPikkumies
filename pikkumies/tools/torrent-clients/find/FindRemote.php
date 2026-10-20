<?php
/**
 * FindRemote: the `find` query against remote services, through the Services contract (lib/Services.php,
 * owner A) -- Services::fanout for the per-service concurrency, Services::jsonl for the `tool
 * torrent-clients/<client> list` call on each (its own records already carry "service": nick, per the
 * Services contract). Every argument here is a plain callable, never the Services class by name, so this
 * unit (and its tests) never need lib/Services.php to exist -- find.php guards with class_exists and only
 * passes real callables when it is actually there.
 */
class FindRemote
{
    /**
     * $nicks: the service nicknames to search. $get($nick) -> that service's record (array), or throws
     * when $nick is unknown. $jsonl($nick, $argv) -> decoded JSONL records, Services::jsonl's own shape
     * (the trailing status line included: find.php's findAbsorb() reads it the same way for every client).
     * $fanout($nicks, $fn, $parallel) -> [nick => $fn($nick)'s return]. Returns [nick => [client => rows]].
     */
    public static function run(array $nicks, callable $fanout, callable $get, callable $jsonl, $parallel = 4)
    {
        if (!$nicks) {
            return [];
        }
        $clients = [];
        foreach ($nicks as $nick) {
            $svc = $get($nick);
            $clients[$nick] = is_array($svc) && isset($svc['clients']) && is_array($svc['clients']) ? array_keys($svc['clients']) : [];
        }
        $out = call_user_func($fanout, $nicks, function ($nick) use ($clients, $jsonl) {
            $byClient = [];
            foreach ($clients[$nick] as $client) {
                $byClient[$client] = (array) call_user_func($jsonl, $nick, ['tool', "torrent-clients/$client", 'list']);
            }
            return $byClient;
        }, $parallel);
        return is_array($out) ? $out : [];
    }
}
