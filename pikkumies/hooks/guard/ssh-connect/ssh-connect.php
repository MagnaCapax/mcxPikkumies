<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030, ADR-0068): every SSH connection the agent makes says
 * why, and the person's lists decide where. Blocks come first: a call it cannot read or a host it cannot read (SshCommand:
 * $VAR, $(...), globs, an unknown ssh option, a non-canonical address...); SSH from execute_code; then SshDecide: a name on
 * ssh.deny, a connection with no `# intent: <why>` in the command and none stated for that host earlier in this session,
 * and with ssh.blockUnknown a name not on ssh.allow or a route this check cannot follow. Then Hermes' approval prompt asks
 * the person (rule_key pikkumies-ssh:<sha1 of the command>) about those names and routes, and about writes to
 * ~/.ssh/config. Every name on ssh.allow: no opinion. A safety prompt, not security (AGENTS.md): the agent's user can edit
 * the lists, and a script that connects by itself is out of sight. Fails closed (hook.md).
 */
$sshRoot = dirname(__DIR__, 3);
require_once "$sshRoot/lib/HookPayload.php";
require_once __DIR__ . '/SshPrograms.php';
const SSH_SESSION_RE = '/^[A-Za-z0-9._-]{1,128}$/';
const SSH_TEXT_MAX = 100000;
const SSH_WRITES_RE = '#(>|\btee\b|\b(sed|perl)\b[^\n]*\s-[a-zA-Z]*i|\b(cp|mv|install|ln|rm|unlink|truncate|dd|ed|ex|vi|vim|nano|emacs|chmod|touch)\b|file_put_contents|fwrite|fputs|rename\s*\(|copy\s*\(|write_text|write_bytes|\.write\s*\()#';

/** Print the answer. In an agent started by an agent a question becomes a block: whoever answers there is an agent (ADR-0020). */
function sshSay(array $answer)
{
    if ($answer['action'] === 'approve' && class_exists('NestedAgent') && NestedAgent::unattended()) {
        $answer = ['action' => 'block', 'message' => 'Pikkumies safety: this agent was started by another agent, so the one answering this question would be an agent, not the user. Not run. Tell the user the command. Asked: ' . $answer['message']];
    }
    echo json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    exit(0);
}

function sshBlock($why)
{
    sshSay(['action' => 'block', 'message' => "Pikkumies SSH check: $why"]);
}

$payload = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    sshBlock('the tool call could not be read to check it. Not run; tell the user this safety check is failing.');
}
list($kind, $command) = HookPayload::read($payload);
$tool = isset($payload['tool_name']) ? (string) $payload['tool_name'] : '';
$input = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
$fileTool = in_array($tool, ['write_file', 'patch'], true);
$fileText = $fileTool ? (isset($input['path']) && is_scalar($input['path']) ? (string) $input['path'] : '') . "\n" . (isset($input['patch']) && is_scalar($input['patch']) ? (string) $input['patch'] : '') : '';
if ($kind === 'command' ? preg_match(SshPrograms::MENTION_RE, $command) === 0 : (!$fileTool || preg_match('#\.ssh#', $fileText) === 0)) {
    exit(0); // nothing about SSH: no opinion, nothing else loaded
}
require_once __DIR__ . '/SshCommand.php';
require_once __DIR__ . '/SshDecide.php';
require_once "$sshRoot/lib/SshLists.php";
require_once "$sshRoot/lib/NestedAgent.php";
require_once "$sshRoot/lib/HookUnits.php";
try {
    $found = ['conns' => [], 'routes' => []];
    if ($fileTool) {
        require_once "$sshRoot/lib/PatchPaths.php";
        $paths = isset($input['patch']) && is_scalar($input['patch']) ? PatchPaths::v4a((string) $input['patch']) : [];
        $configWrite = false;
        foreach (array_merge([isset($input['path']) ? (string) $input['path'] : ''], SshUnreadable::check($paths)) as $path) {
            $configWrite = $configWrite || SshUnreadable::matches('#(^|/)\.ssh/+(config\z|config\.d/)#', SshUnreadable::check(preg_replace('#/(\./)*/*#', '/', trim($path))));
        }
        if (!$configWrite) {
            exit(0); // another file under ~/.ssh: not this check's
        }
        // The question shows the whole change, as config-change does: what the person approves must be in it.
        $command = $tool . ' ' . implode("\n", array_filter(array_map(function ($k) use ($input) {
            return isset($input[$k]) && is_scalar($input[$k]) ? (string) $input[$k] : '';
        }, ['path', 'content', 'old_string', 'new_string', 'patch']), 'strlen'));
    } else {
        if (strlen($command) > SSH_TEXT_MAX) {
            sshBlock('the command is over ' . SSH_TEXT_MAX . ' characters, too long to check. Not run. Split it.');
        }
        $configWrite = SshUnreadable::matches('#\.ssh/+config#', $command) && SshUnreadable::matches(SSH_WRITES_RE, $command);
        if ($tool === 'execute_code' && SshUnreadable::matches('/(?<![\w.-])(ssh|scp|sftp|rsync|sshfs|mosh|autossh|sshpass|ssh-copy-id|ssh-keyscan)(?![\w-])|\b(paramiko|asyncssh|fabric)\b/i', $command)) {
            sshBlock('SSH from code is not checked. Not run. Run SSH from the terminal with the host written literally, so it can be checked against the user\'s lists.');
        }
        $found = $tool === 'execute_code' ? $found : SshCommand::read($command);
    }
    $session = isset($payload['session_id']) && is_string($payload['session_id']) && preg_match(SSH_SESSION_RE, $payload['session_id']) ? $payload['session_id'] : '';
    $intent = !$fileTool && SshUnreadable::matches('/#\s*intent:[ \t]*([^\n]*)/i', $command, $m) ? SshDecide::clean($m[1]) : '';
    $intent = preg_match('/^.{8}/su', $intent) === 1 ? $intent : ''; // at least 8 characters
    $answer = SshDecide::answer($found, SshLists::config(), ['intent' => $intent, 'session' => $session, 'key' => $command,
        'shown' => $fileTool ? $command : SshDecide::shown($command, $intent), 'configWrite' => $configWrite]);
} catch (SshUnreadable $e) {
    sshBlock('cannot tell where this command connects: ' . $e->getMessage() . '. Not run. Write each host literally, one connection per command.');
} catch (\Throwable $e) {
    sshBlock('the check failed (' . substr($e->getMessage(), 0, 160) . '). Not run; tell the user this safety check is failing.');
}
if ($answer !== null) {
    sshSay($answer);
}
exit(0);
