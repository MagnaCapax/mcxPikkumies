<?php
/**
 * RffmpegRemote: everything this unit runs on the GPU service over Services' shell transport (never
 * the forced-command serve, which has no ffmpeg verb in its whitelist, and never tunnel, which runs
 * no commands -- SPEC.md "Remote serve"; RffmpegServices::shellService refuses any other transport).
 *
 * Every call sends `['sh', '-c', $fixedScript, 'rf-<name>', $data1, $data2, ...]`: a FIXED script text
 * (no interpolation) plus untrusted data as separate positional words ($1, $2, ... inside the
 * script), never baked into the script text. This matters because of how Services::sshArgv's shell
 * transport actually works (lib/Services.php, owner A): it single-quotes EACH array element as its
 * OWN word, then ssh joins ALL the trailing words with spaces and hands that one string to the
 * remote's default shell to parse as a command line. The first (quoted-then-dequoted) word on that
 * line is read as the COMMAND NAME. Sending a whole multi-line script as ONE word therefore makes the
 * remote shell try to execute the ENTIRE SCRIPT TEXT as a program name ("command not found") --
 * caught by integration against A's real Services, tests/fixtures hid it because they never ran a
 * real shell. `sh -c <script> <name> <args...>` is the correct shape: "sh" becomes the command name
 * (the first word), "-c" its flag, and the script is sh's OWN argument (sh parses and runs it, it is
 * never re-parsed as a command name); the words after that become $1, $2, ... inside the script.
 */
class RffmpegRemote
{
    /** The paths rffmpeg's own sample config tries (rffmpeg.yml.sample, commands group, fetched 2026-10-20 at commit f54ac843...). */
    const FFMPEG_CANDIDATES = ['/usr/lib/jellyfin-ffmpeg/ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'];
    const FFPROBE_CANDIDATES = ['/usr/lib/jellyfin-ffmpeg/ffprobe', '/usr/bin/ffprobe', '/usr/local/bin/ffprobe'];
    /** media/ffmpeg/FfmpegHw.php DEVICE: the same render node this unit checks locally, checked remotely here. */
    const DRI_DEVICE = '/dev/dri/renderD128';

    /** Single-quotes $s for literals baked into a script's own text (the wrapper file this unit writes, README.md
     * "same-path problem") -- never for building the SSH transport call itself; see this file's own docblock. */
    public static function sh1($s)
    {
        return "'" . str_replace("'", "'\\''", (string) $s) . "'";
    }

    /**
     * ['ffmpeg_path', 'ffprobe_path' (first candidate that is -x on that host, or ''), 'gpu_usable',
     * 'gpu_gap' (FfmpegHw.php's gap idiom: null when usable, else the exact reason), 'groups', 'vainfo'].
     */
    public static function probe(RffmpegServices $gw, $nick)
    {
        $r = $gw->run($nick, ['sh', '-c', self::probeScript()]);
        $vals = self::parseKv((string) $r['stdout']);
        $driExists = isset($vals['DRI_EXISTS']) && $vals['DRI_EXISTS'] === '1';
        $driRw = !empty($vals['DRI_R']) && !empty($vals['DRI_W']);
        $gap = null;
        if (!$driExists) {
            $gap = self::DRI_DEVICE . ' does not exist on this host; no GPU render node to use';
        } elseif (!$driRw) {
            $gap = self::DRI_DEVICE . ' exists but this account cannot read and write it (groups: '
                . (isset($vals['GROUPS']) ? $vals['GROUPS'] : '?') . '); ask whoever administers that host to add it to the device group';
        }
        return ['ffmpeg_path' => isset($vals['FFMPEG']) ? $vals['FFMPEG'] : '', 'ffprobe_path' => isset($vals['FFPROBE']) ? $vals['FFPROBE'] : '',
            'gpu_usable' => $gap === null, 'gpu_gap' => $gap, 'groups' => isset($vals['GROUPS']) ? $vals['GROUPS'] : '',
            'vainfo' => !empty($vals['HAS_VAINFO']), 'rc' => (int) $r['rc']];
    }

