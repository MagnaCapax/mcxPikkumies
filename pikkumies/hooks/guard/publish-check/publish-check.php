<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030, ADR-0083): before the agent publishes for the user to
 * a public place, what goes out is checked for secrets. A terminal `git push` to a remote on a public code-hosting service
 * (PublishScan::HOSTS) and the gh writes that carry text (PublishTargets) are checked unless the repository is private
 * (`gh repo view`, 4 s; unknown counts as public); a gist always is. A push to any other remote, and ssh, scp and rsync,
 * are never looked at. For a push: the commits the remote does not have (all reachable ones for a remote with no
 * remote-tracking branches), their messages, added lines and file names; for gh: the title, body and notes, and the files
 * it publishes. Read with Redact::secrets() until PUBLISH_CHECK_SECONDS; anything left unread counts like a hit.
 * On a hit the call is blocked once with the lines as Redact masks them, and the agent takes the value out itself and
 * publishes again (ADR-0101); the same call again (marker local/state/publish-check/<sha1>, 7 days) becomes a send
 * question for the ask policy, which runs it only when what the user asked for covers it, else refuses it (ADR-0073).
 * With no session id or no marker the block stays. Fails closed (hook.md). A safety
 * prompt, not security (AGENTS.md): a push run from code, a script or an alias is out of its sight.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/HookUnits.php';
require_once __DIR__ . '/PublishTargets.php';
require_once __DIR__ . '/PublishScan.php';

const PUBLISH_CHECK_SESSION_RE = '/^[A-Za-z0-9._-]{1,128}$/';
const PUBLISH_CHECK_KEEP = 604800; // seconds a marker is kept
const PUBLISH_CHECK_SECONDS = 4;   // reading what a push sends
const PUBLISH_CHECK_SHOWN = 5;     // lines named in the block

/** 'public', 'private' (also GitHub's "internal") or 'unknown' for a GitHub repository; $slug '' is the one of $dir. */
function publishCheckVisibility($host, $slug, $dir)
{
    if ($host !== 'github.com' || ($slug === '' && $dir === '') || ToolProc::which('gh') === null) {
        return 'unknown';
    }
    $argv = array_merge(ToolProc::which('timeout') === null ? [] : ['timeout', '4'], ['gh', 'repo', 'view'], $slug === '' ? [] : [$slug],
        ['--json', 'visibility', '--jq', '.visibility']);
    try {
        $r = ToolProc::run($argv, ['GH_PROMPT_DISABLED' => '1', 'NO_COLOR' => '1'], null, is_dir($dir) ? $dir : null);
    } catch (CliError $e) {
        return 'unknown';
    }
    $v = strtolower(trim($r['out']));
    return $r['code'] !== 0 || !in_array($v, ['public', 'private', 'internal'], true) ? 'unknown' : ($v === 'public' ? 'public' : 'private');
}

/** The remote a plain `git push` uses: branch.<b>.pushRemote, remote.pushDefault, branch.<b>.remote, else origin. */
function publishCheckDefaultRemote($top)
{
    $branch = PublishScan::git($top, ['symbolic-ref', '--quiet', '--short', 'HEAD']);
    $keys = $branch === null ? ['remote.pushDefault'] : ["branch.$branch.pushRemote", 'remote.pushDefault', "branch.$branch.remote"];
    foreach ($keys as $k) {
        $v = PublishScan::git($top, ['config', '--get', $k]);
        if ($v !== null && $v !== '') {
            return $v;
        }
    }
    return 'origin';
}

