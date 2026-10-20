<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030, ADR-0099): before a call rewrites a small existing
 * file, a copy of it as it was. It reads write_file and patch (the path, or each file a V4A patch names) and terminal
 * commands that rewrite in place: `sed -i`, a `>` redirect (never `>>`), `truncate`. A regular file of at most 1 MiB that
 * this account owns is copied to local/tmp/pre-edit/<session id>/<its full path>: a private tmp folder under the account's
 * quota (lib/LocalTmp.php: folders 0700 of this uid, never through a symlink), files 0600, at most 50 MB per session.
 * Root and sudo are the same rule: as uid 0 any owner's file is copied, and a `sudo sed -i` or `sudo truncate` of a file
 * another account owns is copied through `sudo -n cat`, which never prompts (no passwordless sudo: no copy). A copy is
 * written as .part, checked against the opened file's own size (fstat, the same file that was checked), and renamed only
 * when whole. The first copy of a session leaves one hook note saying where copies go, and removes session folders older
 * than 7 days; an untrusted folder leaves one note saying copies are off. It never blocks or asks. Fails open (3 s).
 * A safety net for the agent's own bad edits, not a backup: the copy is on this machine and goes with it.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/ToolProc.php';
require_once dirname(__DIR__, 3) . '/lib/FileopsClassify.php';
require_once dirname(__DIR__, 3) . '/lib/PatchPaths.php';
require_once dirname(__DIR__, 3) . '/lib/HookNotes.php';
require_once dirname(__DIR__, 3) . '/lib/LocalTmp.php';
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

const PRE_EDIT_FILE_MAX = 1048576;
const PRE_EDIT_SESSION_MAX = 52428800;
const PRE_EDIT_KEEP = 604800;
const PRE_EDIT_SESSION_RE = '/^[A-Za-z0-9._-]{1,128}$/';

/** The files a call is about to rewrite, as written (relative ones are resolved by the caller): [['path', 'sudo' => run through sudo]]. */
function preEditTargets(array $payload)
{
    $tool = isset($payload['tool_name']) ? (string) $payload['tool_name'] : '';
    $in = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
    $plain = function (array $paths) {
        return array_map(function ($p) {
            return ['path' => (string) $p, 'sudo' => false];
        }, $paths);
    };
    if ($tool === 'write_file' || $tool === 'patch') {
        $named = $tool === 'patch' && isset($in['patch']) && is_string($in['patch']) ? PatchPaths::v4a($in['patch']) : [];
        return $plain(array_merge(isset($in['path']) && is_string($in['path']) ? [$in['path']] : [], is_array($named) ? $named : []));
    }
    if ($tool !== 'terminal' || !isset($in['command']) || !is_string($in['command'])) {
        return [];
    }
    $t = FileopsClassify::words($in['command']);
    $out = [];
    foreach ($t['redirects'] as $r) {
        if (in_array($r[0], ['>', '>|', '&>'], true) && $r[1] !== '' && !preg_match('/^(\d+|-)$/', $r[1])) {
            $out[] = $r[1]; // >> appends and 2>&1 duplicates: neither rewrites a file. The redirect is the user's shell, never sudo's.
        }
    }
    $out = $plain($out);
    $seg = [];
    foreach (array_merge($t['words'], [['op' => ';']]) as $w) {
        if (!isset($w['op'])) {
            $seg[] = $w['w'];
            continue;
        }
        list($files, $sudo) = preEditSegment($seg);
        foreach ($files as $f) {
            $out[] = ['path' => $f, 'sudo' => $sudo];
        }
        $seg = [];
    }
    return $out;
}

/** [files one simple command rewrites in place (sed -i or --in-place, truncate), whether it runs through sudo]. */
function preEditSegment(array $seg)
{
    $sudo = false;
    while ($seg && (in_array($seg[0], ['sudo', 'env', 'command', 'exec', 'nice', 'nohup', 'time'], true) || preg_match('/^[A-Za-z_]\w*=|^-/', $seg[0]))) {
        $sudo = $sudo || $seg[0] === 'sudo';
        array_shift($seg);
    }
    $verb = $seg ? PathName::base(array_shift($seg)) : '';
    $files = [];
    $script = false;
    $inPlace = false;
    for ($i = 0, $n = count($seg); $i < $n; $i++) {
        $w = $seg[$i];
        if ($verb === 'sed' && preg_match('/^(-i|--in-place)/', $w)) {
            $inPlace = true;
        } elseif ($verb === 'sed' && in_array($w, ['-e', '-f', '--expression', '--file'], true)) {
            list($script, $i) = [true, $i + 1];
        } elseif ($verb === 'sed' && preg_match('/^(-[ef].|--expression=|--file=)/', $w)) {
            $script = true;
        } elseif ($verb === 'truncate' && in_array($w, ['-s', '--size', '-r', '--reference'], true)) {
            $i++;
        } elseif ($w !== '' && $w[0] === '-') {
            continue;
        } elseif ($verb === 'sed' && !$script) {
            $script = true; // the first plain word is the script
        } else {
            $files[] = $w;
        }
    }
    return [($verb === 'sed' && $inPlace) || $verb === 'truncate' ? $files : [], $sudo];
}

