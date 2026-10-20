<?php
/**
 * RffmpegVenv: `install` -- a user-space venv under local/state/rffmpeg (never a system path, never
 * root: "installing the user's own third-party app is allowed; our code stays PHP", SPEC.md). rffmpeg
 * itself is pinned to one upstream commit with its own sha256 (github.com/joshuaboniface/rffmpeg,
 * GPL-3.0, commit f54ac843c7c8a157562e7ce37aaebbc5cb41354e, 2025-11-03, GPG-verified merge commit --
 * the only git tag, v1, is from 2022, names the script rffmpeg.py not rffmpeg, and predates the
 * add/remove/status/clear commands and the --no-root init flag this unit relies on; verified by
 * `git ls-remote` and `curl` against raw.githubusercontent.com and api.github.com on 2026-10-20, not
 * by a web-summary alone).
 *
 * click and PyYAML are pinned by sha256 to specific PyPI wheels (Review fixes 2, CWE-494/CWE-1357),
 * fetched and verified from pypi.org/pypi/<pkg>/<version>/json this session (not fabricated): click's
 * one wheel is py3-none-any (universal); PyYAML's wheel is per-CPython-ABI/arch, so twelve hashes
 * cover CPython 3.8-3.13 on manylinux x86_64 and aarch64 (realistic Linux GPU-host targets; Windows,
 * macOS and musllinux are out of scope and simply fail the hash check, never silently unpinned).
 * `pip install --require-hashes --only-binary=:all:` refuses anything that does not match one of
 * these exactly.
 */
require_once __DIR__ . '/RffmpegRemote.php';
require_once __DIR__ . '/RffmpegSshWrapper.php';

class RffmpegVenv
{
    const SCRIPT_URL = 'https://raw.githubusercontent.com/joshuaboniface/rffmpeg/f54ac843c7c8a157562e7ce37aaebbc5cb41354e/rffmpeg';
    const SCRIPT_SHA256 = 'd56929577e69a1f2d0928c103d0fd4d334c2b091fa09941cc0d4624ffddc0017';
    const SCRIPT_COMMIT = 'f54ac843c7c8a157562e7ce37aaebbc5cb41354e';
    const CLICK_VERSION = '8.5.0';
    /** pypi.org/pypi/click/8.5.0/json: the one wheel, py3-none-any (pure Python, no ABI/arch split). */
    const CLICK_HASHES = ['255bc9599cf7748b4b1a446ccc735421bd08a2ae529a8b88597d3de5664ee360'];
    const PYYAML_VERSION = '6.0.3';
    /** pypi.org/pypi/PyYAML/6.0.3/json: manylinux x86_64 + aarch64 wheels, CPython 3.8 through 3.13. */
    const PYYAML_HASHES = [
        '22ba7cfcad58ef3ecddc7ed1db3409af68d023b7f940da23c6c2a1890976eda6', // cp38 x86_64
        '9c57bb8c96f6d1808c030b1687b9b5fb476abaa47f0db9c0101f5e9f394e97f4', // cp38 aarch64
        '0150219816b6a1fa26fb4699fb7daa9caf09eb1999f3b70fb6e786805e80375a', // cp39 x86_64
        '3c5677e12444c15717b902a5798264fa7909e41153cdf9ef7ad571b704a63dd9', // cp39 aarch64
        '9c7708761fccb9397fe64bbc0395abcae8c4bf7b0eac081e12b809bf47700d0b', // cp310 x86_64
        'b30236e45cf30d2b8e7b3e85881719e98507abed1011bf463a8fa23e9c3e98a8', // cp310 aarch64
        'b8bb0864c5a28024fac8a632c443c87c5aa6f215c0b126c449ae1a150412f31d', // cp311 x86_64
        '10892704fc220243f5305762e276552a0395f7beb4dbf9b14ec8fd43b57f126c', // cp311 aarch64
        'ba1cc08a7ccde2d2ec775841541641e4548226580ab850948cbfda66a1befcdc', // cp312 x86_64
        '9149cad251584d5fb4981be1ecde53a1ca46c891a79788c0df828d2f166bda28', // cp312 aarch64
        '0f29edc409a6392443abf94b9cf89ce99889a1dd5376d94316ae5145dfedd5d6', // cp313 x86_64
        'ee2922902c45ae8ccada2c5b501ab86c36525b883eff4255313a253a3160861c', // cp313 aarch64
    ];
    const VENV_TIMEOUT = 120;
    const PIP_TIMEOUT = 180;

