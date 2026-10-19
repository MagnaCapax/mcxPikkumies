<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030): a question for the orchestrator's ask policy
 * (ADR-0073) about the settings changes that loosen something, cost credits, stop scheduled jobs or delete history (ADR-0059).
 * - `php <this Pikkumies' pikkumies-configure.php> set <key> <value>`, alone in the terminal, is judged by the configure
 *   tool's own ConfigureChange::plan against the defaults and local/config.php: an asked change gets approve with
 *   rule_key pikkumies-config:<sha256 of key, old and new>, used to merge this with another unit's question, never to
 *   remember a past answer; a free change or the person's own gets no opinion (the tool makes or refuses it, and
 *   Config::set refuses the person's for every caller).
 * - Asked with the whole command shown: pikkumies-configure followed by set in any other form; any terminal command,
 *   code or process input that names a key with an asked direction (ConfigureTiers) next to the word set or apply;
 *   ConfigureChange, ConfigureSettings or Config called as a method, a callable string or an array; setting
 *   PIKKUMIES_CONFIG; and writes to local/config.php or to the files that decide what may change (the configure tool,
 *   lib/, any hook): by the file tools, matched on the path as written and its real path (a V4A patch's file headers
 *   included), or by a terminal command or code naming one of them with a write.
 * - Blocked: `pikkumies configure undo`, the person's own shell command.
 * A safety prompt, not security (AGENTS.md): a key or command built at run time, or a shell write through a symlinked
 * directory, is out of its sight. In an agent started by an agent every question is a block (ADR-0020). Fails closed
 * (hook.md): a crash, a regex engine error or a payload it cannot read blocks.
 */
$root = dirname(__DIR__, 3);
require_once "$root/lib/NestedAgent.php";
require_once "$root/lib/HookUnits.php"; // HookUnits::approveShown: the one approve-message-fits-the-panel check (ADR-0054)
require_once "$root/lib/ConfigureTiers.php";
require_once __DIR__ . '/ConfigChangePaths.php';
$say = function (array $answer) {
    if ($answer['action'] === 'approve' && NestedAgent::unattended()) {
        $answer = ['action' => 'block', 'message' => 'Pikkumies safety: this agent was started by another agent, so the one answering this question would be an agent, not the user. Not run. Tell the user the command. Asked: ' . $answer['message']];
    }
    echo json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
    exit(0);
};
/** A regex result, or a block when the engine failed (false or null): never an allow. */
$must = function ($result) use ($say) {
    if ($result === false || $result === null) {
        $say(['action' => 'block', 'message' => 'Pikkumies safety: a command too long or complex to check. Not run.']);
    }
    return $result;
};
$payload = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    $say(['action' => 'block', 'message' => 'Pikkumies safety: the tool call could not be read to check it. Not run; tell the user this safety check is failing.']);
}
$tool = isset($payload['tool_name']) ? (string) $payload['tool_name'] : '';
$input = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
$fields = ['terminal' => ['command'], 'execute_code' => ['code'], 'process_manage' => ['data'], 'write_file' => ['path', 'content'],
    'patch' => ['path', 'old_string', 'new_string', 'patch']];
if (!isset($fields[$tool])) {
    exit(0); // a tool that runs or writes nothing: no opinion
}
$parts = [];
foreach ($fields[$tool] as $k) {
    $parts[] = isset($input[$k]) && is_scalar($input[$k]) ? (string) $input[$k] : '';
}
$text = implode("\n", $parts);
/** A question for the ask policy (ADR-0073); $kind 'delete' when the change deletes something that has no copy. */
$ask = function ($what, $id, $shown, $kind = '') use ($say) {
    $approveMessage = "Pikkumies: $what. Command [" . substr($id, 0, 16) . "]: $shown";
    if (!HookUnits::approveShown($approveMessage)) {
        $say(['action' => 'block', 'message' => "Pikkumies safety: $what, and it is too long to show the user in full. Not run."]);
    }
    $say(['action' => 'approve', 'rule_key' => 'pikkumies-config:' . $id, 'message' => $approveMessage] + ($kind !== '' ? ['kind' => $kind] : []));
};
$askWhole = function ($what) use ($ask, $tool, $text) {
    $ask($what, substr(hash('sha256', $tool . "\n" . $text), 0, 32), $tool === 'write_file' || $tool === 'patch' ? "$tool " . trim($text) : trim($text));
};
// The settings file, and the files that decide what may change in it: the configure tool, lib/ (Config, ConfigureTiers,
// NestedAgent, Cli), every hook. Matched by suffix, and on a real path also under this Pikkumies' own root.
$own = realpath($root);
$guarded = '#(^|/)(local/config\.php$|pikkumies/(hooks/|lib/|tools/system/pikkumies-configure/[^/]+\.php$))'
    . ($own === false ? '' : '|^' . preg_quote($own, '#') . '/(hooks/|lib/|local/config\.php$|tools/system/pikkumies-configure/[^/]+\.php$)') . '#';
$guardedWhy = 'writes Pikkumies\' settings file, or a file that decides which settings the agent may change, past pikkumies-configure, so nothing in it is checked';