    /**
     * The systemd --user mount gotcha test (README, "The systemd --user mount gotcha").
     * Review fixes 2, D4: the old script took `head`'s exit status as the result (`; echo "RC=$?"`
     * after a `test -d && ls -A | head`), so an empty, unmounted directory still answered rc=0
     * ("visible"); and parseKv's first-match-wins picked up a FILE literally named "RC=0" from the
     * listing before the real result. This version checks the mount is actually a mount point
     * (`mountpoint -q`, falling back to a device-number comparison against the parent directory when
     * `mountpoint` is not installed) AND non-empty, and reports a long, namespaced sentinel line
     * BEFORE the `ls -A -- "$mount"` listing, so a maliciously-named file can never be mistaken for it.
     */
    public static function mountVisible(RffmpegServices $gw, $nick, $mount)
    {
        $script = 'mount="$1"; '
            . 'if command -v mountpoint >/dev/null 2>&1; then mountpoint -q -- "$mount"; mp=$?; '
            . 'else d1=$(stat -c %d -- "$mount" 2>/dev/null); d2=$(stat -c %d -- "$mount/.." 2>/dev/null); '
            . 'if [ -n "$d1" ] && [ -n "$d2" ] && [ "$d1" != "$d2" ]; then mp=0; else mp=1; fi; fi; '
            . 'ne=1; if [ "$mp" -eq 0 ] && [ -n "$(ls -A -- "$mount" 2>/dev/null)" ]; then ne=0; fi; '
            . 'if [ "$mp" -eq 0 ] && [ "$ne" -eq 0 ]; then echo "PKRF_MOUNT_RESULT=visible"; else echo "PKRF_MOUNT_RESULT=not-visible"; fi; '
            . 'ls -A -- "$mount" 2>&1 | head -5';
        $r = $gw->run($nick, ['sh', '-c', $script, 'rf-mount', $mount]);
        $out = (string) $r['stdout'];
        $result = '';
        foreach (explode("\n", $out) as $line) {
            if (strncmp($line, 'PKRF_MOUNT_RESULT=', 18) === 0) {
                $result = $line;
                break;
            }
        }
        return ['checked' => true, 'visible' => $result === 'PKRF_MOUNT_RESULT=visible', 'rc' => (int) $r['rc'],
            'sample' => trim($out), 'stderr' => trim((string) $r['stderr'])];
    }

    /**
     * POSIX /bin/sh content for the GPU-side wrapper: rewrites every argv element (and TMPDIR) that
     * starts with $from to start with $to instead, then execs the real ffmpeg/ffprobe binary with the
     * rewritten arguments (the "same-path problem": the rclone sftp mount of this box's home on the
     * GPU host; README, "The same-path problem"). The arg_N/eval pair is the
     * standard POSIX-sh array substitute: it stores and restores arbitrary strings (spaces, quotes)
     * without ever evaluating the VALUE, only a fixed integer variable name, so it is not injectable.
     * This is the deployed FILE's own source text (run later, standalone, when Jellyfin calls it) --
     * unrelated to the SSH transport that WRITES the file (deployWrapper, below), so sh1() is correct
     * and required here. Review fixes 2, item 8: `${var#$pattern}` treats an UNQUOTED pattern
     * variable as a glob (confirmed: `/home/u*` and `/home/[x]` both matched paths they should not
     * have matched literally), so both `#` removals quote the pattern (`#"$from"`), which POSIX
     * requires to disable glob characters in the expanded value.
     */
    public static function wrapperScript($from, $to, $real)
    {
        return "#!/bin/sh\n"
            . "# Generated by Pikkumies tools/media/rffmpeg (README.md \"same-path problem\"); do not edit, add-host regenerates this file.\n"
            . 'from=' . self::sh1($from) . "\n"
            . 'to=' . self::sh1($to) . "\n"
            . 'real=' . self::sh1($real) . "\n"
            . 'case "$TMPDIR" in' . "\n"
            . '  "$from"|"$from"/*) TMPDIR="$to${TMPDIR#"$from"}"; export TMPDIR ;;' . "\n"
            . 'esac' . "\n"
            . 'i=0' . "\n"
            . 'for pkrf_a in "$@"; do' . "\n"
            . '  i=$((i+1))' . "\n"
            . '  eval "pkrf_arg_$i=\$pkrf_a"' . "\n"
            . 'done' . "\n"
            . 'set --' . "\n"
            . 'j=0' . "\n"
            . 'while [ "$j" -lt "$i" ]; do' . "\n"
            . '  j=$((j+1))' . "\n"
            . '  eval "pkrf_a=\$pkrf_arg_$j"' . "\n"
            . '  case "$pkrf_a" in' . "\n"
            . '    "$from"|"$from"/*) pkrf_a="$to${pkrf_a#"$from"}" ;;' . "\n"
            . '  esac' . "\n"
            . '  set -- "$@" "$pkrf_a"' . "\n"
            . 'done' . "\n"
            . 'exec "$real" "$@"' . "\n";
    }