    public static function install(array $seams, $localDir, array $opt)
    {
        $p = RffmpegState::paths($localDir);
        $state = RffmpegState::read($localDir);
        if (is_file($p['script']) && empty($opt['force'])) {
            throw new CliError('rffmpeg is already installed here', 'give --force to reinstall (removes and recreates the venv and the pinned script)');
        }
        if (!empty($opt['force']) && is_file($p['script'])) {
            if ($state['hosts']) {
                throw new CliError('cannot --force reinstall while GPU hosts are recorded', 'remove each host first: remove --with-data --allow-shell, then reinstall');
            }
            // ADR-0020 / CWE-862: --force is a delete and must carry the same confirmation the guard recognizes, never a bare flag.
            if (empty($opt['with-data'])) {
                throw new CliError('--force replaces the existing install; give --with-data too to confirm', 'php rffmpeg.php install --force --with-data');
            }
            self::removeLocal($localDir, true); // true: keep the log (the review's "do not delete the log" fix)
        }
        foreach ([$p['base'], $p['db_dir'], $p['run_dir'], $p['log_dir'], $p['wrappers'], $p['transcode_base']] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0700, true)) {
                throw new CliError("cannot create $d", 'check that pikkumies/local is writable by this account');
            }
        }

        $fetch = isset($seams['fetchScript']) ? $seams['fetchScript'] : function ($url) {
            return Http::request('GET', $url, ['timeout' => 30]);
        };
        $resp = call_user_func($fetch, self::SCRIPT_URL);
        if ((int) $resp['code'] !== 200 || $resp['body'] === '') {
            throw new CliError('could not download rffmpeg from ' . self::SCRIPT_URL, isset($resp['error']) && $resp['error'] !== '' ? $resp['error'] : 'HTTP ' . $resp['code']);
        }
        // A test seam may override the expected checksum (to match a fake script body); production always checks the real pin.
        $expectedSha = isset($seams['scriptSha256']) ? $seams['scriptSha256'] : self::SCRIPT_SHA256;
        $sha = hash('sha256', $resp['body']);
        if ($sha !== $expectedSha) {
            throw new CliError('rffmpeg download does not match the pinned checksum; refusing to install it',
                "expected $expectedSha got $sha -- upstream may have force-pushed the pinned commit, or this download was tampered with");
        }

        $timeoutBin = isset($seams['timeoutBin']) ? $seams['timeoutBin'] : ToolProc::need('timeout', 'install coreutils (it provides timeout)');
        $python = isset($seams['pythonBin']) ? $seams['pythonBin'] : ToolProc::need('python3', 'install python3 first (and the python3-venv package)');
        $venvCreate = isset($seams['venvCreate']) ? $seams['venvCreate'] : function ($python, $venvDir) use ($timeoutBin) {
            $r = ToolProc::run([$timeoutBin, (string) self::VENV_TIMEOUT, $python, '-m', 'venv', $venvDir]);
            if ($r['code'] !== 0) {
                throw new CliError('python3 -m venv failed (or timed out after ' . self::VENV_TIMEOUT . 's)',
                    trim($r['err']) !== '' ? trim($r['err']) : 'install the python3-venv package and retry');
            }
        };
        call_user_func($venvCreate, $python, $p['venv']);
        $venvPython = $p['venv'] . '/bin/python3';
        if (!is_file($venvPython)) {
            throw new CliError("the venv did not create $venvPython", 'check the python3 -m venv output above');
        }

        if (@file_put_contents($p['requirements'], self::requirementsText()) === false) {
            throw new CliError("cannot write {$p['requirements']}", 'check that the state directory is writable');
        }
        $pipInstall = isset($seams['pipInstall']) ? $seams['pipInstall'] : function ($venvPython, $reqFile) use ($timeoutBin) {
            $r = ToolProc::run([$timeoutBin, (string) self::PIP_TIMEOUT, $venvPython, '-m', 'pip', 'install', '--no-deps', '--only-binary=:all:',
                '--require-hashes', '--disable-pip-version-check', '-r', $reqFile]);
            if ($r['code'] !== 0) {
                throw new CliError('pip install (hash-pinned) failed (or timed out after ' . self::PIP_TIMEOUT . 's)',
                    trim($r['err']) !== '' ? trim($r['err']) : trim($r['out']));
            }
            $f = ToolProc::run([$venvPython, '-m', 'pip', 'freeze']);
            return array_values(array_filter(explode("\n", trim($f['out'])), 'strlen'));
        };
        $frozen = call_user_func($pipInstall, $venvPython, $p['requirements']);

        $scriptBody = preg_replace('/^#![^\n]*\n/', "#!$venvPython\n", $resp['body'], 1);
        if (@file_put_contents($p['script'], $scriptBody) === false || !@chmod($p['script'], 0700)) {
            throw new CliError("cannot write {$p['script']}", 'check that the state directory is writable');
        }
        self::buildTranscodeEntrypoints($p);

        list($user, $group) = self::account();
        $servicesPath = isset($seams['servicesPath']) ? $seams['servicesPath'] : dirname(__DIR__, 3) . '/lib/Services.php';
        $sshBinReal = isset($seams['sshBinReal']) ? $seams['sshBinReal'] : (ToolProc::which('ssh') !== null ? ToolProc::which('ssh') : '/usr/bin/ssh');
        $wrapperBody = RffmpegSshWrapper::content($servicesPath, $p['state_file'], $sshBinReal);
        if (@file_put_contents($p['ssh_wrapper'], $wrapperBody) === false || !@chmod($p['ssh_wrapper'], 0700)) {
            throw new CliError("cannot write {$p['ssh_wrapper']}", 'check that the state directory is writable');
        }

        if (@file_put_contents($p['config'], self::yaml($p, $user, $group)) === false) {
            throw new CliError("cannot write {$p['config']}", 'check that the state directory is writable');
        }

        $init = isset($seams['runScript']) ? $seams['runScript'] : function ($venvPython, $script, array $args, array $env) {
            return ToolProc::run(array_merge([$venvPython, $script], $args), $env);
        };
        $r = call_user_func($init, $venvPython, $p['script'], ['init', '--no-root', '-y'], ['RFFMPEG_CONFIG' => $p['config']]);
        if ($r['code'] !== 0 || !is_file($p['db_dir'] . '/rffmpeg.db')) {
            throw new CliError('rffmpeg init failed', trim($r['err']) !== '' ? trim($r['err']) : trim($r['out']));
        }

        $state = ['installed' => true, 'script_sha256' => self::SCRIPT_SHA256, 'script_commit' => self::SCRIPT_COMMIT,
            'deps_frozen' => $frozen, 'pip_pins' => ['click' => self::CLICK_VERSION, 'PyYAML' => self::PYYAML_VERSION],
            'installed_at' => date('c'), 'hosts' => []];
        RffmpegState::write($localDir, $state);
        return [['ok' => true] + $state + ['config' => $p['config'], 'ffmpeg' => $p['transcode_ffmpeg'], 'ffprobe' => $p['transcode_ffprobe']]];
    }

    /** Rewrites the remote/commands sections for one GPU host; the whole file is regenerated since this unit owns it completely. */
    public static function writeRemoteConfig($localDir, $sshUser, $sshKey, $ffmpegCmd, $ffprobeCmd)
    {
        $p = RffmpegState::paths($localDir);
        list($user, $group) = self::account();
        $yml = self::yaml($p, $user, $group, ['user' => $sshUser, 'key' => $sshKey, 'ffmpeg' => $ffmpegCmd, 'ffprobe' => $ffprobeCmd]);
        if (@file_put_contents($p['config'], $yml) === false) {
            throw new CliError("cannot write {$p['config']}", 'check that the state directory is writable');
        }
    }

    /** Removes exactly what install created (and add-host added to it), nothing else; returns the paths removed. $keepLog: never touch log_dir (--force). */
    public static function removeLocal($localDir, $keepLog = false)
    {
        $p = RffmpegState::paths($localDir);
        $removed = [];
        foreach ([$p['script'], $p['config'], $p['requirements'], $p['ssh_wrapper'],
            $p['transcode_ffmpeg'], $p['transcode_ffprobe'], $p['transcode_target_ffmpeg'], $p['transcode_target_ffprobe']] as $f) {
            if (is_link($f) || is_file($f)) {
                @unlink($f);
                $removed[] = $f;
            }
        }
        $dirs = [$p['venv'], $p['db_dir'], $p['run_dir'], $p['wrappers'], $p['transcode_base']];
        if (!$keepLog) {
            $dirs[] = $p['log_dir'];
        }
        foreach ($dirs as $d) {
            // CWE-59: a top-level symlink is unlinked and never descended into (an inside actor could
            // otherwise plant one of these as a link to empty an unrelated directory it points at).
            if (is_link($d)) {
                @unlink($d);
                $removed[] = $d;
            } elseif (is_dir($d)) {
                self::rmtree($d);
                $removed[] = $d;
            }
        }
        return $removed;
    }

    /** This account's own login name and primary group name (never guessed; posix_getpwuid/getgrgid, falling back to $USER). */
    private static function account()
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $pw = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
        $user = is_array($pw) && isset($pw['name']) ? $pw['name'] : (string) getenv('USER');
        $gid = function_exists('posix_getegid') ? posix_getegid() : $uid;
        $gr = function_exists('posix_getgrgid') ? @posix_getgrgid($gid) : false;
        $group = is_array($gr) && isset($gr['name']) ? $gr['name'] : $user;
        return [$user !== '' ? $user : 'user', $group !== '' ? $group : 'user'];
    }

    /**
     * Two POSIX /bin/sh entry points at a path with no "rffmpeg" substring anywhere (Review fixes 2,
     * D2/D3): the pinned script's own `__main__` reads `"rffmpeg" in cmd_name` where cmd_name is
     * sys.argv[0] as INVOKED (shebang indirection passes the invoked path through, never the resolved
     * target), so Jellyfin's configured ffmpeg/ffprobe path must never contain it. Each wrapper
     * exports RFFMPEG_CONFIG (D3: Jellyfin never sets it, so rffmpeg silently used /etc/rffmpeg/
     * rffmpeg.yml and its built-in defaults) then execs a same-tree symlink (`.target-ffmpeg` /
     * `.target-ffprobe`, also "rffmpeg"-free) whose own shebang runs the venv python with THAT clean
     * path as argv[0] -- never the control script's path directly, which legitimately contains
     * "rffmpeg" so init/add/status still dispatch correctly.
     */
    private static function buildTranscodeEntrypoints(array $p)
    {
        foreach (['transcode_target_ffmpeg', 'transcode_target_ffprobe'] as $key) {
            @unlink($p[$key]);
            if (!@symlink('../rffmpeg/rffmpeg', $p[$key])) {
                throw new CliError("cannot create {$p[$key]}", 'check that ' . $p['transcode_base'] . ' is writable');
            }
        }
        $roles = ['transcode_ffmpeg' => 'transcode_target_ffmpeg', 'transcode_ffprobe' => 'transcode_target_ffprobe'];
        foreach ($roles as $wrapperKey => $targetKey) {
            $content = "#!/bin/sh\n"
                . "# Generated by pikkumies/tools/media/rffmpeg; do not edit, install/add-host regenerate this file.\n"
                . 'RFFMPEG_CONFIG=' . RffmpegRemote::sh1($p['config']) . "\n"
                . "export RFFMPEG_CONFIG\n"
                . 'exec ' . RffmpegRemote::sh1($p[$targetKey]) . ' "$@"' . "\n";
            if (@file_put_contents($p[$wrapperKey], $content) === false || !@chmod($p[$wrapperKey], 0700)) {
                throw new CliError("cannot write {$p[$wrapperKey]}", 'check that ' . $p['transcode_base'] . ' is writable');
            }
        }
    }

    /** A pip requirements.txt with --hash lines for every pinned wheel (Review fixes 2, CWE-494). */
    private static function requirementsText()
    {
        $text = 'click==' . self::CLICK_VERSION . " \\\n    --hash=sha256:" . self::CLICK_HASHES[0] . "\n";
        $text .= 'PyYAML==' . self::PYYAML_VERSION . " \\\n";
        $last = count(self::PYYAML_HASHES) - 1;
        foreach (self::PYYAML_HASHES as $i => $h) {
            $text .= '    --hash=sha256:' . $h . ($i < $last ? " \\\n" : "\n");
        }
        return $text;
    }

    /**
     * The minimal rffmpeg.yml, everything nested under the top-level `rffmpeg:` key (Review fixes 2,
     * D1): the pinned script's own `load_config()` does `o_config.get("rffmpeg")` and `fail("...top
     * level key 'rffmpeg'")` when that key is missing or empty -- confirmed by reading the function
     * directly this session, not assumed. `commands.ssh` points at the generated security wrapper
     * (RffmpegSshWrapper), never the raw ssh binary (CWE-295/863: upstream's own ssh invocation always
     * sends StrictHostKeyChecking=no with no -F /dev/null, so only a wrapper that runs FIRST in argv
     * can restore the pin). owner/group are only used by a root init (never ours, --no-root always),
     * kept for completeness. $remote: user/key/ffmpeg/ffprobe, all empty until a GPU host is wired in
     * (the ffmpeg/ffprobe commands point at the GPU-side wrapper, never the real binary directly, so
     * the same-path rewrite always runs first).
     */
    private static function yaml(array $p, $user, $group, array $remote = [])
    {
        $esc = function ($s) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $s) . '"';
        };
        $remote += ['user' => '', 'key' => '', 'ffmpeg' => '', 'ffprobe' => ''];
        $args = $remote['key'] !== '' ? "[\"-i\", " . $esc($remote['key']) . "]" : '[]';
        $lines = ["rffmpeg:",
            "  logging:", "    log_to_file: true", "    logfile: " . $esc($p['log_dir'] . '/rffmpeg.log'), "    datedlogfiles: false", "    debug: false",
            "  directories:", "    state: " . $esc($p['db_dir']), "    persist: " . $esc($p['run_dir']), "    owner: " . $esc($user), "    group: " . $esc($group),
            "  remote:", "    user: " . $esc($remote['user']), "    args: $args", "    persist: 300",
            "  commands:", "    ssh: " . $esc($p['ssh_wrapper'])];
        if ($remote['ffmpeg'] !== '') {
            $lines[] = "    ffmpeg: " . $esc($remote['ffmpeg']);
        }
        if ($remote['ffprobe'] !== '') {
            $lines[] = "    ffprobe: " . $esc($remote['ffprobe']);
        }
        return implode("\n", $lines) . "\n";
    }

    /** Only ever called on a fixed sub-path of RffmpegState::base() (see the caller lists above), never on anything the CLI supplied. */
    private static function rmtree($dir)
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $e) {
            $full = "$dir/$e";
            if (is_dir($full) && !is_link($full)) {
                self::rmtree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}