if ($tool === 'write_file' || $tool === 'patch') {
    // A patch in the V4A form names its files in headers, with no path field: PatchPaths reads them as Hermes does.
    require_once "$root/lib/PatchPaths.php";
    $v4a = isset($input['patch']) && is_scalar($input['patch']) ? PatchPaths::v4a((string) $input['patch']) : [];
    $paths = array_merge([isset($input['path']) ? (string) $input['path'] : ''], $must($v4a));
    foreach ($paths as $path) {
        foreach (configChangePathForms($must(preg_replace('/(*UCP)^\s+|\s+$/u', '', $path))) as $form) {
            if ($must(preg_match($guarded, $form)) === 1) {
                $askWhole($guardedWhy);
            }
        }
    }
    exit(0);
}
// The shell joins c"onfig" into config and reads a//b and a/./b as a/b: every pattern also reads that form.
$hay = $text . "\n" . preg_replace('#/(\./)*/*#', '/', str_replace(['"', "'", '\\'], '', $text));
// The keys with an asked direction: a command or code naming one next to set or apply may be an asked change.
$asked = [];
foreach (ConfigureTiers::RULES as $key => $rule) {
    if (in_array('approve', $rule, true) || in_array($rule[0], ['retention', 'channel'], true)) {
        $asked[] = preg_quote($key, '/');
    }
}
$askedKeys = '(?<![\w.])(' . implode('|', $asked) . ')(?![\w.])';
if (!preg_match('/configure|config\.php|PIKKUMIES_CONFIG|pikkumies\/(hooks|lib)\/|' . $askedKeys . '/i', $hay) && strpos($hay, 'Config') === false) {
    exit(0); // not about settings: no opinion, nothing else loaded
}
require_once "$root/lib/Cli.php";
$hit = function ($re) use ($hay, $must) {
    return $must(preg_match($re, $hay)) === 1;
};
if ($hit('/(?<![\w-])pikkumies[\s"\',\[\]]+configure[\s"\',\[\]]+undo(?![\w-])/')) {
    $say(['action' => 'block', 'message' => 'Pikkumies safety: `pikkumies configure undo` is the user\'s own shell command (it asks them on their terminal). Not run. '
        . 'To go back, set the setting to its old value with pikkumies-configure (set prints the old value), or tell the user to run it in their own shell.']);
}
$words = $tool === 'terminal' ? configChangeWords($text) : null;
if ($words !== null) {
    $ownTool = realpath("$root/tools/system/pikkumies-configure/pikkumies-configure.php");
    if (count($words) >= 2 && preg_match('#^(\S*/)?php[0-9.]*$#', $words[0]) === 1 && $ownTool !== false && configChangePath($words[1]) === $ownTool) {
        list($verb, $args) = Cli::parse(array_merge(['tool'], array_slice($words, 2)), ['show', 'get', 'set', 'skills', 'review']);
        if ($verb !== 'set' || count($args) !== 2) {
            exit(0); // a verb that changes nothing the person is asked about (skills, show, review), or one the tool refuses
        }
        require_once "$root/tools/system/pikkumies-configure/ConfigureChange.php";
        try {
            $value = Config::parse($args[0], $args[1]);
            $plan = ConfigureChange::plan(Config::fileConfig(), $args[0], $value);
        } catch (InvalidArgumentException $x) {
            exit(0); // a value the tool refuses: it says why
        } catch (CliError $x) {
            exit(0);
        }
        if ($plan['tier'] !== ConfigureTiers::APPROVE) {
            exit(0);
        }
        $json = function ($v) {
            return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        };
        $what = "sets {$args[0]} from " . $json($plan['old']) . ' to ' . $json($value) . ($plan['why'] !== '' ? ', which ' . $plan['why'] : '');
        // A shorter sessions.retentionDays makes Hermes delete ended sessions: a delete, also where no yes can be checked.
        $ask($what, substr(hash('sha256', $json([$args[0], $plan['old'], $value])), 0, 32), trim($text), $args[0] === 'sessions.retentionDays' ? 'delete' : '');
    }
}
if ($hit('/pikkumies-configure(\.php)?[\s"\',\])]+set(?![\w-])/') || ($hit("/$askedKeys/") && $hit('/(?<![\w-])(set|apply)(?![\w-])/'))) {
    $askWhole('changes Pikkumies settings in a form this check cannot read exactly, so it cannot tell whether the agent may make the change');
}
$writes = '#(>|\btee\b|\b(sed|perl)\b[^\n]*\s-[a-zA-Z]*i|\b(cp|mv|install|ln|rm|unlink|truncate|dd|ed|ex|vi|vim|nano|emacs|chmod|touch)\b|file_put_contents|fwrite|fputs|rename\s*\(|copy\s*\(|write_text|write_bytes|\.write\s*\()#';
if ($hit('#(local/config\.php|pikkumies/(hooks/|lib/|tools/system/pikkumies-configure/))#') && $hit($writes)) {
    $askWhole($guardedWhy);
}
// Config, ConfigureChange or ConfigureSettings as Class::method, 'Class::method' or ['Class', 'method'], with or without parentheses.
if ($hit('/\b(Config[\'"]?\s*(::|,)\s*[\'"]?(set|acceptTerms)|Configure(Change|Settings)[\'"]?\s*(::|,)\s*[\'"]?(apply|set|skills))\b/')
    || $hit('/(^|[\s;&|(`"\'])PIKKUMIES_CONFIG\s*=/m') || $hit('/putenv\s*\([^)]*PIKKUMIES_CONFIG|environ\s*\[\s*PIKKUMIES_CONFIG\s*\]\s*=(?!=)/')) {
    $askWhole('edits Pikkumies\' settings past pikkumies-configure (Config::set, ConfigureChange, ConfigureSettings or a PIKKUMIES_CONFIG overlay), so no setting in it is checked');
}
exit(0);
