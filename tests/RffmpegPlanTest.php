<?php
/**
 * RffmpegPlan against a fake local Jellyfin/Emby and a fake Services gateway (tests/fixtures/rffmpeg/
 * harness.php): plan never aborts on a fixable gap, it reports `ready: false` and the exact next step.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/media/rffmpeg/RffmpegLocal.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media/rffmpeg/RffmpegRemote.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media/rffmpeg/RffmpegPlan.php';
require_once __DIR__ . '/fixtures/rffmpeg/harness.php';

return [
    'rffmpeg plan: refuses only the genuine usage error -- no --gpu at all' => function () {
        $gw = new RffmpegServices(rfServicesSeam([], new RfFakeRemote()));
        try {
            RffmpegPlan::run($gw, [], [], '/tmp', 1000);
            check(false, 'plan without --gpu should have thrown');
        } catch (CliError $e) {
            check(strpos($e->getMessage(), '--gpu') !== false, 'wrong refusal: ' . $e->getMessage());
        }
    },
    'rffmpeg plan: no local Jellyfin/Emby and an unknown service nick both become steps, never an abort' => function () {
        $fx = rfFixture();
        try {
            $gw = new RffmpegServices(rfServicesSeam([], new RfFakeRemote()));
            $out = RffmpegPlan::run($gw, [], ['gpu' => 'nosuch'], $fx['home'], 1000);
            $rec = $out[0];
            check($rec['ready'] === false, 'should not be ready: ' . json_encode($rec));
            check($rec['local_media_server'] === null, 'no local server should have been found');
            check((bool) array_filter($rec['steps'], function ($s) {
                return strpos($s, 'Install Media Stack') !== false;
            }), 'missing the install-media-stack step: ' . json_encode($rec['steps']));
            check((bool) array_filter($rec['steps'], function ($s) {
                return strpos($s, 'no service named nosuch') !== false;
            }), 'missing the unknown-service step: ' . json_encode($rec['steps']));
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg plan: a jellyfin config directory is found natively; a non-shell service and a missing --allow-shell both gate, one step each' => function () {
        $fx = rfFixture();
        try {
            mkdir("$fx[home]/.config/jellyfin", 0700, true);
            $remote = new RfFakeRemote();
            $gw = new RffmpegServices(rfServicesSeam(['gpubox' => rfService(['transport' => 'tunnel'])], $remote));
            $out = RffmpegPlan::run($gw, [], ['gpu' => 'gpubox'], $fx['home'], 1000);
            $rec = $out[0];
            check($rec['local_media_server'] === 'jellyfin', 'native jellyfin dir not found: ' . json_encode($rec));
            check($rec['ready'] === false && (bool) array_filter($rec['steps'], function ($s) {
                return strpos($s, "transport 'tunnel', not 'shell'") !== false;
            }), 'wrong-transport step missing: ' . json_encode($rec['steps']));
            check($remote->calls === [], 'a non-shell service must never be SSHed into');

            $gw2 = new RffmpegServices(rfServicesSeam(['gpubox' => rfService()], $remote));
            $out2 = RffmpegPlan::run($gw2, [], ['gpu' => 'gpubox'], $fx['home'], 1000);
            check((bool) array_filter($out2[0]['steps'], function ($s) {
                return strpos($s, '--allow-shell') !== false;
            }), 'missing-allow-shell step missing: ' . json_encode($out2[0]['steps']));
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg plan: ffmpeg/GPU found and mount visible with --allow-shell makes it ready; the install/add-host steps are the ones printed' => function () {
        $fx = rfFixture();
        try {
            mkdir("$fx[home]/.config/jellyfin", 0700, true);
            $remote = new RfFakeRemote();
            $gw = new RffmpegServices(rfServicesSeam(['gpubox' => rfService()], $remote));
            $opt = ['gpu' => 'gpubox', 'allow-shell' => true, 'mount' => '/home/fixtureuseronbox'];
            $out = RffmpegPlan::run($gw, [], $opt, $fx['home'], 1000);
            $rec = $out[0];
            check($rec['ready'] === true, 'should be ready: ' . json_encode($rec));
            check($rec['remote']['gpu_usable'] === true && $rec['remote']['ffmpeg_path'] !== '', 'remote probe missing: ' . json_encode($rec['remote']));
            check($rec['mount_check']['visible'] === true, 'mount should be visible: ' . json_encode($rec['mount_check']));
            check(count($rec['steps']) === 2 && strpos($rec['steps'][1], 'add-host gpubox') !== false, 'wrong ready steps: ' . json_encode($rec['steps']));
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg plan: an invisible mount (the systemd --user gotcha) and a missing GPU device both block, with the exact test command named' => function () {
        $fx = rfFixture();
        try {
            mkdir("$fx[home]/.config/jellyfin", 0700, true);
            $remote = new RfFakeRemote();
            $remote->mountOk = false;
            $gw = new RffmpegServices(rfServicesSeam(['gpubox' => rfService()], $remote));
            $out = RffmpegPlan::run($gw, [], ['gpu' => 'gpubox', 'allow-shell' => true, 'mount' => '/mnt/x'], $fx['home'], 1000);
            $rec = $out[0];
            check($rec['ready'] === false && $rec['mount_check']['visible'] === false, 'mount gap not reported: ' . json_encode($rec));
            check((bool) array_filter($rec['steps'], function ($s) {
                return strpos($s, 'systemd --user mount gotcha') !== false
                    && strpos($s, 'ssh ' . escapeshellarg('gpubox.example.com') . ' ls ' . escapeshellarg('/mnt/x')) !== false;
            }), 'missing the exact gotcha test command: ' . json_encode($rec['steps']));

            $remote2 = new RfFakeRemote();
            $remote2->driExists = false;
            $gw2 = new RffmpegServices(rfServicesSeam(['gpubox' => rfService()], $remote2));
            $out2 = RffmpegPlan::run($gw2, [], ['gpu' => 'gpubox', 'allow-shell' => true], $fx['home'], 1000);
            check($out2[0]['ready'] === false && strpos($out2[0]['remote']['gpu_gap'], 'does not exist') !== false,
                'missing-device gap wrong: ' . json_encode($out2[0]['remote']));
        } finally {
            rfDone($fx);
        }
    },
];