/** The session's private copy folder, local/tmp/pre-edit/<session>, or null when a folder on the way cannot be trusted. */
function preEditDir($localDir, $uid, $session)
{
    return LocalTmp::dir($localDir, "pre-edit/$session", $uid);
}

/** Removes session folders older than PRE_EDIT_KEEP under $base: only this account's own files and folders, never a link's target. */
function preEditPrune($base, $uid, $now)
{
    foreach ((array) @scandir($base) as $name) {
        $p = "$base/$name";
        $st = $name === '.' || $name === '..' ? false : @lstat($p);
        if ($st !== false && ($st['mode'] & 0170000) === 0040000 && (int) $st['uid'] === (int) $uid && $st['mtime'] < $now - PRE_EDIT_KEEP) {
            preEditRemove($p, $uid);
        }
    }
}

function preEditRemove($p, $uid)
{
    $st = @lstat($p);
    if ($st === false || (int) $st['uid'] !== (int) $uid) {
        return;
    }
    if (($st['mode'] & 0170000) === 0040000) {
        foreach ((array) @scandir($p) as $name) {
            if ($name !== '.' && $name !== '..') {
                preEditRemove("$p/$name", $uid);
            }
        }
        @rmdir($p);
    } else {
        @unlink($p);
    }
}

/**
 * Copies what the call is about to rewrite; returns the copies made (the hook itself prints nothing). Everything it writes
 * is 0600/0700. $run (test seam): fn(argv) => ['code', 'out', 'err'] instead of ToolProc::run for the sudo copy.
 */
function preEditCopy(array $payload, $localDir, $uid, $now, $run = null)
{
    $mask = umask(0077);
    try {
        return preEditCopyFiles($payload, $localDir, $uid, $now, $run);
    } finally {
        umask($mask);
    }
}

function preEditCopyFiles(array $payload, $localDir, $uid, $now, $run)
{
    $session = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
    $targets = preEditTargets($payload);
    if (!$targets || !preg_match(PRE_EDIT_SESSION_RE, $session) || trim($session, '.') === '') { // . and .. are not folder names
        return [];
    }
    $in = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
    // A terminal command's relative path is read against its workdir only (the payload's cwd is Hermes' folder, ADR-0048); a file tool's
    // relative path against the cwd Hermes resolves it from.
    $terminal = isset($payload['tool_name']) && $payload['tool_name'] === 'terminal';
    $cwd = isset($in['workdir']) && is_string($in['workdir']) ? $in['workdir'] : (!$terminal && isset($payload['cwd']) && is_string($payload['cwd']) ? $payload['cwd'] : '');
    $home = rtrim((string) getenv('HOME'), '/');
    $dir = null;
    $made = [];
    $seen = [];
    foreach ($targets as $target) {
        $t = $target['path'];
        $t = $t === '~' || strpos($t, '~/') === 0 ? $home . substr($t, 1) : $t;
        $real = $t !== '' && ($t[0] === '/' || ($cwd !== '' && $cwd[0] === '/')) ? realpath($t[0] === '/' ? $t : "$cwd/$t") : false;
        if ($real === false || isset($seen[$real])) {
            continue;
        }
        $seen[$real] = true;
        $st = @stat($real);
        $mine = $st !== false && ((int) $uid === 0 || (int) $st['uid'] === (int) $uid);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || $st['size'] > PRE_EDIT_FILE_MAX || (!$mine && !$target['sudo'])) {
            continue; // missing (a new file), not a regular file, too big, or another account's without sudo
        }
        if ($dir === null && ($dir = preEditDir($localDir, $uid, $session)) === null) {
            preEditOff($localDir, $session);
            return $made;
        }
        $used = (int) @file_get_contents("$dir/.used");
        $copy = $dir . $real;
        if (is_file($copy) && @sha1_file($copy) === @sha1_file($real)) {
            continue;
        }
        $copy = is_file($copy) || is_link($copy) ? $copy . '.' . gmdate('His', $now) . '-' . mt_rand(1000, 9999) : $copy;
        if ($used + $st['size'] > PRE_EDIT_SESSION_MAX || (!is_dir(dirname($copy)) && !preEditMkdirs($dir, dirname($copy), $uid))) {
            continue;
        }
        $bytes = $mine ? preEditCopyOpen($real, $st, "$copy.part") : preEditCopySudo($real, "$copy.part", $run);
        if ($bytes !== null && @rename("$copy.part", $copy)) {
            $made[] = $copy;
            @file_put_contents("$dir/.used", (string) ($used + $bytes));
        } else {
            @unlink("$copy.part");
        }
    }
    if ($made && $dir !== null && !is_file("$dir/.noticed") && @touch("$dir/.noticed")) {
        preEditPrune(dirname($dir), $uid, $now);
        HookNotes::add($localDir, $session, 'pre-edit-copy', 'Before an edit of a small existing file, Pikkumies keeps a copy of it as it was in '
            . $dir . ' (only this account can open it; folders older than 7 days are removed). If the user wants an edit undone, the copy is there.', false);
    }
    return $made;
}

