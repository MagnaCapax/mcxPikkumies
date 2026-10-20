<?php
/**
 * Helpers for the media/rffmpeg tests: a temp HOME/local dir, a fake Services gateway (the "inject a
 * runner callable" seam SPEC.md asks every builder coding against Services to use before owner A
 * lands lib/Services.php), and a fake remote shell that answers this unit's own fixed probe/mount/
 * deploy/remove command strings by substring, so no real SSH, python3, pip or network is needed.
 */
require_once dirname(__DIR__, 3) . '/pikkumies/tools/media/rffmpeg/RffmpegServices.php';
require_once dirname(__DIR__, 3) . '/pikkumies/tools/media/rffmpeg/RffmpegState.php';

/** A throwaway HOME + pikkumies/local directory; remove with rfDone() in a finally. */
function rfFixture()
{
    // Never "pk-rffmpeg": the D2 fix asserts the transcode entry points' full path has no "rffmpeg"
    // substring anywhere, and that includes whatever temp directory prefix a test fixture picks.
    $dir = testTempDir('pk-gputest');
    $fx = ['dir' => $dir, 'home' => "$dir/home", 'local' => "$dir/local"];
    mkdir($fx['home'], 0700, true);
    mkdir($fx['local'], 0700, true);
    $fx['home'] = realpath($fx['home']);
    return $fx;
}

function rfDone(array $fx)
{
    testRmTree($fx['dir']);
}

/**
 * A fake GPU host over the shell transport. Every real call is `['sh', '-c', $script, 'rf-<name>',
 * $data...]` (RffmpegRemote.php's own docblock explains why: Services::sshArgv's shell transport
 * single-quotes each array element as its OWN word, so a whole script sent as one element would be
 * read by the remote shell as a single, mis-quoted COMMAND NAME, never as a script to run -- a real
 * `sh -c` invocation is the only shape that actually executes). This fake dispatches on a substring
 * of $remoteArgv[2] (the script) and reads $remoteArgv[4..] for the data words, which is what a real
 * remote `sh -c` would see too (tests/RffmpegSshArgvTest.php proves the same argv against the real
 * Services::sshArgv feeds a local `sh -c` correctly).
 */
class RfFakeRemote
{
    public $calls = [];
    public $ffmpegPath = '/usr/lib/jellyfin-ffmpeg/ffmpeg';
    public $ffprobePath = '/usr/lib/jellyfin-ffmpeg/ffprobe';
    public $driExists = true;
    public $driRw = true;
    public $groups = 'video render';
    public $mountOk = true;
    public $mountSample = "movies\nshows\n";
    public $deployOk = true;
    public $deployed = [];
    public $removeOk = true;
    public $removed = false;

    public function run($nick, array $remoteArgv, $stdin = null, $timeout = 120)
    {
        $this->calls[] = $remoteArgv;
        $script = isset($remoteArgv[2]) ? (string) $remoteArgv[2] : '';
        if (strpos($script, 'DRI_EXISTS') !== false) {
            $out = '';
            $out .= $this->ffmpegPath !== '' ? "FFMPEG={$this->ffmpegPath}\n" : '';
            $out .= $this->ffprobePath !== '' ? "FFPROBE={$this->ffprobePath}\n" : '';
            if ($this->driExists) {
                $out .= "DRI_EXISTS=1\n";
                if ($this->driRw) {
                    $out .= "DRI_R=1\nDRI_W=1\n";
                }
            } else {
                $out .= "DRI_EXISTS=0\n";
            }
            $out .= "GROUPS={$this->groups}\n";
            return ['rc' => 0, 'stdout' => $out, 'stderr' => ''];
        }
        if (strpos($script, 'ls -A') !== false) {
            // The sentinel comes FIRST (Review fixes 2, D4): a real remote echoes PKRF_MOUNT_RESULT=
            // before the `ls -A` listing, so a maliciously-named file in that listing can never be
            // mistaken for the result (parseKv-by-position would otherwise pick whichever came first).
            return $this->mountOk ? ['rc' => 0, 'stdout' => "PKRF_MOUNT_RESULT=visible\n" . $this->mountSample, 'stderr' => '']
                : ['rc' => 1, 'stdout' => "PKRF_MOUNT_RESULT=not-visible\n", 'stderr' => 'not a mount point or empty'];
        }
        if (strpos($script, 'PKRF_DEPLOYED') !== false) {
            if (!$this->deployOk) {
                return ['rc' => 1, 'stdout' => '', 'stderr' => 'permission denied'];
            }
            $role = isset($remoteArgv[5]) ? (string) $remoteArgv[5] : '';
            $content = isset($remoteArgv[6]) ? (string) $remoteArgv[6] : '';
            if ($role !== '') {
                $this->deployed[$role] = $content;
            }
            return ['rc' => 0, 'stdout' => "PKRF_DEPLOYED\n", 'stderr' => ''];
        }
        if (strpos($script, 'PKRF_DONE') !== false) {
            if (!$this->removeOk) {
                return ['rc' => 1, 'stdout' => '', 'stderr' => 'permission denied'];
            }
            $this->removed = true;
            return ['rc' => 0, 'stdout' => "PKRF_DONE\n", 'stderr' => ''];
        }
        return ['rc' => 1, 'stdout' => '', 'stderr' => "RfFakeRemote: unrecognized script: $script"];
    }
}

