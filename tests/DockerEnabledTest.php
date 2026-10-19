<?php
/**
 * Docker switched off for the account (PMSS userShowLimits "Docker enabled", account section, PMSS etc/skel/bin/userShowLimits:136):
 * the docker unit's `disabled-for-account` state before `down`, read only when the daemon does not answer, and
 * `system-status account`'s docker.enabled. Media servers state nothing about the platform's GPU transcoding.
 */
require_once __DIR__ . '/fixtures/containers/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/containers/docker/DockerCli.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/system-status/StatusAccount.php';

if (!function_exists('deUsl')) {
    /** The userShowLimits fixture with "Docker enabled" set to $value, or removed when $value is 'missing'. */
    function deUsl($value)
    {
        $d = require __DIR__ . '/fixtures/system-status/userShowLimits.php';
        foreach ($d['sections']['account']['metrics'] as $i => $m) {
            if ($m['metric'] === 'Docker enabled') {
                if ($value === 'missing') {
                    unset($d['sections']['account']['metrics'][$i]);
                } else {
                    $d['sections']['account']['metrics'][$i]['value'] = $value;
                }
            }
        }
        $d['sections']['account']['metrics'] = array_values($d['sections']['account']['metrics']);
        return ['generated_at' => $d['generated_at'], 'sections' => $d['sections']];
    }
}

return [
    'docker ladder: Docker off for the account is disabled-for-account before down; on, unknown or a live daemon keep their states' => function () {
        $base = ['probe_ok' => false, 'timed_out' => false, 'socket_file' => false, 'daemons' => 0, 'processes' => 0, 'context_mismatch' => false,
            'cli_context' => '', 'storage_driver' => '', 'storage_driver_missing' => false, 'pmss' => true, 'linger' => null];
        foreach ([[['docker_enabled' => false], 'disabled-for-account'], [['docker_enabled' => true], 'down'], [['docker_enabled' => null], 'down'],
            [[], 'down'], [['processes' => 1, 'docker_enabled' => false], 'wedged'], [['probe_ok' => true, 'docker_enabled' => false], 'healthy']] as $c) {
            $l = DockerCli::ladder($c[0] + $base);
            check($l['state'] === $c[1] && in_array($l['state'], DockerCli::STATES, true), json_encode($c[0]) . " -> {$l['state']}, expected {$c[1]}");
        }
        $off = DockerCli::ladder(['docker_enabled' => false] + $base);
        check(strpos($off['next_step'], 'support can say why') !== false && !preg_match('/systemctl|kill|enable it yourself/', $off['next_step']), 'advice: ' . $off['next_step']);
        $down = DockerCli::ladder($base);
        check(strpos($down['next_step'], 'systemctl --user start docker.service') !== false, 'the documented PMSS start advice must stay (PMSS ADR-0066): ' . $down['next_step']);
    },
    'docker observe: on PMSS the setting is read from ~/bin/userShowLimits only when the daemon does not answer' => function () {
        $fx = containersFixture();
        try {
            mkdir($fx['dir'] . '/pmss');
            mkdir($fx['home'] . '/bin', 0700);
            file_put_contents($fx['home'] . '/bin/userShowLimits', '#!' . PHP_BINARY . "\n<?php file_put_contents(__DIR__ . '/ran', 'x', FILE_APPEND); echo "
                . var_export(json_encode(deUsl(false)), true) . ";\n");
            chmod($fx['home'] . '/bin/userShowLimits', 0700);
            $seams = ['pmss_dir' => $fx['dir'] . '/pmss'];
            $o = (new DockerCli(containersSeams($fx, $seams)))->observe(['ok' => true]);
            check($o['docker_enabled'] === null && !file_exists($fx['home'] . '/bin/ran'), 'read although the daemon answers: ' . json_encode($o));
            $o = (new DockerCli(containersSeams($fx, $seams)))->observe(['ok' => false, 'timed_out' => false]);
            check($o['docker_enabled'] === false && DockerCli::ladder($o)['state'] === 'disabled-for-account', 'down and off: ' . json_encode($o));
            $o = (new DockerCli(containersSeams($fx)))->observe(['ok' => false, 'timed_out' => false]);
            check($o['docker_enabled'] === null, 'read off PMSS: ' . json_encode($o));
        } finally {
            containersDone($fx);
        }
    },
    'system-status account: docker.enabled is PMSS\'s "Docker enabled" as it is, null when PMSS does not write it' => function () {
        check(StatusAccount::record(deUsl(true), 'pmss', [])['docker']['enabled'] === true, 'enabled');
        check(StatusAccount::record(deUsl(false), 'pmss', [])['docker']['enabled'] === false, 'disabled');
        check(StatusAccount::record(deUsl('missing'), 'pmss', [])['docker']['enabled'] === null, 'missing must be null, never a guess');
    },
    'media servers: no shipped text says whether the platform offers GPU transcoding' => function () {
        $hits = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/pikkumies/tools', FilesystemIterator::SKIP_DOTS)) as $f) {
            if (preg_match('/provides?( no)? GPU transcoding/i', (string) file_get_contents((string) $f))) {
                $hits[] = substr((string) $f, strlen(dirname(__DIR__)) + 1);
            }
        }
        check($hits === [], 'a platform GPU claim: ' . implode(', ', $hits));
    },
];