/**
 * Copies the file this account can open into $part: the opened handle must be the regular file stat() saw (same device
 * and inode, at most PRE_EDIT_FILE_MAX), and every byte of it must arrive. Returns the bytes, or null.
 */
function preEditCopyOpen($real, array $st, $part)
{
    $src = @fopen($real, 'rb');
    $fs = $src !== false ? @fstat($src) : false;
    $dst = $fs !== false && ($fs['mode'] & 0170000) === 0100000 && $fs['dev'] === $st['dev'] && $fs['ino'] === $st['ino']
        && $fs['size'] <= PRE_EDIT_FILE_MAX ? @fopen($part, 'xb') : false; // x: never over an existing name or through a planted symlink
    $n = $dst !== false ? @stream_copy_to_stream($src, $dst, PRE_EDIT_FILE_MAX + 1) : false;
    $closed = $dst !== false && @fclose($dst);
    if ($src !== false) {
        fclose($src);
    }
    return $closed && $n !== false && $n === $fs['size'] ? (int) $n : null;
}

/** Copies a file another account owns through `sudo -n cat` (never a prompt) into $part. Returns the bytes, or null. */
function preEditCopySudo($real, $part, $run)
{
    $argv = ['sudo', '-n', '--', 'cat', '--', $real];
    try {
        $r = $run !== null ? call_user_func($run, $argv) : ToolProc::run($argv);
    } catch (Exception $e) {
        return null;
    }
    if ((int) $r['code'] !== 0 || strlen($r['out']) > PRE_EDIT_FILE_MAX) {
        return null;
    }
    $dst = @fopen($part, 'xb');
    $ok = $dst !== false && @fwrite($dst, $r['out']) === strlen($r['out']);
    $ok = $dst !== false && @fclose($dst) && $ok;
    return $ok ? strlen($r['out']) : null;
}

/** One note per session that copies are off because the folder cannot be trusted (another owner, a symlink, a file). */
function preEditOff($localDir, $session)
{
    $mark = rtrim($localDir, '/') . '/state/pre-edit-copy/' . sha1($session) . '.off';
    if (is_file($mark) || (!is_dir(dirname($mark)) && !@mkdir(dirname($mark), 0700, true)) || !@touch($mark)) {
        return;
    }
    HookNotes::add($localDir, $session, 'pre-edit-copy', 'Pre-edit copies are off: ' . rtrim($localDir, '/') . '/tmp/pre-edit is not a private folder'
        . ' of this account (another owner, a symlink or a file). Tell the user; they can move it aside.', false);
}

/** mkdir -p of $target below $dir, each new folder 0700 and checked like LocalTmp::privateDir. */
function preEditMkdirs($dir, $target, $uid)
{
    $path = $dir;
    foreach (explode('/', trim(substr($target, strlen($dir)), '/')) as $part) {
        $path .= "/$part";
        if (!LocalTmp::privateDir($path, $uid)) {
            return false;
        }
    }
    return true;
}

if (realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $payload = json_decode((string) stream_get_contents(STDIN), true);
    if (is_array($payload)) {
        preEditCopy($payload, Config::localDir(), function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(), time());
    }
}