/** The RffmpegServices constructor seam: $records is nick => service array (SPEC.md Services::get shape). */
function rfServicesSeam(array $records, RfFakeRemote $remote)
{
    return [
        'get' => function ($nick) use ($records) {
            if (!isset($records[$nick])) {
                throw new CliError("no service named $nick", 'check the nick with the services list');
            }
            return $records[$nick];
        },
        'run' => function ($nick, array $remoteArgv, $stdin = null, $timeout = 120) use ($remote) {
            return $remote->run($nick, $remoteArgv, $stdin, $timeout);
        },
        'sshArgv' => function ($nick, array $remoteArgv, array $opt = []) {
            return array_merge(['ssh', $nick], $remoteArgv);
        },
        'secretDir' => function ($nick) {
            return "/fake/secrets/services/$nick";
        },
    ];
}

/** A default shell-transport service record a test can override piecemeal. */
function rfService(array $over = [])
{
    return $over + ['nick' => 'gpubox', 'tags' => ['transcode'], 'provider' => 'generic', 'host' => 'gpubox.example.com',
        'port' => 22, 'user' => 'fixturegpuuser', 'home' => '/home/fixturegpuuser', 'transport' => 'shell', 'clients' => [],
        'allow_delete' => false, 'notes' => '', 'added_at' => '2026-10-20T00:00:00Z'];
}

/**
 * RffmpegVenv::install's non-Services seams: a fake script body/checksum pair (so the checksum check
 * is exercised without needing the real pinned upstream bytes), a fake venv that writes one tiny
 * POSIX-sh "python3" able to answer rffmpeg's own init/add/status/clear the way RffmpegHost's later
 * ToolProc::run calls expect (it only ever looks at $2, never actually parses the downloaded script).
 */
function rfInstallSeams($dbDir, $scriptBody = "#!/usr/bin/env python3\nfake\n")
{
    return [
        'fetchScript' => function ($url) use ($scriptBody) {
            return ['code' => 200, 'body' => $scriptBody, 'error' => ''];
        },
        'scriptSha256' => hash('sha256', $scriptBody),
        'pythonBin' => '/fake/python3',
        'venvCreate' => function ($python, $venvDir) use ($dbDir) {
            if (!is_dir("$venvDir/bin") && !mkdir("$venvDir/bin", 0700, true)) {
                throw new Exception("cannot create $venvDir/bin");
            }
            $fake = "#!/bin/sh\n"
                . "case \"\$2\" in\n"
                . "  init) mkdir -p " . escapeshellarg($dbDir) . " && touch " . escapeshellarg("$dbDir/rffmpeg.db") . "; echo Initializing ;;\n"
                . "  add) echo \"Added host \$3\" ;;\n"
                . "  status) echo 'Weight  1' ;;\n"
                . "  clear) echo Cleared ;;\n"
                . "esac\n";
            file_put_contents("$venvDir/bin/python3", $fake);
            chmod("$venvDir/bin/python3", 0755);
        },
        // Signature matches RffmpegVenv::install()'s hash-pinned call: ($venvPython, $reqFile), never
        // a real pip/network. Defaults to the real pinned versions so a status() pin-drift check sees
        // no drift unless a test deliberately returns something else.
        'pipInstall' => function ($venvPython, $reqFile) {
            return ['click==' . RffmpegVenv::CLICK_VERSION, 'PyYAML==' . RffmpegVenv::PYYAML_VERSION];
        },
    ];
}
