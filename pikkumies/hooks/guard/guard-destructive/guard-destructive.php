<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030): refuses obviously destructive terminal commands outright;
 * data deletes the user may want (backup prunes, torrent data) are asked, and the orchestrator's ask policy decides
 * which of those questions reach the user (ADR-0020, ADR-0073). Reads the hook JSON on stdin, prints a
 * JSON decision on stdout (agent/shell_hooks.py _parse_pre_tool_call).
 *
 * This is a safety prompt for the agent's own mistakes, NOT a security boundary: the user
 * owns this file and Hermes' config. Real limits come from the OS (permissions, no sudo).
 */
require_once dirname(__DIR__, 3) . '/lib/NestedAgent.php';
require_once dirname(__DIR__, 3) . '/lib/HookUnits.php'; // HookUnits::approveShown: the one approve-message-fits-the-panel check (ADR-0054)

/** Print the answer. An agent started by another agent blocks what it would ask: whoever answers there is an agent (ADR-0020). */
function guardSay(array $answer)
{
    if ($answer['action'] === 'approve' && NestedAgent::unattended()) {
        $answer = ['action' => 'block', 'message' => 'Pikkumies safety: this agent was started by another agent, so the one answering this question would be an agent, not the user. Not run. Tell the user the command. Asked: ' . $answer['message']];
    }
    echo json_encode($answer) . "\n";
    exit(0);
}
$stdin = (string) stream_get_contents(STDIN);
$payload = json_decode($stdin, true, 100000);
if (!is_array($payload) && trim($stdin) !== '') {
    // A payload that cannot be read cannot be checked: this unit fails closed, so it must not stay silent.
    echo json_encode(['action' => 'block', 'message' => 'Pikkumies safety: the tool call could not be read to check it. Not run; tell the user this safety check is failing.']) . "\n";
    exit(0);
}
// What the call says (lib/HookPayload.php, shared with the orchestrator's tool-hook selection, ADR-0054): the terminal
// command, the code of execute_code (which can run the same programs), the text written into a background process's
// stdin (process_manage write/submit), the path and search pattern of Hermes' file tools, or the arguments of a
// cronjob_manage call; every form also without quotes and backslashes (the shell joins r"m", 'r'm and r\m into rm)
// and with repeated slashes and /./ folded (hermes//.env is hermes/.env).
require_once dirname(__DIR__, 3) . '/lib/HookPayload.php';
require __DIR__ . '/GuardLocal.php';
// The media apps' logins in plain text (ADR-0078): named, globbed or swept up by a search from the home folder.
require __DIR__ . '/GuardMediaCreds.php';
require_once dirname(__DIR__, 3) . '/lib/Host.php';
$mediaHome = Host::home(function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
$mediaCwd = isset($payload['cwd']) && is_string($payload['cwd']) ? $payload['cwd'] : '';
$payload = is_array($payload) ? $payload : [];
list($callKind, $command, $texts) = HookPayload::read($payload);
$tool = isset($payload['tool_name']) ? $payload['tool_name'] : '';
$input = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
// Hermes' file tools reach the same files without a command: only the path (and a search pattern), and each file a V4A
// patch names, are checked, and only against the stored-password and AI-key rules below (and a write to local hooks or
// notes, GuardLocal.php), so an ordinary file whose name looks like a command is not asked.
$fileTool = $callKind === 'file';
// A scheduled job with a script runs that script every time it is due, with no guard and nobody to ask.
$scheduleTool = $callKind === 'schedule';
if ($callKind === '') {
    exit(0); // no opinion
}
// The shell joins r"m", 'r'm and r\m into rm: every pattern also reads the command without quotes and backslashes,
// and with repeated slashes and /./ folded (hermes//.env is hermes/.env). $command and $texts for the call itself
// come from HookPayload::read() above; this is reused below to read each file a V4A patch names the same way.
function guardTexts($command)
{
    $bare = str_replace(['"', "'", '\\'], '', $command);
    return array_unique([$command, $bare, preg_replace('#/(\./)*/*#', '/', $bare)]);
}

/** preg_match over every form of the command: 1 on a hit, false on a regex engine error, else 0. */
function guardMatch($re, array $texts)
{
    foreach ($texts as $text) {
        $hit = preg_match($re, $text);
        if ($hit !== 0) {
            return $hit;
        }
    }
    return 0;
}

// V: the separator before a subcommand, also in quoted or list form (["rclone", "purge"]).
$v = '[\s"\',\[(]+';
$lh = '(127\.[0-9.]+|localhost|0\.0\.0\.0|\[::1?\])';

// Stored passwords and the AI key: checked for every tool above, file tools included.
$secretPatterns = [
    '#local/secrets\b#'                             => 'a command on stored passwords (local/secrets/); what the agent reads goes to the model',
    // The model key (ADR-0004): Pikkumies hands it to Hermes itself; the agent never needs to read or search for it.
    // `credits balance` answers balance questions without it.
    '#\.(virsilipas|pikkumies)Key\b|hermes(-managed)?/\.env\b|HERMES_(HOME|MANAGED_DIR)\}?/\.env\b|\bvsk-#' => 'a command on the AI key (~/.virsilipasKey, ~/.pikkumiesKey, the Hermes .env); what the agent reads goes to the model. For the balance use credits balance; the user edits key files in a terminal',
    '/\.docker\b.*\bconfig\.json\b/s' => 'a command on registry passwords (~/.docker/config.json); what the agent reads goes to the model',
    // The halt snapshot holds what the user wrote, the only evidence the ask review trusts (ADR-0073): the agent never touches it.
    '#state/+halt\b#' => 'a command on the halt guard\'s state (local/state/halt/), which records what the user asked; the agent never needs it',
];
if ($fileTool) {
    // A V4A patch has no path: each file its headers name, both sides of a Move, is checked like a path (ADR-0020).
    require_once dirname(__DIR__, 3) . '/lib/PatchPaths.php';
    $named = isset($input['patch']) && is_scalar($input['patch']) ? PatchPaths::v4a((string) $input['patch']) : [];
    if ($named === null) {
        echo json_encode(['action' => 'block', 'message' => 'Pikkumies safety: a patch too long or complex to check. Not run.']) . "\n";
        exit(0);
    }
    foreach (array_merge([$command], $named) as $target) {
        $media = GuardMediaCreds::check($tool, $input, guardTexts($target), $mediaCwd, $mediaHome);
        if ($media !== null) {
            echo json_encode(['action' => 'block', 'message' => "Pikkumies safety: $media. Not run."]) . "\n";
            exit(0);
        }
        foreach ($secretPatterns as $re => $why) {
            if (guardMatch($re, guardTexts($target)) !== 0) { // a regex engine error (false) blocks too
                echo json_encode(['action' => 'block', 'message' => "Pikkumies safety: $why. Not run."]) . "\n";
                exit(0);
            }
        }
    }
    $local = guardLocal($callKind, $tool, $input, $command, $texts);
    if ($local !== null) {
        guardSay($local); // an agent started by another agent blocks instead of asking (ADR-0020)
    }
    exit(0);
}
if ($scheduleTool) {
    $script = false;
    foreach (['script', 'monitor_script', 'monitor', 'no_agent'] as $k) {
        $script = $script || !empty($input[$k]);
    }
    if (!$script) {
        exit(0);
    }
    $id = substr(sha1($command), 0, 16);
    $approveMessage = "Pikkumies: schedules a script that then runs by itself whenever it is due, with nobody asked. Job [$id]: " . $command;
    if (!HookUnits::approveShown($approveMessage)) {
        echo json_encode(['action' => 'block', 'message' => 'Pikkumies safety: a scheduled script job too long to show the user in full. Not run.']) . "\n";
        exit(0);
    }
    guardSay(['action' => 'approve', 'rule_key' => 'pikkumies-schedule:' . $id, 'message' => $approveMessage, 'kind' => 'schedule']);
}

$patterns = $secretPatterns + [
    // Hermes' hardline list refuses rm -r of ~ and $HOME, not the same folder written as /home/<name> (tools/approval_detection.py).
    '#(^|[\s;&|(`])rm\s+(-\S*\s+)*-[a-zA-Z]*[rR][a-zA-Z]*\s+(\S+\s+)*["\']?/home/[^/\s"\']+/?\*?["\']?(\s|$|[;&|)])#' => 'recursive delete of a whole home folder',
    '/\bmkfs(\.|\s)/'                              => 'filesystem format',
    '/\bdd\s+[^|]*\bof=\/dev\//'                    => 'raw write to a device',
    '/\b(fdisk|parted|sgdisk|wipefs)\b/'            => 'partition or signature change',
    '/\bchmod\s+(-[a-zA-Z]+\s+|--[a-z-]+\s+)*0*777\b/' => 'world-writable permissions',
    '/:\(\)\s*\{\s*:\|:&\s*\};:/'                   => 'fork bomb',
    '/\bgit\s+push\b.*(--force|\s-f)\b/'           => 'force push',
    // A verb behind a name the shell expands (alias x=rm; X=rm; $X -rf) is out of sight of the patterns here.
    '/\b[A-Za-z_][A-Za-z0-9_]*=["\']?(rm|unlink|shred|truncate|dd)([\s"\';]|$)/' => 'a destructive command renamed through an alias or a variable',
    '/^(?=.*\bbase64\s+(-\S+\s+)*(-d|--decode|-D)\b)(?=.*(\|\s*(sudo\s+)?(ba|z|da|k)?sh\b|\beval\b|\b(ba|z|da|k)?sh\s+(-c|<)|<\())/s' => 'decoded text run as a shell command, which cannot be checked',
    '/\brclone\b.*\bconfig\s+(show|dump)\b/s' => 'rclone config show/dump prints cloud credentials, and what the agent reads goes to the model',
    // Registry logins (ADR-0019: passwords never in chat or on the command line).
    "/\\b(docker|podman|nerdctl)\\b.*\\blogin\\b.*$v(-p|--password(?!-stdin))/s" => 'a registry password on the command line, where other processes can read it; the user types docker login in a terminal',
    // backup remote (ADR-0049): the gpg passphrase is shown only on the user's own terminal; what the agent reads goes to the model.
    "/\\bphp\\b.*{$v}passphrase\\b/s" => 'the backup passphrase is for the user\'s own terminal only, never the chat (it would reach the model provider)',
] + NestedAgent::guardPatterns(); // another agent with approvals, hooks or rules off (ADR-0020 amendment 2026-10-18)
// backup remote (ADR-0049): a command that names the tool and builds anything with $, backticks or eval cannot be checked
// (V=passphrase; php remote.php $V). The setup prefix's own value is skipped when it is single-quoted only, so a login
// value holding a $ stays usable; a double-quoted or unquoted one is expanded by the shell and counts.
$unquotedLogin = preg_replace("/PIKKUMIES_REMOTE_PASSWORD=(?:'[^']*'|\\\\')+/", 'PIKKUMIES_REMOTE_PASSWORD=x', $command);
// A regex engine error (null / false) counts as a hit: an unchecked command is not run.
if (guardMatch('#remote\.php|tools/backup/remote\b#', $texts) !== 0
    && ($unquotedLogin === null || preg_match('/[$`]|\beval\b/', $unquotedLogin) !== 0)) {
    echo json_encode(['action' => 'block', 'message' => 'Pikkumies safety: a backup remote command built with $, backticks or eval'
        . ' cannot be checked. Not run. Name the verb and its arguments literally.']) . "\n";
    exit(0);
}
foreach ($patterns as $re => $why) {
    $hit = guardMatch($re, $texts);
    if ($hit === false) {
        $why = 'a command too long or complex to check'; // a regex engine error must not let the call through
    }
    if ($hit !== 0) {
        echo json_encode(['action' => 'block', 'message' => "Pikkumies safety: $why. Refused outright, not asked. Do not reach the same effect another way; finish the rest of the task and say in one line what was refused and why."]) . "\n";
        exit(0);
    }
}
// The media apps' logins after the stored-password and AI-key rules above, so those keep their own reason.
$media = GuardMediaCreds::check($tool, $input, $texts, $mediaCwd, $mediaHome);
if ($media !== null) {
    echo json_encode(['action' => 'block', 'message' => "Pikkumies safety: $media. Refused outright, not asked. Do not reach the same effect another way; finish the rest of the task and say in one line what was refused and why."]) . "\n";
    exit(0);
}
// A Hermes command line read word by word: -z anywhere, --oneshot before the subcommand, -p, profile use (ADR-0020).
if (NestedAgent::guardCommand($texts)) {
    echo json_encode(['action' => 'block', 'message' => 'Pikkumies safety: ' . NestedAgent::WHY_START . '. Refused outright, not asked. Do the work in this session instead.']) . "\n";
    exit(0);
}

// Email (ADR-0053): logins blocked, smtp commands without shell expansion, send-draft and test asked with what they send.
require __DIR__ . '/GuardMail.php';
$mail = guardMail($command, $texts);
if ($mail !== null) {
    guardSay($mail);
}

// Other accounts' resource files and connections are not the agent's business (AGENTS.md, privacy).
// The message stays generic on purpose and names no path.
$privacyMessage = "Pikkumies privacy: reads only this account's own files and connections. Not run. Use performance check.";
$cg = '(/sys/fs/cgroup|user\.slice)';
$privacy = [
    // a glob, a recursive tool or a directory walk in code, together with the resource tree
    '#^(?=.*' . $cg . ')(?=.*([*]|(?<!\$)\?|(?<!\[)\[(?![\s\[])|\b(find|tree|du|rg)\b|\b(grep|ls)\s+(-\S*\s+)*-[a-zA-Z]*[rR]\b|--recursive\b|\b(walk|listdir|scandir|iterdir|r?glob)\b))#s',
    '#/proc/(self/|thread-self/|[0-9]+/)?net/(tcp|udp|raw|unix|[*?\[])#',
    // connection listings that are neither listening sockets only, a summary, nor filtered to one endpoint
    '#(^|[\s;&|(`/])ss(?=[\s"\']|$)(?![^;&|\n]*(\s-[a-zA-Z]*[ls]|--(listening|summary)\b|\b(dst|src|dport|sport)\b))#',
    '#(^|[\s;&|(`/])netstat(?=[\s"\']|$)(?![^;&|\n]*(\s-[a-zA-Z]*[lirsg]|--(listening|interfaces|route|statistics|groups)\b))#',
];
// user-<n> of another account: always as a slice, otherwise together with the resource tree.
$uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
$hit = 0;
foreach ($texts as $text) {
    preg_match_all('/user-([0-9]+)(\.slice)?/', $text, $users, PREG_SET_ORDER);
    foreach ($users as $u) {
        if ((int) $u[1] !== $uid && (!empty($u[2]) || preg_match("#$cg#", $text))) {
            $hit = 1;
        }
    }
}
foreach ($privacy as $re) {
    $hit = $hit !== 0 ? $hit : guardMatch($re, $texts);
}
if ($hit !== 0) {
    $message = $hit === false ? 'Pikkumies safety: a command too long or complex to check. Not run.' : $privacyMessage;
    echo json_encode(['action' => 'block', 'message' => $message]) . "\n";
    exit(0);
}

// Deletes the user can ask for, but must confirm (ADR-0020): Hermes asks the user in the chat and
// the model cannot answer for them; cron and other unattended runs are refused (approvals.*_mode).
// Each rule is [kind, why]; the kind picks the end of the question (delete, exposure, stop, load).
// rule_key names this exact command: Hermes keys "session"/"always" answers by it and would
// otherwise use the tool name, approving every later delete (hermes_cli/plugins.py).
// Patterns read the whole command (.* and /s): a false match only asks the user, a missed one
// deletes without asking, so nothing may stop at a quote, ";" or "&" inside an argument.
// Docker-compatible CLIs (ADR-0034): the binary, its global options (`docker --context x`), the subcommand.
$dk = "\\b(?:docker|podman|nerdctl)\\b(?:$v-{1,2}[A-Za-z][A-Za-z0-9-]*(?:=[^\\s\"',\\])]+|$v(?!-)[^\\s\"',\\])]+)?)*$v";
// A bind source that is the home itself (or /): `-v ~:/x`, `-v /home/me/:/x`, `--mount source=$HOME,...`.
$home = '(?:~|\$HOME|\$\{HOME\}|/home/[^/\s:,"\']+|/root)?/?';
$approve = [
    "/$dk(rmi|(image|container|volume|network|system|builder|buildx){$v}prune|system{$v}reset|(container|image|volume|network){$v}(rm|remove))\\b/s"
        => ['delete', 'deletes containers, images, volumes or networks'],
    "/(\\b(docker|podman)-compose\\b|{$dk}compose\\b)(?=.*{$v}down\\b)(?=.*{$v}(-v|--volumes|--rmi)\\b)/s" => ['delete', 'compose down that also deletes volumes or images'],
    '/^(?=.*\bcurl\b)(?=.*--unix-socket\b)(?=.*((-X|--request)[\s=]*["\']?DELETE\b|\/prune\b))/si' => ['delete', 'deletes through the container engine API'],
    // -p 8080:80 binds every interface; 127.0.0.1: and [::1]: do not.
    "/$dk(container$v|pod$v|compose$v)?(run|create)\\b.*$v((-p|--publish)(?![\\s=\"',]*(127\\.|\\[::1\\]:|localhost:))(=|$v)?[^\\s\"',\\])]|-[a-zA-Z]*P\\b|--publish-all\\b)/s"
        => ['exposure', 'publishes a container port on every interface (the internet)'],
    "/$dk.*$v(--net(work)?(=|$v)host\\b|--privileged\\b|--cap-add\\b|--device(?![\\w-]))/s" => ['exposure', 'gives a container the host network, extra privileges or a device'],
    '#' . $dk . '.*' . $v . '(-v|--volume|--mount)(=|' . $v . ')?(\S*(\.sock\b|\.ssh(?![\w-])|\bpikkumies(?=[/:,\s"\']|$))|' . $home . ':|\S*\b(source|src)=' . $home . '(?=[,\s"\']|$))#s'
        => ['exposure', 'mounts a socket, ~/.ssh, ~/pikkumies or the whole home into a container'],
    // The docker unit's own flags; a lone launch-plan only prints a plan.
    '/^(?![^;&|\n]*' . $v . 'launch-plan\b[^;&|\n]*$)(?=.*(^|' . $v . ')--(accept-shared-exposure|public)(?![\w-]))/s'
        => ['exposure', 'launches containers whose ports other accounts or the internet can reach'],
    '/\b(pkill|killall|kill)\b.*\b(rootlesskit|dockerd|containerd)\b|\bsystemctl\b.*\b(stop|restart|kill)\b.*\bdocker(\.service|\.socket)?\b/s'
        => ['stop', 'stops or restarts the container daemon'],
    // Load tests (ADR-0020 amendment): cron runs are refused like every approve.
    "/\\bphp\\b.*{$v}heavy\\b/s" => ['load', 'a heavy performance test through a Pikkumies tool'],
    '/^(?!\s*(which|type|command\s+-v|man)\s)(?=.*(^|[\s;&|(`\/"\',\[])(fio|iperf3?|speedtest(-cli)?|yabs(\.sh)?|stress(-ng)?|sysbench|bonnie\+\+)(?=[\s"\',\])]|$)(?!\s+(--version|--help|-v|-V|-h)\s*($|[;&|])))/s'
        => ['load', 'a benchmark or load test'],
    '/\bdd\b(?=[^;&|\n]*\bof=(?!\/dev\/null\b))(?=[^;&|\n]*\b(bs|count)=)/' => ['load', 'a large test write with dd'],
    // imap and pop delete exact messages on the server (ADR-0053); their --expect is also caught below.
    "/\\b(imap|pop)\\.php\\b.*{$v}delete\\b/s" => ['delete', 'deletes mail on the server'],
    "/\\brclone\\b.*$v(sync|bisync|move|moveto|purge|delete|deletefile|rmdirs?|cleanup|dedupe)\\b/s" => ['delete', 'rclone can delete or overwrite files'],
    "/\\brestic\\b.*$v(forget|prune)\\b/s"                                                  => ['delete', 'restic removes backup snapshots'],
    "/\\bborg\\b.*$v(prune|delete|compact|recreate)\\b/s"                                    => ['delete', 'borg removes backup archives'],
    "/\\bkopia\\b.*{$v}snapshot$v(expire|delete)\\b/s"                                       => ['delete', 'kopia removes backup snapshots'],
    "/\\bduplicacy\\b.*{$v}prune\\b/s"                                                       => ['delete', 'duplicacy removes backup revisions'],
    "#{$lh}[^\\s]*/(api/v2/torrents/delete|[^\\s]*deletefiles=true)#si"                         => ['delete', 'deletes torrent or media files through a local app'],
    "/^(?=.*\\bcurl\\b)(?=.*(-X|--request)[\\s=]*[\"']?DELETE\\b)(?=.*$lh)/si"                => ['delete', 'deletes through a local app'],
    "#\\.delete\\(\\s*[fbru]*[\"']https?://$lh#i"                                              => ['delete', 'deletes through a local app'],
    "#\\.request\\(\\s*[\"']DELETE[\"']\\s*,\\s*[fbru]*[\"']https?://$lh#i"                        => ['delete', 'deletes through a local app'],
    // *arr items deleted by HTTP from anywhere (also the public proxy path): library items and their files.
    '#^(?=.*(-X|--request|\\.delete\\(|["\']DELETE["\']))(?=.*/api/v[0-9]+/(queue|movie|series|artist|author|book|episodefile|moviefile|trackfile|bookfile)\\b)#si' => ['delete', 'deletes media or downloads through an *arr app'],
    '#["\']name["\']\\s*:\\s*["\'](DeleteSeriesFiles|ResetApiKey|ApplicationUpdate)["\']#i'      => ['delete', 'runs an *arr command that deletes files, resets the API key or replaces the app'],
    // Media servers from anywhere (also the public proxy path): Jellyfin/Emby DELETE /Items[/{id}] deletes "from the
    // library and filesystem" (Jellyfin 12.2 and Emby 4.1 specs), Plex DELETE /library/metadata/{id}; /Auth/Keys/{key} revokes a key.
    '#^(?=.*((-X|--request)[\\s=]*["\']?DELETE\\b|\\.delete\\(|["\']DELETE["\']))(?=.*/(Items|library/metadata|Auth/Keys)(/|\\?|\\b))#si' => ['delete', 'deletes media files or an API key through a media server'],
    // Bazarr DELETE /api/{episodes,movies}/subtitles deletes a subtitle file (frontend episodes.ts:77-82); autobrr
    // DELETE /api/filters/{id} and /api/keys/{key} (internal/http/server.go:235,240, filter.go:57), also via the public proxy path.
    '#^(?=.*((-X|--request)[\\s=]*["\']?DELETE\\b|\\.delete\\(|["\']DELETE["\']))(?=.*/api/(episodes/subtitles|movies/subtitles|filters/|keys/))#si'
        => ['delete', 'deletes subtitle files, an autobrr filter or an API key through a local app'],
    '/delete[-_]local[-_]data/i'                                                              => ['delete', 'deletes torrent data through a local app'],
    // The tool convention anywhere in the command: `php $Q remove <id> --with-data` names no .php file, and
    // `php $R sync|move|prune ... --expect <n>` names no rclone/restic/borg: every deleting tool verb carries --expect.
    '/(^|[\s"\',\[(])--(with-data|delete-files|expect)\b/'                                   => ['delete', 'deletes data through a Pikkumies tool'],
    "/\\bphp\\b.*{$v}(prune|forget|purge)\\b/s"                                                => ['delete', 'removes data through a Pikkumies tool'],
    '/\bcore\.remove_torrents?\b/'                                                            => ['delete', 'removes torrents, and with remove_data their files, through Deluge'],
    '/\bexecute[a-z0-9.]*\b.*\brm\b/s'                                                         => ['delete', 'runs rm through rTorrent'],
    // Plain file deletes: Hermes' own list catches only rm -r and rm /, and its default "smart" mode lets a
    // second model approve them. rm -rf is asked here too, and the ask review decides whether it reaches the user (ADR-0073);
    // Hermes' own hardline list still blocks rm -rf of /, the home and system folders.
    '/(^|[\s;&|(`"\'])(rm|unlink|shred|truncate)\s/'                                           => ['delete', 'deletes or empties files'],
    // Emptying or deleting without a delete verb: `: > f`, `cp /dev/null f`, `mv f /dev/null`, `... | xargs rm`.
    '#(^|[\s;&|(]):\s*>(?!>)|\b(cp|mv)\b[^;&|\n]*(?<![>&\s])\s+/dev/null\b|\bxargs\b.*\b(rm|unlink|shred)\b#s' => ['delete', 'deletes or empties files'],
    '/\bfind\b.*\s-(delete|exec(dir)?\s+(\S*\/)?(rm|unlink|shred)\b)/s'                        => ['delete', 'deletes the files find matches'],
    '/\bos\.(remove|unlink|rmdir|removedirs)\(|\bshutil\.rmtree\(|\.unlink\(|\.rmdir\(/'          => ['delete', 'deletes files from code'],
    // rclone's remote-control API (the WebUI's rcd runs for every rclone-enabled PMSS account) and its HTTP form.
    '#\brclone\b.*\brc\b.*\boperations/(purge|delete|deletefile|rmdirs?|cleanup)\b#s'          => ['delete', 'rclone deletes files through its remote control'],
    "#{$lh}[^\\s]*/(operations/(purge|delete|deletefile|rmdirs?|cleanup)|sync/(sync|bisync|move))\\b#" => ['delete', 'rclone deletes or overwrites files through its remote control'],
    '/\bborgmatic\b(?!.*\s(list|info|check|rlist|rinfo|--help|--version)\b)/s'                 => ['delete', 'borgmatic runs prune and compact by default'],
    "/\\brestic\\b.*{$v}(key{$v}remove|repair{$v}snapshots.*--forget|restore)\\b/s"            => ['delete', 'restic removes a key, rewrites snapshots, or restores over existing files'],
    "/\\bborg\\b.*{$v}(check.*--repair|extract)\\b/s"                                      => ['delete', 'borg repairs the repository or extracts over existing files'],
    "/\\bkopia\\b.*{$v}(snapshot{$v}restore|restore)\\b|\\bduplicacy\\b.*-overwrite\\b/s"   => ['delete', 'a restore that can overwrite existing files'],
    // --del is rsync's own short name for --delete-during.
    '/\b(rsync|lftp)\b.*--(delete[a-z-]*|del|remove-source-files|Remove-source-files)\b/s'      => ['delete', 'deletes files on one side of a transfer'],
    '/\bgit\b.*\b(clean\b.*(\s-[a-zA-Z]*f|--force\b)|reset\b.*--hard\b)/s'                     => ['delete', 'git clean or reset --hard deletes files and changes that were never committed'],
    '/\bcrontab\b[^;&|\n]*\s-[a-zA-Z]*r\b/'                                                    => ['delete', 'crontab -r deletes every scheduled job of this account'],
    // The list form in code: subprocess.run(["rm", "-r", p]).
    '/[\[(,]\s*["\']?(rm|unlink|shred|truncate)["\']?\s*,/'                                     => ['delete', 'deletes or empties files'],
    '/\brclone\b.*\bserve\b(?!.*--addr[=\s]+["\']?(127\.|localhost|\[::1\]|unix:))/s'         => ['exposure', 'rclone serve without a loopback --addr publishes files to the network'],
    '/\brclone\b.*--rc-(no-auth\b|addr[=\s]+["\']?(?!(127\.|localhost|\[::1\]|unix:)))/s'      => ['exposure', 'rclone remote control without a password or on a network address'],
    // A web server, web shell or tunnel on every interface serves files or a shell beyond this account.
    '/\bpython[0-9.]*\s+(-\S+\s+)*-m\s+http\.server\b(?!.*(-b|--bind)[=\s]+["\']?(127\.|localhost|::1))/s' => ['exposure', 'a web server on every interface serves this folder'],
    '/\bphp\b[^;&|\n]*\s-S\s*+["\']?+(?!(127\.|localhost|\[::1\]))/'                             => ['exposure', 'a web server on a network address serves files'],
    '/(^|[\s;&|(`\/"\'])(ttyd|gotty|ngrok|cloudflared)(?=[\s"\']|$)(?!\s+(--version|--help|-v|-V|-h)\s*($|[;&|]))/' => ['exposure', 'a web shell or tunnel makes this account reachable from the internet'],
    '/\b(dockerd(-rootless(\.sh)?)?|containerd)\b.*(-H|--host)[=\s]+["\']?tcp:\/\//s'          => ['exposure', 'a container engine API on a network address, which anyone who reaches it can use to run code as this account'],
    '/(^|[\s"\',\[(])--replace\b/'                                                             => ['delete', 'replaces existing files through a Pikkumies tool'],
    // Re-running the media installer can delete an app's settings and users (PMSS install-media-stack.sh
    // asks on stdin, which a pipe can answer); stopping or starting one app is safe.
    '/install-media-stack\.sh(?![^;&|\n]*--(stop-app|start-app|help)\b)/' => ['delete', 'runs the media installer, which can delete app settings and data'],
    // backup remote (ADR-0049): where the account's files go, and a job that sends them by itself, are the user's decision.
    "/(^|[\\s;&|(`\"'])PIKKUMIES_REMOTE_PASSWORD=|\\bremote\\.php\\b.*{$v}(setup|schedule)\\b|(^|[\\s\"',\\[(])--daily\\b/s"
        => ['send', 'sets up or schedules backups of this account to another machine'],
];
// What the user is asked about, after the reason; a load test has its own rule_key prefix.
$suffix = [
    'delete'   => ', which cannot be undone',
    'exposure' => ', which can make a service or files reachable beyond this account',
    'stop'     => ', which stops every container this account runs',
    'load'     => "; it loads the shared server while it runs, and the result shows your share now, not the server's ceiling",
    'send'     => ', which sends copies of your files to that machine from now on',
];
foreach ($approve as $re => $rule) {
    list($kind, $why) = $rule;
    $hit = guardMatch($re, $texts);
    if ($hit === false) {
        list($kind, $why) = ['delete', 'a command too long or complex to check']; // fail toward asking the user, never allow
    }
    if ($hit !== 0) {
        $id = substr(sha1($command), 0, 16);
        $key = ($kind === 'load' ? 'pikkumies-load:' : ($kind === 'send' ? 'pikkumies-send:' : 'pikkumies-delete:')) . $id;
        $approveMessage = "Pikkumies: $why{$suffix[$kind]}. Command [$id]: " . $command;
        // The user must see everything they approve: a message too long to show in full is refused.
        if (!HookUnits::approveShown($approveMessage)) {
            echo json_encode(['action' => 'block', 'message' => "Pikkumies safety: $why, and the command is too long to show the user in full. Not run. Split it into smaller commands."]) . "\n";
            exit(0);
        }
        guardSay(['action' => 'approve', 'rule_key' => $key, 'message' => $approveMessage, 'kind' => $kind]); // kind: for the ask policy (ADR-0073)
    }
}
// Writes to local hooks and notes (ADR-0054), when no rule above asked already.
$local = guardLocal($callKind, $tool, $input, $command, $texts);
if ($local !== null) {
    guardSay($local);
}
exit(0);