/** Scan one push; returns where it goes, or null when it is not checked. */
function publishCheckPush(array $t, $home, $visibility, $deadline, array &$hits, array &$notes)
{
    $remote = $t['remote'];
    $isUrl = $remote !== '' && (strpos($remote, ':') !== false || strpos('/.~', $remote[0]) !== false);
    list($host, $slug) = $isUrl ? PublishScan::remote($remote, $home) : ['', ''];
    if ($isUrl && !PublishScan::hosting($host)) {
        return null; // the user's own machine or server
    }
    $top = $t['dir'] === '' ? null : PublishScan::git($t['dir'], ['rev-parse', '--show-toplevel']);
    if ($top === null) {
        $notes[] = 'it cannot tell which repository this push is from; run it as git -C <repository> push ...';
        return 'an unknown repository';
    }
    if (!$isUrl) {
        $remote = $remote === '' ? publishCheckDefaultRemote($top) : $remote;
        $url = PublishScan::git($top, ['remote', 'get-url', '--push', $remote]);
        list($host, $slug) = $url === null ? ['', ''] : PublishScan::remote($url, $home);
        if (!PublishScan::hosting($host)) {
            return null; // no such remote (git refuses the push), or not a public hosting service
        }
    }
    $visible = call_user_func($visibility, $host, $slug, $top);
    if ($visible === 'private') {
        return null; // the user's own space
    }
    $revs = array_merge($t['all'] ? ['--branches'] : [], $t['tags'] ? ['--tags'] : []);
    foreach ($t['refspecs'] as $spec) {
        $src = explode(':', ltrim($spec, '+'))[0];
        $revs = $src === '' ? $revs : array_merge($revs, [strpos($src, '*') === false ? $src : '--branches']);
    }
    $known = !$isUrl && (string) PublishScan::git($top, ['for-each-ref', '--count=1', '--format=%(refname)', "refs/remotes/$remote/"]) !== '';
    try {
        $all = PublishScan::commits($top, array_merge(array_unique($revs ?: ['HEAD']), $known ? ['--not', "--remotes=$remote"] : [], ['--']), $deadline, $hits);
    } catch (CliError $e) {
        $all = false;
    }
    if (!$all && count($hits) < PublishScan::HITS_MAX) {
        $notes[] = 'not every commit of this push could be read within ' . PUBLISH_CHECK_SECONDS . ' seconds';
    }
    return "$host/$slug (" . ($visible === 'public' ? 'public' : 'visibility unknown, treated as public') . ')';
}

/** Scan one gh write; returns where it goes, or null when it is not checked. */
function publishCheckGh(array $t, $command, $home, $visibility, array &$hits, array &$notes)
{
    $place = 'a gist (anyone with its link can read it)';
    if (!$t['gist']) {
        $parts = explode('/', $t['repo']);
        list($host, $slug) = strpos($t['repo'], '://') !== false ? PublishScan::remote($t['repo'], $home)
            : (count($parts) === 3 ? [strtolower($parts[0]), "{$parts[1]}/{$parts[2]}"] : ['github.com', $t['repo']]);
        if (!PublishScan::hosting($host)) {
            return null; // a GitHub Enterprise server is not a public place
        }
        $visible = call_user_func($visibility, $host, $slug, $t['dir']);
        if ($visible === 'private') {
            return null;
        }
        $place = ($slug !== '' ? "$host/$slug" : 'the GitHub repository of ' . ($t['dir'] === '' ? 'this directory' : Config::shown($t['dir'])))
            . ' (' . ($visible === 'public' ? 'public' : 'visibility unknown, treated as public') . ')';
    }
    foreach ($t['texts'] as $text) {
        PublishScan::text($text, "the {$t['what']} text", $hits);
    }
    foreach ($t['files'] as $f) {
        if ($f === '-') {
            PublishScan::text($command, "the {$t['what']} input", $hits); // read from stdin: a here-document is in the command
            continue;
        }
        $path = PublishTargets::path($f, $t['dir'], $home);
        $why = $path === '' ? "$f could not be found to check it" : PublishScan::file($path, $f, $hits);
        $notes = $why === null ? $notes : array_merge($notes, [$why]);
    }
    return $place;
}

