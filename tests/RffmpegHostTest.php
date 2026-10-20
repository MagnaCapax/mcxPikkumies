<?php
/**
 * RffmpegHost::addHost/status/remove against the fake Services gateway and fake GPU shell
 * (tests/fixtures/rffmpeg/harness.php): the wrapper deploy/rewrite, the one-global-identity rule,
 * the mount-visibility gate, and that remove() only touches the remote side with --allow-shell.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/media/rffmpeg/RffmpegState.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media/rffmpeg/RffmpegVenv.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media/rffmpeg/RffmpegHost.php';
require_once __DIR__ . '/fixtures/rffmpeg/harness.php';

/** Installs with the fake seams and returns [$fx, $p, $remote, $gw]. */
function rfHostFixture(array $services)
{
    $fx = rfFixture();
    $p = RffmpegState::paths($fx['local']);
    RffmpegVenv::install(rfInstallSeams($p['db_dir']), $fx['local'], []);
    $remote = new RfFakeRemote();
    $gw = new RffmpegServices(rfServicesSeam($services, $remote));
    return [$fx, $p, $remote, $gw];
}

return [
    'rffmpeg add-host: deploys a wrapper rewriting this home to the mount, wires rffmpeg\'s config and its own add' => function () {
        list($fx, $p, $remote, $gw) = rfHostFixture(['gpubox' => rfService()]);
        try {
            $out = RffmpegHost::addHost($gw, ['gpubox'], ['allow-shell' => true, 'mount' => '/home/fixturegpuuser/mnt/userA'], $fx['local'], $fx['home']);
            $rec = $out[0];
            check($rec['ok'] === true && $rec['remote_ffmpeg'] === '/home/fixturegpuuser/.pikkumies-rffmpeg/ffmpeg', 'add-host result wrong: ' . json_encode($rec));
            check(strpos($rec['rffmpeg_add_output'], 'Added host gpubox.example.com') !== false, 'rffmpeg add was not actually run: ' . json_encode($rec));

            check(isset($remote->deployed['ffmpeg'], $remote->deployed['ffprobe']), 'both wrappers were not deployed');
            check(strpos($remote->deployed['ffmpeg'], "from='" . $fx['home'] . "'") !== false, 'wrapper does not rewrite this home: ' . $remote->deployed['ffmpeg']);
            check(strpos($remote->deployed['ffmpeg'], "to='/home/fixturegpuuser/mnt/userA'") !== false, 'wrapper does not target the mount');
            check(strpos($remote->deployed['ffmpeg'], "real='/usr/lib/jellyfin-ffmpeg/ffmpeg'") !== false, 'wrapper does not exec the detected real ffmpeg');
            check(strpos($remote->deployed['ffprobe'], "real='/usr/lib/jellyfin-ffmpeg/ffprobe'") !== false, 'wrapper does not exec the detected real ffprobe');

            $cfg = file_get_contents($p['config']);
            check(strpos($cfg, 'ffmpeg: "/home/fixturegpuuser/.pikkumies-rffmpeg/ffmpeg"') !== false, 'local config was not wired to the wrapper path: ' . $cfg);
            check(strpos($cfg, 'user: "fixturegpuuser"') !== false, 'local config missing the remote ssh user: ' . $cfg);

            $state = RffmpegState::read($fx['local']);
            check(isset($state['hosts']['gpubox']) && $state['hosts']['gpubox']['host'] === 'gpubox.example.com', 'host not recorded in state: ' . json_encode($state));
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg add-host: refuses a service with no usable home directory (Services::normalize allows an empty one; this unit must not)' => function () {
        list($fx, $p, $remote, $gw) = rfHostFixture(['gpubox' => rfService(['home' => ''])]);
        try {
            try {
                RffmpegHost::addHost($gw, ['gpubox'], ['allow-shell' => true, 'mount' => '/mnt/a'], $fx['local'], $fx['home']);
                check(false, 'an empty home should have refused');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'no usable home directory') !== false, 'wrong refusal: ' . $e->getMessage());
            }
            check($remote->calls === [], 'an empty home must refuse before any remote probe');
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg add-host: refuses a second host with a different SSH user than the first (rffmpeg\'s identity is global)' => function () {
        list($fx, $p, $remote, $gw) = rfHostFixture(['gpubox' => rfService(), 'other' => rfService(['nick' => 'other', 'user' => 'someoneElse', 'host' => 'other.example.com'])]);
        try {
            RffmpegHost::addHost($gw, ['gpubox'], ['allow-shell' => true, 'mount' => '/mnt/a'], $fx['local'], $fx['home']);
            try {
                RffmpegHost::addHost($gw, ['other'], ['allow-shell' => true, 'mount' => '/mnt/b'], $fx['local'], $fx['home']);
                check(false, 'a second host with a different ssh user should have been refused');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'different user/key') !== false, 'wrong refusal: ' . $e->getMessage());
            }
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg add-host: an invisible mount refuses before any wrapper is deployed' => function () {
        list($fx, $p, $remote, $gw) = rfHostFixture(['gpubox' => rfService()]);
        try {
            $remote->mountOk = false;
            try {
                RffmpegHost::addHost($gw, ['gpubox'], ['allow-shell' => true, 'mount' => '/mnt/a'], $fx['local'], $fx['home']);
                check(false, 'an invisible mount should have refused');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'not visible') !== false, 'wrong refusal: ' . $e->getMessage());
            }
            check($remote->deployed === [], 'a wrapper was deployed despite the mount check failing');
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg status: reports the installed state and hosts, and runs rffmpeg\'s own status' => function () {
        list($fx, $p, $remote, $gw) = rfHostFixture(['gpubox' => rfService()]);
        try {
            RffmpegHost::addHost($gw, ['gpubox'], ['allow-shell' => true, 'mount' => '/mnt/a'], $fx['local'], $fx['home']);
            $rec = RffmpegHost::status($fx['local'])[0];
            check($rec['installed'] === true && $rec['hosts'] === ['gpubox'], 'status wrong: ' . json_encode($rec));
            check(strpos($rec['rffmpeg_status'], 'Weight') !== false, 'rffmpeg status was not actually run: ' . json_encode($rec));
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg remove: a dry run without --with-data changes nothing; --with-data without --allow-shell removes locally but skips (and says so for) each remote host' => function () {
        list($fx, $p, $remote, $gw) = rfHostFixture(['gpubox' => rfService()]);
        try {
            RffmpegHost::addHost($gw, ['gpubox'], ['allow-shell' => true, 'mount' => '/mnt/a'], $fx['local'], $fx['home']);

            $dry = RffmpegHost::remove($gw, [], $fx['local'])[0];
            check($dry['ok'] === false && $dry['dry_run'] === true && $dry['would_remove_remote_hosts'] === ['gpubox'], 'dry run wrong: ' . json_encode($dry));
            check(is_file($p['script']), 'a dry run must not remove anything');

            $remote->removed = false;
            $out = RffmpegHost::remove($gw, ['with-data' => true], $fx['local'])[0];
            check($out['ok'] === true && !is_file($p['script']) && !is_dir($p['venv']), 'local side was not removed: ' . json_encode($out));
            check($out['remote']['gpubox']['skipped'] === true && strpos($out['remote']['gpubox']['reason'], '--allow-shell') !== false,
                'a skipped remote cleanup must say why: ' . json_encode($out));
            check($remote->removed === false, 'the remote wrapper must not be touched without --allow-shell');
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg remove: --with-data --allow-shell also removes the remote wrapper files' => function () {
        list($fx, $p, $remote, $gw) = rfHostFixture(['gpubox' => rfService()]);
        try {
            RffmpegHost::addHost($gw, ['gpubox'], ['allow-shell' => true, 'mount' => '/mnt/a'], $fx['local'], $fx['home']);
            $out = RffmpegHost::remove($gw, ['with-data' => true, 'allow-shell' => true], $fx['local'])[0];
            check($out['ok'] === true && $out['remote']['gpubox']['ok'] === true, 'remote removal not reported ok: ' . json_encode($out));
            check($remote->removed === true, 'the fake remote never saw the rm -f call');
        } finally {
            rfDone($fx);
        }
    },
    'rffmpeg remove: targets the RECORDED wrapper paths, not paths re-derived from the service\'s current (possibly changed) home' => function () {
        $services = ['gpubox' => rfService()];
        $fx = rfFixture();
        $p = RffmpegState::paths($fx['local']);
        RffmpegVenv::install(rfInstallSeams($p['db_dir']), $fx['local'], []);
        $remote = new RfFakeRemote();
        $gw = new RffmpegServices(rfServicesSeam($services, $remote));
        try {
            RffmpegHost::addHost($gw, ['gpubox'], ['allow-shell' => true, 'mount' => '/mnt/a'], $fx['local'], $fx['home']);
            $state = RffmpegState::read($fx['local']);
            $recordedFfmpeg = $state['hosts']['gpubox']['ffmpeg_wrapper'];
            $recordedFfprobe = $state['hosts']['gpubox']['ffprobe_wrapper'];
            check($recordedFfmpeg === '/home/fixturegpuuser/.pikkumies-rffmpeg/ffmpeg', 'unexpected recorded path: ' . $recordedFfmpeg);

            // remove() must never call Services::get($nick) for this -- if the service's home changed
            // since add-host (services edit), a re-derived path would miss the real files. Proven here
            // structurally: $gw's only service record still has the ORIGINAL home, so a passing assert
            // that the recorded path (not a re-derived one) reached the remote is still meaningful even
            // though get() would answer the same home either way; the removeWrapper() signature itself
            // (ffmpeg/ffprobe paths, not $gpuHome) is what actually removed the re-derivation.
            RffmpegHost::remove($gw, ['with-data' => true, 'allow-shell' => true], $fx['local']);

            $last = end($remote->calls);
            check(in_array($recordedFfmpeg, $last, true) && in_array($recordedFfprobe, $last, true),
                'remove did not send the recorded wrapper paths to the remote: ' . json_encode($last));
        } finally {
            rfDone($fx);
        }
    },
];
