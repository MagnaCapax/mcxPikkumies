<?php
/**
 * ConfigureTiers (ADR-0059): every setting has a rule, and each direction of each row is the tier the ADR's table says.
 * The table below is written out by hand from the ADR, not read from the code, so flipping any row's direction in
 * ConfigureTiers::RULES fails a line here.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/ConfigureTiers.php';

return [
    'tiers: every key of Config::defaults() has a rule of its own, so no setting is left undecided' => function () {
        $leaves = function (array $a, $prefix) use (&$leaves) {
            $out = [];
            foreach ($a as $k => $v) {
                $out = array_merge($out, is_array($v) && $v !== [] && !Config::isList($v) ? $leaves($v, "$prefix$k.") : ["$prefix$k"]);
            }
            return $out;
        };
        $keys = $leaves(Config::defaults(), '');
        check(count($keys) >= 22, 'the defaults could not be walked: ' . json_encode($keys));
        foreach ($keys as $key) {
            check(isset(ConfigureTiers::RULES[$key]), "$key is in Config::defaults() but has no tier in ConfigureTiers::RULES (ADR-0059: none left undecided)");
        }
        foreach (array_keys(ConfigureTiers::RULES) as $key) {
            check(in_array($key, $keys, true), "ConfigureTiers::RULES names $key, which Config::defaults() does not have");
        }
    },
    'tiers: each direction of each row is the ADR-0059 tier' => function () {
        $f = 'free';
        $a = 'approve';
        $p = 'person';
        $rows = [
            ['provider.endpoint', '', 'https://x/v1', $p], ['provider.keyFile', '/a', '/b', $p], ['provider.virsilipasKeyFile', '/a', '/b', $p],
            ['provider.model', '', 'm-1', $a], ['provider.model', 'm-1', '', $a],
            ['root.disabled', false, true, $f], ['root.disabled', true, false, $p],
            ['skills.disabled', [], ['seedbox-apps'], $f], ['skills.disabled', ['seedbox-apps'], [], $f],
            ['tools.disabled', [], ['email'], $a], ['tools.disabled', ['email', 'backup'], ['backup'], $f], ['tools.disabled', ['email'], ['backup'], $a],
            ['searchFirst.disabled', true, false, $f], ['searchFirst.disabled', false, true, $p],
            ['halt.disabled', true, false, $f], ['halt.disabled', false, true, $p],
            ['lowBalance.disabled', true, false, $f], ['lowBalance.disabled', false, true, $a],
            ['fileopsCheck.disabled', true, false, $f], ['fileopsCheck.disabled', false, true, $p],
            ['memoryCheck.disabled', true, false, $f], ['memoryCheck.disabled', false, true, $p],
            ['hooks.reword', false, true, $f], ['hooks.reword', true, false, $a],
            ['hooks.llm.disabled', true, false, $f], ['hooks.llm.disabled', false, true, $p],
            ['hooks.async.disabled', true, false, $f], ['hooks.async.disabled', false, true, $p],
            ['hooks.model', '', 'm-mini', $a],
            ['autoUpdate.disabled', true, false, $f], ['autoUpdate.disabled', false, true, $p],
            ['cron.disabled', false, true, $a], ['cron.disabled', true, false, $a],
            ['hermes.lazyInstalls', true, false, $f], ['hermes.lazyInstalls', false, true, $p],
            ['agent.name', '', 'Kalle', $f], ['agent.name', 'Kalle', '', $f],
            ['privacy.perfLogging', true, false, $f], ['privacy.perfLogging', false, true, $f],
            ['privacy.telemetry', true, false, $f], ['privacy.telemetry', false, true, $f],
            ['update.channel', 'release', 'git/main', $a], ['update.channel', 'git/main', 'release', $f], ['update.channel', 'git/a', 'git/b', $a],
            ['terms.accepted', false, true, $p], ['terms.accepted', true, false, $p],
            ['host.family', null, 'pmss', $p], ['arr.sonarr.config', null, '/home/u/.config/Sonarr/config.xml', $f],
            // ADR-0068: allowing a host asks, refusing one is free, un-refusing one is the person's; strict mode is free on, asked off.
            ['ssh.allow', [], ['nas'], $a], ['ssh.allow', ['nas'], [], $f], ['ssh.deny', [], ['evil'], $f], ['ssh.deny', ['evil'], [], $p],
            ['ssh.blockUnknown', false, true, $f], ['ssh.blockUnknown', true, false, $a],
        ];
        foreach ($rows as $r) {
            list($key, $old, $new, $want) = $r;
            $got = ConfigureTiers::classify($key, $old, $new);
            check($got === $want, "$key " . json_encode($old) . ' -> ' . json_encode($new) . ": $got, the ADR says $want");
        }
    },
    'tiers: a list change that both adds and removes takes the stricter tier, so a deny entry cannot be swapped away freely' => function () {
        $cases = [['ssh.deny', ['a'], ['b'], 'person'], ['ssh.deny', ['a'], ['a', 'b'], 'free'], ['ssh.allow', ['a'], ['b'], 'approve'],
            ['tools.disabled', ['email'], ['backup'], 'approve'], ['skills.disabled', ['x'], ['y'], 'free'], ['ssh.deny', ['a', 'b'], ['b', 'a'], 'free']];
        foreach ($cases as $c) {
            $got = ConfigureTiers::classify($c[0], $c[1], $c[2]);
            check($got === $c[3], "{$c[0]} " . json_encode($c[1]) . ' -> ' . json_encode($c[2]) . ": $got, want {$c[3]}");
        }
    },
    'tiers: retention 0 is forever, the longest; a longer one or N -> 0 is free, a shorter one or 0 -> N asks' => function () {
        $cases = [[0, 30, 'approve'], [30, 0, 'free'], [30, 90, 'free'], [90, 30, 'approve'], [0, 36500, 'approve'], [36500, 0, 'free'], [7, 7, 'free']];
        foreach ($cases as $c) {
            check(ConfigureTiers::classify('sessions.retentionDays', $c[0], $c[1]) === $c[2], "retention {$c[0]} -> {$c[1]} must be {$c[2]}");
        }
    },
    'tiers: an asked switch says what each direction does: cron.disabled both ways, hooks.reword off' => function () {
        $stop = ConfigureTiers::why('cron.disabled', true);
        $run = ConfigureTiers::why('cron.disabled', false);
        check(strpos($stop, 'stop running') !== false && strpos($run, 'unattended') !== false, 'cron: ' . json_encode([$stop, $run]));
        check(strpos(ConfigureTiers::why('hooks.reword', false), 'words as typed') !== false, 'reword off: ' . ConfigureTiers::why('hooks.reword', false));
        check(strpos(ConfigureTiers::why('provider.model', 'm'), 'model') !== false && ConfigureTiers::why('agent.name', 'x') === '', 'plain WHY and none');
    },
    'tiers: no change is free; a list reordered is no change; a key without a rule is the person\'s' => function () {
        check(ConfigureTiers::classify('provider.model', 'm', 'm') === 'free', 'setting the model it already has asks nothing');
        check(ConfigureTiers::classify('tools.disabled', ['a', 'b'], ['b', 'a']) === 'free', 'the same categories in another order are no change');
        check(ConfigureTiers::rule('nonsense') === null && ConfigureTiers::classify('nonsense', null, 1) === 'person', 'unknown key');
        check(ConfigureTiers::rule('arr.Bad.config') === null && ConfigureTiers::rule('arr.sonarr.port') === null, 'only arr.<app>.config, lowercase app');
    },
];