/** The answer for a pre_tool_call payload, or null. $localDir holds the markers. $visibility: a test seam. */
function publishCheckAnswer(array $payload, $localDir, $home, $visibility = 'publishCheckVisibility')
{
    $input = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
    $command = isset($input['command']) && is_string($input['command']) ? $input['command'] : '';
    if (!isset($payload['tool_name']) || $payload['tool_name'] !== 'terminal' || !preg_match(PublishTargets::MENTION_RE, $command)) {
        return null;
    }
    $dir = isset($input['workdir']) && is_string($input['workdir']) && $input['workdir'] !== '' ? $input['workdir']
        : (isset($payload['cwd']) && is_string($payload['cwd']) ? $payload['cwd'] : '');
    $deadline = microtime(true) + PUBLISH_CHECK_SECONDS;
    list($hits, $notes, $places) = [[], [], []];
    foreach (PublishTargets::read($command, $dir, $home) as $t) {
        $place = $t['kind'] === 'push' ? publishCheckPush($t, $home, $visibility, $deadline, $hits, $notes)
            : publishCheckGh($t, $command, $home, $visibility, $hits, $notes);
        $places = $place === null ? $places : array_merge($places, [$place]);
    }
    if (!$hits && !$notes) {
        return null;
    }
    $places = implode(', ', array_unique($places));
    $sig = sha1($command . "\0" . implode("\n", $hits) . "\0" . implode("\n", $notes));
    $session = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
    $marker = "$localDir/state/publish-check/" . sha1($session . "\0" . $sig);
    $asked = preg_match(PUBLISH_CHECK_SESSION_RE, $session) === 1 && is_file($marker);
    if ($asked) {
        $first = $hits ? $hits[0] : $notes[0];
        $message = "Pikkumies: publishes to $places what looks like a secret, readable by everyone for good: "
            . (strlen($first) > 110 ? substr($first, 0, 110) . '...' : $first)
            . (count($hits) + count($notes) > 1 ? ' and ' . (count($hits) + count($notes) - 1) . ' more' : '')
            . '. It runs only when what the user asked for covers publishing these lines. Command [' . substr($sig, 0, 16) . ']';
        if (HookUnits::approveShown($message)) {
            return ['action' => 'approve', 'kind' => 'send', 'rule_key' => 'pikkumies-publish:' . substr($sig, 0, 16), 'message' => $message];
        }
    }
    $recorded = !$asked && preg_match(PUBLISH_CHECK_SESSION_RE, $session) === 1
        && (is_dir(dirname($marker)) || @mkdir(dirname($marker), 0700, true)) && @touch($marker);
    foreach ($recorded ? (array) glob(dirname($marker) . '/*') : [] as $old) {
        if (@filemtime($old) < time() - PUBLISH_CHECK_KEEP) {
            @unlink($old);
        }
    }
    $more = count($hits) - PUBLISH_CHECK_SHOWN;
    return ['action' => 'block', 'message' => "Pikkumies publish check: not run. This publishes to $places, where it stays readable to everyone, even after it is deleted."
        . ($hits ? "\nWhat would go out looks like it holds a password, key or token, or a credential file (values masked):\n- "
            . implode("\n- ", array_slice($hits, 0, PUBLISH_CHECK_SHOWN)) . ($more > 0 ? "\n- and $more more" : '') : '')
        . ($notes ? "\nNot checked: " . implode('; ', $notes) . '.' : '')
        . "\nTake the value out yourself first (rewrite the commit, keep it in a file that is not tracked), then publish again: what goes out is checked again."
        . ($recorded ? ' If it is meant to be public, run the same command again: it is then reviewed as a send and runs only when what the user asked for covers publishing it; otherwise it is refused.'
            : ' Here it cannot be reviewed, so it is not run while it still holds these lines; list it as skipped, with this reason, in the final answer.')];
}

if (realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $payload = json_decode((string) stream_get_contents(STDIN), true);
    $answer = is_array($payload) ? publishCheckAnswer($payload, Config::localDir(), rtrim((string) getenv('HOME'), '/'))
        : ['action' => 'block', 'message' => 'Pikkumies safety: the tool call could not be read to check it. Not run; tell the user this safety check is failing.'];
    echo $answer === null ? '' : json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
}