    /**
     * Writes $content to <$gpuHome>/.pikkumies-rffmpeg/$role (ffmpeg|ffprobe), executable; returns the
     * remote path. $dir, $role and $content travel as separate positional words ($1/$2/$3), never
     * interpolated into the script text -- so $content (arbitrary file bytes, including single quotes
     * and newlines from wrapperScript()) is written with a plain `printf '%s'`, no heredoc, no marker
     * that content could ever collide with. Review fixes 2, item 8: `umask 077` first (so `mkdir -p`
     * creates the directory 0700 too, not whatever the remote account's own umask happens to be) and
     * `chmod 0700` (not 0755) on the file -- CWE-200/732, the wrapper's path names this account's home
     * and the mount point, and it has no reason to be group/other-readable.
     */
    public static function deployWrapper(RffmpegServices $gw, $nick, $gpuHome, $role, $content)
    {
        $dir = rtrim($gpuHome, '/') . '/.pikkumies-rffmpeg';
        $file = "$dir/$role";
        $script = 'umask 077; dir="$1"; role="$2"; mkdir -p "$dir" && printf \'%s\' "$3" > "$dir/$role.new" && chmod 0700 "$dir/$role.new" '
            . '&& mv "$dir/$role.new" "$dir/$role" && echo PKRF_DEPLOYED';
        $r = $gw->run($nick, ['sh', '-c', $script, 'rf-deploy', $dir, $role, $content]);
        if ((int) $r['rc'] !== 0 || strpos((string) $r['stdout'], 'PKRF_DEPLOYED') === false) {
            throw new CliError("could not deploy the $role wrapper on $nick", trim((string) $r['stderr']) ?: 'check the shell-transport SSH access to this service');
        }
        return $file;
    }

    /**
     * Removes exactly the two wrapper files this unit deployed (and their .new leftovers), and the
     * directory if now empty -- nothing else. Review fixes 2, item 10: takes the RECORDED
     * ffmpeg/ffprobe wrapper paths (state.json) rather than re-deriving them from the service's
     * CURRENT `home` -- if that changed since add-host (services edit, or a stale/garbage value), a
     * re-derived path would miss the real files, leaving them orphaned forever with `remove` wrongly
     * reporting success.
     */
    public static function removeWrapper(RffmpegServices $gw, $nick, $ffmpegPath, $ffprobePath)
    {
        $dir = dirname($ffmpegPath);
        $script = 'f1="$1"; f2="$2"; d="$3"; rm -f "$f1" "$f2" "$f1.new" "$f2.new"; rmdir "$d" 2>/dev/null; echo PKRF_DONE';
        $r = $gw->run($nick, ['sh', '-c', $script, 'rf-remove', $ffmpegPath, $ffprobePath, $dir]);
        return ['ok' => (int) $r['rc'] === 0 && strpos((string) $r['stdout'], 'PKRF_DONE') !== false, 'stderr' => trim((string) $r['stderr'])];
    }

    /** No variable data at all: every candidate path is a fixed literal, so this is baked into the script text itself. */
    private static function probeScript()
    {
        $ffmpegList = implode(' ', array_map(['RffmpegRemote', 'sh1'], self::FFMPEG_CANDIDATES));
        $ffprobeList = implode(' ', array_map(['RffmpegRemote', 'sh1'], self::FFPROBE_CANDIDATES));
        $dri = self::sh1(self::DRI_DEVICE);
        return "for f in $ffmpegList; do [ -x \"\$f\" ] && { echo \"FFMPEG=\$f\"; break; }; done\n"
            . "for f in $ffprobeList; do [ -x \"\$f\" ] && { echo \"FFPROBE=\$f\"; break; }; done\n"
            . "if [ -e $dri ]; then echo DRI_EXISTS=1; [ -r $dri ] && echo DRI_R=1; [ -w $dri ] && echo DRI_W=1; else echo DRI_EXISTS=0; fi\n"
            . "echo \"GROUPS=\$(id -nG 2>/dev/null)\"\n"
            . "command -v vainfo >/dev/null 2>&1 && echo HAS_VAINFO=1\n";
    }

    /** "KEY=value" lines -> [KEY => value]; the first line wins for a repeated key. */
    private static function parseKv($text)
    {
        $vals = [];
        foreach (explode("\n", $text) as $line) {
            $eq = strpos($line, '=');
            if ($eq !== false && !isset($vals[substr($line, 0, $eq)])) {
                $vals[substr($line, 0, $eq)] = substr($line, $eq + 1);
            }
        }
        return $vals;
    }
}
